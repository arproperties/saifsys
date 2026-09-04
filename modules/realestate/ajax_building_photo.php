<?php
/**
 * AJAX: upload / replace / remove building primary photo.
 */

declare(strict_types=1);

ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/re_building_photo_helper.php';

ob_clean();

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

header('Content-Type: application/json');

$currentCompanyId = (int)(current_company_id($conn) ?: 0);
$userId = current_user_id();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method');
    }
    if ($currentCompanyId <= 0) {
        throw new RuntimeException('Company context is required');
    }
    if (!csrf_verify(false)) {
        throw new RuntimeException('CSRF token invalid or missing');
    }

    re_building_photo_ensure_schema($conn);

    $action = (string)($_POST['action'] ?? '');
    $buildingId = (int)($_POST['building_id'] ?? 0);
    if ($buildingId <= 0) {
        throw new RuntimeException('Building id is required');
    }

    if ($action === 'upload' || $action === 'replace') {
        if (!isset($_FILES['photo'])) {
            throw new RuntimeException('No photo uploaded');
        }
        $res = re_building_photo_store($conn, $currentCompanyId, $buildingId, $_FILES['photo'], $userId);
        if (empty($res['ok'])) {
            throw new RuntimeException($res['error'] ?? 'Upload failed');
        }
        echo json_encode([
            'success' => true,
            'message' => 'Building photo saved',
            'photo' => [
                'path' => $res['path'],
                'public_path' => $res['public_path'],
                'mime' => $res['mime'],
                'url' => $res['url'],
            ],
        ]);
        exit;
    }

    if ($action === 'remove') {
        $res = re_building_photo_remove($conn, $currentCompanyId, $buildingId);
        if (empty($res['ok'])) {
            throw new RuntimeException($res['error'] ?? 'Remove failed');
        }
        echo json_encode([
            'success' => true,
            'message' => 'Building photo removed',
        ]);
        exit;
    }

    throw new RuntimeException('Unknown action');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
