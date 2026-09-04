<?php
/**
 * AMC Alert Generation Cron Job
 * Run this script daily via cron to generate alerts for expiring contracts and certificates
 * 
 * Usage: php amc_alert_cron.php (or set up cron: 0 9 * * * php /path/to/amc_alert_cron.php)
 */

if (php_sapi_name() !== 'cli' && !isset($_GET['run_cron'])) {
    die("This script should be run via CLI or with ?run_cron=1");
}

// Set the working directory to the project root (for CLI)
if (php_sapi_name() === 'cli') {
    chdir(__DIR__ . '/../..');
}

require_once __DIR__ . '/../../includes/db_connect.php';

// $conn is already created by db_connect.php

// Get alert configurations
$configs = $conn->query("
    SELECT company_id, alert_type, days_before_expiry 
    FROM re_amc_alert_config 
    WHERE is_enabled = 1
")->fetchAll(PDO::FETCH_ASSOC);

$alertsGenerated = 0;
$companyAlerts = []; // Track alert IDs per company for email details

foreach ($configs as $config) {
    $companyId = $config['company_id'];
    if (!isset($companyAlerts[$companyId])) {
        $companyAlerts[$companyId] = [];
    }
    $alertType = $config['alert_type'];
    $daysBefore = (int)$config['days_before_expiry'];
    
    if ($alertType === 'contract_expiry') {
        // Generate contract expiry alerts
        $expiryDate = date('Y-m-d', strtotime("+{$daysBefore} days"));
        $targetDate = date('Y-m-d', strtotime("+{$daysBefore} days"));
        
        $stmt = $conn->prepare("
            SELECT id, end_date, status 
            FROM re_amc_contracts 
            WHERE company_id = ? 
            AND status = 'active'
            AND DATE(end_date) = DATE(?)
            AND id NOT IN (
                SELECT contract_id FROM re_amc_alerts 
                WHERE contract_id IS NOT NULL 
                AND alert_type = 'contract_expiry' 
                AND DATE(expiry_date) = DATE(?)
                AND DATE(alert_date) = DATE(?)
                AND status IN ('resolved', 'dismissed')
            )
        ");
        $stmt->execute([$companyId, $targetDate, $targetDate, date('Y-m-d')]);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($contracts as $contract) {
            $daysUntilExpiry = (strtotime($contract['end_date']) - time()) / 86400;
            $severity = $daysUntilExpiry <= 7 ? 'critical' : ($daysUntilExpiry <= 30 ? 'warning' : 'info');
            
            $alertStmt = $conn->prepare("
                INSERT INTO re_amc_alerts (
                    contract_id, alert_type, alert_date, expiry_date, 
                    days_until_expiry, severity, status
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $alertStmt->execute([
                $contract['id'], 
                'contract_expiry', 
                date('Y-m-d'),
                $contract['end_date'],
                (int)$daysUntilExpiry,
                $severity
            ]);
            $companyAlerts[$companyId][] = $conn->lastInsertId();
            $alertsGenerated++;
        }
    }
    
    if ($alertType === 'certificate_expiry') {
        // Generate certificate expiry alerts
        $targetDate = date('Y-m-d', strtotime("+{$daysBefore} days"));
        
        $stmt = $conn->prepare("
            SELECT c.id, c.expiry_date, c.contract_id 
            FROM re_amc_certificates c
            JOIN re_amc_contracts ac ON ac.id = c.contract_id
            WHERE ac.company_id = ?
            AND DATE(c.expiry_date) = DATE(?)
            AND c.status != 'expired'
            AND c.id NOT IN (
                SELECT certificate_id FROM re_amc_alerts 
                WHERE certificate_id IS NOT NULL 
                AND alert_type = 'certificate_expiry' 
                AND DATE(expiry_date) = DATE(?)
                AND DATE(alert_date) = DATE(?)
                AND status IN ('resolved', 'dismissed')
            )
        ");
        $stmt->execute([$companyId, $targetDate, $targetDate, date('Y-m-d')]);
        $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($certificates as $cert) {
            $daysUntilExpiry = (strtotime($cert['expiry_date']) - time()) / 86400;
            $severity = $daysUntilExpiry <= 7 ? 'critical' : ($daysUntilExpiry <= 30 ? 'warning' : 'info');
            
            $alertStmt = $conn->prepare("
                INSERT INTO re_amc_alerts (
                    contract_id, certificate_id, alert_type, alert_date, expiry_date, 
                    days_until_expiry, severity, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $alertStmt->execute([
                $cert['contract_id'],
                $cert['id'],
                'certificate_expiry',
                date('Y-m-d'),
                $cert['expiry_date'],
                (int)$daysUntilExpiry,
                $severity
            ]);
            $companyAlerts[$companyId][] = $conn->lastInsertId();
            $alertsGenerated++;
        }
    }
    
    if ($alertType === 'visit_due') {
        // Generate visit due alerts (visits scheduled for today or past due)
        $today = date('Y-m-d');
        
        $stmt = $conn->prepare("
            SELECT v.id, v.contract_id, v.scheduled_date 
            FROM re_amc_visits v
            JOIN re_amc_contracts ac ON ac.id = v.contract_id
            WHERE ac.company_id = ?
            AND v.status = 'scheduled'
            AND DATE(v.scheduled_date) <= DATE(?)
            AND v.contract_id NOT IN (
                SELECT contract_id FROM re_amc_alerts 
                WHERE contract_id IS NOT NULL 
                AND alert_type = 'visit_due' 
                AND DATE(expiry_date) = DATE(v.scheduled_date)
                AND DATE(alert_date) = DATE(?)
                AND status IN ('resolved', 'dismissed')
            )
        ");
        $stmt->execute([$companyId, $today, date('Y-m-d')]);
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($visits as $visit) {
            $daysUntilVisit = (strtotime($visit['scheduled_date']) - time()) / 86400;
            $severity = $daysUntilVisit < 0 ? 'critical' : ($daysUntilVisit <= 1 ? 'warning' : 'info');
            
            $alertStmt = $conn->prepare("
                INSERT INTO re_amc_alerts (
                    contract_id, alert_type, alert_date, expiry_date, 
                    days_until_expiry, severity, status
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $alertStmt->execute([
                $visit['contract_id'],
                'visit_due',
                date('Y-m-d'),
                $visit['scheduled_date'],
                (int)$daysUntilVisit,
                $severity
            ]);
            $companyAlerts[$companyId][] = $conn->lastInsertId();
            $alertsGenerated++;
        }
    }
}

// Update expired contracts and certificates status
$conn->exec("
    UPDATE re_amc_contracts 
    SET status = 'expired' 
    WHERE status = 'active' 
    AND DATE(end_date) < CURDATE()
");

$conn->exec("
    UPDATE re_amc_certificates 
    SET status = 'expired' 
    WHERE status != 'expired' 
    AND DATE(expiry_date) < CURDATE()
");

if (php_sapi_name() === 'cli') {
    echo "AMC Alert Cron Job completed. Generated {$alertsGenerated} alerts.\n";
} else {
    echo "AMC Alert Cron Job completed. Generated {$alertsGenerated} alerts.";
}

// Send email notification if alerts were generated
if ($alertsGenerated > 0) {
    // Get all unique company IDs that actually generated alerts
    $companyIds = array_keys($companyAlerts);
    
    // Send email to each company's configured email
    foreach ($companyIds as $companyId) {
        $recipientEmail = null;
        
        // Get email from database configuration
        try {
            $emailConfig = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
            $emailConfig->execute(['amc_alert_email_' . $companyId]);
            $emailRow = $emailConfig->fetch(PDO::FETCH_ASSOC);
            if ($emailRow && !empty($emailRow['value'])) {
                $recipientEmail = $emailRow['value'];
            }
        } catch (Exception $e) {
            // Skip if config not found
        }
        
        // Only send email if a valid email is configured
        if ($recipientEmail && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            // Get detailed alert information for this company
            $alertDetails = [];
            $companyAlertIds = isset($companyAlerts[$companyId]) ? $companyAlerts[$companyId] : [];
            if (!empty($companyAlertIds)) {
                $placeholders = implode(',', array_fill(0, count($companyAlertIds), '?'));
                $alertQuery = $conn->prepare("
                    SELECT 
                        a.id,
                        a.alert_type,
                        a.alert_date,
                        a.expiry_date,
                        a.days_until_expiry,
                        a.severity,
                        a.status,
                        ac.id as contract_id,
                        ac.contract_number,
                        ac.contract_title,
                        ac.start_date as contract_start,
                        ac.end_date as contract_end,
                        b.name as building_name,
                        b.address as building_address,
                        cat.name as category_name,
                        cert.certificate_number,
                        cert.certificate_type,
                        cert.issued_by
                    FROM re_amc_alerts a
                    LEFT JOIN re_amc_contracts ac ON ac.id = a.contract_id
                    LEFT JOIN re_buildings b ON b.id = ac.building_id
                    LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
                    LEFT JOIN re_amc_certificates cert ON cert.id = a.certificate_id
                    WHERE a.id IN ({$placeholders})
                    AND ac.company_id = ?
                    ORDER BY 
                        CASE a.severity 
                            WHEN 'critical' THEN 1 
                            WHEN 'warning' THEN 2 
                            ELSE 3 
                        END,
                        a.expiry_date ASC
                ");
                $alertQuery->execute(array_merge($companyAlertIds, [$companyId]));
                $alertDetails = $alertQuery->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Count alerts for this company
            $companyAlertCount = count($companyAlertIds);
            
            $baseUrl = (php_sapi_name() === 'cli' && isset($_SERVER['HTTP_HOST'])) 
                ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] 
                : 'https://sys.saifholdinggroup.com';
            
            $subject = 'AMC Alert Notification - ' . $companyAlertCount . ' Alert(s) - ' . date('Y-m-d');
            
            // Build HTML email
            $htmlMessage = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #2c3e50; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .alert-summary { background: #fff; padding: 15px; margin: 15px 0; border-left: 4px solid #3498db; border-radius: 4px; }
        .alert-item { background: #fff; padding: 15px; margin: 10px 0; border-left: 4px solid #e74c3c; border-radius: 4px; }
        .alert-item.warning { border-left-color: #f39c12; }
        .alert-item.info { border-left-color: #3498db; }
        .alert-item.critical { border-left-color: #e74c3c; }
        .alert-header { font-size: 18px; font-weight: bold; margin-bottom: 10px; color: #2c3e50; }
        .alert-details { margin: 10px 0; }
        .alert-details p { margin: 5px 0; }
        .label { font-weight: bold; color: #555; display: inline-block; min-width: 140px; }
        .value { color: #333; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; }
        .badge-critical { background: #e74c3c; color: white; }
        .badge-warning { background: #f39c12; color: white; }
        .badge-info { background: #3498db; color: white; }
        .button { display: inline-block; padding: 12px 24px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { background: #ecf0f1; padding: 15px; text-align: center; font-size: 12px; color: #7f8c8d; border-radius: 0 0 5px 5px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🚨 AMC Alert Notification</h1>
            <p style='margin: 5px 0 0 0;'>Generated on " . date('F d, Y \a\t H:i:s') . "</p>
        </div>
        <div class='content'>
            <div class='alert-summary'>
                <p style='font-size: 16px; margin: 0;'><strong>Total Alerts Generated:</strong> <span style='color: #e74c3c; font-size: 20px;'>{$companyAlertCount}</span></p>
            </div>";
            
            if (!empty($alertDetails)) {
                $htmlMessage .= "<h2 style='color: #2c3e50; margin-top: 25px;'>Alert Details:</h2>";
                
                foreach ($alertDetails as $alert) {
                    $severityClass = $alert['severity'];
                    $severityLabel = ucfirst($alert['severity']);
                    $badgeClass = "badge-{$alert['severity']}";
                    
                    // Determine alert type label
                    $alertTypeLabel = '';
                    if ($alert['alert_type'] === 'contract_expiry') {
                        $alertTypeLabel = 'Contract Expiry';
                    } elseif ($alert['alert_type'] === 'certificate_expiry') {
                        $alertTypeLabel = 'Certificate Expiry';
                    } elseif ($alert['alert_type'] === 'visit_due') {
                        $alertTypeLabel = 'Visit Due';
                    } else {
                        $alertTypeLabel = ucfirst(str_replace('_', ' ', $alert['alert_type']));
                    }
                    
                    $htmlMessage .= "<div class='alert-item {$severityClass}'>
                        <div class='alert-header'>
                            <span class='badge {$badgeClass}'>{$severityLabel}</span> - {$alertTypeLabel}
                        </div>
                        <div class='alert-details'>";
                    
                    // Contract Information
                    if ($alert['contract_number']) {
                        $htmlMessage .= "<p><span class='label'>Contract Number:</span> <span class='value'><strong>{$alert['contract_number']}</strong></span></p>";
                    }
                    if ($alert['contract_title']) {
                        $htmlMessage .= "<p><span class='label'>Contract Title:</span> <span class='value'>{$alert['contract_title']}</span></p>";
                    }
                    if ($alert['category_name']) {
                        $htmlMessage .= "<p><span class='label'>Category:</span> <span class='value'>{$alert['category_name']}</span></p>";
                    }
                    
                    // Building Information
                    if ($alert['building_name']) {
                        $htmlMessage .= "<p><span class='label'>Building:</span> <span class='value'><strong>{$alert['building_name']}</strong></span></p>";
                    }
                    if ($alert['building_address']) {
                        $htmlMessage .= "<p><span class='label'>Address:</span> <span class='value'>{$alert['building_address']}</span></p>";
                    }
                    
                    // Certificate Information (if applicable)
                    if ($alert['alert_type'] === 'certificate_expiry' && $alert['certificate_number']) {
                        $htmlMessage .= "<p><span class='label'>Certificate Number:</span> <span class='value'><strong>{$alert['certificate_number']}</strong></span></p>";
                        if ($alert['certificate_type']) {
                            $htmlMessage .= "<p><span class='label'>Certificate Type:</span> <span class='value'>{$alert['certificate_type']}</span></p>";
                        }
                        if ($alert['issued_by']) {
                            $htmlMessage .= "<p><span class='label'>Issued By:</span> <span class='value'>{$alert['issued_by']}</span></p>";
                        }
                    }
                    
                    // Expiry/Schedule Information
                    if ($alert['alert_type'] === 'contract_expiry') {
                        $htmlMessage .= "<p><span class='label'>Contract End Date:</span> <span class='value'><strong style='color: #e74c3c;'>" . date('F d, Y', strtotime($alert['expiry_date'])) . "</strong></span></p>";
                    } elseif ($alert['alert_type'] === 'certificate_expiry') {
                        $htmlMessage .= "<p><span class='label'>Certificate Expiry Date:</span> <span class='value'><strong style='color: #e74c3c;'>" . date('F d, Y', strtotime($alert['expiry_date'])) . "</strong></span></p>";
                    } elseif ($alert['alert_type'] === 'visit_due') {
                        $htmlMessage .= "<p><span class='label'>Scheduled Visit Date:</span> <span class='value'><strong style='color: #e74c3c;'>" . date('F d, Y', strtotime($alert['expiry_date'])) . "</strong></span></p>";
                    }
                    
                    // Days until expiry
                    $daysText = abs($alert['days_until_expiry']);
                    if ($alert['days_until_expiry'] < 0) {
                        $daysText = "Overdue by {$daysText} day(s)";
                    } elseif ($alert['days_until_expiry'] == 0) {
                        $daysText = "Expires today";
                    } elseif ($alert['days_until_expiry'] == 1) {
                        $daysText = "Expires in 1 day";
                    } else {
                        $daysText = "Expires in {$daysText} days";
                    }
                    
                    $htmlMessage .= "<p><span class='label'>Time Remaining:</span> <span class='value'><strong style='color: #e74c3c; font-size: 16px;'>{$daysText}</strong></span></p>";
                    
                    $htmlMessage .= "</div>
                    </div>";
                }
            }
            
            $htmlMessage .= "
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$baseUrl}/modules/realestate/amc_alerts.php' class='button'>View All Alerts in Dashboard</a>
            </div>
            
            <div style='background: #fff3cd; padding: 15px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                <p style='margin: 0;'><strong>⚠️ Action Required:</strong> Please review the alerts above and take necessary action to renew contracts, update certificates, or schedule visits as needed.</p>
            </div>
        </div>
        <div class='footer'>
            <p>This is an automated notification from your AMC Alert System.</p>
            <p>Please do not reply to this email. For support, contact your system administrator.</p>
        </div>
    </div>
</body>
</html>";
            
            // Plain text version for email clients that don't support HTML
            $plainMessage = "AMC Alert Notification\n";
            $plainMessage .= "Generated on: " . date('Y-m-d H:i:s') . "\n\n";
            $plainMessage .= "Total Alerts Generated: {$companyAlertCount}\n\n";
            $plainMessage .= "=== ALERT DETAILS ===\n\n";
            
            if (!empty($alertDetails)) {
                foreach ($alertDetails as $alert) {
                    $alertTypeLabel = ucfirst(str_replace('_', ' ', $alert['alert_type']));
                    $plainMessage .= "Alert Type: {$alertTypeLabel} ({$alert['severity']})\n";
                    if ($alert['contract_number']) {
                        $plainMessage .= "Contract: {$alert['contract_number']} - {$alert['contract_title']}\n";
                    }
                    if ($alert['building_name']) {
                        $plainMessage .= "Building: {$alert['building_name']}\n";
                    }
                    if ($alert['certificate_number']) {
                        $plainMessage .= "Certificate: {$alert['certificate_number']}\n";
                    }
                    $plainMessage .= "Expiry Date: " . date('F d, Y', strtotime($alert['expiry_date'])) . "\n";
                    $daysText = abs($alert['days_until_expiry']);
                    if ($alert['days_until_expiry'] < 0) {
                        $daysText = "Overdue by {$daysText} day(s)";
                    } elseif ($alert['days_until_expiry'] == 0) {
                        $daysText = "Expires today";
                    } else {
                        $daysText = "Expires in {$daysText} days";
                    }
                    $plainMessage .= "Time Remaining: {$daysText}\n";
                    $plainMessage .= "\n" . str_repeat('-', 50) . "\n\n";
                }
            }
            
            $plainMessage .= "\nView all alerts: {$baseUrl}/modules/realestate/amc_alerts.php\n\n";
            $plainMessage .= "This is an automated notification from your AMC Alert System.\n";
            
            // Use mail() function with HTML support
            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'sys.saifholdinggroup.com';
            $boundary = md5(time());
            $headers = "From: noreply@{$host}\r\n";
            $headers .= "Reply-To: noreply@{$host}\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            
            $emailBody = "--{$boundary}\r\n";
            $emailBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $emailBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $emailBody .= $plainMessage . "\r\n\r\n";
            $emailBody .= "--{$boundary}\r\n";
            $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
            $emailBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $emailBody .= $htmlMessage . "\r\n\r\n";
            $emailBody .= "--{$boundary}--";
            
            @mail($recipientEmail, $subject, $emailBody, $headers);
        }
    }
}
