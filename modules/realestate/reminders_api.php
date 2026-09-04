<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/re_reminder_helper.php';

function re_reminder_api_response(bool $ok, array $payload = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $ok], $payload));
    exit;
}

try {
    require_login();
    require_module_access($conn, MODULE_REALESTATE);

    if (!re_reminder_tables_ready($conn)) {
        re_reminder_api_response(false, ['error' => 'Reminder tables are not installed. Run migrations/re_reminders.sql.'], 503);
    }

    $companyId = current_company_id($conn) ?: 1;
    $userId = current_user_id() ?: 0;
    $isAdmin = re_reminder_admin($conn);
    if (
        !$isAdmin
        && !has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_OPERATIONS, $conn)
        && !has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)
    ) {
        re_reminder_api_response(false, ['error' => 'Forbidden'], 403);
    }
    $action = $_GET['action'] ?? $_POST['action'] ?? 'list';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
    }

    if ($action === 'list') {
        $where = ['company_id = ?'];
        $args = [$companyId];
        if (!$isAdmin) {
            $where[] = 'created_by = ?';
            $args[] = $userId;
        }
        $stmt = $conn->prepare("
            SELECT *
            FROM re_reminders
            WHERE " . implode(' AND ', $where) . "
            ORDER BY status = 'active' DESC, next_run_at IS NULL, next_run_at ASC, id DESC
            LIMIT 500
        ");
        $stmt->execute($args);
        re_reminder_api_response(true, ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $sql = "SELECT * FROM re_reminders WHERE id = ? AND company_id = ?";
        $args = [$id, $companyId];
        if (!$isAdmin) {
            $sql .= " AND created_by = ?";
            $args[] = $userId;
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            re_reminder_api_response(false, ['error' => 'Reminder not found.'], 404);
        }
        re_reminder_api_response(true, ['data' => $row]);
    }

    if ($action === 'create') {
        $id = re_reminder_create($conn, $companyId, $userId, re_reminder_payload_from_request($_POST));
        re_reminder_api_response(true, ['id' => $id], 201);
    }

    if ($action === 'update') {
        re_reminder_update($conn, $companyId, (int)($_POST['id'] ?? 0), $userId, $isAdmin, re_reminder_payload_from_request($_POST));
        re_reminder_api_response(true);
    }

    if ($action === 'delete') {
        re_reminder_delete($conn, $companyId, (int)($_POST['id'] ?? 0), $userId, $isAdmin);
        re_reminder_api_response(true);
    }

    if ($action === 'set_status') {
        re_reminder_set_status($conn, $companyId, (int)($_POST['id'] ?? 0), $userId, $isAdmin, $_POST['status'] ?? 'inactive');
        re_reminder_api_response(true);
    }

    re_reminder_api_response(false, ['error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    re_reminder_api_response(false, ['error' => $e->getMessage()], 400);
}
