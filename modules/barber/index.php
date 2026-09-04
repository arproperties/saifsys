<?php
/**
 * Barber module entry — back office dashboard if allowed, else tablet POS.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
require_module_access($conn, MODULE_BARBER);
require_any_barber_department($conn);
ensure_current_company_supports_module($conn, MODULE_BARBER);

require_once __DIR__ . '/../../includes/url_helper.php';
$root = get_application_web_root();

$depts = get_user_departments((int)current_user_id(), $conn)[MODULE_BARBER] ?? [];
if (in_array(DEPT_BARBER_BACKOFFICE, $depts, true)) {
    header('Location: ' . $root . '/modules/barber/dashboard.php');
} elseif (in_array(DEPT_BARBER_POS, $depts, true)) {
    header('Location: ' . $root . '/modules/barber/pos.php');
} else {
    http_response_code(403);
    echo 'Access denied';
}
exit;
