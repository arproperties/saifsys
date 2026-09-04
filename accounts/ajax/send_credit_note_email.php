<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/email_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'error' => 'Invalid request method']);
  exit;
}

try {
  $credit_note_id = (int)($_POST['credit_note_id'] ?? 0);
  $email_to = trim($_POST['email_to'] ?? '');
  $email_subject = trim($_POST['email_subject'] ?? '');
  $email_message = trim($_POST['email_message'] ?? '');
  
  if ($credit_note_id <= 0) {
    throw new Exception('Invalid credit note ID');
  }
  
  if (empty($email_to) || !filter_var($email_to, FILTER_VALIDATE_EMAIL)) {
    throw new Exception('Invalid email address');
  }
  
  if (empty($email_subject)) {
    throw new Exception('Email subject is required');
  }
  
  // Get credit note details
  $stmt = $conn->prepare("
    SELECT cn.*, c.client_name, c.client_email
    FROM credit_notes cn
    LEFT JOIN client c ON cn.client_id = c.id
    WHERE cn.id = ?
  ");
  $stmt->execute([$credit_note_id]);
  $credit_note = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$credit_note) {
    throw new Exception('Credit note not found');
  }
  
  if ($credit_note['status'] !== 'issued') {
    throw new Exception('Credit note must be issued before sending email');
  }
  
  // Generate PDF
  $pdf_path = generateCreditNotePDF($conn, $credit_note_id);
  if (!$pdf_path) {
    throw new Exception('Failed to generate PDF');
  }
  
  // Prepare email content
  $email_body = $email_message ?: "Please find attached the credit note {$credit_note['credit_note_number']}.";
  
  // Send email
  $emailService = new EmailService($conn);
  $result = $emailService->sendEmail(
    $email_to,
    $email_subject,
    $email_body,
    [$pdf_path], // Attachments
    'credit_note', // Template type
    [
      'credit_note_number' => $credit_note['credit_note_number'],
      'client_name' => $credit_note['client_name'],
      'credit_note_date' => date('M d, Y', strtotime($credit_note['credit_note_date'])),
      'total_amount' => number_format($credit_note['total_amount'], 2)
    ]
  );
  
  if ($result['success']) {
    echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
  } else {
    throw new Exception($result['error']);
  }
  
} catch (Exception $e) {
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function generateCreditNotePDF($conn, $credit_note_id) {
  // This is a placeholder - in a real implementation, you would generate the PDF here
  // For now, we'll return a dummy path
  $credit_note_number = "CN-" . date('Y') . "-" . str_pad($credit_note_id, 5, '0', STR_PAD_LEFT);
  $pdf_path = "storage/credit_notes/{$credit_note_number}.pdf";
  
  // Create directory if it doesn't exist
  $dir = dirname($pdf_path);
  if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
  }
  
  // In a real implementation, you would use a PDF library like Dompdf or TCPDF
  // to generate the actual PDF content here
  
  return $pdf_path;
}
?>
