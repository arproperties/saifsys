<?php
/**
 * SLA (Service Level Agreement) Helper Functions
 * Handles SLA tracking for maintenance requests
 */

if (!function_exists('get_sla_rule')) {
    /**
     * Get SLA rule for a given priority and category
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param string $priority Priority level (low, medium, high, urgent)
     * @param string|null $category Category (optional)
     * @return array|null SLA rule or null if not found
     */
    function get_sla_rule(PDO $conn, int $companyId, string $priority, ?string $category = null): ?array {
        // First try to find rule with specific category
        if ($category) {
            $stmt = $conn->prepare("
                SELECT * FROM re_sla_rules 
                WHERE company_id = ? AND priority = ? AND category = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$companyId, $priority, $category]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rule) {
                return $rule;
            }
        }
        
        // Fallback to general rule (category = NULL)
        $stmt = $conn->prepare("
            SELECT * FROM re_sla_rules 
            WHERE company_id = ? AND priority = ? AND category IS NULL AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$companyId, $priority]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('create_sla_tracking')) {
    /**
     * Create SLA tracking record for a maintenance request
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param int $maintenanceRequestId Maintenance request ID
     * @param string $priority Priority level
     * @param string|null $category Category
     * @param string $requestDate Request creation date/time
     * @return int|null SLA tracking ID or null on failure
     */
    function create_sla_tracking(
        PDO $conn, 
        int $companyId, 
        int $maintenanceRequestId, 
        string $priority, 
        ?string $category, 
        string $requestDate
    ): ?int {
        // Get SLA rule
        $slaRule = get_sla_rule($conn, $companyId, $priority, $category);
        
        if (!$slaRule) {
            // No SLA rule configured, skip tracking
            return null;
        }
        
        // Calculate target times
        $requestDateTime = new DateTime($requestDate);
        $targetResponseTime = clone $requestDateTime;
        $targetResponseTime->modify("+{$slaRule['response_time_minutes']} minutes");
        
        $targetResolutionTime = clone $requestDateTime;
        $targetResolutionTime->modify("+{$slaRule['resolution_time_hours']} hours");
        
        // Create SLA tracking record
        $stmt = $conn->prepare("
            INSERT INTO re_sla_tracking 
            (company_id, maintenance_request_id, sla_rule_id, target_response_time, target_resolution_time)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $companyId,
            $maintenanceRequestId,
            $slaRule['id'],
            $targetResponseTime->format('Y-m-d H:i:s'),
            $targetResolutionTime->format('Y-m-d H:i:s')
        ]);
        
        return $conn->lastInsertId();
    }
}

if (!function_exists('update_sla_response_time')) {
    /**
     * Update SLA tracking when request is assigned/responded to
     * 
     * @param PDO $conn Database connection
     * @param int $maintenanceRequestId Maintenance request ID
     * @param string $respondedAt Response timestamp
     * @return bool Success
     */
    function update_sla_response_time(PDO $conn, int $maintenanceRequestId, string $respondedAt): bool {
        // Get SLA tracking record
        $stmt = $conn->prepare("
            SELECT st.*, mr.request_date, mr.priority, mr.category
            FROM re_sla_tracking st
            JOIN re_maintenance_requests mr ON mr.id = st.maintenance_request_id
            WHERE st.maintenance_request_id = ?
            LIMIT 1
        ");
        $stmt->execute([$maintenanceRequestId]);
        $slaTracking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$slaTracking) {
            return false;
        }
        
        // Calculate response time
        $requestDateTime = new DateTime($slaTracking['request_date']);
        $respondedDateTime = new DateTime($respondedAt);
        $responseTimeMinutes = (int)(($respondedDateTime->getTimestamp() - $requestDateTime->getTimestamp()) / 60);
        
        // Check if SLA was met
        $targetResponseTime = new DateTime($slaTracking['target_response_time']);
        $slaMet = $respondedDateTime <= $targetResponseTime ? 1 : 0;
        
        // Update SLA tracking
        $stmt = $conn->prepare("
            UPDATE re_sla_tracking 
            SET actual_response_time = ?,
                response_time_minutes = ?,
                response_sla_met = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$respondedAt, $responseTimeMinutes, $slaMet, $slaTracking['id']]);
        
        // Update maintenance request
        $stmt = $conn->prepare("
            UPDATE re_maintenance_requests 
            SET responded_at = ?,
                response_time_minutes = ?,
                sla_response_met = ?
            WHERE id = ?
        ");
        $stmt->execute([$respondedAt, $responseTimeMinutes, $slaMet, $maintenanceRequestId]);
        
        // Check for SLA violation and send notification if needed
        if ($slaMet === 0 && !$slaTracking['sla_violation_notified']) {
            check_and_notify_sla_violation($conn, $maintenanceRequestId, 'response');
        }
        
        return true;
    }
}

if (!function_exists('update_sla_resolution_time')) {
    /**
     * Update SLA tracking when request is completed
     * 
     * @param PDO $conn Database connection
     * @param int $maintenanceRequestId Maintenance request ID
     * @param string $completedAt Completion timestamp
     * @return bool Success
     */
    function update_sla_resolution_time(PDO $conn, int $maintenanceRequestId, string $completedAt): bool {
        // Get SLA tracking record
        $stmt = $conn->prepare("
            SELECT st.*, mr.request_date
            FROM re_sla_tracking st
            JOIN re_maintenance_requests mr ON mr.id = st.maintenance_request_id
            WHERE st.maintenance_request_id = ?
            LIMIT 1
        ");
        $stmt->execute([$maintenanceRequestId]);
        $slaTracking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$slaTracking) {
            return false;
        }
        
        // Calculate resolution time
        $requestDateTime = new DateTime($slaTracking['request_date']);
        $completedDateTime = new DateTime($completedAt);
        $resolutionTimeHours = round(($completedDateTime->getTimestamp() - $requestDateTime->getTimestamp()) / 3600, 2);
        
        // Check if SLA was met
        $targetResolutionTime = new DateTime($slaTracking['target_resolution_time']);
        $slaMet = $completedDateTime <= $targetResolutionTime ? 1 : 0;
        
        // Update SLA tracking
        $stmt = $conn->prepare("
            UPDATE re_sla_tracking 
            SET actual_resolution_time = ?,
                resolution_time_hours = ?,
                resolution_sla_met = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$completedAt, $resolutionTimeHours, $slaMet, $slaTracking['id']]);
        
        // Update maintenance request
        $stmt = $conn->prepare("
            UPDATE re_maintenance_requests 
            SET resolution_time_hours = ?,
                sla_resolution_met = ?
            WHERE id = ?
        ");
        $stmt->execute([$resolutionTimeHours, $slaMet, $maintenanceRequestId]);
        
        // Check for SLA violation and send notification if needed
        if ($slaMet === 0 && !$slaTracking['sla_violation_notified']) {
            check_and_notify_sla_violation($conn, $maintenanceRequestId, 'resolution');
        }
        
        return true;
    }
}

if (!function_exists('check_and_notify_sla_violation')) {
    /**
     * Check for SLA violation and send notification email
     * 
     * @param PDO $conn Database connection
     * @param int $maintenanceRequestId Maintenance request ID
     * @param string $type 'response' or 'resolution'
     * @return bool Success
     */
    function check_and_notify_sla_violation(PDO $conn, int $maintenanceRequestId, string $type): bool {
        // Get company ID from maintenance request
        $stmt = $conn->prepare("SELECT company_id FROM re_maintenance_requests WHERE id = ?");
        $stmt->execute([$maintenanceRequestId]);
        $companyId = $stmt->fetchColumn();
        
        if (!$companyId) {
            error_log("SLA Violation: Could not find company ID for maintenance request #{$maintenanceRequestId}");
            return false;
        }
        
        // Mark as notified first to prevent duplicate notifications
        $stmt = $conn->prepare("
            UPDATE re_sla_tracking 
            SET sla_violation_notified = 1
            WHERE maintenance_request_id = ?
        ");
        $stmt->execute([$maintenanceRequestId]);
        
        // Send email notification to managers
        require_once __DIR__ . '/re_email_helper.php';
        $result = send_sla_violation_notification($conn, $maintenanceRequestId, $companyId, $type);
        
        if (!$result['success']) {
            error_log("SLA Violation Email Failed: " . ($result['error'] ?? 'Unknown error'));
        }
        
        return $result['success'];
    }
}

if (!function_exists('initialize_default_sla_rules')) {
    /**
     * Initialize default SLA rules for a company
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @return bool Success
     */
    function initialize_default_sla_rules(PDO $conn, int $companyId): bool {
        // Check if rules already exist
        $stmt = $conn->prepare("SELECT COUNT(*) FROM re_sla_rules WHERE company_id = ?");
        $stmt->execute([$companyId]);
        if ($stmt->fetchColumn() > 0) {
            return true; // Rules already exist
        }
        
        // Default SLA rules
        $defaultRules = [
            ['priority' => 'urgent', 'category' => null, 'response_minutes' => 15, 'resolution_hours' => 4],
            ['priority' => 'high', 'category' => null, 'response_minutes' => 30, 'resolution_hours' => 8],
            ['priority' => 'medium', 'category' => null, 'response_minutes' => 120, 'resolution_hours' => 24],
            ['priority' => 'low', 'category' => null, 'response_minutes' => 240, 'resolution_hours' => 48],
        ];
        
        $stmt = $conn->prepare("
            INSERT INTO re_sla_rules 
            (company_id, priority, category, response_time_minutes, resolution_time_hours, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        
        foreach ($defaultRules as $rule) {
            $stmt->execute([
                $companyId,
                $rule['priority'],
                $rule['category'],
                $rule['response_minutes'],
                $rule['resolution_hours']
            ]);
        }
        
        return true;
    }
}

