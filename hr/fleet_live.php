<?php
// hr/fleet_live.php — every vehicle on a trip right now, on one map.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_role(HR_FLEET_ROLES, $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$companies = hr_active_companies($conn);
$companyId = hr_selected_company_id($conn, $companies);
$ready = fleet_tables_ready($conn);

$pageTitle = 'Live Map';
$pageHead = hr_fleet_map_head();
$pageStyles = hr_fleet_map_styles();
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Live Map',
    'Vehicles on a trip right now. Refreshes every 30 seconds; grey = no signal for 5 minutes.',
    [['label' => 'HR', 'href' => $hrBase . '/dashboard'], ['label' => 'Live Map']],
    '<a class="btn btn-outline-secondary" href="fleet_history">Trip history</a>'
);
?>

<?php hr_fleet_flash(); ?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">Vehicle tracking is not set up on this server yet. Run <code>migrations/fleet_tracking.sql</code>.</div>
<?php else: ?>
  <div class="row g-3">
    <div class="col-lg-9">
      <div id="fleet-map" class="fleet-map"></div>
    </div>
    <div class="col-lg-3">
      <div class="hr-settings-card">
        <div class="settings-header">
          <div class="d-flex justify-content-between align-items-center">
            <span><span id="fleet-count">0</span> on a trip</span>
            <span class="small text-muted" id="fleet-updated"></span>
          </div>
          <form class="mt-2">
            <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="">All companies</option>
              <?php foreach ($companies as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $companyId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <div class="list-group list-group-flush fleet-side" id="fleet-list"></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php
if ($ready) {
    $pageScripts = hr_fleet_map_scripts($hrAssetBase) . '<script>
FleetMap.live({
  mapEl: "fleet-map",
  listEl: document.getElementById("fleet-list"),
  countEl: document.getElementById("fleet-count"),
  updatedEl: document.getElementById("fleet-updated"),
  url: "fleet_data?live=1&company_id=' . $companyId . '"
});
</script>';
}
require_once __DIR__ . '/includes/hr_layout_footer.php';
