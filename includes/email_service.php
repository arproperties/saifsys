<?php
// includes/email_service.php
// Email service with templates, variable substitution, and attachment support

require_once __DIR__ . '/db_connect.php';

class EmailService {
    private $conn;
    private $smtp_host;
    private $smtp_port;
    private $smtp_username;
    private $smtp_password;
    private $smtp_encryption;
    private $from_email;
    private $from_name;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
        
        // Load email settings from database or config
        $this->loadEmailSettings();
    }

    private function loadEmailSettings() {
        // Default settings - in production, these should come from a settings table
        $this->smtp_host = 'localhost';
        $this->smtp_port = 587;
        $this->smtp_username = '';
        $this->smtp_password = '';
        $this->smtp_encryption = 'tls';
        $this->from_email = 'noreply@bmsystem.com';
        $this->from_name = 'BMSystem';
    }

    /**
     * Send email using template
     */
    public function sendTemplateEmail($templateId, $recipientEmail, $variables = [], $attachments = []) {
        try {
            // Get template
            $template = $this->getTemplate($templateId);
            if (!$template) {
                throw new Exception('Email template not found');
            }

            // Replace variables in subject and body
            $subject = $this->replaceVariables($template['subject'], $variables);
            $bodyHtml = $this->replaceVariables($template['body_html'], $variables);
            $bodyPlain = $this->replaceVariables($template['body_plain'], $variables);

            // Log email attempt
            $logId = $this->logEmail($recipientEmail, $subject, $templateId, $variables);

            // Send email
            $result = $this->sendEmail($recipientEmail, $subject, $bodyHtml, $bodyPlain, $attachments);

            // Update log
            $this->updateEmailLog($logId, $result['success'] ? 'sent' : 'failed', $result['error'] ?? null);

            return $result;

        } catch (Exception $e) {
            error_log('EmailService Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send custom email without template
     */
    public function sendCustomEmail($recipientEmail, $subject, $bodyHtml, $bodyPlain = '', $attachments = []) {
        try {
            // Log email attempt
            $logId = $this->logEmail($recipientEmail, $subject, null, []);

            // Send email
            $result = $this->sendEmail($recipientEmail, $subject, $bodyHtml, $bodyPlain, $attachments);

            // Update log
            $this->updateEmailLog($logId, $result['success'] ? 'sent' : 'failed', $result['error'] ?? null);

            return $result;

        } catch (Exception $e) {
            error_log('EmailService Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send invoice email
     */
    public function sendInvoiceEmail($invoiceId, $recipientEmail = null, $templateId = null) {
        try {
            // Get invoice data
            $invoice = $this->getInvoiceData($invoiceId);
            if (!$invoice) {
                throw new Exception('Invoice not found');
            }

            // Use recipient email from invoice if not provided
            if (!$recipientEmail) {
                $recipientEmail = $invoice['client_email'];
            }

            if (!$recipientEmail) {
                throw new Exception('No recipient email address');
            }

            // Use default invoice template if not specified
            if (!$templateId) {
                $templateId = $this->getDefaultTemplateId('invoice');
            }

            // Prepare variables
            $variables = $this->prepareInvoiceVariables($invoice);

            // Generate PDF attachment
            $attachments = [];
            $pdfPath = $this->generateInvoicePDF($invoiceId);
            if ($pdfPath) {
                $attachments[] = [
                    'path' => $pdfPath,
                    'name' => 'Invoice_' . $invoice['invoice_no'] . '.pdf',
                    'type' => 'application/pdf'
                ];
            }

            // Send email
            return $this->sendTemplateEmail($templateId, $recipientEmail, $variables, $attachments);

        } catch (Exception $e) {
            error_log('Invoice Email Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send statement email
     */
    public function sendStatementEmail($clientId, $fromDate, $toDate, $recipientEmail = null, $templateId = null) {
        try {
            // Get client data
            $client = $this->getClientData($clientId);
            if (!$client) {
                throw new Exception('Client not found');
            }

            // Use recipient email from client if not provided
            if (!$recipientEmail) {
                $recipientEmail = $client['email'];
            }

            if (!$recipientEmail) {
                throw new Exception('No recipient email address');
            }

            // Use default statement template if not specified
            if (!$templateId) {
                $templateId = $this->getDefaultTemplateId('statement');
            }

            // Prepare variables
            $variables = $this->prepareStatementVariables($client, $fromDate, $toDate);

            // Generate statement PDF attachment
            $attachments = [];
            $pdfPath = $this->generateStatementPDF($clientId, $fromDate, $toDate);
            if ($pdfPath) {
                $attachments[] = [
                    'path' => $pdfPath,
                    'name' => 'Statement_' . $client['client_name'] . '_' . $fromDate . '_to_' . $toDate . '.pdf',
                    'type' => 'application/pdf'
                ];
            }

            // Send email
            return $this->sendTemplateEmail($templateId, $recipientEmail, $variables, $attachments);

        } catch (Exception $e) {
            error_log('Statement Email Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get email template by ID
     */
    private function getTemplate($templateId) {
        $stmt = $this->conn->prepare("SELECT * FROM email_templates WHERE id = ? AND is_active = 1");
        $stmt->execute([$templateId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get default template ID by type
     */
    private function getDefaultTemplateId($type) {
        $stmt = $this->conn->prepare("SELECT id FROM email_templates WHERE template_type = ? AND is_active = 1 ORDER BY id LIMIT 1");
        $stmt->execute([$type]);
        return $stmt->fetchColumn();
    }

    /**
     * Replace variables in text
     */
    private function replaceVariables($text, $variables) {
        foreach ($variables as $key => $value) {
            // Convert null to empty string to avoid deprecated warnings
            $value = $value ?? '';
            $text = str_replace('{{' . $key . '}}', (string)$value, $text);
        }
        return $text;
    }

    /**
     * Log email attempt
     */
    private function logEmail($recipientEmail, $subject, $templateId, $variables) {
        $stmt = $this->conn->prepare("
            INSERT INTO email_log (recipient_email, subject, template_id, entity_type, entity_id, status, created_by)
            VALUES (?, ?, ?, ?, ?, 'pending', ?)
        ");
        
        $entityType = $variables['entity_type'] ?? null;
        $entityId = $variables['entity_id'] ?? null;
        $userId = $_SESSION['user_id'] ?? null;
        
        $stmt->execute([$recipientEmail, $subject, $templateId, $entityType, $entityId, $userId]);
        return $this->conn->lastInsertId();
    }

    /**
     * Update email log
     */
    private function updateEmailLog($logId, $status, $errorMessage = null) {
        $stmt = $this->conn->prepare("
            UPDATE email_log 
            SET status = ?, sent_at = ?, error_message = ?
            WHERE id = ?
        ");
        $sentAt = $status === 'sent' ? date('Y-m-d H:i:s') : null;
        $stmt->execute([$status, $sentAt, $errorMessage, $logId]);
    }

    /**
     * Send email using PHP's mail function (can be extended to use PHPMailer)
     */
    private function sendEmail($recipientEmail, $subject, $bodyHtml, $bodyPlain, $attachments = []) {
        try {
            // Try PHPMailer first (like the clients page) - this is the modern approach
            $result = $this->sendViaPHPMailer($recipientEmail, $subject, $bodyHtml, $bodyPlain, $attachments);
            
            // If PHPMailer fails, try custom SMTP
            if (!$result['success']) {
                // Build MIME message for fallback
                $headers = [
                    'From: ' . $this->from_name . ' <' . $this->from_email . '>',
                    'Reply-To: ' . $this->from_email,
                    'MIME-Version: 1.0',
                    'Content-Type: multipart/mixed; boundary="boundary123"'
                ];

                $message = "--boundary123\r\n";
                $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
                $message .= $bodyPlain . "\r\n\r\n";
                
                $message .= "--boundary123\r\n";
                $message .= "Content-Type: text/html; charset=UTF-8\r\n";
                $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
                $message .= $bodyHtml . "\r\n\r\n";

                // Add attachments
                foreach ($attachments as $attachment) {
                    // Handle both string and array formats
                    if (is_string($attachment)) {
                        $attachment = [
                            'path' => $attachment,
                            'name' => basename($attachment),
                            'type' => 'application/octet-stream'
                        ];
                    }
                    
                    if (is_array($attachment) && isset($attachment['path']) && file_exists($attachment['path'])) {
                        $fileContent = file_get_contents($attachment['path']);
                        $fileContent = chunk_split(base64_encode($fileContent));
                        
                        $message .= "--boundary123\r\n";
                        $message .= "Content-Type: " . ($attachment['type'] ?? 'application/octet-stream') . "; name=\"" . ($attachment['name'] ?? basename($attachment['path'])) . "\"\r\n";
                        $message .= "Content-Transfer-Encoding: base64\r\n";
                        $message .= "Content-Disposition: attachment; filename=\"" . ($attachment['name'] ?? basename($attachment['path'])) . "\"\r\n\r\n";
                        $message .= $fileContent . "\r\n\r\n";
                    }
                }

                $message .= "--boundary123--\r\n";
                
                $result = $this->sendViaSMTP($recipientEmail, $subject, $message, $headers);
            }
            
            // If both fail, try PHP's mail() function as final fallback
            if (!$result['success']) {
                // Already have the MIME message built above
                if (!isset($message)) {
                    // Build MIME message if not already built
                    $headers = [
                        'From: ' . $this->from_name . ' <' . $this->from_email . '>',
                        'Reply-To: ' . $this->from_email,
                        'MIME-Version: 1.0',
                        'Content-Type: multipart/mixed; boundary="boundary123"'
                    ];

                    $message = "--boundary123\r\n";
                    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
                    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
                    $message .= $bodyPlain . "\r\n\r\n";
                    
                    $message .= "--boundary123\r\n";
                    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
                    $message .= $bodyHtml . "\r\n\r\n";

                    foreach ($attachments as $attachment) {
                        if (is_string($attachment)) {
                            $attachment = [
                                'path' => $attachment,
                                'name' => basename($attachment),
                                'type' => 'application/octet-stream'
                            ];
                        }
                        
                        if (is_array($attachment) && isset($attachment['path']) && file_exists($attachment['path'])) {
                            $fileContent = file_get_contents($attachment['path']);
                            $fileContent = chunk_split(base64_encode($fileContent));
                            
                            $message .= "--boundary123\r\n";
                            $message .= "Content-Type: " . ($attachment['type'] ?? 'application/octet-stream') . "; name=\"" . ($attachment['name'] ?? basename($attachment['path'])) . "\"\r\n";
                            $message .= "Content-Transfer-Encoding: base64\r\n";
                            $message .= "Content-Disposition: attachment; filename=\"" . ($attachment['name'] ?? basename($attachment['path'])) . "\"\r\n\r\n";
                            $message .= $fileContent . "\r\n\r\n";
                        }
                    }

                    $message .= "--boundary123--\r\n";
                }
                
                $result = $this->sendViaMailFunction($recipientEmail, $subject, $message, $headers);
            }

            return $result;

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send email via SMTP
     */
    private function sendViaSMTP($recipientEmail, $subject, $message, $headers) {
        try {
            // Include email config
            require_once __DIR__ . '/email_config.php';
            
            // Create SMTP connection
            $smtpHost = EMAIL_SMTP_HOST;
            $smtpPort = EMAIL_SMTP_PORT;
            $smtpUsername = EMAIL_SMTP_USERNAME;
            $smtpPassword = EMAIL_SMTP_PASSWORD;
            $smtpEncryption = EMAIL_SMTP_ENCRYPTION;
            
            // Create socket connection
            if ($smtpEncryption === 'ssl') {
                // For SSL (port 465), use ssl:// prefix
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ]);
                $socket = stream_socket_client("ssl://{$smtpHost}:{$smtpPort}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
            } else {
                $socket = fsockopen($smtpHost, $smtpPort, $errno, $errstr, 30);
            }
            
            if (!$socket) {
                return ['success' => false, 'error' => "Could not connect to SMTP server: $errstr ($errno)"];
            }
            
            // Read initial response
            $response = fgets($socket);
            if (substr($response, 0, 3) != '220') {
                fclose($socket);
                return ['success' => false, 'error' => "SMTP server error: $response"];
            }
            
            // EHLO command
            fputs($socket, "EHLO " . EMAIL_SMTP_HOST . "\r\n");
            $response = fgets($socket);
            
            // STARTTLS if needed (only for TLS, not SSL)
            if ($smtpEncryption === 'tls') {
                fputs($socket, "STARTTLS\r\n");
                $response = fgets($socket);
                if (substr($response, 0, 3) != '220') {
                    fclose($socket);
                    return ['success' => false, 'error' => "STARTTLS failed: $response"];
                }
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                
                // EHLO again after TLS
                fputs($socket, "EHLO " . EMAIL_SMTP_HOST . "\r\n");
                $response = fgets($socket);
            }
            
            // Authentication - try PLAIN method first (common for SSL)
            fputs($socket, "AUTH PLAIN\r\n");
            $response = fgets($socket);
            
            if (substr($response, 0, 3) == '334') {
                // PLAIN method supported
                $auth_string = base64_encode("\0" . $smtpUsername . "\0" . $smtpPassword);
                fputs($socket, $auth_string . "\r\n");
                $response = fgets($socket);
                if (substr($response, 0, 3) != '235') {
                    fclose($socket);
                    return ['success' => false, 'error' => "PLAIN authentication failed: $response"];
                }
            } else {
                // Try LOGIN method as fallback
                fputs($socket, "AUTH LOGIN\r\n");
                $response = fgets($socket);
                if (substr($response, 0, 3) != '334') {
                    fclose($socket);
                    return ['success' => false, 'error' => "AUTH LOGIN not supported: $response"];
                }
                
                fputs($socket, base64_encode($smtpUsername) . "\r\n");
                $response = fgets($socket);
                if (substr($response, 0, 3) != '334') {
                    fclose($socket);
                    return ['success' => false, 'error' => "Username authentication failed: $response"];
                }
                
                fputs($socket, base64_encode($smtpPassword) . "\r\n");
                $response = fgets($socket);
                if (substr($response, 0, 3) != '235') {
                    fclose($socket);
                    return ['success' => false, 'error' => "Password authentication failed: $response"];
                }
            }
            
            // MAIL FROM
            fputs($socket, "MAIL FROM:<" . EMAIL_FROM_EMAIL . ">\r\n");
            $response = fgets($socket);
            if (substr($response, 0, 3) != '250') {
                fclose($socket);
                return ['success' => false, 'error' => "MAIL FROM failed: $response"];
            }
            
            // RCPT TO
            fputs($socket, "RCPT TO:<$recipientEmail>\r\n");
            $response = fgets($socket);
            if (substr($response, 0, 3) != '250') {
                fclose($socket);
                return ['success' => false, 'error' => "RCPT TO failed: $response"];
            }
            
            // DATA
            fputs($socket, "DATA\r\n");
            $response = fgets($socket);
            if (substr($response, 0, 3) != '354') {
                fclose($socket);
                return ['success' => false, 'error' => "DATA command failed: $response"];
            }
            
            // Send headers and message
            fputs($socket, implode("\r\n", $headers) . "\r\n\r\n");
            fputs($socket, $message . "\r\n.\r\n");
            
            $response = fgets($socket);
            if (substr($response, 0, 3) != '250') {
                fclose($socket);
                return ['success' => false, 'error' => "Message send failed: $response"];
            }
            
            // QUIT
            fputs($socket, "QUIT\r\n");
            fclose($socket);
            
            return ['success' => true, 'error' => null];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'SMTP Error: ' . $e->getMessage()];
        }
    }

    /**
     * Get invoice data for email
     */
    private function getInvoiceData($invoiceId) {
        $stmt = $this->conn->prepare("
            SELECT i.*, c.client_name, c.email as client_email, c.address as client_address, c.mobile_num as client_phone
            FROM invoices i
            LEFT JOIN client c ON c.id = i.client_id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoiceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get client data for email
     */
    private function getClientData($clientId) {
        $stmt = $this->conn->prepare("
            SELECT * FROM client WHERE id = ?
        ");
        $stmt->execute([$clientId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Prepare invoice variables for template
     */
    private function prepareInvoiceVariables($invoice) {
        $company = $this->getCompanySettings();
        
        return [
            'invoice_no' => $invoice['invoice_no'],
            'client_name' => $invoice['client_name'],
            'client_email' => $invoice['client_email'],
            'client_address' => $invoice['client_address'],
            'client_phone' => $invoice['client_phone'],
            'issue_date' => $invoice['issue_date'],
            'due_date' => $invoice['due_date'],
            'total' => number_format($invoice['total'], 2),
            'subtotal' => number_format($invoice['subtotal'], 2),
            'vat_amount' => number_format($invoice['vat_amount'], 2),
            'discount_amount' => number_format($invoice['discount_amount'], 2),
            'status' => ucfirst(str_replace('_', ' ', $invoice['status'])),
            'notes' => $invoice['notes'],
            'company_name' => $company['company_name'],
            'company_address' => $company['company_address'],
            'company_phone' => $company['company_phone'],
            'company_email' => $company['company_email'],
            'entity_type' => 'invoice',
            'entity_id' => $invoice['id']
        ];
    }

    /**
     * Prepare statement variables for template
     */
    private function prepareStatementVariables($client, $fromDate, $toDate) {
        $company = $this->getCompanySettings();
        
        // Get current balance
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(i.total - COALESCE(pa.amount_paid, 0)), 0) as current_balance
            FROM invoices i
            LEFT JOIN (
                SELECT ra.invoice_id, SUM(ra.amount_applied) as amount_paid
                FROM receipt_allocations ra
                GROUP BY ra.invoice_id
            ) pa ON pa.invoice_id = i.id
            WHERE i.client_id = ? AND i.status IN ('issued', 'partially_paid')
        ");
        $stmt->execute([$client['id']]);
        $currentBalance = $stmt->fetchColumn();
        
        return [
            'client_name' => $client['client_name'],
            'client_email' => $client['email'],
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'current_balance' => number_format($currentBalance, 2),
            'company_name' => $company['company_name'],
            'company_address' => $company['company_address'],
            'company_phone' => $company['company_phone'],
            'company_email' => $company['company_email'],
            'entity_type' => 'statement',
            'entity_id' => $client['id']
        ];
    }

    /**
     * Get company settings
     */
    private function getCompanySettings() {
        // This should come from a settings table in production
        return [
            'company_name' => 'BMSystem',
            'company_address' => '123 Business Street, Dubai, UAE',
            'company_phone' => '+971 4 123 4567',
            'company_email' => 'info@bmsystem.com'
        ];
    }

    /**
     * Generate invoice PDF
     */
    private function generateInvoicePDF($invoiceId) {
        try {
            // Get invoice data
            $invoice = $this->getInvoiceData($invoiceId);
            if (!$invoice) {
                throw new Exception('Invoice not found');
            }
            
            // Load Dompdf
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (!file_exists($autoload)) {
                error_log('Composer autoload not found - PDF generation disabled');
                return false;
            }
            
            require_once $autoload;
            if (!class_exists('\Dompdf\Dompdf')) {
                error_log('Dompdf not available - PDF generation disabled');
                return false;
            }
            
            // Get invoice HTML content using a separate endpoint
            $pdfContent = $this->getInvoiceHTML($invoiceId);
            if (!$pdfContent) {
                error_log("Failed to get invoice HTML for invoice {$invoiceId}");
                return false;
            }
            
            // Create temporary directory if needed
            $tmpDir = __DIR__ . '/../storage/tmp';
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0777, true);
            }
            
            // Configure Dompdf
            $options = new \Dompdf\Options();
            $options->setChroot(realpath(__DIR__ . '/../'));
            $options->setIsRemoteEnabled(true);
            $options->setTempDir($tmpDir);
            $options->setFontCache($tmpDir);
            
            // Generate PDF
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($pdfContent);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            
            // Save PDF to file
            $pdfPath = $tmpDir . '/invoice_' . $invoiceId . '_' . time() . '.pdf';
            file_put_contents($pdfPath, $dompdf->output());
            
            return $pdfPath;
            
        } catch (Exception $e) {
            error_log('PDF Generation Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get invoice HTML content for PDF generation
     * Uses the professional invoice_print_pdf.php design
     */
    private function getInvoiceHTML($invoiceId) {
        // Save current $_GET
        $originalGet = $_GET;
        
        try {
            // Set invoice ID
            $_GET['id'] = $invoiceId;
            
            // Make $conn available globally for the included file using $GLOBALS
            $GLOBALS['conn'] = $this->conn;
            
            // Capture output from invoice_print_pdf.php using output buffering
            ob_start();
            
            try {
                // Include the clean PDF version (no auth checks)
                include __DIR__ . '/../accounts/invoice_print_pdf.php';
                
            } catch (Exception $e) {
                ob_end_clean();
                error_log('Error including invoice_print_pdf.php: ' . $e->getMessage());
                throw $e;
            }
            
            // Get the captured HTML
            $htmlContent = ob_get_clean();
            
            // Restore original $_GET and clean up global
            $_GET = $originalGet;
            unset($GLOBALS['conn']);
            
            if (!$htmlContent || strlen($htmlContent) < 100) {
                error_log("Invoice HTML content is too short or empty for invoice {$invoiceId}");
                return $this->generateSimpleInvoiceHTML($invoiceId);
            }
            
            return $htmlContent;
            
        } catch (Exception $e) {
            // Restore on error
            $_GET = $originalGet ?? [];
            unset($GLOBALS['conn']);
            error_log('getInvoiceHTML Error: ' . $e->getMessage());
            return $this->generateSimpleInvoiceHTML($invoiceId);
        }
    }
    
    /**
     * Fallback: Generate simple invoice HTML if invoice_print.php fails
     */
    private function generateSimpleInvoiceHTML($invoiceId) {
        try {
            // Load invoice data
            require_once __DIR__ . '/db_connect.php';
            require_once __DIR__ . '/ar_helpers.php';
            
            $invoice = ar_get_invoice($this->conn, $invoiceId);
            if (!$invoice) {
                return false;
            }
            
            $items = ar_get_invoice_items($this->conn, $invoiceId);
            $payments = ar_get_invoice_payments($this->conn, $invoiceId);
            
            // Get company settings
            $stmt = $this->conn->prepare("SELECT * FROM company_settings LIMIT 1");
            $stmt->execute();
            $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
            // Generate simple HTML invoice
            $html = $this->generateInvoiceHTMLContent($invoice, $items, $payments, $company);
            return $html;
            
        } catch (Exception $e) {
            error_log('generateSimpleInvoiceHTML Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate invoice HTML content
     */
    private function generateInvoiceHTMLContent($invoice, $items, $payments, $company) {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 20px; }
            .company-info { text-align: center; margin-bottom: 30px; }
            .invoice-info { margin-bottom: 20px; }
            .invoice-info table { width: 100%; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
            th { background-color: #f2f2f2; }
            .text-right { text-align: right; }
            .totals { margin-top: 30px; }
            .total-row { font-weight: bold; font-size: 18px; }
        </style></head><body>';
        
        $html .= '<div class="header">';
        $html .= '<h1>' . ($company['legal_name'] ?? 'TAX INVOICE') . '</h1>';
        $html .= '<div>' . ($company['address_line1'] ?? '') . ', ' . ($company['city'] ?? '') . '</div>';
        $html .= '<div>TRN: ' . ($company['trn'] ?? '') . '</div>';
        $html .= '</div>';
        
        $html .= '<div class="invoice-info">
            <h2>Invoice: ' . htmlspecialchars($invoice['invoice_no']) . '</h2>
            <table>
                <tr><td><strong>Client:</strong></td><td>' . htmlspecialchars($invoice['client_name'] ?? '') . '</td></tr>
                <tr><td><strong>Date:</strong></td><td>' . date('Y-m-d', strtotime($invoice['issue_date'])) . '</td></tr>
                <tr><td><strong>Due Date:</strong></td><td>' . date('Y-m-d', strtotime($invoice['due_date'])) . '</td></tr>
            </table>
        </div>';
        
        $html .= '<h3>Line Items</h3><table>';
        $html .= '<tr><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th></tr>';
        foreach ($items as $item) {
            $html .= '<tr>
                <td>' . htmlspecialchars($item['description']) . '</td>
                <td>' . $item['qty'] . ' ' . ($item['unit'] ?? '') . '</td>
                <td>' . number_format($item['unit_price'], 2) . '</td>
                <td class="text-right">' . number_format($item['line_total'], 2) . '</td>
            </tr>';
        }
        $html .= '</table>';
        
        $html .= '<div class="totals">
            <table>
                <tr><td>Subtotal:</td><td class="text-right">' . number_format($invoice['subtotal'], 2) . ' AED</td></tr>
                <tr><td>Discount:</td><td class="text-right">' . number_format($invoice['discount_amount'], 2) . ' AED</td></tr>
                <tr><td>VAT (' . $invoice['vat_rate'] . '%):</td><td class="text-right">' . number_format($invoice['vat_amount'], 2) . ' AED</td></tr>
                <tr class="total-row"><td>Total:</td><td class="text-right">' . number_format($invoice['total'], 2) . ' AED</td></tr>
            </table>
        </div>';
        
        $html .= '</body></html>';
        return $html;
    }

    /**
     * Generate statement PDF
     */
    private function generateStatementPDF($clientId, $fromDate, $toDate) {
        try {
            // Use existing statements_export.php to generate PDF
            $pdfPath = __DIR__ . '/../storage/tmp/statement_' . $clientId . '_' . time() . '.pdf';
            
            // Capture output from statements_export.php
            ob_start();
            $_GET['client_id'] = $clientId;
            $_GET['from'] = $fromDate;
            $_GET['to'] = $toDate;
            include __DIR__ . '/../accounts/statements_export.php';
            $pdfContent = ob_get_clean();
            
            // Save PDF content to file
            file_put_contents($pdfPath, $pdfContent);
            
            return $pdfPath;
        } catch (Exception $e) {
            error_log('PDF Generation Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get email templates
     */
    public function getTemplates($type = null) {
        $sql = "SELECT * FROM email_templates WHERE is_active = 1";
        $params = [];
        
        if ($type) {
            $sql .= " AND template_type = ?";
            $params[] = $type;
        }
        
        $sql .= " ORDER BY template_name";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get email log
     */
    public function getEmailLog($limit = 50, $offset = 0) {
        $stmt = $this->conn->prepare("
            SELECT el.*, et.template_name, u.username
            FROM email_log el
            LEFT JOIN email_templates et ON et.id = el.template_id
            LEFT JOIN user u ON u.id = el.created_by
            ORDER BY el.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Clean up old temporary files
     */
    public function cleanupTempFiles() {
        $tempDir = __DIR__ . '/../storage/tmp/';
        $files = glob($tempDir . '*');
        $now = time();
        
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > 3600) { // 1 hour
                unlink($file);
            }
        }
    }
    
    /**
     * Send email via PHP's mail() function (fallback)
     */
    private function sendViaMailFunction($recipientEmail, $subject, $message, $headers) {
        try {
            // Include email config
            require_once __DIR__ . '/email_config.php';
            
            // Set additional headers for mail() function
            $mail_headers = implode("\r\n", $headers);
            $mail_headers .= "\r\nFrom: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM_EMAIL . ">";
            $mail_headers .= "\r\nReply-To: " . EMAIL_FROM_EMAIL;
            $mail_headers .= "\r\nX-Mailer: PHP/" . phpversion();
            
            // Send email
            $result = mail($recipientEmail, $subject, $message, $mail_headers);
            
            return [
                'success' => $result,
                'error' => $result ? null : 'PHP mail() function failed'
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send email using PHPMailer (like the clients page)
     */
    private function sendViaPHPMailer($recipientEmail, $subject, $bodyHtml, $bodyPlain, $attachments = []) {
        try {
            // Get email settings from app_email_settings table (like the clients page)
            $st = $this->conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
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
            $mail->addAddress($recipientEmail);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $bodyHtml;
            $mail->AltBody = $bodyPlain;
            
            // Add custom headers for better Gmail compatibility
            $mail->addCustomHeader('X-Priority', '1');
            $mail->addCustomHeader('Importance', 'High');
            $mail->addCustomHeader('X-Mailer', 'BMSystem Email Service');
            
            // Add attachments
            if (!empty($attachments)) {
                foreach ($attachments as $attachment) {
                    if (is_string($attachment)) {
                        $attachment = [
                            'path' => $attachment,
                            'name' => basename($attachment),
                            'type' => 'application/pdf'
                        ];
                    }
                    
                    if (is_array($attachment) && isset($attachment['path']) && file_exists($attachment['path'])) {
                        // Verify file is not empty
                        $fileSize = filesize($attachment['path']);
                        if ($fileSize > 0) {
                            $mail->addAttachment(
                                $attachment['path'],
                                $attachment['name'] ?? basename($attachment['path'])
                            );
                        }
                    }
                }
            }
            
            $mail->send();
            
            return ['success' => true, 'message' => 'Email sent successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to send email: ' . $e->getMessage()];
        }
    }
}
