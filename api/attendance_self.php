<?php
/**
 * API: staff self check-in / check-out.
 *
 * Takes a POST with action=in or action=out.
 *
 * Answers a plain form submit with a redirect back to where the person was,
 * so the pop-up keeps working with JavaScript switched off, and answers a
 * fetch() with JSON so the button can update without a page reload.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance_self.php';
require_once __DIR__ . '/../includes/url_helper.php';

$wantsJson = false;
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $wantsJson = true;
}
if (!empty($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $wantsJson = true;
}

/** Finish the request the way the caller asked for. */
function attendance_self_respond(bool $ok, string $message, bool $wantsJson, int $code = 200): void
{
    if ($wantsJson) {
        header('Content-Type: application/json');
        if (!$ok && $code !== 200) {
            http_response_code($code);
        }
        echo json_encode(['success' => $ok, 'message' => $message]);
        exit;
    }

    $_SESSION['attendance_self_flash'] = ['ok' => $ok, 'message' => $message];

    $root = function_exists('get_application_web_root') ? get_application_web_root() : '';
    $target = ($root !== '' ? $root : '') . '/select-module';

    // Only ever return to our own pages.
    $back = (string)($_POST['redirect'] ?? '');
    if ($back !== '' && $back[0] === '/' && strpos($back, '//') !== 0) {
        $target = $back;
    }

    header('Location: ' . $target);
    exit;
}

$userId = current_user_id();
if (!$userId) {
    attendance_self_respond(false, 'Please sign in again.', $wantsJson, 401);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    attendance_self_respond(false, 'Method not allowed.', $wantsJson, 405);
}

csrf_verify();

$action = strtolower(trim((string)($_POST['action'] ?? '')));

if ($action === 'in') {
    $result = attendance_self_check_in($conn);
} elseif ($action === 'out') {
    $result = attendance_self_check_out($conn);
} else {
    attendance_self_respond(false, 'Unknown action.', $wantsJson, 400);
    exit;
}

attendance_self_respond((bool)$result['ok'], (string)$result['message'], $wantsJson, $result['ok'] ? 200 : 422);
