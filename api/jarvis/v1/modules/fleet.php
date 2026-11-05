<?php
/**
 * Jarvis API v1 — the Fleet module: how the Driver app is being used.
 *
 * Loaded only by ../index.php, after the key has been checked; opened directly it
 * answers nothing. $conn, $action and the jarvis_api_* helpers come from there.
 *
 * View only. The numbers are the ones on Operations → Fleet → Usage Report
 * (modules/operations/includes/fleet_usage_lib.php), so Jarvis and the page agree.
 * Who may ask is decided in Jarvis: only people with the Fleet module ticked.
 *
 *   ?module=fleet&action=usage&from=&to=&company=
 *        the report for a period (default: the last 30 days): totals, check rate,
 *        share of 0 km trips, by driver, by vehicle, by week, long trips, and how many
 *        check problems are still not seen. company = id or part of the name.
 *   ?module=fleet&action=driver&q=&from=&to=
 *        one driver (part of the name): their numbers plus their latest trips.
 *   ?module=fleet&action=problems&company=
 *        daily-check problems nobody has marked as seen yet, any date, newest first.
 */

declare(strict_types=1);

if (!defined('JARVIS_API')) {
    http_response_code(404);
    exit;
}

// Trip times are written in Dubai time; read them on the same clock.
date_default_timezone_set('Asia/Dubai');

require_once __DIR__ . '/../../../../modules/operations/includes/fleet_usage_lib.php';

if (!fleet_tables_ready($conn)) {
    jarvis_api_error('not_configured', 'Vehicle tracking is not set up on this server.', 503);
}

function jarvis_fleet_date(string $name, string $fallback): string
{
    $v = trim((string)($_GET[$name] ?? ''));
    if ($v === '') {
        return $fallback;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        jarvis_api_error('bad_request', "$name must be YYYY-MM-DD.", 400);
    }
    return $v;
}

/** [from, to] from the query, last 30 days by default, never reversed. */
function jarvis_fleet_period(): array
{
    $from = jarvis_fleet_date('from', date('Y-m-d', strtotime('-30 days')));
    $to = jarvis_fleet_date('to', date('Y-m-d'));
    return $from > $to ? [$to, $from] : [$from, $to];
}

/** company = id or part of the name. Returns [id, name]; [0, 'All companies'] when not given. */
function jarvis_fleet_company(PDO $conn): array
{
    $q = trim((string)($_GET['company'] ?? ''));
    if ($q === '') {
        return [0, 'All companies'];
    }
    $stmt = ctype_digit($q)
        ? $conn->prepare('SELECT id, name FROM companies WHERE id = ?')
        : $conn->prepare('SELECT id, name FROM companies WHERE name LIKE ? ORDER BY name');
    $stmt->execute([ctype_digit($q) ? (int)$q : '%' . $q . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) !== 1) {
        jarvis_api_error('bad_request', $rows
            ? 'More than one company matches: ' . implode(', ', array_column($rows, 'name')) . '.'
            : 'No company matches "' . $q . '".', 400);
    }
    return [(int)$rows[0]['id'], (string)$rows[0]['name']];
}

function jarvis_fleet_km(int $meters): float
{
    return round($meters / 1000, 1);
}

function jarvis_fleet_driver_row(int $uid, array $d): array
{
    return [
        'driver_id' => $uid,
        'name' => $d['name'],
        'trips' => $d['trips'],
        'km' => jarvis_fleet_km($d['meters']),
        'hours' => round($d['seconds'] / 3600, 1),
        'days_driven' => count($d['days']),
        'trips_last_7_days' => $d['recent'],
        'zero_km_trips_pct' => $d['ended'] > 0 ? (int)round(100 * $d['zero'] / $d['ended']) : null,
        'daily_check' => $d['check_days'] ? ['days_with_check' => $d['checked_days'], 'days_driven' => $d['check_days']] : null,
        'status' => $d['stopped'] ? 'stopped' : 'active',
        'last_trip' => $d['last_at'],
    ];
}

function jarvis_fleet_problem_row(array $p): array
{
    return [
        'date' => $p['check_date'],
        'driver' => $p['driver_name'],
        'vehicle' => $p['plate_no'],
        'problem' => trim((string)$p['notes']),
    ];
}

/** What the numbers mean, sent with every report so the answer explains them right. */
function jarvis_fleet_meaning(): array
{
    return [
        'zero_km_trips' => 'The trip ran but no GPS distance was saved — usually the phone was locked or the app was closed during the trip.',
        'stopped' => 'No trip for ' . FLEET_USAGE_STOPPED_DAYS . ' days or more.',
        'zero_km_pct_high' => 'Above ' . FLEET_USAGE_ZERO_KM_RED . '% is flagged red on the Usage Report page.',
        'long_trip' => 'Over ' . FLEET_USAGE_LONG_TRIP_HOURS . ' hours — usually the driver forgot to press Stop.',
        'check_rate' => 'Share of driving days on which the driver did the daily safety check.',
        'page' => 'Operations → Fleet → Usage Report in saifsys shows the same numbers and has a PDF download.',
    ];
}

if ($action === 'usage') {
    [$from, $to] = jarvis_fleet_period();
    [$companyId, $companyName] = jarvis_fleet_company($conn);
    $r = fleet_usage_report($conn, $from, $to, $companyId);
    $plates = array_column($r['vehicleRows'], 'plate_no', 'id');

    jarvis_api_send([
        'ok' => true,
        'from' => $from,
        'to' => $to,
        'company' => $companyName,
        'totals' => [
            'trips' => $r['totals']['trips'],
            'km' => jarvis_fleet_km($r['totals']['meters']),
            'hours' => round($r['totals']['seconds'] / 3600),
            'drivers' => count($r['drivers']),
            'drivers_active' => $r['activeDrivers'],
            'vehicles' => count($r['vehicleRows']),
            'vehicles_used' => $r['vehiclesUsed'],
            'daily_check_rate_pct' => $r['checkRate'],
            'zero_km_trips_pct' => $r['zeroRate'],
            'check_problems_not_seen' => count($r['openProblems']),
            'long_trips' => count($r['longTrips']),
        ],
        'drivers' => array_values(array_map(
            fn($uid, $d) => jarvis_fleet_driver_row((int)$uid, $d),
            array_keys($r['drivers']), $r['drivers']
        )),
        'vehicles' => array_map(fn($v) => [
            'plate' => $v['plate_no'],
            'name' => $v['name'],
            'company' => $v['company_name'],
            'trips' => $v['trips'],
            'km' => jarvis_fleet_km($v['meters']),
            'main_drivers' => array_values(array_filter($v['main_drivers'])),
            'last_used' => $v['last_at'],
            'status' => $v['status'] === 'inactive' ? 'inactive'
                : ($v['last_at'] === null ? 'never_used' : ($v['trips'] ? 'used' : 'not_used_in_period')),
        ], $r['vehicleRows']),
        'weeks' => array_map(
            fn($week, $w) => ['week_starting' => $week, 'trips' => $w['trips'], 'km' => jarvis_fleet_km($w['meters'])],
            array_keys($r['weeks']), $r['weeks']
        ),
        'long_trips' => array_map(fn($t) => [
            'started' => $t['started_at'],
            'driver' => $t['driver_name'],
            'vehicle' => $plates[(int)$t['vehicle_id']] ?? null,
            'hours' => round($t['seconds'] / 3600, 1),
            'km' => jarvis_fleet_km((int)$t['distance_m']),
            'still_running' => $t['ended_at'] === null,
        ], array_slice($r['longTrips'], 0, 15)),
        'meaning' => jarvis_fleet_meaning(),
    ]);
}

if ($action === 'driver') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        jarvis_api_error('bad_request', 'q (the driver\'s name) is required.', 400);
    }
    [$from, $to] = jarvis_fleet_period();
    $r = fleet_usage_report($conn, $from, $to, 0);
    $found = array_filter($r['drivers'], fn($d) => stripos($d['name'], $q) !== false);
    if (!$found) {
        jarvis_api_send(['ok' => true, 'from' => $from, 'to' => $to, 'drivers' => [],
            'note' => 'No driver named like "' . $q . '" drove in this period.']);
    }

    $out = [];
    foreach ($found as $uid => $d) {
        $stmt = $conn->prepare("
            SELECT t.started_at, t.ended_at, t.distance_m, v.plate_no
            FROM fleet_trips t JOIN fleet_vehicles v ON v.id = t.vehicle_id
            WHERE t.driver_user_id = ? AND t.started_at BETWEEN ? AND ?
            ORDER BY t.started_at DESC LIMIT 10");
        $stmt->execute([(int)$uid, $from . ' 00:00:00', $to . ' 23:59:59']);
        $row = jarvis_fleet_driver_row((int)$uid, $d);
        $row['vehicles'] = array_values(array_unique(array_map(
            fn($vid) => (string)(array_column($r['vehicleRows'], 'plate_no', 'id')[$vid] ?? ''), array_keys($d['vehicles'])
        )));
        $row['latest_trips'] = array_map(fn($t) => [
            'started' => $t['started_at'],
            'ended' => $t['ended_at'],
            'vehicle' => $t['plate_no'],
            'km' => jarvis_fleet_km((int)$t['distance_m']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $row['problems_not_seen'] = array_values(array_map('jarvis_fleet_problem_row', array_filter(
            $r['openProblems'], fn($p) => stripos((string)$p['driver_name'], $d['name']) !== false
        )));
        $out[] = $row;
    }
    jarvis_api_send(['ok' => true, 'from' => $from, 'to' => $to, 'drivers' => $out, 'meaning' => jarvis_fleet_meaning()]);
}

if ($action === 'problems') {
    [$companyId, $companyName] = jarvis_fleet_company($conn);
    $rows = fleet_checks_ready($conn) ? fleet_usage_open_problems($conn, $companyId) : [];
    jarvis_api_send([
        'ok' => true,
        'company' => $companyName,
        'count' => count($rows),
        'problems' => array_map('jarvis_fleet_problem_row', $rows),
        'how_to_clear' => 'Operations → Fleet → Daily Checks in saifsys: press "Mark as seen" once dealt with.',
    ]);
}

jarvis_api_error('not_found', 'Unknown action. Try usage, driver or problems.', 404);
