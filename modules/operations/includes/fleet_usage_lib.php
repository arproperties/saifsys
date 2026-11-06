<?php
/**
 * Fleet usage report — the numbers behind Operations → Fleet → Usage Report, its PDF,
 * and the Jarvis API (api/jarvis/v1/modules/fleet.php), so all three always agree.
 *
 * Read-only. Opens no session and checks no login, so the stateless API can use it;
 * the caller decides who may see it.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/hr/includes/hr_fleet.php';
require_once dirname(__DIR__, 3) . '/hr/includes/hr_fleet_checks.php';

/** No trip for this many days = "Stopped". */
const FLEET_USAGE_STOPPED_DAYS = 7;
/** Above this share of 0 km trips the cell goes red. */
const FLEET_USAGE_ZERO_KM_RED = 30;
/** A trip this long usually means the driver forgot to press Stop. */
const FLEET_USAGE_LONG_TRIP_HOURS = 10;
/** Less than this counts as "0 km": the trip ran but no GPS distance was saved. */
const FLEET_USAGE_ZERO_KM_METERS = 100;

/** Trips that started in the period, oldest first. */
function fleet_usage_trips(PDO $conn, string $from, string $to, int $companyId): array
{
    $sql = "
        SELECT t.id, t.vehicle_id, t.driver_user_id, t.driver_name, t.started_at, t.ended_at, t.distance_m
        FROM fleet_trips t
        WHERE t.started_at BETWEEN ? AND ?" . ($companyId ? ' AND t.company_id = ?' : '') . "
        ORDER BY t.started_at";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $companyId ? [$companyId] : []));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** driver_user_id => latest trip start, any date. Decides Active / Stopped. */
function fleet_usage_driver_last_trip(PDO $conn): array
{
    $rows = $conn->query("SELECT driver_user_id, MAX(started_at) AS last_at FROM fleet_trips GROUP BY driver_user_id")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_column($rows, 'last_at', 'driver_user_id');
}

/** Every vehicle (in the company, if one is picked) with its latest trip, any date. */
function fleet_usage_vehicles(PDO $conn, int $companyId): array
{
    $sql = "
        SELECT v.id, v.plate_no, v.name, v.status, c.name AS company_name,
               (SELECT MAX(t.started_at) FROM fleet_trips t WHERE t.vehicle_id = v.id) AS last_at
        FROM fleet_vehicles v
        LEFT JOIN companies c ON c.id = v.company_id" . ($companyId ? ' WHERE v.company_id = ?' : '') . "
        ORDER BY v.plate_no";
    $stmt = $conn->prepare($sql);
    $stmt->execute($companyId ? [$companyId] : []);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Daily checks done in the period. */
function fleet_usage_checks(PDO $conn, string $from, string $to, int $companyId): array
{
    $sql = "
        SELECT driver_user_id, vehicle_id, check_date, problem_count
        FROM fleet_daily_checks
        WHERE check_date BETWEEN ? AND ?" . ($companyId ? ' AND company_id = ?' : '');
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$from, $to], $companyId ? [$companyId] : []));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Problem checks nobody has marked as seen, any date, newest first. */
function fleet_usage_open_problems(PDO $conn, int $companyId): array
{
    $sql = "
        SELECT c.id, c.check_date, c.driver_name, c.answers, c.notes, c.vehicle_id, v.plate_no
        FROM fleet_daily_checks c
        JOIN fleet_vehicles v ON v.id = c.vehicle_id
        WHERE c.problem_count > 0 AND c.reviewed_at IS NULL" . ($companyId ? ' AND c.company_id = ?' : '') . "
        ORDER BY c.check_date DESC, c.id DESC
        LIMIT 50";
    $stmt = $conn->prepare($sql);
    $stmt->execute($companyId ? [$companyId] : []);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** The first day any check was saved: driving days before it can't be held against anyone. */
function fleet_usage_checks_since(PDO $conn): ?string
{
    $first = $conn->query("SELECT MIN(check_date) FROM fleet_daily_checks")->fetchColumn();
    return $first ? (string)$first : null;
}

/**
 * The whole report for one period. Dates are Y-m-d, already checked; companyId 0 = all.
 * Assumes fleet_tables_ready() is true.
 */
function fleet_usage_report(PDO $conn, string $from, string $to, int $companyId): array
{
    $checksReady = fleet_checks_ready($conn);
    $today = date('Y-m-d');

    $drivers = [];
    $vehicleRows = [];
    $weeks = [];
    $longTrips = [];
    $totals = ['trips' => 0, 'meters' => 0, 'seconds' => 0, 'ended' => 0, 'zero' => 0, 'days' => 0, 'days_checked' => 0];

    $trips = fleet_usage_trips($conn, $from, $to, $companyId);
    $lastTrip = fleet_usage_driver_last_trip($conn);
    $checks = $checksReady ? fleet_usage_checks($conn, $from, $to, $companyId) : [];
    $checksSince = $checksReady ? fleet_usage_checks_since($conn) : null;
    $openProblems = $checksReady ? fleet_usage_open_problems($conn, $companyId) : [];
    $checkItems = $checksReady ? fleet_daily_checklist_items() : [];

    // Which driver did a check on which day (any vehicle).
    $checkedDay = [];
    $checkCount = [];
    foreach ($checks as $c) {
        $checkedDay[(int)$c['driver_user_id']][(string)$c['check_date']] = true;
        $checkCount[(int)$c['driver_user_id']] = ($checkCount[(int)$c['driver_user_id']] ?? 0) + 1;
    }

    // Monday of every week in the period, so empty weeks still show as a gap.
    for ($w = strtotime('monday this week', strtotime($from)); $w <= strtotime($to); $w = strtotime('+1 week', $w)) {
        $weeks[date('Y-m-d', $w)] = ['trips' => 0, 'meters' => 0];
    }

    $byVehicle = [];
    $now = time();
    foreach ($trips as $t) {
        $uid = (int)$t['driver_user_id'];
        $vid = (int)$t['vehicle_id'];
        $start = strtotime((string)$t['started_at']);
        $ended = $t['ended_at'] !== null;
        $seconds = max(0, ($ended ? strtotime((string)$t['ended_at']) : $now) - $start);
        $meters = (int)$t['distance_m'];
        $day = date('Y-m-d', $start);
        $zero = $ended && $meters < FLEET_USAGE_ZERO_KM_METERS;

        $d = &$drivers[$uid];
        $d ??= ['name' => '', 'trips' => 0, 'meters' => 0, 'seconds' => 0, 'ended' => 0, 'zero' => 0, 'days' => [], 'recent' => 0, 'vehicles' => []];
        $d['name'] = (string)$t['driver_name']; // trips are oldest first, so this ends on the latest name
        $d['trips']++;
        $d['meters'] += $meters;
        $d['seconds'] += $seconds;
        $d['ended'] += $ended ? 1 : 0;
        $d['zero'] += $zero ? 1 : 0;
        $d['days'][$day] = true;
        $d['recent'] += $start >= strtotime('-7 days', strtotime($today)) ? 1 : 0;
        $d['vehicles'][$vid] = true;
        unset($d);

        $v = &$byVehicle[$vid];
        $v ??= ['trips' => 0, 'meters' => 0, 'drivers' => []];
        $v['trips']++;
        $v['meters'] += $meters;
        $v['drivers'][$uid] = ($v['drivers'][$uid] ?? 0) + 1;
        unset($v);

        $week = date('Y-m-d', strtotime('monday this week', $start));
        if (isset($weeks[$week])) {
            $weeks[$week]['trips']++;
            $weeks[$week]['meters'] += $meters;
        }

        if ($seconds >= FLEET_USAGE_LONG_TRIP_HOURS * 3600) {
            $longTrips[] = $t + ['seconds' => $seconds];
        }

        $totals['trips']++;
        $totals['meters'] += $meters;
        $totals['seconds'] += $seconds;
        $totals['ended'] += $ended ? 1 : 0;
        $totals['zero'] += $zero ? 1 : 0;
    }

    foreach ($drivers as $uid => &$d) {
        // Daily check rate: driving days since checks began, and how many had a check.
        $countable = array_filter(array_keys($d['days']), fn($day) => $checksSince !== null && $day >= $checksSince);
        $d['check_days'] = count($countable);
        $d['checked_days'] = count(array_filter($countable, fn($day) => isset($checkedDay[$uid][$day])));
        $d['checks'] = $checkCount[$uid] ?? 0;
        $d['last_at'] = $lastTrip[$uid] ?? null;
        $d['stopped'] = $d['last_at'] !== null
            && strtotime((string)$d['last_at']) < strtotime('-' . FLEET_USAGE_STOPPED_DAYS . ' days', $now);
        $totals['days'] += $d['check_days'];
        $totals['days_checked'] += $d['checked_days'];
    }
    unset($d);
    uasort($drivers, fn($a, $b) => $b['trips'] <=> $a['trips']);

    $driverNames = array_map(fn($d) => $d['name'], $drivers);
    foreach (fleet_usage_vehicles($conn, $companyId) as $v) {
        $used = $byVehicle[(int)$v['id']] ?? ['trips' => 0, 'meters' => 0, 'drivers' => []];
        arsort($used['drivers']);
        $v['trips'] = $used['trips'];
        $v['meters'] = $used['meters'];
        $v['main_drivers'] = array_slice(array_map(fn($uid) => $driverNames[$uid] ?? '', array_keys($used['drivers'])), 0, 2);
        $vehicleRows[] = $v;
    }
    usort($vehicleRows, fn($a, $b) => [$b['trips'], $b['meters']] <=> [$a['trips'], $a['meters']]);

    usort($longTrips, fn($a, $b) => $b['seconds'] <=> $a['seconds']);

    return [
        'checksReady' => $checksReady,
        'checkItems' => $checkItems,
        'totals' => $totals,
        'drivers' => $drivers,
        'vehicleRows' => $vehicleRows,
        'weeks' => $weeks,
        'longTrips' => $longTrips,
        'openProblems' => $openProblems,
        'activeDrivers' => count(array_filter($drivers, fn($d) => !$d['stopped'])),
        'vehiclesUsed' => count(array_filter($vehicleRows, fn($v) => $v['trips'] > 0)),
        'checkRate' => $totals['days'] > 0 ? (int)round(100 * $totals['days_checked'] / $totals['days']) : null,
        'zeroRate' => $totals['ended'] > 0 ? (int)round(100 * $totals['zero'] / $totals['ended']) : null,
        'maxWeekTrips' => max([1, ...array_column($weeks, 'trips')]),
        'maxWeekMeters' => max([1, ...array_column($weeks, 'meters')]),
    ];
}
