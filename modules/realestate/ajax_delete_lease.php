<?php
/**
 * Real Estate Module - AJAX Archive Lease (Draft Only)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';

header('Content-Type: application/json');

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = current_company_id($conn) ?: 1;
$leaseId = !empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : 0;
$confirmText = trim((string)($_POST['confirm_text'] ?? ''));
$deleteReason = trim((string)($_POST['delete_reason'] ?? ''));

if (!$leaseId) {
    echo json_encode(['success' => false, 'error' => 'Lease ID is required']);
    exit;
}

// Verify CSRF token
if (!isset($_POST['_csrf']) || !hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

try {
    if ($confirmText !== 'DELETE') {
        echo json_encode(['success' => false, 'error' => 'Type DELETE to confirm archiving this draft lease.']);
        exit;
    }

    re_lease_soft_delete($conn, $currentCompanyId, $leaseId, (int)(current_user_id() ?: 0), $deleteReason);
    echo json_encode(['success' => true, 'message' => 'Draft lease archived successfully. It can be restored from Deleted Leases.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
