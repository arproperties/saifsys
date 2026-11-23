<?php
/**
 * Compliance Helper Functions
 * Document expiry alerts and compliance tracking
 */

require_once __DIR__ . '/re_email_helper.php';
require_once __DIR__ . '/document_watch_helper.php';
require_once __DIR__ . '/document_expiry_digest.php';

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

        // Only documents that still matter (active lease, current copy). See document_watch_helper.php
        $reason = re_doc_watch_reasons($conn, $companyId, [$documentId])[$documentId] ?? 'no_expiry';
        if ($reason !== null) {
            return ['success' => false, 'message' => "Not tracked ({$reason})"];
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
        $daysUntilExpiry = (int)round((strtotime(substr((string)$document['expiry_date'], 0, 10)) - strtotime('today')) / 86400);
        
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
                is_expired = CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END
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
    function check_and_send_document_expiry_alerts(PDO $conn, int $companyId, bool $force = false): array {
        // One summary e-mail grouped by lease (not one e-mail per document).
        // See includes/document_expiry_digest.php
        return send_document_expiry_digest($conn, $companyId, $force);
    }
}

if (!function_exists('re_unit_compliance_evaluate_many')) {
    /**
     * Work out compliance for several units from their documents, using the shared
     * watch rule (document_watch_helper.php), so a renewed Ejari / visa / ID never
     * leaves the old copy counting as expired. This is the ONLY place the unit score
     * and status are worked out; re_unit_compliance_evaluate() is the one-unit form.
     *
     * Documents of a unit: its own, every lease on it, and the current tenant's own
     * documents. Papers of closed old leases drop out through the watch rule.
     * Score: 100 - 20 per expired - 30 per missing required. Anything expired or
     * missing -> below 50 non_compliant, else at_risk. Nothing wrong -> compliant.
     *
     * @param int[] $unitIds
     * @return array<int, array> keyed by unit id
     */
    function re_unit_compliance_evaluate_many(PDO $conn, int $companyId, array $unitIds): array {
        $unitIds = array_values(array_unique(array_filter(array_map('intval', $unitIds))));
        if (!$unitIds) {
            return [];
        }
        $in = implode(',', $unitIds); // ints only

        // Current (active) lease of each unit: latest start wins.
        $active = [];
        $st = $conn->prepare("SELECT id, unit_id, tenant_id FROM re_leases
                              WHERE company_id = ? AND unit_id IN ({$in}) AND status = 'active' AND deleted_at IS NULL
                              ORDER BY start_date DESC, id DESC");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $active[(int)$l['unit_id']] = $active[(int)$l['unit_id']] ?? $l;
        }
        $leaseUnit = [];
        $st = $conn->prepare("SELECT id, unit_id FROM re_leases WHERE company_id = ? AND unit_id IN ({$in})");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $leaseUnit[(int)$l['id']] = (int)$l['unit_id'];
        }
        $tenantUnits = [];
        foreach ($active as $uid => $l) {
            $tenantUnits[(int)$l['tenant_id']][] = $uid;
        }

        $out = [];
        foreach ($unitIds as $uid) {
            $out[$uid] = ['documents' => [], 'missing' => [], 'active_lease' => $active[$uid] ?? null];
        }

        $leaseIds = array_keys($leaseUnit);
        $tenantIds = array_keys($tenantUnits);
        $st = $conn->prepare("
            SELECT d.*, dt.document_type_name, dt.is_required,
                   DATEDIFF(d.expiry_date, CURDATE()) AS days_until_expiry,
                   " . re_doc_unwatched_reason_sql('d') . " AS watch_reason
            FROM re_documents d
            LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
            WHERE d.company_id = ?
              AND d.status = 'active'
              AND (   (d.related_type = 'unit'   AND d.related_id IN ({$in}))
                   OR (d.related_type = 'lease'  AND d.related_id IN (" . ($leaseIds ? implode(',', $leaseIds) : '0') . "))
                   OR (d.related_type = 'tenant' AND d.related_id IN (" . ($tenantIds ? implode(',', array_map('intval', $tenantIds)) : '0') . ")))
            ORDER BY d.expiry_date IS NULL, d.expiry_date ASC, d.id ASC
        ");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $reason = $d['watch_reason'];
            if ($reason === 'lease_closed') {
                continue; // a closed old lease's papers are history, not this unit's paperwork
            }
            $d['is_watched'] = ($reason === null);
            $d['is_replaced'] = ($reason === 'replaced');
            $days = $d['days_until_expiry'] !== null ? (int)$d['days_until_expiry'] : null;
            $d['is_expired'] = $d['is_watched'] && $days !== null && $days < 0;
            $d['is_expiring'] = $d['is_watched'] && $days !== null && $days >= 0 && $days <= 30;

            $rid = (int)$d['related_id'];
            if ($d['related_type'] === 'unit') {
                $targets = [$rid];
            } elseif ($d['related_type'] === 'lease') {
                $targets = isset($leaseUnit[$rid]) ? [$leaseUnit[$rid]] : [];
            } else {
                $targets = $tenantUnits[$rid] ?? [];
            }
            foreach ($targets as $uid) {
                if (isset($out[$uid])) {
                    $out[$uid]['documents'][] = $d;
                }
            }
        }

        $activeLeaseUnit = [];
        foreach ($active as $uid => $l) {
            $activeLeaseUnit[(int)$l['id']] = $uid;
        }
        $st = $conn->prepare("
            SELECT md.*, dt.document_type_name
            FROM re_missing_documents md
            JOIN re_document_types dt ON dt.id = md.document_type_id
            WHERE md.company_id = ?
              AND md.status IN ('missing', 'pending_upload')
              AND (   (md.related_type = 'unit'  AND md.related_id IN ({$in}))
                   OR (md.related_type = 'lease' AND md.related_id IN (" . ($activeLeaseUnit ? implode(',', array_keys($activeLeaseUnit)) : '0') . ")))
            ORDER BY md.is_required DESC, dt.document_type_name
        ");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $uid = $m['related_type'] === 'unit' ? (int)$m['related_id'] : ($activeLeaseUnit[(int)$m['related_id']] ?? 0);
            if (isset($out[$uid])) {
                $out[$uid]['missing'][] = $m;
            }
        }

        foreach ($out as $uid => &$ev) {
            $activeLeaseId = $ev['active_lease'] ? (int)$ev['active_lease']['id'] : 0;
            // Current lease's papers first, then by expiry.
            usort($ev['documents'], static function ($a, $b) use ($activeLeaseId) {
                $ac = ($a['related_type'] === 'lease' && (int)$a['related_id'] === $activeLeaseId) ? 0 : 1;
                $bc = ($b['related_type'] === 'lease' && (int)$b['related_id'] === $activeLeaseId) ? 0 : 1;
                if ($ac !== $bc) return $ac <=> $bc;
                $ae = $a['expiry_date'] ?? '9999-12-31';
                $be = $b['expiry_date'] ?? '9999-12-31';
                return $ae <=> $be ?: ((int)$a['id'] <=> (int)$b['id']);
            });
            $ev['expired'] = count(array_filter($ev['documents'], static function ($d) { return $d['is_expired']; }));
            $ev['expiring'] = count(array_filter($ev['documents'], static function ($d) { return $d['is_expiring']; }));
            $ev['missing_required'] = count(array_filter($ev['missing'], static function ($m) { return (int)$m['is_required'] === 1; }));
            $ev['score'] = max(0, min(100, 100 - ($ev['expired'] * 20) - ($ev['missing_required'] * 30)));
            $ev['legal_status'] = ($ev['expired'] > 0 || $ev['missing_required'] > 0)
                ? ($ev['score'] < 50 ? 'non_compliant' : 'at_risk')
                : 'compliant';
        }
        unset($ev);
        return $out;
    }
}

if (!function_exists('re_unit_compliance_evaluate')) {
    /** One unit's compliance (see re_unit_compliance_evaluate_many). */
    function re_unit_compliance_evaluate(PDO $conn, int $companyId, int $unitId): array {
        return re_unit_compliance_evaluate_many($conn, $companyId, [$unitId])[$unitId];
    }
}

if (!function_exists('re_unit_compliance_save')) {
    /** Store an evaluation in re_unit_legal_status; review notes / dates are left as they are. */
    function re_unit_compliance_save(PDO $conn, int $companyId, int $unitId, array $ev): void {
        $check = $conn->prepare("SELECT id, legal_status, compliance_score FROM re_unit_legal_status WHERE unit_id = ? AND company_id = ?");
        $check->execute([$unitId, $companyId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['legal_status'] !== $ev['legal_status'] || (string)$row['compliance_score'] !== (string)$ev['score']) {
                $conn->prepare("UPDATE re_unit_legal_status SET legal_status = ?, compliance_score = ? WHERE id = ?")
                     ->execute([$ev['legal_status'], $ev['score'], (int)$row['id']]);
            }
        } else {
            $conn->prepare("INSERT INTO re_unit_legal_status (company_id, unit_id, legal_status, compliance_score) VALUES (?, ?, ?, ?)")
                 ->execute([$companyId, $unitId, $ev['legal_status'], $ev['score']]);
        }
    }
}

if (!function_exists('update_unit_compliance_status')) {
    /** Recalculate and save one unit's legal status + score. */
    function update_unit_compliance_status(PDO $conn, int $companyId, int $unitId): array {
        $ev = re_unit_compliance_evaluate($conn, $companyId, $unitId);
        re_unit_compliance_save($conn, $companyId, $unitId, $ev);
        return [
            'success' => true,
            'legal_status' => $ev['legal_status'],
            'compliance_score' => $ev['score'],
            'evaluation' => $ev,
        ];
    }
}

if (!function_exists('re_refresh_unit_compliance_statuses')) {
    /**
     * Recalculate the given units (default: every unit that already has a legal
     * status row) in one pass. Keeps the dashboard count and list current.
     * @param int[]|null $unitIds
     * @return int units recalculated
     */
    function re_refresh_unit_compliance_statuses(PDO $conn, int $companyId, ?array $unitIds = null): int {
        if ($unitIds === null) {
            $st = $conn->prepare("SELECT unit_id FROM re_unit_legal_status WHERE company_id = ?");
            $st->execute([$companyId]);
            $unitIds = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        $n = 0;
        foreach (array_chunk(array_map('intval', $unitIds), 300) as $chunk) {
            foreach (re_unit_compliance_evaluate_many($conn, $companyId, $chunk) as $uid => $ev) {
                re_unit_compliance_save($conn, $companyId, (int)$uid, $ev);
                $n++;
            }
        }
        return $n;
    }
}

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

