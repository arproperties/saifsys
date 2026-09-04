<?php
/**
 * ARS Customer Portal — Authentication & Session Helpers
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!isset($conn) || !($conn instanceof PDO)) {
    require_once __DIR__ . '/../../includes/db_connect.php';
}
if (!function_exists('csrf_token')) {
    require_once __DIR__ . '/../../includes/auth.php';
}

require_once __DIR__ . '/../../modules/ars/includes/ars_helpers.php';

const PORTAL_USER_SESSION_KEY  = 'portal_user_id';
const PORTAL_GUEST_SESSION_KEY = 'portal_guest_id';

function is_portal_guest(): bool {
    return !empty($_SESSION[PORTAL_USER_SESSION_KEY]);
}

function current_portal_user_id(): int {
    return (int)($_SESSION[PORTAL_USER_SESSION_KEY] ?? 0);
}

function current_portal_guest_id(): int {
    return (int)($_SESSION[PORTAL_GUEST_SESSION_KEY] ?? 0);
}

function current_portal_display_name(): string {
    return $_SESSION['portal_display_name'] ?? 'Guest';
}

function portal_login_set_session(array $portalUser, array $guest): void {
    session_regenerate_id(true);
    $_SESSION[PORTAL_USER_SESSION_KEY]  = (int)$portalUser['id'];
    $_SESSION[PORTAL_GUEST_SESSION_KEY] = (int)$portalUser['guest_id'];
    $_SESSION['portal_display_name']    = $guest['first_name'] . ' ' . $guest['last_name'];
    $_SESSION['portal_company_id']      = (int)$portalUser['company_id'];
}

function require_portal_login(): void {
    if (is_portal_guest()) return;
    $_SESSION['portal_next'] = $_SERVER['REQUEST_URI'] ?? '';
    $base = portal_base_url();
    header('Location: ' . $base . '/login.php');
    exit;
}

function portal_base_url(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $pos = strpos($script, '/stay/');
    if ($pos !== false) {
        return substr($script, 0, $pos) . '/stay';
    }
    return '/stay';
}

function portal_asset(string $path): string {
    return portal_base_url() . '/' . ltrim($path, '/');
}

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
