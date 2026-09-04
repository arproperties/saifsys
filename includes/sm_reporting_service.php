<?php
/**
 * Service Management Phase 8 — GM reporting via ServiceAccountingService.
 */

require_once __DIR__ . '/service_accounting_service.php';
require_once __DIR__ . '/service_category_helper.php';
require_once __DIR__ . '/work_order_financial_guard.php';
require_once __DIR__ . '/ar_helpers.php';

class SmReportingService
{
    private PDO $conn;
    private ServiceAccountingService $accounting;
    private int $companyId;

    public function __construct(PDO $conn, ?int $companyId = null)
    {
        $this->conn = $conn;
        $this->companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $this->accounting = new ServiceAccountingService($conn, $this->companyId);
    }

    public function accounting(): ServiceAccountingService
    {
        return $this->accounting;
    }

    /** Full GM dashboard bundle for a date range. */
    public function getGMReport(string $from, string $to): array
    {
        $revenueInvoices = $this->accounting->getRevenueFromInvoices($from, $to);
        $revenueGl = $this->accounting->getRevenueFromGL($from, $to);
        $expensesGl = $this->accounting->getExpensesFromGL($from, $to);
        $expensesTable = $this->accounting->getExpenses($from, $to);
        $profit = round($revenueGl - $expensesGl, 2);
        $arSummary = $this->accounting->getARSummary();

        return [
            'from' => $from,
            'to' => $to,
            'revenue_invoices' => $revenueInvoices,
            'revenue_gl' => $revenueGl,
            'expenses_gl' => $expensesGl,
            'expenses_table' => $expensesTable,
            'net_profit' => $profit,
            'collections' => $this->accounting->getCollections($from, $to),
            'outstanding_ar' => $arSummary['ar_total'],
            'overdue_ar' => $arSummary['overdue_total'],
            'open_invoices' => $arSummary['open_count'],
            'jobs_completed' => $this->getJobsCompletedCount($from, $to),
            'jobs_finalized' => $this->getJobsFinalizedCount($from, $to),
            'open_work_orders' => $this->getOpenWorkOrderCount(),
            'revenue_by_category' => $this->getRevenueByServiceCategory($from, $to),
            'monthly_trend' => $this->getMonthlyTrend(6),
            'top_overdue' => $this->accounting->getTopOverdue(5),
            'cross_checks' => $this->getCrossChecks($from, $to),
            'revenue_breakdown' => $this->getRevenueBreakdown($from, $to),
            'source' => 'ServiceAccountingService',
        ];
    }

    public function getCrossChecks(string $from, string $to): array
    {
        $checks = [];
        $revInv = $this->accounting->getRevenueFromInvoices($from, $to);
        $revGl = $this->accounting->getRevenueFromGL($from, $to);
        $glInvoiceCredits = $this->getGlInvoiceRevenueCredits($from, $to);
        $glReversals = $this->getGlRevenueReversals($from, $to);
        $expGl = $this->accounting->getExpensesFromGL($from, $to);
        $expTbl = $this->accounting->getExpenses($from, $to);
        $pnlProfit = round($revGl - $expGl, 2);

        $checks[] = $this->checkRow(
            'Invoice register vs GL invoice postings',
            $revInv,
            $glInvoiceCredits,
            'Compares invoice issue-date totals to revenue credited on GL journals with source = invoice (same journal dates).'
        );

        if ($glReversals > 0.02) {
            $checks[] = [
                'label' => 'GL revenue reversals in period',
                'value_a' => round($glReversals, 2),
                'value_b' => 0.0,
                'difference' => round(-$glReversals, 2),
                'ok' => true,
                'note' => 'Credit notes / voids posted as reversal journals reduce net GL revenue.',
                'informational' => true,
            ];
        }

        $checks[] = $this->checkRow(
            'Invoice register vs net GL revenue',
            $revInv,
            $revGl,
            $glReversals > 0.02
                ? 'Net GL = invoice postings − reversals (' . number_format($glReversals, 2) . ' AED). Invoice headers may still show pre-reversal totals.'
                : 'Invoice issue-date totals may differ from GL journal dates by a few days.'
        );

        $checks[] = $this->checkRow(
            'Expense table vs GL expenses',
            $expTbl,
            $expGl,
            'Prepaid amortization and manual JVs appear in GL but not expense headers.'
        );
        $checks[] = $this->checkRow(
            'P&L net profit (GL revenue − GL expenses)',
            $pnlProfit,
            $pnlProfit,
            '',
            true
        );

        return $checks;
    }

    public function getRevenueBreakdown(string $from, string $to): array
    {
        $register = $this->accounting->getRevenueFromInvoices($from, $to);
        $glInvoiceCredits = $this->getGlInvoiceRevenueCredits($from, $to);
        $glReversals = $this->getGlRevenueReversals($from, $to);
        $glNet = $this->accounting->getRevenueFromGL($from, $to);

        return [
            'invoice_register' => $register,
            'gl_invoice_credits' => $glInvoiceCredits,
            'gl_revenue_reversals' => $glReversals,
            'gl_net_revenue' => $glNet,
            'register_minus_reversals' => round($register - $glReversals, 2),
        ];
    }

    /** Revenue credits on GL from invoice journals in date range. */
    public function getGlInvoiceRevenueCredits(string $from, string $to): float
    {
        $params = [$from, $to];
        $glCompany = '';
        if (gl_column_exists($this->conn, 'gl_journals', 'company_id')) {
            $params[] = $this->companyId;
            $glCompany = ' AND j.company_id = ?';
        }
        $sql = "
            SELECT COALESCE(SUM(l.credit), 0) - COALESCE(SUM(l.debit), 0)
            FROM gl_journal_lines l
            INNER JOIN gl_journals j ON j.id = l.journal_id
            INNER JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE j.is_posted = 1
              AND j.journal_date BETWEEN ? AND ?
              AND j.source = 'invoice'
              AND a.type = 'Revenue'
              {$glCompany}
        ";
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    /** Revenue debits from reversal journals in date range (credit notes, voids). */
    public function getGlRevenueReversals(string $from, string $to): float
    {
        $params = [$from, $to];
        $glCompany = '';
        if (gl_column_exists($this->conn, 'gl_journals', 'company_id')) {
            $params[] = $this->companyId;
            $glCompany = ' AND j.company_id = ?';
        }
        $sql = "
            SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0)
            FROM gl_journal_lines l
            INNER JOIN gl_journals j ON j.id = l.journal_id
            INNER JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE j.is_posted = 1
              AND j.journal_date BETWEEN ? AND ?
              AND j.source = 'reversal'
              AND a.type = 'Revenue'
              {$glCompany}
        ";
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    private function invoicePeriodWhereClause(string $alias = 'i', string $companySql = ''): string
    {
        return "
            {$alias}.status <> 'void'
            AND " . ar_collectible_invoice_sql($alias) . "
            AND {$alias}.issue_date BETWEEN ? AND ?
            {$companySql}
        ";
    }

    private function checkRow(string $label, float $a, float $b, string $note = '', bool $alwaysOk = false): array
    {
        $diff = round($b - $a, 2);
        $ok = $alwaysOk || abs($diff) <= 1.00;
        return [
            'label' => $label,
            'value_a' => round($a, 2),
            'value_b' => round($b, 2),
            'difference' => $diff,
            'ok' => $ok,
            'note' => $note,
        ];
    }

    public function getJobsCompletedCount(string $from, string $to): int
    {
        if (wo_column_exists($this->conn, 'ops_status')) {
            $st = $this->conn->prepare("
                SELECT COUNT(*) FROM make_order
                WHERE ops_status = 'completed'
                  AND DATE(COALESCE(service_date, `date`)) BETWEEN ? AND ?
            ");
            $st->execute([$from, $to]);
            return (int)$st->fetchColumn();
        }
        $st = $this->conn->prepare("
            SELECT COUNT(*) FROM make_order
            WHERE status IN ('completed', 'invoiced')
              AND DATE(COALESCE(service_date, `date`)) BETWEEN ? AND ?
        ");
        $st->execute([$from, $to]);
        return (int)$st->fetchColumn();
    }

    public function getJobsFinalizedCount(string $from, string $to): int
    {
        if (!wo_column_exists($this->conn, 'is_finalized')) {
            return 0;
        }
        $dateCol = wo_column_exists($this->conn, 'finalized_at')
            ? 'DATE(finalized_at)'
            : 'DATE(COALESCE(service_date, `date`))';
        $st = $this->conn->prepare("
            SELECT COUNT(*) FROM make_order
            WHERE COALESCE(is_finalized, 0) = 1
              AND {$dateCol} BETWEEN ? AND ?
        ");
        $st->execute([$from, $to]);
        return (int)$st->fetchColumn();
    }

    public function getOpenWorkOrderCount(): int
    {
        if (wo_column_exists($this->conn, 'ops_status')) {
            return (int)$this->conn->query("
                SELECT COUNT(*) FROM make_order WHERE ops_status NOT IN ('completed', 'cancelled')
            ")->fetchColumn();
        }
        return (int)$this->conn->query("
            SELECT COUNT(*) FROM make_order
            WHERE status NOT IN ('completed', 'cancelled', 'invoiced')
        ")->fetchColumn();
    }

    /** Revenue split by catalog line category (supports hybrid Cleaning + Pest Control on one WO). */
    public function getRevenueByServiceCategory(string $from, string $to): array
    {
        if (!sm_service_category_table_exists($this->conn)) {
            return [];
        }

        $invoices = $this->getInvoiceRegister($from, $to);
        $buckets = [];

        foreach ($invoices as $inv) {
            $orderId = (int)($inv['order_id'] ?? 0);
            $total = round((float)($inv['total'] ?? 0), 2);
            if ($orderId <= 0 || $total <= 0) {
                continue;
            }
            foreach ($this->getOrderCategoryAmounts($orderId, $total) as $item) {
                $key = ($item['code'] ?? '') . '|' . ($item['name'] ?? '');
                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'category_name' => $item['name'],
                        'category_code' => $item['code'] ?? '—',
                        'revenue' => 0.0,
                        'invoice_ids' => [],
                    ];
                }
                $buckets[$key]['revenue'] += $item['amount'];
                $buckets[$key]['invoice_ids'][(int)$inv['id']] = true;
            }
        }

        $rows = [];
        foreach ($buckets as $bucket) {
            $rows[] = [
                'category_name' => $bucket['category_name'],
                'category_code' => $bucket['category_code'],
                'invoice_count' => count($bucket['invoice_ids']),
                'revenue' => round($bucket['revenue'], 2),
            ];
        }
        usort($rows, static fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        return $rows;
    }

    /** Last N months revenue, expenses, profit (GL-based). */
    public function getMonthlyTrend(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = date('Y-m-01', strtotime("-{$i} months"));
            $end = date('Y-m-t', strtotime($start));
            $rev = $this->accounting->getRevenueFromGL($start, $end);
            $exp = $this->accounting->getExpensesFromGL($start, $end);
            $out[] = [
                'month' => date('M Y', strtotime($start)),
                'month_key' => date('Y-m', strtotime($start)),
                'revenue' => $rev,
                'expenses' => $exp,
                'profit' => round($rev - $exp, 2),
            ];
        }
        return $out;
    }

    /** Export-ready invoice register. */
    public function getInvoiceRegister(string $from, string $to): array
    {
        $params = [$from, $to];
        $companySql = '';
        if (gl_column_exists($this->conn, 'invoices', 'company_id')) {
            $params[] = $this->companyId;
            $companySql = ' AND i.company_id = ?';
        }

        $sql = "
            SELECT
                i.id,
                i.invoice_no,
                i.issue_date,
                i.due_date,
                i.status,
                i.total,
                i.subtotal,
                i.vat_amount,
                c.client_name,
                mo.id AS order_id,
                '' AS service_category,
                COALESCE(pa.amount_paid, 0) AS amount_paid,
                GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) AS balance_due
            FROM invoices i
            LEFT JOIN client c ON c.id = i.client_id
            LEFT JOIN make_order mo ON mo.id = i.order_id
            LEFT JOIN sm_service_categories sc ON sc.id = mo.service_category_id
            LEFT JOIN (
                SELECT invoice_id, ROUND(SUM(amount_applied), 2) AS amount_paid
                FROM receipt_allocations GROUP BY invoice_id
            ) pa ON pa.invoice_id = i.id
            WHERE i.status <> 'void'
              AND " . ar_collectible_invoice_sql('i') . "
              AND i.issue_date BETWEEN ? AND ?
              {$companySql}
            ORDER BY i.issue_date DESC, i.id DESC
        ";
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->enrichInvoiceRegisterRows($rows);
    }

    /** Add per-category revenue split for hybrid orders. */
    public function enrichInvoiceRegisterRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $orderId = (int)($row['order_id'] ?? 0);
            $total = round((float)($row['total'] ?? 0), 2);
            $amounts = ($orderId > 0 && $total > 0)
                ? $this->getOrderCategoryAmounts($orderId, $total)
                : [];
            $row['category_breakdown'] = $this->formatCategoryAmounts($amounts);
            $row['service_category'] = $this->formatCategoryLabels($amounts);
        }
        return $rows;
    }

    /** @return array<int, array{name:string, code:string, amount:float}> */
    public function getOrderCategoryAmounts(int $orderId, float $invoiceTotal): array
    {
        if ($orderId <= 0 || $invoiceTotal <= 0) {
            return [];
        }

        $st = $this->conn->prepare("
            SELECT service_id, service_name, description, line_total
            FROM order_services
            WHERE order_id = ?
            ORDER BY id
        ");
        $st->execute([$orderId]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$lines) {
            return $this->legacyOrderCategoryAmounts($orderId, $invoiceTotal);
        }

        $lineSum = 0.0;
        foreach ($lines as $ln) {
            $lineSum += (float)$ln['line_total'];
        }
        if ($lineSum <= 0) {
            return $this->legacyOrderCategoryAmounts($orderId, $invoiceTotal);
        }

        $amounts = [];
        foreach ($lines as $ln) {
            $cat = sm_resolve_order_line_category($this->conn, $ln, $this->companyId);
            $name = $cat['name'];
            $code = $cat['code'];
            $share = round($invoiceTotal * ((float)$ln['line_total'] / $lineSum), 2);
            $key = $code . '|' . $name;
            if (!isset($amounts[$key])) {
                $amounts[$key] = ['name' => $name, 'code' => $code, 'amount' => 0.0];
            }
            $amounts[$key]['amount'] += $share;
        }

        return array_values(array_map(static function ($item) {
            $item['amount'] = round($item['amount'], 2);
            return $item;
        }, $amounts));
    }

    /** Orders with no order_services rows — use work order header category. */
    private function legacyOrderCategoryAmounts(int $orderId, float $invoiceTotal): array
    {
        if (!sm_make_order_has_service_category($this->conn)) {
            return [];
        }
        $st = $this->conn->prepare("
            SELECT sc.name, sc.code
            FROM make_order mo
            LEFT JOIN sm_service_categories sc ON sc.id = mo.service_category_id
            WHERE mo.id = ?
            LIMIT 1
        ");
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['name'])) {
            $clean = sm_get_category_by_code($this->conn, 'cleaning', $this->companyId);
            if (!$clean) {
                return [];
            }
            return [[
                'name' => $clean['name'],
                'code' => $clean['code'],
                'amount' => round($invoiceTotal, 2),
            ]];
        }
        return [[
            'name' => (string)$row['name'],
            'code' => (string)($row['code'] ?? '—'),
            'amount' => round($invoiceTotal, 2),
        ]];
    }

    public function formatOrderCategoryBreakdown(int $orderId, float $invoiceTotal): string
    {
        return $this->formatCategoryAmounts($this->getOrderCategoryAmounts($orderId, $invoiceTotal));
    }

    private function formatCategoryAmounts(array $amounts): string
    {
        $parts = [];
        foreach ($amounts as $item) {
            $parts[] = $item['name'] . ' ' . number_format($item['amount'], 2);
        }
        return implode(' · ', $parts);
    }

    private function formatCategoryLabels(array $amounts): string
    {
        $names = array_unique(array_column($amounts, 'name'));
        return implode(' + ', $names) ?: '—';
    }

    /** Expense summary by type and by GL expense account. */
    public function getExpenseSummary(string $from, string $to): array
    {
        $params = [$from, $to];
        $companySql = '';
        if (gl_column_exists($this->conn, 'expenses', 'company_id')) {
            $params[] = $this->companyId;
            $companySql = ' AND e.company_id = ?';
        }

        $byType = [];
        $typeSql = "
            SELECT COALESCE(e.expense_type, 'operating') AS expense_type,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(e.total), 0) AS total
            FROM expenses e
            WHERE e.status = 'posted'
              AND e.expense_date BETWEEN ? AND ?
              {$companySql}
            GROUP BY COALESCE(e.expense_type, 'operating')
            ORDER BY total DESC
        ";
        $st = $this->conn->prepare($typeSql);
        $st->execute($params);
        $byType = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $glParams = [$from, $to];
        $glCompany = '';
        if (gl_column_exists($this->conn, 'gl_journals', 'company_id')) {
            $glParams[] = $this->companyId;
            $glCompany = ' AND j.company_id = ?';
        }
        $byAccountSql = "
            SELECT a.account_no, a.name,
                   COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS net_expense
            FROM gl_journal_lines l
            JOIN gl_journals j ON j.id = l.journal_id
            JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE j.is_posted = 1
              AND j.journal_date BETWEEN ? AND ?
              AND a.type = 'Expense'
              {$glCompany}
            GROUP BY a.id, a.account_no, a.name
            HAVING net_expense <> 0
            ORDER BY net_expense DESC
        ";
        $gst = $this->conn->prepare($byAccountSql);
        $gst->execute($glParams);
        $byAccount = $gst->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'by_type' => $byType,
            'by_account' => $byAccount,
            'total_gl' => $this->accounting->getExpensesFromGL($from, $to),
            'total_table' => $this->accounting->getExpenses($from, $to),
        ];
    }
}
