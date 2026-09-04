<?php
/**
 * Firebase Users Admin API
 * View and manage clients linked with Firebase Authentication
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Check if user is logged in (admin only)
session_start();
if (!isset($_SESSION['user_id'])) {
    errorResponse('Unauthorized', 401);
}

try {
    // Get all clients with Firebase UID
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.client_name,
            c.mobile_num,
            c.email,
            c.firebase_uid,
            c.is_active,
            mu.last_login_at as mobile_last_login,
            mu.device_token,
            COUNT(DISTINCT ob.id) as total_bookings,
            SUM(CASE WHEN ob.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings
        FROM client c
        LEFT JOIN mobile_user mu ON c.id = mu.client_id
        LEFT JOIN online_bookings ob ON c.id = ob.client_id
        WHERE c.firebase_uid IS NOT NULL
        GROUP BY c.id, c.client_name, c.mobile_num, c.email, c.firebase_uid, c.is_active, mu.last_login_at, mu.device_token
        ORDER BY mu.last_login_at DESC, c.id DESC
    ");
    
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    successResponse([
        'total_users' => count($users),
        'users' => $users
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in firebase_users.php: " . $e->getMessage());
    errorResponse('Database error', 500);
}

