#!/usr/bin/env php
<?php
/**
 * Run database structure sync preview or apply (CLI or browser).
 * Use on live shared hosting when admin UI times out (503).
 *
 * CLI examples:
 *   php database/run_database_structure_sync.php --preview
 *   php database/run_database_structure_sync.php --preview --refresh
 *   php database/run_database_structure_sync.php --apply --sync-key=YOUR_KEY
 *
 * Browser (sync key required on live):
 *   .../database/run_database_structure_sync.php?mode=preview&sync_key=YOUR_KEY
 */

$isCli = (PHP_SAPI === 'cli');
$projectRoot = dirname(__DIR__);

@set_time_limit(0);
@ini_set('memory_limit', '512M');
if (!$isCli) {
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    if (ob_get_level()) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
}

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/db_connect.php';
require_once $projectRoot . '/includes/database_structure_sync.php';

$mode = 'preview';
$refresh = false;
$syncKey = '';
$applyConfirmed = false;

if ($isCli) {
    foreach ($argv as $arg) {
        if ($arg === '--preview') {
            $mode = 'preview';
        } elseif ($arg === '--apply') {
            $mode = 'apply';
        } elseif ($arg === '--refresh') {
            $refresh = true;
        } elseif (str_starts_with($arg, '--sync-key=')) {
            $syncKey = substr($arg, 11);
        }
    }
} else {
    header('Content-Type: text/plain; charset=utf-8');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    header('X-Accel-Buffering: no');
    $mode = $_GET['mode'] ?? $_POST['mode'] ?? 'preview';
    $refresh = !empty($_GET['refresh']) || !empty($_POST['refresh']);
    $syncKey = (string)($_GET['sync_key'] ?? $_POST['sync_key'] ?? '');
    $applyConfirmed = !empty($_POST['apply_confirmed']);
}

if (!DatabaseStructureSync::checkAccess([], $syncKey)) {
    if ($isCli) {
        fwrite(STDERR, "Access denied. Pass --sync-key= matching DB_STRUCTURE_SYNC_KEY in config.php\n");
        exit(1);
    }
    http_response_code(403);
    die("Access denied. Provide valid sync_key.\n");
}

$snapshotPath = DatabaseStructureSync::resolveSnapshotPath($projectRoot);
$logsDir = $projectRoot . '/logs';
$sync = new DatabaseStructureSync($conn, DB_NAME);

function out(string $line, bool $isCli): void
{
    echo $line . ($isCli ? "\n" : "\n");
    if (!$isCli) {
        @ob_flush();
        @flush();
    }
}

try {
    if (!is_file($snapshotPath)) {
        throw new RuntimeException('Snapshot not found: ' . $snapshotPath);
    }

    out('Database Structure Sync — ' . strtoupper($mode), $isCli);
    out('Database: ' . DB_NAME, $isCli);
    out('Snapshot: ' . $snapshotPath, $isCli);
    out(str_repeat('-', 60), $isCli);
    out('Step 1/3: Loading localhost snapshot JSON...', $isCli);
    out('Step 2/3: Reading live structure (batched queries)...', $isCli);

    $built = $sync->buildComparePlan($snapshotPath, $refresh);
    $plan = $built['plan'];
    $summary = $plan['summary'] ?? [];
    $safeCount = count($plan['safe'] ?? []);
    $manualCount = count($plan['manual_review'] ?? []);

    out('Step 3/3: Compare finished.', $isCli);
    out('Compare elapsed: ' . $built['elapsed'] . 's' . ($built['from_cache'] ? ' (from cache)' : ''), $isCli);
    out('Safe changes: ' . $safeCount, $isCli);
    out('Manual review: ' . $manualCount, $isCli);
    out('Skipped: ' . count($plan['skipped'] ?? []), $isCli);
    out('Missing tables: ' . (int)($summary['missing_tables'] ?? 0), $isCli);
    out('', $isCli);

    if ($mode === 'apply') {
        if (!$applyConfirmed && !$isCli) {
            throw new RuntimeException('Apply via browser requires POST with apply_confirmed=1');
        }
        if ($isCli && $safeCount > 0) {
            out('WARNING: Applying ' . $safeCount . ' safe change(s) to LIVE database.', $isCli);
            out('Press Ctrl+C within 5 seconds to cancel...', $isCli);
            sleep(5);
        }
        $applyResult = $sync->applyPlan($plan, false);
        $logPath = DatabaseStructureSync::writeLogFile($logsDir, $plan, $applyResult);
        out('Apply finished.', $isCli);
        out('Executed: ' . count($applyResult['executed'] ?? []), $isCli);
        out('Failed: ' . count($applyResult['failed'] ?? []), $isCli);
        out('Log: ' . $logPath, $isCli);
        // Invalidate cache after apply
        $cachePath = DatabaseStructureSync::planCachePathForSnapshot($snapshotPath);
        if (is_file($cachePath)) {
            @unlink($cachePath);
        }
        exit(count($applyResult['failed'] ?? []) > 0 ? 2 : 0);
    }

    out('=== SAFE CHANGES (first 20) ===', $isCli);
    foreach (array_slice($plan['safe'] ?? [], 0, 20) as $entry) {
        out(sprintf('[%s] %s — %s', $entry['category'] ?? '', $entry['object'] ?? '', $entry['reason'] ?? ''), $isCli);
        if (!empty($entry['sql'])) {
            out(DatabaseStructureSync::oneLine($entry['sql']), $isCli);
        }
        out('', $isCli);
    }
    if ($safeCount > 20) {
        out('... and ' . ($safeCount - 20) . ' more safe changes', $isCli);
    }

    out('=== MANUAL REVIEW (first 10) ===', $isCli);
    foreach (array_slice($plan['manual_review'] ?? [], 0, 10) as $entry) {
        out(sprintf('[%s] %s — %s', $entry['category'] ?? '', $entry['object'] ?? '', $entry['reason'] ?? ''), $isCli);
    }
    if ($manualCount > 10) {
        out('... and ' . ($manualCount - 10) . ' more manual items', $isCli);
    }

    out('', $isCli);
    out('Open admin UI for full review:', $isCli);
    out('admin/database_structure_sync.php?sync_key=YOUR_KEY', $isCli);
    out('Plan cache: ' . DatabaseStructureSync::planCachePathForSnapshot($snapshotPath), $isCli);

    exit(0);
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
