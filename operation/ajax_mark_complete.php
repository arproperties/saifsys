<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/work_order_adjustment_service.php';

header('Content-Type: application/json');

if (!current_user_id()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}
csrf_verify();

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

echo json_encode(sm_mark_order_complete($conn, $orderId, current_user_id()));
