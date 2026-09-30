<?php
/**
 * Jarvis API v1 — the door the Jarvis assistant (ainalreemproaiagent.fun) uses to read
 * saifsys.
 *
 * Read-only on purpose: Jarvis asks, saifsys answers, and nothing here changes a row.
 * Every request must carry the shared secret as `X-Jarvis-Key`. The secret lives in
 * includes/config.php as JARVIS_API_KEY and the same value sits in Jarvis's .env as
 * SAIFSYS_API_KEY. Empty or missing = the whole API answers 503, so uploading this
 * file without configuring it exposes nothing.
 *
 * Organised like the saifsys launcher: one file per module in modules/, picked by
 * `?module=`, and one route per `action` inside it. A new module is a new file there
 * plus its name in JARVIS_MODULES below; a new question is a new action in its file.
 *
 *   GET ?action=health                                -> { ok: true, modules: [...] }
 *   GET ?module=ars&action=checkouts&date=YYYY-MM-DD  -> modules/ars.php
 *
 * `?action=checkouts` with no module still means ARS, so a Jarvis that has not been
 * updated yet keeps working.
 */

declare(strict_types=1);

define('JARVIS_API', true);

// The modules that have something to answer. Each is modules/<name>.php.
const JARVIS_MODULES = ['ars'];

require_once __DIR__ . '/../../../includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jarvis_api_send(array $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jarvis_api_error(string $code, string $message, int $status): void
{
    jarvis_api_send(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
}

$secret = defined('JARVIS_API_KEY') ? (string)JARVIS_API_KEY : '';
if ($secret === '') {
    jarvis_api_error('not_configured', 'JARVIS_API_KEY is not set on this server.', 503);
}
$given = (string)($_SERVER['HTTP_X_JARVIS_KEY'] ?? '');
if ($given === '' || !hash_equals($secret, $given)) {
    jarvis_api_error('unauthorized', 'Not authorised.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jarvis_api_error('method_not_allowed', 'Read-only: GET only.', 405);
}

$action = (string)($_GET['action'] ?? '');

if ($action === 'health') {
    jarvis_api_send(['ok' => true, 'version' => 'v1', 'modules' => JARVIS_MODULES]);
}

$module = (string)($_GET['module'] ?? '');
if ($module === '' && $action === 'checkouts') {
    $module = 'ars'; // the old address, from before modules
}
if (!in_array($module, JARVIS_MODULES, true)) {
    jarvis_api_error('not_found', 'Unknown module. Try module=ars.', 404);
}
require __DIR__ . '/modules/' . $module . '.php';
