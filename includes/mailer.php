<?php
// includes/mailer.php
require_once __DIR__ . '/phpmailer_bootstrap.php';
herosysgro_ensure_phpmailer();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send SMTP mail using app_email_settings row $s
 * Returns: ['ok'=>bool, 'error'=>string|null]
 */
/**
 * @param array $s app_email_settings row
 * @param array $overrides Optional per-message identity:
 *        from_email / from_name / reply_to. Used for internal mail that should
 *        appear to come from the acting staff member instead of the shared
 *        mailbox. Only honoured when from_email is on the same domain as the
 *        configured sender, otherwise SPF/DKIM alignment breaks and the message
 *        lands in spam. If the SMTP server refuses the sender, the send is
 *        retried once with the configured default so notifications never drop.
 */
function send_smtp_mail(array $s, string $to, string $subject, string $html, array $overrides = []): array {
    if (!class_exists(PHPMailer::class)) {
        return ['ok' => false, 'error' => 'PHPMailer is not available on this server (autoload/manual load failed).'];
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $s['smtp_host'] ?? '';
        $mail->Port       = (int)($s['smtp_port'] ?? 587);
        $secure           = strtolower($s['smtp_secure'] ?? 'tls');

        // Map security -> PHPMailer values
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // for port 465
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // for port 587
        } else {
            $mail->SMTPSecure = false;
        }

        $mail->SMTPAuth   = !empty($s['smtp_username']);
        if ($mail->SMTPAuth) {
            $mail->Username = $s['smtp_username'];
            $mail->Password = $s['smtp_password'] ?? '';
        }

        $mail->CharSet = 'UTF-8';
        $fromEmail = $s['from_email'] ?? '';
        $fromName  = $s['from_name'] ?? '';
        if ($fromEmail === '') {
            // Fallback to username if from_email not provided
            $fromEmail = $s['smtp_username'] ?? 'no-reply@example.com';
        }
        // Per-message sender identity, restricted to the configured sender domain.
        $defaultFromEmail = $fromEmail;
        $defaultFromName  = $fromName;
        $overrideFrom = trim((string)($overrides['from_email'] ?? ''));
        if ($overrideFrom !== '' && smtp_same_mail_domain($overrideFrom, $defaultFromEmail)) {
            $fromEmail = $overrideFrom;
            $fromName  = trim((string)($overrides['from_name'] ?? '')) ?: $overrideFrom;
        }
        $mail->setFrom($fromEmail, $fromName ?: $fromEmail);

        $replyTo = trim((string)($overrides['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo, trim((string)($overrides['from_name'] ?? '')) ?: $replyTo);
        }

        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->msgHTML($html);

        // Enable debug mode if in development or if debug flag is set
        $debugMode = isset($_GET['debug_email']) || (defined('EMAIL_DEBUG') && EMAIL_DEBUG);
        if ($debugMode) {
            $mail->SMTPDebug = 2; // 2 = client+server messages
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer Debug: $str");
            };
        }

        $mail->send();
        return ['ok'=>true, 'error'=>null];
    } catch (\Throwable $e) {
        $errorMsg = $mail->ErrorInfo ?: $e->getMessage();

        // Some providers only let the authenticated mailbox appear in From.
        // Retry once with the configured default sender so the message still lands.
        if (!empty($overrides) && smtp_sender_was_rejected($errorMsg)) {
            error_log("send_smtp_mail: sender override rejected, retrying with default sender. {$errorMsg}");
            $retry = $overrides;
            unset($retry['from_email'], $retry['from_name']);
            if (!empty($retry['reply_to'])) {
                return send_smtp_mail($s, $to, $subject, $html, $retry);
            }
            return send_smtp_mail($s, $to, $subject, $html);
        }

        // Provide more helpful error messages
        if (stripos($errorMsg, 'authenticate') !== false || stripos($errorMsg, 'authentication') !== false) {
            $errorMsg .= " | Please check: 1) SMTP Username (should be full email address), 2) SMTP Password, 3) Port/Encryption combination (587/TLS or 465/SSL)";
        }

        return ['ok'=>false, 'error'=>$errorMsg];
    }
}

/**
 * True when both addresses share a mail domain (case-insensitive).
 */
function smtp_same_mail_domain(string $a, string $b): bool {
    if (!filter_var($a, FILTER_VALIDATE_EMAIL) || !filter_var($b, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $da = strtolower(substr(strrchr($a, '@'), 1));
    $db = strtolower(substr(strrchr($b, '@'), 1));
    return $da !== '' && $da === $db;
}

/**
 * Recognise the SMTP refusals that mean "you may not send as this address".
 */
function smtp_sender_was_rejected(string $error): bool {
    $needles = ['sender address', 'not allowed', 'not permitted', 'sender rejected',
                'from address', 'must be', 'does not match', '553', '550 5.7.1'];
    $error = strtolower($error);
    foreach ($needles as $needle) {
        if (strpos($error, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Send SMTP mail with optional file attachments (same app_email_settings as send_smtp_mail).
 *
 * @param array $s SMTP settings row
 * @param string $to Recipient
 * @param string $subject Subject
 * @param string $html HTML body
 * @param array<int, array{path:string,name?:string}> $attachments Absolute paths
 * @return array{ok:bool,error:?string}
 */
function send_smtp_mail_with_attachments(array $s, string $to, string $subject, string $html, array $attachments = []): array {
    if (!class_exists(PHPMailer::class)) {
        return ['ok' => false, 'error' => 'PHPMailer is not available on this server (autoload/manual load failed).'];
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $s['smtp_host'] ?? '';
        $mail->Port = (int)($s['smtp_port'] ?? 587);
        $secure = strtolower($s['smtp_secure'] ?? 'tls');
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
        }
        $mail->SMTPAuth = !empty($s['smtp_username']);
        if ($mail->SMTPAuth) {
            $mail->Username = $s['smtp_username'];
            $mail->Password = $s['smtp_password'] ?? '';
        }
        $mail->CharSet = 'UTF-8';
        $fromEmail = $s['from_email'] ?? '';
        $fromName = $s['from_name'] ?? '';
        if ($fromEmail === '') {
            $fromEmail = $s['smtp_username'] ?? 'no-reply@example.com';
        }
        $mail->setFrom($fromEmail, $fromName ?: $fromEmail);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->msgHTML($html);
        foreach ($attachments as $att) {
            $path = (string)($att['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                continue;
            }
            $mail->addAttachment($path, (string)($att['name'] ?? basename($path)));
        }
        $mail->send();
        return ['ok' => true, 'error' => null];
    } catch (\Throwable $e) {
        $errorMsg = $mail->ErrorInfo ?: $e->getMessage();
        return ['ok' => false, 'error' => $errorMsg];
    }
}

/**
 * Simple placeholder replacement in the template body & subject.
 * Accepts placeholders like {employee_name}, {doc_type}, etc.
 */
function render_template(string $tpl, array $vars): string {
    return preg_replace_callback('/\{([a-z0-9_]+)\}/i', function($m) use ($vars) {
        $key = $m[1];
        return isset($vars[$key]) ? (string)$vars[$key] : $m[0]; // leave unknown tokens as-is
    }, $tpl);
}

/**
 * Send notification email to owner emails configured in app_email_settings
 * Returns: ['ok'=>bool, 'error'=>string|null, 'sent_count'=>int]
 */
function send_notification_to_owners(PDO $conn, string $subject, string $html): array {
    try {
        // Get email settings
        $stmt = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
        $stmt->execute();
        $emailSettings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$emailSettings) {
            return ['ok' => false, 'error' => 'Email notifications are not enabled', 'sent_count' => 0];
        }
        
        // Get owner emails
        $ownerEmails = trim($emailSettings['owner_emails'] ?? '');
        if (empty($ownerEmails)) {
            return ['ok' => false, 'error' => 'No owner emails configured', 'sent_count' => 0];
        }
        
        // Parse comma-separated emails
        $emails = array_map('trim', explode(',', $ownerEmails));
        $emails = array_filter($emails, function($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        });
        
        if (empty($emails)) {
            return ['ok' => false, 'error' => 'No valid owner email addresses found', 'sent_count' => 0];
        }
        
        // Send to each owner email
        $sentCount = 0;
        $errors = [];
        
        foreach ($emails as $email) {
            $result = send_smtp_mail($emailSettings, $email, $subject, $html);
            if ($result['ok']) {
                $sentCount++;
            } else {
                $errors[] = "Failed to send to {$email}: " . ($result['error'] ?? 'Unknown error');
            }
        }
        
        if ($sentCount > 0) {
            return [
                'ok' => true,
                'error' => empty($errors) ? null : implode('; ', $errors),
                'sent_count' => $sentCount
            ];
        } else {
            return [
                'ok' => false,
                'error' => implode('; ', $errors),
                'sent_count' => 0
            ];
        }
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'sent_count' => 0];
    }
}

/**
 * Send notification email to owners asynchronously (non-blocking)
 * This function sends emails in the background without blocking the response
 */
function send_notification_to_owners_async(PDO $conn, string $subject, string $html): void {
    // Use register_shutdown_function to send email after response is sent
    register_shutdown_function(function() use ($conn, $subject, $html) {
        // Ignore user abort so email sending continues even if user closes connection
        ignore_user_abort(true);
        // Set longer execution time for email sending
        set_time_limit(30);
        
        try {
            send_notification_to_owners($conn, $subject, $html);
        } catch (Exception $e) {
            // Log error but don't throw (silent failure for background process)
            error_log("Failed to send notification email: " . $e->getMessage());
        }
    });
    
    // If fastcgi_finish_request is available, flush response immediately
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

/**
 * Send notification email to an employee asynchronously (non-blocking)
 * This function sends emails in the background without blocking the response
 * 
 * @param PDO $conn Database connection
 * @param string $employeeEmail Employee's email address
 * @param string $subject Email subject
 * @param string $html Email HTML content
 */
function send_notification_to_employee_async(PDO $conn, string $employeeEmail, string $subject, string $html): void {
    // Validate email
    if (empty($employeeEmail) || !filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid employee email address: " . $employeeEmail);
        return;
    }
    
    // Use register_shutdown_function to send email after response is sent
    register_shutdown_function(function() use ($conn, $employeeEmail, $subject, $html) {
        // Ignore user abort so email sending continues even if user closes connection
        ignore_user_abort(true);
        // Set longer execution time for email sending
        set_time_limit(30);
        
        try {
            // Get email settings
            $stmt = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
            $stmt->execute();
            $emailSettings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$emailSettings) {
                error_log("Email notifications are not enabled or email settings not found");
                return;
            }
            
            // Send email to employee
            $result = send_smtp_mail($emailSettings, $employeeEmail, $subject, $html);
            if (!$result['ok']) {
                error_log("Failed to send email to employee {$employeeEmail}: " . ($result['error'] ?? 'Unknown error'));
            }
        } catch (Exception $e) {
            // Log error but don't throw (silent failure for background process)
            error_log("Failed to send notification email to employee: " . $e->getMessage());
        }
    });
    
    // If fastcgi_finish_request is available, flush response immediately
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}