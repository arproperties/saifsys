<?php
// modules/operations/purchase_file.php?id=&file= — one photo (or PDF) of a purchase: the bill
// or the delivery note. Same access as Operations → Purchases. The files sit in
// uploads/purchases/, which the web server refuses to serve.

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_purchases.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_login(get_application_web_root() . '/login');
ops_require_access($conn);
session_write_close();

$file = pur_tables_ready($conn)
    ? pur_file($conn, ['user_id' => (int)current_user_id(), 'name' => '', 'office' => true], (int)($_GET['id'] ?? 0), (int)($_GET['file'] ?? 0))
    : null;
if ($file === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
pur_send_file($file);
