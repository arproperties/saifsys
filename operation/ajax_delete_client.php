<?php
// operation/ajax_delete_client.php
declare(strict_types=1);

header('Content-Type: application/json');

try {
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db_connect.php';

  // Make sure PDO throws exceptions (if your db_connect doesn't already)
  if (method_exists($conn, 'setAttribute')) {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  $client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
  if ($client_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid client ID']);
    exit;
  }

  // Ensure client exists
  $st = $conn->prepare("SELECT id FROM client WHERE id = ?");
  $st->execute([$client_id]);
  if (!$st->fetchColumn()) {
    echo json_encode(['success' => false, 'error' => 'Client not found']);
    exit;
  }

  // Count relations
  $rel = ['orders'=>0,'invoices'=>0,'receipts'=>0];

  $st = $conn->prepare("SELECT COUNT(*) FROM make_order WHERE client_id = ?");
  $st->execute([$client_id]); $rel['orders'] = (int)$st->fetchColumn();

  $st = $conn->prepare("SELECT COUNT(*) FROM invoices WHERE client_id = ?");
  $st->execute([$client_id]); $rel['invoices'] = (int)$st->fetchColumn();

  $st = $conn->prepare("SELECT COUNT(*) FROM receipts WHERE client_id = ?");
  $st->execute([$client_id]); $rel['receipts'] = (int)$st->fetchColumn();

  $hasRelations = ($rel['orders'] + $rel['invoices'] + $rel['receipts']) > 0;

  // Check if is_active column exists (for soft-delete)
  $hasIsActive = false;
  $chk = $conn->prepare("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client' AND COLUMN_NAME = 'is_active'
  ");
  $chk->execute(); $hasIsActive = ((int)$chk->fetchColumn() > 0);

  if ($hasRelations && !$hasIsActive) {
    echo json_encode([
      'success' => false,
      'error'   => "Client has related data (orders/invoices/receipts) and table 'client' has no 'is_active' column for soft delete."
    ]);
    exit;
  }

  if ($hasRelations) {
    // Soft delete
    $upd = $conn->prepare("UPDATE client SET is_active = 0 WHERE id = ?");
    $upd->execute([$client_id]);
    echo json_encode([
      'success'      => true,
      'soft_deleted' => true,
      'relations'    => $rel,
      'message'      => 'Client marked inactive (has related history).'
    ]);
  } else {
    // Hard delete
    $del = $conn->prepare("DELETE FROM client WHERE id = ?");
    $del->execute([$client_id]);
    echo json_encode([
      'success'   => true,
      'deleted'   => true,
      'relations' => $rel,
      'message'   => 'Client deleted.'
    ]);
  }

} catch (Throwable $e) {
  // Return the actual error so the UI doesn't just show "Server error."
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
