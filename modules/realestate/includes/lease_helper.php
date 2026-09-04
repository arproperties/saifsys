<?php
/**
 * Real Estate Module - Lease Helper Functions
 */

if (!function_exists('send_lease_expiry_reminder')) {
    /**
     * Send expiry reminder for a lease
     * @param PDO $conn Database connection
     * @param int $leaseId Lease ID
     * @param string $reminderType Type of reminder (30_days, 15_days, 7_days, 1_day, expired)
     * @param bool $sendToManagement Send to management
     * @param bool $sendToTenant Send to tenant
     * @return array Result with success status and messages
     */
    function send_lease_expiry_reminder(PDO $conn, int $leaseId, string $reminderType, bool $sendToManagement = true, bool $sendToTenant = true): array {
        // Email helper lives in the SAME directory as this file
        // Using __DIR__ . '/re_email_helper.php' works both locally and on the live server.
        require_once __DIR__ . '/re_email_helper.php';
        
        // Get lease details
        $stmt = $conn->prepare("
            SELECT l.*, 
                   u.unit_number, u.unit_type,
                   b.name as building_name, b.address as building_address,
                   t.first_name, t.last_name, t.email, t.phone
            FROM re_leases l
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE l.id = ?
        ");
        $stmt->execute([$leaseId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lease) {
            return ['success' => false, 'message' => 'Lease not found'];
        }
        
        $result = ['success' => true, 'messages' => []];
        $reminderDate = date('Y-m-d');
        
        // Days until expiry (calendar days, consistent with SQL DATEDIFF on lease end)
        $endDate = new DateTime(date('Y-m-d', strtotime((string)$lease['end_date'])));
        $today = new DateTime(date('Y-m-d'));
        $daysUntilExpiry = $today->diff($endDate)->days;
        if ($endDate < $today) {
            $daysUntilExpiry = -$daysUntilExpiry;
        }
        
        // Send to management
        if ($sendToManagement) {
            // Get management emails (users with real estate access)
            $stmtUsers = $conn->prepare("
                SELECT DISTINCT u.email, u.username
                FROM user u
                JOIN user_companies uc ON uc.user_id = u.id
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id
                WHERE uc.company_id = ? 
                AND (r.module = 'realestate' OR r.name IN ('Owner', 'Admin'))
                AND u.email IS NOT NULL AND u.email != ''
            ");
            $stmtUsers->execute([$lease['company_id']]);
            $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
            
            $subject = "Lease Expiry Reminder: {$lease['lease_number']} - {$daysUntilExpiry} days remaining";
            $body = "
                <h3>Lease Expiry Reminder</h3>
                <p><strong>Lease Number:</strong> {$lease['lease_number']}</p>
                <p><strong>Unit:</strong> {$lease['building_name']} - {$lease['unit_number']}</p>
                <p><strong>Tenant:</strong> {$lease['first_name']} {$lease['last_name']}</p>
                <p><strong>End Date:</strong> {$lease['end_date']}</p>
                <p><strong>Days Remaining:</strong> {$daysUntilExpiry} days</p>
                <p><strong>Reminder Type:</strong> " . ucfirst(str_replace('_', ' ', $reminderType)) . "</p>
                <p><a href='" . getBaseUrl() . "/modules/realestate/lease_view.php?id={$leaseId}'>View Lease Details</a></p>
            ";
            
            $sentCount = 0;
            foreach ($users as $user) {
                if (send_re_email($user['email'], $subject, $body)) {
                    $sentCount++;
                }
            }
            
            if ($sentCount > 0) {
                $result['messages'][] = "Sent to {$sentCount} management user(s)";
                // Record reminder - check if exists first
                $stmtCheck = $conn->prepare("SELECT id FROM re_lease_expiry_reminders WHERE lease_id = ? AND reminder_date = ? AND reminder_type = ?");
                $stmtCheck->execute([$leaseId, $reminderDate, $reminderType]);
                $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $stmtReminder = $conn->prepare("
                        UPDATE re_lease_expiry_reminders 
                        SET sent_to_management = 1,
                            management_sent_at = IF(management_sent_at IS NULL, NOW(), management_sent_at)
                        WHERE id = ?
                    ");
                    $stmtReminder->execute([$existing['id']]);
                } else {
                    $stmtReminder = $conn->prepare("
                        INSERT INTO re_lease_expiry_reminders 
                        (lease_id, reminder_date, reminder_type, sent_to_management, management_sent_at)
                        VALUES (?, ?, ?, 1, NOW())
                    ");
                    $stmtReminder->execute([$leaseId, $reminderDate, $reminderType]);
                }
            }
        }
        
        // Send to tenant
        if ($sendToTenant && !empty($lease['email'])) {
            $subject = "Lease Renewal Reminder: {$lease['lease_number']}";
            $body = "
                <h3>Lease Renewal Reminder</h3>
                <p>Dear {$lease['first_name']} {$lease['last_name']},</p>
                <p>This is a reminder that your lease agreement <strong>{$lease['lease_number']}</strong> for unit <strong>{$lease['building_name']} - {$lease['unit_number']}</strong> will expire on <strong>{$lease['end_date']}</strong>.</p>
                <p><strong>Days Remaining:</strong> {$daysUntilExpiry} days</p>
                <p>Please contact us to discuss renewal options or schedule a move-out inspection.</p>
                <p>Thank you,<br>Property Management Team</p>
            ";
            
            if (send_re_email($lease['email'], $subject, $body)) {
                $result['messages'][] = "Sent to tenant: {$lease['email']}";
                // Record reminder - check if exists first
                $stmtCheck = $conn->prepare("SELECT id FROM re_lease_expiry_reminders WHERE lease_id = ? AND reminder_date = ? AND reminder_type = ?");
                $stmtCheck->execute([$leaseId, $reminderDate, $reminderType]);
                $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $stmtReminder = $conn->prepare("
                        UPDATE re_lease_expiry_reminders 
                        SET sent_to_tenant = 1,
                            tenant_sent_at = IF(tenant_sent_at IS NULL, NOW(), tenant_sent_at)
                        WHERE id = ?
                    ");
                    $stmtReminder->execute([$existing['id']]);
                } else {
                    $stmtReminder = $conn->prepare("
                        INSERT INTO re_lease_expiry_reminders 
                        (lease_id, reminder_date, reminder_type, sent_to_tenant, tenant_sent_at)
                        VALUES (?, ?, ?, 1, NOW())
                    ");
                    $stmtReminder->execute([$leaseId, $reminderDate, $reminderType]);
                }
            }
        }
        
        return $result;
    }
}

if (!function_exists('auto_check_lease_expiry')) {
    /**
     * Automatically check and update expired leases
     * @param PDO $conn Database connection
     * @return array Result with updated count
     */
    function auto_check_lease_expiry(PDO $conn): array {
        // Update leases that have expired (end_date < today and status is 'active')
        $stmt = $conn->prepare("
            UPDATE re_leases 
            SET status = 'expired'
            WHERE status = 'active' 
            AND end_date < CURDATE()
        ");
        $stmt->execute();
        $updatedCount = $stmt->rowCount();
        
        return ['success' => true, 'updated_count' => $updatedCount];
    }
}

if (!function_exists('generate_expiry_reminders')) {
    /**
     * Generate expiry reminder records for leases expiring soon
     * @param PDO $conn Database connection
     * @param int $daysBefore Days before expiry to generate reminders (default 30)
     * @return array Result with generated count
     */
    function generate_expiry_reminders(PDO $conn, int $daysBefore = 30): array {
        // Find active leases expiring in $daysBefore days
        $stmt = $conn->prepare("
            SELECT l.id, l.end_date, DATE_SUB(l.end_date, INTERVAL ? DAY) as reminder_date
            FROM re_leases l
            WHERE l.status = 'active'
            AND l.end_date >= CURDATE()
            AND DATE_SUB(l.end_date, INTERVAL ? DAY) <= CURDATE()
            AND NOT EXISTS (
                SELECT 1 FROM re_lease_expiry_reminders ler
                WHERE ler.lease_id = l.id 
                AND ler.reminder_type = '30_days'
            )
        ");
        $stmt->execute([$daysBefore, $daysBefore]);
        $leases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $generated = 0;
        foreach ($leases as $lease) {
            $daysUntilExpiry = (new DateTime($lease['end_date']))->diff(new DateTime())->days;
            $reminderType = '30_days';
            if ($daysUntilExpiry <= 1) {
                $reminderType = '1_day';
            } elseif ($daysUntilExpiry <= 7) {
                $reminderType = '7_days';
            } elseif ($daysUntilExpiry <= 15) {
                $reminderType = '15_days';
            }
            
            $insert = $conn->prepare("
                INSERT INTO re_lease_expiry_reminders (lease_id, reminder_date, reminder_type)
                VALUES (?, ?, ?)
            ");
            $insert->execute([$lease['id'], date('Y-m-d'), $reminderType]);
            $generated++;
        }
        
        return ['success' => true, 'generated_count' => $generated];
    }
}

if (!function_exists('getBaseUrl')) {
    function getBaseUrl(): string {
        $env = getenv('LEASE_REMINDER_BASE_URL');
        if (is_string($env) && trim($env) !== '') {
            return rtrim(trim($env), '/');
        }
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')));
        return "{$protocol}://{$host}{$script}";
    }
}

