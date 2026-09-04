<?php
/**
 * API: Update User Profile
 * 
 * Handles updating basic user profile information:
 * - Full name
 * - Email
 * - Phone
 * - Job title
 * - Department
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
    $data = [
        'fullname'   => trim($_POST['fullname'] ?? ''),
        'email'      => trim($_POST['email'] ?? ''),
        'phone'      => trim($_POST['phone'] ?? ''),
        'job_title'  => trim($_POST['job_title'] ?? ''),
        'department' => trim($_POST['department'] ?? '')
    ];
    
    // Validate required fields
    if (empty($data['fullname'])) {
        echo json_encode(['success' => false, 'error' => 'Full name is required']);
        exit;
    }
    
    // Remove empty values (convert empty strings to null)
    foreach ($data as $key => $value) {
        if ($value === '') {
            $data[$key] = null;
        }
    }
    
    // Update profile using helper function
    $result = updateUserProfile($conn, $userId, $data);
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    error_log("Profile update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while updating profile'
    ]);
}

