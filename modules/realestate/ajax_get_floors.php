<?php
/**
 * AJAX endpoint to get floors for a building
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

header('Content-Type: application/json');

require_login();
$currentCompanyId = current_company_id($conn) ?: 1;

$buildingId = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;

if ($buildingId) {
    $stmt = $conn->prepare("
        SELECT id, floor_number, name,
               CONCAT(
                   COALESCE(name, CONCAT('Floor ', floor_number)),
                   ' (', floor_number, ')'
               ) as display_name
        FROM re_floors 
        WHERE building_id = ? 
        ORDER BY floor_number
    ");
    $stmt->execute([$buildingId]);
    $floors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($floors);
} else {
    // Get all floors for the company
    $stmt = $conn->prepare("
        SELECT f.id, f.floor_number, f.name, b.name as building_name,
               CONCAT(b.name, ' - ', COALESCE(f.name, CONCAT('Floor ', f.floor_number)), ' (', f.floor_number, ')') as display_name
        FROM re_floors f
        JOIN re_buildings b ON b.id = f.building_id
        WHERE b.company_id = ?
        ORDER BY b.name, f.floor_number
    ");
    $stmt->execute([$currentCompanyId]);
    $floors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($floors);
}

