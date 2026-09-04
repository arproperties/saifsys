#!/usr/bin/env php
<?php
/**
 * Export LOCAL database structure snapshot (no data).
 *
 * Usage (localhost):
 *   php database/export_schema_snapshot.php
 *   php database/export_schema_snapshot.php --output=/path/to/schema_snapshot_local.json
 *
 * Or open in browser (Owner login required):
 *   http://localhost/herosysgro/database/export_schema_snapshot.php
 */

$isCli = (PHP_SAPI === 'cli');
$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/db_connect.php';
require_once $projectRoot . '/includes/database_structure_sync.php';

if (!$isCli) {
    require_once $projectRoot . '/includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/html; charset=utf-8');
}

$output = DatabaseStructureSync::defaultSnapshotPath($projectRoot);
if ($isCli) {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--output=')) {
            $output = substr($arg, 9);
        }
    }
} elseif (!empty($_GET['output'])) {
    $output = $projectRoot . '/' . ltrim($_GET['output'], '/');
}

$started = microtime(true);
$sync = new DatabaseStructureSync($conn, DB_NAME);

try {
    $result = $sync->exportToFile($output);
    $elapsed = round(microtime(true) - $started, 2);

    if ($isCli) {
        echo "Schema snapshot exported successfully.\n";
        echo "Database: " . DB_NAME . "\n";
        echo "File: {$result['path']}\n";
        echo "Size: " . number_format($result['bytes']) . " bytes\n";
        echo "Elapsed: {$elapsed}s\n";
        echo "Inventory:\n";
        foreach ($result['inventory'] as $k => $v) {
            echo "  - $k: $v\n";
        }
        if (!empty($result['warnings'])) {
            echo "Warnings:\n";
            foreach ($result['warnings'] as $w) {
                echo "  ! $w\n";
            }
        }
        exit(0);
    }

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Schema Export</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4">';
    echo '<div class="container"><h1>Schema snapshot exported</h1>';
    echo '<div class="alert alert-success">Saved to <code>' . htmlspecialchars($result['path']) . '</code></div>';
    echo '<ul class="list-group mb-3">';
    foreach ($result['inventory'] as $k => $v) {
        echo '<li class="list-group-item d-flex justify-content-between"><span>' . htmlspecialchars($k) . '</span><strong>' . (int)$v . '</strong></li>';
    }
    echo '</ul><p class="text-muted">Elapsed: ' . $elapsed . 's · Size: ' . number_format($result['bytes']) . ' bytes</p>';
    if (!empty($result['warnings'])) {
        echo '<div class="alert alert-warning"><strong>Warnings</strong><ul class="mb-0">';
        foreach ($result['warnings'] as $w) {
            echo '<li>' . htmlspecialchars($w) . '</li>';
        }
        echo '</ul></div>';
    }
    echo '<p>Upload <code>storage/schema_snapshots/schema_snapshot_local.json</code> to the live server (same path), then open <code>admin/database_structure_sync.php</code>.</p>';
    echo '</div></body></html>';
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, 'Export failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo '<div class="alert alert-danger m-4">Export failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
