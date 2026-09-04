<?php
/**
 * CLI: archive aged audit_log rows.
 * Usage: php tools/audit_log_retention.php
 * Human approval recommended before production cron.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
$dbHost = (defined('DB_HOST') && DB_HOST === 'localhost') ? '127.0.0.1' : (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
$conn = new PDO(
    'mysql:host=' . $dbHost . ';dbname=' . (defined('DB_NAME') ? DB_NAME : 'datanew') . ';charset=utf8mb4',
    defined('DB_USER') ? DB_USER : 'root',
    defined('DB_PASS') ? DB_PASS : '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
require_once __DIR__ . '/../includes/audit_retention.php';

try {
    $result = audit_log_run_retention($conn);
    echo 'Archived ' . (int)$result['archived'] . ' row(s); retention='
        . (int)$result['retention_months'] . " months\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
