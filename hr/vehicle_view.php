<?php
// hr/vehicle_view.php — one vehicle and its own history: every trip, by any driver.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_role(HR_FLEET_ROLES, $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$id = (int)($_GET['id'] ?? 0);
$vehicle = null;
if (fleet_tables_ready($conn)) {
    $stmt = $conn->prepare("
        SELECT v.*, c.name AS company_name FROM fleet_vehicles v
        LEFT JOIN companies c ON c.id = v.company_id WHERE v.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$pageTitle = $vehicle ? $vehicle['plate_no'] : 'Vehicle';
$pageHead = hr_fleet_map_head();
$pageStyles = hr_fleet_map_styles();
require_once __DIR__ . '/includes/hr_layout_header.php';

if (!$vehicle) {
    echo '<div class="alert alert-warning">Vehicle not found. <a href="vehicles">Back to vehicles</a></div>';
    require_once __DIR__ . '/includes/hr_layout_footer.php';
    exit;
}

$types = fleet_vehicle_types();
$from = hr_fleet_date_param('from', date('Y-m-d', strtotime('-30 days')));
$to = hr_fleet_date_param('to', date('Y-m-d'));
$trips = fleet_trip_rows($conn, ['vehicle_id' => $id, 'date_from' => $from, 'date_to' => $to], 300);
$open = fleet_open_trip_for_vehicle($conn, $id);

$stats = $conn->prepare("
    SELECT COUNT(*) AS trips, COALESCE(SUM(distance_m), 0) AS meters, COUNT(DISTINCT driver_user_id) AS drivers
    FROM fleet_trips WHERE vehicle_id = ?
");
$stats->execute([$id]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

$back = 'vehicle_view?' . http_build_query(['id' => $id, 'from' => $from, 'to' => $to]);
$details = implode(' · ', array_filter([
    $vehicle['name'],
    $types[$vehicle['vehicle_type']] ?? null,
    $vehicle['color'],
    $vehicle['company_name'],
]));

echo hr_ui_page_header(
    $vehicle['plate_no'],
    $details,
    [['label' => 'HR', 'href' => $hrBase . '/dashboard'], ['label' => 'Vehicles', 'href' => $hrBase . '/vehicles'], ['label' => $vehicle['plate_no']]],
    '<a class="btn btn-outline-secondary" href="vehicles?edit=' . $id . '">Edit</a>'
);
?>

<?php hr_fleet_flash(); ?>

<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="hr-settings-card h-100"><div class="card-body">
      <div class="small text-muted">Status</div>
      <div class="fs-5 fw-semibold"><?= $vehicle['status'] === 'active' ? 'Active' : 'Inactive' ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="hr-settings-card h-100"><div class="card-body">
      <div class="small text-muted">Trips (all time)</div>
      <div class="fs-5 fw-semibold"><?= (int)$stats['trips'] ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="hr-settings-card h-100"><div class="card-body">
      <div class="small text-muted">Distance (all time)</div>
      <div class="fs-5 fw-semibold"><?= htmlspecialchars(fleet_format_km((int)$stats['meters'])) ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="hr-settings-card h-100"><div class="card-body">
      <div class="small text-muted">Different drivers</div>
      <div class="fs-5 fw-semibold"><?= (int)$stats['drivers'] ?></div>
    </div></div>
  </div>
</div>

<?php if ($open): ?>
  <div class="alert alert-success d-flex flex-wrap align-items-center gap-2">
    <span><strong>On a trip now</strong> with <?= htmlspecialchars($open['driver_name']) ?> since
      <?= date('H:i', strtotime($open['started_at'])) ?> · <?= htmlspecialchars(fleet_format_duration($open['started_at'], null)) ?>
      · <?= htmlspecialchars(fleet_format_km((int)$open['distance_m'])) ?> · last signal <?= htmlspecialchars(fleet_ago($open['last_point_at'])) ?></span>
    <a class="btn btn-sm btn-success ms-auto" href="fleet_live">Live map</a>
  </div>
<?php endif; ?>

<?php if (!empty($vehicle['notes'])): ?>
  <p class="text-muted small"><?= htmlspecialchars($vehicle['notes']) ?></p>
<?php endif; ?>

<div class="hr-settings-card">
  <div class="settings-header">
    <form class="row g-2 align-items-center">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="col-auto fw-semibold me-2">Trip history</div>
      <div class="col-auto"><input type="date" class="form-control form-control-sm" name="from" value="<?= $from ?>"></div>
      <div class="col-auto">to</div>
      <div class="col-auto"><input type="date" class="form-control form-control-sm" name="to" value="<?= $to ?>"></div>
      <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
    </form>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-lg-6 fleet-side"><?php hr_fleet_trip_table($trips, false, $back); ?></div>
      <div class="col-lg-6"><div id="fleet-map" class="fleet-map"></div><?php hr_fleet_player(); ?></div>
    </div>
  </div>
</div>

<?php
$pageScripts = hr_fleet_map_scripts($hrAssetBase) . '<script>
FleetMap.routes({ mapEl: "fleet-map", toggles: ".fleet-trip-toggle", url: "fleet_data?trip=", playerEl: "fleet-player" });
</script>';
require_once __DIR__ . '/includes/hr_layout_footer.php';
