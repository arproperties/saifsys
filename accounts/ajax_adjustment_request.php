<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/work_order_adjustment_service.php';

header('Content-Type: application/json');
require_role(['Owner', 'Admin', 'Account'], $conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}
csrf_verify();

$action = $_POST['action'] ?? '';
$requestId = (int)($_POST['request_id'] ?? 0);
$notes = trim((string)($_POST['review_notes'] ?? ''));

if ($requestId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    exit;
}

if ($action === 'approve') {
    echo json_encode(sm_approve_adjustment_request($conn, $requestId, current_user_id(), $notes));
    exit;
}
if ($action === 'reject') {
    if ($notes === '') {
        echo json_encode(['success' => false, 'message' => 'Rejection reason is required.']);
        exit;
    }
    echo json_encode(sm_reject_adjustment_request($conn, $requestId, $notes, current_user_id()));
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
