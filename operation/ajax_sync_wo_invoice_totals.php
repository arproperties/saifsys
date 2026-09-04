<?php
/**
 * One-time sync: align finalized work order totals with its invoice (Admin/Accountant).
 * Does not create credit notes, supplementary invoices, or GL entries.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/work_order_financial_guard.php';

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

echo json_encode(wo_sync_finalized_order_from_invoice($conn, $orderId, current_user_id()));
