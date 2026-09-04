<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/accounting_health_service.php';
require_once __DIR__.'/../includes/cleaning_payment_accounts.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$client_id = (int)($_POST['client_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$method = trim($_POST['payment_method'] ?? 'cash');
$date = $_POST['payment_date'] ?? date('Y-m-d');
$notes = trim($_POST['notes'] ?? '');
$deposit_account_no = trim($_POST['deposit_account_no'] ?? '');

// Debug: Log the received data (can be removed in production)
// error_log("Quick Payment Debug - Client: $client_id, Amount: $amount, Method: '$method', Date: $date, Notes: '$notes'");

if ($client_id <= 0) {
  echo json_encode(['success' => false, 'error' => 'Please select a client']);
  exit;
}

if ($amount <= 0) {
  echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']);
  exit;
}

// Ensure method is not empty
if (empty($method)) {
  $method = 'cash'; // Default to cash if empty
}

if ($deposit_account_no === '') {
  echo json_encode(['success' => false, 'error' => 'Deposit account is required.']);
  exit;
}

try {
  cleaning_validate_payment_account_no($conn, $deposit_account_no);
} catch (Throwable $e) {
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  exit;
}

// Ensure user_id is valid
$user_id = $_SESSION['user_id'] ?? null;
if (empty($user_id)) {
  $user_id = 1; // Default user if not set
}

try {
  $conn->beginTransaction();

  // Create a receipt for the payment
  $receipt_no = 'RCT-'.date('YmdHis').'-'.mt_rand(100,999);
  
  // Debug: Log the exact values being inserted (can be removed in production)
  // error_log("Inserting receipt - Client: $client_id, Receipt: $receipt_no, Date: $date, Method: '$method', Amount: $amount, Notes: '$notes'");
  
  // Get current company_id
  require_once __DIR__ . '/../includes/company_helper.php';
  $currentCompanyId = current_company_id($conn) ?: 1;
  
  $insR = $conn->prepare("
    INSERT INTO receipts
      (company_id, client_id, receipt_no, receipt_date, method, deposit_account_no, reference, amount, notes, created_at, created_by)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
  ");
  $insR->execute([
    $currentCompanyId,
    $client_id,
    $receipt_no,
    $date,
    $method,
    $deposit_account_no,
    $method,  // reference field
    $amount,
    $notes,
    $user_id
  ]);
  $receipt_id = (int)$conn->lastInsertId();

  // Post to GL
  ar_post_or_repost_receipt($conn, $receipt_id);

  // Automatically apply payment to outstanding invoices
  $applied_invoices = auto_apply_payment_to_invoices($conn, $receipt_id, $client_id, $amount);

  accounting_health_assert_receipt($conn, $receipt_id);

  // Clear dashboard cache to ensure AR totals update immediately
  if (!empty($applied_invoices)) {
    require_once __DIR__ . '/../includes/caching_service.php';
    $cacheService = new CachingService($conn);
    $cacheService->invalidatePattern('ar_');
    $cacheService->invalidatePattern('top_');
    $cacheService->invalidatePattern('dashboard');
    
    // Also clear specific cache keys that might be affected
    $conn->prepare("DELETE FROM dashboard_cache WHERE cache_key IN ('ar_summary', 'ar_ageing', 'top_overdue_10', 'top_clients_balance_10')")->execute();
  }

  // Audit Log: Track quick payment
  require_once __DIR__ . '/../includes/AuditService.php';
  AuditService::log([
    'action' => 'insert',
    'object_type' => 'receipts',
    'object_id' => (string)$receipt_id,
    'summary' => "Quick payment of " . number_format($amount, 2) . " AED via {$method} for client #{$client_id}",
    'new_data' => [
      'receipt_no' => $receipt_no,
      'receipt_date' => $date,
      'method' => $method,
      'deposit_account_no' => $deposit_account_no,
      'amount' => $amount,
      'notes' => $notes,
      'client_id' => $client_id
    ],
    'success' => true,
    'user_id' => $user_id
  ]);

  $conn->commit();

  echo json_encode([
    'success' => true, 
    'receipt_id' => $receipt_id,
    'applied_invoices' => $applied_invoices,
    'message' => count($applied_invoices) > 0 
      ? 'Payment applied to ' . count($applied_invoices) . ' outstanding invoice(s)' 
      : 'Payment recorded as credit (no outstanding invoices)'
  ]);
} catch (Throwable $e) {
  if ($conn->inTransaction()) $conn->rollBack();
  // Log the full error for debugging
  error_log("Quick Payment Error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
  echo json_encode(['success' => false, 'error' => 'Failed to record payment: ' . $e->getMessage()]);
}

/**
 * Automatically apply payment to outstanding invoices for a client
 */
function auto_apply_payment_to_invoices(PDO $conn, int $receipt_id, int $client_id, float $total_amount) {
  try {
    // Get outstanding invoices for this client, ordered by due date (oldest first)
    $stmt = $conn->prepare("
      SELECT i.id, i.invoice_no, i.total, i.due_date,
             (i.total - COALESCE(SUM(ra.amount_applied), 0)) as balance_due
      FROM invoices i
      LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
      WHERE i.client_id = ? 
        AND i.status IN ('issued', 'partially_paid')
        AND COALESCE(i.is_batch_summary, 0) = 0
      GROUP BY i.id
      HAVING balance_due > 0
      ORDER BY i.due_date ASC, i.id ASC
    ");
    $stmt->execute([$client_id]);
    $outstanding_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $remaining_amount = $total_amount;
    $applied_invoices = [];
    
    foreach ($outstanding_invoices as $invoice) {
      if ($remaining_amount <= 0) break;
      
      $balance_due = (float)$invoice['balance_due'];
      $amount_to_apply = min($remaining_amount, $balance_due);
      
      if ($amount_to_apply > 0) {
        // Apply payment to this invoice
        if (ar_apply_credit($conn, $invoice['id'], $receipt_id, $amount_to_apply)) {
          $remaining_amount -= $amount_to_apply;
          $applied_invoices[] = [
            'invoice_no' => $invoice['invoice_no'],
            'amount_applied' => $amount_to_apply
          ];
        }
      }
    }
    
    // Log the automatic allocation
    if (!empty($applied_invoices)) {
      error_log("Auto-applied payment of {$total_amount} to " . count($applied_invoices) . " invoices for client {$client_id}");
    }
    
    return $applied_invoices;
    
  } catch (Throwable $e) {
    error_log("Error in auto_apply_payment_to_invoices: " . $e->getMessage());
    return [];
  }
}
