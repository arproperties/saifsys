<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$invoice_id = (int)($_GET['id'] ?? 0);

if ($invoice_id <= 0) {
  echo json_encode(['success' => false, 'error' => 'Invalid invoice ID']);
  exit;
}

try {
  // Get audit logs for this invoice
  // Convert invoice_id to string since object_id is VARCHAR
  $st = $conn->prepare("
    SELECT 
      al.created_at,
      al.summary,
      al.action,
      al.object_type,
      al.object_id,
      u.username as user_name
    FROM audit_log al
    LEFT JOIN user u ON u.id = al.user_id
    WHERE al.object_type = 'invoices' 
      AND al.object_id = ?
    ORDER BY al.created_at DESC
    LIMIT 10
  ");
  $st->execute([(string)$invoice_id]);
  $audit_logs = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'success' => true,
    'audit_logs' => $audit_logs
  ]);

} catch (Throwable $e) {
  echo json_encode([
    'success' => false,
    'error' => 'Failed to load audit log: ' . $e->getMessage()
  ]);
}
