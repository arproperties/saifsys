<?php
/**
 * Real Estate Maintenance Photos Fetch Handler (AJAX)
 */

// Prevent any output before JSON
ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

// Clear any output
ob_clean();

require_login();
require_module_access($conn, MODULE_REALESTATE);

header('Content-Type: application/json');

$currentCompanyId = current_company_id($conn) ?: 1;

try {
    $maintenanceRequestId = (int)($_GET['maintenance_request_id'] ?? 0);
    
    if ($maintenanceRequestId <= 0) {
        throw new Exception('Maintenance request ID is required');
    }
    
    // Verify maintenance request exists and belongs to company
    $stmt = $conn->prepare("SELECT id FROM re_maintenance_requests WHERE id = ? AND company_id = ?");
    $stmt->execute([$maintenanceRequestId, $currentCompanyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Maintenance request not found or access denied');
    }
    
    // Get photos
    $stmt = $conn->prepare("
        SELECT mp.*, u.username as uploaded_by_name
        FROM re_maintenance_photos mp
        LEFT JOIN user u ON u.id = mp.uploaded_by
        WHERE mp.maintenance_request_id = ? AND mp.company_id = ?
        ORDER BY mp.created_at DESC
    ");
    $stmt->execute([$maintenanceRequestId, $currentCompanyId]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'photos' => $photos
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

