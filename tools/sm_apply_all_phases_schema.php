<?php
/**
 * Apply Service Management schema — all phases 0→8 in one run.
 *
 * Usage:
 *   php tools/sm_apply_all_phases_schema.php
 *
 * Equivalent SQL file:
 *   migrations/sm_all_phases_0_to_8.sql
 */
require_once __DIR__ . '/../includes/db_connect.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

$sqlFile = __DIR__ . '/../migrations/sm_all_phases_0_to_8.sql';
if (!is_file($sqlFile)) {
    echo "ERROR: missing {$sqlFile}\n";
    exit(1);
}

echo "=== Service Management — all phases 0→8 ===\n";
echo "Source: migrations/sm_all_phases_0_to_8.sql\n\n";

$sql = file_get_contents($sqlFile);
if ($sql === false || trim($sql) === '') {
    echo "ERROR: empty SQL file\n";
    exit(1);
}

// Strip block comments (-- lines) for cleaner splitting; keep executable statements.
$lines = preg_split('/\r\n|\r|\n/', $sql);
$buffer = '';
$statements = [];
foreach ($lines as $line) {
    $trim = ltrim($line);
    if ($trim === '' || str_starts_with($trim, '--')) {
        continue;
    }
    $buffer .= $line . "\n";
    if (str_ends_with(rtrim($line), ';')) {
        $stmt = trim($buffer);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
        $buffer = '';
    }
}
if (trim($buffer) !== '') {
    $statements[] = trim($buffer);
}

$ok = 0;
$warn = 0;
foreach ($statements as $i => $stmt) {
    try {
        $conn->exec($stmt);
        $ok++;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // expenses.status enum may already be extended
        if (stripos($stmt, 'MODIFY COLUMN status') !== false && stripos($msg, 'Duplicate') !== false) {
            echo "SKIP: expenses.status already extended\n";
            $warn++;
            continue;
        }
        echo "WARN statement #" . ($i + 1) . ": {$msg}\n";
        $warn++;
    }
}

echo "\nExecuted {$ok} statement(s)";
if ($warn > 0) {
    echo ", {$warn} warning(s)";
}
echo ".\n";
echo "=== Complete — run Live Data Repair next (see docs/service_management/LIVE_DEPLOYMENT.md) ===\n";
