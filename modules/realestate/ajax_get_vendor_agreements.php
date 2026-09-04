<?php
/**
 * AJAX endpoint to get agreements for a vendor
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

require_login();

header('Content-Type: application/json');

$vendorId = !empty($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
$currentCompanyId = current_company_id($conn) ?: 1;

if (!$vendorId) {
    echo json_encode(['success' => false, 'message' => 'Vendor ID required']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT id, agreement_name, agreement_number 
        FROM re_service_agreements 
        WHERE vendor_id = ? AND company_id = ?
        ORDER BY agreement_name
    ");
    $stmt->execute([$vendorId, $currentCompanyId]);
    $agreements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'agreements' => $agreements]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

