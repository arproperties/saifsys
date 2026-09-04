<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../includes/construction_bank_reconciliation.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

header('Content-Type: application/json; charset=utf-8');

$cid = current_company_id($conn) ?: 1;

function co_bank_reco_csrf_ok(): bool
{
    return isset($_POST['_csrf']) && hash_equals($_SESSION['_csrf'] ?? '', (string) ($_POST['_csrf'] ?? ''));
}

function co_bank_reco_json_error(string $msg): void
{
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function co_bank_reco_require_tables(PDO $conn): void
{
    if (!co_bank_reco_tables_ready($conn)) {
        co_bank_reco_json_error('Bank reconciliation tables not installed. Run migrations/construction_bank_reconciliation.sql');
    }
}

function co_bank_reco_guard(PDO $conn, string $permissionKey): void
{
    co_bank_reco_require_permission($conn, $permissionKey);
}
