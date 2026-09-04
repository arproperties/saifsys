<?php
/**
 * Real Estate — delete or mark primary public unit listing media.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/unit_public_listing_helper.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
require_module_access($conn, MODULE_REALESTATE);
csrf_verify();

$currentCompanyId = current_company_id($conn) ?: 1;
re_unit_public_ensure_schema($conn);

$unitId = (int)($_POST['unit_id'] ?? 0);
$mediaId = (int)($_POST['media_id'] ?? 0);
$action = (string)($_POST['action'] ?? 'delete');
if ($unitId <= 0 || $mediaId <= 0 || !in_array($action, ['delete', 'primary'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}
if (!re_unit_public_unit_allowed($conn, $currentCompanyId, $unitId)) {
    echo json_encode(['success' => false, 'error' => 'Unit not found']);
    exit;
}

if ($action === 'primary') {
    re_unit_public_set_primary_media($conn, $currentCompanyId, $unitId, $mediaId);
} else {
    re_unit_public_delete_media($conn, $currentCompanyId, $unitId, $mediaId);
}

echo json_encode(['success' => true]);
exit;
