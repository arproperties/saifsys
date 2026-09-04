<?php
/**
 * Construction bank reconciliation — bank rules (Phase 3).
 */
declare(strict_types=1);

require_once __DIR__ . '/construction_bank_reconciliation.php';

function co_bank_rules_table_ready(PDO $conn): bool
{
    return co_db_table_exists($conn, 'co_bank_rules');
}

/**
 * @return list<array<string,mixed>>
 */
function co_bank_rules_list(PDO $conn, int $companyId, bool $activeOnly = false): array
{
    if (!co_bank_rules_table_ready($conn)) {
        return [];
    }
    $sql = 'SELECT * FROM co_bank_rules WHERE company_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY priority ASC, id ASC';
    $st = $conn->prepare($sql);
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['conditions'] = json_decode((string) ($row['conditions_json'] ?? '{}'), true) ?: [];
        $row['action'] = json_decode((string) ($row['action_json'] ?? '{}'), true) ?: [];
    }
    unset($row);
    return $rows;
}

/** @return array<string,mixed>|null */
function co_bank_rule_get(PDO $conn, int $ruleId, int $companyId): ?array
{
    if (!co_bank_rules_table_ready($conn)) {
        return null;
    }
    $st = $conn->prepare('SELECT * FROM co_bank_rules WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$ruleId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['conditions'] = json_decode((string) ($row['conditions_json'] ?? '{}'), true) ?: [];
    $row['action'] = json_decode((string) ($row['action_json'] ?? '{}'), true) ?: [];
    return $row;
}

/** @param array<string,mixed> $data */
function co_bank_rule_save(PDO $conn, int $companyId, array $data, ?int $userId): array
{
    if (!co_bank_rules_table_ready($conn)) {
        return ['success' => false, 'rule_id' => null, 'error' => 'Rules table not installed'];
    }

    $ruleId = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['rule_name'] ?? ''));
    if ($name === '') {
        return ['success' => false, 'rule_id' => null, 'error' => 'Rule name is required'];
    }

    $direction = (string) ($data['direction'] ?? 'spent');
    if (!in_array($direction, ['spent', 'received', 'both', 'transfer'], true)) {
        $direction = 'spent';
    }

    $conditions = is_array($data['conditions'] ?? null) ? $data['conditions'] : [];
    $action = is_array($data['action'] ?? null) ? $data['action'] : [];
    $bankAccountId = !empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
    $priority = (int) ($data['priority'] ?? 100);
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $autoSuggest = !isset($data['auto_suggest']) || !empty($data['auto_suggest']) ? 1 : 0;
    $autoCreateDraft = !empty($data['auto_create_draft']) ? 1 : 0;

    $conditionsJson = json_encode($conditions, JSON_UNESCAPED_UNICODE);
    $actionJson = json_encode($action, JSON_UNESCAPED_UNICODE);

    try {
        if ($ruleId > 0) {
            $st = $conn->prepare('
                UPDATE co_bank_rules SET
                  rule_name = ?, direction = ?, bank_account_id = ?, conditions_json = ?, action_json = ?,
                  priority = ?, is_active = ?, auto_suggest = ?, auto_create_draft = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ');
            $st->execute([
                mb_substr($name, 0, 120),
                $direction,
                $bankAccountId,
                $conditionsJson,
                $actionJson,
                $priority,
                $isActive,
                $autoSuggest,
                $autoCreateDraft,
                $ruleId,
                $companyId,
            ]);
        } else {
            $st = $conn->prepare('
                INSERT INTO co_bank_rules
                  (company_id, rule_name, direction, bank_account_id, conditions_json, action_json, priority, is_active, auto_suggest, auto_create_draft, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $st->execute([
                $companyId,
                mb_substr($name, 0, 120),
                $direction,
                $bankAccountId,
                $conditionsJson,
                $actionJson,
                $priority,
                $isActive,
                $autoSuggest,
                $autoCreateDraft,
                $userId ?: null,
            ]);
            $ruleId = (int) $conn->lastInsertId();
        }
        co_bank_reco_audit($conn, $companyId, $bankAccountId ?: 0, null, null, 'bank_rule_save', null, [
            'rule_id' => $ruleId,
            'rule_name' => $name,
        ], $userId);
        return ['success' => true, 'rule_id' => $ruleId, 'error' => null];
    } catch (Throwable $e) {
        return ['success' => false, 'rule_id' => null, 'error' => $e->getMessage()];
    }
}

function co_bank_rule_delete(PDO $conn, int $ruleId, int $companyId, ?int $userId = null): array
{
    if (!co_bank_rules_table_ready($conn)) {
        return ['success' => false, 'error' => 'Rules table not installed'];
    }
    $rule = co_bank_rule_get($conn, $ruleId, $companyId);
    if (!$rule) {
        return ['success' => false, 'error' => 'Rule not found'];
    }
    $conn->prepare('DELETE FROM co_bank_rules WHERE id = ? AND company_id = ?')->execute([$ruleId, $companyId]);
    co_bank_reco_audit($conn, $companyId, (int) ($rule['bank_account_id'] ?? 0), null, null, 'bank_rule_delete', ['rule_id' => $ruleId], null, $userId);
    return ['success' => true, 'error' => null];
}

/** @param array<string,mixed> $conditions */
function co_bank_rule_conditions_match(array $conditions, array $line): bool
{
    $desc = mb_strtolower(trim((string) ($line['description'] ?? '')), 'UTF-8');
    $ref = mb_strtolower(trim((string) ($line['reference'] ?? '')), 'UTF-8');
    $abs = round(abs((float) ($line['amount'] ?? 0)), 2);

    if (!empty($conditions['description_contains'])) {
        $needle = mb_strtolower(trim((string) $conditions['description_contains']), 'UTF-8');
        if ($needle !== '' && !str_contains($desc, $needle)) {
            return false;
        }
    }
    if (!empty($conditions['description_equals'])) {
        $eq = mb_strtolower(trim((string) $conditions['description_equals']), 'UTF-8');
        if ($eq !== '' && $desc !== $eq) {
            return false;
        }
    }
    if (!empty($conditions['reference_contains'])) {
        $needle = mb_strtolower(trim((string) $conditions['reference_contains']), 'UTF-8');
        if ($needle !== '' && !str_contains($ref, $needle)) {
            return false;
        }
    }
    if (isset($conditions['amount_equals']) && $conditions['amount_equals'] !== '' && $conditions['amount_equals'] !== null) {
        if (abs($abs - (float) $conditions['amount_equals']) >= 0.02) {
            return false;
        }
    }
    if (isset($conditions['amount_min']) && $conditions['amount_min'] !== '' && $conditions['amount_min'] !== null) {
        if ($abs < (float) $conditions['amount_min'] - 0.009) {
            return false;
        }
    }
    if (isset($conditions['amount_max']) && $conditions['amount_max'] !== '' && $conditions['amount_max'] !== null) {
        if ($abs > (float) $conditions['amount_max'] + 0.009) {
            return false;
        }
    }
    return true;
}

/** @param array<string,mixed> $action */
function co_bank_rule_build_prefill(array $action, array $line): array
{
    $tpl = (string) ($action['description_template'] ?? '{description}');
    $desc = (string) ($line['description'] ?? '');
    $memo = str_replace(
        ['{description}', '{reference}', '{amount}'],
        [$desc, (string) ($line['reference'] ?? ''), number_format(abs((float) ($line['amount'] ?? 0)), 2, '.', '')],
        $tpl
    );
    if (trim($memo) === '') {
        $memo = $desc;
    }

    $contactType = !empty($action['contact_type']) ? (string) $action['contact_type'] : null;
    $contactId = !empty($action['contact_id']) ? (int) $action['contact_id'] : null;
    $contactValue = ($contactType && $contactId) ? ($contactType . ':' . $contactId) : '';

    return [
        'transaction_type' => (string) ($action['transaction_type'] ?? 'quick_expense'),
        'account_id' => !empty($action['account_id']) ? (int) $action['account_id'] : null,
        'project_id' => !empty($action['project_id']) ? (int) $action['project_id'] : null,
        'contact' => $contactValue,
        'contact_type' => $contactType,
        'contact_id' => $contactId,
        'description' => $memo,
        'reference' => (string) ($line['reference'] ?? ''),
    ];
}

/**
 * Find the first matching active rule for a statement line (lowest priority number wins).
 *
 * @return array<string,mixed>|null
 */
function co_bank_rule_match_line(PDO $conn, int $companyId, array $line, ?int $bankAccountId = null): ?array
{
    if (!co_bank_rules_table_ready($conn)) {
        return null;
    }
    $bankAccountId = $bankAccountId ?: (int) ($line['bank_account_id'] ?? 0);
    $isSpent = (float) ($line['amount'] ?? 0) < 0;
    $isReceived = (float) ($line['amount'] ?? 0) > 0;

    foreach (co_bank_rules_list($conn, $companyId, true) as $rule) {
        $ruleBank = $rule['bank_account_id'] ?? null;
        if ($ruleBank && (int) $ruleBank !== $bankAccountId) {
            continue;
        }
        $dir = (string) ($rule['direction'] ?? 'both');
        if ($dir === 'spent' && !$isSpent) {
            continue;
        }
        if ($dir === 'received' && !$isReceived) {
            continue;
        }
        if ($dir === 'transfer') {
            continue;
        }
        if (!co_bank_rule_conditions_match($rule['conditions'], $line)) {
            continue;
        }
        return [
            'rule_id' => (int) $rule['id'],
            'rule_name' => (string) $rule['rule_name'],
            'priority' => (int) $rule['priority'],
            'auto_suggest' => (int) ($rule['auto_suggest'] ?? 1),
            'auto_create_draft' => (int) ($rule['auto_create_draft'] ?? 0),
            'prefill' => co_bank_rule_build_prefill($rule['action'], $line),
        ];
    }
    return null;
}

/** @return array<string,mixed> */
function co_bank_rule_from_line(PDO $conn, int $companyId, array $line, ?int $bankAccountId = null): array
{
    $isSpent = (float) ($line['amount'] ?? 0) < 0;
    $guess = co_bank_reco_guess_contact($conn, $companyId, (string) ($line['description'] ?? '') . ' ' . (string) ($line['reference'] ?? ''), !$isSpent);
    return [
        'rule_name' => mb_substr(trim((string) ($line['description'] ?? 'Bank rule')), 0, 120),
        'direction' => $isSpent ? 'spent' : 'received',
        'bank_account_id' => $bankAccountId ?: (int) ($line['bank_account_id'] ?? 0),
        'priority' => 100,
        'is_active' => 1,
        'auto_suggest' => 1,
        'auto_create_draft' => 0,
        'conditions' => [
            'description_contains' => mb_substr(trim((string) ($line['description'] ?? '')), 0, 80),
            'reference_contains' => mb_substr(trim((string) ($line['reference'] ?? '')), 0, 80),
            'amount_equals' => round(abs((float) ($line['amount'] ?? 0)), 2),
        ],
        'action' => [
            'transaction_type' => $isSpent ? 'quick_expense' : 'client_receipt',
            'contact_type' => $guess['type'] ?? null,
            'contact_id' => $guess['id'] ?? null,
            'description_template' => '{description}',
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function co_bank_reco_report(PDO $conn, int $companyId, int $bankAccountId, string $dateFrom, string $dateTo): array
{
    $bank = co_bank_verify_account($conn, $bankAccountId, $companyId);
    if (!$bank) {
        return ['error' => 'Invalid bank account'];
    }
    $glId = (int) $bank['gl_account_id'];
    $asOf = $dateTo !== '' ? $dateTo : date('Y-m-d');

    $erpBalance = co_bank_erp_balance($conn, $glId, $companyId, $asOf);
    $statementBalance = co_bank_statement_balance($conn, $bankAccountId, $companyId, $asOf);
    $difference = $statementBalance !== null ? co_bank_reco_money($erpBalance - $statementBalance) : null;

    $st = $conn->prepare('
        SELECT l.*,
          (SELECT COALESCE(SUM(m.amount_matched),0) FROM co_reconciliation_matches m
           WHERE m.bank_statement_line_id = l.id AND m.status IN (\'proposed\',\'confirmed\')) AS matched_sum
        FROM co_bank_statement_lines l
        WHERE l.bank_account_id = ? AND l.company_id = ? AND l.txn_date BETWEEN ? AND ?
        ORDER BY l.txn_date ASC, l.id ASC
    ');
    $st->execute([$bankAccountId, $companyId, $dateFrom, $dateTo]);
    $statementLines = [];
    $unreconciledLines = [];
    $reconciledLines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rem = max(0, round(abs((float) $row['amount']) - (float) $row['matched_sum'], 2));
        $row['remaining'] = $rem;
        $row['is_reconciled'] = $rem <= 0.009;
        $statementLines[] = $row;
        if ($row['is_reconciled']) {
            $reconciledLines[] = $row;
        } else {
            $unreconciledLines[] = $row;
        }
    }

    $st = $conn->prepare("
        SELECT gl.id, gl.entry_date, gl.debit_amount, gl.credit_amount, gl.description, gl.reference, gl.is_reconciled,
               jh.journal_number
        FROM re_general_ledger gl
        LEFT JOIN re_journal_headers jh ON jh.id = gl.journal_id
        WHERE gl.company_id = ? AND gl.account_id = ? AND gl.entry_date BETWEEN ? AND ?
          AND (gl.debit_amount > 0 OR gl.credit_amount > 0)
        ORDER BY gl.entry_date ASC, gl.id ASC
    ");
    $st->execute([$companyId, $glId, $dateFrom, $dateTo]);
    $outstandingGl = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $gl) {
        $gid = (int) $gl['id'];
        $entryAmt = co_gl_entry_amount($gl);
        $matched = co_gl_entry_matched_sum($conn, $gid, 'confirmed');
        $rem = round($entryAmt - $matched, 2);
        if ($rem <= 0.009) {
            continue;
        }
        $gl['remaining'] = $rem;
        $gl['amount'] = $entryAmt;
        $outstandingGl[] = $gl;
    }

    $st = $conn->prepare("
        SELECT m.*, l.txn_date, l.description AS line_description, l.reference AS line_reference
        FROM co_reconciliation_matches m
        JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
        WHERE l.bank_account_id = ? AND l.company_id = ? AND m.status = 'confirmed'
          AND m.match_method IN ('adjustment','create','cash_coding','rule')
          AND COALESCE(m.confirmed_at, l.txn_date) BETWEEN ? AND ?
        ORDER BY m.confirmed_at DESC
    ");
    $st->execute([$bankAccountId, $companyId, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
    $adjustments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'bank' => $bank,
        'erp_balance' => $erpBalance,
        'statement_balance' => $statementBalance,
        'difference' => $difference,
        'statement_lines' => $statementLines,
        'unreconciled_lines' => $unreconciledLines,
        'reconciled_lines' => $reconciledLines,
        'outstanding_gl' => $outstandingGl,
        'adjustments' => $adjustments,
        'counts' => [
            'statement_total' => count($statementLines),
            'unreconciled' => count($unreconciledLines),
            'reconciled' => count($reconciledLines),
            'outstanding_gl' => count($outstandingGl),
            'adjustments' => count($adjustments),
        ],
    ];
}
