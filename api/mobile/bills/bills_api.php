<?php
/**
 * Bills API (the staff app's Bills button) — the gate and who is calling.
 *
 * Built on the same plumbing as Purchase (api/mobile/purchase/purchase_api.php):
 * the same app key, the same PIN and lockout, the same token signing. The token
 * carries typ `staff_bills`, so no other app's token opens it, and every
 * request checks again that the PIN is the one it was issued from, the login is
 * open, and Bills has not been turned off in HR (staff_app_access, app 'bills_off').
 *
 * Tokens come from api/mobile/staff/signin.php, with the other apps' tokens.
 * The rules live in includes/staff_bills.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/fleet/fleet_api.php';
require_once dirname(__DIR__, 3) . '/includes/staff_bills.php';

/** Bills on for this login (a PIN, not turned off in HR), and the tables are there. */
function bills_api_allowed(PDO $conn, int $userId): bool
{
    return $userId > 0 && bill_tables_ready($conn) && bill_has_access($conn, $userId);
}

/** @return array{token:string,expires_in:int} */
function bills_api_issue_token(int $userId, int $pinEpoch): array
{
    $ttl = ops_api_token_ttl();
    return ['token' => ops_api_jwt_sign(['typ' => 'staff_bills', 'sub' => $userId, 'pin' => $pinEpoch], $ttl), 'expires_in' => $ttl];
}

function bills_api_boot(PDO $conn): void
{
    customer_api_json_headers();
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Ops-App-Key, X-Ops-Device-Id, X-Ops-Request-Id');
    customer_api_handle_options();
    header('Cache-Control: no-store');

    ops_api_require_app_key();

    if (!bill_tables_ready($conn)) {
        customer_api_send_error('not_configured', 'This service is not available.', 503);
    }
}

function bills_api_parse_route(): string
{
    $fromQuery = trim((string)($_GET['route'] ?? ''), '/');
    if ($fromQuery !== '') {
        return $fromQuery;
    }
    $uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $prefix = get_application_web_root() . '/api/mobile/bills';
    if (strpos($uriPath, $prefix) !== 0) {
        return '';
    }
    $rest = trim(substr($uriPath, strlen($prefix)), '/');
    if ($rest === 'index.php' || str_starts_with($rest, 'index.php/')) {
        $rest = trim(substr($rest, strlen('index.php')), '/');
    }
    return $rest;
}

/**
 * The person named by the token, or 401.
 *
 * @return array{user_id:int,name:string,office:bool}
 */
function bills_api_current_user(PDO $conn): array
{
    $token = ops_api_bearer_token();
    $claims = $token === '' ? null : ops_api_jwt_verify($token);
    if (!$claims || ($claims['typ'] ?? '') !== 'staff_bills') {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }
    $userId = (int)($claims['sub'] ?? 0);
    $epoch = $userId > 0 ? ops_pin_current_epoch($conn, $userId) : null;
    if ($epoch === null || $epoch !== (int)($claims['pin'] ?? -1)) {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }
    require_once dirname(__DIR__, 3) . '/includes/account_status.php';
    if (account_login_block_reason($conn, $userId) !== null || !bills_api_allowed($conn, $userId)) {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }
    return ['user_id' => $userId, 'name' => (string)bill_user_name($conn, $userId), 'office' => false];
}

/** The body: JSON, or a form whose "data" field holds the JSON (when files go with it). */
function bills_api_body(): array
{
    if (isset($_POST['data'])) {
        $data = json_decode((string)$_POST['data'], true);
        if (!is_array($data)) {
            customer_api_send_error('bad_request', 'The bill did not come through whole. Try again.', 400);
        }
        return $data;
    }
    return customer_api_read_json_body();
}

/**
 * A change's result back to the phone, then its notifications — after the
 * answer has gone, where the server allows it, so the phone does not wait.
 *
 * @param array{0:?array,1:list<array>}|null $result
 */
function bills_api_send(PDO $conn, ?array $result): void
{
    if ($result === null || $result[0] === null) {
        customer_api_send_error('not_found', 'That bill is not there any more.', 404);
    }
    if (!empty($result[1])) {
        $messages = $result[1];
        register_shutdown_function(static function () use ($conn, $messages): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            bill_notify($conn, $messages);
        });
    }
    customer_api_send_ok($result[0]);
}
