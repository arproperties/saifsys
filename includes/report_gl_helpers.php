<?php
/**
 * Shared GL calculations for financial reports.
 */

if (!function_exists('report_gl_pnl_journal_sql')) {
    /** Journal filters aligned with report_pnl.php (includes void/reversal pairing). */
    function report_gl_pnl_journal_sql(string $j = 'j'): string
    {
        return "
            AND {$j}.is_posted = 1
            AND NOT ({$j}.source = 'reversal' AND EXISTS (
                SELECT 1 FROM gl_journals aj
                WHERE aj.source = 'adjustment' AND aj.source_id = {$j}.id
            ))
            AND NOT ({$j}.source = 'adjustment' AND {$j}.memo LIKE 'Compensating entry: Voiding duplicate reversal%')
        ";
    }
}

if (!function_exists('report_gl_bs_journal_sql')) {
    /** Journal filters aligned with report_balance_sheet.php (active journals only). */
    function report_gl_bs_journal_sql(string $j = 'j', string $lineAlias = 'l'): string
    {
        return "
            AND {$j}.is_posted = 1
            AND {$j}.is_reversed = 0
            AND NOT ({$j}.source = 'reversal' AND EXISTS (
                SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = {$j}.id
            ))
            AND NOT ({$j}.source = 'adjustment' AND {$j}.memo LIKE 'Compensating entry: Voiding duplicate reversal%')
            AND NOT ({$j}.source = 'adjustment' AND {$j}.memo LIKE 'Void incorrect bank transfer%')
            AND NOT ({$j}.source = 'adjustment' AND {$j}.memo LIKE 'Bank Transfer%' AND {$j}.is_reversed = 1)
            AND NOT ({$j}.source = 'reversal' AND {$j}.source_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM gl_journals orig_j
                LEFT JOIN invoices inv ON inv.id = orig_j.source_id
                WHERE orig_j.id = {$j}.source_id
                  AND orig_j.source = 'invoice'
                  AND (inv.status = 'void' OR inv.id IS NULL)
                  AND NOT EXISTS (
                    SELECT 1 FROM gl_journal_lines orig_l
                    JOIN gl_journals orig_j2 ON orig_j2.id = orig_l.journal_id
                    WHERE orig_l.journal_id = orig_j.id
                      AND orig_l.account_id = {$lineAlias}.account_id
                      AND orig_l.debit > 0
                      AND orig_j2.is_posted = 1
                  )
            ))
        ";
    }
}

if (!function_exists('report_accumulated_net_income')) {
    /**
     * Cumulative net income from Revenue & Expense GL through as-of date (P&L logic).
     *
     * @return array{revenue:float, expense:float, net:float}
     */
    function report_accumulated_net_income(PDO $conn, int $companyId, string $asofDate): array
    {
        $pnlFilter = report_gl_pnl_journal_sql('j');
        $st = $conn->prepare("
            SELECT a.type,
                   COALESCE(SUM(l.debit), 0) AS debit,
                   COALESCE(SUM(l.credit), 0) AS credit
            FROM gl_journal_lines l
            JOIN gl_journals j ON j.id = l.journal_id
              AND j.journal_date <= ?
              AND j.company_id = ?
              {$pnlFilter}
            JOIN chart_of_accounts a ON a.id = l.account_id AND a.company_id = ?
            WHERE a.type IN ('Revenue', 'Expense')
            GROUP BY a.type
        ");
        $st->execute([$asofDate, $companyId, $companyId]);
        $rev = 0.0;
        $exp = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (($row['type'] ?? '') === 'Revenue') {
                $rev += (float)$row['credit'] - (float)$row['debit'];
            } else {
                $exp += (float)$row['debit'] - (float)$row['credit'];
            }
        }
        return [
            'revenue' => round($rev, 2),
            'expense' => round($exp, 2),
            'net' => round($rev - $exp, 2),
        ];
    }
}

if (!function_exists('report_period_net_income')) {
    /**
     * Net income for a date range (matches P&L report).
     *
     * @return array{revenue:float, expense:float, net:float}
     */
    function report_period_net_income(PDO $conn, int $companyId, string $from, string $to): array
    {
        $pnlFilter = report_gl_pnl_journal_sql('j');
        $st = $conn->prepare("
            SELECT a.type,
                   COALESCE(SUM(l.debit), 0) AS debit,
                   COALESCE(SUM(l.credit), 0) AS credit
            FROM gl_journal_lines l
            JOIN gl_journals j ON j.id = l.journal_id
              AND j.journal_date BETWEEN ? AND ?
              AND j.company_id = ?
              {$pnlFilter}
            JOIN chart_of_accounts a ON a.id = l.account_id AND a.company_id = ?
            WHERE a.type IN ('Revenue', 'Expense')
            GROUP BY a.type
        ");
        $st->execute([$from, $to, $companyId, $companyId]);
        $rev = 0.0;
        $exp = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (($row['type'] ?? '') === 'Revenue') {
                $rev += (float)$row['credit'] - (float)$row['debit'];
            } else {
                $exp += (float)$row['debit'] - (float)$row['credit'];
            }
        }
        return [
            'revenue' => round($rev, 2),
            'expense' => round($exp, 2),
            'net' => round($rev - $exp, 2),
        ];
    }
}

if (!function_exists('report_pnl_account_net')) {
    /** Signed P&L amount for one account row (revenue: credit−debit, expense: debit−credit). */
    function report_pnl_account_net(string $type, float $debit, float $credit): float
    {
        if ($type === 'Revenue') {
            return round($credit - $debit, 2);
        }
        return round($debit - $credit, 2);
    }
}

if (!function_exists('report_pnl_classify_section')) {
    /**
     * Map a COA row to Income, Cost of Sales, or Expenses for the traditional P&L layout.
     *
     * @param array<string,mixed> $account
     * @param array<int,string> $parentNames parent_id => name
     * @param array<int,string> $cosAccountNos
     */
    function report_pnl_classify_section(array $account, array $parentNames, array $cosAccountNos): string
    {
        if (($account['type'] ?? '') === 'Revenue') {
            return 'income';
        }
        $parentId = (int)($account['parent_id'] ?? 0);
        if ($parentId > 0) {
            $parentName = strtolower((string)($parentNames[$parentId] ?? ''));
            if (str_contains($parentName, 'cost of sales') || str_contains($parentName, 'cost of goods')) {
                return 'cost_of_sales';
            }
        }
        $accountNo = (string)($account['account_no'] ?? '');
        if (in_array($accountNo, $cosAccountNos, true)) {
            return 'cost_of_sales';
        }
        return 'expenses';
    }
}

if (!function_exists('report_pnl_cos_account_nos')) {
    /**
     * Account numbers treated as Cost of Sales (comma-separated setting or 5010–5099 default).
     *
     * @return array<int,string>
     */
    function report_pnl_cos_account_nos(PDO $conn): array
    {
        $nos = [];
        if (!function_exists('sm_get_setting')) {
            $settingsFile = __DIR__ . '/service_management_settings.php';
            if (is_file($settingsFile)) {
                require_once $settingsFile;
            }
        }
        if (function_exists('sm_get_setting')) {
            $raw = trim(sm_get_setting($conn, 'sm_pnl_cos_accounts', ''));
            if ($raw !== '') {
                foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
                    $part = trim((string)$part);
                    if ($part !== '') {
                        $nos[] = $part;
                    }
                }
                return array_values(array_unique($nos));
            }
        }
        for ($n = 5010; $n <= 5099; $n++) {
            $nos[] = (string)$n;
        }
        return $nos;
    }
}

if (!function_exists('report_pnl_period_label')) {
    /** Human-readable period for report header (e.g. "July 2026" or "1 Jan 2020 – 8 Jul 2026"). */
    function report_pnl_period_label(string $from, string $to): string
    {
        $fromDt = DateTimeImmutable::createFromFormat('Y-m-d', $from);
        $toDt = DateTimeImmutable::createFromFormat('Y-m-d', $to);
        if (!$fromDt || !$toDt) {
            return $from . ' to ' . $to;
        }
        if ($from === $to) {
            return $toDt->format('j M Y');
        }
        $sameMonth = $fromDt->format('Y-m') === $toDt->format('Y-m');
        $monthStart = $fromDt->format('Y-m-01');
        $monthEnd = $toDt->format('Y-m-t');
        if ($sameMonth && $from === $monthStart && $to === $monthEnd) {
            return $toDt->format('F Y');
        }
        return $fromDt->format('j M Y') . ' – ' . $toDt->format('j M Y');
    }
}

if (!function_exists('report_pnl_format_money')) {
    function report_pnl_format_money(float $amount, string $currency = 'AED'): string
    {
        $formatted = number_format(abs($amount), 2);
        if ($amount < 0) {
            return $currency . '(' . $formatted . ')';
        }
        return $currency . $formatted;
    }
}

if (!function_exists('report_pnl_fetch_accounts')) {
    /**
     * P&L account balances for a date range.
     * Uses an aggregated subquery so date/company filters cannot leak via LEFT JOIN.
     *
     * @return array<int,array<string,mixed>>
     */
    function report_pnl_fetch_accounts(PDO $conn, int $companyId, string $from, string $to, bool $includeZero = false): array
    {
        $pnlFilter = report_gl_pnl_journal_sql('j');
        if ($includeZero) {
            $st = $conn->prepare("
                SELECT a.id, a.account_no, a.name, a.type, a.parent_id, a.is_header,
                       COALESCE(agg.debit, 0) AS debit,
                       COALESCE(agg.credit, 0) AS credit
                FROM chart_of_accounts a
                LEFT JOIN (
                    SELECT l.account_id,
                           SUM(l.debit) AS debit,
                           SUM(l.credit) AS credit
                    FROM gl_journal_lines l
                    JOIN gl_journals j ON j.id = l.journal_id
                      AND j.journal_date BETWEEN ? AND ?
                      AND j.company_id = ?
                      {$pnlFilter}
                    GROUP BY l.account_id
                ) agg ON agg.account_id = a.id
                WHERE a.type IN ('Revenue', 'Expense')
                  AND a.is_active = 1
                ORDER BY a.account_no
            ");
            $st->execute([$from, $to, $companyId]);
        } else {
            $st = $conn->prepare("
                SELECT a.id, a.account_no, a.name, a.type, a.parent_id, a.is_header,
                       SUM(l.debit) AS debit,
                       SUM(l.credit) AS credit
                FROM gl_journal_lines l
                JOIN gl_journals j ON j.id = l.journal_id
                  AND j.journal_date BETWEEN ? AND ?
                  AND j.company_id = ?
                  {$pnlFilter}
                JOIN chart_of_accounts a ON a.id = l.account_id
                WHERE a.type IN ('Revenue', 'Expense')
                  AND a.is_active = 1
                GROUP BY a.id, a.account_no, a.name, a.type, a.parent_id, a.is_header
                ORDER BY a.account_no
            ");
            $st->execute([$from, $to, $companyId]);
        }
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('report_pnl_fetch_journal_details')) {
    /**
     * Journal line detail grouped by account_no (for expandable drill-down).
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    function report_pnl_fetch_journal_details(PDO $conn, int $companyId, string $from, string $to): array
    {
        $pnlFilter = report_gl_pnl_journal_sql('j');
        $st = $conn->prepare("
            SELECT
                a.id AS account_id,
                a.account_no,
                a.name AS account_name,
                a.type,
                j.id AS journal_id,
                j.journal_no,
                j.journal_date,
                j.source,
                j.source_id,
                j.memo,
                l.description AS line_description,
                l.debit,
                l.credit,
                CASE
                  WHEN j.source = 'invoice' AND j.source_id IS NOT NULL THEN
                    (SELECT CONCAT('Invoice: ', invoice_no) FROM invoices WHERE id = j.source_id)
                  WHEN j.source = 'reversal' AND j.source_id IS NOT NULL THEN
                    CONCAT('Reversal of J#', j.source_id)
                  WHEN j.source = 'adjustment' THEN
                    CONCAT('Adjustment: ', COALESCE(j.memo, 'Manual Entry'))
                  WHEN j.source = 'expense' AND j.source_id IS NOT NULL THEN
                    (SELECT CONCAT('Expense: ', reference_no) FROM expenses WHERE id = j.source_id)
                  ELSE j.source
                END AS source_info
            FROM gl_journal_lines l
            JOIN gl_journals j ON j.id = l.journal_id
              AND j.journal_date BETWEEN ? AND ?
              AND j.company_id = ?
              {$pnlFilter}
            JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE a.type IN ('Revenue', 'Expense')
            ORDER BY a.account_no, j.journal_date, j.id, l.line_no
        ");
        $st->execute([$from, $to, $companyId]);
        $grouped = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $accNo = (string)$row['account_no'];
            $grouped[$accNo][] = $row;
        }
        return $grouped;
    }
}

if (!function_exists('report_pnl_build')) {
    /**
     * Build structured Profit & Loss report (Income → COS → Gross Profit → Expenses → Net Earning).
     *
     * @return array{
     *   income: array<int,array<string,mixed>>,
     *   cost_of_sales: array<int,array<string,mixed>>,
     *   expenses: array<int,array<string,mixed>>,
     *   total_income: float,
     *   total_cos: float,
     *   gross_profit: float,
     *   total_expenses: float,
     *   net_earning: float
     * }
     */
    function report_pnl_build(PDO $conn, int $companyId, string $from, string $to, bool $includeZero = false): array
    {
        $rows = report_pnl_fetch_accounts($conn, $companyId, $from, $to, $includeZero);
        $parentNames = [];
        $parentSt = $conn->prepare("
            SELECT id, name FROM chart_of_accounts
            WHERE company_id = ? AND is_header = 1
        ");
        $parentSt->execute([$companyId]);
        foreach ($parentSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $parentNames[(int)$p['id']] = (string)$p['name'];
        }
        $cosNos = report_pnl_cos_account_nos($conn);

        $sections = [
            'income' => [],
            'cost_of_sales' => [],
            'expenses' => [],
        ];
        foreach ($rows as $row) {
            $debit = (float)$row['debit'];
            $credit = (float)$row['credit'];
            $net = report_pnl_account_net((string)$row['type'], $debit, $credit);
            if (!$includeZero && abs($net) < 0.005 && $debit < 0.005 && $credit < 0.005) {
                continue;
            }
            $section = report_pnl_classify_section($row, $parentNames, $cosNos);
            $row['debit'] = $debit;
            $row['credit'] = $credit;
            $row['net'] = $net;
            $row['pnl_section'] = $section;
            $sections[$section][] = $row;
        }

        $totalIncome = round(array_sum(array_column($sections['income'], 'net')), 2);
        $totalCos = round(array_sum(array_column($sections['cost_of_sales'], 'net')), 2);
        $totalExpenses = round(array_sum(array_column($sections['expenses'], 'net')), 2);
        $grossProfit = round($totalIncome - $totalCos, 2);
        $netEarning = round($totalIncome - $totalCos - $totalExpenses, 2);

        return [
            'income' => $sections['income'],
            'cost_of_sales' => $sections['cost_of_sales'],
            'expenses' => $sections['expenses'],
            'total_income' => $totalIncome,
            'total_cos' => $totalCos,
            'gross_profit' => $grossProfit,
            'total_expenses' => $totalExpenses,
            'net_earning' => $netEarning,
        ];
    }
}

if (!function_exists('report_gl_coa_account_stats')) {
    /**
     * COA list stats aligned with General Ledger (same journal filters + as-of date).
     *
     * @return array<int, array{balance:float, period_txn_count:int}>
     */
    function report_gl_coa_account_stats(PDO $conn, int $companyId, string $from, string $to): array
    {
        $glFilter = report_gl_bs_journal_sql('j', 'l');
        $stats = [];

        $balSt = $conn->prepare("
            SELECT l.account_id,
                   SUM(CASE WHEN a.normal_balance = 'debit'
                       THEN l.debit - l.credit
                       ELSE l.credit - l.debit
                   END) AS balance
            FROM gl_journal_lines l
            JOIN gl_journals j ON j.id = l.journal_id
              AND j.company_id = ?
              AND j.journal_date <= ?
              {$glFilter}
            JOIN chart_of_accounts a ON a.id = l.account_id
            GROUP BY l.account_id
        ");
        $balSt->execute([$companyId, $to]);
        foreach ($balSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)$row['account_id'];
            $stats[$id] = [
                'balance' => round((float)$row['balance'], 2),
                'period_txn_count' => 0,
            ];
        }

        $txnSt = $conn->prepare("
            SELECT l.account_id, COUNT(DISTINCT j.id) AS txn_count
            FROM gl_journal_lines l
            JOIN gl_journals j ON j.id = l.journal_id
              AND j.company_id = ?
              AND j.journal_date BETWEEN ? AND ?
              {$glFilter}
            GROUP BY l.account_id
        ");
        $txnSt->execute([$companyId, $from, $to]);
        foreach ($txnSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)$row['account_id'];
            if (!isset($stats[$id])) {
                $stats[$id] = ['balance' => 0.0, 'period_txn_count' => 0];
            }
            $stats[$id]['period_txn_count'] = (int)$row['txn_count'];
        }

        return $stats;
    }
}
