<?php
/**
 * Real Estate Module - AJAX: Change Lease Status
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// CSRF verification - check for _csrf in POST
if (!isset($_POST['_csrf']) || !hash_equals($_SESSION['_csrf'] ?? '', (string)$_POST['_csrf'])) {
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$leaseId = !empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : 0;
$newStatus = $_POST['status'] ?? '';
$confirmText = trim((string)($_POST['confirm_text'] ?? ''));

$validStatuses = re_lease_valid_statuses();
if (!in_array($newStatus, $validStatuses, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}
if ($newStatus === 'terminated') {
    echo json_encode(['success' => false, 'error' => 'Please use the Terminate Lease workflow so the termination date, returned cheques, and revenue recognition are handled correctly.']);
    exit;
}

if (!$leaseId) {
    echo json_encode(['success' => false, 'error' => 'Lease ID is required']);
    exit;
}

try {
    if ($confirmText !== 'CHANGE') {
        echo json_encode(['success' => false, 'error' => 'Type CHANGE to confirm this status update.']);
        exit;
    }

    re_lease_change_status_guarded($conn, $currentCompanyId, $leaseId, $newStatus, (int)(current_user_id() ?: 0));
    
    echo json_encode(['success' => true, 'message' => 'Lease status updated successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

