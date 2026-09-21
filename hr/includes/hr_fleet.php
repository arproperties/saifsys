<?php
/**
 * Vehicle tracking — the rules both sides share.
 *
 * Used by the HR Fleet pages (hr/vehicles.php, hr/fleet_*.php) and by the
 * Driver app's API (api/mobile/fleet/). It opens no session and includes no
 * auth, so the stateless API can use it too.
 *
 * The one idea to keep in mind: a trip belongs to a VEHICLE. The driver is
 * written on each trip (user id + a name snapshot), so a car keeps a single
 * history however many people drive it. Schema: migrations/fleet_tracking.sql.
 */

declare(strict_types=1);

/** staff_app_access.app value that opens the Driver app. */
const FLEET_DRIVER_APP = 'driver';

function fleet_tables_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->query("SELECT 1 FROM fleet_trips LIMIT 1");
        $conn->query("SELECT 1 FROM staff_app_access LIMIT 1");
        $ready = true;
    } catch (PDOException $e) {
        $ready = false;
    }
    return $ready;
}

/** PHP's clock, not the database's, so every trip time comes from one place. */
function fleet_now(): string
{
    return date('Y-m-d H:i:s');
}

/** No point for this long = grey marker on the live map. */
function fleet_stale_seconds(): int
{
    return 5 * 60;
}

/** Fixes worse than this are stored but ignored for distance and route lines. */
function fleet_max_accuracy_m(): float
{
    return 50.0;
}

/**
 * Moves shorter than this from the last counted point are parked GPS jitter.
 * The anchor stays put, so slow crawling still adds up once it passes this.
 */
function fleet_min_hop_m(): float
{
    return 20.0;
}

/** A hop faster than this is a GPS jump, not driving. */
function fleet_max_speed_kmh(): float
{
    return 250.0;
}

function fleet_vehicle_types(): array
{
    return [
        'car' => 'Car',
        'van' => 'Van',
        'pickup' => 'Pickup',
        'truck' => 'Truck',
        'bus' => 'Bus',
        'bike' => 'Motorbike',
    ];
}

// ---------------------------------------------------------------------------
// App access — one PIN, a switch per app
// ---------------------------------------------------------------------------

function fleet_app_access_has(PDO $conn, int $userId, string $app = FLEET_DRIVER_APP): bool
{
    if ($userId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare("SELECT 1 FROM staff_app_access WHERE user_id = ? AND app = ? LIMIT 1");
        $stmt->execute([$userId, $app]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        // No table = nobody has access. Fail closed.
        return false;
    }
}

function fleet_app_access_set(PDO $conn, int $userId, bool $allowed, ?int $actorId, string $app = FLEET_DRIVER_APP): void
{
    if ($allowed) {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO staff_app_access (user_id, app, granted_at, granted_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $app, fleet_now(), $actorId]);
        return;
    }
    $stmt = $conn->prepare("DELETE FROM staff_app_access WHERE user_id = ? AND app = ?");
    $stmt->execute([$userId, $app]);
}

// ---------------------------------------------------------------------------
// Distance
// ---------------------------------------------------------------------------

/** Great-circle distance in metres. */
function fleet_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * 6371000.0 * asin(min(1.0, sqrt($a)));
}

/**
 * Walk points (oldest first) into a trip's running totals and its route stops.
 *
 * One walk, two callers: fleet_extend_trip() feeds it only a new batch,
 * starting from the totals saved on the trip — the normal case, and why the
 * server's work per send does not grow with the trip. fleet_recompute_trip()
 * feeds it every point from zero, for the rare batch that arrives out of order.
 *
 * $state: distance, count, anchor (last point that counted towards distance),
 * last_any, last_good, pos (the position already saved on the trip).
 */
function fleet_fold_points(array &$state, iterable $points, array &$stops): void
{
    foreach ($points as $p) {
        $state['count']++;
        $state['last_any'] = $p;
        if ($p['accuracy_m'] !== null && (float)$p['accuracy_m'] > fleet_max_accuracy_m()) {
            continue;
        }
        $state['last_good'] = $p;

        // Reached = first fix inside the stop's radius. Left = first fix after
        // that 50 m beyond it, so hovering at the edge does not count as leaving.
        foreach ($stops as &$stop) {
            $away = fleet_distance_m((float)$stop['lat'], (float)$stop['lng'], (float)$p['lat'], (float)$p['lng']);
            if ($stop['arrived_at'] === null) {
                if ($away <= (float)$stop['radius_m']) {
                    $stop['arrived_at'] = $p['recorded_at'];
                }
            } elseif ($stop['left_at'] === null && $away > (float)$stop['radius_m'] + 50) {
                $stop['left_at'] = $p['recorded_at'];
            }
        }
        unset($stop);

        $ts = (int)strtotime((string)$p['recorded_at']);
        $anchor = $state['anchor'];
        if ($anchor !== null) {
            $hop = fleet_distance_m($anchor['lat'], $anchor['lng'], (float)$p['lat'], (float)$p['lng']);
            if ($hop < fleet_min_hop_m() || $hop / max(1, $ts - $anchor['ts']) * 3.6 > fleet_max_speed_kmh()) {
                continue;
            }
            $state['distance'] += $hop;
        }
        $state['anchor'] = ['lat' => (float)$p['lat'], 'lng' => (float)$p['lng'], 'ts' => $ts];
    }
}

/** Stops ready for fleet_fold_points(), remembering what is saved so only changes are written. */
function fleet_watch_stops(array $stops, bool $fromScratch): array
{
    return array_map(static function (array $s) use ($fromScratch): array {
        $s['saved_arrived'] = $s['arrived_at'];
        $s['saved_left'] = $s['left_at'];
        if ($fromScratch) {
            $s['arrived_at'] = null;
            $s['left_at'] = null;
        }
        return $s;
    }, $stops);
}

function fleet_save_trip_state(PDO $conn, int $tripId, array $state, array $stops, ?string $seenAt = null): void
{
    // The live map shows the newest trustworthy fix; a poor one only when
    // nothing better exists. Its time is still the newest signal of any kind.
    $pos = $state['last_good'] ?? $state['pos'] ?? $state['last_any'];
    $lastAt = $state['last_any']['recorded_at'] ?? $state['pos']['recorded_at'] ?? null;
    $anchor = $state['anchor'];

    $sql = "UPDATE fleet_trips SET distance_m = ?, point_count = ?, last_lat = ?, last_lng = ?, last_speed_kmh = ?,
            last_point_at = ?, dist_anchor_lat = ?, dist_anchor_lng = ?, dist_anchor_at = ?";
    $params = [
        (int)round($state['distance']),
        $state['count'],
        $pos['lat'] ?? null,
        $pos['lng'] ?? null,
        $pos['speed_kmh'] ?? null,
        $lastAt,
        $anchor['lat'] ?? null,
        $anchor['lng'] ?? null,
        $anchor !== null ? date('Y-m-d H:i:s', $anchor['ts']) : null,
    ];
    if ($seenAt !== null) {
        $sql .= ", last_seen_at = ?";
        $params[] = $seenAt;
    }
    $params[] = $tripId;
    $conn->prepare($sql . " WHERE id = ?")->execute($params);

    $saveStop = $conn->prepare("UPDATE fleet_trip_stops SET arrived_at = ?, left_at = ? WHERE trip_id = ? AND stop_order = ?");
    foreach ($stops as $s) {
        if ($s['arrived_at'] !== $s['saved_arrived'] || $s['left_at'] !== $s['saved_left']) {
            $saveStop->execute([$s['arrived_at'], $s['left_at'], $tripId, $s['stop_order']]);
        }
    }
}

/**
 * Add a batch of new points to a trip, reading nothing but the batch.
 *
 * Only valid when every point is newer than the trip's newest one — the caller
 * checks, and holds the trip row FOR UPDATE so two sends cannot overlap.
 *
 * @param array $trip   the fleet_trips row
 * @param array $points lat, lng, speed_kmh, accuracy_m, recorded_at — oldest first
 */
function fleet_extend_trip(PDO $conn, array $trip, array $points, string $seenAt): void
{
    $state = [
        'distance' => (float)$trip['distance_m'],
        'count' => (int)$trip['point_count'],
        'anchor' => $trip['dist_anchor_at'] !== null
            ? ['lat' => (float)$trip['dist_anchor_lat'], 'lng' => (float)$trip['dist_anchor_lng'], 'ts' => (int)strtotime((string)$trip['dist_anchor_at'])]
            : null,
        'last_any' => null,
        'last_good' => null,
        'pos' => $trip['last_lat'] !== null
            ? ['lat' => $trip['last_lat'], 'lng' => $trip['last_lng'], 'speed_kmh' => $trip['last_speed_kmh'], 'recorded_at' => $trip['last_point_at']]
            : null,
    ];

    // Only stops not reached yet, or reached and not yet left, can still change.
    $stops = [];
    if (!empty($trip['route_id'])) {
        $stops = fleet_watch_stops(array_values(array_filter(
            fleet_trip_stops($conn, (int)$trip['id']),
            static fn(array $s): bool => $s['arrived_at'] === null || $s['left_at'] === null
        )), false);
    }

    fleet_fold_points($state, $points, $stops);
    fleet_save_trip_state($conn, (int)$trip['id'], $state, $stops, $seenAt);
}

/**
 * Count a trip again from all of its points. Only for points that arrived out
 * of order — a phone filling a gap in the middle of a route.
 */
function fleet_recompute_trip(PDO $conn, int $tripId): void
{
    $stmt = $conn->prepare("
        SELECT lat, lng, speed_kmh, accuracy_m, recorded_at
        FROM fleet_trip_points
        WHERE trip_id = ?
        ORDER BY recorded_at ASC
    ");
    $stmt->execute([$tripId]);

    $state = ['distance' => 0.0, 'count' => 0, 'anchor' => null, 'last_any' => null, 'last_good' => null, 'pos' => null];
    $stops = fleet_watch_stops(fleet_trip_stops($conn, $tripId), true);
    $rows = (static function () use ($stmt) {
        while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $p;
        }
    })();

    fleet_fold_points($state, $rows, $stops);
    fleet_save_trip_state($conn, $tripId, $state, $stops);
}

// ---------------------------------------------------------------------------
// Trips
// ---------------------------------------------------------------------------

function fleet_trip_select_sql(): string
{
    return "
        SELECT t.*, v.plate_no, v.name AS vehicle_name, v.vehicle_type, v.color AS vehicle_color,
               c.name AS company_name
        FROM fleet_trips t
        JOIN fleet_vehicles v ON v.id = t.vehicle_id
        LEFT JOIN companies c ON c.id = t.company_id
    ";
}

function fleet_load_trip(PDO $conn, int $tripId): ?array
{
    $stmt = $conn->prepare(fleet_trip_select_sql() . " WHERE t.id = ? LIMIT 1");
    $stmt->execute([$tripId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fleet_open_trip_for_driver(PDO $conn, int $userId): ?array
{
    $stmt = $conn->prepare(fleet_trip_select_sql() . " WHERE t.open_driver_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fleet_open_trip_for_vehicle(PDO $conn, int $vehicleId): ?array
{
    $stmt = $conn->prepare(fleet_trip_select_sql() . " WHERE t.open_vehicle_id = ? LIMIT 1");
    $stmt->execute([$vehicleId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Close a trip. Returns false when it was already closed, so a repeated Stop
 * (a phone retrying) changes nothing.
 *
 * @param int|null $endedBy office user; null when the driver stopped it
 */
function fleet_end_trip(PDO $conn, int $tripId, string $endedAt, ?int $endedBy): bool
{
    $stmt = $conn->prepare("
        UPDATE fleet_trips
        SET ended_at = GREATEST(started_at, ?), ended_by = ?
        WHERE id = ? AND ended_at IS NULL
    ");
    $stmt->execute([$endedAt, $endedBy, $tripId]);
    // No recount: the totals are already kept up to date by every send.
    return $stmt->rowCount() > 0;
}

/**
 * Trips for the history screens, newest first.
 *
 * @param array{vehicle_id?:int,driver_user_id?:int,company_id?:int,date_from?:string,date_to?:string} $f
 */
function fleet_trip_rows(PDO $conn, array $f, int $limit = 200): array
{
    $where = [];
    $params = [];
    if (!empty($f['vehicle_id'])) {
        $where[] = 't.vehicle_id = ?';
        $params[] = (int)$f['vehicle_id'];
    }
    if (!empty($f['driver_user_id'])) {
        $where[] = 't.driver_user_id = ?';
        $params[] = (int)$f['driver_user_id'];
    }
    if (!empty($f['company_id'])) {
        $where[] = 't.company_id = ?';
        $params[] = (int)$f['company_id'];
    }
    if (!empty($f['date_from'])) {
        $where[] = 't.started_at >= ?';
        $params[] = $f['date_from'] . ' 00:00:00';
    }
    if (!empty($f['date_to'])) {
        $where[] = 't.started_at <= ?';
        $params[] = $f['date_to'] . ' 23:59:59';
    }

    $sql = fleet_trip_select_sql()
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY t.started_at DESC LIMIT ' . max(1, $limit);
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * A trip's route for a map: [{lat,lng,t,speed}], oldest first.
 * Poor fixes are left out so the line does not zig-zag — unless that would
 * leave nothing to draw.
 */
function fleet_trip_points(PDO $conn, int $tripId): array
{
    $stmt = $conn->prepare("
        SELECT lat, lng, speed_kmh, accuracy_m, recorded_at
        FROM fleet_trip_points
        WHERE trip_id = ?
        ORDER BY recorded_at ASC
    ");
    $stmt->execute([$tripId]);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $good = array_values(array_filter(
        $all,
        static fn(array $p): bool => $p['accuracy_m'] === null || (float)$p['accuracy_m'] <= fleet_max_accuracy_m()
    ));
    $rows = $good ?: $all;

    return array_map(static fn(array $p): array => [
        'lat' => round((float)$p['lat'], 6),
        'lng' => round((float)$p['lng'], 6),
        't' => date('H:i', (int)strtotime((string)$p['recorded_at'])),
        'ts' => (int)strtotime((string)$p['recorded_at']),
        'speed' => $p['speed_kmh'] !== null ? (int)round((float)$p['speed_kmh']) : null,
    ], $rows);
}

/** A counted hop faster than this is flagged on the trip page as a likely GPS jump. */
function fleet_suspect_speed_kmh(): float
{
    return 150.0;
}

/** No fix for this long is a signal gap on the trip page. */
function fleet_gap_seconds(): int
{
    return 3 * 60;
}

/** Staying within this many metres for a while is a stop on the trip page. */
function fleet_stop_radius_m(): float
{
    return 50.0;
}

/** Shortest stop the trip page lists, unless the page asks for another. */
function fleet_stop_seconds(): int
{
    return 60;
}

/** Choices for "stops of at least" on the trip page, in seconds. */
function fleet_stop_second_options(): array
{
    return [60, 120, 180, 300, 600];
}

/**
 * Why a trip shows the km and time it does, for hr/fleet_trip.php.
 *
 * Walks the points with the same rules as fleet_fold_points(), so the
 * "counted" km here is what the trip's distance is made of. Returns every
 * point with what happened to it, plus the stops, signal gaps and
 * too-fast hops found along the way, and a timeline of drives and stops.
 *
 * @param int $minStop shortest stay in one place that counts as a stop, seconds
 */
function fleet_trip_analysis(PDO $conn, array $trip, int $minStop = 0): array
{
    $minStop = $minStop > 0 ? $minStop : fleet_stop_seconds();
    $stmt = $conn->prepare("
        SELECT lat, lng, speed_kmh, accuracy_m, recorded_at, received_at
        FROM fleet_trip_points
        WHERE trip_id = ?
        ORDER BY recorded_at ASC
    ");
    $stmt->execute([(int)$trip['id']]);

    $points = [];
    $good = [];
    $jumps = [];
    $gaps = [];
    $counted = 0.0;
    $poor = 0;
    $late = 0;
    $anchor = null;
    $prev = null;

    while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ts = (int)strtotime((string)$p['recorded_at']);
        $lat = (float)$p['lat'];
        $lng = (float)$p['lng'];
        $row = [
            'ts' => $ts,
            'lat' => $lat,
            'lng' => $lng,
            'speed' => $p['speed_kmh'] !== null ? (float)$p['speed_kmh'] : null,
            'accuracy' => $p['accuracy_m'] !== null ? (float)$p['accuracy_m'] : null,
            'gap_s' => $prev !== null ? $ts - $prev['ts'] : null,
            'late_s' => max(0, (int)strtotime((string)$p['received_at']) - $ts),
            'hop_m' => null,
            'hop_kmh' => null,
            'status' => 'first',
        ];
        if ($row['late_s'] > 300) {
            $late++;
        }

        if ($prev !== null && $row['gap_s'] >= fleet_gap_seconds()) {
            $gaps[] = [
                'from_ts' => $prev['ts'],
                'to_ts' => $ts,
                'seconds' => $row['gap_s'],
                'meters' => fleet_distance_m($prev['lat'], $prev['lng'], $lat, $lng),
                'from' => [$prev['lat'], $prev['lng']],
                'to' => [$lat, $lng],
            ];
        }
        $prev = $row;

        if ($row['accuracy'] !== null && $row['accuracy'] > fleet_max_accuracy_m()) {
            $row['status'] = 'poor';
            $poor++;
            $points[] = $row;
            continue;
        }
        $good[] = $row;

        if ($anchor !== null) {
            $hop = fleet_distance_m($anchor['lat'], $anchor['lng'], $lat, $lng);
            $kmh = $hop / max(1, $ts - $anchor['ts']) * 3.6;
            $row['hop_m'] = $hop;
            $row['hop_kmh'] = $kmh;
            if ($hop < fleet_min_hop_m()) {
                $row['status'] = 'still';
                $points[] = $row;
                continue;
            }
            if ($kmh > fleet_max_speed_kmh()) {
                $row['status'] = 'dropped';
                $points[] = $row;
                continue;
            }
            $counted += $hop;
            $row['status'] = 'counted';
            if ($kmh > fleet_suspect_speed_kmh()) {
                $row['status'] = 'suspect';
                $jumps[] = [
                    'ts' => $ts,
                    'meters' => $hop,
                    'kmh' => $kmh,
                    'from' => [$anchor['lat'], $anchor['lng']],
                    'to' => [$lat, $lng],
                ];
            }
        }
        $anchor = ['lat' => $lat, 'lng' => $lng, 'ts' => $ts];
        $points[] = $row;
    }

    // Stops: from each good fix, how long the vehicle stayed within the
    // radius of it. A gap while parked still counts, as both ends are close.
    $stops = [];
    $n = count($good);
    for ($i = 0; $i < $n;) {
        $j = $i;
        while ($j + 1 < $n
            && fleet_distance_m($good[$i]['lat'], $good[$i]['lng'], $good[$j + 1]['lat'], $good[$j + 1]['lng']) <= fleet_stop_radius_m()) {
            $j++;
        }
        if ($good[$j]['ts'] - $good[$i]['ts'] >= $minStop) {
            $stops[] = [
                'from_ts' => $good[$i]['ts'],
                'to_ts' => $good[$j]['ts'],
                'seconds' => $good[$j]['ts'] - $good[$i]['ts'],
                'lat' => $good[$i]['lat'],
                'lng' => $good[$i]['lng'],
                'at_start' => $i === 0,
                'at_end' => $j === $n - 1,
            ];
            $i = $j + 1;
        } else {
            $i++;
        }
    }

    // Pulling forward a few metres at the same place is still one stop.
    $merged = [];
    foreach ($stops as $stop) {
        $last = $merged ? $merged[count($merged) - 1] : null;
        if ($last !== null && $stop['from_ts'] - $last['to_ts'] <= 60
            && fleet_distance_m($last['lat'], $last['lng'], $stop['lat'], $stop['lng']) <= 3 * fleet_stop_radius_m()) {
            $last['to_ts'] = $stop['to_ts'];
            $last['seconds'] = $last['to_ts'] - $last['from_ts'];
            $last['at_end'] = $stop['at_end'];
            $merged[count($merged) - 1] = $last;
            continue;
        }
        $merged[] = $stop;
    }
    $stops = $merged;

    // A stop at a pickup point is named after it.
    $pickups = [];
    if (fleet_routes_ready($conn)) {
        $pickups = $conn->query("SELECT name, lat, lng, radius_m FROM fleet_pickup_points WHERE status = 'active'")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    foreach ($stops as &$stop) {
        $stop['pickup'] = null;
        $best = null;
        foreach ($pickups as $pp) {
            $away = fleet_distance_m($stop['lat'], $stop['lng'], (float)$pp['lat'], (float)$pp['lng']);
            if ($away <= (float)$pp['radius_m'] && ($best === null || $away < $best)) {
                $best = $away;
                $stop['pickup'] = (string)$pp['name'];
            }
        }
    }
    unset($stop);

    // Timeline: the drive before each stop, the stop, and the drive after the last one.
    $timeline = [];
    if ($points) {
        $cursor = $points[0]['ts'];
        $number = 0;
        foreach ($stops as $stop) {
            if ($stop['from_ts'] > $cursor) {
                $timeline[] = fleet_trip_leg($points, $cursor, $stop['from_ts']);
            }
            $timeline[] = ['type' => 'stop', 'number' => ++$number] + $stop;
            $cursor = $stop['to_ts'];
        }
        $lastTs = $points[count($points) - 1]['ts'];
        if ($lastTs > $cursor) {
            $timeline[] = fleet_trip_leg($points, $cursor, $lastTs);
        }
    }

    $start = (int)strtotime((string)$trip['started_at']);
    $end = $trip['ended_at'] !== null ? (int)strtotime((string)$trip['ended_at']) : time();

    return [
        'points' => $points,
        'stops' => $stops,
        'gaps' => $gaps,
        'jumps' => $jumps,
        'counted_m' => $counted,
        'poor' => $poor,
        'late' => $late,
        'start_ts' => $start,
        'end_ts' => $end,
        'first_ts' => $points ? $points[0]['ts'] : null,
        'last_ts' => $points ? $points[count($points) - 1]['ts'] : null,
        'stopped_s' => array_sum(array_column($stops, 'seconds')),
        'min_stop_s' => $minStop,
        'timeline' => $timeline,
    ];
}

/** One drive between two moments of a trip, from fleet_trip_analysis() points. */
function fleet_trip_leg(array $points, int $from, int $to): array
{
    $meters = 0.0;
    $top = null;
    $path = [];
    foreach ($points as $p) {
        if ($p['ts'] < $from || $p['ts'] > $to || $p['status'] === 'poor') {
            continue;
        }
        $path[] = [$p['lat'], $p['lng']];
        if ($p['ts'] > $from && in_array($p['status'], ['counted', 'suspect'], true)) {
            $meters += $p['hop_m'];
        }
        if ($p['speed'] !== null && ($top === null || $p['speed'] > $top)) {
            $top = $p['speed'];
        }
    }
    $seconds = $to - $from;
    return [
        'type' => 'drive',
        'from_ts' => $from,
        'to_ts' => $to,
        'seconds' => $seconds,
        'meters' => $meters,
        'avg_kmh' => $seconds > 0 ? $meters / $seconds * 3.6 : null,
        'max_kmh' => $top,
        'path' => $path,
    ];
}

/**
 * Plain-language findings for the trip page, worst first.
 *
 * @return list<array{level:string,text:string}> level: danger, warning, info, success
 */
function fleet_trip_findings(array $trip, array $a): array
{
    $out = [];
    $saved = (int)$trip['distance_m'];
    $km = static fn(float $m): string => number_format($m / 1000, 1) . ' km';
    $mins = 'fleet_format_seconds';
    $hm = static fn(int $ts): string => date('H:i', $ts);

    if (!$a['points']) {
        return [['level' => 'danger', 'text' => 'The phone sent no GPS points for this trip, so there is no route and no distance.']];
    }

    if (abs($saved - $a['counted_m']) > 200) {
        $out[] = ['level' => 'warning', 'text' => 'The trip shows ' . $km($saved) . ' but its points add up to ' . $km($a['counted_m'])
            . '. Points probably arrived out of order; the difference is not explained by this page.'];
    }

    if ($a['jumps']) {
        $jumpM = array_sum(array_column($a['jumps'], 'meters'));
        $top = max(array_column($a['jumps'], 'kmh'));
        $out[] = ['level' => 'danger', 'text' => count($a['jumps']) . ' hop(s) faster than ' . (int)fleet_suspect_speed_kmh()
            . ' km/h were counted (fastest ' . (int)round($top) . ' km/h). They add ' . $km($jumpM)
            . ' — most likely GPS jumps, not driving. Marked red on the map.'];
    }

    $endStop = null;
    foreach ($a['stops'] as $s) {
        if ($s['at_end']) {
            $endStop = $s;
        }
    }
    if ($endStop !== null) {
        $parked = $a['end_ts'] - $endStop['from_ts'];
        if ($parked >= 10 * 60) {
            $out[] = ['level' => 'danger', 'text' => 'The vehicle was parked from ' . $hm($endStop['from_ts']) . ' until the trip ended at '
                . $hm($a['end_ts']) . ' (' . $mins($parked) . '). The trip was probably left running after the drive.'];
        }
    }
    if ($a['last_ts'] !== null && $a['end_ts'] - $a['last_ts'] >= 10 * 60) {
        $out[] = ['level' => 'warning', 'text' => 'No GPS for the last ' . $mins($a['end_ts'] - $a['last_ts']) . ' of the trip (last point '
            . $hm($a['last_ts']) . ', ended ' . $hm($a['end_ts']) . '). The phone stopped sending before Stop was pressed.'];
    }
    if ($a['first_ts'] !== null && $a['first_ts'] - $a['start_ts'] >= 5 * 60) {
        $out[] = ['level' => 'warning', 'text' => 'First GPS point came ' . $mins($a['first_ts'] - $a['start_ts']) . ' after Start was pressed.'];
    }

    if ($a['gaps']) {
        $gapS = array_sum(array_column($a['gaps'], 'seconds'));
        $gapM = array_sum(array_column($a['gaps'], 'meters'));
        $out[] = ['level' => 'warning', 'text' => count($a['gaps']) . ' signal gap(s) of ' . (int)(fleet_gap_seconds() / 60)
            . '+ min, ' . $mins($gapS) . ' in total. The route draws a straight line across them (' . $km($gapM)
            . '), so the real road distance there is missing. Dashed orange on the map.'];
    }

    $middle = array_filter($a['stops'], static fn(array $s): bool => !$s['at_end']);
    if ($middle) {
        $out[] = ['level' => 'info', 'text' => count($middle) . ' stop(s) of ' . (int)($a['min_stop_s'] / 60) . '+ min, '
            . $mins((int)array_sum(array_column($middle, 'seconds'))) . ' in total. Numbered on the map and listed in the timeline below.'];
    }
    if ($a['poor']) {
        $out[] = ['level' => 'info', 'text' => $a['poor'] . ' of ' . count($a['points']) . ' points had poor accuracy (over '
            . (int)fleet_max_accuracy_m() . ' m) and were left out of the distance and route.'];
    }
    if ($a['late']) {
        $out[] = ['level' => 'info', 'text' => $a['late'] . ' points reached the server more than 5 min late — the phone was offline and sent them afterwards.'];
    }

    if (!$out) {
        $out[] = ['level' => 'success', 'text' => 'Nothing unusual: the distance comes from continuous driving with no jumps, gaps or long stops.'];
    }
    return $out;
}

/** Vehicles currently on a trip, shaped for the live map. */
function fleet_live_rows(PDO $conn, int $companyId = 0): array
{
    $sql = fleet_trip_select_sql() . " WHERE t.ended_at IS NULL"
        . ($companyId > 0 ? " AND t.company_id = ?" : "")
        . " ORDER BY v.plate_no";
    $stmt = $conn->prepare($sql);
    $stmt->execute($companyId > 0 ? [$companyId] : []);

    $now = time();
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
        $lastPoint = $t['last_point_at'] !== null ? (int)strtotime((string)$t['last_point_at']) : null;
        $rows[] = [
            'trip_id' => (int)$t['id'],
            'vehicle_id' => (int)$t['vehicle_id'],
            'plate_no' => (string)$t['plate_no'],
            'vehicle_name' => (string)($t['vehicle_name'] ?? ''),
            'vehicle_type' => (string)($t['vehicle_type'] ?? ''),
            'driver_name' => (string)$t['driver_name'],
            'company_name' => (string)($t['company_name'] ?? ''),
            'lat' => $t['last_lat'] !== null ? (float)$t['last_lat'] : null,
            'lng' => $t['last_lng'] !== null ? (float)$t['last_lng'] : null,
            'speed_kmh' => $t['last_speed_kmh'] !== null ? (int)round((float)$t['last_speed_kmh']) : null,
            'started' => date('H:i', (int)strtotime((string)$t['started_at'])),
            'duration' => fleet_format_duration((string)$t['started_at'], null),
            'distance' => fleet_format_km((int)$t['distance_m']),
            'last_seen' => fleet_ago($t['last_point_at']),
            'stale' => $lastPoint === null || $now - $lastPoint > fleet_stale_seconds(),
            'route' => fleet_trip_route_label($t),
            'stops' => array_map(static fn(array $s): array => [
                'name' => (string)$s['name'],
                'lat' => (float)$s['lat'],
                'lng' => (float)$s['lng'],
                'time' => fleet_hm($s['planned_time']),
                'done' => $s['arrived_at'] !== null,
            ], fleet_trip_stops_shown($conn, (int)$t['id'])),
        ];
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// Pickup points and routes — see migrations/fleet_pickup_routes.sql
// ---------------------------------------------------------------------------

/**
 * Pickup points and routes: on. They were hidden for a day in Sept 2026 and
 * turned back on 2026-09-12. Set this to false to hide the menu items, the
 * three pages, stops on new trips, and route stops on the maps and in the
 * Driver app — without removing any code, tables or data.
 */
function fleet_routes_enabled(): bool
{
    return true;
}

/** A trip's stops for showing — none while routes are switched off. */
function fleet_trip_stops_shown(PDO $conn, int $tripId): array
{
    return fleet_routes_enabled() ? fleet_trip_stops($conn, $tripId) : [];
}

function fleet_routes_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT 1 FROM fleet_trip_stops LIMIT 1");
            $conn->query("SELECT route_id FROM fleet_trips LIMIT 1");
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Before noon a route runs as the morning pickup; after, as the evening drop-off. */
function fleet_direction_for(int $ts): string
{
    return (int)date('G', $ts) < 12 ? 'pickup' : 'dropoff';
}

function fleet_direction_label(?string $direction): string
{
    return ['pickup' => 'Morning pickup', 'dropoff' => 'Evening drop-off'][$direction ?? ''] ?? '';
}

/** "MOE run · Morning pickup", or '' for a trip without a route. */
function fleet_trip_route_label(array $trip): string
{
    if (!fleet_routes_enabled() || empty($trip['route_name'])) {
        return '';
    }
    return $trip['route_name'] . ' · ' . fleet_direction_label($trip['direction'] ?? null);
}

/** 'HH:MM' from a TIME column, or null. */
function fleet_hm(?string $time): ?string
{
    return $time === null || $time === '' ? null : substr($time, 0, 5);
}

/** A stop reached more than this many minutes after its time is late. */
function fleet_late_grace_minutes(): int
{
    return 5;
}

function fleet_route_for_vehicle(PDO $conn, int $vehicleId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM fleet_routes WHERE vehicle_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$vehicleId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fleet_route_stop_rows(PDO $conn, int $routeId, bool $activeOnly = false): array
{
    $stmt = $conn->prepare("
        SELECT s.stop_order, s.pickup_time, s.dropoff_time,
               p.id AS point_id, p.name, p.lat, p.lng, p.radius_m, p.status
        FROM fleet_route_stops s
        JOIN fleet_pickup_points p ON p.id = s.point_id
        WHERE s.route_id = ?" . ($activeOnly ? " AND p.status = 'active'" : "") . "
        ORDER BY s.stop_order
    ");
    $stmt->execute([$routeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Give a new trip its vehicle's route. The stops are COPIED onto the trip, so
 * editing or removing the route later never changes what a past trip shows.
 */
function fleet_attach_route(PDO $conn, int $tripId, int $vehicleId, string $startedAt): void
{
    if (!fleet_routes_enabled() || !fleet_routes_ready($conn)) {
        return;
    }
    $route = fleet_route_for_vehicle($conn, $vehicleId);
    if (!$route) {
        return;
    }

    $direction = fleet_direction_for((int)strtotime($startedAt));
    $stops = fleet_route_stop_rows($conn, (int)$route['id'], true);
    if ($direction === 'dropoff') {
        $stops = array_reverse($stops);
    }

    $conn->prepare("UPDATE fleet_trips SET route_id = ?, route_name = ?, direction = ? WHERE id = ?")
        ->execute([(int)$route['id'], $route['name'], $direction, $tripId]);

    $insert = $conn->prepare("
        INSERT INTO fleet_trip_stops (trip_id, stop_order, point_id, name, lat, lng, radius_m, planned_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach (array_values($stops) as $i => $s) {
        $insert->execute([
            $tripId, $i + 1, (int)$s['point_id'], $s['name'], $s['lat'], $s['lng'], (int)$s['radius_m'],
            $direction === 'pickup' ? $s['pickup_time'] : $s['dropoff_time'],
        ]);
    }
}

function fleet_trip_stops(PDO $conn, int $tripId): array
{
    try {
        $stmt = $conn->prepare("SELECT * FROM fleet_trip_stops WHERE trip_id = ? ORDER BY stop_order");
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/** Minutes after its planned time a stop was reached (negative = early), or null. */
function fleet_stop_late_minutes(array $trip, array $stop): ?int
{
    if ($stop['planned_time'] === null || $stop['arrived_at'] === null) {
        return null;
    }
    $planned = (int)strtotime(substr((string)$trip['started_at'], 0, 10) . ' ' . $stop['planned_time']);
    return (int)round(((int)strtotime((string)$stop['arrived_at']) - $planned) / 60);
}

// ---------------------------------------------------------------------------
// Lookups and wording
// ---------------------------------------------------------------------------

function fleet_vehicle_options(PDO $conn): array
{
    return $conn->query("
        SELECT id, plate_no, name, status FROM fleet_vehicles ORDER BY status = 'inactive', plate_no
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Everyone who has driven, by the name on their latest trip. */
function fleet_driver_options(PDO $conn): array
{
    return $conn->query("
        SELECT t.driver_user_id, t.driver_name
        FROM fleet_trips t
        JOIN (SELECT driver_user_id, MAX(id) AS last_id FROM fleet_trips GROUP BY driver_user_id) x
          ON x.last_id = t.id
        ORDER BY t.driver_name
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fleet_format_duration(string $start, ?string $end): string
{
    $to = ($end !== null && $end !== '') ? (int)strtotime($end) : time();
    return fleet_format_seconds($to - (int)strtotime($start));
}

function fleet_format_seconds(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}

function fleet_format_km(int $meters): string
{
    return number_format($meters / 1000, 1) . ' km';
}

function fleet_ago(?string $when): string
{
    if ($when === null || $when === '') {
        return 'no signal yet';
    }
    $s = max(0, time() - (int)strtotime($when));
    if ($s < 60) {
        return 'just now';
    }
    if ($s < 3600) {
        return intdiv($s, 60) . ' min ago';
    }
    if ($s < 86400) {
        return intdiv($s, 3600) . ' h ago';
    }
    return date('d M H:i', (int)strtotime($when));
}
