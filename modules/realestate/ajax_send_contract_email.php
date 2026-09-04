<?php
/**
 * Real Estate Module - AJAX: Send Contract PDF via Email
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

header('Content-Type: application/json');

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = current_company_id($conn) ?: 1;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// CSRF verification - check for _csrf in POST
if (!isset($_POST['_csrf']) || !hash_equals($_SESSION['_csrf'] ?? '', (string)$_POST['_csrf'])) {
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$leaseId = !empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : 0;

if (!$leaseId) {
    echo json_encode(['success' => false, 'error' => 'Lease ID is required']);
    exit;
}

try {
    // Get lease details with tenant email and contract PDF path
    $stmt = $conn->prepare("
        SELECT l.*, 
               t.first_name, t.last_name, t.email, t.phone,
               u.unit_number,
               b.name as building_name,
               c.name as company_name
        FROM re_leases l
        JOIN re_tenants t ON t.id = l.tenant_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN companies c ON c.id = l.company_id
        WHERE l.id = ? AND l.company_id = ?
    ");
    $stmt->execute([$leaseId, $currentCompanyId]);
    $lease = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lease) {
        echo json_encode(['success' => false, 'error' => 'Lease not found']);
        exit;
    }
    
    if (empty($lease['email'])) {
        echo json_encode(['success' => false, 'error' => 'Tenant email address is not available']);
        exit;
    }
    
    if (empty($lease['generated_contract_path'])) {
        echo json_encode(['success' => false, 'error' => 'Contract PDF has not been generated yet. Please generate the contract first.']);
        exit;
    }
    
    $pdfPath = __DIR__ . '/../../' . $lease['generated_contract_path'];
    if (!file_exists($pdfPath)) {
        echo json_encode(['success' => false, 'error' => 'Contract PDF file not found']);
        exit;
    }
    
    // Get email settings
    $stmt = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
    $stmt->execute();
    $emailSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$emailSettings || empty($emailSettings['smtp_host']) || empty($emailSettings['smtp_username'])) {
        echo json_encode(['success' => false, 'error' => 'Email not configured. Please set up SMTP settings in Email Settings.']);
        exit;
    }
    
    // Load PHPMailer
    require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/Exception.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $emailSettings['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $emailSettings['smtp_username'];
        $mail->Password = $emailSettings['smtp_password'];
        $mail->SMTPSecure = $emailSettings['smtp_secure'] === 'none' ? '' : $emailSettings['smtp_secure'];
        $mail->Port = (int)$emailSettings['smtp_port'];
        $mail->CharSet = 'UTF-8';
        
        // Recipients
        $mail->setFrom($emailSettings['from_email'], $emailSettings['from_name']);
        $mail->addAddress($lease['email'], $lease['first_name'] . ' ' . $lease['last_name']);
        
        // Add PDF attachment
        $pdfFilename = 'Lease_Contract_' . $lease['lease_number'] . '.pdf';
        $mail->addAttachment($pdfPath, $pdfFilename);
        
        // Email content
        $tenantName = $lease['first_name'] . ' ' . $lease['last_name'];
        $leaseNumber = $lease['lease_number'] ?: 'L-' . $leaseId;
        $unitInfo = $lease['building_name'] . ' - ' . $lease['unit_number'];
        $companyName = $lease['company_name'];
        
        $subject = "Lease Contract - " . $leaseNumber . " - " . $companyName;
        
        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #4a90e2; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Lease Contract</h2>
                </div>
                <div class='content'>
                    <p>Dear " . htmlspecialchars($tenantName) . ",</p>
                    <p>Please find attached your lease contract for:</p>
                    <ul>
                        <li><strong>Lease Number:</strong> " . htmlspecialchars($leaseNumber) . "</li>
                        <li><strong>Unit:</strong> " . htmlspecialchars($unitInfo) . "</li>
                        <li><strong>Start Date:</strong> " . date('d/m/Y', strtotime($lease['start_date'])) . "</li>
                        <li><strong>End Date:</strong> " . date('d/m/Y', strtotime($lease['end_date'])) . "</li>
                    </ul>
                    <p>The contract PDF is attached to this email. Please review it carefully and keep a copy for your records.</p>
                    <p>If you have any questions or concerns, please do not hesitate to contact us.</p>
                    <p>Best regards,<br>" . htmlspecialchars($companyName) . "</p>
                </div>
                <div class='footer'>
                    <p>This is an automated email. Please do not reply to this message.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = "Dear " . $tenantName . ",\n\nPlease find attached your lease contract for:\n\nLease Number: " . $leaseNumber . "\nUnit: " . $unitInfo . "\nStart Date: " . date('d/m/Y', strtotime($lease['start_date'])) . "\nEnd Date: " . date('d/m/Y', strtotime($lease['end_date'])) . "\n\nThe contract PDF is attached to this email. Please review it carefully and keep a copy for your records.\n\nIf you have any questions or concerns, please do not hesitate to contact us.\n\nBest regards,\n" . $companyName;
        
        $mail->send();
        
        // Log the email send (optional - you can create a table for this)
        // For now, we'll just return success
        
        echo json_encode([
            'success' => true, 
            'message' => 'Contract sent successfully',
            'email' => $lease['email']
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to send email: ' . $mail->ErrorInfo]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}

