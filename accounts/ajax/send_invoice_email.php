<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/email_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$invoice_id = (int)($_POST['invoice_id'] ?? $_GET['invoice_id'] ?? 0);
$recipient_email = trim($_POST['recipient_email'] ?? $_GET['recipient_email'] ?? '');
$template_id = (int)($_POST['template_id'] ?? $_GET['template_id'] ?? 0);

if ($invoice_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid invoice ID']);
    exit;
}

try {
    $emailService = new EmailService($conn);
    
    // Get invoice data to check if client has email
    $stmt = $conn->prepare("
        SELECT i.*, c.client_name, c.email as client_email
        FROM invoices i
        LEFT JOIN client c ON c.id = i.client_id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        echo json_encode(['success' => false, 'error' => 'Invoice not found']);
        exit;
    }
    
    // Use recipient email from request, or fall back to client email
    if (empty($recipient_email)) {
        $recipient_email = $invoice['client_email'];
    }
    
    if (empty($recipient_email)) {
        echo json_encode(['success' => false, 'error' => 'No email address available for this client']);
        exit;
    }
    
    // Send email
    $result = $emailService->sendInvoiceEmail($invoice_id, $recipient_email, $template_id ?: null);
    
    if ($result['success']) {
        // Log the email sending action
        require_once __DIR__ . '/../../includes/AuditService.php';
        AuditService::log([
            'action' => 'email_sent',
            'object_type' => 'invoices',
            'object_id' => (string)$invoice_id,
            'summary' => "Invoice #{$invoice['invoice_no']} emailed to {$recipient_email}",
            'new_data' => [
                'recipient_email' => $recipient_email,
                'template_id' => $template_id,
                'invoice_no' => $invoice['invoice_no']
            ],
            'success' => true
        ]);
    }
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    error_log('send_invoice_email.php error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'error' => 'Failed to send email: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
