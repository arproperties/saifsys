<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
header('Content-Type: application/json');

function jerr($msg) { 
  echo json_encode(['success' => false, 'error' => $msg]); 
  exit; 
}

function jok($data = []) { 
  echo json_encode(array_merge(['success' => true], $data)); 
  exit; 
}

// Get parameters
$client_id = (int)($_POST['client_id'] ?? 0);
$status = $_POST['status'] ?? '';

if (!$client_id) {
  jerr('Client ID required');
}

// Validate status
$valid_statuses = ['active', 'inactive', 'vip', 'at_risk'];
if (!in_array($status, $valid_statuses)) {
  jerr('Invalid status. Must be one of: ' . implode(', ', $valid_statuses));
}

try {
  // Check if client exists
  $st = $conn->prepare("SELECT id, client_name FROM client WHERE id = ? AND is_active = 1");
  $st->execute([$client_id]);
  $client = $st->fetch(PDO::FETCH_ASSOC);
  
  if (!$client) {
    jerr('Client not found');
  }
  
  // Update client status
  $st = $conn->prepare("UPDATE client SET client_status = ? WHERE id = ?");
  $st->execute([$status, $client_id]);
  
  if ($st->rowCount() === 0) {
    jerr('No changes made');
  }
  
  jok([
    'message' => "Client '{$client['client_name']}' status updated to " . strtoupper($status),
    'client_id' => $client_id,
    'new_status' => $status
  ]);
  
} catch (PDOException $e) {
  error_log("Status update error: " . $e->getMessage());
  jerr('Database error occurred');
}
?>
