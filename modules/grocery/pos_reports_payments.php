<?php
/**
 * Payment summary: cash vs card (and other) for the period/location filter.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/../../includes/grocery/pos_report_helpers.php';

require_login();
require_module_access($conn, MODULE_GROCERY);
require_grocery_backoffice_department($conn);
require_permission('grocery_reports.view', MODULE_GROCERY, $conn);

ensure_current_company_supports_module($conn, MODULE_GROCERY);

$companyId = current_company_id($conn) ?: 0;
$appBase = get_application_web_root();
$f = pos_report_parse_filters();
$where = pos_report_sales_where_time_locations($companyId, $f['ts_from'], $f['ts_to'], $f['location_ids']);

$tot = ['transactions' => 0, 'grand' => 0.0, 'cash' => 0.0, 'card' => 0.0, 'other' => 0.0];

if ($companyId) {
    $sql = "
        SELECT
            COUNT(*) AS cnt,
            COALESCE(SUM(ps.grand_total_incl),0) AS grand,
            COALESCE(SUM(CASE WHEN LOWER(IFNULL(ps.payment_method,'')) = 'cash' THEN ps.grand_total_incl ELSE 0 END),0) AS cash,
            COALESCE(SUM(CASE WHEN LOWER(IFNULL(ps.payment_method,'')) = 'card' THEN ps.grand_total_incl ELSE 0 END),0) AS card,
            COALESCE(SUM(CASE WHEN ps.payment_method IS NULL OR LOWER(ps.payment_method) NOT IN ('cash','card') THEN ps.grand_total_incl ELSE 0 END),0) AS other
        FROM pos_sales ps
        " . $where[0];
    $st = $conn->prepare($sql);
    $st->execute($where[1]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $tot['transactions'] = (int)$r['cnt'];
        $tot['grand'] = (float)$r['grand'];
        $tot['cash'] = (float)$r['cash'];
        $tot['card'] = (float)$r['card'];
        $tot['other'] = (float)$r['other'];
    }
}

$qsBase = pos_report_query_string([
    'preset' => $f['preset'],
    'date_from' => $f['date_from'],
    'date_to' => $f['date_to'],
    'loc_ids' => $f['location_ids'] ? implode(',', $f['location_ids']) : '',
]);

$locations = [];
if ($companyId) {
    $st = $conn->prepare("SELECT id, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $st->execute([$companyId]);
    $locations = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'POS payments';
require_once __DIR__ . '/includes/grocery_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">POS payment summary</div>
  <a class="btn btn-outline-primary btn-sm" href="<?= h($appBase) ?>/modules/grocery/pos_dashboard.php<?= h($qsBase) ?>">Dashboard</a>
</div>

<form class="card p-3 mb-3" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-md-2">
      <label class="form-label">Period</label>
      <select class="form-select" name="preset" id="presetSel">
        <option value="today" <?= $f['preset'] === 'today' ? 'selected' : '' ?>>Today</option>
        <option value="yesterday" <?= $f['preset'] === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
        <option value="custom" <?= $f['preset'] === 'custom' ? 'selected' : '' ?>>Custom</option>
      </select>
    </div>
    <div class="col-md-2 crg" style="<?= $f['preset'] === 'custom' ? '' : 'display:none;' ?>">
      <label class="form-label">From</label>
      <input type="date" class="form-control" name="date_from" value="<?= h($f['date_from']) ?>">
    </div>
    <div class="col-md-2 crg" style="<?= $f['preset'] === 'custom' ? '' : 'display:none;' ?>">
      <label class="form-label">To</label>
      <input type="date" class="form-control" name="date_to" value="<?= h($f['date_to']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Locations</label>
      <select class="form-select" name="loc_ids[]" multiple size="2">
        <?php foreach ($locations as $loc): ?>
          <option value="<?= (int)$loc['id'] ?>" <?= in_array((int)$loc['id'], $f['location_ids'], true) ? 'selected' : '' ?>><?= h($loc['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary">Apply</button>
    </div>
  </div>
</form>

<div class="row g-3">
  <div class="col-md-4">
    <div class="card p-4">
      <div class="small text-muted">Total (incl. VAT)</div>
      <div class="fs-3 fw-bold"><?= number_format($tot['grand'], 2) ?></div>
      <div class="small"><?= (int)$tot['transactions'] ?> transactions</div>
      <a class="small" href="<?= h($appBase) ?>/modules/grocery/pos_sales_list.php<?= h($qsBase) ?>">View sales →</a>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card p-4 border-success">
      <div class="small text-muted">Cash</div>
      <div class="fs-3 fw-bold text-success"><?= number_format($tot['cash'], 2) ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card p-4 border-primary">
      <div class="small text-muted">Card</div>
      <div class="fs-3 fw-bold text-primary"><?= number_format($tot['card'], 2) ?></div>
    </div>
  </div>
</div>
<?php if ($tot['other'] > 0.0001): ?>
  <div class="alert alert-secondary mt-3">Other / unspecified payment: <strong><?= number_format($tot['other'], 2) ?></strong> (legacy or manual entries)</div>
<?php endif; ?>

<script>(function(){var p=document.getElementById('presetSel');if(!p)return;p.addEventListener('change',function(){document.querySelectorAll('.crg').forEach(function(el){el.style.display=p.value==='custom'?'':'none';});});})();</script>

<?php require_once __DIR__ . '/includes/grocery_layout_footer.php'; ?>
