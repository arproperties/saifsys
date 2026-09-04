<?php
/**
 * AJAX endpoint to get related items for document upload
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

header('Content-Type: application/json');

require_login();
$currentCompanyId = current_company_id($conn) ?: 1;

$type = $_GET['type'] ?? '';

$items = [];

switch ($type) {
    case 'lease':
        $stmt = $conn->prepare("
            SELECT 
                l.id,
                CONCAT(l.lease_number, ' - ', b.name, ' - ', u.unit_number, ' (', t.first_name, ' ', t.last_name, ')') as name
            FROM re_leases l
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE l.company_id = ?
            ORDER BY l.lease_number
        ");
        $stmt->execute([$currentCompanyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'tenant':
        $stmt = $conn->prepare("
            SELECT id, CONCAT(first_name, ' ', last_name) as name
            FROM re_tenants
            WHERE company_id = ?
            ORDER BY last_name, first_name
        ");
        $stmt->execute([$currentCompanyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'unit':
        $stmt = $conn->prepare("
            SELECT u.id, CONCAT(b.name, ' - ', u.unit_number) as name
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id
            WHERE u.company_id = ?
            ORDER BY b.name, u.unit_number
        ");
        $stmt->execute([$currentCompanyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'building':
        $stmt = $conn->prepare("
            SELECT id, name
            FROM re_buildings
            WHERE company_id = ?
            ORDER BY name
        ");
        $stmt->execute([$currentCompanyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'maintenance':
        $stmt = $conn->prepare("
            SELECT 
                mr.id,
                CONCAT('MR-', mr.id, ' - ', b.name, ' - ', u.unit_number) as name
            FROM re_maintenance_requests mr
            JOIN re_units u ON u.id = mr.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            WHERE mr.company_id = ?
            ORDER BY mr.id DESC
        ");
        $stmt->execute([$currentCompanyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'legal_case':
        $stmt = $conn->prepare("
            SELECT id, CONCAT(case_number, ' - ', title) as name
            FROM re_legal_cases
            WHERE company_id = ? AND deleted_at IS NULL
            ORDER BY created_at DESC
        ");
        $stmt->execute([$currentCompanyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
}

echo json_encode($items);

