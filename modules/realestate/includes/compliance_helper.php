<?php
/**
 * Compliance Helper Functions
 * Document expiry alerts and compliance tracking
 */

require_once __DIR__ . '/re_email_helper.php';

if (!function_exists('send_document_expiry_alert')) {
    /**
     * Send document expiry alert
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param int $documentId Document ID
     * @return array Result
     */
    function send_document_expiry_alert(PDO $conn, int $companyId, int $documentId): array {
        // Get document details
        $document = $conn->prepare("
            SELECT 
                d.*,
                dt.document_type_name,
                dt.alert_days_before_expiry,
                CASE d.related_type
                    WHEN 'lease' THEN (SELECT CONCAT(b.name, ' - ', u.unit_number) FROM re_leases l JOIN re_units u ON u.id = l.unit_id JOIN re_buildings b ON b.id = u.building_id WHERE l.id = d.related_id)
                    WHEN 'tenant' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM re_tenants WHERE id = d.related_id)
                    WHEN 'unit' THEN (SELECT CONCAT(b.name, ' - ', u.unit_number) FROM re_units u JOIN re_buildings b ON b.id = u.building_id WHERE u.id = d.related_id)
                    ELSE 'Other'
                END as related_name
            FROM re_documents d
            LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
            WHERE d.id = ? AND d.company_id = ?
        ");
        $document->execute([$documentId, $companyId]);
        $document = $document->fetch(PDO::FETCH_ASSOC);
        
        if (!$document || !$document['expiry_date']) {
            return ['success' => false, 'message' => 'Document not found or has no expiry date'];
        }
        
        // Check if alert already sent today
        $todayCheck = $conn->prepare("
            SELECT id FROM re_document_expiry_alerts
            WHERE document_id = ? AND alert_sent_date = CURDATE() AND status = 'sent'
        ");
        $todayCheck->execute([$documentId]);
        if ($todayCheck->fetch()) {
            return ['success' => false, 'message' => 'Alert already sent today'];
        }
        
        // Calculate days until expiry
        $daysUntilExpiry = (int)((strtotime($document['expiry_date']) - time()) / 86400);
        
        // Check if we should send alert based on document type configuration
        $alertDays = $document['alert_days_before_expiry'] ?? 30;
        if ($daysUntilExpiry > $alertDays) {
            return ['success' => false, 'message' => "Alert not due yet (sends {$alertDays} days before expiry)"];
        }
        
        // Get recipient emails from collections alerts config
        $config = $conn->prepare("
            SELECT recipient_emails FROM re_collections_alerts_config
            WHERE company_id = ? AND alert_type = 'document_expiry' AND is_enabled = 1
        ");
        $config->execute([$companyId]);
        $config = $config->fetch(PDO::FETCH_ASSOC);
        
        $recipients = [];
        if ($config && $config['recipient_emails']) {
            $recipients = array_map('trim', explode(',', $config['recipient_emails']));
        }
        
        // Get emails from re_email_notifications table
        $emailNotif = $conn->prepare("
            SELECT DISTINCT recipient_email 
            FROM re_email_notifications 
            WHERE company_id = ? AND notification_type = 'document_expiry' AND is_enabled = 1
        ");
        $emailNotif->execute([$companyId]);
        $notifEmails = $emailNotif->fetchAll(PDO::FETCH_COLUMN);
        $recipients = array_merge($recipients, $notifEmails);
        $recipients = array_unique(array_filter($recipients));
        
        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No recipients configured'];
        }
        
        // Prepare email
        $subject = "[Real Estate] Document Expiry Alert: {$document['document_type_name']} - {$document['related_name']}";
        $viewLink = get_base_url() . "/modules/realestate/compliance.php";
        
        $expiryStatus = $daysUntilExpiry < 0 ? 'EXPIRED' : ($daysUntilExpiry <= 7 ? 'Expiring Soon' : 'Expiring');
        $alertColor = $daysUntilExpiry < 0 ? '#dc3545' : ($daysUntilExpiry <= 7 ? '#ffc107' : '#17a2b8');
        
        $htmlBody = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: {$alertColor}; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f8f9fa; padding: 20px; border-radius: 0 0 5px 5px; }
                    .info-row { margin: 10px 0; }
                    .label { font-weight: bold; display: inline-block; width: 150px; }
                    .alert-box { background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid {$alertColor}; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>⚠️ Document Expiry Alert</h2>
                    </div>
                    <div class='content'>
                        <p>Dear Manager,</p>
                        <div class='alert-box'>
                            <strong>⚠️ Document is " . ($daysUntilExpiry < 0 ? abs($daysUntilExpiry) . " days EXPIRED" : "expiring in {$daysUntilExpiry} days") . "!</strong>
                        </div>
                        
                        <p>Document details:</p>
                        
                        <div class='info-row'>
                            <span class='label'>Document Name:</span>
                            <span>" . h($document['document_name'] ?: $document['file_name']) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Document Type:</span>
                            <span>" . h($document['document_type_name'] ?: '-') . "</span>
                        </div>
                        
                        " . ($document['document_number'] ? "
                        <div class='info-row'>
                            <span class='label'>Document Number:</span>
                            <span>" . h($document['document_number']) . "</span>
                        </div>
                        " : "") . "
                        
                        <div class='info-row'>
                            <span class='label'>Related To:</span>
                            <span>" . h($document['related_name'] ?: '-') . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Expiry Date:</span>
                            <span>" . date('M d, Y', strtotime($document['expiry_date'])) . "</span>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Days " . ($daysUntilExpiry < 0 ? "Expired" : "Until Expiry") . ":</span>
                            <span><strong>" . abs($daysUntilExpiry) . " days</strong></span>
                        </div>
                        
                        <a href='{$viewLink}' class='button'>View Compliance Dashboard</a>
                        
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
            'document_expiry', 
            $subject, 
            $htmlBody, 
            $recipients,
            $documentId,
            'document'
        );
        
        // Log alert
        $logStmt = $conn->prepare("
            INSERT INTO re_document_expiry_alerts
            (company_id, document_id, alert_sent_date, alert_sent_to, days_before_expiry, expiry_date, status)
            VALUES (?, ?, CURDATE(), ?, ?, ?, 'sent')
        ");
        $logStmt->execute([
            $companyId, $documentId, implode(', ', $recipients), 
            $daysUntilExpiry, $document['expiry_date']
        ]);
        
        // Update document
        $conn->prepare("
            UPDATE re_documents 
            SET expiry_alert_sent = 1,
                is_expired = CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END,
                status = CASE WHEN expiry_date < CURDATE() THEN 'expired' ELSE 'active' END
            WHERE id = ?
        ")->execute([$documentId]);
        
        return ['success' => true, 'message' => 'Alert sent successfully', 'recipients' => $recipients];
    }
}

if (!function_exists('check_and_send_document_expiry_alerts')) {
    /**
     * Check all documents and send expiry alerts
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @return array Results
     */
    function check_and_send_document_expiry_alerts(PDO $conn, int $companyId): array {
        // Get documents expiring soon or expired
        $documents = $conn->prepare("
            SELECT 
                d.id,
                d.expiry_date,
                dt.alert_days_before_expiry,
                DATEDIFF(d.expiry_date, CURDATE()) as days_until_expiry
            FROM re_documents d
            LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
            WHERE d.company_id = ?
            AND d.expiry_date IS NOT NULL
            AND d.status = 'active'
            AND (
                d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL COALESCE(dt.alert_days_before_expiry, 30) DAY)
                OR d.expiry_date < CURDATE()
            )
            AND (d.expiry_alert_sent = 0 OR d.expiry_alert_sent IS NULL)
        ");
        $documents->execute([$companyId]);
        $documents = $documents->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [
            'total' => count($documents),
            'sent' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        foreach ($documents as $doc) {
            $result = send_document_expiry_alert($conn, $companyId, $doc['id']);
            if ($result['success']) {
                $results['sent']++;
            } else {
                $results['skipped']++;
                $results['errors'][] = $result['message'];
            }
        }
        
        return $results;
    }
}

if (!function_exists('update_unit_compliance_status')) {
    /**
     * Update unit compliance status based on documents
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param int $unitId Unit ID
     * @return array Result
     */
    function update_unit_compliance_status(PDO $conn, int $companyId, int $unitId): array {
        // Get all documents for unit
        $documents = $conn->prepare("
            SELECT 
                d.*,
                dt.is_required
            FROM re_documents d
            LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
            WHERE d.company_id = ?
            AND (
                (d.related_type = 'unit' AND d.related_id = ?)
                OR (d.related_type = 'lease' AND d.related_id IN (SELECT id FROM re_leases WHERE unit_id = ?))
            )
            AND d.status = 'active'
        ");
        $documents->execute([$companyId, $unitId, $unitId]);
        $documents = $documents->fetchAll(PDO::FETCH_ASSOC);
        
        // Get missing documents
        $missingDocs = $conn->prepare("
            SELECT COUNT(*) as count
            FROM re_missing_documents
            WHERE company_id = ? AND related_type = 'unit' AND related_id = ? 
            AND status IN ('missing', 'pending_upload') AND is_required = 1
        ");
        $missingDocs->execute([$companyId, $unitId]);
        $missingRequired = (int)$missingDocs->fetchColumn();
        
        // Calculate compliance score
        $totalDocs = count($documents);
        $expiredDocs = count(array_filter($documents, function($d) { 
            return $d['expiry_date'] && strtotime($d['expiry_date']) < time(); 
        }));
        
        $complianceScore = max(0, min(100, 
            100 - ($expiredDocs * 20) - ($missingRequired * 30)
        ));
        
        // Determine legal status
        $legalStatus = 'compliant';
        if ($missingRequired > 0 || $expiredDocs > 0) {
            $legalStatus = $complianceScore < 50 ? 'non_compliant' : 'at_risk';
        }
        
        // Update or insert
        $check = $conn->prepare("SELECT id FROM re_unit_legal_status WHERE unit_id = ? AND company_id = ?");
        $check->execute([$unitId, $companyId]);
        $existing = $check->fetch();
        
        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE re_unit_legal_status
                SET legal_status = ?,
                    compliance_score = ?
                WHERE id = ?
            ");
            $stmt->execute([$legalStatus, $complianceScore, $existing['id']]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO re_unit_legal_status
                (company_id, unit_id, legal_status, compliance_score)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$companyId, $unitId, $legalStatus, $complianceScore]);
        }
        
        return [
            'success' => true,
            'legal_status' => $legalStatus,
            'compliance_score' => $complianceScore
        ];
    }
}

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

