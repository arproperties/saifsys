<?php
/**
 * Store API (the staff app's Store button) — the gate and who is calling.
 *
 * Same plumbing as Purchase (api/mobile/purchase/purchase_api.php): the same
 * app key, PIN and token signing. The token carries typ `staff_store`, and
 * every request checks again that the PIN is the one it was issued from, the
 * login is open, and Store is still switched on in HR (staff_app_access,
 * app 'store'). Switching it off signs the phone out of Store on its next request.
 *
 * Tokens come from api/mobile/staff/signin.php, with the other apps' tokens.
 * The rules live in modules/operations/includes/ops_store.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/fleet/fleet_api.php';
require_once dirname(__DIR__, 3) . '/modules/operations/includes/ops_store.php';

const STAFF_STORE_APP = 'store';

/** Store switched on for this login in HR, and the tables are there. */
function store_api_allowed(PDO $conn, int $userId): bool
{
    return $userId > 0 && fleet_tables_ready($conn) && st_tables_ready($conn)
        && fleet_app_access_has($conn, $userId, STAFF_STORE_APP);
}

/** @return array{token:string,expires_in:int} */
function store_api_issue_token(int $userId, int $pinEpoch): array
{
    $ttl = ops_api_token_ttl();
    return ['token' => ops_api_jwt_sign(['typ' => 'staff_store', 'sub' => $userId, 'pin' => $pinEpoch], $ttl), 'expires_in' => $ttl];
}

function store_api_boot(PDO $conn): void
{
    customer_api_json_headers();
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Ops-App-Key, X-Ops-Device-Id, X-Ops-Request-Id');
    customer_api_handle_options();
    header('Cache-Control: no-store');

    ops_api_require_app_key();

    if (!fleet_tables_ready($conn) || !st_tables_ready($conn)) {
        customer_api_send_error('not_configured', 'This service is not available.', 503);
    }
}

function store_api_parse_route(): string
{
    $fromQuery = trim((string)($_GET['route'] ?? ''), '/');
    if ($fromQuery !== '') {
        return $fromQuery;
    }
    $uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $prefix = get_application_web_root() . '/api/mobile/store';
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
 * The store keeper named by the token, or 401.
 *
 * @return array{user_id:int,name:string,office:bool}
 */
function store_api_current_keeper(PDO $conn): array
{
    $token = ops_api_bearer_token();
    $claims = $token === '' ? null : ops_api_jwt_verify($token);
    if (!$claims || ($claims['typ'] ?? '') !== 'staff_store') {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }
    $userId = (int)($claims['sub'] ?? 0);
    $epoch = $userId > 0 ? ops_pin_current_epoch($conn, $userId) : null;
    if ($epoch === null || $epoch !== (int)($claims['pin'] ?? -1)) {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }
    require_once dirname(__DIR__, 3) . '/includes/account_status.php';
    if (account_login_block_reason($conn, $userId) !== null || !store_api_allowed($conn, $userId)) {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }
    $stmt = $conn->prepare("SELECT COALESCE(NULLIF(fullname, ''), username) FROM `user` WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    return ['user_id' => $userId, 'name' => (string)$stmt->fetchColumn(), 'office' => false];
}

/** The body: JSON, or a form whose "data" field holds the JSON (when a photo goes with it). */
function store_api_body(): array
{
    if (isset($_POST['data'])) {
        $data = json_decode((string)$_POST['data'], true);
        if (!is_array($data)) {
            customer_api_send_error('bad_request', 'The entry did not come through whole. Try again.', 400);
        }
        return $data;
    }
    return customer_api_read_json_body();
}

function store_api_send(?array $data): void
{
    if ($data === null) {
        customer_api_send_error('not_found', 'That entry is not there any more.', 404);
    }
    customer_api_send_ok($data);
}
