<?php
/**
 * CLI: Live data repair (dry-run or execute).
 *
 * Usage:
 *   php tools/sm_run_live_repair.php --dry-run
 *   php tools/sm_run_live_repair.php --step=allocations
 *   php tools/sm_run_live_repair.php --all
 *   php tools/sm_run_live_repair.php --all --execute
 */
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sm_data_repair_service.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role(['Owner'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

$dryRun = true;
$step = null;
$runAll = false;

if ($isCli) {
    foreach ($argv as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
        } elseif ($arg === '--execute') {
            $dryRun = false;
        } elseif ($arg === '--all') {
            $runAll = true;
        } elseif (preg_match('/^--step=(.+)$/', $arg, $m)) {
            $step = $m[1];
        } elseif ($arg === '--help' || $arg === '-h') {
            echo "Usage: php tools/sm_run_live_repair.php [--dry-run|--execute] [--all|--step=KEY]\n";
            echo "Steps: allocations, missing_invoice_gl, expenses_gl, duplicate_invoice_gl, wo_sync, clear_cache\n";
            exit(0);
        }
    }
} else {
    $dryRun = !isset($_GET['execute']);
    $runAll = isset($_GET['all']);
    $step = $_GET['step'] ?? null;
}

$companyId = cleaning_accounting_company_id($conn);

if ($runAll) {
    $results = sm_repair_run_all($conn, $companyId, $dryRun, null);
} elseif ($step) {
    $results = [$step => sm_repair_run_step($conn, $step, $companyId, $dryRun, null)];
} else {
    $results = sm_repair_preview_all($conn, $companyId);
}

foreach ($results as $key => $result) {
    $title = is_array($result) && isset($result['step']) ? $result['step'] : $key;
    echo "=== {$title}" . ($dryRun ? ' (dry-run)' : ' (EXECUTE)') . " ===\n";
    if (!is_array($result) || !isset($result['eligible'])) {
        echo "  Eligible: " . (int)($result['eligible'] ?? 0) . "\n\n";
        continue;
    }
    echo "  Eligible: {$result['eligible']}\n";
    if (!$dryRun) {
        echo "  Fixed: {$result['fixed']}, Skipped: {$result['skipped']}, Unchanged: {$result['unchanged']}\n";
        if (!empty($result['errors'])) {
            echo "  Errors:\n";
            foreach ($result['errors'] as $err) {
                echo "    - {$err}\n";
            }
        }
    }
    foreach (array_slice($result['items'] ?? [], 0, 50) as $item) {
        echo "  [{$item['status']}] {$item['label']}: {$item['message']}\n";
    }
    if (count($result['items'] ?? []) > 50) {
        echo "  ... and " . (count($result['items']) - 50) . " more\n";
    }
    echo "\n";
}

exit(empty(array_filter($results, static fn($r) => is_array($r) && !empty($r['errors']))) ? 0 : 1);
