<?php
/**
 * Schema helper — Service Management Phase 4 (hybrid batch invoicing).
 */
require_once __DIR__ . '/../includes/db_connect.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

function sm4_table_exists(PDO $conn, string $table): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function sm4_col_exists(PDO $conn, string $table, string $col): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

echo "=== SM Phase 4 schema ===\n";

if (!sm4_col_exists($conn, 'invoices', 'is_batch_summary')) {
    $conn->exec("ALTER TABLE invoices ADD COLUMN is_batch_summary TINYINT(1) NOT NULL DEFAULT 0");
    echo "OK: invoices.is_batch_summary\n";
} else {
    echo "SKIP: invoices.is_batch_summary\n";
}

if (!sm4_table_exists($conn, 'sm_invoice_batches')) {
    $conn->exec("
        CREATE TABLE sm_invoice_batches (
          id INT NOT NULL AUTO_INCREMENT,
          client_id INT NOT NULL,
          batch_invoice_id INT NOT NULL,
          range_start DATE NOT NULL,
          range_end DATE NOT NULL,
          child_count INT NOT NULL DEFAULT 0,
          subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          status ENUM('draft','issued','void') NOT NULL DEFAULT 'issued',
          notes VARCHAR(500) NULL,
          created_by INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          issued_at DATETIME NULL,
          PRIMARY KEY (id),
          KEY idx_sm_batch_client (client_id),
          KEY idx_sm_batch_invoice (batch_invoice_id),
          KEY idx_sm_batch_range (range_start, range_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK: sm_invoice_batches\n";
} else {
    echo "SKIP: sm_invoice_batches\n";
}

if (!sm4_table_exists($conn, 'sm_invoice_batch_lines')) {
    $conn->exec("
        CREATE TABLE sm_invoice_batch_lines (
          id INT NOT NULL AUTO_INCREMENT,
          batch_id INT NOT NULL,
          order_id INT NOT NULL,
          child_invoice_id INT NOT NULL,
          line_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          line_vat DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          PRIMARY KEY (id),
          UNIQUE KEY uq_sm_batch_order (batch_id, order_id),
          UNIQUE KEY uq_sm_batch_child (batch_id, child_invoice_id),
          KEY idx_sm_batch_line_order (order_id),
          KEY idx_sm_batch_line_inv (child_invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK: sm_invoice_batch_lines\n";
} else {
    echo "SKIP: sm_invoice_batch_lines\n";
}

$conn->exec("
    INSERT INTO settings (`key`, `value`)
    SELECT 'sm_hybrid_batch_invoicing', '1'
    FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_hybrid_batch_invoicing')
");
echo "OK: setting sm_hybrid_batch_invoicing\n";
echo "=== Done ===\n";
