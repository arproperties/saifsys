<?php
// hr/fleet_trip_end.php — the office ends a trip the driver forgot to stop.
// The phone learns on its next send (the API answers trip_open: false) and
// stops recording by itself.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_role(HR_FLEET_ROLES, $conn);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: fleet_live');
    exit;
}
csrf_verify();

// Only back to one of the fleet pages.
$back = (string)($_POST['back'] ?? '');
if (!preg_match('#^(vehicle_view|fleet_history|fleet_live)(\?[A-Za-z0-9_=&%.\-]*)?$#', $back)) {
    $back = 'fleet_live';
}

$tripId = (int)($_POST['trip_id'] ?? 0);
$trip = fleet_tables_ready($conn) ? fleet_load_trip($conn, $tripId) : null;
$uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

if (!$trip) {
    $_SESSION['flash_error'] = 'That trip was not found.';
} elseif (!fleet_end_trip($conn, $tripId, fleet_now(), $uid)) {
    $_SESSION['flash_error'] = 'That trip had already ended.';
} else {
    audit_bridge_hr_ops(
        'fleet_trip_ended_by_office',
        'fleet_trips',
        $tripId,
        'Ended trip #' . $tripId . ' of ' . $trip['plate_no'] . ' (driver ' . $trip['driver_name'] . ') from HR',
        (int)$trip['company_id'],
        ['trip_id' => $tripId, 'vehicle_id' => (int)$trip['vehicle_id'], 'driver_user_id' => (int)$trip['driver_user_id']],
        'Trip #' . $tripId,
        $uid
    );
    $_SESSION['flash_success'] = 'Trip ended for ' . $trip['plate_no'] . ' (' . $trip['driver_name'] . '). Their phone stops recording on its next send.';
}

header('Location: ' . $back);
