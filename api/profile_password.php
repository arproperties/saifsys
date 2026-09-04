<?php
/**
 * API: Change User Password
 * 
 * Handles password change requests with:
 * - Current password verification
 * - New password validation
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
    // Get form data
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validate required fields
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'error' => 'All fields are required']);
        exit;
    }
    
    // Check if passwords match
    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'error' => 'New passwords do not match']);
        exit;
    }
    
    // Check if new password is same as current
    if ($currentPassword === $newPassword) {
        echo json_encode(['success' => false, 'error' => 'New password must be different from current password']);
        exit;
    }
    
    // Change password using helper function
    $result = changeUserPassword($conn, $userId, $currentPassword, $newPassword);
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    error_log("Password change error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while changing password'
    ]);
}

