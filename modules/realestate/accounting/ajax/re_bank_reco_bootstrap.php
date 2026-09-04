<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/db_connect.php';
require_once __DIR__ . '/../../../../includes/company_helper.php';
require_once __DIR__ . '/../../../../includes/module_access.php';
require_once __DIR__ . '/../../../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/re_bank_reco_core.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

header('Content-Type: application/json; charset=utf-8');

$cid = current_company_id($conn) ?: 1;

function re_bank_reco_csrf_ok(): bool
{
    return isset($_POST['_csrf']) && hash_equals($_SESSION['_csrf'] ?? '', (string) ($_POST['_csrf'] ?? ''));
}

function re_bank_reco_json_error(string $msg): void
{
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function re_bank_reco_require_tables(PDO $conn): void
{
    if (!re_bank_reco_tables_ready($conn)) {
        re_bank_reco_json_error('Bank reconciliation tables not installed. Run migrations/re_accounting_phase8_bank_reconciliation.sql and migrations/re_bank_reconciliation_v2.sql');
    }
}

function re_bank_reco_guard(PDO $conn, string $permissionKey): void
{
    re_bank_reco_require_permission($conn, $permissionKey);
}
