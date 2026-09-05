<?php
/**
 * Preventive Maintenance Helper Functions
 * Enterprise-grade facility management functions
 */

if (!function_exists('calculate_next_due_date')) {
    /**
     * Calculate next due date based on frequency
     * 
     * @param string $frequencyType daily, weekly, monthly, quarterly, semi_annual, annual, custom
     * @param int|null $frequencyValue For custom: number of days
     * @param int|null $frequencyDay Day of week (1-7) or day of month (1-31)
     * @param int|null $frequencyMonth Month (1-12) for annual
     * @param string|null $lastCompletedDate Last completion date (Y-m-d) or null
     * @param string|null $startDate Start date (Y-m-d) or null (uses today)
     * @return string Next due date (Y-m-d)
     */
    function calculate_next_due_date(
        string $frequencyType,
        ?int $frequencyValue = null,
        ?int $frequencyDay = null,
        ?int $frequencyMonth = null,
        ?string $lastCompletedDate = null,
        ?string $startDate = null
    ): string {
        $baseDate = $lastCompletedDate ? new DateTime($lastCompletedDate) : new DateTime($startDate ?: 'today');
        
        switch ($frequencyType) {
            case 'daily':
                $baseDate->modify('+1 day');
                break;
                
            case 'weekly':
                if ($frequencyDay) {
                    // Specific day of week (1=Monday, 7=Sunday)
                    $currentDay = (int)$baseDate->format('N'); // 1-7
                    $daysToAdd = ($frequencyDay - $currentDay + 7) % 7;
                    if ($daysToAdd === 0) $daysToAdd = 7; // Next week
                    $baseDate->modify("+{$daysToAdd} days");
                } else {
                    $baseDate->modify('+1 week');
                }
                break;
                
            case 'monthly':
                if ($frequencyDay) {
                    // Specific day of month (1-31)
                    $baseDate->modify('+1 month');
                    $targetDay = min($frequencyDay, (int)$baseDate->format('t')); // Last day of month if needed
                    $baseDate->setDate((int)$baseDate->format('Y'), (int)$baseDate->format('m'), $targetDay);
                } else {
                    $baseDate->modify('+1 month');
                }
                break;
                
            case 'quarterly':
                $baseDate->modify('+3 months');
                break;
                
            case 'semi_annual':
                $baseDate->modify('+6 months');
                break;
                
            case 'annual':
                if ($frequencyMonth && $frequencyDay) {
                    // Specific month and day (e.g., January 15)
                    $currentYear = (int)$baseDate->format('Y');
                    $currentMonth = (int)$baseDate->format('m');
                    $currentDay = (int)$baseDate->format('d');
                    
                    $targetDate = new DateTime("{$currentYear}-{$frequencyMonth}-{$frequencyDay}");
                    if ($targetDate < $baseDate) {
                        $targetDate->modify('+1 year');
                    }
                    return $targetDate->format('Y-m-d');
                } else {
                    $baseDate->modify('+1 year');
                }
                break;
                
            case 'custom':
                if ($frequencyValue) {
                    $baseDate->modify("+{$frequencyValue} days");
                } else {
                    $baseDate->modify('+30 days'); // Default
                }
                break;
        }
        
        return $baseDate->format('Y-m-d');
    }
}

if (!function_exists('generate_preventive_maintenance_tasks')) {
    /**
     * Generate preventive maintenance tasks from active schedules
     * Should be run daily via cron or scheduled task
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param int $daysAhead How many days ahead to generate tasks (default: 30)
     * @return array Generated task IDs
     */
    function generate_preventive_maintenance_tasks(PDO $conn, int $companyId, int $daysAhead = 30): array {
        $generatedTaskIds = [];
        $cutoffDate = date('Y-m-d', strtotime("+{$daysAhead} days"));
        
        // Get all active schedules
        $stmt = $conn->prepare("
            SELECT * FROM re_preventive_maintenance_schedules 
            WHERE company_id = ? AND is_active = 1
        ");
        $stmt->execute([$companyId]);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($schedules as $schedule) {
            // Calculate next due date
            $nextDueDate = $schedule['next_due_date'];
            
            // If no next_due_date set, calculate from last_completed_date or created_at
            if (!$nextDueDate) {
                $startDate = $schedule['last_completed_date'] ?: $schedule['created_at'];
                $nextDueDate = calculate_next_due_date(
                    $schedule['frequency_type'],
                    $schedule['frequency_value'],
                    $schedule['frequency_day'],
                    $schedule['frequency_month'],
                    $schedule['last_completed_date'],
                    $startDate
                );
            }
            
            // Check if task already exists for this due date
            $checkStmt = $conn->prepare("
                SELECT id FROM re_preventive_maintenance_tasks 
                WHERE schedule_id = ? AND due_date = ? AND status NOT IN ('cancelled', 'completed')
            ");
            $checkStmt->execute([$schedule['id'], $nextDueDate]);
            if ($checkStmt->fetch()) {
                continue; // Task already exists
            }
            
            // Generate tasks up to cutoff date
            $currentDate = new DateTime($nextDueDate);
            $cutoff = new DateTime($cutoffDate);
            
            while ($currentDate <= $cutoff) {
                $dueDate = $currentDate->format('Y-m-d');
                
                // Determine asset, building, unit
                $assetId = $schedule['asset_id'];
                $buildingId = $schedule['building_id'];
                $unitId = null;
                
                if ($assetId) {
                    $assetStmt = $conn->prepare("SELECT building_id, unit_id FROM re_maintenance_assets WHERE id = ?");
                    $assetStmt->execute([$assetId]);
                    $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
                    if ($asset) {
                        $buildingId = $asset['building_id'];
                        $unitId = $asset['unit_id'];
                    }
                }
                
                // Create task
                $taskStmt = $conn->prepare("
                    INSERT INTO re_preventive_maintenance_tasks 
                    (company_id, schedule_id, asset_id, building_id, unit_id, task_name, task_description,
                     due_date, status, assigned_to, priority, category)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
                ");
                
                $taskName = $schedule['schedule_name'] . ' - ' . $dueDate;
                $taskStmt->execute([
                    $companyId,
                    $schedule['id'],
                    $assetId,
                    $buildingId,
                    $unitId,
                    $taskName,
                    $schedule['task_description'],
                    $dueDate,
                    $schedule['assigned_to'],
                    $schedule['priority'],
                    $schedule['category']
                ]);
                
                $generatedTaskIds[] = $conn->lastInsertId();
                
                // Calculate next occurrence
                $currentDate = new DateTime(calculate_next_due_date(
                    $schedule['frequency_type'],
                    $schedule['frequency_value'],
                    $schedule['frequency_day'],
                    $schedule['frequency_month'],
                    $dueDate
                ));
            }
            
            // Update schedule's next_due_date
            $updateStmt = $conn->prepare("
                UPDATE re_preventive_maintenance_schedules 
                SET next_due_date = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$currentDate->format('Y-m-d'), $schedule['id']]);
        }
        
        return $generatedTaskIds;
    }
}

if (!function_exists('create_maintenance_request_from_task')) {
    /**
     * Create a maintenance request from a preventive maintenance task
     * 
     * @param PDO $conn Database connection
     * @param int $taskId Preventive maintenance task ID
     * @param int $userId User creating the request
     * @return int|null Maintenance request ID or null on failure
     */
    function create_maintenance_request_from_task(PDO $conn, int $taskId, int $userId): ?int {
        // Get task details
        $stmt = $conn->prepare("
            SELECT t.*, s.task_description, s.instructions, s.required_parts,
                   a.asset_name, a.asset_type
            FROM re_preventive_maintenance_tasks t
            JOIN re_preventive_maintenance_schedules s ON s.id = t.schedule_id
            LEFT JOIN re_maintenance_assets a ON a.id = t.asset_id
            WHERE t.id = ?
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$task || !$task['building_id']) {
            return null;
        }
        
        // Get unit_id - use task unit_id or find a unit in the building
        $unitId = $task['unit_id'];
        if (!$unitId) {
            $unitStmt = $conn->prepare("SELECT id FROM re_units WHERE building_id = ? AND company_id = ? LIMIT 1");
            $unitStmt->execute([$task['building_id'], $task['company_id']]);
            $unitId = $unitStmt->fetchColumn();
        }
        
        if (!$unitId) {
            return null; // No unit found
        }
        
        // Build description
        $description = "Preventive Maintenance: " . $task['task_description'];
        if ($task['asset_name']) {
            $description .= "\n\nAsset: " . $task['asset_name'];
        }
        if ($task['instructions']) {
            $description .= "\n\nInstructions:\n" . $task['instructions'];
        }
        if ($task['required_parts']) {
            $description .= "\n\nRequired Parts:\n" . $task['required_parts'];
        }
        
        // Create maintenance request
        $requestStmt = $conn->prepare("
            INSERT INTO re_maintenance_requests 
            (company_id, unit_id, building_id, request_date, priority, category,
             description, status, assigned_to, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ");
        
        $requestDate = date('Y-m-d');
        $requestStmt->execute([
            $task['company_id'],
            $unitId,
            $task['building_id'],
            $requestDate,
            $task['priority'],
            $task['category'] ?: 'Preventive Maintenance',
            $description,
            $task['assigned_to'],
            $userId
        ]);
        
        $requestId = $conn->lastInsertId();
        
        // Link task to request
        $linkStmt = $conn->prepare("
            UPDATE re_preventive_maintenance_tasks 
            SET maintenance_request_id = ?, status = 'scheduled'
            WHERE id = ?
        ");
        $linkStmt->execute([$requestId, $taskId]);
        
        
        return $requestId;
    }
}

if (!function_exists('complete_preventive_maintenance_task')) {
    /**
     * Mark a preventive maintenance task as completed
     * 
     * @param PDO $conn Database connection
     * @param int $taskId Task ID
     * @param int $completedBy Employee ID
     * @param array $completionData Completion details
     * @return bool Success
     */
    function complete_preventive_maintenance_task(
        PDO $conn,
        int $taskId,
        int $completedBy,
        array $completionData = []
    ): bool {
        try {
            $conn->beginTransaction();
            
            // Get task details
            $stmt = $conn->prepare("SELECT * FROM re_preventive_maintenance_tasks WHERE id = ?");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$task) {
                throw new Exception("Task not found");
            }
            
            $completedDate = $completionData['completed_date'] ?? date('Y-m-d');
            $duration = $completionData['duration_minutes'] ?? null;
            $cost = $completionData['cost'] ?? 0;
            $notes = $completionData['notes'] ?? '';
            $statusBefore = $completionData['status_before'] ?? null;
            $statusAfter = $completionData['status_after'] ?? null;
            $issuesFound = $completionData['issues_found'] ?? null;
            $partsReplaced = $completionData['parts_replaced'] ?? null;
            $nextServiceDue = $completionData['next_service_due'] ?? null;
            
            // Update task
            $updateStmt = $conn->prepare("
                UPDATE re_preventive_maintenance_tasks 
                SET status = 'completed',
                    completed_date = ?,
                    completed_by = ?,
                    actual_duration_minutes = ?,
                    actual_cost = ?,
                    completion_notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([
                $completedDate,
                $completedBy,
                $duration,
                $cost,
                $notes,
                $taskId
            ]);
            
            // Create history record
            $historyStmt = $conn->prepare("
                INSERT INTO re_preventive_maintenance_history 
                (company_id, task_id, schedule_id, asset_id, maintenance_request_id,
                 performed_date, performed_by, duration_minutes, cost,
                 status_before, status_after, issues_found, parts_replaced,
                 next_service_due, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $historyStmt->execute([
                $task['company_id'],
                $taskId,
                $task['schedule_id'],
                $task['asset_id'],
                $task['maintenance_request_id'],
                $completedDate,
                $completedBy,
                $duration,
                $cost,
                $statusBefore,
                $statusAfter,
                $issuesFound,
                $partsReplaced,
                $nextServiceDue,
                $notes
            ]);
            
            // Update schedule's last_completed_date and next_due_date
            $scheduleStmt = $conn->prepare("
                SELECT * FROM re_preventive_maintenance_schedules WHERE id = ?
            ");
            $scheduleStmt->execute([$task['schedule_id']]);
            $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($schedule) {
                $nextDue = calculate_next_due_date(
                    $schedule['frequency_type'],
                    $schedule['frequency_value'],
                    $schedule['frequency_day'],
                    $schedule['frequency_month'],
                    $completedDate
                );
                
                $updateScheduleStmt = $conn->prepare("
                    UPDATE re_preventive_maintenance_schedules 
                    SET last_completed_date = ?,
                        next_due_date = ?
                    WHERE id = ?
                ");
                $updateScheduleStmt->execute([
                    $completedDate,
                    $nextDue,
                    $task['schedule_id']
                ]);
            }
            
            // Update linked maintenance request if exists
            if ($task['maintenance_request_id']) {
                $requestUpdateStmt = $conn->prepare("
                    UPDATE re_maintenance_requests 
                    SET status = 'completed',
                        completed_at = NOW(),
                        cost = ?
                    WHERE id = ?
                ");
                $requestUpdateStmt->execute([$cost, $task['maintenance_request_id']]);
                
            }
            
            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Error completing preventive maintenance task: " . $e->getMessage());
            return false;
        }
    }
}

