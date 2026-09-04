<?php
/**
 * Historical Income Reclassification helper.
 *
 * Posts separate adjustment journals: Dr wrong income / Cr correct income.
 * Never mutates original invoice journals, AR, VAT, receipts, or allocations.
 * Does not modify accounting_engine.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/re_income_account_roles.php';
require_once __DIR__ . '/../accounting/accounting_engine.php';

const RE_INCOME_RECLASS_BATCH_SIZE = 25;

/**
 * Control-account role seeds for reconciliation (codes live only here as defaults).
 * Future configurable mapping can replace this seam without changing callers.
 *
 * @return array<string,string>
 */
function re_income_reclass_control_role_seeds(): array
{
    return [
        'AR_RECEIVABLE' => '1310',
        'VAT_OUTPUT' => '2310',
    ];
}

function re_income_reclass_control_account_code(PDO $conn, int $companyId, string $role): ?string
{
    $role = strtoupper(trim($role));
    // FUTURE HOOK: company-level control account map using $conn + $companyId
    $seeds = re_income_reclass_control_role_seeds();
    return $seeds[$role] ?? null;
}

function re_income_reclass_resolve_control_account(PDO $conn, int $companyId, string $role): ?array
{
    $code = re_income_reclass_control_account_code($conn, $companyId, $role);
    if ($code === null || $code === '') {
        return null;
    }
    return re_income_find_account_by_code($conn, $companyId, $code);
}

function re_income_reclass_schema_ready(PDO $conn): bool
{
    try {
        $a = $conn->query("SHOW TABLES LIKE 're_income_reclass_sessions'");
        $b = $conn->query("SHOW TABLES LIKE 're_income_reclass_lines'");
        return (bool)($a && $a->fetchColumn()) && (bool)($b && $b->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function re_income_reclass_candidate_key(int $invoiceItemId, int $originalJournalId, int $wrongAccountId, int $correctAccountId): string
{
    return $invoiceItemId . ':' . $originalJournalId . ':' . $wrongAccountId . ':' . $correctAccountId;
}

/**
 * Stable active-repair identity (must NOT depend on invoice_item_id).
 * Prevents duplicate GL reclass even if candidate_key formatting changes.
 */
function re_income_reclass_stable_fingerprint(int $originalJournalId, int $wrongAccountId, int $correctAccountId): string
{
    return 'j:' . $originalJournalId . ':w:' . $wrongAccountId . ':c:' . $correctAccountId;
}

/** @deprecated use re_income_reclass_stable_fingerprint — kept for callers that pass a candidate_key */
function re_income_reclass_active_fingerprint(string $candidateKey): string
{
    // If legacy candidate_key "item:journal:wrong:correct", derive stable fp
    $parts = explode(':', $candidateKey);
    if (count($parts) === 4 && ctype_digit($parts[1]) && ctype_digit($parts[2]) && ctype_digit($parts[3])) {
        return re_income_reclass_stable_fingerprint((int)$parts[1], (int)$parts[2], (int)$parts[3]);
    }
    return $candidateKey;
}

/**
 * True if an active (non-reversed) repair already exists for this journal/account pair.
 */
function re_income_reclass_has_active_repair(
    PDO $conn,
    int $companyId,
    int $originalJournalId,
    int $wrongAccountId,
    int $correctAccountId,
    ?int $excludeLineId = null
): bool {
    $fp = re_income_reclass_stable_fingerprint($originalJournalId, $wrongAccountId, $correctAccountId);
    $sql = "
        SELECT id FROM re_income_reclass_lines
        WHERE company_id = ?
          AND status = 'repaired'
          AND (
            active_fingerprint = ?
            OR (original_journal_id = ? AND wrong_account_id = ? AND correct_account_id = ? AND active_fingerprint IS NOT NULL)
          )
    ";
    $params = [$companyId, $fp, $originalJournalId, $wrongAccountId, $correctAccountId];
    if ($excludeLineId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeLineId;
    }
    $sql .= ' LIMIT 1';
    $st = $conn->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

/**
 * @return array{tb_debit:float,tb_credit:float,tb_diff:float,ar:float,vat_output:float,net_income:float,income_accounts:array<string,float>,as_of:string}
 */
function re_income_reclass_recon_snapshot(PDO $conn, int $companyId): array
{
    $tbDebit = 0.0;
    $tbCredit = 0.0;
    $st = $conn->prepare("
        SELECT
            COALESCE(SUM(gl.debit_amount), 0) AS td,
            COALESCE(SUM(gl.credit_amount), 0) AS tc
        FROM re_general_ledger gl
        WHERE gl.company_id = ?
    ");
    $st->execute([$companyId]);
    $tb = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $tbDebit = round((float)($tb['td'] ?? 0), 2);
    $tbCredit = round((float)($tb['tc'] ?? 0), 2);

    $balanceFor = static function (PDO $conn, int $companyId, ?array $acc): float {
        if (!$acc || empty($acc['id'])) {
            return 0.0;
        }
        $st = $conn->prepare("
            SELECT COALESCE(SUM(debit_amount),0) - COALESCE(SUM(credit_amount),0) AS bal
            FROM re_general_ledger
            WHERE company_id = ? AND account_id = ?
        ");
        $st->execute([$companyId, (int)$acc['id']]);
        return round((float)$st->fetchColumn(), 2);
    };

    // Income accounts: credit-normal → report credit − debit as "income balance"
    $incomeBalFor = static function (PDO $conn, int $companyId, ?array $acc): float {
        if (!$acc || empty($acc['id'])) {
            return 0.0;
        }
        $st = $conn->prepare("
            SELECT COALESCE(SUM(credit_amount),0) - COALESCE(SUM(debit_amount),0) AS bal
            FROM re_general_ledger
            WHERE company_id = ? AND account_id = ?
        ");
        $st->execute([$companyId, (int)$acc['id']]);
        return round((float)$st->fetchColumn(), 2);
    };

    $ar = re_income_reclass_resolve_control_account($conn, $companyId, 'AR_RECEIVABLE');
    $vat = re_income_reclass_resolve_control_account($conn, $companyId, 'VAT_OUTPUT');

    $incomeAccounts = [];
    $incomeTotal = 0.0;
    foreach (re_income_account_role_codes() as $role) {
        $resolved = re_resolve_income_account($conn, $companyId, $role, false);
        $acc = $resolved['account'] ?? null;
        $code = (string)($resolved['account_code'] ?? $role);
        $bal = $incomeBalFor($conn, $companyId, $acc);
        $incomeAccounts[$code] = $bal;
        $incomeTotal += $bal;
    }

    // Net income from all Income − Expense accounts (company COA types)
    $st = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN coa.account_type = 'Income'
                THEN gl.credit_amount - gl.debit_amount ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN coa.account_type = 'Expense'
                THEN gl.debit_amount - gl.credit_amount ELSE 0 END), 0) AS net_income
        FROM re_general_ledger gl
        JOIN re_chart_of_accounts coa ON coa.id = gl.account_id AND coa.company_id = gl.company_id
        WHERE gl.company_id = ?
    ");
    $st->execute([$companyId]);
    $netIncome = round((float)$st->fetchColumn(), 2);

    return [
        'tb_debit' => $tbDebit,
        'tb_credit' => $tbCredit,
        'tb_diff' => round($tbDebit - $tbCredit, 2),
        'ar' => $balanceFor($conn, $companyId, $ar),
        'vat_output' => $balanceFor($conn, $companyId, $vat),
        'net_income' => $netIncome,
        'income_accounts' => $incomeAccounts,
        'income_total' => round($incomeTotal, 2),
        'as_of' => date('c'),
    ];
}

/**
 * @param array{before:array,after:array} $pair
 * @return array{ok:bool,messages:list<string>}
 */
function re_income_reclass_recon_compare(array $before, array $after): array
{
    $msgs = [];
    $ok = true;
    $check = static function (string $label, float $a, float $b) use (&$ok, &$msgs): void {
        if (abs($a - $b) > 0.01) {
            $ok = false;
            $msgs[] = "{$label} changed: before={$a} after={$b}";
        }
    };
    $check('Trial Balance difference', (float)$before['tb_diff'], (float)$after['tb_diff']);
    if (abs((float)$after['tb_diff']) > 0.01) {
        $ok = false;
        $msgs[] = 'Trial Balance not balanced after repair';
    }
    $check('AR', (float)$before['ar'], (float)$after['ar']);
    $check('Output VAT', (float)$before['vat_output'], (float)$after['vat_output']);
    $check('Net Income', (float)$before['net_income'], (float)$after['net_income']);
    $check('Income accounts total', (float)$before['income_total'], (float)$after['income_total']);
    if ($ok) {
        $msgs[] = 'Reconciliation OK: TB balanced; AR/VAT/Net Income/income-total unchanged; only income leaf distribution may differ.';
    }
    return ['ok' => $ok, 'messages' => $msgs];
}

function re_income_reclass_next_session_number(PDO $conn, int $companyId): string
{
    $year = date('Y');
    $prefix = 'INC-REPAIR-' . $year . '-';
    $st = $conn->prepare("
        SELECT session_number FROM re_income_reclass_sessions
        WHERE company_id = ? AND session_number LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$companyId, $prefix . '%']);
    $last = (string)($st->fetchColumn() ?: '');
    $seq = 1;
    if (preg_match('/INC-REPAIR-\d{4}-(\d+)$/', $last, $m)) {
        $seq = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/**
 * Resolve account ids for a mispost candidate row.
 *
 * @param array<string,mixed> $m from re_income_mispost_candidates
 * @return array{wrong:?array,correct:?array,error:?string}
 */
function re_income_reclass_resolve_accounts(PDO $conn, int $companyId, array $m): array
{
    $wrong = re_income_find_account_by_code($conn, $companyId, (string)$m['posted_income_code']);
    $correct = re_income_find_account_by_code($conn, $companyId, (string)$m['expected_account_code']);
    if (!$wrong) {
        return ['wrong' => null, 'correct' => null, 'error' => 'Wrong income account not found: ' . $m['posted_income_code']];
    }
    if (!$correct) {
        return ['wrong' => $wrong, 'correct' => null, 'error' => 'Correct income account not found: ' . $m['expected_account_code']];
    }
    if ((int)$wrong['id'] === (int)$correct['id']) {
        return ['wrong' => $wrong, 'correct' => $correct, 'error' => 'Wrong and correct accounts are identical'];
    }
    return ['wrong' => $wrong, 'correct' => $correct, 'error' => null];
}

/**
 * @param list<array<string,mixed>> $candidates selected mispost rows
 * @return array{success:bool,session_id:?int,session_number:?string,error:?string}
 */
function re_income_reclass_create_session(
    PDO $conn,
    int $companyId,
    array $candidates,
    ?string $correctionDate,
    ?int $userId,
    string $notes = ''
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'session_id' => null, 'session_number' => null, 'error' => 'Company context is required'];
    }
    if (!re_income_reclass_schema_ready($conn)) {
        return ['success' => false, 'session_id' => null, 'session_number' => null, 'error' => 'Income reclass tables are not installed'];
    }
    if (!$candidates) {
        return ['success' => false, 'session_id' => null, 'session_number' => null, 'error' => 'No candidates selected'];
    }
    if ($correctionDate !== null && $correctionDate !== '' && is_period_locked($companyId, $correctionDate)) {
        return ['success' => false, 'session_id' => null, 'session_number' => null, 'error' => 'Correction date falls in a locked fiscal year'];
    }

    $startedHere = !$conn->inTransaction();
    if ($startedHere) {
        $conn->beginTransaction();
    }
    try {
        $sessionNumber = re_income_reclass_next_session_number($conn, $companyId);
        $total = 0.0;
        foreach ($candidates as $c) {
            $total += (float)($c['posted_income_credit'] ?? 0);
        }
        $st = $conn->prepare("
            INSERT INTO re_income_reclass_sessions (
                company_id, session_number, status, correction_date,
                selected_count, total_amount, notes, created_by
            ) VALUES (?, ?, 'draft', ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $companyId,
            $sessionNumber,
            ($correctionDate !== null && $correctionDate !== '') ? $correctionDate : null,
            count($candidates),
            round($total, 2),
            $notes !== '' ? $notes : null,
            $userId,
        ]);
        $sessionId = (int)$conn->lastInsertId();

        $ins = $conn->prepare("
            INSERT INTO re_income_reclass_lines (
                repair_session_id, company_id, candidate_key, active_fingerprint,
                invoice_id, invoice_item_id, original_journal_id, obligation_id,
                wrong_account_id, correct_account_id, amount,
                original_journal_date, posted_account_code, expected_account_code,
                obligation_type, accounting_class, item_name, invoice_number, journal_number,
                status, created_by
            ) VALUES (
                ?, ?, ?, NULL,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                'selected', ?
            )
        ");

        foreach ($candidates as $m) {
            $acc = re_income_reclass_resolve_accounts($conn, $companyId, $m);
            if ($acc['error']) {
                throw new RuntimeException($acc['error'] . ' (invoice ' . ($m['invoice_number'] ?? '') . ')');
            }
            $wrongId = (int)$acc['wrong']['id'];
            $correctId = (int)$acc['correct']['id'];
            $itemId = (int)($m['invoice_item_id'] ?? 0);
            $jrnId = (int)$m['journal_id'];
            $ck = re_income_reclass_candidate_key($itemId, $jrnId, $wrongId, $correctId);

            // Block if another active repair exists (stable journal+accounts identity)
            if (re_income_reclass_has_active_repair($conn, $companyId, $jrnId, $wrongId, $correctId, null)) {
                throw new RuntimeException(
                    'Active repair already exists for ' . ($m['invoice_number'] ?? '') . ' / journal #' . $jrnId
                    . ' (' . ($m['posted_income_code'] ?? '') . '→' . ($m['expected_account_code'] ?? '') . '). Reverse it first or skip.'
                );
            }

            $jDate = null;
            $jd = $conn->prepare("SELECT journal_date FROM re_journal_headers WHERE id = ? AND company_id = ?");
            $jd->execute([$jrnId, $companyId]);
            $jDate = $jd->fetchColumn() ?: ($m['invoice_date'] ?? null);

            $ins->execute([
                $sessionId,
                $companyId,
                $ck,
                (int)$m['invoice_id'],
                $itemId > 0 ? $itemId : null,
                $jrnId,
                !empty($m['obligation_id']) ? (int)$m['obligation_id'] : null,
                $wrongId,
                $correctId,
                round((float)$m['posted_income_credit'], 2),
                $jDate,
                (string)$m['posted_income_code'],
                (string)$m['expected_account_code'],
                (string)$m['obligation_type'],
                (string)$m['accounting_class'],
                (string)$m['item_name'],
                (string)$m['invoice_number'],
                (string)$m['journal_number'],
                $userId,
            ]);
        }

        if ($startedHere) {
            $conn->commit();
        }
        return ['success' => true, 'session_id' => $sessionId, 'session_number' => $sessionNumber, 'error' => null];
    } catch (Throwable $e) {
        if ($startedHere && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'session_id' => null, 'session_number' => null, 'error' => $e->getMessage()];
    }
}

/**
 * @return array<string,mixed>|null
 */
function re_income_reclass_load_session(PDO $conn, int $companyId, int $sessionId): ?array
{
    $st = $conn->prepare("SELECT * FROM re_income_reclass_sessions WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$sessionId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return list<array<string,mixed>>
 */
function re_income_reclass_load_lines(PDO $conn, int $companyId, int $sessionId): array
{
    $st = $conn->prepare("
        SELECT * FROM re_income_reclass_lines
        WHERE company_id = ? AND repair_session_id = ?
        ORDER BY id ASC
    ");
    $st->execute([$companyId, $sessionId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Validate one line for dry-run / execute. No DB writes.
 *
 * @param array<string,mixed> $session
 * @param array<string,mixed> $line
 * @return array{ok:bool,skip:bool,action_date:?string,error:?string}
 */
function re_income_reclass_validate_line(PDO $conn, int $companyId, array $session, array $line): array
{
    $lineId = (int)$line['id'];
    $jrnId = (int)$line['original_journal_id'];
    $amount = round((float)$line['amount'], 2);
    $wrongId = (int)$line['wrong_account_id'];
    $correctId = (int)$line['correct_account_id'];

    if ($amount <= 0) {
        return ['ok' => false, 'skip' => true, 'action_date' => null, 'error' => 'Amount must be positive'];
    }

    // Already repaired elsewhere (stable identity — not candidate_key alone)
    if (re_income_reclass_has_active_repair($conn, $companyId, $jrnId, $wrongId, $correctId, $lineId > 0 ? $lineId : null)) {
        return ['ok' => false, 'skip' => true, 'action_date' => null, 'error' => 'Duplicate active repair exists for this journal/accounts'];
    }

    if (($line['status'] ?? '') === 'repaired' && !empty($line['repair_journal_id'])) {
        return ['ok' => false, 'skip' => true, 'action_date' => null, 'error' => 'Already repaired in this session'];
    }

    $jh = $conn->prepare("
        SELECT id, journal_number, journal_date, is_posted, is_reversed, total_debit, total_credit
        FROM re_journal_headers
        WHERE id = ? AND company_id = ?
        LIMIT 1
    ");
    $jh->execute([$jrnId, $companyId]);
    $journal = $jh->fetch(PDO::FETCH_ASSOC);
    if (!$journal) {
        return ['ok' => false, 'skip' => false, 'action_date' => null, 'error' => 'Original journal not found'];
    }
    if (!(int)$journal['is_posted']) {
        return ['ok' => false, 'skip' => false, 'action_date' => null, 'error' => 'Original journal is not posted'];
    }
    if ((int)$journal['is_reversed']) {
        return ['ok' => false, 'skip' => true, 'action_date' => null, 'error' => 'Original journal is reversed'];
    }
    if (abs((float)$journal['total_debit'] - (float)$journal['total_credit']) > 0.01) {
        return ['ok' => false, 'skip' => false, 'action_date' => null, 'error' => 'Original journal is not balanced'];
    }

    // Confirm income credit still exists on wrong account
    $cr = $conn->prepare("
        SELECT COALESCE(SUM(credit_amount),0) FROM re_journal_lines
        WHERE journal_id = ? AND account_id = ? AND credit_amount > 0
    ");
    $cr->execute([$jrnId, $wrongId]);
    $creditOnWrong = round((float)$cr->fetchColumn(), 2);
    if ($creditOnWrong + 0.01 < $amount) {
        return ['ok' => false, 'skip' => false, 'action_date' => null, 'error' => "Original income credit missing/insufficient on wrong account (have {$creditOnWrong}, need {$amount})"];
    }

    $wrongAcc = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE id = ? AND company_id = ? AND is_active = 1");
    $wrongAcc->execute([$wrongId, $companyId]);
    if (!$wrongAcc->fetchColumn()) {
        return ['ok' => false, 'skip' => false, 'action_date' => null, 'error' => 'Wrong account inactive or wrong company'];
    }
    $correctAcc = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE id = ? AND company_id = ? AND is_active = 1");
    $correctAcc->execute([$correctId, $companyId]);
    if (!$correctAcc->fetchColumn()) {
        return ['ok' => false, 'skip' => false, 'action_date' => null, 'error' => 'Correct account inactive or wrong company'];
    }

    $origDate = (string)($journal['journal_date'] ?? $line['original_journal_date'] ?? '');
    $actionDate = $origDate;
    if ($origDate === '' || is_period_locked($companyId, $origDate)) {
        $corr = (string)($session['correction_date'] ?? '');
        if ($corr === '') {
            return ['ok' => false, 'skip' => false, 'action_date' => null, 'error' => 'Original period locked; set an open correction_date on the session'];
        }
        if (is_period_locked($companyId, $corr)) {
            return ['ok' => false, 'skip' => false, 'action_date' => null, 'error' => 'Session correction_date is in a locked fiscal year'];
        }
        $actionDate = $corr;
    }

    return ['ok' => true, 'skip' => false, 'action_date' => $actionDate, 'error' => null];
}

/**
 * Dry run: validates all lines, stores preview JSON, no journals created.
 *
 * @return array{success:bool,session:?array,preview:array,error:?string}
 */
function re_income_reclass_dry_run(PDO $conn, int $companyId, int $sessionId): array
{
    $session = re_income_reclass_load_session($conn, $companyId, $sessionId);
    if (!$session) {
        return ['success' => false, 'session' => null, 'preview' => [], 'error' => 'Session not found'];
    }
    if (in_array($session['status'], ['completed', 'reversed'], true)) {
        return ['success' => false, 'session' => $session, 'preview' => [], 'error' => 'Session is already ' . $session['status']];
    }

    $lines = re_income_reclass_load_lines($conn, $companyId, $sessionId);
    $preview = ['lines' => [], 'ok_count' => 0, 'fail_count' => 0, 'skip_count' => 0, 'total_ok_amount' => 0.0];
    foreach ($lines as $line) {
        if (!in_array($line['status'], ['selected', 'failed', 'skipped'], true)) {
            continue;
        }
        $v = re_income_reclass_validate_line($conn, $companyId, $session, $line);
        $row = [
            'line_id' => (int)$line['id'],
            'invoice_number' => $line['invoice_number'],
            'journal_number' => $line['journal_number'],
            'amount' => (float)$line['amount'],
            'wrong_code' => $line['posted_account_code'],
            'correct_code' => $line['expected_account_code'],
            'action_date' => $v['action_date'],
            'ok' => $v['ok'],
            'skip' => $v['skip'],
            'error' => $v['error'],
            'entry' => $v['ok'] ? [
                'debit_account_id' => (int)$line['wrong_account_id'],
                'credit_account_id' => (int)$line['correct_account_id'],
                'amount' => (float)$line['amount'],
            ] : null,
        ];
        $preview['lines'][] = $row;
        if ($v['ok']) {
            $preview['ok_count']++;
            $preview['total_ok_amount'] += (float)$line['amount'];
        } elseif ($v['skip']) {
            $preview['skip_count']++;
        } else {
            $preview['fail_count']++;
        }
    }
    $preview['total_ok_amount'] = round($preview['total_ok_amount'], 2);
    $preview['passed'] = $preview['fail_count'] === 0 && $preview['ok_count'] > 0;
    $preview['ran_at'] = date('c');

    $newStatus = $preview['passed'] ? 'dry_run_passed' : 'draft';
    $upd = $conn->prepare("
        UPDATE re_income_reclass_sessions
        SET status = ?, dry_run_json = ?
        WHERE id = ? AND company_id = ?
    ");
    $upd->execute([$newStatus, json_encode($preview, JSON_UNESCAPED_UNICODE), $sessionId, $companyId]);
    $session = re_income_reclass_load_session($conn, $companyId, $sessionId);

    return [
        'success' => (bool)$preview['passed'],
        'session' => $session,
        'preview' => $preview,
        'error' => $preview['passed'] ? null : 'Dry run found failures or zero repairable lines',
    ];
}

/**
 * Execute repairs in batches with per-line savepoints (atomic audit+journal+status).
 *
 * @return array{success:bool,session:?array,error:?string,results:array}
 */
function re_income_reclass_execute(PDO $conn, int $companyId, int $sessionId, ?int $userId): array
{
    $session = re_income_reclass_load_session($conn, $companyId, $sessionId);
    if (!$session) {
        return ['success' => false, 'session' => null, 'error' => 'Session not found', 'results' => []];
    }
    if ($session['status'] !== 'dry_run_passed' && $session['status'] !== 'partial') {
        return ['success' => false, 'session' => $session, 'error' => 'Run a successful dry run before execute (status must be dry_run_passed or partial)', 'results' => []];
    }

    $before = re_income_reclass_recon_snapshot($conn, $companyId);
    $conn->prepare("UPDATE re_income_reclass_sessions SET recon_before_json = ? WHERE id = ? AND company_id = ?")
        ->execute([json_encode($before, JSON_UNESCAPED_UNICODE), $sessionId, $companyId]);

    $lines = re_income_reclass_load_lines($conn, $companyId, $sessionId);
    $pending = array_values(array_filter($lines, static fn($l) => in_array($l['status'], ['selected', 'failed'], true)));

    $results = ['repaired' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
    $jMin = $session['repair_journal_id_min'] !== null ? (int)$session['repair_journal_id_min'] : null;
    $jMax = $session['repair_journal_id_max'] !== null ? (int)$session['repair_journal_id_max'] : null;

    $chunks = array_chunk($pending, RE_INCOME_RECLASS_BATCH_SIZE);
    foreach ($chunks as $chunk) {
        $startedHere = !$conn->inTransaction();
        if ($startedHere) {
            $conn->beginTransaction();
        }
        try {
            foreach ($chunk as $line) {
                $sp = 'reclass_' . (int)$line['id'];
                $conn->exec('SAVEPOINT ' . $sp);
                try {
                    $v = re_income_reclass_validate_line($conn, $companyId, $session, $line);
                    if ($v['skip']) {
                        $conn->prepare("
                            UPDATE re_income_reclass_lines
                            SET status = 'skipped', error_message = ?
                            WHERE id = ? AND company_id = ?
                        ")->execute([substr((string)$v['error'], 0, 500), (int)$line['id'], $companyId]);
                        $results['skipped']++;
                        $conn->exec('RELEASE SAVEPOINT ' . $sp);
                        continue;
                    }
                    if (!$v['ok']) {
                        throw new RuntimeException((string)$v['error']);
                    }

                    $actionDate = (string)$v['action_date'];
                    $amount = round((float)$line['amount'], 2);
                    $desc = sprintf(
                        'Historical Income Reclassification | Invoice %s | Journal %s | Type %s | Previous %s | Correct %s | Session %s',
                        (string)$line['invoice_number'],
                        (string)$line['journal_number'],
                        (string)$line['obligation_type'],
                        (string)$line['posted_account_code'],
                        (string)$line['expected_account_code'],
                        (string)$session['session_number']
                    );

                    // Set stable active fingerprint before posting (unique guard)
                    $fp = re_income_reclass_stable_fingerprint(
                        (int)$line['original_journal_id'],
                        (int)$line['wrong_account_id'],
                        (int)$line['correct_account_id']
                    );
                    if (re_income_reclass_has_active_repair(
                        $conn,
                        $companyId,
                        (int)$line['original_journal_id'],
                        (int)$line['wrong_account_id'],
                        (int)$line['correct_account_id'],
                        (int)$line['id']
                    )) {
                        throw new RuntimeException('Active repair already exists (duplicate blocked)');
                    }
                    $conn->prepare("
                        UPDATE re_income_reclass_lines
                        SET active_fingerprint = ?, correction_date = ?, error_message = NULL
                        WHERE id = ? AND company_id = ? AND status IN ('selected','failed')
                    ")->execute([$fp, $actionDate, (int)$line['id'], $companyId]);

                    $je = create_and_post_journal(
                        $companyId,
                        'adjustment',
                        'income_reclass',
                        (int)$line['id'],
                        [
                            [
                                'account_id' => (int)$line['wrong_account_id'],
                                'debit' => $amount,
                                'credit' => 0,
                                'description' => $desc,
                                'reference' => (string)$session['session_number'],
                            ],
                            [
                                'account_id' => (int)$line['correct_account_id'],
                                'debit' => 0,
                                'credit' => $amount,
                                'description' => $desc,
                                'reference' => (string)$session['session_number'],
                            ],
                        ],
                        $desc,
                        $actionDate,
                        $userId
                    );

                    if (empty($je['success']) || empty($je['journal_id'])) {
                        throw new RuntimeException($je['error'] ?? 'Journal post failed');
                    }

                    $jid = (int)$je['journal_id'];
                    $conn->prepare("
                        UPDATE re_income_reclass_lines
                        SET status = 'repaired', repair_journal_id = ?, correction_date = ?, error_message = NULL
                        WHERE id = ? AND company_id = ?
                    ")->execute([$jid, $actionDate, (int)$line['id'], $companyId]);

                    $results['repaired']++;
                    $jMin = $jMin === null ? $jid : min($jMin, $jid);
                    $jMax = $jMax === null ? $jid : max($jMax, $jid);
                    $conn->exec('RELEASE SAVEPOINT ' . $sp);
                } catch (Throwable $e) {
                    $conn->exec('ROLLBACK TO SAVEPOINT ' . $sp);
                    $conn->prepare("
                        UPDATE re_income_reclass_lines
                        SET status = 'failed', active_fingerprint = NULL, error_message = ?
                        WHERE id = ? AND company_id = ? AND status <> 'repaired'
                    ")->execute([substr($e->getMessage(), 0, 500), (int)$line['id'], $companyId]);
                    $results['failed']++;
                    $results['errors'][] = 'Line #' . (int)$line['id'] . ': ' . $e->getMessage();
                }
            }
            if ($startedHere) {
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($startedHere && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'session' => $session, 'error' => 'Batch failed: ' . $e->getMessage(), 'results' => $results];
        }

        // Refresh session counters after each batch
        re_income_reclass_refresh_session_counts($conn, $companyId, $sessionId, $jMin, $jMax);
    }

    $after = re_income_reclass_recon_snapshot($conn, $companyId);
    $cmp = re_income_reclass_recon_compare($before, $after);
    $session = re_income_reclass_refresh_session_counts($conn, $companyId, $sessionId, $jMin, $jMax);

    $finalStatus = 'completed';
    if ((int)$session['failed_count'] > 0 && (int)$session['repaired_count'] > 0) {
        $finalStatus = 'partial';
    } elseif ((int)$session['repaired_count'] <= 0) {
        $finalStatus = 'draft';
    }

    $conn->prepare("
        UPDATE re_income_reclass_sessions
        SET status = ?, recon_after_json = ?, executed_at = NOW(),
            repair_journal_id_min = ?, repair_journal_id_max = ?
        WHERE id = ? AND company_id = ?
    ")->execute([
        $finalStatus,
        json_encode(['snapshot' => $after, 'compare' => $cmp], JSON_UNESCAPED_UNICODE),
        $jMin,
        $jMax,
        $sessionId,
        $companyId,
    ]);

    $session = re_income_reclass_load_session($conn, $companyId, $sessionId);
    return [
        'success' => $cmp['ok'] && $results['failed'] === 0 && $results['repaired'] > 0,
        'session' => $session,
        'error' => $cmp['ok'] ? ($results['failed'] ? 'Completed with failures' : null) : implode('; ', $cmp['messages']),
        'results' => $results + ['recon' => $cmp],
    ];
}

/**
 * @return array<string,mixed>
 */
function re_income_reclass_refresh_session_counts(PDO $conn, int $companyId, int $sessionId, ?int $jMin, ?int $jMax): array
{
    $st = $conn->prepare("
        SELECT
            SUM(status = 'repaired') AS repaired_count,
            SUM(status = 'failed') AS failed_count,
            SUM(status = 'skipped') AS skipped_count,
            COALESCE(SUM(CASE WHEN status = 'repaired' THEN amount ELSE 0 END),0) AS repaired_amount
        FROM re_income_reclass_lines
        WHERE company_id = ? AND repair_session_id = ?
    ");
    $st->execute([$companyId, $sessionId]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $conn->prepare("
        UPDATE re_income_reclass_sessions SET
            repaired_count = ?, failed_count = ?, skipped_count = ?,
            repair_journal_id_min = COALESCE(?, repair_journal_id_min),
            repair_journal_id_max = COALESCE(?, repair_journal_id_max)
        WHERE id = ? AND company_id = ?
    ")->execute([
        (int)($c['repaired_count'] ?? 0),
        (int)($c['failed_count'] ?? 0),
        (int)($c['skipped_count'] ?? 0),
        $jMin,
        $jMax,
        $sessionId,
        $companyId,
    ]);
    return re_income_reclass_load_session($conn, $companyId, $sessionId) ?: [];
}

/**
 * Reverse one repaired line: reverse repair journal, clear active_fingerprint after success.
 *
 * @return array{success:bool,error:?string}
 */
function re_income_reclass_reverse_line(PDO $conn, int $companyId, int $lineId, ?int $userId, string $reason = ''): array
{
    $st = $conn->prepare("SELECT * FROM re_income_reclass_lines WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$lineId, $companyId]);
    $line = $st->fetch(PDO::FETCH_ASSOC);
    if (!$line) {
        return ['success' => false, 'error' => 'Line not found'];
    }
    if (($line['status'] ?? '') !== 'repaired' || empty($line['repair_journal_id'])) {
        return ['success' => false, 'error' => 'Line is not in repaired state'];
    }

    $startedHere = !$conn->inTransaction();
    if ($startedHere) {
        $conn->beginTransaction();
    }
    try {
        $rev = reverse_journal((int)$line['repair_journal_id'], $reason !== '' ? $reason : 'Income reclass reverse', $userId, null);
        if (empty($rev['success'])) {
            throw new RuntimeException($rev['error'] ?? 'Reversal failed');
        }
        // Clear active_fingerprint only after reversal journal posted
        $conn->prepare("
            UPDATE re_income_reclass_lines
            SET status = 'reversed',
                active_fingerprint = NULL,
                reversal_journal_id = ?,
                reversed_by = ?,
                reversed_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([(int)$rev['reversal_journal_id'], $userId, $lineId, $companyId]);

        if ($startedHere) {
            $conn->commit();
        }
        re_income_reclass_maybe_mark_session_reversed($conn, $companyId, (int)$line['repair_session_id'], $userId);
        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        if ($startedHere && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function re_income_reclass_reverse_session(PDO $conn, int $companyId, int $sessionId, ?int $userId, string $reason = ''): array
{
    $lines = re_income_reclass_load_lines($conn, $companyId, $sessionId);
    $ok = 0;
    $fail = 0;
    $errors = [];
    foreach ($lines as $line) {
        if (($line['status'] ?? '') !== 'repaired') {
            continue;
        }
        $r = re_income_reclass_reverse_line($conn, $companyId, (int)$line['id'], $userId, $reason);
        if ($r['success']) {
            $ok++;
        } else {
            $fail++;
            $errors[] = 'Line #' . (int)$line['id'] . ': ' . $r['error'];
        }
    }
    re_income_reclass_maybe_mark_session_reversed($conn, $companyId, $sessionId, $userId);
    return [
        'success' => $fail === 0 && $ok > 0,
        'reversed' => $ok,
        'failed' => $fail,
        'error' => $errors ? implode('; ', $errors) : null,
    ];
}

function re_income_reclass_maybe_mark_session_reversed(PDO $conn, int $companyId, int $sessionId, ?int $userId): void
{
    $st = $conn->prepare("
        SELECT
            SUM(status = 'repaired') AS still_repaired,
            SUM(status = 'reversed') AS reversed_count
        FROM re_income_reclass_lines
        WHERE company_id = ? AND repair_session_id = ?
    ");
    $st->execute([$companyId, $sessionId]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($c['still_repaired'] ?? 0) === 0 && (int)($c['reversed_count'] ?? 0) > 0) {
        $conn->prepare("
            UPDATE re_income_reclass_sessions
            SET status = 'reversed', reversed_by = ?, reversed_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$userId, $sessionId, $companyId]);
    }
}

/**
 * Status overlay for mispost report rows.
 * Keyed by stable fingerprint j:{journal}:w:{wrong}:c:{correct}
 *
 * @return array<string,string> stable_fp => status label raw
 */
function re_income_reclass_active_status_map(PDO $conn, int $companyId): array
{
    if (!re_income_reclass_schema_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("
        SELECT original_journal_id, wrong_account_id, correct_account_id, status, active_fingerprint, id
        FROM re_income_reclass_lines
        WHERE company_id = ? AND status = 'repaired' AND active_fingerprint IS NOT NULL
        ORDER BY id DESC
    ");
    $st->execute([$companyId]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fp = re_income_reclass_stable_fingerprint(
            (int)$row['original_journal_id'],
            (int)$row['wrong_account_id'],
            (int)$row['correct_account_id']
        );
        if (!isset($map[$fp])) {
            $map[$fp] = 'repaired';
        }
    }
    // Also mark in-progress selected lines in open sessions
    $st2 = $conn->prepare("
        SELECT l.original_journal_id, l.wrong_account_id, l.correct_account_id, l.status
        FROM re_income_reclass_lines l
        JOIN re_income_reclass_sessions s ON s.id = l.repair_session_id AND s.company_id = l.company_id
        WHERE l.company_id = ?
          AND l.status IN ('selected', 'failed', 'skipped')
          AND s.status IN ('draft', 'dry_run_passed', 'partial')
        ORDER BY l.id DESC
    ");
    $st2->execute([$companyId]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fp = re_income_reclass_stable_fingerprint(
            (int)$row['original_journal_id'],
            (int)$row['wrong_account_id'],
            (int)$row['correct_account_id']
        );
        if (!isset($map[$fp])) {
            $map[$fp] = (string)$row['status'];
        }
    }
    return $map;
}
