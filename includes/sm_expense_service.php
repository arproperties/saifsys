<?php
/**
 * Service Management Phase 7 — expense workflow (draft, approval, post, prepaid).
 */

require_once __DIR__ . '/service_management_settings.php';
require_once __DIR__ . '/cleaning_accounting_context.php';
require_once __DIR__ . '/gl_posting.php';
require_once __DIR__ . '/work_order_financial_guard.php';
require_once __DIR__ . '/sm_prepaid_service.php';

if (!function_exists('sm_expense_requires_approval')) {
    function sm_expense_requires_approval(PDO $conn): bool
    {
        return sm_get_setting($conn, 'sm_expense_requires_approval', '0') === '1';
    }
}

if (!function_exists('sm_prepaid_asset_account')) {
    /** Default prepaid asset account for new prepaid expenses (when user does not pick one). */
    function sm_prepaid_asset_account(PDO $conn): string
    {
        $configured = trim(sm_get_setting($conn, 'sm_prepaid_asset_account', '1240'));
        // Legacy mistaken default was Furniture & Fixtures (1310). Guide users to a real prepaid asset.
        if ($configured === '' || $configured === '1310') {
            return '1240';
        }
        return $configured;
    }
}

if (!function_exists('sm_prepaid_asset_account_label')) {
    function sm_prepaid_asset_account_label(PDO $conn, ?string $accountNo = null): string
    {
        $no = $accountNo !== null && $accountNo !== '' ? $accountNo : sm_prepaid_asset_account($conn);
        $st = $conn->prepare('SELECT name FROM chart_of_accounts WHERE account_no = ? LIMIT 1');
        $st->execute([$no]);
        $name = (string)($st->fetchColumn() ?: '');
        return $name !== '' ? ($no . ' — ' . $name) : $no;
    }
}

if (!function_exists('sm_prepaid_asset_options')) {
    /**
     * Suggested prepaid / deposit asset accounts for dropdowns.
     *
     * @return array<int,array{account_no:string,name:string}>
     */
    function sm_prepaid_asset_options(PDO $conn): array
    {
        $preferred = ['1210', '1220', '1230', '1240', '1200'];
        $st = $conn->query("
            SELECT account_no, name
            FROM chart_of_accounts
            WHERE type = 'Asset' AND is_header = 0 AND is_active = 1
              AND (
                account_no IN ('1210','1220','1230','1240')
                OR LOWER(name) LIKE '%prepaid%'
                OR LOWER(name) LIKE '%deposit%'
                OR LOWER(name) LIKE '%advance%'
              )
            ORDER BY
              FIELD(account_no, '1210','1220','1230','1240') = 0,
              FIELD(account_no, '1210','1220','1230','1240'),
              account_no
        ");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            // Fallback: any non-header asset under 12xx
            $st = $conn->query("
                SELECT account_no, name FROM chart_of_accounts
                WHERE type='Asset' AND is_header=0 AND is_active=1 AND account_no LIKE '12%'
                ORDER BY account_no
            ");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return $rows;
    }
}

if (!function_exists('sm_resolve_prepaid_asset_account')) {
    /** Resolve prepaid asset for an expense: explicit column → setting default. */
    function sm_resolve_prepaid_asset_account(PDO $conn, array $expense): string
    {
        $picked = trim((string)($expense['prepaid_asset_account_no'] ?? ''));
        if ($picked !== '') {
            return $picked;
        }
        return sm_prepaid_asset_account($conn);
    }
}

if (!function_exists('sm_ensure_prepaid_asset_column')) {
    function sm_ensure_prepaid_asset_column(PDO $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $st = $conn->query("SHOW COLUMNS FROM expenses LIKE 'prepaid_asset_account_no'");
            if (!$st->fetch()) {
                $conn->exec("ALTER TABLE expenses ADD COLUMN prepaid_asset_account_no VARCHAR(20) NULL AFTER prepaid_expense_account_no");
            }
        } catch (Throwable $e) {
            // ignore if already exists / no permission mid-request
        }
        $done = true;
    }
}

if (!function_exists('sm_user_can_approve_expense')) {
    function sm_user_can_approve_expense(PDO $conn): bool
    {
        return sm_user_can_finalize($conn);
    }
}

if (!function_exists('sm_expense_load')) {
    function sm_expense_load(PDO $conn, int $expenseId): ?array
    {
        $st = $conn->prepare('SELECT * FROM expenses WHERE id = ? LIMIT 1');
        $st->execute([$expenseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('sm_gl_post_prepaid_expense')) {
    /** Prepaid: Dr prepaid asset + VAT recoverable, Cr payment account (not expense yet). */
    function sm_gl_post_prepaid_expense(PDO $conn, array $expense, string $prepaidAccountNo): int
    {
        $expenseId = (int)$expense['id'];
        $date = $expense['expense_date'];
        $subtotal = round((float)$expense['subtotal'], 2);
        $vatTotal = round((float)$expense['vat_amount'], 2);
        $total = round((float)$expense['total'], 2);

        if (($expense['paid_via'] ?? 'bank') === 'ap') {
            $creditNo = '2000';
        } else {
            $pay = trim((string)($expense['pay_account_no'] ?? ''));
            $creditNo = $pay !== '' ? $pay : (($expense['paid_via'] ?? '') === 'cash' ? '1010' : '1020');
        }

        $glLines = [];
        if ($subtotal > 0) {
            $glLines[] = [
                'account_id' => coa_id($conn, $prepaidAccountNo),
                'desc' => 'Prepaid expense asset',
                'debit' => $subtotal,
                'credit' => 0,
            ];
        }
        if ($vatTotal > 0) {
            $glLines[] = [
                'account_id' => coa_id($conn, '1260'),
                'desc' => 'VAT Recoverable',
                'debit' => $vatTotal,
                'credit' => 0,
            ];
        }
        $glLines[] = [
            'account_id' => coa_id($conn, $creditNo),
            'desc' => 'Payment / Liability',
            'debit' => 0,
            'credit' => $total,
        ];

        $companyId = (int)($expense['company_id'] ?? cleaning_accounting_company_id($conn));
        return gl_create_journal($conn, [
            'date' => $date,
            'source' => 'expense',
            'source_id' => $expenseId,
            'memo' => 'Prepaid expense #' . $expenseId . ($expense['reference_no'] ? ' (Ref ' . $expense['reference_no'] . ')' : ''),
            'created_by' => $expense['created_by'] ?? null,
            'company_id' => $companyId,
        ], $glLines);
    }
}

if (!function_exists('sm_expense_create_prepaid_schedule')) {
    function sm_expense_create_prepaid_schedule(PDO $conn, array $expense): ?int
    {
        if (!sm_prepaid_table_exists($conn)) {
            return null;
        }

        $months = max(1, (int)($expense['prepaid_months'] ?? 12));
        $expenseAcct = trim((string)($expense['prepaid_expense_account_no'] ?? ''));
        if ($expenseAcct === '') {
            $lineSt = $conn->prepare('SELECT account_no FROM expense_lines WHERE expense_id = ? ORDER BY line_no LIMIT 1');
            $lineSt->execute([(int)$expense['id']]);
            $expenseAcct = (string)$lineSt->fetchColumn() ?: '5100';
        }

        $total = round((float)$expense['subtotal'], 2);
        $monthly = round($total / $months, 2);
        $companyId = (int)($expense['company_id'] ?? cleaning_accounting_company_id($conn));

        $st = $conn->prepare("
            INSERT INTO sm_prepaid_schedules
              (company_id, expense_id, vendor_id, description, start_date, months, total_amount, monthly_amount,
               prepaid_account_no, expense_account_no, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
        ");
        $desc = 'Prepaid from expense #' . (int)$expense['id'];
        if (!empty($expense['reference_no'])) {
            $desc .= ' — ' . $expense['reference_no'];
        }
        $st->execute([
            $companyId,
            (int)$expense['id'],
            $expense['vendor_id'] ?: null,
            $desc,
            $expense['expense_date'],
            $months,
            $total,
            $monthly,
            sm_resolve_prepaid_asset_account($conn, $expense),
            $expenseAcct,
            $expense['created_by'] ?? null,
        ]);
        $scheduleId = (int)$conn->lastInsertId();

        $periodStart = new DateTime((string)$expense['expense_date']);
        $insAmort = $conn->prepare("
            INSERT INTO sm_prepaid_amortization (schedule_id, period_month, amount, status)
            VALUES (?, ?, ?, 'pending')
        ");
        for ($i = 0; $i < $months; $i++) {
            $period = (clone $periodStart)->modify("+{$i} month")->format('Y-m');
            $amt = ($i === $months - 1)
                ? round($total - ($monthly * ($months - 1)), 2)
                : $monthly;
            $insAmort->execute([$scheduleId, $period, $amt]);
        }

        return $scheduleId;
    }
}

if (!function_exists('sm_expense_post_to_gl')) {
    /**
     * Post approved expense to GL. Creates prepaid schedule when expense_type = prepaid.
     *
     * @return array{success:bool, message:string, journal_id?:int, schedule_id?:int}
     */
    function sm_expense_post_to_gl(PDO $conn, int $expenseId, ?int $userId = null): array
    {
        $expense = sm_expense_load($conn, $expenseId);
        if (!$expense) {
            return ['success' => false, 'message' => 'Expense not found.'];
        }
        if (($expense['status'] ?? '') === 'posted') {
            return ['success' => false, 'message' => 'Expense is already posted.'];
        }
        if (($expense['status'] ?? '') === 'void') {
            return ['success' => false, 'message' => 'Cannot post a voided expense.'];
        }

        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);
        $type = (string)($expense['expense_type'] ?? 'operating');

        try {
            if ($type === 'prepaid') {
                sm_ensure_prepaid_asset_column($conn);
                $assetNo = sm_resolve_prepaid_asset_account($conn, $expense);
                $jid = sm_gl_post_prepaid_expense($conn, $expense, $assetNo);
                $conn->prepare("UPDATE expenses SET gl_journal_id = ?, status = 'posted', approved_by = COALESCE(approved_by, ?), approved_at = COALESCE(approved_at, NOW()) WHERE id = ?")
                    ->execute([$jid, $userId, $expenseId]);
                $scheduleId = sm_expense_create_prepaid_schedule($conn, $expense);
            } else {
                gl_post_expense($conn, $expenseId);
                $jidSt = $conn->prepare('SELECT gl_journal_id FROM expenses WHERE id = ?');
                $jidSt->execute([$expenseId]);
                $jid = (int)$jidSt->fetchColumn();
                $conn->prepare("UPDATE expenses SET approved_by = COALESCE(approved_by, ?), approved_at = COALESCE(approved_at, NOW()) WHERE id = ?")
                    ->execute([$userId, $expenseId]);
                $scheduleId = null;
            }

            require_once __DIR__ . '/service_accounting_service.php';
            (new ServiceAccountingService($conn))->invalidateFinancialCache();

            return [
                'success' => true,
                'message' => $type === 'prepaid'
                    ? 'Prepaid expense posted. Amortization schedule #' . ($scheduleId ?? '—') . ' created.'
                    : 'Expense posted to GL.',
                'journal_id' => $jid ?? null,
                'schedule_id' => $scheduleId,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('sm_expense_submit_for_approval')) {
    function sm_expense_submit_for_approval(PDO $conn, int $expenseId): array
    {
        $expense = sm_expense_load($conn, $expenseId);
        if (!$expense) {
            return ['success' => false, 'message' => 'Expense not found.'];
        }
        if (!in_array($expense['status'], ['draft'], true)) {
            return ['success' => false, 'message' => 'Only draft expenses can be submitted.'];
        }
        $conn->prepare("UPDATE expenses SET status = 'pending_approval', submitted_at = NOW() WHERE id = ?")
            ->execute([$expenseId]);
        return ['success' => true, 'message' => 'Expense submitted for approval.'];
    }
}

if (!function_exists('sm_expense_approve_and_post')) {
    function sm_expense_approve_and_post(PDO $conn, int $expenseId, ?int $userId = null): array
    {
        if (!sm_user_can_approve_expense($conn)) {
            return ['success' => false, 'message' => 'Only Admin or Accountant can approve expenses.'];
        }
        $expense = sm_expense_load($conn, $expenseId);
        if (!$expense) {
            return ['success' => false, 'message' => 'Expense not found.'];
        }
        if (!in_array($expense['status'], ['draft', 'pending_approval'], true)) {
            return ['success' => false, 'message' => 'Expense cannot be approved (status: ' . ($expense['status'] ?? '') . ').'];
        }

        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);
        $conn->prepare("UPDATE expenses SET approved_by = ?, approved_at = NOW() WHERE id = ?")
            ->execute([$userId, $expenseId]);

        return sm_expense_post_to_gl($conn, $expenseId, $userId);
    }
}

if (!function_exists('sm_expense_prepaid_schedule')) {
    /** Active prepaid schedule linked to an expense (if any). */
    function sm_expense_prepaid_schedule(PDO $conn, int $expenseId): ?array
    {
        if (!sm_prepaid_table_exists($conn) || $expenseId <= 0) {
            return null;
        }
        $st = $conn->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM sm_prepaid_amortization a WHERE a.schedule_id = s.id AND a.status = 'posted') AS posted_cnt,
                   (SELECT COUNT(*) FROM sm_prepaid_amortization a WHERE a.schedule_id = s.id AND a.status = 'pending') AS pending_cnt
            FROM sm_prepaid_schedules s
            WHERE s.expense_id = ?
            ORDER BY s.id DESC
            LIMIT 1
        ");
        $st->execute([$expenseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('sm_prepaid_cancel_schedule_if_safe')) {
    /**
     * Cancel prepaid schedule when no amortization has been posted yet.
     * @return array{success:bool, message:string, cancelled?:bool}
     */
    function sm_prepaid_cancel_schedule_if_safe(PDO $conn, int $scheduleId): array
    {
        if (!sm_prepaid_table_exists($conn) || $scheduleId <= 0) {
            return ['success' => true, 'message' => 'No schedule.', 'cancelled' => false];
        }
        $st = $conn->prepare("SELECT COUNT(*) FROM sm_prepaid_amortization WHERE schedule_id = ? AND status = 'posted'");
        $st->execute([$scheduleId]);
        if ((int)$st->fetchColumn() > 0) {
            return [
                'success' => false,
                'message' => 'Cannot rebuild schedule: amortization already posted. Void the expense or reverse posted amortization first.',
                'cancelled' => false,
            ];
        }
        $conn->prepare("DELETE FROM sm_prepaid_amortization WHERE schedule_id = ? AND status = 'pending'")
            ->execute([$scheduleId]);
        $conn->prepare("UPDATE sm_prepaid_schedules SET status = 'cancelled' WHERE id = ?")
            ->execute([$scheduleId]);
        return ['success' => true, 'message' => 'Previous schedule cancelled.', 'cancelled' => true];
    }
}

if (!function_exists('sm_expense_repost')) {
    /**
     * Reverse existing expense GL and post again (operating or prepaid).
     * For prepaid: rebuilds schedule only when no amortization has been posted.
     *
     * @return array{success:bool, message:string}
     */
    function sm_expense_repost(PDO $conn, int $expenseId, ?int $userId = null): array
    {
        $expense = sm_expense_load($conn, $expenseId);
        if (!$expense) {
            return ['success' => false, 'message' => 'Expense not found.'];
        }
        if (($expense['status'] ?? '') === 'void') {
            return ['success' => false, 'message' => 'Cannot edit a voided expense.'];
        }

        $type = (string)($expense['expense_type'] ?? 'operating');
        $schedule = sm_expense_prepaid_schedule($conn, $expenseId);

        if ($type === 'prepaid' && $schedule && (int)($schedule['posted_cnt'] ?? 0) > 0) {
            return [
                'success' => false,
                'message' => 'This prepaid already has posted amortization. Void entries and rebuild positions via Prepaid Schedules / void, or ask an accountant to reverse amortization journals first.',
            ];
        }

        $ownTxn = !$conn->inTransaction();
        try {
            if ($ownTxn) {
                $conn->beginTransaction();
            }

            foreach (gl_find_expense_journals($conn, $expenseId) as $jid) {
                gl_reverse_journal($conn, $jid);
            }

            if ($schedule && in_array((string)$schedule['status'], ['active', 'completed'], true)) {
                $cancel = sm_prepaid_cancel_schedule_if_safe($conn, (int)$schedule['id']);
                if (!$cancel['success']) {
                    throw new RuntimeException($cancel['message']);
                }
            }

            if ($type === 'prepaid') {
                sm_ensure_prepaid_asset_column($conn);
                $assetNo = sm_resolve_prepaid_asset_account($conn, $expense);
                $jid = sm_gl_post_prepaid_expense($conn, $expense, $assetNo);
                $conn->prepare("UPDATE expenses SET gl_journal_id = ?, status = 'posted' WHERE id = ?")
                    ->execute([$jid, $expenseId]);
                sm_expense_create_prepaid_schedule($conn, $expense);
                $msg = 'Prepaid expense updated and re-posted. Amortization schedule rebuilt.';
            } else {
                gl_post_expense($conn, $expenseId);
                $msg = 'Expense updated and re-posted to GL.';
            }

            if ($ownTxn) {
                $conn->commit();
            }

            require_once __DIR__ . '/service_accounting_service.php';
            (new ServiceAccountingService($conn))->invalidateFinancialCache();

            return ['success' => true, 'message' => $msg];
        } catch (Throwable $e) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
