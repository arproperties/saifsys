<?php
/**
 * Driver app — route handlers.
 *
 * Everything except sign-in runs after fleet_api_current_driver() has proved
 * who is calling. Trip rules shared with the HR pages live in
 * hr/includes/hr_fleet.php.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// POST auth/pin
// ---------------------------------------------------------------------------

/**
 * Same PIN and same lockout as the Operations app (see
 * ops_api_handle_pin_login). A PIN that is real but has no Driver access gets
 * the SAME answer as a wrong one and counts as a failed guess — otherwise this
 * app would tell a guesser which PINs open the Operations app.
 */
function fleet_api_handle_pin_login(PDO $conn): void
{
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
    $allowed = $person !== null && fleet_app_access_has($conn, (int)$person['id'], FLEET_DRIVER_APP);

    if (!$allowed) {
        ops_pin_record_attempt($conn, $deviceId, $ip, $person['id'] ?? null, false);
        ops_pin_prune_attempts($conn);
        customer_api_send_error('bad_pin', 'That PIN did not work.', 401);
    }

    ops_pin_record_attempt($conn, $deviceId, $ip, (int)$person['id'], true);
    ops_pin_prune_attempts($conn);

    $issued = fleet_api_issue_token((int)$person['id'], (int)$person['epoch']);

    customer_api_send_ok([
        'token' => $issued['token'],
        'expires_in' => $issued['expires_in'],
        'user' => ['id' => (int)$person['id'], 'name' => (string)$person['name']],
    ]);
}

// ---------------------------------------------------------------------------
// GET vehicles
// ---------------------------------------------------------------------------

/** Every active vehicle, across companies, flagged when someone else has it. */
function fleet_api_handle_vehicles(PDO $conn, array $driver): void
{
    $rows = $conn->query("
        SELECT v.id, v.plate_no, v.name, v.vehicle_type, v.color, c.name AS company_name,
               t.driver_user_id AS busy_user_id, t.driver_name AS busy_name
        FROM fleet_vehicles v
        LEFT JOIN companies c ON c.id = v.company_id
        LEFT JOIN fleet_trips t ON t.open_vehicle_id = v.id
        WHERE v.status = 'active'
        ORDER BY v.plate_no
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $last = $conn->prepare("SELECT vehicle_id FROM fleet_trips WHERE driver_user_id = ? ORDER BY id DESC LIMIT 1");
    $last->execute([$driver['id']]);
    $lastVehicleId = $last->fetchColumn();

    $vehicleTypes = fleet_vehicle_types();

    customer_api_send_ok([
        'vehicles' => array_map(static function (array $v) use ($driver, $vehicleTypes): array {
            $busyByOther = $v['busy_user_id'] !== null && (int)$v['busy_user_id'] !== $driver['id'];
            return [
                'id' => (int)$v['id'],
                'plate_no' => (string)$v['plate_no'],
                'name' => $v['name'] !== null && $v['name'] !== '' ? (string)$v['name'] : null,
                'vehicle_type' => $v['vehicle_type'] !== null && $v['vehicle_type'] !== '' ? (string)$v['vehicle_type'] : null,
                'type_label' => $vehicleTypes[(string)$v['vehicle_type']] ?? null,
                'color' => $v['color'] !== null && $v['color'] !== '' ? (string)$v['color'] : null,
                'company_name' => $v['company_name'] !== null ? (string)$v['company_name'] : null,
                'busy_with' => $busyByOther ? (string)$v['busy_name'] : null,
            ];
        }, $rows),
        'last_vehicle_id' => $lastVehicleId !== false ? (int)$lastVehicleId : null,
    ]);
}

// ---------------------------------------------------------------------------
// Trips
// ---------------------------------------------------------------------------

/** The driver's open trip, so the app can pick up where it left off. */
function fleet_api_handle_current_trip(PDO $conn, array $driver): void
{
    $trip = fleet_open_trip_for_driver($conn, $driver['id']);
    customer_api_send_ok(['trip' => $trip ? fleet_api_trip_row($conn, $trip) : null]);
}

function fleet_api_handle_trip_start(PDO $conn, array $driver): void
{
    $body = customer_api_read_json_body();
    $vehicleId = (int)($body['vehicle_id'] ?? 0);

    $stmt = $conn->prepare("SELECT id, company_id, plate_no FROM fleet_vehicles WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$vehicleId]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vehicle) {
        customer_api_send_error('not_found', 'That vehicle is not available any more. Pick another one.', 404);
    }

    $mine = fleet_open_trip_for_driver($conn, $driver['id']);
    if ($mine) {
        // Same vehicle = a retried Start whose reply got lost. Hand it back.
        if ((int)$mine['vehicle_id'] === $vehicleId) {
            customer_api_send_ok(['trip' => fleet_api_trip_row($conn, $mine)]);
        }
        customer_api_send_error('conflict', 'You are already on a trip in ' . $mine['plate_no'] . '. Stop that trip first.', 409);
    }

    $theirs = fleet_open_trip_for_vehicle($conn, $vehicleId);
    if ($theirs) {
        customer_api_send_error(
            'conflict',
            $vehicle['plate_no'] . ' is already on a trip with ' . $theirs['driver_name'] . '. Ask the office if that is wrong.',
            409
        );
    }

    $startedAt = fleet_now();
    try {
        $conn->beginTransaction();
        $insert = $conn->prepare("
            INSERT INTO fleet_trips (company_id, vehicle_id, driver_user_id, driver_name, started_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert->execute([(int)$vehicle['company_id'], $vehicleId, $driver['id'], $driver['name'], $startedAt]);
        $tripId = (int)$conn->lastInsertId();
        // The vehicle's route, if the office gave it one, becomes this trip's stops.
        fleet_attach_route($conn, $tripId, $vehicleId, $startedAt);
        $conn->commit();
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        // The open-trip unique keys: someone pressed Start on it a moment ago.
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            customer_api_send_error('conflict', $vehicle['plate_no'] . ' was just taken by someone else. Pick again.', 409);
        }
        throw $e;
    }

    $trip = fleet_load_trip($conn, $tripId);
    customer_api_send_ok(['trip' => fleet_api_trip_row($conn, $trip)]);
}

/**
 * A batch of GPS fixes.
 *
 * Each point: {lat, lng, t (ms), speed (m/s), heading, accuracy (m)}. Points
 * outside the trip's time window are skipped, repeats are ignored by the
 * unique key, and the reply says whether the trip is still open — that is how
 * a phone learns the office ended it.
 */
function fleet_api_handle_trip_points(PDO $conn, array $driver, int $tripId): void
{
    $trip = fleet_api_own_trip($conn, $driver, $tripId);

    $points = customer_api_read_json_body()['points'] ?? null;
    if (!is_array($points)) {
        customer_api_send_error('bad_request', 'No points were sent.', 400);
    }
    if (count($points) > 500) {
        customer_api_send_error('bad_request', 'Too many points in one request.', 400);
    }

    $from = (int)strtotime((string)$trip['started_at']) - 120;
    $to = $trip['ended_at'] !== null ? (int)strtotime((string)$trip['ended_at']) + 120 : time() + 300;

    $rows = [];
    foreach ($points as $p) {
        if (!is_array($p) || !is_numeric($p['lat'] ?? null) || !is_numeric($p['lng'] ?? null) || !is_numeric($p['t'] ?? null)) {
            continue;
        }
        $lat = (float)$p['lat'];
        $lng = (float)$p['lng'];
        $ts = intdiv((int)$p['t'], 1000);
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0) || $ts < $from || $ts > $to) {
            continue;
        }
        $speed = is_numeric($p['speed'] ?? null) && (float)$p['speed'] >= 0 ? min(999.0, round((float)$p['speed'] * 3.6, 2)) : null;
        $heading = is_numeric($p['heading'] ?? null) && (float)$p['heading'] >= 0 ? (int)round((float)$p['heading']) % 360 : null;
        $accuracy = is_numeric($p['accuracy'] ?? null) && (float)$p['accuracy'] >= 0 ? min(99999.0, round((float)$p['accuracy'], 2)) : null;

        $recordedAt = date('Y-m-d H:i:s', $ts);
        // Two fixes in the same second are one point, exactly as the unique key sees it.
        if (!isset($rows[$recordedAt])) {
            $rows[$recordedAt] = [
                'lat' => $lat,
                'lng' => $lng,
                'speed_kmh' => $speed,
                'heading' => $heading,
                'accuracy_m' => $accuracy,
                'recorded_at' => $recordedAt,
            ];
        }
    }
    ksort($rows);

    $receivedAt = fleet_now();
    $accepted = 0;

    // One send at a time per trip. The row lock stops two overlapping sends (a
    // phone retrying while its first try is still running) from counting the
    // same stretch of road twice.
    $conn->beginTransaction();
    try {
        $lock = $conn->prepare("SELECT * FROM fleet_trips WHERE id = ? FOR UPDATE");
        $lock->execute([$tripId]);
        $locked = $lock->fetch(PDO::FETCH_ASSOC);

        if ($rows) {
            $params = [];
            foreach ($rows as $r) {
                array_push($params, $tripId, $r['lat'], $r['lng'], $r['speed_kmh'], $r['heading'], $r['accuracy_m'], $r['recorded_at'], $receivedAt);
            }
            $insert = $conn->prepare("
                INSERT IGNORE INTO fleet_trip_points
                    (trip_id, lat, lng, speed_kmh, heading, accuracy_m, recorded_at, received_at)
                VALUES " . implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, ?, ?)'))
            );
            $insert->execute($params);
            // Points the server already had are ignored here — a resend adds nothing.
            $accepted = $insert->rowCount();
        }

        $newer = array_values(array_filter(
            $rows,
            static fn(array $r): bool => $locked['last_point_at'] === null || $r['recorded_at'] > $locked['last_point_at']
        ));

        if ($accepted > 0 && $accepted === count($newer)) {
            // The normal case: everything new comes after what is counted, so
            // only this batch is read, however long the trip has been running.
            fleet_extend_trip($conn, $locked, $newer, $receivedAt);
        } elseif ($accepted > 0) {
            // Older points filled a gap mid-route (a phone catching up out of order).
            fleet_recompute_trip($conn, $tripId);
            $conn->prepare("UPDATE fleet_trips SET last_seen_at = ? WHERE id = ?")->execute([$receivedAt, $tripId]);
        } else {
            $conn->prepare("UPDATE fleet_trips SET last_seen_at = ? WHERE id = ?")->execute([$receivedAt, $tripId]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    $trip = fleet_load_trip($conn, $tripId);
    customer_api_send_ok([
        'accepted' => $accepted,
        'trip_open' => $trip['ended_at'] === null,
        'trip' => fleet_api_trip_row($conn, $trip),
    ]);
}

/**
 * Stop. The phone sends the moment Stop was pressed (ended_at_ms), because it
 * may only reach the server later; anything implausible falls back to now.
 * Stopping a trip that is already closed is a success, not an error.
 */
function fleet_api_handle_trip_stop(PDO $conn, array $driver, int $tripId): void
{
    $trip = fleet_api_own_trip($conn, $driver, $tripId);

    if ($trip['ended_at'] === null) {
        $endTs = time();
        $sent = customer_api_read_json_body()['ended_at_ms'] ?? null;
        if (is_numeric($sent)) {
            $candidate = intdiv((int)$sent, 1000);
            if ($candidate >= (int)strtotime((string)$trip['started_at']) && $candidate <= time()) {
                $endTs = $candidate;
            }
        }
        fleet_end_trip($conn, $tripId, date('Y-m-d H:i:s', $endTs), null);
        $trip = fleet_load_trip($conn, $tripId);
    }

    customer_api_send_ok(['trip' => fleet_api_trip_row($conn, $trip)]);
}
