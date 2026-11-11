<?php
/**
 * Daily: moves tenants' post-dated cheques recorded since the last run to PDC
 * Receivables (Dr PDC Receivables, Cr the tenant), one PDC Receivable voucher per
 * cheque dated with its cheque date, for every company. The same happens whenever
 * a lease is posted or Finance > PDC Receivables is opened. See fin_pdc_helper.php.
 *
 *     php cron/fin_pdc_receivables.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/fin_pdc_helper.php';

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!fin_pdc_ready($conn)) {
    echo "PDC Receivables not installed (run migrations/fin_pdc_receivables.sql).\n";
    exit(0);
}
$today = fin_pdc_today();
$total = 0;
$companies = $conn->query("SELECT DISTINCT company_id FROM fin_vouchers WHERE type = 'lv' AND status = 'posted'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($companies as $companyId) {
    $r = fin_pdc_post_due($conn, (int)$companyId);
    $total += count($r['posted']);
    if ($r['posted']) {
        echo "company {$companyId}: " . count($r['posted']) . " posted\n";
    }
    foreach ($r['waiting'] as $w) {
        echo "company {$companyId}: waiting - {$w}\n";
    }
}
echo date('Y-m-d H:i') . " (Dubai {$today}) done, {$total} PDC vouchers posted.\n";
