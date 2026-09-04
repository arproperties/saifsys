<?php
/**
 * Real Estate Maintenance Photo Upload Handler (AJAX)
 */

ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/maintenance_photo_helper.php';

ob_clean();

require_login();
require_module_access($conn, MODULE_REALESTATE);

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

    if (!isset($_FILES['photo'])) {
        throw new RuntimeException('No photo uploaded');
    }

    $maintenanceRequestId = (int)($_POST['maintenance_request_id'] ?? 0);
    $photoType = (string)($_POST['photo_type'] ?? 'completion');
    $description = trim((string)($_POST['description'] ?? ''));

    $res = re_maint_store_photo(
        $conn,
        $currentCompanyId,
        $maintenanceRequestId,
        $_FILES['photo'],
        $photoType,
        $description !== '' ? $description : null,
        $userId
    );
    if (empty($res['ok'])) {
        throw new RuntimeException($res['error'] ?? 'Upload failed');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Photo uploaded successfully',
        'photo' => [
            'id' => $res['photo_id'],
            'file_name' => $_FILES['photo']['name'] ?? '',
            'file_path' => $res['file_path'],
            'photo_type' => $photoType,
            'description' => $description,
            'uploaded_at' => date('Y-m-d H:i:s'),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
