<?php
// hr/fleet_data.php — JSON for the fleet maps. ?live=1[&company_id=] or ?trip=ID

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_role(HR_FLEET_ROLES, $conn);

// Read-only: release the session so a map refreshing every 15 s never
// blocks another tab.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!fleet_tables_ready($conn)) {
    http_response_code(503);
    echo json_encode(['error' => 'Run migrations/fleet_tracking.sql first.']);
    exit;
}

if (isset($_GET['live'])) {
    echo json_encode([
        'rows' => fleet_live_rows($conn, (int)($_GET['company_id'] ?? 0)),
        'at' => date('H:i:s'),
    ]);
    exit;
}

$trip = isset($_GET['trip']) ? fleet_load_trip($conn, (int)$_GET['trip']) : null;
if (!$trip) {
    http_response_code(404);
    echo json_encode(['error' => 'Trip not found.']);
    exit;
}

echo json_encode([
    'trip' => [
        'id' => (int)$trip['id'],
        'plate_no' => (string)$trip['plate_no'],
        'vehicle_type' => (string)($trip['vehicle_type'] ?? ''),
        'driver_name' => (string)$trip['driver_name'],
        'open' => $trip['ended_at'] === null,
        'started' => date('d M H:i', strtotime((string)$trip['started_at'])),
        'duration' => fleet_format_duration((string)$trip['started_at'], $trip['ended_at']),
        'distance' => fleet_format_km((int)$trip['distance_m']),
        'route' => fleet_trip_route_label($trip),
    ],
    'stops' => array_map(static fn(array $s): array => [
        'name' => (string)$s['name'],
        'lat' => (float)$s['lat'],
        'lng' => (float)$s['lng'],
        'time' => fleet_hm($s['planned_time']),
        'arrived' => $s['arrived_at'] !== null ? date('H:i', strtotime($s['arrived_at'])) : null,
    ], fleet_trip_stops_shown($conn, (int)$trip['id'])),
    'points' => fleet_trip_points($conn, (int)$trip['id']),
]);
