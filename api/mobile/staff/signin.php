<?php
/**
 * Staff app (web) — one sign-in for both halves of it.
 *
 * POST api/mobile/staff/signin.php   {"pin":"1234"}
 *
 * The staff app at /staff/ is the Driver app and the Cleaning (Operations) app
 * in one page. They keep their own APIs — api/mobile/fleet and api/mobile/ops,
 * unchanged — and their own tokens. This file only saves the person typing the
 * same PIN twice: it checks the PIN once, against the same lockout, and hands
 * back a token for each app the person may use.
 *
 *   cleaning  anyone the Operations app lets in: a PIN and at least one company
 *             (ops_api_handle_pin_login's rule)
 *   driver    Driver access switched on in HR (fleet_api_handle_pin_login's rule)
 *
 * A PIN that opens neither gets the same "That PIN did not work" as a wrong one
 * and counts as a failed guess, exactly as each app does on its own.
 *
 * Each token keeps its own typ (ops_staff / fleet_driver), so neither API
 * accepts the other's, and both still re-check PIN, account and access on
 * every request.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/fleet/fleet_api.php';

customer_api_json_headers();
header('Access-Control-Allow-Headers: Content-Type, X-Ops-App-Key, X-Ops-Device-Id');
customer_api_handle_options();
header('Cache-Control: no-store');

ops_api_require_app_key();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    customer_api_send_error('not_found', 'Not found', 404);
}

if (!ops_pin_configured()) {
    customer_api_send_error('not_configured', 'This service is not available.', 503);
}

$deviceId = substr(trim((string)($_SERVER['HTTP_X_OPS_DEVICE_ID'] ?? '')), 0, 64);
$ip = ops_pin_client_ip();

$throttle = ops_pin_throttle_state($conn, $deviceId, $ip);
if ($throttle['blocked']) {
    customer_api_send_error(
        'too_many_attempts',
        'Too many wrong PINs. Try again later.',
        429,
        ['retry_after' => $throttle['retry_after']]
    );
}

$body = customer_api_read_json_body();
$pin = trim((string)($body['pin'] ?? ''));
$person = ops_pin_format_ok($pin) ? ops_pin_resolve_user($conn, $pin) : null;
$userId = $person ? (int)$person['id'] : 0;

$cleaning = $person !== null && ops_api_user_company_ids($conn, $userId) !== [];
$driver = $person !== null && fleet_tables_ready($conn) && fleet_app_access_has($conn, $userId, FLEET_DRIVER_APP);

if (!$cleaning && !$driver) {
    ops_pin_record_attempt($conn, $deviceId, $ip, $person['id'] ?? null, false);
    ops_pin_prune_attempts($conn);
    customer_api_send_error('bad_pin', 'That PIN did not work.', 401);
}

ops_pin_record_attempt($conn, $deviceId, $ip, $userId, true);
ops_pin_prune_attempts($conn);

$epoch = (int)$person['epoch'];

customer_api_send_ok([
    'user' => ['id' => $userId, 'name' => (string)$person['name']],
    'apps' => [
        'cleaning' => $cleaning ? ops_api_issue_token($userId, $epoch) : null,
        'driver' => $driver ? fleet_api_issue_token($userId, $epoch) : null,
    ],
]);
