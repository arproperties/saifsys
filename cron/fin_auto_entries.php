<?php
/**
 * Daily: makes the monthly scheduled entries that have come due (depreciation
 * of fixed assets, prepaid / accrued allocations), as pending, for every company. Nothing is posted here:
 * Accounts approve them in Finance > Pending Approval. The same entries are
 * also made whenever Fixed Assets, Allocations or Pending Approval is opened, so a missed
 * run loses nothing.
 *
 *     php cron/fin_auto_entries.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/fin_asset_helper.php';
require_once __DIR__ . '/../includes/fin_allocation_helper.php';

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!fin_asset_ready($conn)) {
    echo "Finance assets not installed.\n";
    exit(0);
}
// PHP on live runs in UTC; the business day is Dubai's.
$today = (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
$total = 0;
$companies = $conn->query("SELECT company_id FROM fin_assets UNION SELECT company_id FROM fin_alloc_schedules")->fetchAll(PDO::FETCH_COLUMN);
foreach ($companies as $companyId) {
    $n = fin_auto_generate($conn, (int)$companyId, $today);
    $total += $n;
    if ($n) {
        echo "company {$companyId}: {$n} made\n";
    }
}
echo date('Y-m-d H:i') . " done, {$total} pending entries made.\n";
