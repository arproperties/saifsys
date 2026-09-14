<?php
/**
 * Switch an employee's access to a company mobile app on or off.
 *
 * One PIN (employee_pin_save.php) signs in to every app; this decides which
 * apps it opens. Today that is the Driver app. Switching it off signs the
 * phone out on its next request — the API re-checks access every time.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_employee_login.php';
require_once __DIR__ . '/includes/hr_fleet.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Same gate as setting the PIN.
$roles = function_exists('current_user_roles') ? current_user_roles() : [];
if (!array_intersect($roles, ['Owner', 'Admin', 'HR'])) {
    http_response_code(403);
    exit('Forbidden');
}

csrf_verify();

$empId = (int)($_POST['employee_id'] ?? 0);
$stmt = $conn->prepare("SELECT id, full_name, employee_code, user_id, company_id FROM employees WHERE id = ? LIMIT 1");
$stmt->execute([$empId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    header('Location: employees.php');
    exit;
}

$back = 'employee_view.php?id=' . urlencode((string)$empId) . '#tab-overview';
$userId = (int)($emp['user_id'] ?? 0);
$allowed = ($_POST['allowed'] ?? '') === '1';
$actorId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
$empLabel = trim((string)$emp['full_name'] . ' (' . (string)($emp['employee_code'] ?: '#' . $empId) . ')');

if (!fleet_tables_ready($conn)) {
    $_SESSION['flash_error'] = 'Vehicle tracking is not set up on this server yet. Run migrations/fleet_tracking.sql.';
} elseif (!hr_employee_has_login($conn, $userId)) {
    $_SESSION['flash_error'] = 'Set a mobile app PIN first — it creates the login that Driver access hangs on.';
} else {
    fleet_app_access_set($conn, $userId, $allowed, $actorId);
    audit_bridge_hr_ops(
        $allowed ? 'app_access_granted' : 'app_access_revoked',
        'user',
        $userId,
        ($allowed ? 'Turned on' : 'Turned off') . ' Driver app access for ' . $empLabel,
        $emp['company_id'] !== null ? (int)$emp['company_id'] : null,
        ['employee_id' => $empId, 'user_id' => $userId, 'app' => FLEET_DRIVER_APP, 'allowed' => $allowed],
        'User #' . $userId . ' — ' . $empLabel,
        $actorId
    );
    $_SESSION['flash_success'] = $allowed
        ? 'Driver app turned on for ' . $emp['full_name'] . '. They sign in with their mobile app PIN.'
        : 'Driver app turned off for ' . $emp['full_name'] . '. Their phone is signed out on its next use.';
}

header('Location: ' . $back);
