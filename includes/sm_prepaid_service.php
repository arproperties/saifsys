<?php
/**
 * Service Management Phase 7 — prepaid amortization schedules.
 */

require_once __DIR__ . '/cleaning_accounting_context.php';
require_once __DIR__ . '/gl_posting.php';
require_once __DIR__ . '/work_order_financial_guard.php';

if (!function_exists('sm_prepaid_table_exists')) {
    function sm_prepaid_table_exists(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $st = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sm_prepaid_schedules'");
        $st->execute();
        return $ok = ((int)$st->fetchColumn() > 0);
    }
}

if (!function_exists('sm_prepaid_due_summary')) {
    /**
     * Pending amortization rows for a calendar month (default: current month).
     *
     * @return array{period:string, count:int, total:float, items:array<int, array>}
     */
    function sm_prepaid_due_summary(PDO $conn, ?string $periodMonth = null): array
    {
        $periodMonth = $periodMonth ?: date('Y-m');
        $empty = ['period' => $periodMonth, 'count' => 0, 'total' => 0.0, 'items' => []];
        if (!sm_prepaid_table_exists($conn)) {
            return $empty;
        }

        $companyId = cleaning_accounting_company_id($conn);
        $st = $conn->prepare("
            SELECT a.schedule_id, a.amount, s.description, s.expense_account_no,
                   v.name AS vendor_name
            FROM sm_prepaid_amortization a
            INNER JOIN sm_prepaid_schedules s ON s.id = a.schedule_id
            LEFT JOIN vendors v ON v.id = s.vendor_id
            WHERE a.period_month = ?
              AND a.status = 'pending'
              AND s.status = 'active'
              AND s.company_id = ?
            ORDER BY s.id
        ");
        $st->execute([$periodMonth, $companyId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $total = 0.0;
        foreach ($items as $row) {
            $total += round((float)$row['amount'], 2);
        }

        return [
            'period' => $periodMonth,
            'count' => count($items),
            'total' => round($total, 2),
            'items' => $items,
        ];
    }
}

if (!function_exists('sm_prepaid_run_amortization')) {
    /**
     * Post pending amortization entries for a given period (default: current month).
     *
     * @return array{success:bool, message:string, posted:int, errors:array}
     */
    function sm_prepaid_run_amortization(PDO $conn, ?string $periodMonth = null, ?int $userId = null): array
    {
        if (!sm_prepaid_table_exists($conn)) {
            return ['success' => false, 'message' => 'Prepaid tables not installed.', 'posted' => 0, 'errors' => []];
        }

        $periodMonth = $periodMonth ?: date('Y-m');
        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);
        $companyId = cleaning_accounting_company_id($conn);

        $st = $conn->prepare("
            SELECT a.*, s.prepaid_account_no, s.expense_account_no, s.description AS sched_desc
            FROM sm_prepaid_amortization a
            INNER JOIN sm_prepaid_schedules s ON s.id = a.schedule_id
            WHERE a.period_month = ?
              AND a.status = 'pending'
              AND s.status = 'active'
              AND s.company_id = ?
        ");
        $st->execute([$periodMonth, $companyId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $posted = 0;
        $errors = [];

        foreach ($rows as $row) {
            $amt = round((float)$row['amount'], 2);
            if ($amt <= 0) {
                $conn->prepare("UPDATE sm_prepaid_amortization SET status = 'skipped' WHERE id = ?")->execute([(int)$row['id']]);
                continue;
            }

            try {
                $glLines = [
                    [
                        'account_id' => coa_id($conn, $row['expense_account_no']),
                        'desc' => 'Prepaid amortization — ' . ($row['sched_desc'] ?? ''),
                        'debit' => $amt,
                        'credit' => 0,
                    ],
                    [
                        'account_id' => coa_id($conn, $row['prepaid_account_no']),
                        'desc' => 'Prepaid amortization',
                        'debit' => 0,
                        'credit' => $amt,
                    ],
                ];

                $jid = gl_create_journal($conn, [
                    'date' => $periodMonth . '-01',
                    'source' => 'manual',
                    'source_id' => (int)$row['schedule_id'],
                    'memo' => 'Prepaid amortization ' . $periodMonth . ' — schedule #' . $row['schedule_id'],
                    'created_by' => $userId,
                    'company_id' => $companyId,
                ], $glLines);

                $conn->prepare("
                    UPDATE sm_prepaid_amortization
                    SET status = 'posted', gl_journal_id = ?, amortized_at = NOW()
                    WHERE id = ?
                ")->execute([$jid, (int)$row['id']]);

                $pendingSt = $conn->prepare("SELECT COUNT(*) FROM sm_prepaid_amortization WHERE schedule_id = ? AND status = 'pending'");
                $pendingSt->execute([(int)$row['schedule_id']]);
                if ((int)$pendingSt->fetchColumn() === 0) {
                    $conn->prepare("UPDATE sm_prepaid_schedules SET status = 'completed' WHERE id = ?")
                        ->execute([(int)$row['schedule_id']]);
                }

                $posted++;
            } catch (Throwable $e) {
                $errors[] = 'Schedule #' . $row['schedule_id'] . ': ' . $e->getMessage();
            }
        }

        if ($posted > 0) {
            require_once __DIR__ . '/service_accounting_service.php';
            (new ServiceAccountingService($conn))->invalidateFinancialCache();
        }

        return [
            'success' => empty($errors),
            'message' => $posted . ' amortization(s) posted for ' . $periodMonth . '.',
            'posted' => $posted,
            'errors' => $errors,
        ];
    }
}

if (!function_exists('sm_recurring_run_due')) {
    /**
     * Post due recurring journals and advance next_run_date.
     */
    function sm_recurring_run_due(PDO $conn, ?string $asOfDate = null, ?int $userId = null): array
    {
        $chk = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sm_recurring_journals'");
        $chk->execute();
        if ((int)$chk->fetchColumn() === 0) {
            return ['success' => false, 'message' => 'Recurring journal tables not installed.', 'posted' => 0];
        }

        $asOf = $asOfDate ?: date('Y-m-d');
        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);
        $companyId = cleaning_accounting_company_id($conn);

        $st = $conn->prepare("
            SELECT * FROM sm_recurring_journals
            WHERE status = 'active' AND next_run_date <= ? AND company_id = ?
        ");
        $st->execute([$asOf, $companyId]);
        $templates = $st->fetchAll(PDO::FETCH_ASSOC);

        $posted = 0;
        foreach ($templates as $tpl) {
            $linesSt = $conn->prepare('SELECT * FROM sm_recurring_journal_lines WHERE recurring_journal_id = ? ORDER BY line_no');
            $linesSt->execute([(int)$tpl['id']]);
            $tplLines = $linesSt->fetchAll(PDO::FETCH_ASSOC);
            if (count($tplLines) < 2) {
                continue;
            }

            $glLines = [];
            foreach ($tplLines as $line) {
                $glLines[] = [
                    'account_id' => coa_id($conn, $line['account_no']),
                    'desc' => $line['description'] ?: $tpl['name'],
                    'debit' => round((float)$line['debit'], 2),
                    'credit' => round((float)$line['credit'], 2),
                ];
            }

            try {
                gl_create_journal($conn, [
                    'date' => $tpl['next_run_date'],
                    'source' => 'manual',
                    'source_id' => (int)$tpl['id'],
                    'memo' => 'Recurring: ' . $tpl['name'],
                    'created_by' => $userId,
                    'company_id' => $companyId,
                ], $glLines);

                $next = new DateTime($tpl['next_run_date']);
                switch ($tpl['frequency']) {
                    case 'quarterly':
                        $next->modify('+3 months');
                        break;
                    case 'yearly':
                        $next->modify('+1 year');
                        break;
                    default:
                        $next->modify('+1 month');
                }

                $conn->prepare("
                    UPDATE sm_recurring_journals
                    SET last_run_date = ?, next_run_date = ?
                    WHERE id = ?
                ")->execute([$tpl['next_run_date'], $next->format('Y-m-d'), (int)$tpl['id']]);

                $posted++;
            } catch (Throwable $e) {
                error_log('sm_recurring_run_due #' . $tpl['id'] . ': ' . $e->getMessage());
            }
        }

        if ($posted > 0) {
            require_once __DIR__ . '/service_accounting_service.php';
            (new ServiceAccountingService($conn))->invalidateFinancialCache();
        }

        return ['success' => true, 'message' => $posted . ' recurring journal(s) posted.', 'posted' => $posted];
    }
}
