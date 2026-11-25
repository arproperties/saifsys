<?php
// modules/operations/store_camera_alerts.php — the Operations bell's list (JSON), asked every half
// minute by the bell in includes/ops_layout_header.php. Owner and Admin only, like Store Camera.
// Asking also rings "store camera program not running" when the laptop program has gone quiet.
// {"ok": true, "latest": 12, "items": [{"id", "kind": "movement|count|stopped", "title", "detail", "url", "at"}]}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_store_camera.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!current_user_id() || !array_intersect(current_user_roles($conn), ['Owner', 'Admin'])) {
    http_response_code(403);
    exit(json_encode(['ok' => false]));
}
session_write_close();

if (!sc_ready($conn) || !sc_checks_ready($conn)) {
    exit(json_encode(['ok' => true, 'latest' => 0, 'items' => []]));
}
sc_check_stopped($conn);
$items = sc_alerts($conn, 20);
echo json_encode(['ok' => true, 'latest' => $items ? $items[0]['id'] : 0, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
