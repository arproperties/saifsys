<?php
/**
 * Real Estate — upload public unit listing media for Find Your Home.
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
$mediaType = (string)($_POST['media_type'] ?? 'photo');
if ($unitId <= 0 || !in_array($mediaType, ['photo', 'floor_plan'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}
if (!re_unit_public_unit_allowed($conn, $currentCompanyId, $unitId)) {
    echo json_encode(['success' => false, 'error' => 'Unit not found']);
    exit;
}

$files = $_FILES['media'] ?? null;
if (!$files) {
    echo json_encode(['success' => false, 'error' => 'No files selected']);
    exit;
}

$uploaded = 0;
$errors = [];
$isMulti = is_array($files['name'] ?? null);
$count = $isMulti ? count($files['name']) : 1;
for ($i = 0; $i < $count; $i++) {
    $file = [
        'name' => $isMulti ? ($files['name'][$i] ?? '') : ($files['name'] ?? ''),
        'type' => $isMulti ? ($files['type'][$i] ?? '') : ($files['type'] ?? ''),
        'tmp_name' => $isMulti ? ($files['tmp_name'][$i] ?? '') : ($files['tmp_name'] ?? ''),
        'error' => $isMulti ? ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => $isMulti ? ($files['size'][$i] ?? 0) : ($files['size'] ?? 0),
    ];
    $failure = null;
    if (re_unit_public_save_uploaded_media($conn, $currentCompanyId, $unitId, $mediaType, $file, current_user_id(), $failure) !== null) {
        $uploaded++;
    } elseif ($failure) {
        $errors[] = $file['name'] ? $file['name'] . ': ' . $failure : $failure;
    }
}

echo json_encode([
    'success' => $uploaded > 0,
    'uploaded' => $uploaded,
    'error' => $uploaded > 0 ? null : ($errors[0] ?? 'No valid files were uploaded'),
    'errors' => $errors,
]);
exit;
