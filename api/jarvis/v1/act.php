<?php
/**
 * Jarvis API v1 — the door for things Jarvis DOES in saifsys, as opposed to index.php,
 * which only reads.
 *
 * Its own key, so the read key can never change anything: `X-Jarvis-Action-Key` must
 * equal JARVIS_ACTION_KEY in includes/config.php (Jarvis keeps the same value as
 * SAIFSYS_ACTION_KEY). Missing or empty = this door answers 503 and does nothing.
 *
 * POST only, JSON body. Every request names the person acting by `email`: the company
 * mailbox they have connected in Jarvis, which proves it is theirs. It must be the email
 * on exactly one saifsys user; that user is who saifsys records as having done it.
 * Jarvis decides who may act at all (the master, and the people ticked for it) and only
 * calls after the person has approved the action on a card.
 *
 *   POST ?module=ars_booking&action=quote   -> the price saifsys would charge, nothing saved
 *   POST ?module=ars_booking&action=create  -> the booking, created
 */

declare(strict_types=1);

define('JARVIS_ACT', true);

// The modules that can act. Each is modules/<name>.php.
const JARVIS_ACT_MODULES = ['ars_booking'];

require_once __DIR__ . '/../../../includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jarvis_api_send(array $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jarvis_api_error(string $code, string $message, int $status, array $extra = []): void
{
    jarvis_api_send(['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra], $status);
}

$secret = defined('JARVIS_ACTION_KEY') ? (string)JARVIS_ACTION_KEY : '';
if ($secret === '') {
    jarvis_api_error('not_configured', 'JARVIS_ACTION_KEY is not set on this server.', 503);
}
$given = (string)($_SERVER['HTTP_X_JARVIS_ACTION_KEY'] ?? '');
if ($given === '' || !hash_equals($secret, $given)) {
    jarvis_api_error('unauthorized', 'Not authorised.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jarvis_api_error('method_not_allowed', 'POST only.', 405);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    jarvis_api_error('bad_body', 'The body must be a JSON object.', 400);
}

// Who is acting: the one saifsys user with this email.
$email = strtolower(trim((string)($in['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jarvis_api_error('no_email', 'The email of the person acting is missing.', 400);
}
$stmt = $conn->prepare('SELECT * FROM `user` WHERE LOWER(TRIM(email)) = ? LIMIT 2');
$stmt->execute([$email]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$users) {
    jarvis_api_error('no_saifsys_user', "No saifsys user has the email $email on their profile.", 403);
}
if (count($users) > 1) {
    jarvis_api_error('email_not_unique', "More than one saifsys user has the email $email, so saifsys cannot tell who you are.", 409);
}
$actor = $users[0];
foreach (['is_active' => 0, 'active' => 0, 'disabled' => 1] as $col => $blocked) {
    if (array_key_exists($col, $actor) && (int)$actor[$col] === $blocked) {
        jarvis_api_error('saifsys_user_inactive', "The saifsys user for $email is not active.", 403);
    }
}
if (isset($actor['status']) && in_array(strtolower((string)$actor['status']), ['inactive', 'disabled', 'blocked', 'suspended'], true)) {
    jarvis_api_error('saifsys_user_inactive', "The saifsys user for $email is not active.", 403);
}
$actorId = (int)$actor['id'];
$actorName = (string)($actor['fullname'] ?? $actor['username'] ?? $email);

$action = (string)($_GET['action'] ?? '');
$module = (string)($_GET['module'] ?? '');
if (!in_array($module, JARVIS_ACT_MODULES, true)) {
    jarvis_api_error('not_found', 'Unknown module.', 404);
}
require __DIR__ . '/modules/' . $module . '.php';
