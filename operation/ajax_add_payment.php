<?php
require_once __DIR__.'/../includes/db_connect.php';
header('Content-Type: application/json');
$order_id = intval($_POST['order_id'] ?? 0);
$payment_date = $_POST['payment_date'] ?? '';
$amount = floatval($_POST['amount'] ?? 0);
$payment_method = $_POST['payment_method'] ?? '';
$notes = $_POST['notes'] ?? '';
if (!$order_id || !$payment_date || !$amount || !$payment_method) {
    echo json_encode(['success'=>false, 'error'=>'Missing data']); exit;
}
$stmt = $conn->prepare("INSERT INTO order_payment (order_id, payment_date, amount, payment_method, notes) VALUES (?,?,?,?,?)");
$ok = $stmt->execute([$order_id, $payment_date, $amount, $payment_method, $notes]);

if ($ok) {
  $payment_id = $conn->lastInsertId();
  
  // Audit Log: Track order payment addition
  require_once __DIR__ . '/../includes/AuditService.php';
  AuditService::logCreate('order_payment', $payment_id, [
    'order_id' => $order_id,
    'payment_date' => $payment_date,
    'amount' => $amount,
    'payment_method' => $payment_method,
    'notes' => $notes
  ], "Added payment of " . number_format($amount, 2) . " AED to order #{$order_id} via {$payment_method}");
}

echo json_encode(['success'=>$ok]);
