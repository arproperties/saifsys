<?php

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';

require_role(['Owner','Admin','Dispatcher'], $conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: ../operation.php?tab=workorder');
    exit;
}

// Get order data before deletion for audit log
$orderStmt = $conn->prepare("SELECT * FROM make_order WHERE id = ?");
$orderStmt->execute([$id]);
$orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);

if ($orderData) {
    // Audit Log: Track order deletion
    require_once __DIR__ . '/../includes/AuditService.php';
    AuditService::logDelete('make_order', $id, $orderData, "Deleted order #{$id} for {$orderData['client_name']}", current_user_id());
}

// remove ordering links first
$stmt = $conn->prepare("DELETE FROM order_workers WHERE order_id = ?");
$stmt->execute([$id]);

// delete the order
$stmt = $conn->prepare("DELETE FROM make_order WHERE id = ?");
$stmt->execute([$id]);

$_SESSION['message'] = 'Order deleted successfully!';
header('Location: ../operation.php?tab=workorder');
exit;
?>
