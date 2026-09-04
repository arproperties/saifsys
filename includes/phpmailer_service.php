<?php
/**
 * PHPMailer Email Service
 * This service uses PHPMailer for reliable email sending
 */

// Include PHPMailer (you'll need to install it via Composer)
// For now, we'll create a simple implementation

class PHPMailerService {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Send email using PHPMailer
     */
    public function sendEmail($to, $subject, $body, $attachments = [], $template_type = null, $variables = []) {
        try {
            // Include email config
            require_once __DIR__ . '/email_config.php';
            
            // Create email message
            $message = $this->buildEmailMessage($to, $subject, $body, $attachments, $template_type, $variables);
            
            // Send via SMTP
            $result = $this->sendViaSMTP($message);
            
            // Log the email
            $this->logEmail($to, $subject, $result['success'], $result['error'] ?? null);
            
            return $result;
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Build email message
     */
    private function buildEmailMessage($to, $subject, $body, $attachments, $template_type, $variables) {
        $message = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'attachments' => $attachments,
            'template_type' => $template_type,
            'variables' => $variables,
            'from_email' => EMAIL_FROM_EMAIL,
            'from_name' => EMAIL_FROM_NAME
        ];
        
        return $message;
    }
    
    /**
     * Send email via SMTP using a more compatible approach
     */
    private function sendViaSMTP($message) {
        try {
            // Create socket connection
            $socket = $this->createSMTPConnection();
            if (!$socket) {
                return ['success' => false, 'error' => 'Could not connect to SMTP server'];
            }
            
            // Send email commands
            $result = $this->sendSMTPCommands($socket, $message);
            
            fclose($socket);
            return $result;
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Create SMTP connection
     */
    private function createSMTPConnection() {
        // Try different connection methods
        $methods = [
            'ssl_stream' => function() {
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ]);
                return stream_socket_client("ssl://" . EMAIL_SMTP_HOST . ":" . EMAIL_SMTP_PORT, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
            },
            'tls_stream' => function() {
                $socket = fsockopen(EMAIL_SMTP_HOST, EMAIL_SMTP_PORT, $errno, $errstr, 30);
                if ($socket) {
                    // Try STARTTLS
                    fputs($socket, "EHLO " . EMAIL_SMTP_HOST . "\r\n");
                    $response = fgets($socket);
                    
                    fputs($socket, "STARTTLS\r\n");
                    $response = fgets($socket);
                    
                    if (substr($response, 0, 3) == '220') {
                        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    }
                }
                return $socket;
            },
            'basic_socket' => function() {
                return fsockopen(EMAIL_SMTP_HOST, EMAIL_SMTP_PORT, $errno, $errstr, 30);
            }
        ];
        
        foreach ($methods as $method_name => $method) {
            try {
                $socket = $method();
                if ($socket) {
                    // Test if connection is working
                    $response = fgets($socket);
                    if (substr($response, 0, 3) == '220') {
                        return $socket;
                    }
                    fclose($socket);
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        return false;
    }
    
    /**
     * Send SMTP commands
     */
    private function sendSMTPCommands($socket, $message) {
        try {
            // EHLO
            fputs($socket, "EHLO " . EMAIL_SMTP_HOST . "\r\n");
            $response = fgets($socket);
            
            // Try different authentication methods
            $auth_methods = ['PLAIN', 'LOGIN', 'CRAM-MD5'];
            $authenticated = false;
            
            foreach ($auth_methods as $method) {
                fputs($socket, "AUTH {$method}\r\n");
                $response = fgets($socket);
                
                if (substr($response, 0, 3) == '334') {
                    // Method supported, try to authenticate
                    if ($method == 'PLAIN') {
                        $auth_string = base64_encode("\0" . EMAIL_SMTP_USERNAME . "\0" . EMAIL_SMTP_PASSWORD);
                        fputs($socket, $auth_string . "\r\n");
                    } elseif ($method == 'LOGIN') {
                        fputs($socket, base64_encode(EMAIL_SMTP_USERNAME) . "\r\n");
                        $response = fgets($socket);
                        fputs($socket, base64_encode(EMAIL_SMTP_PASSWORD) . "\r\n");
                    }
                    
                    $response = fgets($socket);
                    if (substr($response, 0, 3) == '235') {
                        $authenticated = true;
                        break;
                    }
                }
            }
            
            if (!$authenticated) {
                return ['success' => false, 'error' => 'Authentication failed'];
            }
            
            // MAIL FROM
            fputs($socket, "MAIL FROM:<" . EMAIL_FROM_EMAIL . ">\r\n");
            $response = fgets($socket);
            if (substr($response, 0, 3) != '250') {
                return ['success' => false, 'error' => 'MAIL FROM failed: ' . $response];
            }
            
            // RCPT TO
            fputs($socket, "RCPT TO:<" . $message['to'] . ">\r\n");
            $response = fgets($socket);
            if (substr($response, 0, 3) != '250') {
                return ['success' => false, 'error' => 'RCPT TO failed: ' . $response];
            }
            
            // DATA
            fputs($socket, "DATA\r\n");
            $response = fgets($socket);
            if (substr($response, 0, 3) != '354') {
                return ['success' => false, 'error' => 'DATA failed: ' . $response];
            }
            
            // Send email content
            $email_content = $this->buildEmailContent($message);
            fputs($socket, $email_content . "\r\n.\r\n");
            
            $response = fgets($socket);
            if (substr($response, 0, 3) != '250') {
                return ['success' => false, 'error' => 'Email sending failed: ' . $response];
            }
            
            // QUIT
            fputs($socket, "QUIT\r\n");
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Build email content
     */
    private function buildEmailContent($message) {
        $headers = [
            'From: ' . EMAIL_FROM_NAME . ' <' . EMAIL_FROM_EMAIL . '>',
            'To: ' . $message['to'],
            'Subject: ' . $message['subject'],
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ];
        
        $content = implode("\r\n", $headers) . "\r\n\r\n";
        $content .= $message['body'];
        
        return $content;
    }
    
    /**
     * Log email
     */
    private function logEmail($to, $subject, $success, $error = null) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO email_log 
                (recipient_email, subject, status, error_message, sent_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $status = $success ? 'sent' : 'failed';
            $stmt->execute([$to, $subject, $status, $error]);
            
        } catch (Exception $e) {
            // Log error but don't fail the email sending
            error_log("Email logging failed: " . $e->getMessage());
        }
    }
}
?>
