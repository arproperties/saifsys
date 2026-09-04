<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/email_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$client_id = (int)($_POST['client_id'] ?? $_GET['client_id'] ?? 0);
$from_date = $_POST['from_date'] ?? $_GET['from_date'] ?? '';
$to_date = $_POST['to_date'] ?? $_GET['to_date'] ?? '';
$recipient_email = trim($_POST['recipient_email'] ?? $_GET['recipient_email'] ?? '');
$template_id = (int)($_POST['template_id'] ?? $_GET['template_id'] ?? 0);

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

if (empty($from_date) || empty($to_date)) {
    echo json_encode(['success' => false, 'error' => 'Date range is required']);
    exit;
}

try {
    $emailService = new EmailService($conn);
    
    // Get client data to check if client has email
    $stmt = $conn->prepare("SELECT * FROM client WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        echo json_encode(['success' => false, 'error' => 'Client not found']);
        exit;
    }
    
    // Use recipient email from request, or fall back to client email
    if (empty($recipient_email)) {
        $recipient_email = $client['email'];
    }
    
    if (empty($recipient_email)) {
        echo json_encode(['success' => false, 'error' => 'No email address available for this client']);
        exit;
    }
    
    // Send email
    $result = $emailService->sendStatementEmail($client_id, $from_date, $to_date, $recipient_email, $template_id ?: null);
    
    if ($result['success']) {
        // Log the email sending action
        require_once __DIR__ . '/../../includes/AuditService.php';
        AuditService::log([
            'action' => 'email_sent',
            'object_type' => 'statements',
            'object_id' => (string)$client_id,
            'summary' => "Statement for {$client['client_name']} emailed to {$recipient_email}",
            'new_data' => [
                'recipient_email' => $recipient_email,
                'template_id' => $template_id,
                'client_name' => $client['client_name'],
                'from_date' => $from_date,
                'to_date' => $to_date
            ],
            'success' => true
        ]);
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to send email: ' . $e->getMessage()]);
}
