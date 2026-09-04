<?php
/**
 * API: Get User Activity History
 * 
 * Returns paginated user activity from audit_log
 * Supports filtering by action type
 */

header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/profile_functions.php';

// Check if user is logged in
$userId = current_user_id();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get pagination parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    
    // Get filter parameters
    $actionFilter = isset($_GET['action']) && !empty($_GET['action']) ? $_GET['action'] : null;
    
    // Get activity data
    $activities = getUserActivity($conn, $userId, $perPage, $offset, $actionFilter);
    $total = getUserActivityCount($conn, $userId, $actionFilter);
    
    echo json_encode([
        'success' => true,
        'activities' => $activities,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage)
    ]);
    
} catch (Throwable $e) {
    error_log("Activity fetch error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while fetching activity history'
    ]);
}

