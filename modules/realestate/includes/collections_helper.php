<?php
/**
 * Collections Helper Functions
 * Automated alert sending and tracking
 */

require_once __DIR__ . '/re_email_helper.php';

if (!function_exists('send_overdue_rent_alert')) {
    /**
     * Send overdue rent alert
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param int $leaseId Lease ID
     * @param int|null $installmentId Installment ID
     * @param int|null $billingItemId Billing item ID
     * @param int|null $invoiceId Invoice ID
     * @param string $dueDate Due date
     * @param float $amount Amount overdue
     * @param int $daysOverdue Days overdue
     * @return array Result
     */
    function send_overdue_rent_alert(
        PDO $conn,
        int $companyId,
        int $leaseId,
        ?int $installmentId,
        ?int $billingItemId,
        ?int $invoiceId,
        string $dueDate,
        float $amount,
        int $daysOverdue
    ): array {
        // Check if alert config is enabled
        $config = $conn->prepare("
            SELECT * FROM re_collections_alerts_config
            WHERE company_id = ? AND alert_type = 'overdue_rent' AND is_enabled = 1
        ");
        $config->execute([$companyId]);
        $config = $config->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            return ['success' => false, 'message' => 'Overdue rent alerts are disabled'];
        }
        
        // Check threshold
        if ($daysOverdue < $config['days_overdue_threshold']) {
            return ['success' => false, 'message' => 'Days overdue below threshold'];
        }
        
        // Check if alert already sent today (for daily frequency)
        if ($config['alert_frequency'] === 'daily') {
            $todayCheck = $conn->prepare("
                SELECT id FROM re_overdue_rent_alerts
                WHERE company_id = ? 
                AND lease_id = ?
                AND (installment_id = ? OR billing_item_id = ? OR invoice_id = ?)
                AND alert_sent_date = CURDATE()
                AND status = 'sent'
            ");
            $todayCheck->execute([$companyId, $leaseId, $installmentId, $billingItemId, $invoiceId]);
            if ($todayCheck->fetch()) {
                return ['success' => false, 'message' => 'Alert already sent today'];
            }
        }
        
        // Get lease details
        $lease = $conn->prepare("
            SELECT 
                l.*,
                u.unit_number,
                b.name as building_name,
                t.first_name,
                t.last_name,
                t.email as tenant_email,
                t.phone as tenant_phone
            FROM re_leases l
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE l.id = ? AND l.company_id = ?
        ");
        $lease->execute([$leaseId, $companyId]);
        $lease = $lease->fetch(PDO::FETCH_ASSOC);
        
        if (!$lease) {
            return ['success' => false, 'message' => 'Lease not found'];
        }
        
        // Get recipient emails
        $recipients = [];
        if ($config['recipient_emails']) {
            $recipients = array_map('trim', explode(',', $config['recipient_emails']));
        }
        
        // Get emails from re_email_notifications table
        $emailNotif = $conn->prepare("
            SELECT DISTINCT recipient_email 
            FROM re_email_notifications 
            WHERE company_id = ? AND notification_type = 'overdue_rent' AND is_enabled = 1
        ");
        $emailNotif->execute([$companyId]);
        $notifEmails = $emailNotif->fetchAll(PDO::FETCH_COLUMN);
        $recipients = array_merge($recipients, $notifEmails);
        $recipients = array_unique(array_filter($recipients));
        
        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No recipients configured'];
        }
        
        // Prepare email
        $subject = "[Real Estate] Overdue Payment Alert: {$lease['building_name']} - {$lease['unit_number']} ({$daysOverdue} days overdue)";
        $viewLink = get_base_url() . "/modules/realestate/collections.php";
        
        $htmlBody = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #dc3545; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f8f9fa; padding: 20px; border-radius: 0 0 5px 5px; }
                    .info-row { margin: 10px 0; }
                    .label { font-weight: bold; display: inline-block; width: 150px; }
                    .alert-box { background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #ffc107; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>⚠️ Overdue Payment Alert</h2>
                    </div>
                    <div class='content'>
                        <p>Dear Manager,</p>
                        <div class='alert-box'>
                            <strong>⚠️ Payment is {$daysOverdue} days overdue!</strong>
                        </div>
                        
                        <p>Details of the overdue payment:</p>
                        
                        <div class='info-row'>
                            <span class='label'>Lease Number:</span>
                            <span>" . h($lease['lease_number']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Building:</span>
                            <span>" . h($lease['building_name']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Unit:</span>
                            <span>" . h($lease['unit_number']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Tenant:</span>
                            <span>" . h($lease['first_name'] . ' ' . $lease['last_name']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Due Date:</span>
                            <span>" . date('M d, Y', strtotime($dueDate)) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Days Overdue:</span>
                            <span><strong>{$daysOverdue} days</strong></span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Amount:</span>
                            <span><strong>" . number_format($amount, 2) . " AED</strong></span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Tenant Phone:</span>
                            <span>" . h($lease['tenant_phone'] ?: '-') . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Tenant Email:</span>
                            <span>" . h($lease['tenant_email'] ?: '-') . "</span>
                        </div>
                        
                        <a href='{$viewLink}' class='button'>View Collections Dashboard</a>
                        
                        <p style='margin-top: 20px; font-size: 12px; color: #6c757d;'>
                            This is an automated notification from the Real Estate Management System.
                        </p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Send email
        $emailResult = send_re_email_notification(
            $conn, 
            $companyId, 
            'overdue_rent', 
            $subject, 
            $htmlBody, 
            $recipients,
            $leaseId,
            'lease'
        );
        
        // Log alert
        $logStmt = $conn->prepare("
            INSERT INTO re_overdue_rent_alerts
            (company_id, lease_id, installment_id, billing_item_id, invoice_id, due_date, amount, days_overdue, alert_sent_date, alert_sent_to, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'sent')
        ");
        $logStmt->execute([
            $companyId, $leaseId, $installmentId, $billingItemId, $invoiceId,
            $dueDate, $amount, $daysOverdue, implode(', ', $recipients)
        ]);
        
        return ['success' => true, 'message' => 'Alert sent successfully', 'recipients' => $recipients];
    }
}

if (!function_exists('send_payment_received_notification')) {
    /**
     * Send payment received notification
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param int $paymentId Payment ID
     * @return array Result
     */
    function send_payment_received_notification(PDO $conn, int $companyId, int $paymentId): array {
        // Check if alert config is enabled
        $config = $conn->prepare("
            SELECT * FROM re_collections_alerts_config
            WHERE company_id = ? AND alert_type = 'payment_received' AND is_enabled = 1
        ");
        $config->execute([$companyId]);
        $config = $config->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            return ['success' => false, 'message' => 'Payment received notifications are disabled'];
        }
        
        // Check if already sent
        $check = $conn->prepare("
            SELECT id FROM re_payment_notifications
            WHERE payment_id = ?
        ");
        $check->execute([$paymentId]);
        if ($check->fetch()) {
            return ['success' => false, 'message' => 'Notification already sent'];
        }
        
        // Get payment details
        $payment = $conn->prepare("
            SELECT 
                p.*,
                l.lease_number,
                u.unit_number,
                b.name as building_name,
                t.first_name,
                t.last_name,
                t.email as tenant_email
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE p.id = ? AND p.company_id = ?
        ");
        $payment->execute([$paymentId, $companyId]);
        $payment = $payment->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found'];
        }
        
        // Get recipient emails
        $recipients = [];
        if ($config['recipient_emails']) {
            $recipients = array_map('trim', explode(',', $config['recipient_emails']));
        }
        
        // Get emails from re_email_notifications table
        $emailNotif = $conn->prepare("
            SELECT DISTINCT recipient_email 
            FROM re_email_notifications 
            WHERE company_id = ? AND notification_type = 'payment_received' AND is_enabled = 1
        ");
        $emailNotif->execute([$companyId]);
        $notifEmails = $emailNotif->fetchAll(PDO::FETCH_COLUMN);
        $recipients = array_merge($recipients, $notifEmails);
        $recipients = array_unique(array_filter($recipients));
        
        // Determine notification type
        $notificationType = 'payment_received';
        // Could check if full payment or partial based on outstanding amounts
        
        // Prepare email
        $subject = "[Real Estate] Payment Received: {$payment['building_name']} - {$payment['unit_number']}";
        $viewLink = get_base_url() . "/modules/realestate/payment_view.php?id={$paymentId}";
        
        $htmlBody = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #28a745; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f8f9fa; padding: 20px; border-radius: 0 0 5px 5px; }
                    .info-row { margin: 10px 0; }
                    .label { font-weight: bold; display: inline-block; width: 150px; }
                    .success-box { background-color: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #28a745; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>✅ Payment Received</h2>
                    </div>
                    <div class='content'>
                        <p>Dear Manager,</p>
                        <div class='success-box'>
                            <strong>✅ Payment received successfully!</strong>
                        </div>
                        
                        <p>Payment details:</p>
                        
                        <div class='info-row'>
                            <span class='label'>Lease Number:</span>
                            <span>" . h($payment['lease_number']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Building:</span>
                            <span>" . h($payment['building_name']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Unit:</span>
                            <span>" . h($payment['unit_number']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Tenant:</span>
                            <span>" . h($payment['first_name'] . ' ' . $payment['last_name']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Payment Date:</span>
                            <span>" . date('M d, Y', strtotime($payment['payment_date'])) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Amount:</span>
                            <span><strong>" . number_format($payment['amount'], 2) . " AED</strong></span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Payment Method:</span>
                            <span>" . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . "</span>
                        </div>
                        
                        " . ($payment['reference_number'] ? "
                        <div class='info-row'>
                            <span class='label'>Reference:</span>
                            <span>" . h($payment['reference_number']) . "</span>
                        </div>
                        " : "") . "
                        
                        <a href='{$viewLink}' class='button'>View Payment Details</a>
                        
                        <p style='margin-top: 20px; font-size: 12px; color: #6c757d;'>
                            This is an automated notification from the Real Estate Management System.
                        </p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Send email
        $emailResult = send_re_email_notification(
            $conn, 
            $companyId, 
            'payment_received', 
            $subject, 
            $htmlBody, 
            $recipients,
            $paymentId,
            'payment'
        );
        
        // Log notification
        $logStmt = $conn->prepare("
            INSERT INTO re_payment_notifications
            (company_id, payment_id, lease_id, notification_sent_date, notification_sent_to, notification_type)
            VALUES (?, ?, ?, NOW(), ?, ?)
        ");
        $logStmt->execute([
            $companyId, $paymentId, $payment['lease_id'], 
            implode(', ', $recipients), $notificationType
        ]);
        
        return ['success' => true, 'message' => 'Notification sent successfully', 'recipients' => $recipients];
    }
}

if (!function_exists('send_bounced_cheque_alert')) {
    /**
     * Send bounced cheque alert
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param int $chequeId Cheque ID
     * @return array Result
     */
    function send_bounced_cheque_alert(PDO $conn, int $companyId, int $chequeId): array {
        // Check if alert config is enabled
        $config = $conn->prepare("
            SELECT * FROM re_collections_alerts_config
            WHERE company_id = ? AND alert_type = 'bounced_cheque' AND is_enabled = 1
        ");
        $config->execute([$companyId]);
        $config = $config->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            return ['success' => false, 'message' => 'Bounced cheque alerts are disabled'];
        }
        
        // Check if already sent
        $check = $conn->prepare("
            SELECT id FROM re_bounced_cheque_alerts
            WHERE cheque_id = ? AND status = 'sent'
        ");
        $check->execute([$chequeId]);
        if ($check->fetch()) {
            return ['success' => false, 'message' => 'Alert already sent'];
        }
        
        // Get cheque details
        $cheque = $conn->prepare("
            SELECT 
                c.*,
                l.lease_number,
                u.unit_number,
                b.name as building_name,
                t.first_name,
                t.last_name,
                t.email as tenant_email,
                t.phone as tenant_phone
            FROM re_post_dated_cheques c
            JOIN re_leases l ON l.id = c.lease_id
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE c.id = ? AND c.company_id = ?
        ");
        $cheque->execute([$chequeId, $companyId]);
        $cheque = $cheque->fetch(PDO::FETCH_ASSOC);
        
        if (!$cheque) {
            return ['success' => false, 'message' => 'Cheque not found'];
        }
        
        // Get recipient emails
        $recipients = [];
        if ($config['recipient_emails']) {
            $recipients = array_map('trim', explode(',', $config['recipient_emails']));
        }
        
        // Get emails from re_email_notifications table
        $emailNotif = $conn->prepare("
            SELECT DISTINCT recipient_email 
            FROM re_email_notifications 
            WHERE company_id = ? AND notification_type = 'bounced_cheque' AND is_enabled = 1
        ");
        $emailNotif->execute([$companyId]);
        $notifEmails = $emailNotif->fetchAll(PDO::FETCH_COLUMN);
        $recipients = array_merge($recipients, $notifEmails);
        $recipients = array_unique(array_filter($recipients));
        
        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No recipients configured'];
        }
        
        // Prepare email
        $subject = "[Real Estate] ⚠️ BOUNCED CHEQUE ALERT: {$cheque['building_name']} - {$cheque['unit_number']}";
        $viewLink = get_base_url() . "/modules/realestate/billing_cheque_view.php?id={$chequeId}";
        
        $htmlBody = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #dc3545; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f8f9fa; padding: 20px; border-radius: 0 0 5px 5px; }
                    .info-row { margin: 10px 0; }
                    .label { font-weight: bold; display: inline-block; width: 150px; }
                    .alert-box { background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #dc3545; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>⚠️ Bounced Cheque Alert</h2>
                    </div>
                    <div class='content'>
                        <p>Dear Manager,</p>
                        <div class='alert-box'>
                            <strong>⚠️ A cheque has been bounced!</strong>
                        </div>
                        
                        <p>Cheque details:</p>
                        
                        <div class='info-row'>
                            <span class='label'>Cheque Number:</span>
                            <span><strong>" . h($cheque['cheque_number']) . "</strong></span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Lease Number:</span>
                            <span>" . h($cheque['lease_number']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Building:</span>
                            <span>" . h($cheque['building_name']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Unit:</span>
                            <span>" . h($cheque['unit_number']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Tenant:</span>
                            <span>" . h($cheque['first_name'] . ' ' . $cheque['last_name']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Cheque Amount:</span>
                            <span><strong>" . number_format($cheque['cheque_amount'], 2) . " AED</strong></span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Cheque Date:</span>
                            <span>" . date('M d, Y', strtotime($cheque['cheque_date'])) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Bounced Date:</span>
                            <span>" . ($cheque['bounced_date'] ? date('M d, Y', strtotime($cheque['bounced_date'])) : '-') . "</span>
                        </div>
                        
                        " . ($cheque['bounced_reason'] ? "
                        <div class='info-row'>
                            <span class='label'>Bounced Reason:</span>
                            <span>" . h($cheque['bounced_reason']) . "</span>
                        </div>
                        " : "") . "
                        
                        " . ($cheque['bank_name'] ? "
                        <div class='info-row'>
                            <span class='label'>Bank:</span>
                            <span>" . h($cheque['bank_name']) . "</span>
                        </div>
                        " : "") . "
                        
                        <a href='{$viewLink}' class='button'>View Cheque Details</a>
                        
                        <p style='margin-top: 20px; font-size: 12px; color: #6c757d;'>
                            This is an automated notification from the Real Estate Management System.
                        </p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Send email
        $emailResult = send_re_email_notification(
            $conn, 
            $companyId, 
            'bounced_cheque', 
            $subject, 
            $htmlBody, 
            $recipients,
            $chequeId,
            'cheque'
        );
        
        // Log alert
        $logStmt = $conn->prepare("
            INSERT INTO re_bounced_cheque_alerts
            (company_id, cheque_id, lease_id, cheque_number, cheque_amount, bounced_date, bounced_reason, alert_sent_date, alert_sent_to, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'sent')
        ");
        $logStmt->execute([
            $companyId, $chequeId, $cheque['lease_id'], $cheque['cheque_number'],
            $cheque['cheque_amount'], $cheque['bounced_date'], $cheque['bounced_reason'],
            implode(', ', $recipients)
        ]);
        
        return ['success' => true, 'message' => 'Alert sent successfully', 'recipients' => $recipients];
    }
}

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

