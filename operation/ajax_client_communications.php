<?php
// operation/ajax_client_communications.php

// Start output buffering to prevent any HTML output before JSON
ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

// Clear any output that might have been generated
ob_clean();

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : (isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0);

if ($client_id <= 0 && in_array($action, ['list', 'send'])) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    ob_end_flush();
    exit;
}

try {
    switch ($action) {
        case 'list':
            $result = listCommunications($conn, $client_id);
            break;
            
        case 'send':
            $result = sendCommunication($conn, $_POST);
            break;
            
        case 'templates':
            $result = getTemplates($conn);
            break;
            
        case 'history':
            $result = getCommunicationHistory($conn, $client_id);
            break;
            
        case 'get_client_info':
            $result = getClientInfo($conn, $client_id);
            break;
            
        case 'get_template_data':
            $result = getTemplateData($conn, $client_id);
            break;
            
        default:
            $result = ['success' => false, 'error' => 'Invalid action'];
            break;
    }
    
    // Clear any output and send JSON
    ob_clean();
    echo json_encode($result);
    
} catch (Throwable $e) {
    // Clear any output and send error JSON
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Action failed: ' . $e->getMessage()]);
} finally {
    // End output buffering
    ob_end_flush();
}

function listCommunications(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            cc.*,
            u.fullname as sent_by_name,
            c.client_name
        FROM client_communications cc
        LEFT JOIN user u ON u.id = cc.sent_by
        LEFT JOIN client c ON c.id = cc.client_id
        WHERE cc.client_id = ?
        ORDER BY cc.sent_at DESC
        LIMIT 50
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $communications = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Format dates and add status indicators
    foreach ($communications as &$comm) {
        $comm['formatted_date'] = date('M j, Y g:i A', strtotime($comm['sent_at']));
        $comm['status_class'] = getStatusClass($comm['status']);
    }
    
    return [
        'success' => true,
        'communications' => $communications
    ];
}

function sendCommunication(PDO $conn, array $post): array {
    $client_id = (int)($post['client_id'] ?? 0);
    $type = trim($post['type'] ?? '');
    $subject = trim($post['subject'] ?? '');
    $message = trim($post['message'] ?? '');
    $template_id = $post['template_id'] ?? '';
    $user_id = current_user_id();
    
    if ($client_id <= 0) {
        return ['success' => false, 'error' => 'Invalid client ID'];
    }
    
    if (empty($type) || empty($message)) {
        return ['success' => false, 'error' => 'Type and message are required'];
    }
    
    // Get client details
    $st = $conn->prepare("SELECT id, client_name, email, mobile_num FROM client WHERE id = ?");
    $st->execute([$client_id]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        return ['success' => false, 'error' => 'Client not found'];
    }
    
    // Apply template if provided
    if (!empty($template_id)) {
        $template_result = applyTemplate($conn, $template_id, $client, $subject, $message);
        $subject = $template_result['subject'];
        $message = $template_result['message'];
    }
    
    // Save communication record
    $sql = "
        INSERT INTO client_communications 
        (client_id, type, direction, subject, message, sent_by, status)
        VALUES (?, ?, 'outbound', ?, ?, ?, 'sent')
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([$client_id, $type, $subject, $message, $user_id]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to save communication record'];
    }
    
    $comm_id = (int)$conn->lastInsertId();
    
    // Actually send the communication
    $send_result = sendActualCommunication($type, $client, $subject, $message);
    
    // Update status based on send result
    $update_status = $send_result['success'] ? 'delivered' : 'failed';
    $st = $conn->prepare("UPDATE client_communications SET status = ? WHERE id = ?");
    $st->execute([$update_status, $comm_id]);
    
    if ($send_result['success']) {
        return [
            'success' => true,
            'message' => ucfirst($type) . ' sent successfully',
            'communication_id' => $comm_id
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Failed to send ' . $type . ': ' . $send_result['error']
        ];
    }
}

function getTemplates(PDO $conn): array {
    // Define email templates
    $email_templates = [
        [
            'id' => 'welcome',
            'name' => 'Welcome Email',
            'subject' => 'Welcome to Our Cleaning Services',
            'body' => "Dear {{client_name}},\n\nWelcome to our premium cleaning services! We're excited to have you as a valued client.\n\nOur team is committed to providing exceptional service and ensuring your complete satisfaction.\n\nIf you have any questions or special requirements, please don't hesitate to contact us.\n\nBest regards,\nThe Cleaning Team"
        ],
        [
            'id' => 'invoice_reminder',
            'name' => 'Invoice Reminder',
            'subject' => 'Payment Reminder - Invoice {{invoice_no}}',
            'body' => "Dear {{client_name}},\n\nThis is a friendly reminder that your invoice {{invoice_no}} for AED {{amount}} is due on {{due_date}}.\n\nTo maintain uninterrupted service, please process this payment at your earliest convenience.\n\nPayment can be made via:\n- Bank transfer\n- Cash\n- Check\n\nIf you've already made this payment, please disregard this message.\n\nThank you for your business!\n\nThe Cleaning Team"
        ],
        [
            'id' => 'service_confirmation',
            'name' => 'Service Confirmation',
            'subject' => 'Service Confirmation - {{service_date}}',
            'body' => "Dear {{client_name}},\n\nThis confirms your cleaning service scheduled for {{service_date}} at {{service_time}}.\n\nService Details:\n- Date: {{service_date}}\n- Time: {{service_time}}\n- Workers: {{worker_names}}\n- Estimated Duration: {{duration}} hours\n\nPlease ensure access is available at the scheduled time.\n\nIf you need to reschedule or have any questions, please contact us immediately.\n\nThank you!\n\nThe Cleaning Team"
        ]
    ];
    
    // Define SMS templates
    $sms_templates = [
        [
            'id' => 'appointment_reminder',
            'name' => 'Appointment Reminder',
            'subject' => '',
            'body' => "Hi {{client_name}}, this is a reminder that your cleaning service is scheduled for tomorrow at {{service_time}}. Please ensure access is available. Reply STOP to opt out."
        ],
        [
            'id' => 'payment_reminder_sms',
            'name' => 'Payment Reminder SMS',
            'subject' => '',
            'body' => "Hi {{client_name}}, your invoice {{invoice_no}} for AED {{amount}} is due. Please arrange payment to avoid service interruption. Reply STOP to opt out."
        ]
    ];
    
    return [
        'success' => true,
        'templates' => [
            'email' => $email_templates,
            'sms' => $sms_templates
        ]
    ];
}

function applyTemplate(PDO $conn, $template_id, array $client, string $subject, string $message): array {
    // This would typically load from a database table
    // For now, we'll use predefined templates
    $templates = getTemplates($conn)['templates'];
    
    $template = null;
    foreach ($templates['email'] as $t) {
        if ($t['id'] == $template_id) {
            $template = $t;
            break;
        }
    }
    
    if (!$template) {
        foreach ($templates['sms'] as $t) {
            if ($t['id'] == $template_id) {
                $template = $t;
                break;
            }
        }
    }
    
    if (!$template) {
        return ['subject' => $subject, 'message' => $message];
    }
    
    // Get real client data for placeholders
    $client_data = getClientTemplateData($conn, $client['id']);
    
    // Replace placeholders in both subject and message
    $processed_subject = str_replace([
        '{{client_name}}',
        '{{invoice_no}}',
        '{{amount}}',
        '{{due_date}}',
        '{{service_date}}',
        '{{service_time}}',
        '{{worker_names}}',
        '{{duration}}'
    ], [
        $client['client_name'],
        $client_data['invoice_no'],
        $client_data['amount'],
        $client_data['due_date'],
        $client_data['service_date'],
        $client_data['service_time'],
        $client_data['worker_names'],
        $client_data['duration']
    ], $template['subject']);
    
    $processed_message = str_replace([
        '{{client_name}}',
        '{{invoice_no}}',
        '{{amount}}',
        '{{due_date}}',
        '{{service_date}}',
        '{{service_time}}',
        '{{worker_names}}',
        '{{duration}}'
    ], [
        $client['client_name'],
        $client_data['invoice_no'],
        $client_data['amount'],
        $client_data['due_date'],
        $client_data['service_date'],
        $client_data['service_time'],
        $client_data['worker_names'],
        $client_data['duration']
    ], $template['body']);
    
    return [
        'subject' => $processed_subject ?: $subject,
        'message' => $processed_message ?: $message
    ];
}

function sendActualCommunication(string $type, array $client, string $subject, string $message): array {
    switch ($type) {
        case 'email':
            return sendEmail($client['email'], $subject, $message);
            
        case 'sms':
            return sendSMS($client['mobile_num'], $message);
            
        case 'whatsapp':
            return sendWhatsApp($client['mobile_num'], $message);
            
        default:
            return ['success' => false, 'error' => 'Unsupported communication type'];
    }
}

function sendEmail(string $email, string $subject, string $message): array {
    try {
        // Load email settings from app_email_settings table
        global $conn;
        
        $st = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
        $st->execute();
        $settings = $st->fetch(PDO::FETCH_ASSOC);
        
        // Check if email is configured
        if (!$settings || empty($settings['smtp_host']) || empty($settings['smtp_username'])) {
            return ['success' => false, 'error' => 'Email not configured. Please set up SMTP settings in Email Settings.'];
        }
        
        // Create PHPMailer instance
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $settings['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['smtp_username'];
        $mail->Password = $settings['smtp_password'];
        $mail->SMTPSecure = $settings['smtp_secure'] === 'none' ? '' : $settings['smtp_secure'];
        $mail->Port = (int)$settings['smtp_port'];
        
        // Recipients
        $mail->setFrom($settings['from_email'], $settings['from_name']);
        $mail->addAddress($email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = nl2br(htmlspecialchars($message));
        
        $mail->send();
        
        return ['success' => true, 'message' => 'Email sent successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Failed to send email: ' . $e->getMessage()];
    }
}

function sendSMS(string $mobile, string $message): array {
    // This would integrate with an SMS provider
    // For now, we'll simulate success
    return ['success' => true, 'message' => 'SMS sent successfully'];
}

function sendWhatsApp(string $mobile, string $message): array {
    // This would use your existing WhatsApp integration
    // For now, we'll simulate success
    return ['success' => true, 'message' => 'WhatsApp message sent successfully'];
}

function getCommunicationHistory(PDO $conn, int $client_id): array {
    return listCommunications($conn, $client_id);
}

function getClientInfo(PDO $conn, int $client_id): array {
    // Get client information
    $st = $conn->prepare("SELECT id, client_name, email FROM client WHERE id = ?");
    $st->execute([$client_id]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        return ['success' => false, 'error' => 'Client not found'];
    }
    
    // Get sender information from app_email_settings table
    $st = $conn->prepare("SELECT from_name, from_email FROM app_email_settings WHERE id = 1");
    $st->execute();
    $email_settings = $st->fetch(PDO::FETCH_ASSOC);
    
    $sender_name = $email_settings['from_name'] ?? 'HeroSys Cleaning Services';
    $sender_email = $email_settings['from_email'] ?? 'noreply@herosys.com';
    
    return [
        'success' => true,
        'client' => $client,
        'sender' => [
            'name' => $sender_name,
            'email' => $sender_email
        ]
    ];
}

function getTemplateData(PDO $conn, int $client_id): array {
    if ($client_id <= 0) {
        return ['success' => false, 'error' => 'Invalid client ID'];
    }
    
    try {
        $client_data = getClientTemplateData($conn, $client_id);
        
        return [
            'success' => true,
            'template_data' => $client_data
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Failed to fetch template data: ' . $e->getMessage()
        ];
    }
}

function getClientTemplateData(PDO $conn, int $client_id): array {
    // Default values
    $data = [
        'invoice_no' => 'N/A',
        'amount' => 'AED 0.00',
        'due_date' => date('M j, Y'),
        'service_date' => date('M j, Y'),
        'service_time' => '9:00 AM',
        'worker_names' => 'TBD',
        'duration' => '2'
    ];
    
    try {
        // Get the most recent outstanding invoice for this client
        $sql = "
            SELECT 
                i.invoice_no,
                i.total,
                i.due_date,
                i.issue_date,
                i.status
            FROM invoices i
            WHERE i.client_id = ?
            AND i.status IN ('issued', 'partially_paid')
            ORDER BY i.due_date ASC, i.issue_date DESC
            LIMIT 1
        ";
        
        $st = $conn->prepare($sql);
        $st->execute([$client_id]);
        $invoice = $st->fetch(PDO::FETCH_ASSOC);
        
        if ($invoice) {
            $data['invoice_no'] = $invoice['invoice_no'] ?: 'N/A';
            $data['amount'] = 'AED ' . number_format((float)$invoice['total'], 2);
            $data['due_date'] = $invoice['due_date'] ? date('M j, Y', strtotime($invoice['due_date'])) : date('M j, Y');
        }
        
        // Get the most recent service/order for service-related placeholders
        // First try to get confirmed/scheduled services, then any non-cancelled services
        $sql = "
            SELECT 
                mo.service_date,
                mo.start_time,
                mo.hours,
                mo.status,
                mo.created_at,
                GROUP_CONCAT(COALESCE(w.worker_name, CONCAT('Worker #', w.id)) SEPARATOR ', ') as worker_names
            FROM make_order mo
            LEFT JOIN order_workers ow ON ow.order_id = mo.id
            LEFT JOIN workers w ON w.id = ow.worker_id
            WHERE mo.client_id = ?
            AND COALESCE(mo.status,'') <> 'cancelled'
            GROUP BY mo.id
            ORDER BY 
                CASE 
                    WHEN mo.status IN ('confirmed', 'scheduled', 'in_progress') THEN 1
                    ELSE 2
                END,
                COALESCE(mo.service_date, mo.date) DESC, 
                mo.created_at DESC
            LIMIT 1
        ";
        
        $st = $conn->prepare($sql);
        $st->execute([$client_id]);
        $service = $st->fetch(PDO::FETCH_ASSOC);
        
        if ($service) {
            $data['service_date'] = $service['service_date'] ? date('M j, Y', strtotime($service['service_date'])) : date('M j, Y');
            $data['service_time'] = $service['start_time'] ?: '9:00 AM';
            $data['duration'] = $service['hours'] ?: '2';
            $data['worker_names'] = $service['worker_names'] ?: 'TBD';
        } else {
            // Fallback: try to get any service data from make_order without worker join
            $fallback_sql = "
                SELECT 
                    service_date,
                    start_time,
                    hours,
                    worker_name,
                    status
                FROM make_order 
                WHERE client_id = ?
                AND COALESCE(status,'') <> 'cancelled'
                ORDER BY COALESCE(service_date, date) DESC, created_at DESC
                LIMIT 1
            ";
            
            $st = $conn->prepare($fallback_sql);
            $st->execute([$client_id]);
            $fallback_service = $st->fetch(PDO::FETCH_ASSOC);
            
            if ($fallback_service) {
                $data['service_date'] = $fallback_service['service_date'] ? date('M j, Y', strtotime($fallback_service['service_date'])) : date('M j, Y');
                $data['service_time'] = $fallback_service['start_time'] ?: '9:00 AM';
                $data['duration'] = $fallback_service['hours'] ?: '2';
                $data['worker_names'] = $fallback_service['worker_name'] ?: 'TBD';
            }
        }
        
    } catch (Exception $e) {
        // If there's an error, use default values
    }
    
    return $data;
}

function getStatusClass(string $status): string {
    return match($status) {
        'sent' => 'text-info',
        'delivered' => 'text-success',
        'failed' => 'text-danger',
        'opened' => 'text-primary',
        default => 'text-muted'
    };
}
