<?php
/**
 * AJAX endpoint to get units for a building
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/includes/re_task_access.php';

require_login();
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    if (!re_tasks_user_can_view_shared($conn, (int)current_user_id())) {
        http_response_code(403);
        exit('Forbidden');
    }
}

header('Content-Type: application/json');

$buildingId = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$floorId = !empty($_GET['floor_id']) ? (int)$_GET['floor_id'] : 0;
$currentCompanyId = current_company_id($conn) ?: 1;

if (!$buildingId) {
    echo json_encode(['success' => false, 'message' => 'Building ID required']);
    exit;
}

try {
    $sql = "
        SELECT id, unit_number, floor_id
        FROM re_units
        WHERE building_id = ? AND company_id = ?
    ";
    $params = [$buildingId, $currentCompanyId];
    if ($floorId > 0) {
        $sql .= " AND floor_id = ?";
        $params[] = $floorId;
    }
    $sql .= " ORDER BY unit_number";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'units' => $units]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Could not load units']);
}

