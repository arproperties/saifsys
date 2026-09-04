<?php
/**
 * Schema helper — Service Management Phase 5b (catalog + multi-booking).
 */
require_once __DIR__ . '/../includes/db_connect.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

function sm5b_col_exists(PDO $conn, string $table, string $col): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

echo "=== SM Phase 5b schema ===\n";

if (!sm5b_col_exists($conn, 'sm_service_categories', 'materials_rate_per_hour')) {
    $conn->exec("
        ALTER TABLE sm_service_categories
        ADD COLUMN materials_rate_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00
        COMMENT 'Cleaning: AED per hour per worker for materials'
    ");
    echo "OK: sm_service_categories.materials_rate_per_hour\n";
} else {
    echo "SKIP: materials_rate_per_hour\n";
}

if (!sm5b_col_exists($conn, 'make_order', 'booking_categories')) {
    $conn->exec("ALTER TABLE make_order ADD COLUMN booking_categories JSON NULL AFTER service_category_id");
    echo "OK: make_order.booking_categories\n";
} else {
    echo "SKIP: booking_categories\n";
}

echo "=== Phase 5b complete ===\n";
