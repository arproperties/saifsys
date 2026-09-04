<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

unset(
    $_SESSION['portal_user_id'],
    $_SESSION['portal_guest_id'],
    $_SESSION['portal_display_name'],
    $_SESSION['portal_company_id'],
    $_SESSION['portal_next']
);

$script = $_SERVER['SCRIPT_NAME'] ?? '';
$pos = strpos($script, '/stay/');
$base = ($pos !== false) ? substr($script, 0, $pos) . '/stay' : '/stay';

header('Location: ' . $base . '/login.php');
exit;
