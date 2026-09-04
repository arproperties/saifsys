<?php
/**
 * ServiceAccountingService — single source of truth for Cleaning / Service Management financial metrics.
 *
 * All dashboard KPIs, AR summaries, and cross-report comparisons should use these methods.
 */

require_once __DIR__ . '/cleaning_accounting_context.php';
require_once __DIR__ . '/gl_posting.php';
require_once __DIR__ . '/caching_service.php';
require_once __DIR__ . '/ar_helpers.php';

class ServiceAccountingService
{
    private PDO $conn;
    private int $companyId;

    public function __construct(PDO $conn, ?int $companyId = null)
    {
        $this->conn = $conn;
        $this->companyId = $companyId ?? cleaning_accounting_company_id($conn);
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    private function invoiceCompanySql(string $alias, array &$params): string
    {
        if (!gl_column_exists($this->conn, 'invoices', 'company_id')) {
            return '';
        }
        $params[] = $this->companyId;
        return " AND {$alias}.company_id = ?";
    }

    private function glCompanySql(string $alias, array &$params): string
    {
        if (!gl_column_exists($this->conn, 'gl_journals', 'company_id')) {
            return '';
        }
        $params[] = $this->companyId;
        return " AND {$alias}.company_id = ?";
    }

    private function expenseCompanySql(string $alias, array &$params): string
    {
        if (!gl_column_exists($this->conn, 'expenses', 'company_id')) {
            return '';
        }
        $params[] = $this->companyId;
        return " AND {$alias}.company_id = ?";
    }

    private function receiptCompanySql(string $alias, array &$params): string
    {
        if (!gl_column_exists($this->conn, 'receipts', 'company_id')) {
            return '';
        }
        $params[] = $this->companyId;
        return " AND {$alias}.company_id = ?";
    }

    /** Revenue from non-void collectible invoices by issue date (excludes BINV). */
    public function getRevenueFromInvoices(string $from, string $to): float
    {
        $params = [$from, $to];
        $sql = "
            SELECT COALESCE(SUM(i.total), 0)
            FROM invoices i
            WHERE i.status <> 'void'
              AND " . ar_collectible_invoice_sql('i') . "
              AND i.issue_date BETWEEN ? AND ?
        " . $this->invoiceCompanySql('i', $params);
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    /** Net revenue from posted GL (Revenue accounts, credits − debits). */
    public function getRevenueFromGL(string $from, string $to): float
    {
        $params = [$from, $to];
        $glSql = $this->glCompanySql('j', $params);
        $sql = "
            SELECT COALESCE(SUM(l.credit), 0) - COALESCE(SUM(l.debit), 0)
            FROM gl_journal_lines l
            JOIN gl_journals j ON j.id = l.journal_id
            JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE j.is_posted = 1
              AND j.journal_date BETWEEN ? AND ?
              AND a.type = 'Revenue'
              {$glSql}
        ";
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    public function getExpenses(string $from, string $to): float
    {
        $params = [$from, $to];
        $sql = "
            SELECT COALESCE(SUM(e.total), 0)
            FROM expenses e
            WHERE e.status = 'posted'
              AND e.expense_date BETWEEN ? AND ?
        " . $this->expenseCompanySql('e', $params);
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    public function getProfit(string $from, string $to): float
    {
        return round($this->getRevenueFromGL($from, $to) - $this->getExpensesFromGL($from, $to), 2);
    }

    /** Expenses from GL expense accounts (debits − credits). */
    public function getExpensesFromGL(string $from, string $to): float
    {
        $params = [$from, $to];
        $glSql = $this->glCompanySql('j', $params);
        $sql = "
            SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0)
            FROM gl_journal_lines l
            JOIN gl_journals j ON j.id = l.journal_id
            JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE j.is_posted = 1
              AND j.journal_date BETWEEN ? AND ?
              AND a.type = 'Expense'
              {$glSql}
        ";
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    /** Open AR from receipt allocations (canonical customer balance). */
    public function getARTotal(): float
    {
        $params = [];
        $companySql = $this->invoiceCompanySql('i', $params);
        $sql = "
            SELECT COALESCE(SUM(
                GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0)
            ), 0)
            FROM invoices i
            LEFT JOIN (
                SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
                FROM receipt_allocations ra
                GROUP BY ra.invoice_id
            ) pa ON pa.invoice_id = i.id
            WHERE i.status IN ('issued', 'partially_paid')
              AND " . ar_collectible_invoice_sql('i') . "
              {$companySql}
        ";
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    public function getOverdueTotal(): float
    {
        $params = [];
        $companySql = $this->invoiceCompanySql('i', $params);
        $sql = "
            SELECT COALESCE(SUM(
                GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0)
            ), 0)
            FROM invoices i
            LEFT JOIN (
                SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
                FROM receipt_allocations ra
                GROUP BY ra.invoice_id
            ) pa ON pa.invoice_id = i.id
            WHERE i.status IN ('issued', 'partially_paid')
              AND " . ar_collectible_invoice_sql('i') . "
              AND i.due_date < CURDATE()
              {$companySql}
        ";
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    /** Top overdue collectible invoices by balance due. */
    public function getTopOverdue(int $limit = 10): array
    {
        $params = [];
        $companySql = $this->invoiceCompanySql('i', $params);
        $sql = "
            SELECT i.id, i.invoice_no, i.due_date, i.total, c.client_name,
                   CASE
                       WHEN i.due_date IS NULL THEN 0
                       WHEN i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date)
                       ELSE 0
                   END AS days_overdue,
                   GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) AS balance_due
            FROM invoices i
            LEFT JOIN client c ON c.id = i.client_id
            LEFT JOIN (
                SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
                FROM receipt_allocations ra
                GROUP BY ra.invoice_id
            ) pa ON pa.invoice_id = i.id
            WHERE i.status IN ('issued', 'partially_paid')
              AND " . ar_collectible_invoice_sql('i') . "
              AND i.due_date < CURDATE()
              AND i.total > COALESCE(pa.amount_paid, 0)
              {$companySql}
            ORDER BY balance_due DESC
            LIMIT " . (int)$limit;
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Top clients by outstanding collectible balance. */
    public function getTopClientsByBalance(int $limit = 10): array
    {
        $params = [];
        $companySql = $this->invoiceCompanySql('i', $params);
        $sql = "
            SELECT
                c.client_name,
                c.id AS client_id,
                COUNT(i.id) AS invoice_count,
                SUM(GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0)) AS total_balance
            FROM invoices i
            LEFT JOIN client c ON c.id = i.client_id
            LEFT JOIN (
                SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
                FROM receipt_allocations ra
                GROUP BY ra.invoice_id
            ) pa ON pa.invoice_id = i.id
            WHERE i.status IN ('issued', 'partially_paid')
              AND " . ar_collectible_invoice_sql('i') . "
              AND i.total > COALESCE(pa.amount_paid, 0)
              {$companySql}
            GROUP BY c.id, c.client_name
            HAVING total_balance > 0
            ORDER BY total_balance DESC
            LIMIT " . (int)$limit;
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getCollections(string $from, string $to): float
    {
        $params = [$from, $to];
        $sql = "
            SELECT COALESCE(SUM(r.amount), 0)
            FROM receipts r
            WHERE r.receipt_date BETWEEN ? AND ?
        " . $this->receiptCompanySql('r', $params);
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    public function getOpenInvoiceCount(): int
    {
        $params = [];
        $companySql = $this->invoiceCompanySql('i', $params);
        $sql = "
            SELECT COUNT(*)
            FROM invoices i
            WHERE i.status IN ('issued', 'partially_paid')
              AND " . ar_collectible_invoice_sql('i') . "
              {$companySql}
        ";
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    /** AR dashboard summary aligned with allocation-based balances. */
    public function getARSummary(): array
    {
        $params = [];
        $companySql = $this->invoiceCompanySql('i', $params);
        $monthStart = date('Y-m-01');

        $openSql = "
            SELECT
                COUNT(*) AS open_count,
                COALESCE(SUM(GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0)), 0) AS ar_total,
                COALESCE(SUM(
                    CASE WHEN i.due_date < CURDATE()
                    THEN GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0)
                    ELSE 0 END
                ), 0) AS overdue_total
            FROM invoices i
            LEFT JOIN (
                SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
                FROM receipt_allocations ra
                GROUP BY ra.invoice_id
            ) pa ON pa.invoice_id = i.id
            WHERE i.status IN ('issued', 'partially_paid')
              AND " . ar_collectible_invoice_sql('i') . "
              {$companySql}
        ";
        $st = $this->conn->prepare($openSql);
        $st->execute($params);
        $open = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $paidParams = [$monthStart];
        $receiptCompany = $this->receiptCompanySql('r', $paidParams);
        $paidSql = "
            SELECT COALESCE(SUM(r.amount), 0)
            FROM receipts r
            WHERE r.receipt_date >= ?
            {$receiptCompany}
        ";
        $pst = $this->conn->prepare($paidSql);
        $pst->execute($paidParams);
        $paidThisMonth = (float)$pst->fetchColumn();

        return [
            'open_count' => (int)($open['open_count'] ?? 0),
            'ar_total' => round((float)($open['ar_total'] ?? 0), 2),
            'overdue_total' => round((float)($open['overdue_total'] ?? 0), 2),
            'paid_this_month' => round($paidThisMonth, 2),
        ];
    }

    public function getAgeingBuckets(): array
    {
        $params = [];
        $companySql = $this->invoiceCompanySql('i', $params);
        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN i.due_date IS NULL OR i.due_date >= CURDATE()
                    THEN GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) ELSE 0 END), 0) AS bucket_0,
                COALESCE(SUM(CASE WHEN i.due_date < CURDATE()
                    AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30
                    THEN GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) ELSE 0 END), 0) AS bucket_30,
                COALESCE(SUM(CASE WHEN i.due_date < CURDATE()
                    AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60
                    THEN GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) ELSE 0 END), 0) AS bucket_60,
                COALESCE(SUM(CASE WHEN i.due_date < CURDATE()
                    AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90
                    THEN GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) ELSE 0 END), 0) AS bucket_90,
                COALESCE(SUM(CASE WHEN i.due_date < CURDATE()
                    AND DATEDIFF(CURDATE(), i.due_date) > 90
                    THEN GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) ELSE 0 END), 0) AS bucket_120
            FROM invoices i
            LEFT JOIN (
                SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
                FROM receipt_allocations ra
                GROUP BY ra.invoice_id
            ) pa ON pa.invoice_id = i.id
            WHERE i.status IN ('issued', 'partially_paid')
              AND " . ar_collectible_invoice_sql('i') . "
              {$companySql}
        ";
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['bucket_0', 'bucket_30', 'bucket_60', 'bucket_90', 'bucket_120'] as $k) {
            $row[$k] = round((float)($row[$k] ?? 0), 2);
        }
        return $row;
    }

    /** Home / API dashboard bundle. */
    public function getDashboardSummary(int $days = 30): array
    {
        $currentMonth = date('Y-m-01');
        $today = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $activeClients = (int)$this->conn->query('SELECT COUNT(*) FROM client WHERE is_active = 1')->fetchColumn();
        $openWorkOrders = (int)$this->conn->query("
            SELECT COUNT(*) FROM make_order
            WHERE status NOT IN ('completed', 'cancelled', 'invoiced')
        ")->fetchColumn();
        $todayWorkOrders = (int)$this->conn->query("
            SELECT COUNT(*) FROM make_order WHERE DATE(COALESCE(service_date, `date`)) = CURDATE()
        ")->fetchColumn();

        $pendingLeave = 0;
        try {
            $pendingLeave = (int)$this->conn->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
        } catch (Throwable $e) {
            $pendingLeave = 0;
        }

        return [
            'activeClients' => $activeClients,
            'openWorkOrders' => $openWorkOrders,
            'pendingInvoices' => $this->getOpenInvoiceCount(),
            'revenueThisMonth' => $this->getRevenueFromInvoices($currentMonth, $today),
            'revenueThisMonthGL' => $this->getRevenueFromGL($currentMonth, $today),
            'overduePayments' => $this->getOverdueTotal(),
            'outstandingAR' => $this->getARTotal(),
            'pendingLeave' => $pendingLeave,
            'todayWorkOrders' => $todayWorkOrders,
            'totalRevenue' => $this->getRevenueFromInvoices($startDate, $today),
            'totalExpenses' => $this->getExpenses($startDate, $today),
            'netProfit' => $this->getProfit($startDate, $today),
            'collectionsThisMonth' => $this->getCollections($currentMonth, $today),
            'period' => $days . ' days',
            'source' => 'ServiceAccountingService',
        ];
    }

    /** Invalidate all financial dashboard caches. */
    public function invalidateFinancialCache(): void
    {
        $cache = new CachingService($this->conn);
        $keys = [
            'ar_summary', 'ar_ageing', 'top_overdue_10', 'top_overdue',
            'top_clients_balance_10', 'top_unbilled_10', 'top_unbilled',
            'payment_trends_6', 'client_credit', 'charts_data',
        ];
        foreach ($keys as $key) {
            $cache->forget($key);
        }
        for ($limit = 5; $limit <= 25; $limit += 5) {
            $cache->forget("top_overdue_{$limit}");
            $cache->forget("top_clients_balance_{$limit}");
            $cache->forget("top_unbilled_{$limit}");
        }
        for ($months = 3; $months <= 12; $months += 3) {
            $cache->forget("payment_trends_{$months}");
        }
    }

    /**
     * Compare legacy inline queries vs this service (for health check / rollout).
     * @return array<int, array<string, mixed>>
     */
    public function compareWithLegacy(): array
    {
        $currentMonth = date('Y-m-01');
        $today = date('Y-m-d');
        $mismatches = [];

        $legacyRevenue = (float)$this->conn->query("
            SELECT COALESCE(SUM(total), 0) FROM invoices
            WHERE status != 'void' AND issue_date >= '{$currentMonth}'
        ")->fetchColumn();
        $svcRevenue = $this->getRevenueFromInvoices($currentMonth, $today);
        if (abs($legacyRevenue - $svcRevenue) > 0.02) {
            $mismatches[] = [
                'metric' => 'revenue_this_month_invoices',
                'legacy' => round($legacyRevenue, 2),
                'service' => $svcRevenue,
                'difference' => round($svcRevenue - $legacyRevenue, 2),
            ];
        }

        $legacyOverdue = (float)$this->conn->query("
            SELECT COALESCE(SUM(i.total - COALESCE(paid.amount, 0)), 0)
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, SUM(amount_applied) AS amount
                FROM receipt_allocations GROUP BY invoice_id
            ) paid ON paid.invoice_id = i.id
            WHERE i.status IN ('issued', 'partially_paid') AND i.due_date < CURDATE()
              AND " . ar_collectible_invoice_sql('i') . "
        ")->fetchColumn();
        $svcOverdue = $this->getOverdueTotal();
        if (abs($legacyOverdue - $svcOverdue) > 0.02) {
            $mismatches[] = [
                'metric' => 'overdue_total',
                'legacy' => round($legacyOverdue, 2),
                'service' => $svcOverdue,
                'difference' => round($svcOverdue - $legacyOverdue, 2),
            ];
        }

        $cache = new CachingService($this->conn);
        $cached = $cache->get('ar_summary') ?: [];
        $live = $this->getARSummary();
        if ($cached) {
            foreach (['ar_total', 'overdue_total', 'open_count'] as $field) {
                $c = round((float)($cached[$field] ?? 0), 2);
                $l = round((float)($live[$field] ?? 0), 2);
                if (abs($c - $l) > 0.02) {
                    $mismatches[] = [
                        'metric' => "cache_ar_summary_{$field}",
                        'legacy' => $c,
                        'service' => $l,
                        'difference' => round($l - $c, 2),
                    ];
                }
            }
        }

        $glRev = $this->getRevenueFromGL($currentMonth, $today);
        if (abs($svcRevenue - $glRev) > 1.00) {
            $mismatches[] = [
                'metric' => 'invoice_register_vs_gl_revenue_mtd',
                'legacy' => $svcRevenue,
                'service' => $glRev,
                'difference' => round($glRev - $svcRevenue, 2),
                'note' => 'Invoice issue-date totals may differ from GL journal dates; investigate if large.',
            ];
        }

        return $mismatches;
    }
}
