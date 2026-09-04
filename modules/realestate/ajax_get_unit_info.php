<?php
/**
 * AJAX endpoint to get unit information
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

header('Content-Type: application/json');

$unitId = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$currentCompanyId = current_company_id($conn) ?: 1;

if ($unitId) {
    $stmt = $conn->prepare("
        SELECT u.unit_number, u.unit_type, u.building_id, b.name as building_name
        FROM re_units u
        JOIN re_buildings b ON b.id = u.building_id
        WHERE u.id = ? AND u.company_id = ?
    ");
    $stmt->execute([$unitId, $currentCompanyId]);
    $unit = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($unit) {
        $buildingShort = preg_replace('/[^A-Za-z0-9]/', '', $unit['building_name']);
        $buildingShort = strtoupper(substr($buildingShort, 0, 8));
        if ($buildingShort === '') {
            $buildingShort = 'B' . $unit['building_id'];
        }
        $unit['building_short'] = $buildingShort;
        unset($unit['building_id']);
    }
    echo json_encode($unit ?: []);
} else {
    echo json_encode([]);
}

