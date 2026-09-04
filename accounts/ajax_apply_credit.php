<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/ar_helpers.php';
header('Content-Type: application/json');
require_role(['Owner','Admin','Account'], $conn);

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
if ($invoice_id <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid invoice']); exit; }

$apply = $_POST['apply_amount'] ?? []; // receipt_id => amount
if (!is_array($apply) || !$apply) { echo json_encode(['success'=>false,'error'=>'No amounts to apply']); exit; }

$ok_any = false;
$applied_credits = [];

foreach ($apply as $rid => $amt) {
  $rid = (int)$rid; $amt = (float)$amt;
  if ($rid > 0 && $amt > 0) {
    $ok = ar_apply_credit($conn, $invoice_id, $rid, $amt);
    if ($ok) {
      $applied_credits[] = "Receipt #{$rid}: " . number_format($amt, 2) . " AED";
    }
    $ok_any = $ok_any || $ok;
  }
}

// Audit Log: Track credit application
if ($ok_any && !empty($applied_credits)) {
  require_once __DIR__ . '/../includes/AuditService.php';
  AuditService::log([
    'action' => 'update',
    'object_type' => 'invoices',
    'object_id' => (string)$invoice_id,
    'summary' => "Applied credit to invoice #{$invoice_id}: " . implode(', ', $applied_credits),
    'new_data' => ['applied_credits' => $applied_credits],
    'success' => true
  ]);
}

echo json_encode(['success'=>$ok_any]);
