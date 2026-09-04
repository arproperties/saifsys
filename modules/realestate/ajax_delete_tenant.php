<?php
/**
 * Real Estate Module - AJAX Delete Tenant
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

header('Content-Type: application/json');

require_login();

$currentCompanyId = current_company_id($conn) ?: 1;
$tenantId = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : 0;

if (!$tenantId) {
    echo json_encode(['success' => false, 'error' => 'Tenant ID is required']);
    exit;
}

// Verify CSRF token. The shared auth helper stores it in $_SESSION['_csrf'].
if (!csrf_verify(false)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

try {
    // Check if tenant exists and belongs to current company
    $stmt = $conn->prepare("SELECT id FROM re_tenants WHERE id = ? AND company_id = ?");
    $stmt->execute([$tenantId, $currentCompanyId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tenant) {
        echo json_encode(['success' => false, 'error' => 'Tenant not found']);
        exit;
    }
    
    // Check if tenant has any leases
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM re_leases WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    $leaseCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($leaseCount > 0) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete tenant with existing leases']);
        exit;
    }
    
    // Delete the tenant
    $stmt = $conn->prepare("DELETE FROM re_tenants WHERE id = ? AND company_id = ?");
    $stmt->execute([$tenantId, $currentCompanyId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Tenant deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete tenant']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
