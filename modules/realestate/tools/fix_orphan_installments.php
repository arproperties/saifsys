<?php
/**
 * Safe cleanup for orphan extra rent installments.
 *
 * Why:
 * - Some leases ended with more rent installments than number_of_installments.
 * - Extra rows are usually unpaid orphan rows (often duplicate last date, no cheque/payment link).
 *
 * Usage:
 *   php modules/realestate/tools/fix_orphan_installments.php --dry-run
 *   php modules/realestate/tools/fix_orphan_installments.php --apply
 *   php modules/realestate/tools/fix_orphan_installments.php --lease=294 --dry-run
 *   php modules/realestate/tools/fix_orphan_installments.php --lease=294 --apply
 *   php modules/realestate/tools/fix_orphan_installments.php --company=1 --apply
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once dirname(__DIR__, 3) . '/includes/db_connect.php';
require_once dirname(__DIR__) . '/includes/installment_cleanup_helper.php';

$opts = getopt('', ['apply', 'dry-run', 'lease::', 'company::']);
$apply = isset($opts['apply']);
$dryRun = isset($opts['dry-run']) || !$apply;
$leaseFilter = isset($opts['lease']) ? (int)$opts['lease'] : 0;
$companyFilter = isset($opts['company']) ? (int)$opts['company'] : 0;

echo $dryRun ? "MODE: DRY RUN (no deletes)\n" : "MODE: APPLY (deletes enabled)\n";
echo "Scanning leases...\n\n";

$report = re_cleanup_orphan_installments($conn, [
    'lease_id' => $leaseFilter > 0 ? $leaseFilter : null,
    'company_id' => $companyFilter > 0 ? $companyFilter : null,
    'apply' => $apply,
]);

foreach ($report['lines'] as $line) {
    $leaseLabel = "Lease #{$line['lease_id']} ({$line['lease_number']})";
    if (($line['status'] ?? '') === 'manual_review') {
        echo "{$leaseLabel}: expected {$line['expected']}, actual {$line['actual']}, extra {$line['extra']} -> SKIP (needs manual review)\n";
    } elseif (($line['status'] ?? '') === 'applied' || ($line['status'] ?? '') === 'dry_run_ok' || ($line['status'] ?? '') === 'pending_apply') {
        $ids = implode(',', $line['delete_ids'] ?? []);
        $prefix = $apply ? "deleting" : "would delete";
        echo "{$leaseLabel}: expected {$line['expected']}, actual {$line['actual']}, {$prefix} " . count($line['delete_ids'] ?? []) . " orphan row(s): [{$ids}]\n";
    } elseif (($line['status'] ?? '') === 'error') {
        echo "{$leaseLabel}: ERROR {$line['error']}\n";
    }
}

echo "\n--- Summary ---\n";
echo "Scanned leases: {$report['scanned']}\n";
echo "Leases with extra rent installments: {$report['with_extra']}\n";
if ($dryRun) {
    echo "Dry-run only, no rows deleted.\n";
} else {
    echo "Leases fixed: {$report['fixed']}\n";
    echo "Rows deleted: {$report['rows_deleted']}\n";
}

if (!empty($report['manual_review'])) {
    echo "\nManual review required for " . count($report['manual_review']) . " lease(s):\n";
    foreach ($report['manual_review'] as $m) {
        echo "- Lease #{$m['lease_id']} ({$m['lease_number']}): expected {$m['expected']}, actual {$m['actual']}, extra {$m['extra']}, safe_candidates {$m['safe_candidates']}";
        if (!empty($m['error'])) {
            echo ", error: {$m['error']}";
        }
        echo "\n";
    }
}

echo "\nDone.\n";

