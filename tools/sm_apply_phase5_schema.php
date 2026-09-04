<?php
/**
 * Schema helper — Service Management Phase 5 (service category foundation).
 */
require_once __DIR__ . '/../includes/db_connect.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

function sm5_table_exists(PDO $conn, string $table): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function sm5_col_exists(PDO $conn, string $table, string $col): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

echo "=== SM Phase 5 schema (service categories) ===\n";

$sqlFile = __DIR__ . '/../migrations/sm_phase5_service_foundation.sql';
if (is_file($sqlFile)) {
    $sql = file_get_contents($sqlFile);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '' || stripos($stmt, 'CREATE TABLE') === false) {
            continue;
        }
        try {
            $conn->exec($stmt);
            if (preg_match('/CREATE TABLE IF NOT EXISTS\s+(\w+)/i', $stmt, $m)) {
                echo "OK: table {$m[1]}\n";
            }
        } catch (Throwable $e) {
            echo "WARN: {$e->getMessage()}\n";
        }
    }
}

if (!sm5_col_exists($conn, 'make_order', 'service_category_id')) {
    $conn->exec("ALTER TABLE make_order ADD COLUMN service_category_id INT NULL AFTER company_id");
    echo "OK: make_order.service_category_id\n";
} else {
    echo "SKIP: make_order.service_category_id\n";
}

if (!sm5_col_exists($conn, 'services', 'service_category_id')) {
    if (sm5_table_exists($conn, 'services')) {
        $conn->exec("ALTER TABLE services ADD COLUMN service_category_id INT NULL AFTER id");
        echo "OK: services.service_category_id\n";
    }
} else {
    echo "SKIP: services.service_category_id\n";
}

require_once __DIR__ . '/../includes/cleaning_accounting_context.php';
require_once __DIR__ . '/../includes/service_category_helper.php';

$companyId = cleaning_accounting_company_id($conn);
$catId = sm_ensure_default_cleaning_category($conn, $companyId);
echo "OK: default cleaning category id = {$catId}\n";

$backfill = $conn->prepare("
    UPDATE make_order
    SET service_category_id = ?
    WHERE service_category_id IS NULL
");
$backfill->execute([$catId]);
echo "OK: backfilled make_order.service_category_id ({$backfill->rowCount()} rows)\n";

if (sm5_col_exists($conn, 'services', 'service_category_id')) {
    $svcBf = $conn->prepare("
        UPDATE services
        SET service_category_id = ?
        WHERE service_category_id IS NULL
    ");
    $svcBf->execute([$catId]);
    echo "OK: backfilled services.service_category_id ({$svcBf->rowCount()} rows)\n";
}

echo "=== Phase 5 complete ===\n";
