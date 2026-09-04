<?php
/**
 * AJAX endpoint to get active lease for a unit
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

header('Content-Type: application/json');

$unitId = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;

$currentCompanyId = current_company_id($conn) ?: 1;

if ($unitId) {
    $stmt = $conn->prepare("
        SELECT l.id as lease_id, l.lease_number, l.end_date, l.tenant_id,
               t.first_name, t.last_name
        FROM re_leases l
        LEFT JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.unit_id = ? AND l.company_id = ? AND l.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$unitId, $currentCompanyId]);
    $lease = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($lease ?: []);
} else {
    echo json_encode([]);
}

