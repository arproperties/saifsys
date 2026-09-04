<?php
/**
 * Schema helper for Service Management Phase 3.
 * Run: php tools/sm_apply_phase3_schema.php (or browser as Owner/Admin)
 */
require_once __DIR__ . '/../includes/db_connect.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

function sm3_table_exists(PDO $conn, string $table): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

echo "=== SM Phase 3 schema ===\n";

if (!sm3_table_exists($conn, 'sm_adjustment_requests')) {
    $conn->exec("
        CREATE TABLE sm_adjustment_requests (
          id INT NOT NULL AUTO_INCREMENT,
          order_id INT NOT NULL,
          invoice_id INT NULL,
          request_type ENUM('amount_decrease','amount_increase','cancellation','other') NOT NULL DEFAULT 'other',
          status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
          reason VARCHAR(500) NOT NULL,
          notes TEXT NULL,
          current_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          current_vat DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          current_grand DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          requested_subtotal DECIMAL(12,2) NULL,
          requested_vat DECIMAL(12,2) NULL,
          requested_grand DECIMAL(12,2) NULL,
          delta_grand DECIMAL(12,2) NULL,
          resolution_type ENUM('credit_note','supplementary_invoice','void_invoice','manual','none') NULL,
          credit_note_id INT NULL,
          adjustment_invoice_id INT NULL,
          requested_by INT NULL,
          requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          reviewed_by INT NULL,
          reviewed_at DATETIME NULL,
          review_notes TEXT NULL,
          PRIMARY KEY (id),
          KEY idx_sm_adj_order (order_id),
          KEY idx_sm_adj_status (status),
          KEY idx_sm_adj_invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK: created sm_adjustment_requests\n";
} else {
    echo "SKIP: sm_adjustment_requests exists\n";
}

if (!sm3_table_exists($conn, 'sm_financial_change_log')) {
    $conn->exec("
        CREATE TABLE sm_financial_change_log (
          id INT NOT NULL AUTO_INCREMENT,
          order_id INT NOT NULL,
          adjustment_request_id INT NULL,
          field_name VARCHAR(64) NOT NULL,
          old_value VARCHAR(255) NULL,
          new_value VARCHAR(255) NULL,
          change_source VARCHAR(64) NOT NULL DEFAULT 'adjustment',
          user_id INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_sm_fcl_order (order_id),
          KEY idx_sm_fcl_adj (adjustment_request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK: created sm_financial_change_log\n";
} else {
    echo "SKIP: sm_financial_change_log exists\n";
}

echo "=== Done ===\n";
