<?php
/**
 * AJAX endpoint to clear bank reconciliation match results
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

header('Content-Type: application/json');

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$csrfToken = $data['_csrf'] ?? '';

// Verify CSRF token
if (empty($csrfToken) || !hash_equals($_SESSION['_csrf'] ?? '', $csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Clear match results from session
unset($_SESSION['bank_reconciliation_matches']);

echo json_encode(['success' => true, 'message' => 'Match results cleared']);
