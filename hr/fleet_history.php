<?php
// hr/fleet_history.php — past trips across all vehicles, with routes on a map.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_role(HR_FLEET_ROLES, $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$companies = hr_active_companies($conn);
$ready = fleet_tables_ready($conn);

$filters = [
    'vehicle_id' => (int)($_GET['vehicle_id'] ?? 0),
    'driver_user_id' => (int)($_GET['driver_user_id'] ?? 0),
    'company_id' => hr_selected_company_id($conn, $companies),
    'date_from' => hr_fleet_date_param('from', date('Y-m-d', strtotime('-7 days'))),
    'date_to' => hr_fleet_date_param('to', date('Y-m-d')),
];
$trips = $ready ? fleet_trip_rows($conn, $filters, 300) : [];
$vehicles = $ready ? fleet_vehicle_options($conn) : [];
$drivers = $ready ? fleet_driver_options($conn) : [];

$back = 'fleet_history?' . http_build_query([
    'vehicle_id' => $filters['vehicle_id'] ?: null,
    'driver_user_id' => $filters['driver_user_id'] ?: null,
    'company_id' => $filters['company_id'] ?: null,
    'from' => $filters['date_from'],
    'to' => $filters['date_to'],
]);

$pageTitle = 'Trip History';
$pageHead = hr_fleet_map_head();
$pageStyles = hr_fleet_map_styles();
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Trip History',
    'Tick trips to draw their routes, or press ▶ to replay one. Green dot = start, red = end.',
    [['label' => 'HR', 'href' => $hrBase . '/dashboard'], ['label' => 'Trip History']],
    '<a class="btn btn-outline-primary" href="fleet_live"><i class="bi bi-geo-alt me-1"></i>Live map</a>'
);
?>

<?php hr_fleet_flash(); ?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">Vehicle tracking is not set up on this server yet. Run <code>migrations/fleet_tracking.sql</code>.</div>
<?php else: ?>
  <div class="hr-settings-card">
    <div class="settings-header">
      <form class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label small mb-1">Vehicle</label>
          <select name="vehicle_id" class="form-select form-select-sm">
            <option value="">All vehicles</option>
            <?php foreach ($vehicles as $v): ?>
              <option value="<?= (int)$v['id'] ?>" <?= $filters['vehicle_id'] === (int)$v['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($v['plate_no'] . ($v['status'] === 'inactive' ? ' (inactive)' : '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Driver</label>
          <select name="driver_user_id" class="form-select form-select-sm">
            <option value="">All drivers</option>
            <?php foreach ($drivers as $d): ?>
              <option value="<?= (int)$d['driver_user_id'] ?>" <?= $filters['driver_user_id'] === (int)$d['driver_user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['driver_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Company</label>
          <select name="company_id" class="form-select form-select-sm">
            <option value="">All companies</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $filters['company_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">From</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= $filters['date_from'] ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">To</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= $filters['date_to'] ?>">
        </div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Show trips</button></div>
      </form>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-lg-6 fleet-side"><?php hr_fleet_trip_table($trips, true, $back); ?></div>
        <div class="col-lg-6"><div id="fleet-map" class="fleet-map"></div><?php hr_fleet_player(); ?></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php
if ($ready) {
    $pageScripts = hr_fleet_map_scripts($hrAssetBase) . '<script>
FleetMap.routes({ mapEl: "fleet-map", toggles: ".fleet-trip-toggle", url: "fleet_data?trip=", playerEl: "fleet-player" });
</script>';
}
require_once __DIR__ . '/includes/hr_layout_footer.php';
