<?php
/**
 * One-time schema helper for Service Management Phase 2.
 * Run from browser (Owner) or CLI: php tools/sm_apply_phase2_schema.php
 */
require_once __DIR__ . '/../includes/db_connect.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

function sm_col_exists(PDO $conn, string $table, string $column): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

function sm_exec(PDO $conn, string $sql, string $label): void
{
    try {
        $conn->exec($sql);
        echo "OK: {$label}\n";
    } catch (Throwable $e) {
        echo "SKIP/FAIL {$label}: " . $e->getMessage() . "\n";
    }
}

echo "=== SM Phase 2 schema ===\n";

$columns = [
    "ALTER TABLE make_order ADD COLUMN is_finalized TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE make_order ADD COLUMN finalized_at DATETIME NULL",
    "ALTER TABLE make_order ADD COLUMN finalized_by INT NULL",
    "ALTER TABLE make_order ADD COLUMN frozen_subtotal DECIMAL(12,2) NULL",
    "ALTER TABLE make_order ADD COLUMN frozen_vat_amount DECIMAL(12,2) NULL",
    "ALTER TABLE make_order ADD COLUMN frozen_grand_total DECIMAL(12,2) NULL",
    "ALTER TABLE make_order ADD COLUMN ops_status ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open'",
];

foreach ($columns as $sql) {
    if (preg_match('/ADD COLUMN (\w+)/', $sql, $m)) {
        $col = $m[1];
        if (sm_col_exists($conn, 'make_order', $col)) {
            echo "SKIP: make_order.{$col} already exists\n";
            continue;
        }
    }
    sm_exec($conn, $sql, $sql);
}

sm_exec($conn, "
    INSERT INTO settings (`key`, `value`)
    SELECT 'sm_defer_auto_invoice', '1'
    FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_defer_auto_invoice')
", 'setting sm_defer_auto_invoice');

if (sm_col_exists($conn, 'make_order', 'is_finalized')) {
    $n = $conn->exec("
        UPDATE make_order mo
        INNER JOIN invoices i ON i.order_id = mo.id AND i.status <> 'void'
        SET mo.is_finalized = 1,
            mo.finalized_at = COALESCE(mo.finalized_at, i.posted_at, i.created_at),
            mo.frozen_subtotal = COALESCE(mo.frozen_subtotal, mo.total),
            mo.frozen_vat_amount = COALESCE(mo.frozen_vat_amount, mo.vat_amount),
            mo.frozen_grand_total = COALESCE(mo.frozen_grand_total, mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0)),
            mo.ops_status = IF(mo.status = 'cancelled', 'cancelled', 'completed')
        WHERE mo.is_finalized = 0
    ");
    echo "OK: backfilled is_finalized for {$n} order(s) with active invoices\n";

    $n2 = $conn->exec("
        UPDATE make_order
        SET ops_status = 'open'
        WHERE is_finalized = 0
          AND COALESCE(status, '') NOT IN ('completed', 'invoiced', 'cancelled')
    ");
    echo "OK: set ops_status=open for {$n2} non-finalized order(s)\n";
}

echo "=== Done ===\n";
