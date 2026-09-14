<?php
/**
 * Driver app API — the gate, routing, and who is calling.
 *
 * Built on the Operations app's plumbing on purpose (api/mobile/ops/ops_api.php):
 * the same app key header, the same PIN — one PIN opens every company app —
 * the same lockout and the same token signing. Two things differ:
 *
 *   - tokens carry typ `fleet_driver`. Each API checks its own typ, so an ops
 *     token is refused here and a driver token is refused there;
 *   - the person must have Driver access switched on in HR
 *     (staff_app_access), re-checked on every request, so switching it off
 *     signs the phone out on its next call.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/ops/ops_api.php';
require_once dirname(__DIR__, 3) . '/hr/includes/hr_fleet.php';

function fleet_api_boot(PDO $conn): void
{
    customer_api_json_headers();
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Ops-App-Key, X-Ops-Device-Id');
    customer_api_handle_options();

    ops_api_require_app_key();

    if (!fleet_tables_ready($conn)) {
        customer_api_send_error('not_configured', 'This service is not available.', 503);
    }
}

function fleet_api_parse_route(): string
{
    $fromQuery = trim((string)($_GET['route'] ?? ''), '/');
    if ($fromQuery !== '') {
        return $fromQuery;
    }

    $uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $prefix = get_application_web_root() . '/api/mobile/fleet';
    if (strpos($uriPath, $prefix) !== 0) {
        return '';
    }
    $rest = trim(substr($uriPath, strlen($prefix)), '/');
    if ($rest === 'index.php' || str_starts_with($rest, 'index.php/')) {
        $rest = trim(substr($rest, strlen('index.php')), '/');
    }
    return $rest;
}

// ---------------------------------------------------------------------------
// Tokens
// ---------------------------------------------------------------------------

/** @return array{token:string,expires_in:int} */
function fleet_api_issue_token(int $userId, int $pinEpoch): array
{
    $ttl = ops_api_token_ttl();
    $token = ops_api_jwt_sign([
        'typ' => 'fleet_driver',
        'sub' => $userId,
        'pin' => $pinEpoch,
    ], $ttl);
    return ['token' => $token, 'expires_in' => $ttl];
}

/**
 * The driver named by the token, or 401.
 *
 * Re-checked every request, because a token lives for weeks: the PIN must be
 * the one it was issued from, the login active, and Driver access still on.
 *
 * @return array{id:int,name:string}
 */
function fleet_api_current_driver(PDO $conn): array
{
    $token = ops_api_bearer_token();
    $claims = $token === '' ? null : ops_api_jwt_verify($token);
    if (!$claims || ($claims['typ'] ?? '') !== 'fleet_driver') {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }

    $userId = (int)($claims['sub'] ?? 0);
    $epoch = $userId > 0 ? ops_pin_current_epoch($conn, $userId) : null;
    if ($epoch === null || $epoch !== (int)($claims['pin'] ?? -1)) {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }

    $stmt = $conn->prepare("
        SELECT id, COALESCE(NULLIF(fullname, ''), username) AS name
        FROM user
        WHERE id = ? AND status = 1
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !fleet_app_access_has($conn, $userId, FLEET_DRIVER_APP)) {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }

    return ['id' => (int)$user['id'], 'name' => (string)$user['name']];
}

// ---------------------------------------------------------------------------
// JSON shapes
// ---------------------------------------------------------------------------

function fleet_api_ms(?string $datetime): ?int
{
    return $datetime === null || $datetime === '' ? null : (int)strtotime($datetime) * 1000;
}

function fleet_api_trip_row(PDO $conn, array $t): array
{
    return [
        'route_name' => fleet_routes_enabled() && !empty($t['route_name']) ? (string)$t['route_name'] : null,
        'direction' => fleet_routes_enabled() ? ($t['direction'] ?? null) : null,
        // In route order. The app shows the first one not reached yet.
        'stops' => array_map(static fn(array $s): array => [
            'order' => (int)$s['stop_order'],
            'name' => (string)$s['name'],
            'lat' => (float)$s['lat'],
            'lng' => (float)$s['lng'],
            'time' => fleet_hm($s['planned_time']),
            'arrived' => $s['arrived_at'] !== null,
        ], fleet_trip_stops_shown($conn, (int)$t['id'])),
        'id' => (int)$t['id'],
        'vehicle_id' => (int)$t['vehicle_id'],
        'plate_no' => (string)$t['plate_no'],
        'vehicle_name' => $t['vehicle_name'] !== null && $t['vehicle_name'] !== '' ? (string)$t['vehicle_name'] : null,
        'started_at_ms' => fleet_api_ms($t['started_at']),
        'ended_at_ms' => fleet_api_ms($t['ended_at']),
        'ended_by_office' => $t['ended_at'] !== null && $t['ended_by'] !== null,
        'distance_km' => round((int)$t['distance_m'] / 1000, 1),
        'point_count' => (int)$t['point_count'],
    ];
}

/** 404 unless this trip was started by this driver. */
function fleet_api_own_trip(PDO $conn, array $driver, int $tripId): array
{
    $trip = fleet_load_trip($conn, $tripId);
    if (!$trip || (int)$trip['driver_user_id'] !== $driver['id']) {
        customer_api_send_error('not_found', 'That trip is not yours.', 404);
    }
    return $trip;
}
