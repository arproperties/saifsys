<?php
/**
 * API: Upload User Avatar
 * 
 * Handles avatar image uploads with:
 * - File validation (type, size)
 * - Image resizing
 * - Old avatar cleanup
 * - Audit logging
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

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Check if file was uploaded
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        exit;
    }
    
    // Upload avatar using helper function
    $result = uploadUserAvatar($conn, $userId, $_FILES['avatar']);
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    error_log("Avatar upload error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while uploading avatar'
    ]);
}

