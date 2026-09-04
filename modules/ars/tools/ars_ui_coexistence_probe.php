<?php
/**
 * Bootstrap coexistence probe — Wave 0 only.
 * Loads existing ARS Bootstrap chrome + a small #ars-app island.
 * NOT linked from navigation. Does not redesign production pages.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../includes/ars_helpers.php';
require_once __DIR__ . '/../includes/ars_permissions.php';
require_once __DIR__ . '/../includes/ars_ui.php';

arsPageAuth($conn);
$brand = getBrandSettings($conn);
$pageTitle = 'UI Coexistence Probe (Wave 0)';
ob_start();
ars_ui_assets();
$pageHead = ob_get_clean();

require_once __DIR__ . '/../includes/ars_layout_header.php';
?>

<div class="alert alert-info mb-3">
  Coexistence probe: Bootstrap page chrome + isolated <code>#ars-app</code> island. Verify Bootstrap buttons/tables/modals still look correct.
</div>

<div class="card mb-3">
  <div class="card-body">
    <h2 class="h5">Bootstrap island (control)</h2>
    <button type="button" class="btn btn-primary btn-sm me-2" data-bs-toggle="modal" data-bs-target="#probeModal">Bootstrap modal</button>
    <table class="table table-sm table-bordered w-auto mb-0">
      <thead><tr><th>Col</th><th>Value</th></tr></thead>
      <tbody><tr><td>A</td><td>Bootstrap table</td></tr></tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="probeModal" tabindex="-1" aria-labelledby="probeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="probeModalLabel">Bootstrap modal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">If this modal looks normal, Tailwind preflight did not break Bootstrap.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?= ars_ui_app_open(['class' => 'p-4 rounded border mb-4']) ?>
<p class="text-ars-sm text-ars-muted mb-2">ARS island inside Bootstrap page</p>
<?= ars_ui_button('ARS primary', ['icon' => 'check']) ?>
<?= ars_ui_status_badge('booking', 'confirmed') ?>
<?= ars_ui_app_close() ?>

<?php require_once __DIR__ . '/../includes/ars_layout_footer.php'; ?>
