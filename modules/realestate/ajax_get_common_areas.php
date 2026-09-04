<?php
/**
 * AJAX: active common areas for a building (company-scoped).
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/maintenance_location_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

header('Content-Type: application/json');

$companyId = (int)(current_company_id($conn) ?: 0);
$buildingId = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;

if ($companyId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Company context is required']);
    exit;
}
if ($buildingId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Building ID required']);
    exit;
}

try {
    $areas = re_maint_load_common_areas_for_building($conn, $companyId, $buildingId, true);
    echo json_encode(['success' => true, 'common_areas' => $areas]);
} catch (Throwable $e) {
    error_log('ajax_get_common_areas: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load common areas']);
}
