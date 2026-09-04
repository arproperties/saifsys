<?php
/**
 * API: Update User Preferences
 * 
 * Handles updating user preferences:
 * - Theme (light/dark/auto)
 * - Language
 * - Timezone
 * - Email notifications
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
    $preferences = [
        'theme'                => $_POST['theme'] ?? 'light',
        'language'             => $_POST['language'] ?? 'en',
        'timezone'             => $_POST['timezone'] ?? 'Asia/Dubai',
        'email_notifications'  => isset($_POST['email_notifications'])
    ];
    
    // Update preferences using helper function
    $result = updateUserPreferences($conn, $userId, $preferences);
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    error_log("Preferences update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while updating preferences'
    ]);
}

