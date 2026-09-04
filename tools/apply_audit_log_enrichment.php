<?php
/**
 * Safely apply audit_log enrichment columns (idempotent).
 * CLI: php tools/apply_audit_log_enrichment.php
 * Requires Owner/Admin when hit via web, or CLI.
 */
declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');
require_once dirname(__DIR__) . '/includes/config.php';
// XAMPP/macOS: prefer TCP when DB_HOST is localhost (avoids missing mysql.sock)
$dbHost = (defined('DB_HOST') && DB_HOST === 'localhost') ? '127.0.0.1' : (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
try {
    $conn = new PDO(
        'mysql:host=' . $dbHost . ';dbname=' . (defined('DB_NAME') ? DB_NAME : 'datanew') . ';charset=utf8mb4',
        defined('DB_USER') ? DB_USER : 'root',
        defined('DB_PASS') ? DB_PASS : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}
if (!$isCli) {
    require_once dirname(__DIR__) . '/includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

function col_exists(PDO $conn, string $table, string $col): bool
{
    $st = $conn->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
}

function idx_exists(PDO $conn, string $table, string $idx): bool
{
    $st = $conn->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
    $st->execute([$idx]);
    return (bool)$st->fetch();
}

$adds = [
    'company_id' => "ADD COLUMN `company_id` INT NULL DEFAULT NULL COMMENT 'Company context when known' AFTER `user_role`",
    'module' => "ADD COLUMN `module` VARCHAR(40) NULL DEFAULT NULL AFTER `company_id`",
    'source' => "ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'user' AFTER `module`",
    'action_label' => "ADD COLUMN `action_label` VARCHAR(120) NULL DEFAULT NULL AFTER `action`",
    'object_ref' => "ADD COLUMN `object_ref` VARCHAR(120) NULL DEFAULT NULL AFTER `object_id`",
];

// Order-sensitive AFTER clauses — apply in sequence
$ordered = ['company_id', 'module', 'source', 'action_label', 'object_ref'];
foreach ($ordered as $col) {
    if (!col_exists($conn, 'audit_log', $col)) {
        // Rebuild AFTER based on what exists
        $sql = "ALTER TABLE `audit_log` ";
        if ($col === 'company_id') {
            $sql .= "ADD COLUMN `company_id` INT NULL DEFAULT NULL AFTER `user_role`";
        } elseif ($col === 'module') {
            $sql .= "ADD COLUMN `module` VARCHAR(40) NULL DEFAULT NULL AFTER `company_id`";
        } elseif ($col === 'source') {
            $sql .= "ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'user' AFTER `module`";
        } elseif ($col === 'action_label') {
            $sql .= "ADD COLUMN `action_label` VARCHAR(120) NULL DEFAULT NULL AFTER `action`";
        } elseif ($col === 'object_ref') {
            $sql .= "ADD COLUMN `object_ref` VARCHAR(120) NULL DEFAULT NULL AFTER `object_id`";
        }
        $conn->exec($sql);
        echo "Added column {$col}\n";
    } else {
        echo "Column {$col} already exists\n";
    }
}

$indexes = [
    'idx_audit_company_created' => '(`company_id`, `created_at`)',
    'idx_audit_module_created' => '(`module`, `created_at`)',
    'idx_audit_source' => '(`source`)',
    'idx_audit_object_ref' => '(`object_ref`)',
];
foreach ($indexes as $name => $def) {
    if (!idx_exists($conn, 'audit_log', $name)) {
        $conn->exec("ALTER TABLE `audit_log` ADD INDEX `{$name}` {$def}");
        echo "Added index {$name}\n";
    } else {
        echo "Index {$name} already exists\n";
    }
}

$conn->exec("
CREATE TABLE IF NOT EXISTS `audit_log_archive` (
  `id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT NULL DEFAULT NULL,
  `user_name` VARCHAR(255) NULL DEFAULT NULL,
  `user_role` VARCHAR(255) NULL DEFAULT NULL,
  `company_id` INT NULL DEFAULT NULL,
  `module` VARCHAR(40) NULL DEFAULT NULL,
  `source` VARCHAR(20) NOT NULL DEFAULT 'user',
  `action` VARCHAR(50) NOT NULL,
  `action_label` VARCHAR(120) NULL DEFAULT NULL,
  `object_type` VARCHAR(100) NOT NULL,
  `object_id` VARCHAR(100) NULL DEFAULT NULL,
  `object_ref` VARCHAR(120) NULL DEFAULT NULL,
  `summary` TEXT NOT NULL,
  `old_data` LONGTEXT NULL DEFAULT NULL,
  `new_data` LONGTEXT NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(500) NULL DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `error_message` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_arch_created` (`created_at`),
  KEY `idx_arch_company` (`company_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
echo "audit_log_archive ready\n";

$st = $conn->prepare("SELECT 1 FROM settings WHERE `key` = 'audit_retention_months' LIMIT 1");
$st->execute();
if (!$st->fetchColumn()) {
    $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES ('audit_retention_months', '24')")->execute();
    echo "Inserted audit_retention_months=24\n";
} else {
    echo "audit_retention_months already set\n";
}

if (class_exists('AuditService')) {
    AuditService::resetSchemaCache();
}
echo "Done.\n";
