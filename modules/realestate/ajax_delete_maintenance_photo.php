<?php
/**
 * Real Estate Maintenance Photo Delete Handler (AJAX)
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $photoId = (int)($_POST['photo_id'] ?? 0);
    
    if ($photoId <= 0) {
        throw new Exception('Photo ID is required');
    }
    
    // Get photo details
    $stmt = $conn->prepare("
        SELECT mp.* 
        FROM re_maintenance_photos mp
        WHERE mp.id = ? AND mp.company_id = ?
    ");
    $stmt->execute([$photoId, $currentCompanyId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$photo) {
        throw new Exception('Photo not found or access denied');
    }
    
    // Delete file
    $filePath = __DIR__ . '/../../' . $photo['file_path'];
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
    
    // Delete from database
    $stmt = $conn->prepare("DELETE FROM re_maintenance_photos WHERE id = ? AND company_id = ?");
    $stmt->execute([$photoId, $currentCompanyId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Photo deleted successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

