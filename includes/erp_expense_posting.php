<?php
/**
 * Post ERP operating expenses to re_journal_* / re_general_ledger (shared engine).
 * Does not use legacy gl_posting or expenses table.
 */
require_once __DIR__ . '/../modules/realestate/accounting/accounting_engine.php';

if (!function_exists('erp_expense_lines_has_building_column')) {
    function erp_expense_lines_has_building_column(PDO $db): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $st = $db->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'erp_expense_lines'
                  AND COLUMN_NAME = 'building_id'
            ");
            $exists = ((int)$st->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $exists = false;
        }
        return $exists;
    }
}

/**
 * Validate a debit line account for Quick Paid / ERP expenses.
 * Dropdown may list full COA; save/post must reject protected control accounts.
 *
 * @return array{ok:bool,error?:string,account?:array}
 */
if (!function_exists('erp_expense_validate_line_account')) {
    function erp_expense_validate_line_account(PDO $db, int $companyId, int $accountId): array
    {
        if ($companyId <= 0 || $accountId <= 0) {
            return ['ok' => false, 'error' => 'Invalid company or expense account.'];
        }
        $st = $db->prepare("
            SELECT id, account_code, account_name, account_type, is_header, is_active
            FROM re_chart_of_accounts
            WHERE id = ? AND company_id = ?
            LIMIT 1
        ");
        $st->execute([$accountId, $companyId]);
        $acc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$acc) {
            return ['ok' => false, 'error' => 'Expense account not found for this company.'];
        }
        if ((int)($acc['is_header'] ?? 0) === 1) {
            return ['ok' => false, 'error' => 'Header accounts cannot be used on expense lines.'];
        }
        if ((int)($acc['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'Inactive accounts cannot be used on expense lines.'];
        }

        $type = strtolower(trim((string)($acc['account_type'] ?? '')));
        $code = trim((string)($acc['account_code'] ?? ''));
        $name = strtolower(trim((string)($acc['account_name'] ?? '')));

        if (in_array($type, ['income', 'equity', 'liability'], true)) {
            return ['ok' => false, 'error' => 'Account ' . $code . ' (' . $acc['account_type'] . ') cannot be used as an expense line debit.'];
        }

        if (preg_match('/^(11|12)/', $code)) {
            return ['ok' => false, 'error' => 'Bank/cash account ' . $code . ' cannot be used as an expense line debit (use settlement account instead).'];
        }
        try {
            $bankGl = $db->prepare('SELECT 1 FROM re_bank_accounts WHERE company_id = ? AND gl_account_id = ? LIMIT 1');
            $bankGl->execute([$companyId, $accountId]);
            if ($bankGl->fetchColumn()) {
                return ['ok' => false, 'error' => 'Bank settlement accounts cannot be used as expense line debits.'];
            }
        } catch (Throwable $e) {
            // table may be missing on partial installs
        }

        if (preg_match('/^13/', $code) || str_contains($name, 'receivable') || str_contains($name, 'accounts receivable')) {
            return ['ok' => false, 'error' => 'Receivable account ' . $code . ' cannot be used as an expense line debit.'];
        }

        if (preg_match('/^21/', $code) || str_contains($name, 'payable') || str_contains($name, 'accounts payable')) {
            return ['ok' => false, 'error' => 'Payable account ' . $code . ' cannot be used as an expense line debit.'];
        }

        $vatCodes = ['1260', '2130', '2310', '2320', '2330'];
        if (in_array($code, $vatCodes, true)
            || str_contains($name, 'input vat')
            || str_contains($name, 'output vat')
            || str_contains($name, 'vat recoverable')
            || str_contains($name, 'vat payable')
        ) {
            return ['ok' => false, 'error' => 'VAT control account ' . $code . ' cannot be selected as an expense line (VAT posts automatically).'];
        }

        if ($type === 'expense' || $type === 'asset') {
            return ['ok' => true, 'account' => $acc];
        }

        return ['ok' => false, 'error' => 'Account ' . $code . ' is not allowed as an expense line debit.'];
    }
}

if (!function_exists('erp_expense_validate_building')) {
    function erp_expense_validate_building(PDO $db, int $companyId, int $buildingId): array
    {
        if ($buildingId <= 0) {
            return ['ok' => true, 'building_id' => null];
        }
        $st = $db->prepare('SELECT id FROM re_buildings WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$buildingId, $companyId]);
        if (!$st->fetchColumn()) {
            return ['ok' => false, 'error' => 'Selected building is invalid for this company.'];
        }
        return ['ok' => true, 'building_id' => $buildingId];
    }
}

/**
 * Resolve Input VAT debit account.
 * Construction Quick Paid: prefer 2130 (Confirmed BR-CO-QPE-003).
 * Real Estate / default: prefer 2320 then 1260.
 */
function erp_resolve_input_vat_account_id(PDO $db, int $companyId, ?string $sourceModule = null): ?int {
    $codes = ($sourceModule === 'construction')
        ? ['2130', '2320', '1260']
        : ['2320', '1260'];
    foreach ($codes as $code) {
        $st = $db->prepare('SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = ? AND is_active = 1 LIMIT 1');
        $st->execute([$companyId, $code]);
        $id = $st->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }
    $st = $db->prepare("
        SELECT id FROM re_chart_of_accounts
        WHERE company_id = ? AND is_active = 1 AND is_header = 0
        AND (account_name LIKE '%Input VAT%' OR account_name LIKE '%VAT Recoverable%')
        LIMIT 1
    ");
    $st->execute([$companyId]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/** @return bool True when Construction historical ERP archive row (read-only forever). */
function erp_expense_is_legacy_archive(array $header): bool {
    return (string)($header['source_module'] ?? '') === 'construction'
        && (int)($header['legacy_archive'] ?? 0) === 1;
}

function erp_expense_headers_has_legacy_archive_column(PDO $db): bool {
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $st = $db->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'erp_expense_headers'
              AND COLUMN_NAME = 'legacy_archive'
        ");
        $exists = ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

/**
 * Validate settlement (credit) account belongs to company and is a posting account.
 * @return array{ok:bool,error?:string}
 */
function erp_expense_validate_pay_account(PDO $db, int $companyId, int $accountId): array {
    if ($companyId <= 0 || $accountId <= 0) {
        return ['ok' => false, 'error' => 'Payment / settlement account is required.'];
    }
    $st = $db->prepare("
        SELECT id, is_header, is_active FROM re_chart_of_accounts
        WHERE id = ? AND company_id = ? LIMIT 1
    ");
    $st->execute([$accountId, $companyId]);
    $acc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$acc) {
        return ['ok' => false, 'error' => 'Settlement account not found for this company.'];
    }
    if ((int)($acc['is_header'] ?? 0) === 1 || (int)($acc['is_active'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'Settlement account must be an active posting account.'];
    }
    return ['ok' => true];
}

function erp_resolve_ap_account_id(PDO $db, int $companyId): int {
    foreach (['2100', '2000'] as $code) {
        $st = $db->prepare('SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = ? AND is_active = 1 LIMIT 1');
        $st->execute([$companyId, $code]);
        $id = $st->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }
    throw new RuntimeException('Accounts Payable account not found in chart (expected 2100 or 2000).');
}

/**
 * @return array{success:bool,journal_id?:int,error?:string|null}
 */
/**
 * Journal lines for an expense header (Dr expense lines / Dr Input VAT / Cr pay account).
 *
 * @return array{success:bool,lines?:array,error?:string}
 */
function erp_expense_build_journal_lines(PDO $db, array $h): array {
    $expenseId = (int)$h['id'];
    $companyId = (int)$h['company_id'];
    $sourceModule = (string)($h['source_module'] ?? 'realestate');
    $paidVia = $h['paid_via'] ?? 'bank';

    $L = $db->prepare('SELECT * FROM erp_expense_lines WHERE expense_id = ? ORDER BY line_no');
    $L->execute([$expenseId]);
    $rows = $L->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['success' => false, 'error' => 'No expense lines'];
    }

    $lines = [];
    $vatTotal = round((float)$h['vat_amount'], 2);
    foreach ($rows as $r) {
        $check = erp_expense_validate_line_account($db, $companyId, (int)$r['account_id']);
        if (empty($check['ok'])) {
            return ['success' => false, 'error' => $check['error'] ?? 'Invalid expense line account'];
        }
        $net = round((float)$r['line_subtotal'], 2);
        if ($net <= 0) {
            continue;
        }
        $lines[] = [
            'account_id' => (int)$r['account_id'],
            'debit' => $net,
            'credit' => 0,
            'description' => $r['description'] ?: 'Expense',
        ];
    }
    if (!$lines) {
        return ['success' => false, 'error' => 'No positive expense lines'];
    }

    if ($vatTotal > 0.005) {
        $vatAcc = erp_resolve_input_vat_account_id($db, $companyId, $sourceModule);
        if (!$vatAcc) {
            $hint = $sourceModule === 'construction'
                ? 'add account code 2130 (Construction Input VAT)'
                : 'add account code 2320 or 1260';
            return ['success' => false, 'error' => 'Input VAT account not found in chart (' . $hint . ').'];
        }
        $lines[] = [
            'account_id' => $vatAcc,
            'debit' => $vatTotal,
            'credit' => 0,
            'description' => 'Input VAT',
        ];
    }

    $creditAmt = round((float)$h['total'], 2);

    if ($paidVia === 'accounts_payable') {
        $creditId = erp_resolve_ap_account_id($db, $companyId);
    } elseif (in_array($paidVia, ['bank', 'cash', 'credit'], true)) {
        $creditId = (int)$h['pay_account_id'];
        $payCheck = erp_expense_validate_pay_account($db, $companyId, $creditId);
        if (empty($payCheck['ok'])) {
            return ['success' => false, 'error' => $payCheck['error'] ?? 'Payment / settlement account required'];
        }
    } else {
        return ['success' => false, 'error' => 'Invalid payment method'];
    }

    $lines[] = [
        'account_id' => $creditId,
        'debit' => 0,
        'credit' => $creditAmt,
        'description' => $paidVia === 'accounts_payable' ? 'Accounts Payable' : 'Payment',
    ];

    $sumDr = 0.0;
    $sumCr = 0.0;
    foreach ($lines as $ln) {
        $sumDr += (float)$ln['debit'];
        $sumCr += (float)$ln['credit'];
    }
    if (round($sumDr, 2) !== round($sumCr, 2)) {
        return ['success' => false, 'error' => 'Journal does not balance (Dr ' . $sumDr . ' vs Cr ' . $sumCr . '). Check line totals.'];
    }

    return ['success' => true, 'lines' => $lines];
}

function erp_post_expense(PDO $db, int $expenseId, ?int $userId): array {
    $H = $db->prepare('SELECT * FROM erp_expense_headers WHERE id = ?');
    $H->execute([$expenseId]);
    $h = $H->fetch(PDO::FETCH_ASSOC);
    if (!$h) {
        return ['success' => false, 'error' => 'Expense not found'];
    }
    $status = $h['status'] ?? 'draft';
    if ($status === 'cancelled') {
        return ['success' => false, 'error' => 'Cannot post cancelled expense'];
    }
    if ($status === 'posted' && !empty($h['journal_id'])) {
        return ['success' => false, 'error' => 'Expense already posted'];
    }
    if (!empty($h['journal_id'])) {
        return ['success' => false, 'error' => 'Expense already has a journal'];
    }
    $companyId = (int)$h['company_id'];
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.'];
    }

    $sourceModule = (string)($h['source_module'] ?? 'realestate');
    $paidVia = $h['paid_via'] ?? 'bank';
    if (erp_expense_is_legacy_archive($h)) {
        return ['success' => false, 'error' => 'Historical ERP Expenses are read-only and cannot be posted or changed.'];
    }
    if (in_array($sourceModule, ['realestate', 'construction'], true) && $paidVia === 'accounts_payable') {
        return [
            'success' => false,
            'error' => $sourceModule === 'construction'
                ? 'Quick Paid Expenses must be paid via cash, bank, or credit card. Use Supplier Invoices for unpaid AP.'
                : 'Quick Paid Expenses must be paid via bank or cash. Use Vendor Bills for unpaid AP.',
        ];
    }

    $built = erp_expense_build_journal_lines($db, $h);
    if (!$built['success']) {
        return ['success' => false, 'error' => $built['error']];
    }
    $lines = $built['lines'];

    $ref = !empty($h['expense_number']) ? $h['expense_number'] : ('#' . $expenseId);
    $memo = 'ERP Expense ' . $ref . (!empty($h['reference_no']) ? ' (Ref ' . $h['reference_no'] . ')' : '');
    $res = create_and_post_journal(
        $companyId,
        'expense',
        'erp_expense',
        $expenseId,
        $lines,
        $memo,
        $h['expense_date'],
        $userId
    );

    if (!$res['success']) {
        return ['success' => false, 'error' => $res['error'] ?? 'Post failed'];
    }

    $jid = (int)$res['journal_id'];
    $db->prepare('UPDATE erp_expense_headers SET journal_id = ?, status = \'posted\' WHERE id = ?')->execute([$jid, $expenseId]);

    return ['success' => true, 'journal_id' => $jid, 'error' => null];
}

/**
 * Replace the lines of an expense's posted journal in place (same journal number).
 * Returns null when the journal can't be safely rewritten (reversed, other satellite
 * rows, bank-reconciled, locked period) so the caller falls back to reverse + repost.
 *
 * @return array{success:bool,journal_id?:int,error?:string|null}|null
 */
function erp_rewrite_expense_journal(PDO $db, array $h, int $jid, ?int $userId): ?array {
    $expenseId = (int)$h['id'];
    $companyId = (int)$h['company_id'];

    $J = $db->prepare('SELECT * FROM re_journal_headers WHERE id = ? AND company_id = ?');
    $J->execute([$jid, $companyId]);
    $j = $J->fetch(PDO::FETCH_ASSOC);
    if (!$j || (int)$j['is_posted'] !== 1 || (int)$j['is_reversed'] !== 0
        || ($j['reference_type'] ?? '') !== 'erp_expense' || (int)$j['reference_id'] !== $expenseId) {
        return null;
    }
    if (is_period_locked($companyId, $j['journal_date']) || is_period_locked($companyId, $h['expense_date'])) {
        return null;
    }
    foreach ([
        'SELECT COUNT(*) FROM re_bank_reconciliation_matches m JOIN re_general_ledger g ON g.id = m.gl_line_id WHERE g.company_id = ? AND g.journal_id = ?',
        'SELECT COUNT(*) FROM re_account_ledger_entries WHERE company_id = ? AND journal_id = ?',
        'SELECT COUNT(*) FROM re_accounting_postings WHERE company_id = ? AND journal_id = ?',
    ] as $sql) {
        try {
            $c = $db->prepare($sql);
            $c->execute([$companyId, $jid]);
            if ((int)$c->fetchColumn() > 0) {
                return null;
            }
        } catch (Throwable $e) {
            // table may not exist
        }
    }

    $built = erp_expense_build_journal_lines($db, $h);
    if (!$built['success']) {
        return ['success' => false, 'error' => $built['error']];
    }
    $lines = $built['lines'];
    $totalDr = 0.0;
    $totalCr = 0.0;
    foreach ($lines as $ln) {
        $totalDr += (float)$ln['debit'];
        $totalCr += (float)$ln['credit'];
    }

    $ref = !empty($h['expense_number']) ? $h['expense_number'] : ('#' . $expenseId);
    $memo = 'ERP Expense ' . $ref . (!empty($h['reference_no']) ? ' (Ref ' . $h['reference_no'] . ')' : '');

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }
    try {
        $db->prepare('DELETE FROM re_general_ledger WHERE company_id = ? AND journal_id = ?')->execute([$companyId, $jid]);
        $db->prepare('DELETE FROM re_journal_lines WHERE company_id = ? AND journal_id = ?')->execute([$companyId, $jid]);
        $db->prepare('
            UPDATE re_journal_headers
            SET journal_date = ?, description = ?, total_debit = ?, total_credit = ?, is_posted = 0
            WHERE id = ? AND company_id = ?
        ')->execute([$h['expense_date'], $memo, round($totalDr, 2), round($totalCr, 2), $jid, $companyId]);

        $insL = $db->prepare('
            INSERT INTO re_journal_lines
            (company_id, journal_id, account_id, line_number, debit_amount, credit_amount, description, reference)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $n = 1;
        foreach ($lines as $ln) {
            $insL->execute([$companyId, $jid, $ln['account_id'], $n++, (float)$ln['debit'], (float)$ln['credit'], $ln['description'], null]);
        }

        // Re-posts the GL rows and marks the header posted again.
        $post = post_journal($jid, $userId);
        if (empty($post['success'])) {
            throw new RuntimeException($post['error'] ?? 'Journal repost failed');
        }
        $db->prepare('UPDATE erp_expense_headers SET status = \'posted\' WHERE id = ?')->execute([$expenseId]);
        accounting_audit_log($companyId, 'edit_journal', 'journal_header', $jid, $userId, 'Expense updated in place: ' . ($j['journal_number'] ?? ''));

        if ($ownTx) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }

    return ['success' => true, 'journal_id' => $jid, 'error' => null];
}

/**
 * @return array{success:bool,journal_id?:int,error?:string|null}
 */
function erp_repost_expense(PDO $db, int $expenseId, ?int $userId): array {
    $H = $db->prepare('SELECT * FROM erp_expense_headers WHERE id = ?');
    $H->execute([$expenseId]);
    $h = $H->fetch(PDO::FETCH_ASSOC);
    if (!$h) {
        return ['success' => false, 'error' => 'Expense not found'];
    }
    if (erp_expense_is_legacy_archive($h)) {
        return ['success' => false, 'error' => 'Historical ERP Expenses are read-only and cannot be reposted.'];
    }
    if (($h['status'] ?? '') === 'cancelled') {
        return ['success' => false, 'error' => 'Cannot repost cancelled expense'];
    }
    $jid = (int)($h['journal_id'] ?? 0);
    if ($jid > 0) {
        // Edit keeps one journal: rewrite it in place instead of reverse + new entry.
        $rewrite = erp_rewrite_expense_journal($db, $h, $jid, $userId);
        if ($rewrite !== null) {
            return $rewrite;
        }
        $rev = reverse_journal($jid, 'Expense updated', $userId, $h['expense_date']);
        if (!$rev['success']) {
            return ['success' => false, 'error' => $rev['error'] ?? 'Reverse failed'];
        }
        $db->prepare('UPDATE erp_expense_headers SET journal_id = NULL, status = \'draft\' WHERE id = ?')->execute([$expenseId]);
    }
    return erp_post_expense($db, $expenseId, $userId);
}

/**
 * Cancel: reverse journal if any, mark cancelled (excluded from active lists).
 *
 * @return array{success:bool,error?:string|null}
 */
function erp_cancel_expense(PDO $db, int $expenseId, ?int $userId): array {
    $H = $db->prepare('SELECT * FROM erp_expense_headers WHERE id = ?');
    $H->execute([$expenseId]);
    $h = $H->fetch(PDO::FETCH_ASSOC);
    if (!$h) {
        return ['success' => false, 'error' => 'Expense not found'];
    }
    if (erp_expense_is_legacy_archive($h)) {
        return ['success' => false, 'error' => 'Historical ERP Expenses are read-only and cannot be cancelled.'];
    }
    $jid = (int)($h['journal_id'] ?? 0);
    if ($jid > 0) {
        $rev = reverse_journal($jid, 'Expense cancelled', $userId, $h['expense_date']);
        if (!$rev['success']) {
            return ['success' => false, 'error' => $rev['error'] ?? 'Reverse failed'];
        }
    }
    $db->prepare('UPDATE erp_expense_headers SET status = \'cancelled\', journal_id = NULL WHERE id = ?')->execute([$expenseId]);
    return ['success' => true, 'error' => null];
}

/**
 * Permanently delete a Quick Paid / ERP expense after reversing any posted journal.
 * Keeps the reversing journal in the shared ledger (audit trail); removes operational rows only.
 *
 * @return array{success:bool,error?:string,message?:string,reversed?:bool}
 */
function erp_delete_expense(
    PDO $db,
    int $expenseId,
    int $companyId,
    ?int $userId = null,
    ?string $requireSourceModule = null
): array {
    if ($companyId <= 0 || $expenseId <= 0) {
        return ['success' => false, 'error' => 'Company and expense are required.'];
    }

    $ownTx = !$db->inTransaction();
    try {
        if ($ownTx) {
            $db->beginTransaction();
        }

        $H = $db->prepare('SELECT * FROM erp_expense_headers WHERE id = ? AND company_id = ? FOR UPDATE');
        $H->execute([$expenseId, $companyId]);
        $h = $H->fetch(PDO::FETCH_ASSOC);
        if (!$h) {
            throw new RuntimeException('Expense not found.');
        }
        if ($requireSourceModule !== null && (string)($h['source_module'] ?? '') !== $requireSourceModule) {
            throw new RuntimeException('Expense is not available for this module.');
        }
        if (erp_expense_is_legacy_archive($h)) {
            throw new RuntimeException('Historical ERP Expenses are read-only and cannot be deleted.');
        }

        $jid = (int)($h['journal_id'] ?? 0);
        $status = (string)($h['status'] ?? '');
        $reversed = false;

        if ($status === 'posted' || $jid > 0) {
            if ($jid > 0) {
                $js = $db->prepare('SELECT id, company_id FROM re_journal_headers WHERE id = ? LIMIT 1');
                $js->execute([$jid]);
                $jrow = $js->fetch(PDO::FETCH_ASSOC);
                if ($jrow && (int)$jrow['company_id'] !== $companyId) {
                    throw new RuntimeException('Linked journal belongs to another company.');
                }
            }
            $cancel = erp_cancel_expense($db, $expenseId, $userId);
            if (empty($cancel['success'])) {
                throw new RuntimeException($cancel['error'] ?? 'Could not reverse expense journal.');
            }
            $reversed = ($jid > 0);
        }

        try {
            $db->prepare('DELETE FROM erp_expense_attachments WHERE expense_id = ?')->execute([$expenseId]);
        } catch (Throwable $e) {
            // Attachments table may not exist in older schemas.
        }
        $db->prepare('DELETE FROM erp_expense_lines WHERE expense_id = ?')->execute([$expenseId]);
        $del = $db->prepare('DELETE FROM erp_expense_headers WHERE id = ? AND company_id = ?');
        $del->execute([$expenseId, $companyId]);
        if ($del->rowCount() < 1) {
            throw new RuntimeException('Expense could not be deleted.');
        }

        if ($ownTx) {
            $db->commit();
        }

        $label = trim((string)($h['expense_number'] ?? ''));
        $msg = ($label !== '' ? $label : ('Expense #' . $expenseId)) . ' deleted.';
        if ($reversed) {
            $msg .= ' Its posted journal was reversed.';
        }

        $sourceModule = (string)($h['source_module'] ?? '');
        $details = $msg
            . ' [module=' . $sourceModule
            . '; status_before=' . $status
            . '; journal_id_before=' . $jid
            . '; reversed=' . ($reversed ? '1' : '0')
            . ']';
        accounting_audit_log(
            $companyId,
            'delete_expense',
            'erp_expense',
            $expenseId,
            $userId,
            $details
        );

        return ['success' => true, 'message' => $msg, 'reversed' => $reversed];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Whether a shared-ledger journal can be physically removed (duplicate-cleanup path).
 *
 * @return array{ok:bool,error?:string,journal?:array}
 */
function erp_journal_hard_delete_precheck(PDO $db, int $companyId, int $journalId, int $expenseId): array
{
    if ($companyId <= 0 || $journalId <= 0) {
        return ['ok' => false, 'error' => 'Journal and company are required.'];
    }

    $js = $db->prepare('SELECT * FROM re_journal_headers WHERE id = ? LIMIT 1');
    $js->execute([$journalId]);
    $j = $js->fetch(PDO::FETCH_ASSOC);
    if (!$j) {
        return ['ok' => false, 'error' => 'Journal #' . $journalId . ' not found.'];
    }
    if ((int)$j['company_id'] !== $companyId) {
        return ['ok' => false, 'error' => 'Journal belongs to another company.'];
    }

    $refType = (string)($j['reference_type'] ?? '');
    $refId = (int)($j['reference_id'] ?? 0);
    // Allow original erp_expense journals and their reversal journals (same reference).
    if ($refType !== 'erp_expense' || $refId !== $expenseId) {
        return ['ok' => false, 'error' => 'Journal is not exclusively linked to this Quick Paid Expense.'];
    }

    if (is_period_locked($companyId, (string)$j['journal_date'])) {
        return ['ok' => false, 'error' => 'Cannot hard-delete journal ' . ($j['journal_number'] ?? ('#' . $journalId)) . ': period is locked.'];
    }

    // Bank reconciliation on GL lines
    try {
        $reco = $db->prepare("
            SELECT COUNT(*) FROM re_general_ledger
            WHERE company_id = ? AND journal_id = ? AND COALESCE(is_reconciled, 0) = 1
        ");
        $reco->execute([$companyId, $journalId]);
        if ((int)$reco->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'Journal is bank-reconciled (GL flagged). Unreconcile first.'];
        }
    } catch (Throwable $e) {
        // is_reconciled column may be absent on older DBs
    }

    try {
        $match = $db->prepare("
            SELECT COUNT(*) FROM re_bank_reconciliation_matches m
            INNER JOIN re_general_ledger gl ON gl.id = m.gl_line_id AND gl.company_id = m.company_id
            WHERE m.company_id = ? AND gl.journal_id = ? AND m.status = 'confirmed'
        ");
        $match->execute([$companyId, $journalId]);
        if ((int)$match->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'Journal has confirmed bank reconciliation matches. Unmatch first.'];
        }
    } catch (Throwable $e) {
        // matches table may be absent
    }

    // Other documents pointing at this journal
    $otherExpense = $db->prepare("
        SELECT id FROM erp_expense_headers
        WHERE journal_id = ? AND id <> ? AND company_id = ?
        LIMIT 1
    ");
    $otherExpense->execute([$journalId, $expenseId, $companyId]);
    if ($otherExpense->fetchColumn()) {
        return ['ok' => false, 'error' => 'Journal is linked to another expense.'];
    }

    foreach (
        [
            're_vendor_payments' => 'vendor payment',
            're_vendor_invoices' => 'vendor bill',
            'co_supplier_payments' => 'supplier payment',
            'co_supplier_invoices' => 'supplier invoice',
        ] as $table => $label
    ) {
        try {
            $st = $db->prepare("SELECT id FROM {$table} WHERE journal_id = ? AND company_id = ? LIMIT 1");
            $st->execute([$journalId, $companyId]);
            if ($st->fetchColumn()) {
                return ['ok' => false, 'error' => 'Journal is linked to a ' . $label . '.'];
            }
        } catch (Throwable $e) {
            // column may not exist on invoices
        }
    }

    return ['ok' => true, 'journal' => $j];
}

/**
 * Physically remove a journal and its GL lines (shared re_* stack). Caller must precheck.
 */
function erp_purge_journal_rows(PDO $db, int $companyId, int $journalId): void
{
    // Clear reversal pointers that reference this journal
    try {
        $db->prepare("
            UPDATE re_journal_headers
            SET reversal_journal_id = NULL
            WHERE company_id = ? AND reversal_journal_id = ?
        ")->execute([$companyId, $journalId]);
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $db->prepare('DELETE FROM re_bank_reconciliation_matches WHERE company_id = ? AND gl_line_id IN (SELECT id FROM re_general_ledger WHERE company_id = ? AND journal_id = ?)')
            ->execute([$companyId, $companyId, $journalId]);
    } catch (Throwable $e) {
        // MySQL may reject subquery delete; fallback below
        try {
            $ids = $db->prepare('SELECT id FROM re_general_ledger WHERE company_id = ? AND journal_id = ?');
            $ids->execute([$companyId, $journalId]);
            $glIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($glIds) {
                $ph = implode(',', array_fill(0, count($glIds), '?'));
                $db->prepare("DELETE FROM re_bank_reconciliation_matches WHERE company_id = ? AND gl_line_id IN ({$ph})")
                    ->execute(array_merge([$companyId], $glIds));
            }
        } catch (Throwable $e2) {
            // ignore
        }
    }

    foreach ([
        'DELETE FROM re_general_ledger WHERE company_id = ? AND journal_id = ?',
        'DELETE FROM re_account_ledger_entries WHERE company_id = ? AND journal_id = ?',
        'DELETE FROM re_accounting_postings WHERE company_id = ? AND journal_id = ?',
        'DELETE FROM re_journal_lines WHERE company_id = ? AND journal_id = ?',
        'DELETE FROM re_journal_headers WHERE company_id = ? AND id = ?',
    ] as $sql) {
        try {
            $db->prepare($sql)->execute([$companyId, $journalId]);
        } catch (Throwable $e) {
            // some satellite tables may not exist
            if (strpos($sql, 're_journal_headers') !== false || strpos($sql, 're_journal_lines') !== false || strpos($sql, 're_general_ledger') !== false) {
                throw $e;
            }
        }
    }
}

/**
 * Hard-delete a Quick Paid Expense and its linked journal(s) from the shared ledger.
 * Used for confirmed QPE↔AP duplicate cleanup (no reversal clutter).
 *
 * @return array{success:bool,error?:string,message?:string,purged_journal_ids?:list<int>}
 */
function erp_hard_delete_expense_with_journal(
    PDO $db,
    int $expenseId,
    int $companyId,
    ?int $userId = null,
    ?string $requireSourceModule = 'realestate',
    bool $allowLegacyArchiveOverride = false
): array {
    if ($companyId <= 0 || $expenseId <= 0) {
        return ['success' => false, 'error' => 'Company and expense are required.'];
    }

    $ownTx = !$db->inTransaction();
    try {
        if ($ownTx) {
            $db->beginTransaction();
        }

        $H = $db->prepare('SELECT * FROM erp_expense_headers WHERE id = ? AND company_id = ? FOR UPDATE');
        $H->execute([$expenseId, $companyId]);
        $h = $H->fetch(PDO::FETCH_ASSOC);
        if (!$h) {
            throw new RuntimeException('Expense not found.');
        }
        if ($requireSourceModule !== null && (string)($h['source_module'] ?? '') !== $requireSourceModule) {
            throw new RuntimeException('Expense is not available for this module.');
        }
        $isLegacyArchive = erp_expense_is_legacy_archive($h);
        if ($isLegacyArchive && !$allowLegacyArchiveOverride) {
            throw new RuntimeException('Historical ERP Expenses are read-only and cannot be deleted.');
        }

        // Collect journals: current link + any erp_expense reference (includes already-reversed originals)
        $journalIds = [];
        $linkedId = (int)($h['journal_id'] ?? 0);
        if ($linkedId > 0) {
            $journalIds[$linkedId] = true;
        }
        $refJ = $db->prepare("
            SELECT id, reversal_journal_id
            FROM re_journal_headers
            WHERE company_id = ? AND reference_type = 'erp_expense' AND reference_id = ?
        ");
        $refJ->execute([$companyId, $expenseId]);
        foreach ($refJ->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rj) {
            $jid = (int)$rj['id'];
            if ($jid > 0) {
                $journalIds[$jid] = true;
            }
            $revId = (int)($rj['reversal_journal_id'] ?? 0);
            if ($revId > 0) {
                $journalIds[$revId] = true;
            }
        }

        $purgeIds = array_keys($journalIds);
        sort($purgeIds);

        foreach ($purgeIds as $jid) {
            $pre = erp_journal_hard_delete_precheck($db, $companyId, (int)$jid, $expenseId);
            if (empty($pre['ok'])) {
                throw new RuntimeException($pre['error'] ?? 'Journal cannot be hard-deleted.');
            }
        }

        // Detach expense link before journal purge
        $db->prepare('UPDATE erp_expense_headers SET journal_id = NULL WHERE id = ? AND company_id = ?')
            ->execute([$expenseId, $companyId]);

        // Purge reversal journals first, then originals (FK-safe)
        $ordered = $purgeIds;
        usort($ordered, static function ($a, $b) use ($db, $companyId) {
            $sa = $db->prepare('SELECT is_reversed, reversal_journal_id FROM re_journal_headers WHERE id = ? AND company_id = ?');
            $sa->execute([(int)$a, $companyId]);
            $ra = $sa->fetch(PDO::FETCH_ASSOC) ?: [];
            $sb = $db->prepare('SELECT is_reversed, reversal_journal_id FROM re_journal_headers WHERE id = ? AND company_id = ?');
            $sb->execute([(int)$b, $companyId]);
            $rb = $sb->fetch(PDO::FETCH_ASSOC) ?: [];
            // Delete non-reversed "reversal" children (pointed to by others) first: prefer deleting ids that are someone's reversal_journal_id
            $aIsRevTarget = 0;
            $bIsRevTarget = 0;
            $chk = $db->prepare('SELECT COUNT(*) FROM re_journal_headers WHERE company_id = ? AND reversal_journal_id = ?');
            $chk->execute([$companyId, (int)$a]);
            $aIsRevTarget = (int)$chk->fetchColumn();
            $chk->execute([$companyId, (int)$b]);
            $bIsRevTarget = (int)$chk->fetchColumn();
            if ($aIsRevTarget !== $bIsRevTarget) {
                return $bIsRevTarget <=> $aIsRevTarget; // targets of reversal_journal_id first
            }
            return ((int)($ra['is_reversed'] ?? 0)) <=> ((int)($rb['is_reversed'] ?? 0));
        });

        foreach ($ordered as $jid) {
            // Re-check existence (may already be gone if cascade-like)
            $exists = $db->prepare('SELECT id FROM re_journal_headers WHERE id = ? AND company_id = ?');
            $exists->execute([(int)$jid, $companyId]);
            if (!$exists->fetchColumn()) {
                continue;
            }
            erp_purge_journal_rows($db, $companyId, (int)$jid);
        }

        try {
            $db->prepare('DELETE FROM erp_expense_attachments WHERE expense_id = ?')->execute([$expenseId]);
        } catch (Throwable $e) {
            // optional table
        }
        $db->prepare('DELETE FROM erp_expense_lines WHERE expense_id = ?')->execute([$expenseId]);
        $del = $db->prepare('DELETE FROM erp_expense_headers WHERE id = ? AND company_id = ?');
        $del->execute([$expenseId, $companyId]);
        if ($del->rowCount() < 1) {
            throw new RuntimeException('Expense could not be deleted.');
        }

        if ($ownTx) {
            $db->commit();
        }

        $label = trim((string)($h['expense_number'] ?? ''));
        $msg = ($label !== '' ? $label : ('Expense #' . $expenseId)) . ' hard-deleted';
        if ($ordered) {
            $msg .= ' with ' . count($ordered) . ' journal(s) removed from the ledger.';
        } else {
            $msg .= ' (no journal was linked).';
        }

        $details = $msg
            . ' [module=' . (string)($h['source_module'] ?? '')
            . '; purged_journals=' . implode(',', array_map('intval', $ordered))
            . '; mode=hard_delete_duplicate_cleanup'
            . ($isLegacyArchive ? '; legacy_archive_override=1' : '')
            . ']';
        accounting_audit_log(
            $companyId,
            'hard_delete_expense',
            'erp_expense',
            $expenseId,
            $userId,
            $details
        );

        return [
            'success' => true,
            'message' => $msg,
            'purged_journal_ids' => array_map('intval', $ordered),
        ];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** @deprecated use erp_cancel_expense */
function erp_void_expense(PDO $db, int $expenseId, ?int $userId): array {
    return erp_cancel_expense($db, $expenseId, $userId);
}
