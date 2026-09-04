<?php
/**
 * AJAX endpoint to get preventive maintenance task details
 */

ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

ob_clean();

require_login();
require_module_access($conn, MODULE_REALESTATE);

header('Content-Type: application/json');

$currentCompanyId = current_company_id($conn) ?: 1;

try {
    $taskId = (int)($_GET['task_id'] ?? 0);
    
    if ($taskId <= 0) {
        throw new Exception('Task ID is required');
    }
    
    $stmt = $conn->prepare("
        SELECT t.*, 
               s.schedule_name, s.task_description as schedule_description, s.instructions, s.required_parts,
               a.asset_name, a.asset_type,
               b.name as building_name, u.unit_number,
               e.full_name as assigned_employee,
               e2.full_name as completed_by_name
        FROM re_preventive_maintenance_tasks t
        JOIN re_preventive_maintenance_schedules s ON s.id = t.schedule_id
        LEFT JOIN re_maintenance_assets a ON a.id = t.asset_id
        LEFT JOIN re_buildings b ON b.id = t.building_id
        LEFT JOIN re_units u ON u.id = t.unit_id
        LEFT JOIN employees e ON e.id = t.assigned_to
        LEFT JOIN employees e2 ON e2.id = t.completed_by
        WHERE t.id = ? AND t.company_id = ?
    ");
    $stmt->execute([$taskId, $currentCompanyId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        throw new Exception('Task not found or access denied');
    }
    
    echo json_encode([
        'success' => true,
        'task' => $task
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

