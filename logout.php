<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';

// Audit Log: Track logout before destroying session
require_once __DIR__ . '/includes/AuditService.php';
$logoutUser = $_SESSION['user']['username'] ?? $_SESSION['user']['fullname'] ?? 'Unknown';
AuditService::logEvent([
    'action' => 'logout',
    'module' => 'auth',
    'object_type' => 'auth',
    'object_ref' => 'Sign-out',
    'summary' => "User {$logoutUser} logged out",
    'source' => 'user',
    'success' => true
]);

if (!empty($_COOKIE['rememberme'])) {
    [$selector] = explode(':', $_COOKIE['rememberme'], 2);
    $conn->prepare("DELETE FROM user_tokens WHERE selector=?")->execute([$selector]);
    setcookie('rememberme', '', time() - 3600, '/');
}

session_unset();
session_destroy();
header('Location: login');
exit;
