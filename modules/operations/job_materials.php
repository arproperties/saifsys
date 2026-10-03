<?php
/**
 * Operations — the Material tab of one job, as a piece of page.
 *
 * Materials come from Building Inventory, which is kept in the Reem app: this asks
 * Reem what has been given to the job and which buildings the person at the screen
 * keeps, and draws the list and the form. job_view.php fetches it when the tab is
 * opened, so a job page never waits on Reem unless someone looks at materials.
 *
 *   job_materials.php?job_id=12              the tab's HTML
 *   job_materials.php?job_id=12&building=3   JSON: the items of that building, for the picker
 *
 * Nothing is stored in saifsys. The taking itself is job_action.php (take_inventory).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/../building_inventory/includes/binv_helper.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$companyId = ops_company_id($conn);
$userId = (int)current_user_id();

$jobId = (int)($_GET['job_id'] ?? 0);
$job = $jobId > 0 ? ops_load_job($conn, $jobId, $companyId) : null;
if (!$job) {
    http_response_code(404);
    exit('Not found');
}
$code = binv_employee_code($conn, $userId);

// ---- the items of one building, for the picker ----
if (isset($_GET['building'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $inv = binv_reem($code, 'GET', '/' . (int)$_GET['building']);
    } catch (BinvError $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
    $items = [];
    foreach ($inv['items'] ?? [] as $it) {
        $left = (float)($it['quantity'] ?? 0);
        $items[] = [
            'id' => (int)$it['id'],
            'left' => $left,
            'label' => $it['name'] . ' — ' . ($it['place']['label'] ?? '') . ' — '
                . ($left > 0 ? binv_qty_label($left, $it['counted_in'] ?? null) . ' left' : 'none left'),
        ];
    }
    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- the tab ----
$error = null;
$buildings = [];
$taken = [];
try {
    $buildings = binv_reem($code, 'GET');
    $taken = binv_reem($code, 'GET', '/jobs/' . $jobId);
} catch (BinvError $e) {
    $error = $e->getMessage();
}
$returnTo = $opsBase . '/job_view.php?id=' . $jobId . '&tab=material';

// Reem lists each real building by its saifsys id, so the job's own building can be
// picked for the person: the first of the job's places that they keep.
$jobBuildingIds = [];
foreach (ops_job_places($conn, [$jobId])[$jobId] ?? [] as $place) {
    if ($place['building_id'] !== null) {
        $jobBuildingIds[] = (int)$place['building_id'];
    }
}
$preselect = 0;
foreach ($buildings as $b) {
    if (in_array((int)$b['id'], $jobBuildingIds, true)) {
        $preselect = (int)$b['id'];
        break;
    }
}
if ($preselect === 0 && count($buildings) === 1) {
    $preselect = (int)$buildings[0]['id'];
}
?>
<?php if ($error !== null): ?>
  <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle"></i> <?= h($error) ?></div>
<?php else: ?>
<div class="row g-3">

  <!-- What has already gone out -->
  <div class="col-12 ops-col-65">
    <div class="card card-round h-100">
      <div class="card-body pb-2 flex-grow-0">
        <h6 class="fw-bold mb-0"><i class="bi bi-box-seam"></i> Materials used</h6>
      </div>

      <?php if (!$taken): ?>
        <div class="card-body pt-0 flex-grow-0">
          <p class="text-muted small mb-0">
            Nothing taken from Building Inventory for this job yet — hand something over
            on the right and it is recorded here.
          </p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table ops-table align-middle">
            <thead>
              <tr><th>Item</th><th>Taken</th><th>From</th><th>Note</th><th>By</th><th>When</th></tr>
            </thead>
            <tbody>
              <?php foreach ($taken as $t): ?>
                <tr>
                  <td class="fw-semibold"><?= h($t['item'] ?? '') ?></td>
                  <td class="text-nowrap">
                    <span class="ops-pill ops-pill-out">−<?= h(binv_qty_label($t['quantity'] ?? 0, $t['counted_in'] ?? null)) ?></span>
                  </td>
                  <td class="small text-muted"><?= h(($t['building']['name'] ?? '') . ' · ' . ($t['place'] ?? '')) ?></td>
                  <td class="small text-muted"><?= h($t['note'] ?? '') ?></td>
                  <td class="small text-muted"><?= h($t['by'] ?? '—') ?></td>
                  <td class="small text-muted text-nowrap num"><?= h(binv_when($t['at'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Hand something over -->
  <div class="col-12 ops-col-35">
    <div class="card card-round h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-box-arrow-right"></i> Take from Building Inventory</h6>

        <?php if (!$buildings): ?>
          <p class="text-muted small mb-0">
            You do not keep any building's inventory, so there is nothing to take from.
            In Reem, the master names the administrator of each building.
          </p>
        <?php else: ?>
          <form method="post" action="<?= h($opsBase) ?>/job_action.php" class="row g-2 align-items-end" id="opsMatForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="take_inventory">
            <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
            <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">

            <div class="col-12">
              <label class="form-label small fw-semibold mb-1">Building</label>
              <select name="building_id" id="opsMatBuilding" class="form-select" required>
                <?php if ($preselect === 0): ?><option value="">Choose a building…</option><?php endif; ?>
                <?php foreach ($buildings as $b): ?>
                  <option value="<?= (int)$b['id'] ?>"<?= (int)$b['id'] === $preselect ? ' selected' : '' ?>><?= h($b['name'] ?? '') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold mb-1">Item</label>
              <select name="item_id" id="opsMatItem" class="form-select" required>
                <option value="">Choose a building first</option>
              </select>
            </div>
            <div class="col-5">
              <label class="form-label small fw-semibold mb-1">How much</label>
              <input type="number" step="0.01" min="0.01" name="qty" class="form-control" placeholder="1" required>
            </div>
            <div class="col-7">
              <label class="form-label small fw-semibold mb-1">Note <span class="text-muted fw-normal">(optional)</span></label>
              <input type="text" name="note" class="form-control" maxlength="200" placeholder="Given to Francis">
            </div>
            <div class="col-12 d-grid">
              <button class="btn text-white" id="opsMatSend" style="background:var(--primary)">
                <i class="bi bi-check-lg"></i> Record
              </button>
            </div>
          </form>
          <div class="form-text mt-2">Comes off that item's count in Building Inventory straight away, and shows in its changes with this job's number.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>
<?php endif; ?>
