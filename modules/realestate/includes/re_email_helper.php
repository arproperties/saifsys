<?php
/**
 * Real Estate Email Notification Helper
 * Sends email notifications for Real Estate module events
 */

require_once __DIR__ . '/../../../includes/mailer.php';

/**
 * Backwards-compatible simple email sender used by older helpers
 * Signature matches legacy code: send_re_email($to, $subject, $html)
 * Uses global $conn to load SMTP settings from app_email_settings.
 */
if (!function_exists('send_re_email')) {
    function send_re_email(string $to, string $subject, string $html): bool {
        global $conn;

        if (!$conn instanceof PDO) {
            error_log('send_re_email: $conn not available');
            return false;
        }

        // Load SMTP settings
        $stmt = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            error_log('send_re_email: Email settings not configured or disabled');
            return false;
        }

        $result = send_smtp_mail($settings, $to, $subject, $html);
        if (!$result['ok']) {
            error_log('send_re_email: Failed to send mail to ' . $to . ' - ' . ($result['error'] ?? 'Unknown error'));
        }

        return (bool)$result['ok'];
    }
}

/**
 * Send Real Estate email notification
 * 
 * @param PDO $conn Database connection
 * @param int $companyId Company ID
 * @param string $notificationType Type of notification (e.g., 'maintenance_request', 'lease_expiry')
 * @param string $subject Email subject
 * @param string $htmlBody HTML email body
 * @param array $additionalRecipients Additional email addresses (optional)
 * @return array ['success' => bool, 'sent_to' => array, 'errors' => array]
 */
function send_re_email_notification(
    PDO $conn, 
    int $companyId, 
    string $notificationType, 
    string $subject, 
    string $htmlBody,
    array $additionalRecipients = [],
    ?int $relatedId = null,
    ?string $relatedType = null
): array {
    $result = [
        'success' => false,
        'sent_to' => [],
        'errors' => [],
        'logs' => []
    ];
    
    // Get email settings (sender configuration)
    $emailSettings = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
    $emailSettings->execute();
    $settings = $emailSettings->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings || empty($settings['smtp_host']) || empty($settings['smtp_username'])) {
        $error = 'Email not configured. Please set up SMTP settings in Email Settings.';
        $result['errors'][] = $error;
        error_log("RE Email Error: {$error}");
        return $result;
    }
    
    // Get recipient emails from re_email_notifications table
    $recipients = $conn->prepare("
        SELECT DISTINCT recipient_email 
        FROM re_email_notifications 
        WHERE company_id = ? AND notification_type = ? AND is_enabled = 1
    ");
    $recipients->execute([$companyId, $notificationType]);
    $recipientEmails = $recipients->fetchAll(PDO::FETCH_COLUMN);
    
    // Add additional recipients
    $recipientEmails = array_merge($recipientEmails, $additionalRecipients);
    $recipientEmails = array_unique(array_filter($recipientEmails));
    
    if (empty($recipientEmails)) {
        $error = "No recipients configured for notification type: {$notificationType}. Please configure in Settings > Real Estate Email Notifications.";
        $result['errors'][] = $error;
        error_log("RE Email Error: {$error}");
        return $result;
    }
    
    // Log email attempt for each recipient
    $logStmt = $conn->prepare("
        INSERT INTO re_email_logs 
        (company_id, notification_type, recipient_email, subject, status, related_id, related_type)
        VALUES (?, ?, ?, ?, 'pending', ?, ?)
    ");
    
    // Send email to each recipient
    foreach ($recipientEmails as $email) {
        $logId = null;
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email address: {$email}";
            $result['errors'][] = $error;
            error_log("RE Email Error: {$error}");
            continue;
        }
        
        // Log email attempt
        try {
            $logStmt->execute([$companyId, $notificationType, $email, $subject, $relatedId, $relatedType]);
            $logId = $conn->lastInsertId();
        } catch (Exception $e) {
            error_log("RE Email Log Error: " . $e->getMessage());
        }
        
        // Send email
        $mailResult = send_smtp_mail($settings, $email, $subject, $htmlBody);
        
        // Update log with result
        if ($logId) {
            $updateLog = $conn->prepare("
                UPDATE re_email_logs 
                SET status = ?, sent_at = ?, error_message = ?
                WHERE id = ?
            ");
            
            if ($mailResult['ok']) {
                $updateLog->execute(['sent', date('Y-m-d H:i:s'), null, $logId]);
                $result['sent_to'][] = $email;
                $result['logs'][] = ['email' => $email, 'status' => 'sent', 'log_id' => $logId];
            } else {
                $errorMsg = $mailResult['error'] ?? 'Unknown error';
                $updateLog->execute(['failed', null, $errorMsg, $logId]);
                $result['errors'][] = "Failed to send to {$email}: {$errorMsg}";
                $result['logs'][] = ['email' => $email, 'status' => 'failed', 'error' => $errorMsg, 'log_id' => $logId];
                error_log("RE Email Failed to {$email}: {$errorMsg}");
            }
        }
    }
    
    $result['success'] = count($result['sent_to']) > 0;
    
    return $result;
}

if (!function_exists('re_find_home_admin_url')) {
    function re_find_home_admin_url(string $path): string {
        if (function_exists('get_base_url')) {
            return rtrim(get_base_url(), '/') . '/' . ltrim($path, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $prefix = '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (strpos($script, '/herosysgro/') !== false) {
            $prefix = '/herosysgro';
        }
        return $scheme . '://' . $host . $prefix . '/' . ltrim($path, '/');
    }
}

if (!function_exists('re_find_home_email_value')) {
    function re_find_home_email_value($value): string {
        return htmlspecialchars((string)($value !== null && $value !== '' ? $value : 'Not provided'), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('re_find_home_lead_email_html')) {
    function re_find_home_lead_email_html(string $title, array $lead, array $unit, string $adminUrl, array $extraRows = []): string {
        $rows = array_merge([
            'Prospect' => trim((string)($lead['full_name'] ?? '')),
            'Phone' => $lead['phone'] ?? '',
            'Email' => $lead['email'] ?? '',
            'Building' => $unit['building_name'] ?? '',
            'Unit' => $unit['unit_number'] ?? '',
            'Listing' => $unit['listing_title'] ?? '',
            'Annual rent' => !empty($unit['annual_rent']) ? 'AED ' . number_format((float)$unit['annual_rent'], 2) : '',
        ], $extraRows);

        $rowHtml = '';
        foreach ($rows as $label => $value) {
            $rowHtml .= '<tr><th style="text-align:left;padding:8px 10px;background:#f7f2ea;border-bottom:1px solid #e8ddca;width:35%;">'
                . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8')
                . '</th><td style="padding:8px 10px;border-bottom:1px solid #e8ddca;">'
                . re_find_home_email_value($value)
                . '</td></tr>';
        }

        return "
        <html>
        <body style=\"font-family:Arial,sans-serif;color:#17252D;background:#F7F2EA;padding:20px;\">
            <div style=\"max-width:680px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #E8DDCA;\">
                <div style=\"background:#071A24;color:#F7F2EA;padding:22px 24px;\">
                    <div style=\"font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#C9A45C;\">Find Your Home</div>
                    <h2 style=\"margin:6px 0 0;font-size:22px;\">{$title}</h2>
                </div>
                <div style=\"padding:22px 24px;\">
                    <p style=\"margin-top:0;\">A prospective tenant submitted a new lead from the Ain Al Reem Living mobile app.</p>
                    <table style=\"border-collapse:collapse;width:100%;font-size:14px;\">{$rowHtml}</table>
                    <p style=\"margin-top:22px;\">
                        <a href=\"{$adminUrl}\" style=\"display:inline-block;background:#C9A45C;color:#071A24;text-decoration:none;padding:11px 18px;border-radius:10px;font-weight:bold;\">Open Admin Queue</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }
}

if (!function_exists('send_find_home_viewing_request_notification')) {
    function send_find_home_viewing_request_notification(PDO $conn, int $requestId, int $companyId): array {
        $stmt = $conn->prepare("
            SELECT r.*, u.unit_number, u.listing_title, u.annual_rent, b.name AS building_name
            FROM re_unit_viewing_requests r
            JOIN re_units u ON u.id = r.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            WHERE r.id = ? AND r.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$requestId, $companyId]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lead) {
            return ['success' => false, 'errors' => ['Viewing request not found']];
        }

        $subject = '[Find Your Home] New viewing request - ' . (($lead['building_name'] ?? 'Property') . ' ' . ($lead['unit_number'] ?? ''));
        $adminUrl = re_find_home_admin_url('modules/realestate/unit_viewing_requests.php');
        $html = re_find_home_lead_email_html(
            'New Viewing Request',
            $lead,
            $lead,
            $adminUrl,
            [
                'Preferred date' => $lead['preferred_date'] ?? '',
                'Preferred time' => $lead['preferred_time'] ?? '',
                'Message' => $lead['message'] ?? '',
            ]
        );

        return send_re_email_notification(
            $conn,
            $companyId,
            'find_home_viewing_request',
            $subject,
            $html,
            [],
            $requestId,
            'find_home_viewing_request'
        );
    }
}

if (!function_exists('send_find_home_lease_application_notification')) {
    function send_find_home_lease_application_notification(PDO $conn, int $applicationId, int $companyId): array {
        $stmt = $conn->prepare("
            SELECT a.*, u.unit_number, u.listing_title, u.annual_rent, b.name AS building_name
            FROM re_unit_lease_applications a
            JOIN re_units u ON u.id = a.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            WHERE a.id = ? AND a.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$applicationId, $companyId]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lead) {
            return ['success' => false, 'errors' => ['Lease application not found']];
        }

        $subject = '[Find Your Home] New lease application - ' . (($lead['building_name'] ?? 'Property') . ' ' . ($lead['unit_number'] ?? ''));
        $adminUrl = re_find_home_admin_url('modules/realestate/unit_lease_applications.php');
        $html = re_find_home_lead_email_html(
            'New Lease Application',
            $lead,
            $lead,
            $adminUrl,
            [
                'Nationality' => $lead['nationality'] ?? '',
                'Employer' => $lead['employer'] ?? '',
                'Move-in date' => $lead['move_in_date'] ?? '',
                'Occupants' => $lead['occupants_count'] ?? '',
                'Notes' => $lead['notes'] ?? '',
            ]
        );

        return send_re_email_notification(
            $conn,
            $companyId,
            'find_home_lease_application',
            $subject,
            $html,
            [],
            $applicationId,
            'find_home_lease_application'
        );
    }
}

/**
 * Send a reminder email for a task to the assigned employee.
 *
 * @param PDO  $conn
 * @param array $task Row from re_tasks joined with employee and category (see cron_task_reminders.php)
 * @return array Result from send_re_email_notification
 */
function send_task_reminder_email(PDO $conn, array $task): array
{
    $companyId = (int)$task['company_id'];
    $subject = '[Task Reminder] ' . ($task['task_title'] ?? 'Task');

    $dueText = $task['due_date'] ? date('M d, Y', strtotime($task['due_date'])) : 'No due date';
    $priority = ucfirst($task['priority'] ?? 'medium');
    $category = $task['category_name'] ?? 'No category';
    $status = ucfirst(str_replace('_', ' ', $task['status'] ?? 'pending'));

    $taskUrl = get_base_url() . '/modules/realestate/tasks_view.php?id=' . (int)$task['id'];

    $htmlBody = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.5; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; background:#f8f9fa; border-radius:8px; }
            .title { font-size: 18px; font-weight: bold; margin-bottom: 10px; }
            .meta { font-size: 14px; margin-bottom: 5px; }
            .button { display:inline-block; margin-top:15px; padding:10px 18px; background:#0d6efd; color:#fff; text-decoration:none; border-radius:4px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='title'>Task Reminder</div>
            <p>You have a task that needs your attention:</p>
            <p><strong>" . htmlspecialchars($task['task_title'] ?? '', ENT_QUOTES, 'UTF-8') . "</strong></p>
            <p class='meta'><strong>Status:</strong> {$status}</p>
            <p class='meta'><strong>Priority:</strong> {$priority}</p>
            <p class='meta'><strong>Due date:</strong> {$dueText}</p>
            <p class='meta'><strong>Category:</strong> {$category}</p>
            " . (!empty($task['task_description']) ? "<p>" . nl2br(htmlspecialchars($task['task_description'], ENT_QUOTES, 'UTF-8')) . "</p>" : "") . "
            <a href='{$taskUrl}' class='button'>Open task</a>
        </div>
    </body>
    </html>";

    // Use the generic RE email sender, but force recipient to the assigned employee
    $additionalRecipients = [];
    if (!empty($task['assigned_email'])) {
        $additionalRecipients[] = $task['assigned_email'];
    }

    return send_re_email_notification(
        $conn,
        $companyId,
        'task_reminder',
        $subject,
        $htmlBody,
        $additionalRecipients,
        (int)$task['id'],
        'task'
    );
}

/**
 * Send maintenance request notification to maintenance manager
 * 
 * @param PDO $conn Database connection
 * @param int $requestId Maintenance request ID
 * @param int $companyId Company ID
 * @return array Result of email sending
 */
function send_maintenance_request_notification(PDO $conn, int $requestId, int $companyId): array {
    require_once __DIR__ . '/maintenance_location_helper.php';
    // Get maintenance request details
    $stmt = $conn->prepare("
        SELECT mr.*,
               u.unit_number,
               ca.area_name AS common_area_name,
               COALESCE(b.name, bu.name) AS building_name,
               t.first_name, t.last_name, t.phone,
               l.lease_number,
               creator.username as created_by_name
        FROM re_maintenance_requests mr
        LEFT JOIN re_units u ON u.id = mr.unit_id
        LEFT JOIN re_buildings bu ON bu.id = u.building_id
        LEFT JOIN re_buildings b ON b.id = mr.building_id
        LEFT JOIN re_building_common_areas ca ON ca.id = mr.common_area_id
        LEFT JOIN re_tenants t ON t.id = mr.tenant_id
        LEFT JOIN re_leases l ON l.id = mr.lease_id
        LEFT JOIN user creator ON creator.id = mr.created_by
        WHERE mr.id = ? AND mr.company_id = ?
    ");
    $stmt->execute([$requestId, $companyId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        return ['success' => false, 'error' => 'Maintenance request not found'];
    }
    $locationLabel = htmlspecialchars(re_maint_location_label(
        $request['building_name'] ?? null,
        (string)($request['location_type'] ?? 'unit'),
        $request['unit_number'] ?? null,
        $request['common_area_name'] ?? null
    ), ENT_QUOTES, 'UTF-8');
    
    // Build email subject
    $priorityLabels = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'URGENT'
    ];
    $priorityLabel = $priorityLabels[$request['priority']] ?? ucfirst($request['priority']);
    
    $subject = "[Real Estate] New Maintenance Request #{$requestId} - {$priorityLabel} Priority";
    
    // Build email body
    $htmlBody = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #007bff; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
            .content { background-color: #f8f9fa; padding: 20px; border-radius: 0 0 5px 5px; }
            .info-row { margin: 10px 0; }
            .label { font-weight: bold; display: inline-block; width: 150px; }
            .priority-urgent { color: #dc3545; font-weight: bold; }
            .priority-high { color: #fd7e14; font-weight: bold; }
            .priority-medium { color: #ffc107; }
            .priority-low { color: #6c757d; }
            .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>New Maintenance Request</h2>
            </div>
            <div class='content'>
                <p>A new maintenance request has been created and requires your attention.</p>
                
                <div class='info-row'>
                    <span class='label'>Request ID:</span>
                    <span>#{$requestId}</span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Priority:</span>
                    <span class='priority-{$request['priority']}'>{$priorityLabel}</span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Location:</span>
                    <span>{$locationLabel}</span>
                </div>
                
                " . ($request['first_name'] ? "
                <div class='info-row'>
                    <span class='label'>Tenant:</span>
                    <span>{$request['first_name']} {$request['last_name']}</span>
                </div>
                " : "") . "
                
                " . ($request['phone'] ? "
                <div class='info-row'>
                    <span class='label'>Tenant Phone:</span>
                    <span>{$request['phone']}</span>
                </div>
                " : "") . "
                
                " . ($request['category'] ? "
                <div class='info-row'>
                    <span class='label'>Category:</span>
                    <span>{$request['category']}</span>
                </div>
                " : "") . "
                
                <div class='info-row'>
                    <span class='label'>Request Date:</span>
                    <span>" . date('Y-m-d', strtotime($request['request_date'])) . "</span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Description:</span>
                </div>
                <div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>
                    " . nl2br(htmlspecialchars($request['description'])) . "
                </div>
                
                " . ($request['notes'] ? "
                <div class='info-row'>
                    <span class='label'>Notes:</span>
                </div>
                <div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>
                    " . nl2br(htmlspecialchars($request['notes'])) . "
                </div>
                " : "") . "
                
                <div class='info-row'>
                    <span class='label'>Created By:</span>
                    <span>{$request['created_by_name']}</span>
                </div>
                
                <a href='" . get_base_url() . "/modules/realestate/maintenance_queue.php' class='button'>View in Maintenance Queue</a>
                
                <p style='margin-top: 20px; font-size: 12px; color: #6c757d;'>
                    This is an automated notification from the Real Estate Management System.
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Send email
    return send_re_email_notification($conn, $companyId, 'maintenance_request', $subject, $htmlBody, [], $requestId, 'maintenance_request');
}

/**
 * Notify inventory manager(s) when a material request is created from another module (RE, construction, ARS, cleaning, etc.).
 * Recipients: Settings → Real Estate Email Notifications → Inventory manager / admin (notification_type = inventory_material_request).
 *
 * @param PDO $conn
 * @param int $requestId inv_request_headers.id
 * @param int $companyId
 * @return array Result from send_re_email_notification
 */
function send_inventory_material_request_notification(PDO $conn, int $requestId, int $companyId): array {
    require_once dirname(__DIR__, 3) . '/includes/inventory/inv_request_create_helpers.php';

    $hStmt = $conn->prepare("
        SELECT r.*, u.username AS requested_by_username, u.fullname AS requested_by_fullname
        FROM inv_request_headers r
        LEFT JOIN user u ON u.id = r.requested_by
        WHERE r.id = ? AND r.company_id = ?
    ");
    $hStmt->execute([$requestId, $companyId]);
    $h = $hStmt->fetch(PDO::FETCH_ASSOC);
    if (!$h) {
        return ['success' => false, 'errors' => ['Material request not found'], 'sent_to' => [], 'logs' => []];
    }

    $lStmt = $conn->prepare("
        SELECT l.*, i.item_code, i.name AS item_name, um.code AS uom_code
        FROM inv_request_lines l
        JOIN inv_items i ON i.id = l.item_id AND i.company_id = l.company_id
        LEFT JOIN inv_uoms um ON um.id = l.uom_id
        WHERE l.header_id = ? AND l.company_id = ?
        ORDER BY l.sort_order ASC, l.id ASC
    ");
    $lStmt->execute([$requestId, $companyId]);
    $lines = $lStmt->fetchAll(PDO::FETCH_ASSOC);

    $ctx = [
        'context_building_id' => !empty($h['context_building_id']) ? (int)$h['context_building_id'] : null,
        'context_unit_id' => !empty($h['context_unit_id']) ? (int)$h['context_unit_id'] : null,
        'context_project_id' => !empty($h['context_project_id']) ? (int)$h['context_project_id'] : null,
        'context_booking_id' => !empty($h['context_booking_id']) ? (int)$h['context_booking_id'] : null,
        'context_work_order_id' => !empty($h['context_work_order_id']) ? (int)$h['context_work_order_id'] : null,
        'context_housekeeping_id' => !empty($h['context_housekeeping_id']) ? (int)$h['context_housekeeping_id'] : null,
        'context_cleaning_job_id' => !empty($h['context_cleaning_job_id']) ? (int)$h['context_cleaning_job_id'] : null,
    ];
    $labels = inv_request_resolve_context_labels($conn, $companyId, $ctx);
    $titles = inv_material_request_context_field_titles();

    $moduleLabel = trim((string)($h['source_module'] ?? ''));
    if ($moduleLabel !== '') {
        $moduleLabel = ucwords(str_replace(['_', '-'], ' ', $moduleLabel));
    } else {
        $moduleLabel = '—';
    }

    $reqNo = htmlspecialchars((string)($h['request_no'] ?? ''), ENT_QUOTES, 'UTF-8');
    $reqDate = !empty($h['request_date']) ? date('Y-m-d', strtotime((string)$h['request_date'])) : '';
    $requestedBy = trim((string)($h['requested_by_fullname'] ?? '') . ' (' . (string)($h['requested_by_username'] ?? '') . ')');
    $requestedBy = $requestedBy === ' ()' ? '—' : htmlspecialchars($requestedBy, ENT_QUOTES, 'UTF-8');

    $contextRows = '';
    foreach ($titles as $key => $title) {
        if (empty($labels[$key])) {
            continue;
        }
        $contextRows .= "<div class='info-row'><span class='label'>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ":</span><span>"
            . htmlspecialchars((string)$labels[$key], ENT_QUOTES, 'UTF-8') . "</span></div>";
    }
    if ($h['source_table'] || $h['source_id']) {
        $ref = trim(($h['source_table'] ?? '') . ($h['source_id'] ? ' #' . (int)$h['source_id'] : ''));
        $contextRows .= "<div class='info-row'><span class='label'>Source ref:</span><span>" . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . "</span></div>";
    }

    $notesHtml = '';
    if (!empty($h['notes'])) {
        $notesHtml = "<div class='info-row'><span class='label'>Notes:</span></div><div style='background:#fff;padding:12px;border-radius:5px;margin:8px 0;'>"
            . nl2br(htmlspecialchars((string)$h['notes'], ENT_QUOTES, 'UTF-8')) . "</div>";
    }

    $lineRows = '';
    foreach ($lines as $ln) {
        $code = htmlspecialchars((string)($ln['item_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string)($ln['item_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $uom = htmlspecialchars((string)($ln['uom_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $qty = htmlspecialchars((string)$ln['requested_qty'], ENT_QUOTES, 'UTF-8');
        $lineRows .= "<tr><td>{$code}</td><td>{$name}</td><td>{$uom}</td><td style='text-align:right'>{$qty}</td></tr>";
    }
    if ($lineRows === '') {
        $lineRows = '<tr><td colspan="4">No lines</td></tr>';
    }

    $viewUrl = get_base_url() . '/modules/inventory/request_view.php?id=' . (int)$requestId;
    $subject = '[Inventory] New material request ' . ($h['request_no'] ?? ('#' . $requestId)) . ' — ' . $moduleLabel;

    $htmlBody = "
    <html><head><style>
        body{font-family:Arial,sans-serif;line-height:1.6;color:#333;}
        .container{max-width:640px;margin:0 auto;padding:20px;}
        .header{background:#0d6efd;color:#fff;padding:16px;border-radius:6px 6px 0 0;}
        .content{background:#f8f9fa;padding:20px;border-radius:0 0 6px 6px;}
        .info-row{margin:8px 0;}
        .label{font-weight:bold;display:inline-block;min-width:140px;}
        table{width:100%;border-collapse:collapse;background:#fff;margin-top:12px;font-size:14px;}
        th,td{border:1px solid #dee2e6;padding:8px;text-align:left;}
        th{background:#e9ecef;}
        .button{display:inline-block;padding:10px 18px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:5px;margin-top:16px;}
    </style></head><body>
    <div class='container'>
        <div class='header'><h2 style='margin:0;font-size:18px;'>New material request</h2></div>
        <div class='content'>
            <p>A material request was submitted from <strong>" . htmlspecialchars($moduleLabel, ENT_QUOTES, 'UTF-8') . "</strong> and is pending review in Inventory.</p>
            <div class='info-row'><span class='label'>Request no.:</span><span>{$reqNo}</span></div>
            <div class='info-row'><span class='label'>Request date:</span><span>" . htmlspecialchars($reqDate, ENT_QUOTES, 'UTF-8') . "</span></div>
            <div class='info-row'><span class='label'>Requested by:</span><span>{$requestedBy}</span></div>
            {$contextRows}
            {$notesHtml}
            <p style='margin-top:16px;margin-bottom:4px;font-weight:bold;'>Lines</p>
            <table>
                <thead><tr><th>Code</th><th>Item</th><th>UoM</th><th style='text-align:right'>Qty</th></tr></thead>
                <tbody>{$lineRows}</tbody>
            </table>
            <a href='" . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . "' class='button'>Open request in Inventory</a>
            <p style='margin-top:20px;font-size:12px;color:#6c757d;'>Automated notification — configure recipients under System Settings → Real Estate Email Notifications.</p>
        </div>
    </div>
    </body></html>";

    return send_re_email_notification(
        $conn,
        $companyId,
        'inventory_material_request',
        $subject,
        $htmlBody,
        [],
        $requestId,
        'inv_request'
    );
}

/**
 * Send extra service request notification to management
 * Recipients must be configured in re_email_notifications with notification_type = 'extra_service_request'
 *
 * @param PDO $conn Database connection
 * @param int $requestId tenant_extra_service_requests.id
 * @param int $companyId Company ID
 * @return array Result of email sending
 */
function send_extra_service_request_notification(PDO $conn, int $requestId, int $companyId): array {
    $stmt = $conn->prepare("
        SELECT r.*, l.lease_number, t.first_name, t.last_name, t.email, t.phone,
               u.unit_number, b.name AS building_name
        FROM tenant_extra_service_requests r
        JOIN re_leases l ON l.id = r.lease_id
        JOIN re_tenants t ON t.id = r.tenant_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE r.id = ? AND r.company_id = ?
    ");
    $stmt->execute([$requestId, $companyId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        return ['success' => false, 'error' => 'Extra service request not found'];
    }
    $typeLabel = ucwords(str_replace('_', ' ', $req['service_type']));
    $subject = "[Real Estate] New Extra Service Request #{$requestId} - {$typeLabel}";
    $period = '';
    if (!empty($req['period_from']) && !empty($req['period_to'])) {
        $period = "<div class='info-row'><span class='label'>Period:</span><span>" . date('M j, Y', strtotime($req['period_from'])) . " – " . date('M j, Y', strtotime($req['period_to'])) . "</span></div>";
        if (!empty($req['total_amount_aed'])) {
            $period .= "<div class='info-row'><span class='label'>Total amount:</span><span>" . number_format((float)$req['total_amount_aed'], 2) . " AED</span></div>";
        }
    }
    $htmlBody = "
    <html><head><style>
        body{font-family:Arial,sans-serif;line-height:1.6;color:#333;}
        .container{max-width:600px;margin:0 auto;padding:20px;}
        .header{background:#0f4c75;color:white;padding:15px;border-radius:5px 5px 0 0;}
        .content{background:#f8f9fa;padding:20px;border-radius:0 0 5px 5px;}
        .info-row{margin:10px 0;}
        .label{font-weight:bold;display:inline-block;width:150px;}
        .button{display:inline-block;padding:10px 20px;background:#0f4c75;color:white;text-decoration:none;border-radius:5px;margin-top:20px;}
    </style></head><body>
    <div class='container'>
        <div class='header'><h2>New Extra Service Request</h2></div>
        <div class='content'>
            <p>A tenant has submitted an extra service request.</p>
            <div class='info-row'><span class='label'>Request ID:</span><span>#{$requestId}</span></div>
            <div class='info-row'><span class='label'>Service type:</span><span>{$typeLabel}</span></div>
            <div class='info-row'><span class='label'>Unit:</span><span>{$req['building_name']} – {$req['unit_number']}</span></div>
            <div class='info-row'><span class='label'>Lease:</span><span>{$req['lease_number']}</span></div>
            <div class='info-row'><span class='label'>Tenant:</span><span>{$req['first_name']} {$req['last_name']}</span></div>
            " . ($req['phone'] ? "<div class='info-row'><span class='label'>Phone:</span><span>{$req['phone']}</span></div>" : "") . "
            " . $period . "
            <div class='info-row'><span class='label'>Description:</span></div>
            <div style='background:white;padding:15px;border-radius:5px;margin:10px 0;'>" . nl2br(htmlspecialchars($req['description'] ?: '—')) . "</div>
            <a href='" . get_base_url() . "/modules/realestate/extra_service_requests.php' class='button'>View Extra Service Requests</a>
            <p style='margin-top:20px;font-size:12px;color:#6c757d;'>This is an automated notification from the Tenant Portal.</p>
        </div>
    </div>
    </body></html>
    ";
    return send_re_email_notification($conn, $companyId, 'extra_service_request', $subject, $htmlBody, [], $requestId, 'extra_service_request');
}

/**
 * Send cleaning request notification to management.
 * Recipients: re_email_notifications with notification_type = 'cleaning_request'
 */
function send_cleaning_request_notification(PDO $conn, int $requestId, int $companyId): array {
    $stmt = $conn->prepare("
        SELECT r.*, l.lease_number, t.first_name, t.last_name, t.phone,
               u.unit_number, b.name AS building_name
        FROM tenant_cleaning_requests r
        JOIN re_leases l ON l.id = r.lease_id
        JOIN re_tenants t ON t.id = r.tenant_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE r.id = ? AND r.company_id = ?
    ");
    $stmt->execute([$requestId, $companyId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['success' => false, 'error' => 'Request not found'];
    $subject = "[Real Estate] New Cleaning Request #{$requestId}";
    $htmlBody = "
    <html><head><style>body{font-family:Arial,sans-serif;line-height:1.6;}.container{max-width:600px;margin:0 auto;padding:20px;}.header{background:#0f4c75;color:white;padding:15px;}.content{padding:20px;background:#f8f9fa;}.info-row{margin:8px 0;}.label{font-weight:bold;display:inline-block;width:140px;}.button{display:inline-block;padding:10px 20px;background:#0f4c75;color:white;text-decoration:none;border-radius:5px;margin-top:15px;}</style></head><body>
    <div class='container'><div class='header'><h2>New Cleaning Request</h2></div><div class='content'>
    <p>A tenant has submitted a cleaning request.</p>
    <div class='info-row'><span class='label'>Request ID:</span>#{$requestId}</div>
    <div class='info-row'><span class='label'>Unit:</span>{$req['building_name']} – {$req['unit_number']}</div>
    <div class='info-row'><span class='label'>Lease:</span>{$req['lease_number']}</div>
    <div class='info-row'><span class='label'>Tenant:</span>{$req['first_name']} {$req['last_name']}</div>
    <div class='info-row'><span class='label'>Cleaners:</span>{$req['num_cleaners']}</div>
    <div class='info-row'><span class='label'>Hours:</span>{$req['num_hours']}</div>
    <div class='info-row'><span class='label'>Materials:</span>" . ($req['has_materials'] ? 'Yes' : 'No') . "</div>
    <div class='info-row'><span class='label'>Total (AED):</span>" . ($req['total_amount_aed'] ? number_format((float)$req['total_amount_aed'], 2) : '—') . "</div>
    <a href='" . get_base_url() . "/modules/realestate/cleaning_requests.php' class='button'>View Cleaning Requests</a>
    </div></div></body></html>
    ";
    return send_re_email_notification($conn, $companyId, 'cleaning_request', $subject, $htmlBody, [], $requestId, 'cleaning_request');
}

/**
 * Send pest control request notification to management.
 * Recipients: re_email_notifications with notification_type = 'pest_control_request'
 */
function send_pest_control_request_notification(PDO $conn, int $requestId, int $companyId): array {
    $stmt = $conn->prepare("
        SELECT r.*, l.lease_number, t.first_name, t.last_name, t.phone,
               u.unit_number, b.name AS building_name
        FROM tenant_pest_control_requests r
        JOIN re_leases l ON l.id = r.lease_id
        JOIN re_tenants t ON t.id = r.tenant_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE r.id = ? AND r.company_id = ?
    ");
    $stmt->execute([$requestId, $companyId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['success' => false, 'error' => 'Request not found'];
    $subject = "[Real Estate] New Pest Control Request #{$requestId}";
    $ut = ucwords(str_replace('_', ' ', $req['unit_type']));
    $htmlBody = "
    <html><head><style>body{font-family:Arial,sans-serif;line-height:1.6;}.container{max-width:600px;margin:0 auto;padding:20px;}.header{background:#0f4c75;color:white;padding:15px;}.content{padding:20px;background:#f8f9fa;}.info-row{margin:8px 0;}.label{font-weight:bold;display:inline-block;width:140px;}.button{display:inline-block;padding:10px 20px;background:#0f4c75;color:white;text-decoration:none;border-radius:5px;margin-top:15px;}</style></head><body>
    <div class='container'><div class='header'><h2>New Pest Control Request</h2></div><div class='content'>
    <p>A tenant has submitted a pest control request.</p>
    <div class='info-row'><span class='label'>Request ID:</span>#{$requestId}</div>
    <div class='info-row'><span class='label'>Unit:</span>{$req['building_name']} – {$req['unit_number']}</div>
    <div class='info-row'><span class='label'>Lease:</span>{$req['lease_number']}</div>
    <div class='info-row'><span class='label'>Tenant:</span>{$req['first_name']} {$req['last_name']}</div>
    <div class='info-row'><span class='label'>Unit type:</span>{$ut}</div>
    <div class='info-row'><span class='label'>Amount (AED):</span>" . ($req['total_amount_aed'] ? number_format((float)$req['total_amount_aed'], 2) : '—') . "</div>
    <a href='" . get_base_url() . "/modules/realestate/pest_control_requests.php' class='button'>View Pest Control Requests</a>
    </div></div></body></html>
    ";
    return send_re_email_notification($conn, $companyId, 'pest_control_request', $subject, $htmlBody, [], $requestId, 'pest_control_request');
}

/**
 * Send email to accountants when reception confirms cash (pending verification).
 * Recipients: re_email_notifications with notification_type = 'cash_payment_pending_verification'
 * Configure in Settings > Real Estate Email Notifications > "Cash Payment – Accountants Team".
 *
 * @param PDO $conn Database connection
 * @param int $cashPaymentRequestId re_cash_payment_requests.id
 * @param int $companyId Company ID
 * @return array Result of send_re_email_notification
 */
function send_cash_payment_pending_verification_notification(PDO $conn, int $cashPaymentRequestId, int $companyId): array {
    $stmt = $conn->prepare("
        SELECT r.request_number, r.related_type, r.related_id, r.amount_aed, r.receipt_number, r.received_at
        FROM re_cash_payment_requests r
        WHERE r.id = ? AND r.company_id = ?
    ");
    $stmt->execute([$cashPaymentRequestId, $companyId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        return ['success' => false, 'error' => 'Cash payment request not found'];
    }
    $typeLabel = ucwords(str_replace('_', ' ', $req['related_type']));
    $subject = "[Real Estate] Cash received – Pending verification: {$req['request_number']}";
    $receivedAt = $req['received_at'] ? date('M j, Y H:i', strtotime($req['received_at'])) : '—';
    $htmlBody = "
    <html><head><style>body{font-family:Arial,sans-serif;line-height:1.6;}.container{max-width:600px;margin:0 auto;padding:20px;}.header{background:#0d6efd;color:white;padding:15px;}.content{padding:20px;background:#f8f9fa;}.info-row{margin:8px 0;}.label{font-weight:bold;display:inline-block;width:140px;}.button{display:inline-block;padding:10px 20px;background:#0d6efd;color:white;text-decoration:none;border-radius:5px;margin-top:15px;}</style></head><body>
    <div class='container'><div class='header'><h2>Cash received – Pending verification</h2></div><div class='content'>
    <p>Reception has confirmed cash receipt. Please verify and approve in Cash verification.</p>
    <div class='info-row'><span class='label'>Request ID:</span>{$req['request_number']}</div>
    <div class='info-row'><span class='label'>Type:</span>{$typeLabel}</div>
    <div class='info-row'><span class='label'>Related ID:</span>{$req['related_id']}</div>
    <div class='info-row'><span class='label'>Amount (AED):</span>" . number_format((float)$req['amount_aed'], 2) . "</div>
    <div class='info-row'><span class='label'>Receipt:</span>" . htmlspecialchars($req['receipt_number'] ?? '—', ENT_QUOTES, 'UTF-8') . "</div>
    <div class='info-row'><span class='label'>Received at:</span>{$receivedAt}</div>
    <a href='" . get_base_url() . "/modules/realestate/cash_verification.php' class='button'>Cash verification</a>
    <p style='margin-top:20px;font-size:12px;color:#6c757d;'>This is an automated notification.</p>
    </div></div></body></html>
    ";
    return send_re_email_notification($conn, $companyId, 'cash_payment_pending_verification', $subject, $htmlBody, [], $cashPaymentRequestId, 'cash_payment_request');
}

/**
 * Generate cash payment receipt as PDF (string) for attachment.
 * Returns PDF binary string or null on failure.
 *
 * @param PDO $conn Database connection
 * @param int $cashPaymentRequestId re_cash_payment_requests.id
 * @param int $companyId Company ID
 * @param string $tenantName Tenant name for receipt
 * @return string|null PDF binary content or null
 */
function generate_cash_payment_receipt_pdf(PDO $conn, int $cashPaymentRequestId, int $companyId, string $tenantName): ?string {
    $stmt = $conn->prepare("
        SELECT r.request_number, r.receipt_number, r.related_type, r.related_id, r.amount_aed, r.verified_at, r.received_at
        FROM re_cash_payment_requests r
        WHERE r.id = ? AND r.company_id = ?
    ");
    $stmt->execute([$cashPaymentRequestId, $companyId]);
    $cpr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cpr) {
        return null;
    }
    $companyName = 'Real Estate';
    try {
        $c = $conn->prepare("SELECT name FROM companies WHERE id = ?");
        $c->execute([$companyId]);
        $row = $c->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['name'])) {
            $companyName = $row['name'];
        }
    } catch (Throwable $e) {}

    $serviceLabel = ucwords(str_replace('_', ' ', $cpr['related_type']));
    $requestNumber = htmlspecialchars($cpr['request_number'], ENT_QUOTES, 'UTF-8');
    $receiptNumber = htmlspecialchars($cpr['receipt_number'] ?? $cpr['request_number'], ENT_QUOTES, 'UTF-8');
    $amount = number_format((float)$cpr['amount_aed'], 2);
    $verifiedAt = $cpr['verified_at'] ? date('F j, Y \a\t g:i A', strtotime($cpr['verified_at'])) : date('F j, Y \a\t g:i A');
    $receivedAt = $cpr['received_at'] ? date('F j, Y \a\t g:i A', strtotime($cpr['received_at'])) : '—';
    $tenantNameSafe = htmlspecialchars($tenantName ?: 'Tenant', ENT_QUOTES, 'UTF-8');
    $companyNameSafe = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');

    $html = "
    <!DOCTYPE html><html><head><meta charset='UTF-8'><style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; line-height: 1.4; color: #333; }
        .receipt { max-width: 500px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; border-bottom: 2px solid #198754; padding-bottom: 15px; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 18pt; color: #198754; }
        .header .company { font-size: 12pt; color: #555; margin-top: 5px; }
        .title { font-size: 14pt; font-weight: bold; margin-bottom: 15px; }
        table.details { width: 100%; border-collapse: collapse; }
        table.details td { padding: 6px 0; border-bottom: 1px solid #eee; }
        table.details td:first-child { font-weight: bold; width: 140px; color: #555; }
        .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 9pt; color: #666; text-align: center; }
    </style></head><body>
    <div class='receipt'>
        <div class='header'>
            <h1>PAYMENT RECEIPT</h1>
            <div class='company'>{$companyNameSafe}</div>
        </div>
        <div class='title'>Receipt #{$receiptNumber}</div>
        <table class='details'>
            <tr><td>Request ID</td><td>{$requestNumber}</td></tr>
            <tr><td>Paid by</td><td>{$tenantNameSafe}</td></tr>
            <tr><td>Service</td><td>{$serviceLabel}</td></tr>
            <tr><td>Amount</td><td><strong>{$amount} AED</strong></td></tr>
            <tr><td>Received at</td><td>{$receivedAt}</td></tr>
            <tr><td>Verified at</td><td>{$verifiedAt}</td></tr>
        </table>
        <div class='footer'>This receipt confirms that the above cash payment has been received and verified. Thank you.</div>
    </div>
    </body></html>
    ";

    try {
        $vendorAutoload = __DIR__ . '/../../../vendor/autoload.php';
        if (!class_exists('\Dompdf\Dompdf') && file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }
        if (!class_exists('\Dompdf\Dompdf')) {
            error_log('generate_cash_payment_receipt_pdf: Dompdf not found');
            return null;
        }
        $baseDir = __DIR__ . '/../../../';
        $tmpDir = $baseDir . 'uploads/cash_receipts_temp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
            $tmpDir = sys_get_temp_dir();
        }
        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('tempDir', $tmpDir);
        $options->set('fontCache', $tmpDir);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A5', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();
        return ($output !== false && $output !== '') ? $output : null;
    } catch (Throwable $e) {
        error_log('generate_cash_payment_receipt_pdf: ' . $e->getMessage());
        return null;
    }
}

/**
 * Send email to tenant when accountant/owner/manager approves cash payment (Paid verified).
 * Includes PDF receipt as attachment. Tenant email is resolved from the related request.
 *
 * @param PDO $conn Database connection
 * @param int $cashPaymentRequestId re_cash_payment_requests.id
 * @param int $companyId Company ID
 * @return array ['success' => bool, 'sent_to' => ?string, 'error' => ?string]
 */
function send_cash_payment_verified_to_tenant_notification(PDO $conn, int $cashPaymentRequestId, int $companyId): array {
    $stmt = $conn->prepare("
        SELECT request_number, receipt_number, related_type, related_id, amount_aed
        FROM re_cash_payment_requests
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$cashPaymentRequestId, $companyId]);
    $cpr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cpr) {
        return ['success' => false, 'error' => 'Cash payment request not found'];
    }
    $tenantEmail = null;
    $tenantName = '';
    $serviceLabel = ucwords(str_replace('_', ' ', $cpr['related_type']));
    if ($cpr['related_type'] === 'extra_service') {
        $q = $conn->prepare("SELECT t.email, t.first_name, t.last_name FROM tenant_extra_service_requests r JOIN re_tenants t ON t.id = r.tenant_id WHERE r.id = ? AND r.company_id = ?");
        $q->execute([$cpr['related_id'], $companyId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tenantEmail = trim($row['email'] ?? '');
            $tenantName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        }
    } elseif ($cpr['related_type'] === 'cleaning') {
        $q = $conn->prepare("SELECT t.email, t.first_name, t.last_name FROM tenant_cleaning_requests r JOIN re_tenants t ON t.id = r.tenant_id WHERE r.id = ? AND r.company_id = ?");
        $q->execute([$cpr['related_id'], $companyId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tenantEmail = trim($row['email'] ?? '');
            $tenantName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        }
    } elseif ($cpr['related_type'] === 'pest_control') {
        $q = $conn->prepare("SELECT t.email, t.first_name, t.last_name FROM tenant_pest_control_requests r JOIN re_tenants t ON t.id = r.tenant_id WHERE r.id = ? AND r.company_id = ?");
        $q->execute([$cpr['related_id'], $companyId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tenantEmail = trim($row['email'] ?? '');
            $tenantName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        }
    }
    if (!$tenantEmail || !filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Tenant email not found or invalid'];
    }

    $subject = "Payment verified – {$cpr['request_number']}";
    $htmlBody = "
    <html><head><style>body{font-family:Arial,sans-serif;line-height:1.6;}.container{max-width:600px;margin:0 auto;padding:20px;}.header{background:#198754;color:white;padding:15px;}.content{padding:20px;background:#f8f9fa;}</style></head><body>
    <div class='container'><div class='header'><h2>Payment verified</h2></div><div class='content'>
    <p>Dear " . htmlspecialchars($tenantName ?: 'Tenant', ENT_QUOTES, 'UTF-8') . ",</p>
    <p>Your cash payment has been verified by accounting. Please find your receipt attached as a PDF.</p>
    <p><strong>Request ID:</strong> {$cpr['request_number']}<br>
    <strong>Service:</strong> {$serviceLabel}<br>
    <strong>Amount:</strong> " . number_format((float)$cpr['amount_aed'], 2) . " AED</p>
    <p>Your service can now be activated. Thank you.</p>
    <p style='font-size:12px;color:#6c757d;'>This is an automated message from the Real Estate Management System.</p>
    </div></div></body></html>
    ";

    $receiptFilename = 'Receipt_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $cpr['request_number']) . '.pdf';
    $pdfContent = generate_cash_payment_receipt_pdf($conn, $cashPaymentRequestId, $companyId, $tenantName);
    $pdfTempPath = null;
    if ($pdfContent !== null && $pdfContent !== '') {
        $baseDir = __DIR__ . '/../../../';
        $tempDir = $baseDir . 'uploads/cash_receipts_temp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }
        if (is_dir($tempDir) && is_writable($tempDir)) {
            $pdfTempPath = $tempDir . '/' . uniqid('receipt_', true) . '.pdf';
            if (@file_put_contents($pdfTempPath, $pdfContent) === false) {
                $pdfTempPath = null;
            }
        }
        // Fallback: if app temp dir failed, use system temp so we still attach the PDF
        if ($pdfTempPath === null) {
            $fallbackDir = sys_get_temp_dir();
            if (is_writable($fallbackDir)) {
                $pdfTempPath = $fallbackDir . '/' . uniqid('receipt_', true) . '.pdf';
                if (@file_put_contents($pdfTempPath, $pdfContent) !== false) {
                    // success
                } else {
                    $pdfTempPath = null;
                }
            }
        }
    }

    $emailSettings = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
    $emailSettings->execute();
    $settings = $emailSettings->fetch(PDO::FETCH_ASSOC);
    if (!$settings || empty($settings['smtp_host']) || empty($settings['smtp_username'])) {
        if ($pdfTempPath && file_exists($pdfTempPath)) {
            @unlink($pdfTempPath);
        }
        return ['success' => false, 'error' => 'Email not configured'];
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $settings['smtp_host'] ?? '';
        $mail->Port       = (int)($settings['smtp_port'] ?? 587);
        $secure = strtolower($settings['smtp_secure'] ?? 'tls');
        if ($secure === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
        }
        $mail->SMTPAuth = !empty($settings['smtp_username']);
        if ($mail->SMTPAuth) {
            $mail->Username = $settings['smtp_username'];
            $mail->Password = $settings['smtp_password'] ?? '';
        }
        $mail->CharSet = 'UTF-8';
        $fromEmail = $settings['from_email'] ?? $settings['smtp_username'] ?? 'no-reply@example.com';
        $mail->setFrom($fromEmail, $settings['from_name'] ?? $fromEmail);
        $mail->addAddress($tenantEmail, $tenantName ?: 'Tenant');
        $mail->Subject = $subject;
        $mail->msgHTML($htmlBody);
        // Attach PDF from string so it always attaches when generation succeeded (no temp file dependency)
        if ($pdfContent !== null && $pdfContent !== '') {
            $mail->addStringAttachment($pdfContent, $receiptFilename, 'base64', 'application/pdf');
        } elseif ($pdfTempPath && file_exists($pdfTempPath)) {
            $mail->addAttachment($pdfTempPath, $receiptFilename);
        }
        $mail->send();
        if ($pdfTempPath && file_exists($pdfTempPath)) {
            @unlink($pdfTempPath);
        }
        return ['success' => true, 'sent_to' => $tenantEmail, 'error' => null];
    } catch (Throwable $e) {
        if ($pdfTempPath && file_exists($pdfTempPath)) {
            @unlink($pdfTempPath);
        }
        $err = $e->getMessage();
        error_log('send_cash_payment_verified_to_tenant: ' . $err);
        return ['success' => false, 'sent_to' => null, 'error' => $err];
    }
}

/**
 * Send maintenance assignment notification to assigned employee
 * 
 * @param PDO $conn Database connection
 * @param int $requestId Maintenance request ID
 * @param int $companyId Company ID
 * @param int $employeeId Assigned employee ID
 * @param array $requestDetails Request details from database
 * @param array $employee Employee details from database
 * @return array Result of email sending
 */
function send_maintenance_assignment_notification(
    PDO $conn, 
    int $requestId, 
    int $companyId, 
    int $employeeId,
    array $requestDetails,
    array $employee
): array {
    if (empty($employee['email'])) {
        return ['success' => false, 'error' => 'Employee email not found'];
    }

    require_once __DIR__ . '/maintenance_location_helper.php';
    $locationLabel = htmlspecialchars(re_maint_location_label(
        $requestDetails['building_name'] ?? null,
        (string)($requestDetails['location_type'] ?? 'unit'),
        $requestDetails['unit_number'] ?? null,
        $requestDetails['common_area_name'] ?? null
    ), ENT_QUOTES, 'UTF-8');
    
    // Build email subject
    $priorityLabels = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'URGENT'
    ];
    $priorityLabel = $priorityLabels[$requestDetails['priority']] ?? ucfirst($requestDetails['priority']);
    
    $subject = "[Real Estate] Maintenance Request #{$requestId} Assigned to You - {$priorityLabel} Priority";
    
    // Build email body
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
            .priority-urgent { color: #dc3545; font-weight: bold; }
            .priority-high { color: #fd7e14; font-weight: bold; }
            .priority-medium { color: #ffc107; }
            .priority-low { color: #6c757d; }
            .button { display: inline-block; padding: 10px 20px; background-color: #28a745; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Maintenance Request Assigned</h2>
            </div>
            <div class='content'>
                <p>Hello <strong>{$employee['full_name']}</strong>,</p>
                <p>A maintenance request has been assigned to you and requires your attention.</p>
                
                <div class='info-row'>
                    <span class='label'>Request ID:</span>
                    <span>#{$requestId}</span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Priority:</span>
                    <span class='priority-{$requestDetails['priority']}'>{$priorityLabel}</span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Location:</span>
                    <span>{$locationLabel}</span>
                </div>
                
                " . ($requestDetails['first_name'] ? "
                <div class='info-row'>
                    <span class='label'>Tenant:</span>
                    <span>{$requestDetails['first_name']} {$requestDetails['last_name']}</span>
                </div>
                " : "") . "
                
                " . ($requestDetails['phone'] ? "
                <div class='info-row'>
                    <span class='label'>Tenant Phone:</span>
                    <span>{$requestDetails['phone']}</span>
                </div>
                " : "") . "
                
                " . ($requestDetails['category'] ? "
                <div class='info-row'>
                    <span class='label'>Category:</span>
                    <span>{$requestDetails['category']}</span>
                </div>
                " : "") . "
                
                <div class='info-row'>
                    <span class='label'>Request Date:</span>
                    <span>" . date('Y-m-d', strtotime($requestDetails['request_date'])) . "</span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Description:</span>
                </div>
                <div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>
                    " . nl2br(htmlspecialchars($requestDetails['description'])) . "
                </div>
                
                " . ($requestDetails['notes'] ? "
                <div class='info-row'>
                    <span class='label'>Notes:</span>
                </div>
                <div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>
                    " . nl2br(htmlspecialchars($requestDetails['notes'])) . "
                </div>
                " : "") . "
                
                " . ($requestDetails['cost'] > 0 ? "
                <div class='info-row'>
                    <span class='label'>Estimated Cost:</span>
                    <span>" . number_format($requestDetails['cost'], 2) . " AED</span>
                </div>
                " : "") . "
                
                <a href='" . get_base_url() . "/modules/realestate/maintenance_view.php?id={$requestId}' class='button'>View Request Details</a>
                
                <p style='margin-top: 20px; font-size: 12px; color: #6c757d;'>
                    This is an automated notification from the Real Estate Management System.
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Send email directly to the employee
    require_once __DIR__ . '/../../../includes/mailer.php';
    
    // Get email settings
    $emailSettings = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
    $emailSettings->execute();
    $settings = $emailSettings->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings || empty($settings['smtp_host'])) {
        return ['success' => false, 'error' => 'Email settings not configured'];
    }
    
    // Send email
    $mailResult = send_smtp_mail($settings, $employee['email'], $subject, $htmlBody);
    
    // Log the email attempt
    try {
        $logStmt = $conn->prepare("
            INSERT INTO re_email_logs 
            (company_id, notification_type, recipient_email, subject, status, related_id, related_type, sent_at)
            VALUES (?, 'maintenance_assignment', ?, ?, ?, ?, 'maintenance_request', ?)
        ");
        $status = $mailResult['ok'] ? 'sent' : 'failed';
        $sentAt = $mailResult['ok'] ? date('Y-m-d H:i:s') : null;
        $logStmt->execute([
            $companyId, 
            $employee['email'], 
            $subject, 
            $status, 
            $requestId,
            $sentAt
        ]);
        
        if (!$mailResult['ok']) {
            $updateLog = $conn->prepare("
                UPDATE re_email_logs 
                SET error_message = ?
                WHERE id = ?
            ");
            $updateLog->execute([$mailResult['error'] ?? 'Unknown error', $conn->lastInsertId()]);
        }
    } catch (Exception $e) {
        error_log("Failed to log assignment email: " . $e->getMessage());
    }
    
    return [
        'success' => $mailResult['ok'],
        'sent_to' => $mailResult['ok'] ? [$employee['email']] : [],
        'errors' => $mailResult['ok'] ? [] : [$mailResult['error'] ?? 'Unknown error']
    ];
}

/**
 * Get base URL for email links
 */
function get_base_url(): string {
    $env = getenv('CRON_BASE_URL');
    if ($env === false || $env === '') {
        $env = getenv('LEASE_REMINDER_BASE_URL');
    }
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Method 1: Use DOCUMENT_ROOT to find project root
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $projectRoot = realpath(__DIR__ . '/../../../');
    
    if ($docRoot && $projectRoot) {
        $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        
        if (str_starts_with($projectRoot, $docRoot)) {
            $basePath = substr($projectRoot, strlen($docRoot));
            $basePath = $basePath ? '/' . ltrim($basePath, '/') : '';
            return "{$protocol}://{$host}{$basePath}";
        }
    }
    
    // Method 2: Parse from REQUEST_URI or SCRIPT_NAME and remove module paths
    $requestUri = $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
    if ($requestUri) {
        // Remove query string
        $requestUri = strtok($requestUri, '?');
        // Remove any /modules/... paths to get to project root
        $basePath = preg_replace('#/modules/.*$#', '', $requestUri);
        // Also remove any filename (like maintenance_add.php)
        $basePath = preg_replace('#/[^/]+\.php$#', '', $basePath);
        $basePath = rtrim($basePath, '/');
        if ($basePath && $basePath !== '/') {
            return "{$protocol}://{$host}{$basePath}";
        }
    }
    
    // Method 3: Root (project at server root)
    return "{$protocol}://{$host}";
}

/**
 * Resolve the staff member who triggered an internal notification.
 *
 * Task assignment is internal traffic, so it should read as coming from the
 * colleague who assigned it rather than the shared tenantcare mailbox. Only the
 * employee's company address qualifies: a personal address (gmail etc.) on the
 * From line would fail DMARC and land in spam, so those fall back to the
 * configured default. Tenant-facing mail never calls this.
 *
 * @return array{from_email?:string, from_name?:string, reply_to?:string}
 */
function re_internal_sender_identity(PDO $conn, ?int $actorUserId, array $settings): array {
    if (!$actorUserId || $actorUserId <= 0) {
        return [];
    }

    $defaultFrom = (string)($settings['from_email'] ?? '');
    if ($defaultFrom === '') {
        return [];
    }

    try {
        $stmt = $conn->prepare("
            SELECT full_name, email
            FROM employees
            WHERE user_id = ? AND email IS NOT NULL AND email <> ''
            LIMIT 1
        ");
        $stmt->execute([$actorUserId]);
        $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    if (!$actor || !filter_var($actor['email'], FILTER_VALIDATE_EMAIL)) {
        return [];
    }

    // Same-domain only — see doc block above.
    require_once __DIR__ . '/../../../includes/mailer.php';
    if (!smtp_same_mail_domain((string)$actor['email'], $defaultFrom)) {
        return [];
    }

    $name = trim((string)($actor['full_name'] ?? '')) ?: (string)$actor['email'];
    return [
        'from_email' => (string)$actor['email'],
        'from_name'  => $name,
        'reply_to'   => (string)$actor['email'],
    ];
}

/**
 * Send task assignment notification to assigned employee
 *
 * @param PDO $conn Database connection
 * @param int $taskId Task ID
 * @param int $companyId Company ID
 * @param int $employeeId Assigned employee ID
 * @param array $taskDetails Task details from database
 * @param array $employee Employee details from database
 * @param int|null $actorUserId User who created/assigned the task; when they have a
 *        company email the message is sent from it instead of the shared mailbox.
 * @return array Result of email sending
 */
function send_task_assignment_notification(
    PDO $conn,
    int $taskId,
    int $companyId,
    int $employeeId,
    array $taskDetails,
    array $employee,
    ?int $actorUserId = null
): array {
    if (empty($employee['email'])) {
        return ['success' => false, 'error' => 'Employee email not found'];
    }

    $priorityLabels = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'URGENT'];
    $priorityLabel = $priorityLabels[$taskDetails['priority']] ?? ucfirst($taskDetails['priority']);

    // Who assigned it — shown in the body so the recipient knows without checking the app.
    $assignedByName = '';
    if ($actorUserId && $actorUserId > 0) {
        try {
            $byStmt = $conn->prepare("SELECT full_name FROM employees WHERE user_id = ? LIMIT 1");
            $byStmt->execute([$actorUserId]);
            $assignedByName = trim((string)($byStmt->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $assignedByName = '';
        }
    }

    $subject = "[Real Estate] Task Assigned: {$taskDetails['task_title']} - {$priorityLabel} Priority";
    
    $dueDateText = $taskDetails['due_date'] ? date('M d, Y', strtotime($taskDetails['due_date'])) : 'No due date';
    $isOverdue = $taskDetails['due_date'] && strtotime($taskDetails['due_date']) < time();
    
    $htmlBody = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #007bff; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
            .content { background-color: #f8f9fa; padding: 20px; border-radius: 0 0 5px 5px; }
            .info-row { margin: 10px 0; }
            .label { font-weight: bold; display: inline-block; width: 150px; }
            .priority-urgent { color: #dc3545; font-weight: bold; }
            .priority-high { color: #fd7e14; font-weight: bold; }
            .priority-medium { color: #ffc107; }
            .priority-low { color: #6c757d; }
            .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
            .overdue { color: #dc3545; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>New Task Assigned</h2>
            </div>
            <div class='content'>
                <p>Hello <strong>{$employee['full_name']}</strong>,</p>
                <p>A new task has been assigned to you and requires your attention.</p>

                <div class='info-row'>
                    <span class='label'>Task:</span>
                    <span><strong>{$taskDetails['task_title']}</strong></span>
                </div>

                " . ($assignedByName !== '' ? "
                <div class='info-row'>
                    <span class='label'>Assigned by:</span>
                    <span>" . htmlspecialchars($assignedByName) . "</span>
                </div>
                " : "") . "

                <div class='info-row'>
                    <span class='label'>Priority:</span>
                    <span class='priority-{$taskDetails['priority']}'>{$priorityLabel}</span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Due Date:</span>
                    <span class='" . ($isOverdue ? 'overdue' : '') . "'>{$dueDateText}" . ($isOverdue ? ' (OVERDUE)' : '') . "</span>
                </div>
                
                " . ($taskDetails['category_name'] ? "
                <div class='info-row'>
                    <span class='label'>Category:</span>
                    <span>{$taskDetails['category_name']}</span>
                </div>
                " : "") . "
                
                " . ($taskDetails['building_name'] ? "
                <div class='info-row'>
                    <span class='label'>Location:</span>
                    <span>{$taskDetails['building_name']}" . ($taskDetails['unit_number'] ? ' - Unit ' . $taskDetails['unit_number'] : '') . "</span>
                </div>
                " : "") . "
                
                " . ($taskDetails['task_description'] ? "
                <div class='info-row'>
                    <span class='label'>Description:</span>
                </div>
                <div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>
                    " . nl2br(htmlspecialchars($taskDetails['task_description'])) . "
                </div>
                " : "") . "
                
                <a href='" . get_base_url() . "/modules/realestate/tasks_view.php?id={$taskId}' class='button'>View Task Details</a>
                
                <p style='margin-top: 20px; font-size: 12px; color: #6c757d;'>
                    This is an automated notification from the Real Estate Management System.
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    require_once __DIR__ . '/../../../includes/mailer.php';
    
    $emailSettings = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
    $emailSettings->execute();
    $settings = $emailSettings->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings || empty($settings['smtp_host'])) {
        return ['success' => false, 'error' => 'Email settings not configured'];
    }
    
    $senderIdentity = re_internal_sender_identity($conn, $actorUserId, $settings);
    $mailResult = send_smtp_mail($settings, $employee['email'], $subject, $htmlBody, $senderIdentity);

    // Log the email
    try {
        $logStmt = $conn->prepare("
            INSERT INTO re_email_logs
            (company_id, notification_type, recipient_email, subject, status, related_id, related_type, sent_at)
            VALUES (?, 'task_assignment', ?, ?, ?, ?, 'task', ?)
        ");
        $status = $mailResult['ok'] ? 'sent' : 'failed';
        $sentAt = $mailResult['ok'] ? date('Y-m-d H:i:s') : null;
        $logStmt->execute([$companyId, $employee['email'], $subject, $status, $taskId, $sentAt]);
        
        if (!$mailResult['ok']) {
            $updateLog = $conn->prepare("UPDATE re_email_logs SET error_message = ? WHERE id = ?");
            $updateLog->execute([$mailResult['error'] ?? 'Unknown error', $conn->lastInsertId()]);
        }
    } catch (Exception $e) {
        error_log("Failed to log task assignment email: " . $e->getMessage());
    }
    
    return [
        'success' => $mailResult['ok'],
        'sent_to' => $mailResult['ok'] ? [$employee['email']] : [],
        'errors' => $mailResult['ok'] ? [] : [$mailResult['error'] ?? 'Unknown error']
    ];
}

/**
 * Send task due date reminder notification
 * 
 * @param PDO $conn Database connection
 * @param int $taskId Task ID
 * @param int $companyId Company ID
 * @param array $taskDetails Task details from database
 * @param array $employee Employee details from database
 * @param int $daysUntilDue Days until due date (negative if overdue)
 * @return array Result of email sending
 */
function send_task_due_reminder_notification(
    PDO $conn, 
    int $taskId, 
    int $companyId, 
    array $taskDetails,
    array $employee,
    int $daysUntilDue
): array {
    if (empty($employee['email'])) {
        return ['success' => false, 'error' => 'Employee email not found'];
    }
    
    $isOverdue = $daysUntilDue < 0;
    $subject = $isOverdue 
        ? "[Real Estate] URGENT: Task Overdue - {$taskDetails['task_title']}"
        : "[Real Estate] Task Due Soon - {$taskDetails['task_title']}";
    
    $dueText = $isOverdue 
        ? "This task is " . abs($daysUntilDue) . " day(s) OVERDUE"
        : "This task is due in {$daysUntilDue} day(s)";
    
    $htmlBody = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: " . ($isOverdue ? '#dc3545' : '#ffc107') . "; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
            .content { background-color: #f8f9fa; padding: 20px; border-radius: 0 0 5px 5px; }
            .info-row { margin: 10px 0; }
            .label { font-weight: bold; display: inline-block; width: 150px; }
            .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
            .alert { background-color: " . ($isOverdue ? '#f8d7da' : '#fff3cd') . "; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid " . ($isOverdue ? '#dc3545' : '#ffc107') . "; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>" . ($isOverdue ? '⚠️ Task Overdue' : '⏰ Task Due Soon') . "</h2>
            </div>
            <div class='content'>
                <p>Hello <strong>{$employee['full_name']}</strong>,</p>
                
                <div class='alert'>
                    <strong>{$dueText}</strong>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Task:</span>
                    <span><strong>{$taskDetails['task_title']}</strong></span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Due Date:</span>
                    <span>" . date('M d, Y', strtotime($taskDetails['due_date'])) . "</span>
                </div>
                
                " . ($taskDetails['task_description'] ? "
                <div class='info-row'>
                    <span class='label'>Description:</span>
                </div>
                <div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>
                    " . nl2br(htmlspecialchars(substr($taskDetails['task_description'], 0, 200))) . "
                </div>
                " : "") . "
                
                <a href='" . get_base_url() . "/modules/realestate/tasks_view.php?id={$taskId}' class='button'>View Task Details</a>
                
                <p style='margin-top: 20px; font-size: 12px; color: #6c757d;'>
                    This is an automated reminder from the Real Estate Management System.
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    require_once __DIR__ . '/../../../includes/mailer.php';
    
    $emailSettings = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
    $emailSettings->execute();
    $settings = $emailSettings->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings || empty($settings['smtp_host'])) {
        return ['success' => false, 'error' => 'Email settings not configured'];
    }
    
    $mailResult = send_smtp_mail($settings, $employee['email'], $subject, $htmlBody);
    
    // Log the email
    try {
        $logStmt = $conn->prepare("
            INSERT INTO re_email_logs 
            (company_id, notification_type, recipient_email, subject, status, related_id, related_type, sent_at)
            VALUES (?, 'task_due_reminder', ?, ?, ?, ?, 'task', ?)
        ");
        $status = $mailResult['ok'] ? 'sent' : 'failed';
        $sentAt = $mailResult['ok'] ? date('Y-m-d H:i:s') : null;
        $logStmt->execute([$companyId, $employee['email'], $subject, $status, $taskId, $sentAt]);
        
        if (!$mailResult['ok']) {
            $updateLog = $conn->prepare("UPDATE re_email_logs SET error_message = ? WHERE id = ?");
            $updateLog->execute([$mailResult['error'] ?? 'Unknown error', $conn->lastInsertId()]);
        }
    } catch (Exception $e) {
        error_log("Failed to log task reminder email: " . $e->getMessage());
    }
    
    return [
        'success' => $mailResult['ok'],
        'sent_to' => $mailResult['ok'] ? [$employee['email']] : [],
        'errors' => $mailResult['ok'] ? [] : [$mailResult['error'] ?? 'Unknown error']
    ];
}

/**
 * Send SLA violation notification to managers
 * 
 * @param PDO $conn Database connection
 * @param int $maintenanceRequestId Maintenance request ID
 * @param int $companyId Company ID
 * @param string $violationType 'response' or 'resolution'
 * @return array Result of email sending
 */
function send_sla_violation_notification(
    PDO $conn, 
    int $maintenanceRequestId, 
    int $companyId,
    string $violationType
): array {
    // Get maintenance request and SLA tracking details
    $stmt = $conn->prepare("
        SELECT 
            mr.*,
            st.target_response_time,
            st.actual_response_time,
            st.target_resolution_time,
            st.actual_resolution_time,
            st.response_time_minutes,
            st.resolution_time_hours,
            st.response_sla_met,
            st.resolution_sla_met,
            b.name as building_name,
            u.unit_number,
            t.first_name,
            t.last_name,
            sr.response_time_minutes as target_response_minutes,
            sr.resolution_time_hours as target_resolution_hours
        FROM re_maintenance_requests mr
        LEFT JOIN re_sla_tracking st ON st.maintenance_request_id = mr.id
        LEFT JOIN re_sla_rules sr ON sr.id = st.sla_rule_id
        LEFT JOIN re_units u ON u.id = mr.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        LEFT JOIN re_tenants t ON t.id = mr.tenant_id
        WHERE mr.id = ? AND mr.company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$maintenanceRequestId, $companyId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        return ['success' => false, 'error' => 'Maintenance request not found'];
    }
    
    // Build email subject
    $violationTypeLabel = $violationType === 'response' ? 'Response' : 'Resolution';
    $subject = "[Real Estate] SLA VIOLATION: {$violationTypeLabel} Time Exceeded - Request #{$maintenanceRequestId}";
    
    // Calculate violation details
    $priorityLabels = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'URGENT'];
    $priorityLabel = $priorityLabels[$request['priority']] ?? ucfirst($request['priority']);
    
    if ($violationType === 'response') {
        $targetTime = $request['target_response_time'] ? date('M d, Y H:i', strtotime($request['target_response_time'])) : 'N/A';
        $actualTime = $request['actual_response_time'] ? date('M d, Y H:i', strtotime($request['actual_response_time'])) : 'N/A';
        $targetMinutes = $request['target_response_minutes'] ?? 0;
        $actualMinutes = $request['response_time_minutes'] ?? 0;
        $overdueMinutes = max(0, $actualMinutes - $targetMinutes);
        $violationText = "Response SLA Violation: Request was not responded to within the target time of {$targetMinutes} minutes. Actual response time: {$actualMinutes} minutes ({$overdueMinutes} minutes overdue).";
    } else {
        $targetTime = $request['target_resolution_time'] ? date('M d, Y H:i', strtotime($request['target_resolution_time'])) : 'N/A';
        $actualTime = $request['actual_resolution_time'] ? date('M d, Y H:i', strtotime($request['actual_resolution_time'])) : 'N/A';
        $targetHours = $request['target_resolution_hours'] ?? 0;
        $actualHours = $request['resolution_time_hours'] ?? 0;
        $overdueHours = max(0, round($actualHours - $targetHours, 2));
        $violationText = "Resolution SLA Violation: Request was not resolved within the target time of {$targetHours} hours. Actual resolution time: {$actualHours} hours ({$overdueHours} hours overdue).";
    }
    
    // Build email body
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
            .priority-urgent { color: #dc3545; font-weight: bold; }
            .priority-high { color: #fd7e14; font-weight: bold; }
            .priority-medium { color: #ffc107; }
            .priority-low { color: #6c757d; }
            .button { display: inline-block; padding: 10px 20px; background-color: #dc3545; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
            .alert { background-color: #f8d7da; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #dc3545; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>⚠️ SLA Violation Alert</h2>
            </div>
            <div class='content'>
                <div class='alert'>
                    <strong>{$violationText}</strong>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Request ID:</span>
                    <span><strong>#{$maintenanceRequestId}</strong></span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Priority:</span>
                    <span class='priority-{$request['priority']}'>{$priorityLabel}</span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Building:</span>
                    <span>" . ($request['building_name'] ?: 'N/A') . "</span>
                </div>
                
                <div class='info-row'>
                    <span class='label'>Unit:</span>
                    <span>" . ($request['unit_number'] ?: 'N/A') . "</span>
                </div>
                
                " . ($request['first_name'] ? "
                <div class='info-row'>
                    <span class='label'>Tenant:</span>
                    <span>{$request['first_name']} {$request['last_name']}</span>
                </div>
                " : "") . "
                
                " . ($request['category'] ? "
                <div class='info-row'>
                    <span class='label'>Category:</span>
                    <span>{$request['category']}</span>
                </div>
                " : "") . "
                
                <div class='info-row'>
                    <span class='label'>Request Date:</span>
                    <span>" . date('M d, Y H:i', strtotime($request['request_date'])) . "</span>
                </div>
                
                " . ($violationType === 'response' ? "
                <div class='info-row'>
                    <span class='label'>Target Response Time:</span>
                    <span>{$targetTime}</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Actual Response Time:</span>
                    <span>{$actualTime}</span>
                </div>
                " : "
                <div class='info-row'>
                    <span class='label'>Target Resolution Time:</span>
                    <span>{$targetTime}</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Actual Resolution Time:</span>
                    <span>{$actualTime}</span>
                </div>
                ") . "
                
                " . ($request['description'] ? "
                <div class='info-row'>
                    <span class='label'>Description:</span>
                </div>
                <div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>
                    " . nl2br(htmlspecialchars($request['description'])) . "
                </div>
                " : "") . "
                
                <a href='" . get_base_url() . "/modules/realestate/maintenance_view.php?id={$maintenanceRequestId}' class='button'>View Request Details</a>
                
                <p style='margin-top: 20px; font-size: 12px; color: #6c757d;'>
                    This is an automated SLA violation alert from the Real Estate Management System.
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Send email using the Real Estate email notification system
    return send_re_email_notification(
        $conn,
        $companyId,
        'sla_violation',
        $subject,
        $htmlBody,
        [],
        $maintenanceRequestId,
        'maintenance_request'
    );
}

