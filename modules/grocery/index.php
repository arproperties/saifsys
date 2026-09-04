<?php
/**
 * Grocery module entry — back office dashboard if allowed, else retail POS.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/url_helper.php';

require_login();
require_module_access($conn, MODULE_GROCERY);
require_any_grocery_department($conn);
ensure_current_company_supports_module($conn, MODULE_GROCERY);

$root = get_application_web_root();
$depts = get_user_departments((int)current_user_id(), $conn)[MODULE_GROCERY] ?? [];
if (in_array(DEPT_GROCERY_BACKOFFICE, $depts, true)) {
    header('Location: ' . $root . '/modules/grocery/pos_dashboard.php', true, 302);
} elseif (in_array(DEPT_GROCERY_POS, $depts, true)) {
    header('Location: ' . $root . '/modules/grocery/pos_retail.php', true, 302);
} else {
    http_response_code(403);
    echo 'Access denied';
}
exit;
