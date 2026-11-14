<?php
// modules/operations/staff_bill_file.php?id=&file= — one attachment of a staff bill.
// Same access as Operations → Staff Bills (Owner, Admin). The files sit in
// uploads/bills/, which the web server refuses to serve.

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/../../includes/staff_bills.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_login(get_application_web_root() . '/login');
ops_require_access($conn);
if (!array_intersect(current_user_roles($conn), ['Owner', 'Admin'])) {
    http_response_code(403);
    exit('Forbidden');
}
session_write_close();

$file = bill_tables_ready($conn)
    ? bill_file($conn, ['user_id' => 0, 'name' => '', 'office' => true], (int)($_GET['id'] ?? 0), (int)($_GET['file'] ?? 0))
    : null;
if ($file === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'That attachment is not there any more.';
    exit;
}
bill_send_file($file);
