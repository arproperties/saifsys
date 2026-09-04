<?php
/**
 * Monthly cron: prepaid amortization + due recurring journals.
 * Usage: php tools/sm_run_prepaid_cron.php [YYYY-MM]
 */
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sm_prepaid_service.php';

$period = $argv[1] ?? date('Y-m');
echo "=== SM Phase 7 cron — period {$period} ===\n";

$amort = sm_prepaid_run_amortization($conn, $period, null);
echo $amort['message'] . "\n";
foreach ($amort['errors'] as $e) {
    echo "  ERR: {$e}\n";
}

$recur = sm_recurring_run_due($conn, date('Y-m-d'), null);
echo $recur['message'] . "\n";

echo "=== Done ===\n";
