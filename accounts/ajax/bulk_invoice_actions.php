<?php
header('Content-Type: application/json');
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/ar_helpers.php';
require_once __DIR__.'/../../includes/email_queue_service.php';
require_role(['Owner','Admin','Account'], $conn);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$invoice_ids = json_decode($_POST['invoice_ids'] ?? $_GET['invoice_ids'] ?? '[]', true);

if (empty($invoice_ids) || !is_array($invoice_ids)) {
  if ($action === 'pdf' || $action === 'csv') {
    header('HTTP/1.1 400 Bad Request');
    exit('No invoices selected');
  }
  echo json_encode(['success' => false, 'error' => 'No invoices selected']);
  exit;
}

// Validate invoice IDs
$invoice_ids = array_filter(array_map('intval', $invoice_ids));
if (empty($invoice_ids)) {
  if ($action === 'pdf' || $action === 'csv') {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid invoice IDs');
  }
  echo json_encode(['success' => false, 'error' => 'Invalid invoice IDs']);
  exit;
}

try {
  switch ($action) {
    case 'email':
      handleBulkEmail($conn, $invoice_ids);
      break;
    case 'void':
      handleBulkVoid($conn, $invoice_ids);
      break;
    case 'pdf':
      handleBulkPDF($conn, $invoice_ids);
      break;
    case 'csv':
      handleBulkCSV($conn, $invoice_ids);
      break;
    default:
      throw new Exception('Invalid action');
  }
} catch (Throwable $e) {
  if ($action === 'pdf' || $action === 'csv') {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Error: ' . $e->getMessage());
  }
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function handleBulkEmail($conn, $invoice_ids) {
  try {
    $emailQueueService = new EmailQueueService($conn);
    
    // Get template ID if provided
    $template_id = (int)($_POST['template_id'] ?? $_GET['template_id'] ?? 0);
    
    // Create queue name
    $queue_name = 'Bulk Invoice Emails - ' . date('Y-m-d H:i:s');
    
    // Create the email queue
    $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
    $result = $emailQueueService->createQueue(
      $queue_name,
      'invoice',
      $invoice_ids,
      $template_id ?: null,
      null, // recipient_emails (will be fetched from client records)
      null, // custom_message
      $userId
    );
    
    if (!$result['success']) {
      throw new Exception($result['error']);
    }
    
    $queue_id = $result['queue_id'];
    
    // Process the queue immediately (in production, this might be handled by a background job)
    $process_result = $emailQueueService->processQueue($queue_id);
    
    if ($process_result['success']) {
      echo json_encode([
        'success' => true, 
        'queue_id' => $queue_id,
        'processed' => $process_result['processed'],
        'failed' => $process_result['failed'],
        'message' => "Email queue created and processed. {$process_result['processed']} emails sent, {$process_result['failed']} failed."
      ]);
    } else {
      echo json_encode([
        'success' => false, 
        'error' => 'Queue created but processing failed: ' . $process_result['error']
      ]);
    }
    
  } catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  }
}

function handleBulkVoid($conn, $invoice_ids) {
  $void_count = 0;
  
  foreach ($invoice_ids as $invoice_id) {
    // Check if invoice can be voided
    $st = $conn->prepare("SELECT status FROM invoices WHERE id = ?");
    $st->execute([$invoice_id]);
    $status = $st->fetchColumn();
    
    if (in_array($status, ['issued', 'partially_paid'])) {
      // Update invoice status
      $conn->prepare("UPDATE invoices SET status = 'void', updated_at = NOW() WHERE id = ?")
           ->execute([$invoice_id]);
      
      // Reverse GL postings
      ar_post_or_repost_invoice($conn, $invoice_id);
      
      $void_count++;
    }
  }
  
  echo json_encode(['success' => true, 'void_count' => $void_count]);
}

function handleBulkPDF($conn, $invoice_ids) {
  // For now, redirect to individual PDF generation
  // In a real implementation, you would generate a ZIP file with all PDFs
  if (count($invoice_ids) === 1) {
    header("Location: ../invoice_print.php?id=" . $invoice_ids[0]);
  } else {
    // For multiple invoices, redirect to first one
    // In production, you'd generate a ZIP file
    header("Location: ../invoice_print.php?id=" . $invoice_ids[0]);
  }
  exit;
}

function handleBulkCSV($conn, $invoice_ids) {
  $placeholders = str_repeat('?,', count($invoice_ids) - 1) . '?';
  
  $st = $conn->prepare("
    SELECT 
      i.invoice_no,
      i.issue_date,
      i.due_date,
      c.client_name,
      i.total,
      COALESCE(pa.amount_paid, 0) AS amount_paid,
      GREATEST(ROUND(COALESCE(i.total,0) - COALESCE(pa.amount_paid,0), 2), 0) AS balance_due,
      i.status
    FROM invoices i
    LEFT JOIN client c ON c.id = i.client_id
    LEFT JOIN (
      SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
      FROM receipt_allocations ra
      GROUP BY ra.invoice_id
    ) pa ON pa.invoice_id = i.id
    WHERE i.id IN ($placeholders)
    ORDER BY i.issue_date DESC
  ");
  $st->execute($invoice_ids);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="invoices_export_' . date('Y-m-d_H-i-s') . '.csv"');
  
  $output = fopen('php://output', 'w');
  
  // CSV headers
  fputcsv($output, [
    'Invoice No',
    'Issue Date', 
    'Due Date',
    'Client Name',
    'Total',
    'Amount Paid',
    'Balance Due',
    'Status'
  ]);
  
  // CSV data
  foreach ($rows as $row) {
    fputcsv($output, [
      $row['invoice_no'],
      $row['issue_date'],
      $row['due_date'],
      $row['client_name'],
      $row['total'],
      $row['amount_paid'],
      $row['balance_due'],
      $row['status']
    ]);
  }
  
  fclose($output);
  exit;
}
