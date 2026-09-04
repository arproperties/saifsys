<?php
/**
 * Scheduled report generation helpers — storage, periods, PDF/CSV output, report HTML.
 */

declare(strict_types=1);

require_once __DIR__ . '/composer_autoload_safe.php';

function scheduled_report_type_catalog(): array
{
    return [
        'ar_summary' => [
            'label' => 'AR Summary',
            'description' => 'Outstanding receivables by client with totals.',
            'category' => 'Finance',
            'icon' => 'bi-wallet2',
            'default_frequency' => 'monthly',
        ],
        'ar_aging' => [
            'label' => 'AR Ageing',
            'description' => 'Overdue buckets (0–30, 31–60, 61–90, 90+ days) for management.',
            'category' => 'Finance',
            'icon' => 'bi-hourglass-split',
            'default_frequency' => 'weekly',
        ],
        'payments' => [
            'label' => 'Payments Received',
            'description' => 'Receipts allocated to invoices for the selected period.',
            'category' => 'Finance',
            'icon' => 'bi-cash-coin',
            'default_frequency' => 'weekly',
        ],
        'overdue_invoices' => [
            'label' => 'Overdue Invoices',
            'description' => 'Past-due invoices with days overdue and balances.',
            'category' => 'Finance',
            'icon' => 'bi-exclamation-triangle',
            'default_frequency' => 'daily',
        ],
        'pnl' => [
            'label' => 'Profit & Loss Snapshot',
            'description' => 'Revenue vs posted expenses for the period.',
            'category' => 'Finance',
            'icon' => 'bi-graph-up',
            'default_frequency' => 'monthly',
        ],
        'expense_summary' => [
            'label' => 'Expense Summary',
            'description' => 'Posted expenses grouped by vendor/category for owners.',
            'category' => 'Finance',
            'icon' => 'bi-receipt-cutoff',
            'default_frequency' => 'monthly',
        ],
        'operations_summary' => [
            'label' => 'Operations / Work Orders',
            'description' => 'Completed orders, revenue, hours, and status breakdown.',
            'category' => 'Operations',
            'icon' => 'bi-clipboard-check',
            'default_frequency' => 'weekly',
        ],
        'management_dashboard' => [
            'label' => 'Management Dashboard',
            'description' => 'Owner KPI snapshot: AR, collections, overdue, orders, expenses.',
            'category' => 'Management',
            'icon' => 'bi-speedometer2',
            'default_frequency' => 'weekly',
        ],
    ];
}

function scheduled_reports_base_dir(): string
{
    return dirname(__DIR__) . '/storage';
}

function scheduled_reports_ensure_dirs(): array
{
    $base = scheduled_reports_base_dir();
    $reports = $base . '/scheduled_reports';
    $tmp = $base . '/dompdf_tmp';

    foreach ([$base, $reports, $tmp] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_dir($dir) && !is_writable($dir)) {
            @chmod($dir, 0777);
        }
    }

    return ['reports' => $reports, 'tmp' => $tmp];
}

function scheduled_reports_filepath(string $basename, string $format): string
{
    $dirs = scheduled_reports_ensure_dirs();
    $ext = $format === 'excel' ? 'xlsx' : $format;
    return $dirs['reports'] . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename) . '.' . $ext;
}

function scheduled_reports_resolve_period(array $parameters, string $frequency = 'monthly'): array
{
    if (!empty($parameters['from_date']) && !empty($parameters['to_date'])) {
        return [$parameters['from_date'], $parameters['to_date']];
    }

    $range = (string)($parameters['date_range'] ?? '');
    switch ($range) {
        case 'last_week':
            return [
                date('Y-m-d', strtotime('monday last week')),
                date('Y-m-d', strtotime('sunday last week')),
            ];
        case 'last_month':
            return [
                date('Y-m-01', strtotime('first day of last month')),
                date('Y-m-t', strtotime('last day of last month')),
            ];
        case 'this_week':
            return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')];
        case 'today':
            $d = date('Y-m-d');
            return [$d, $d];
        default:
            if ($frequency === 'weekly') {
                return [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')];
            }
            if ($frequency === 'daily') {
                $d = date('Y-m-d');
                return [$d, $d];
            }
            return [date('Y-m-01'), date('Y-m-d')];
    }
}

function scheduled_reports_company(PDO $conn): array
{
    return $conn->query('SELECT * FROM company_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
}

function scheduled_reports_money($n): string
{
    return number_format((float)$n, 2);
}

function scheduled_reports_pdf_styles(): string
{
    return '
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 18px; color: #1a365d; margin: 0 0 4px; }
        .subtitle { color: #666; margin-bottom: 16px; }
        .kpi-row { width: 100%; margin-bottom: 16px; }
        .kpi { display: inline-block; width: 23%; padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; margin-right: 1%; vertical-align: top; }
        .kpi-label { font-size: 9px; text-transform: uppercase; color: #64748b; }
        .kpi-value { font-size: 14px; font-weight: bold; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { background: #1e40af; color: #fff; padding: 7px 6px; text-align: left; font-size: 10px; }
        td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) td { background: #f9fafb; }
        .num { text-align: right; }
        .footer { margin-top: 24px; font-size: 9px; color: #94a3b8; }
        .warn { color: #b45309; font-weight: bold; }
        .danger { color: #b91c1c; font-weight: bold; }
    ';
}

function scheduled_reports_render_pdf(string $html, string $filepath, bool $landscape = false): array
{
    herosysgro_composer_autoload_safe();
    if (!class_exists('\Dompdf\Dompdf')) {
        throw new RuntimeException('Dompdf is not available. Run composer install on the server.');
    }

    $dirs = scheduled_reports_ensure_dirs();
    if (!is_writable($dirs['reports'])) {
        throw new RuntimeException('Report storage is not writable: ' . $dirs['reports']);
    }

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('tempDir', $dirs['tmp']);
    $options->set('fontCache', $dirs['tmp']);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
    $dompdf->render();

    $bytes = $dompdf->output();
    if (@file_put_contents($filepath, $bytes) === false) {
        throw new RuntimeException('Failed to write report file. Check permissions on storage/scheduled_reports.');
    }

    $size = filesize($filepath);
    if (!$size) {
        throw new RuntimeException('Generated report file is empty.');
    }

    return ['file_path' => $filepath, 'file_size' => (int)$size];
}

function scheduled_reports_wrap_html(string $title, string $companyName, string $periodLabel, string $bodyHtml): string
{
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
        . scheduled_reports_pdf_styles()
        . '</style></head><body>'
        . '<h1>' . htmlspecialchars($title) . '</h1>'
        . '<div class="subtitle"><strong>' . htmlspecialchars($companyName) . '</strong>'
        . ' &middot; ' . htmlspecialchars($periodLabel)
        . ' &middot; Generated ' . date('Y-m-d H:i') . '</div>'
        . $bodyHtml
        . '<div class="footer">Automated report from HeroSys Accounts</div>'
        . '</body></html>';
}

function scheduled_reports_write_csv(array $rows, array $columns, string $filepath): array
{
    scheduled_reports_ensure_dirs();
    $out = fopen($filepath, 'w');
    if (!$out) {
        throw new RuntimeException('Cannot write CSV file.');
    }
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, array_values($columns));
    foreach ($rows as $row) {
        $line = [];
        foreach (array_keys($columns) as $key) {
            $line[] = $row[$key] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    return ['file_path' => $filepath, 'file_size' => (int)filesize($filepath)];
}

function scheduled_reports_build_report(PDO $conn, string $type, array $parameters, string $format, string $frequency): array
{
    $slug = $type . '_' . date('Y-m-d_H-i-s');
    $filepath = scheduled_reports_filepath($slug, $format);
    [$fromDate, $toDate] = scheduled_reports_resolve_period($parameters, $frequency);
    $company = scheduled_reports_company($conn);
    $companyName = $company['legal_name'] ?? 'Company';
    $periodLabel = date('M j, Y', strtotime($fromDate)) . ' – ' . date('M j, Y', strtotime($toDate));

    switch ($type) {
        case 'ar_summary':
            return scheduled_reports_build_ar_summary($conn, $filepath, $format, $companyName, $periodLabel);
        case 'ar_aging':
            return scheduled_reports_build_ar_aging($conn, $filepath, $format, $companyName, $periodLabel);
        case 'payments':
            return scheduled_reports_build_payments($conn, $filepath, $format, $companyName, $periodLabel, $fromDate, $toDate);
        case 'overdue_invoices':
            return scheduled_reports_build_overdue($conn, $filepath, $format, $companyName);
        case 'pnl':
            return scheduled_reports_build_pnl($conn, $filepath, $format, $companyName, $periodLabel, $fromDate, $toDate);
        case 'expense_summary':
            return scheduled_reports_build_expenses($conn, $filepath, $format, $companyName, $periodLabel, $fromDate, $toDate);
        case 'operations_summary':
            return scheduled_reports_build_operations($conn, $filepath, $format, $companyName, $periodLabel, $fromDate, $toDate);
        case 'management_dashboard':
            return scheduled_reports_build_management($conn, $filepath, $format, $companyName, $periodLabel, $fromDate, $toDate);
        default:
            throw new InvalidArgumentException('Unknown report type: ' . $type);
    }
}

function scheduled_reports_build_ar_summary(PDO $conn, string $filepath, string $format, string $companyName, string $periodLabel): array
{
    $sql = "
        SELECT c.client_name,
               COUNT(i.id) AS invoice_count,
               ROUND(SUM(i.total), 2) AS total_invoiced,
               ROUND(SUM(COALESCE(pa.amount_paid, 0)), 2) AS total_paid,
               ROUND(SUM(i.total) - SUM(COALESCE(pa.amount_paid, 0)), 2) AS balance_due
        FROM invoices i
        INNER JOIN client c ON c.id = i.client_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_applied) AS amount_paid
            FROM receipt_allocations GROUP BY invoice_id
        ) pa ON pa.invoice_id = i.id
        WHERE i.status IN ('issued', 'partially_paid', 'paid')
        GROUP BY c.id, c.client_name
        HAVING balance_due > 0.005
        ORDER BY balance_due DESC
        LIMIT 100
    ";
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $columns = [
        'client_name' => 'Client',
        'invoice_count' => 'Invoices',
        'total_invoiced' => 'Invoiced (AED)',
        'total_paid' => 'Paid (AED)',
        'balance_due' => 'Balance (AED)',
    ];

    if ($format === 'csv') {
        return scheduled_reports_write_csv($rows, $columns, $filepath);
    }

    $totOutstanding = array_sum(array_column($rows, 'balance_due'));
    $body = '<div class="kpi-row">'
        . '<div class="kpi"><div class="kpi-label">Clients with balance</div><div class="kpi-value">' . count($rows) . '</div></div>'
        . '<div class="kpi"><div class="kpi-label">Total outstanding</div><div class="kpi-value">AED ' . scheduled_reports_money($totOutstanding) . '</div></div>'
        . '</div><table><thead><tr>';
    foreach ($columns as $label) {
        $body .= '<th>' . htmlspecialchars($label) . '</th>';
    }
    $body .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $body .= '<tr><td>' . htmlspecialchars($r['client_name']) . '</td>'
            . '<td class="num">' . (int)$r['invoice_count'] . '</td>'
            . '<td class="num">' . scheduled_reports_money($r['total_invoiced']) . '</td>'
            . '<td class="num">' . scheduled_reports_money($r['total_paid']) . '</td>'
            . '<td class="num">' . scheduled_reports_money($r['balance_due']) . '</td></tr>';
    }
    $body .= '</tbody></table>';

    return scheduled_reports_render_pdf(
        scheduled_reports_wrap_html('AR Summary', $companyName, $periodLabel, $body),
        $filepath,
        true
    );
}

function scheduled_reports_build_ar_aging(PDO $conn, string $filepath, string $format, string $companyName, string $periodLabel): array
{
    $sql = "
        SELECT c.client_name, i.invoice_no, i.due_date,
               DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
               GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) AS balance_due,
               CASE
                 WHEN DATEDIFF(CURDATE(), i.due_date) <= 0 THEN 'Current'
                 WHEN DATEDIFF(CURDATE(), i.due_date) <= 30 THEN '1-30 days'
                 WHEN DATEDIFF(CURDATE(), i.due_date) <= 60 THEN '31-60 days'
                 WHEN DATEDIFF(CURDATE(), i.due_date) <= 90 THEN '61-90 days'
                 ELSE '90+ days'
               END AS bucket
        FROM invoices i
        INNER JOIN client c ON c.id = i.client_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_applied) AS amount_paid
            FROM receipt_allocations GROUP BY invoice_id
        ) pa ON pa.invoice_id = i.id
        WHERE i.status IN ('issued', 'partially_paid')
        HAVING balance_due > 0.005
        ORDER BY days_overdue DESC
        LIMIT 150
    ";
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $buckets = ['Current' => 0, '1-30 days' => 0, '31-60 days' => 0, '61-90 days' => 0, '90+ days' => 0];
    foreach ($rows as $r) {
        $buckets[$r['bucket']] = ($buckets[$r['bucket']] ?? 0) + (float)$r['balance_due'];
    }

    $columns = [
        'client_name' => 'Client',
        'invoice_no' => 'Invoice',
        'due_date' => 'Due',
        'days_overdue' => 'Days Overdue',
        'bucket' => 'Bucket',
        'balance_due' => 'Balance (AED)',
    ];

    if ($format === 'csv') {
        return scheduled_reports_write_csv($rows, $columns, $filepath);
    }

    $body = '<div class="kpi-row">';
    foreach ($buckets as $label => $amt) {
        $body .= '<div class="kpi"><div class="kpi-label">' . htmlspecialchars($label) . '</div>'
            . '<div class="kpi-value">AED ' . scheduled_reports_money($amt) . '</div></div>';
    }
    $body .= '</div><table><thead><tr>';
    foreach ($columns as $label) {
        $body .= '<th>' . htmlspecialchars($label) . '</th>';
    }
    $body .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $body .= '<tr><td>' . htmlspecialchars($r['client_name']) . '</td><td>' . htmlspecialchars($r['invoice_no']) . '</td>'
            . '<td>' . htmlspecialchars($r['due_date']) . '</td><td class="num">' . (int)$r['days_overdue'] . '</td>'
            . '<td>' . htmlspecialchars($r['bucket']) . '</td><td class="num">' . scheduled_reports_money($r['balance_due']) . '</td></tr>';
    }
    $body .= '</tbody></table>';

    return scheduled_reports_render_pdf(
        scheduled_reports_wrap_html('AR Ageing Report', $companyName, 'As of ' . date('M j, Y'), $body),
        $filepath,
        true
    );
}

function scheduled_reports_build_payments(PDO $conn, string $filepath, string $format, string $companyName, string $periodLabel, string $from, string $to): array
{
    $sql = "
        SELECT r.receipt_date AS payment_date, r.receipt_no, i.invoice_no, c.client_name,
               ra.amount_applied AS amount, r.method AS payment_method, r.notes
        FROM receipts r
        INNER JOIN receipt_allocations ra ON ra.receipt_id = r.id
        INNER JOIN invoices i ON i.id = ra.invoice_id
        LEFT JOIN client c ON c.id = COALESCE(r.client_id, i.client_id)
        WHERE r.receipt_date BETWEEN ? AND ?
        ORDER BY r.receipt_date DESC, r.id DESC
        LIMIT 500
    ";
    $st = $conn->prepare($sql);
    $st->execute([$from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $columns = [
        'payment_date' => 'Date',
        'receipt_no' => 'Receipt',
        'invoice_no' => 'Invoice',
        'client_name' => 'Client',
        'amount' => 'Amount (AED)',
        'payment_method' => 'Method',
        'notes' => 'Notes',
    ];

    if ($format === 'csv') {
        return scheduled_reports_write_csv($rows, $columns, $filepath);
    }

    $total = array_sum(array_column($rows, 'amount'));
    $body = '<div class="kpi-row">'
        . '<div class="kpi"><div class="kpi-label">Payments</div><div class="kpi-value">' . count($rows) . '</div></div>'
        . '<div class="kpi"><div class="kpi-label">Total collected</div><div class="kpi-value">AED ' . scheduled_reports_money($total) . '</div></div>'
        . '</div><table><thead><tr>';
    foreach ($columns as $label) {
        $body .= '<th>' . htmlspecialchars($label) . '</th>';
    }
    $body .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $body .= '<tr><td>' . htmlspecialchars($r['payment_date']) . '</td><td>' . htmlspecialchars($r['receipt_no']) . '</td>'
            . '<td>' . htmlspecialchars($r['invoice_no']) . '</td><td>' . htmlspecialchars($r['client_name'] ?? '') . '</td>'
            . '<td class="num">' . scheduled_reports_money($r['amount']) . '</td><td>' . htmlspecialchars(ucfirst($r['payment_method'])) . '</td>'
            . '<td>' . htmlspecialchars($r['notes'] ?? '') . '</td></tr>';
    }
    $body .= '</tbody></table>';

    return scheduled_reports_render_pdf(
        scheduled_reports_wrap_html('Payments Received', $companyName, $periodLabel, $body),
        $filepath,
        true
    );
}

function scheduled_reports_build_overdue(PDO $conn, string $filepath, string $format, string $companyName): array
{
    $sql = "
        SELECT i.invoice_no, c.client_name, i.due_date,
               DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
               i.total,
               COALESCE(SUM(ra.amount_applied), 0) AS paid_amount,
               GREATEST(i.total - COALESCE(SUM(ra.amount_applied), 0), 0) AS balance_due
        FROM invoices i
        INNER JOIN client c ON c.id = i.client_id
        LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
        WHERE i.due_date < CURDATE() AND i.status IN ('issued', 'partially_paid')
        GROUP BY i.id, i.invoice_no, c.client_name, i.due_date, i.total
        HAVING balance_due > 0.005
        ORDER BY days_overdue DESC
        LIMIT 150
    ";
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $columns = [
        'invoice_no' => 'Invoice',
        'client_name' => 'Client',
        'due_date' => 'Due Date',
        'days_overdue' => 'Days Overdue',
        'total' => 'Total (AED)',
        'paid_amount' => 'Paid (AED)',
        'balance_due' => 'Balance (AED)',
    ];

    if ($format === 'csv') {
        return scheduled_reports_write_csv($rows, $columns, $filepath);
    }

    $total = array_sum(array_column($rows, 'balance_due'));
    $body = '<div class="kpi-row"><div class="kpi"><div class="kpi-label">Overdue invoices</div><div class="kpi-value">' . count($rows) . '</div></div>'
        . '<div class="kpi"><div class="kpi-label">Total overdue</div><div class="kpi-value danger">AED ' . scheduled_reports_money($total) . '</div></div></div>'
        . '<table><thead><tr>';
    foreach ($columns as $label) {
        $body .= '<th>' . htmlspecialchars($label) . '</th>';
    }
    $body .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $body .= '<tr><td>' . htmlspecialchars($r['invoice_no']) . '</td><td>' . htmlspecialchars($r['client_name']) . '</td>'
            . '<td>' . htmlspecialchars($r['due_date']) . '</td><td class="num danger">' . (int)$r['days_overdue'] . '</td>'
            . '<td class="num">' . scheduled_reports_money($r['total']) . '</td><td class="num">' . scheduled_reports_money($r['paid_amount']) . '</td>'
            . '<td class="num danger">' . scheduled_reports_money($r['balance_due']) . '</td></tr>';
    }
    $body .= '</tbody></table>';

    return scheduled_reports_render_pdf(
        scheduled_reports_wrap_html('Overdue Invoices', $companyName, 'As of ' . date('M j, Y'), $body),
        $filepath,
        true
    );
}

function scheduled_reports_build_pnl(PDO $conn, string $filepath, string $format, string $companyName, string $periodLabel, string $from, string $to): array
{
    $st = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE issue_date BETWEEN ? AND ? AND status IN ('issued','partially_paid','paid')");
    $st->execute([$from, $to]);
    $revenue = (float)$st->fetchColumn();

    $st = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM expenses WHERE expense_date BETWEEN ? AND ? AND status = 'posted'");
    $st->execute([$from, $to]);
    $expenses = (float)$st->fetchColumn();

    $net = $revenue - $expenses;
    $rows = [
        ['line' => 'Revenue (invoiced)', 'amount' => $revenue],
        ['line' => 'Expenses (posted)', 'amount' => -$expenses],
        ['line' => 'Net result', 'amount' => $net],
    ];

    if ($format === 'csv') {
        return scheduled_reports_write_csv($rows, ['line' => 'Line', 'amount' => 'Amount (AED)'], $filepath);
    }

    $body = '<table><thead><tr><th>Line</th><th class="num">Amount (AED)</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $cls = $r['amount'] < 0 ? 'warn' : '';
        $body .= '<tr><td>' . htmlspecialchars($r['line']) . '</td><td class="num ' . $cls . '">' . scheduled_reports_money(abs($r['amount'])) . ($r['amount'] < 0 ? ' (expense)' : '') . '</td></tr>';
    }
    $body .= '</tbody></table>';

    return scheduled_reports_render_pdf(
        scheduled_reports_wrap_html('Profit & Loss Snapshot', $companyName, $periodLabel, $body),
        $filepath
    );
}

function scheduled_reports_build_expenses(PDO $conn, string $filepath, string $format, string $companyName, string $periodLabel, string $from, string $to): array
{
    $sql = "
        SELECT COALESCE(v.name, 'No vendor') AS vendor_name,
               COUNT(*) AS expense_count,
               ROUND(SUM(e.total), 2) AS total_amount
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        WHERE e.expense_date BETWEEN ? AND ? AND e.status = 'posted'
        GROUP BY v.id, v.name
        ORDER BY total_amount DESC
        LIMIT 100
    ";
    $st = $conn->prepare($sql);
    $st->execute([$from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $columns = ['vendor_name' => 'Vendor', 'expense_count' => 'Count', 'total_amount' => 'Total (AED)'];

    if ($format === 'csv') {
        return scheduled_reports_write_csv($rows, $columns, $filepath);
    }

    $total = array_sum(array_column($rows, 'total_amount'));
    $body = '<div class="kpi-row"><div class="kpi"><div class="kpi-label">Total expenses</div><div class="kpi-value">AED ' . scheduled_reports_money($total) . '</div></div></div><table><thead><tr>';
    foreach ($columns as $label) {
        $body .= '<th>' . htmlspecialchars($label) . '</th>';
    }
    $body .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $body .= '<tr><td>' . htmlspecialchars($r['vendor_name']) . '</td><td class="num">' . (int)$r['expense_count'] . '</td>'
            . '<td class="num">' . scheduled_reports_money($r['total_amount']) . '</td></tr>';
    }
    $body .= '</tbody></table>';

    return scheduled_reports_render_pdf(
        scheduled_reports_wrap_html('Expense Summary', $companyName, $periodLabel, $body),
        $filepath,
        true
    );
}

function scheduled_reports_build_operations(PDO $conn, string $filepath, string $format, string $companyName, string $periodLabel, string $from, string $to): array
{
    $hasGrand = (bool)$conn->query("SHOW COLUMNS FROM make_order LIKE 'grand_total'")->fetch();
    $amountExpr = $hasGrand
        ? 'COALESCE(NULLIF(mo.grand_total,0), mo.total + COALESCE(mo.vat_amount,0))'
        : 'COALESCE(mo.total,0)';

    $sql = "
        SELECT mo.id, COALESCE(c.client_name, mo.client_name) AS client_name,
               COALESCE(mo.service_date, mo.date) AS service_date,
               mo.status, mo.hours, ({$amountExpr}) AS order_total
        FROM make_order mo
        LEFT JOIN client c ON c.id = mo.client_id
        WHERE COALESCE(mo.service_date, mo.date) BETWEEN ? AND ?
          AND COALESCE(mo.status,'') <> 'cancelled'
        ORDER BY service_date DESC, mo.id DESC
        LIMIT 300
    ";
    $st = $conn->prepare($sql);
    $st->execute([$from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $completed = count(array_filter($rows, fn($r) => in_array($r['status'], ['completed', 'invoiced', 'paid'], true)));
    $revenue = array_sum(array_column($rows, 'order_total'));
    $hours = array_sum(array_column($rows, 'hours'));

    $columns = [
        'id' => 'Order #',
        'client_name' => 'Client',
        'service_date' => 'Date',
        'status' => 'Status',
        'hours' => 'Hours',
        'order_total' => 'Total (AED)',
    ];

    if ($format === 'csv') {
        return scheduled_reports_write_csv($rows, $columns, $filepath);
    }

    $body = '<div class="kpi-row">'
        . '<div class="kpi"><div class="kpi-label">Orders</div><div class="kpi-value">' . count($rows) . '</div></div>'
        . '<div class="kpi"><div class="kpi-label">Completed / invoiced</div><div class="kpi-value">' . $completed . '</div></div>'
        . '<div class="kpi"><div class="kpi-label">Hours</div><div class="kpi-value">' . scheduled_reports_money($hours) . '</div></div>'
        . '<div class="kpi"><div class="kpi-label">Revenue</div><div class="kpi-value">AED ' . scheduled_reports_money($revenue) . '</div></div>'
        . '</div><table><thead><tr>';
    foreach ($columns as $label) {
        $body .= '<th>' . htmlspecialchars($label) . '</th>';
    }
    $body .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $body .= '<tr><td>#' . (int)$r['id'] . '</td><td>' . htmlspecialchars($r['client_name'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($r['service_date']) . '</td><td>' . htmlspecialchars($r['status']) . '</td>'
            . '<td class="num">' . scheduled_reports_money($r['hours']) . '</td><td class="num">' . scheduled_reports_money($r['order_total']) . '</td></tr>';
    }
    $body .= '</tbody></table>';

    return scheduled_reports_render_pdf(
        scheduled_reports_wrap_html('Operations / Work Orders', $companyName, $periodLabel, $body),
        $filepath,
        true
    );
}

function scheduled_reports_build_management(PDO $conn, string $filepath, string $format, string $companyName, string $periodLabel, string $from, string $to): array
{
    $ar = (float)$conn->query("
        SELECT COALESCE(SUM(GREATEST(i.total - COALESCE(pa.amount_paid,0), 0)), 0)
        FROM invoices i
        LEFT JOIN (SELECT invoice_id, SUM(amount_applied) amount_paid FROM receipt_allocations GROUP BY invoice_id) pa ON pa.invoice_id = i.id
        WHERE i.status IN ('issued','partially_paid')
    ")->fetchColumn();

    $overdue = (float)$conn->query("
        SELECT COALESCE(SUM(GREATEST(i.total - COALESCE(pa.amount_paid,0), 0)), 0)
        FROM invoices i
        LEFT JOIN (SELECT invoice_id, SUM(amount_applied) amount_paid FROM receipt_allocations GROUP BY invoice_id) pa ON pa.invoice_id = i.id
        WHERE i.status IN ('issued','partially_paid') AND i.due_date < CURDATE()
    ")->fetchColumn();

    $st = $conn->prepare("SELECT COALESCE(SUM(r.amount),0) FROM receipts r WHERE r.receipt_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $collections = (float)$st->fetchColumn();

    $st = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE issue_date BETWEEN ? AND ? AND status IN ('issued','partially_paid','paid')");
    $st->execute([$from, $to]);
    $invoiced = (float)$st->fetchColumn();

    $st = $conn->prepare("SELECT COUNT(*) FROM make_order WHERE COALESCE(service_date, date) BETWEEN ? AND ? AND COALESCE(status,'') <> 'cancelled'");
    $st->execute([$from, $to]);
    $orders = (int)$st->fetchColumn();

    $st = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM expenses WHERE expense_date BETWEEN ? AND ? AND status = 'posted'");
    $st->execute([$from, $to]);
    $expenses = (float)$st->fetchColumn();

    $kpis = [
        ['label' => 'Total AR outstanding', 'value' => 'AED ' . scheduled_reports_money($ar)],
        ['label' => 'Overdue AR', 'value' => 'AED ' . scheduled_reports_money($overdue)],
        ['label' => 'Collections (period)', 'value' => 'AED ' . scheduled_reports_money($collections)],
        ['label' => 'Invoiced (period)', 'value' => 'AED ' . scheduled_reports_money($invoiced)],
        ['label' => 'Work orders (period)', 'value' => (string)$orders],
        ['label' => 'Posted expenses (period)', 'value' => 'AED ' . scheduled_reports_money($expenses)],
    ];

    if ($format === 'csv') {
        $rows = array_map(fn($k) => ['metric' => $k['label'], 'value' => $k['value']], $kpis);
        return scheduled_reports_write_csv($rows, ['metric' => 'Metric', 'value' => 'Value'], $filepath);
    }

    $body = '<div class="kpi-row">';
    foreach ($kpis as $k) {
        $body .= '<div class="kpi"><div class="kpi-label">' . htmlspecialchars($k['label']) . '</div>'
            . '<div class="kpi-value">' . htmlspecialchars($k['value']) . '</div></div>';
    }
    $body .= '</div><p>This snapshot is intended for owners and general managers. Detailed drill-down is available in Accounts and Operation modules.</p>';

    return scheduled_reports_render_pdf(
        scheduled_reports_wrap_html('Management Dashboard', $companyName, $periodLabel, $body),
        $filepath
    );
}
