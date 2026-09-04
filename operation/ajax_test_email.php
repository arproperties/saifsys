<?php
// operation/ajax_test_email.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

// Check if user has admin or owner permissions
require_login();
if (!has_role('admin') && !has_role('Owner')) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = (int)($_POST['smtp_port'] ?? 587);
    $smtp_username = trim($_POST['smtp_username'] ?? '');
    $smtp_password = trim($_POST['smtp_password'] ?? '');
    $smtp_encryption = trim($_POST['smtp_encryption'] ?? 'tls');
    $from_email = trim($_POST['from_email'] ?? '');
    $from_name = trim($_POST['from_name'] ?? '');
    
    if (empty($smtp_host) || empty($smtp_username) || empty($from_email)) {
        echo json_encode(['success' => false, 'error' => 'Missing required SMTP settings']);
        exit;
    }
    
    // Get current user's email for testing
    $user_email = current_user_email();
    if (empty($user_email)) {
        echo json_encode(['success' => false, 'error' => 'No email address found for current user']);
        exit;
    }
    
    // Send test email
    $result = sendTestEmail([
        'host' => $smtp_host,
        'port' => $smtp_port,
        'username' => $smtp_username,
        'password' => $smtp_password,
        'encryption' => $smtp_encryption,
        'from_email' => $from_email,
        'from_name' => $from_name
    ], $user_email);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Test failed: ' . $e->getMessage()]);
}

function sendTestEmail(array $smtp_config, string $to_email): array {
    try {
        // Create PHPMailer instance
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtp_config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_config['username'];
        $mail->Password = $smtp_config['password'];
        $mail->SMTPSecure = $smtp_config['encryption'] === 'none' ? '' : $smtp_config['encryption'];
        $mail->Port = $smtp_config['port'];
        
        // Recipients
        $mail->setFrom($smtp_config['from_email'], $smtp_config['from_name']);
        $mail->addAddress($to_email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'HeroSys Email Test';
        $mail->Body = '
            <h2>Email Configuration Test</h2>
            <p>This is a test email from HeroSys to verify that your email settings are working correctly.</p>
            <p><strong>Test Details:</strong></p>
            <ul>
                <li>Sent at: ' . date('Y-m-d H:i:s') . '</li>
                <li>From: ' . htmlspecialchars($smtp_config['from_name']) . ' (' . htmlspecialchars($smtp_config['from_email']) . ')</li>
                <li>SMTP Host: ' . htmlspecialchars($smtp_config['host']) . '</li>
                <li>SMTP Port: ' . $smtp_config['port'] . '</li>
                <li>Encryption: ' . htmlspecialchars($smtp_config['encryption'] ?: 'None') . '</li>
            </ul>
            <p>If you received this email, your email configuration is working correctly!</p>
            <hr>
            <p><small>This is an automated test email from HeroSys Cleaning Management System.</small></p>
        ';
        
        $mail->send();
        
        return [
            'success' => true,
            'message' => 'Test email sent successfully to ' . $to_email
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Failed to send test email: ' . $e->getMessage()
        ];
    }
}

function current_user_email(): string {
    global $conn;
    
    $user_id = current_user_id();
    if (!$user_id) return '';
    
    $st = $conn->prepare("SELECT email FROM user WHERE id = ?");
    $st->execute([$user_id]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    
    return $user['email'] ?? '';
}
?>
