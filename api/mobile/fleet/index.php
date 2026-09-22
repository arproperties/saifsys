<?php
/**
 * Driver app API — front controller.
 * Same envelope as the ops API: {"ok":true,"data":…} / {"ok":false,"error":…}.
 */

declare(strict_types=1);

require_once __DIR__ . '/fleet_api.php';
require_once __DIR__ . '/fleet_endpoints.php';
require_once dirname(__DIR__, 3) . '/hr/includes/hr_fleet_checks.php';

fleet_api_boot($conn);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = fleet_api_parse_route();

if ($route === 'health' && $method === 'GET') {
    customer_api_send_ok(['version' => 'fleet-v1']);
}

// The only route without a token — it is where tokens come from.
if ($route === 'auth/pin' && $method === 'POST') {
    fleet_api_handle_pin_login($conn);
}

$driver = fleet_api_current_driver($conn);

if ($route === 'auth/me' && $method === 'GET') {
    customer_api_send_ok(['user' => $driver]);
}

if ($route === 'vehicles' && $method === 'GET') {
    fleet_api_handle_vehicles($conn, $driver);
}

if ($route === 'trips/current' && $method === 'GET') {
    fleet_api_handle_current_trip($conn, $driver);
}

if ($route === 'checks/today' && $method === 'GET') {
    fleet_api_handle_check_today($conn, $driver);
}

if ($route === 'checks' && $method === 'POST') {
    fleet_api_handle_check_save($conn, $driver);
}

if ($route === 'trips/start' && $method === 'POST') {
    fleet_api_handle_trip_start($conn, $driver);
}

if ($method === 'POST' && preg_match('#^trips/(\d+)/points$#', $route, $m)) {
    fleet_api_handle_trip_points($conn, $driver, (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^trips/(\d+)/stop$#', $route, $m)) {
    fleet_api_handle_trip_stop($conn, $driver, (int)$m[1]);
}

customer_api_send_error('not_found', 'Not found', 404);
