<?php
/**
 * POS sales dashboard — KPIs, top items, links to reports.
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
require_once __DIR__ . '/../../includes/inventory/inv_pos_retail_profiles.php';

require_login();
require_module_access($conn, MODULE_GROCERY);
require_grocery_backoffice_department($conn);
require_permission('grocery_reports.view', MODULE_GROCERY, $conn);

ensure_current_company_supports_module($conn, MODULE_GROCERY);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$appBase = get_application_web_root();
$f = pos_report_parse_filters();
$where = pos_report_sales_where_time_locations($companyId, $f['ts_from'], $f['ts_to'], $f['location_ids']);

$kpis = [
    'transactions' => 0,
    'total_incl' => 0.0,
    'sub_excl' => 0.0,
    'tax' => 0.0,
    'cash' => 0.0,
    'card' => 0.0,
    'avg_basket' => 0.0,
];
$topItems = [];
$locations = [];
$posRetailProfiles = [];

if ($companyId) {
    $st = $conn->prepare("SELECT id, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $st->execute([$companyId]);
    $locations = $st->fetchAll(PDO::FETCH_ASSOC);
    $posRetailProfiles = inv_pos_profiles_list($conn, $companyId);

    $sqlK = "
        SELECT
            COUNT(*) AS cnt,
            COALESCE(SUM(ps.grand_total_incl),0) AS total_incl,
            COALESCE(SUM(ps.subtotal_excl),0) AS sub_excl,
            COALESCE(SUM(ps.tax_total),0) AS tax,
            COALESCE(SUM(CASE WHEN LOWER(IFNULL(ps.payment_method,'')) = 'cash' THEN ps.grand_total_incl ELSE 0 END),0) AS cash,
            COALESCE(SUM(CASE WHEN LOWER(IFNULL(ps.payment_method,'')) = 'card' THEN ps.grand_total_incl ELSE 0 END),0) AS card
        FROM pos_sales ps
        " . $where[0];
    $st = $conn->prepare($sqlK);
    $st->execute($where[1]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $kpis['transactions'] = (int)$row['cnt'];
        $kpis['total_incl'] = (float)$row['total_incl'];
        $kpis['sub_excl'] = (float)$row['sub_excl'];
        $kpis['tax'] = (float)$row['tax'];
        $kpis['cash'] = (float)$row['cash'];
        $kpis['card'] = (float)$row['card'];
        $kpis['avg_basket'] = $kpis['transactions'] > 0 ? $kpis['total_incl'] / $kpis['transactions'] : 0.0;
    }

    $locSql = pos_report_location_clause($f['location_ids']);
    $sqlTop = "
        SELECT i.id, i.item_code, i.name,
               SUM(l.qty) AS qty_sold,
               SUM(l.line_total_incl) AS revenue
        FROM pos_sale_lines l
        JOIN pos_sales ps ON ps.id = l.header_id AND ps.company_id = l.company_id
        JOIN inv_items i ON i.id = l.item_id AND i.company_id = l.company_id
        WHERE ps.company_id = ?
          AND ps.status = 'posted'
          AND COALESCE(ps.posted_at, ps.created_at) >= ?
          AND COALESCE(ps.posted_at, ps.created_at) <= ?
        " . $locSql[0] . "
        GROUP BY i.id, i.item_code, i.name
        ORDER BY revenue DESC
        LIMIT 15
    ";
    $params = array_merge([$companyId, $f['ts_from'], $f['ts_to']], $locSql[1]);
    $st = $conn->prepare($sqlTop);
    $st->execute($params);
    $topItems = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'POS dashboard';
$qsBase = pos_report_query_string([
    'preset' => $f['preset'],
    'date_from' => $f['date_from'],
    'date_to' => $f['date_to'],
    'loc_ids' => $f['location_ids'] ? implode(',', $f['location_ids']) : '',
]);

require_once __DIR__ . '/includes/grocery_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="page-header-label">POS sales dashboard</div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-primary btn-sm" href="<?= h($appBase) ?>/modules/grocery/pos_sales_list.php<?= h($qsBase) ?>">All sales</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h($appBase) ?>/modules/grocery/pos_reports_items.php<?= h($qsBase) ?>">Item sales</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h($appBase) ?>/modules/grocery/pos_reports_cashiers.php<?= h($qsBase) ?>">Cashiers</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h($appBase) ?>/modules/grocery/pos_reports_payments.php<?= h($qsBase) ?>">Payments</a>
  </div>
</div>

<form class="card p-3 mb-3" method="get" id="posFilterForm">
  <div class="row g-2 align-items-end">
    <div class="col-md-2">
      <label class="form-label">Period</label>
      <select class="form-select" name="preset" id="presetSel">
        <option value="today" <?= $f['preset'] === 'today' ? 'selected' : '' ?>>Today</option>
        <option value="yesterday" <?= $f['preset'] === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
        <option value="custom" <?= $f['preset'] === 'custom' ? 'selected' : '' ?>>Custom range</option>
      </select>
    </div>
    <div class="col-md-2 custom-range" style="<?= $f['preset'] === 'custom' ? '' : 'display:none;' ?>">
      <label class="form-label">From</label>
      <input type="date" class="form-control" name="date_from" value="<?= h($f['date_from']) ?>">
    </div>
    <div class="col-md-2 custom-range" style="<?= $f['preset'] === 'custom' ? '' : 'display:none;' ?>">
      <label class="form-label">To</label>
      <input type="date" class="form-control" name="date_to" value="<?= h($f['date_to']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Locations (empty = all)</label>
      <select class="form-select" name="loc_ids[]" multiple size="2" style="min-height:4.5rem">
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

<?php if ($companyId && $posRetailProfiles): ?>
<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Retail POS — open by store</div>
  <p class="small text-muted mb-2">Each link opens the cashier screen with the correct default selling location and its own cart. Configure profiles under <a href="<?= h($appBase) ?>/modules/inventory/locations.php#pos-profiles">Inventory → Locations</a>.</p>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($posRetailProfiles as $pr): ?>
      <a class="btn btn-primary btn-sm" href="<?= h($appBase) ?>/modules/grocery/pos_retail.php?pos=<?= rawurlencode((string)$pr['profile_code']) ?>"><?= h((string)$pr['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$companyId): ?>
  <div class="alert alert-warning">Select a company.</div>
<?php else: ?>

<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="card p-3 h-100">
      <div class="small text-muted">Total sales (incl. VAT)</div>
      <div class="fs-4 fw-bold"><?= number_format($kpis['total_incl'], 2) ?></div>
      <a class="small" href="<?= h($appBase) ?>/modules/grocery/pos_sales_list.php<?= h($qsBase) ?>">View transactions →</a>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card p-3 h-100">
      <div class="small text-muted">Transactions</div>
      <div class="fs-4 fw-bold"><?= (int)$kpis['transactions'] ?></div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card p-3 h-100">
      <div class="small text-muted">Cash</div>
      <div class="fs-5 fw-semibold text-success"><?= number_format($kpis['cash'], 2) ?></div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card p-3 h-100">
      <div class="small text-muted">Card</div>
      <div class="fs-5 fw-semibold text-primary"><?= number_format($kpis['card'], 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card p-3 h-100">
      <div class="small text-muted">Avg. basket (incl.)</div>
      <div class="fs-4 fw-bold"><?= number_format($kpis['avg_basket'], 2) ?></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Subtotal (ex VAT) & VAT</div>
      <div>Ex VAT: <strong><?= number_format($kpis['sub_excl'], 2) ?></strong></div>
      <div>VAT: <strong><?= number_format($kpis['tax'], 2) ?></strong></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Drill-down</div>
      <div class="small text-muted mb-1">Jump to sales for this period & location filter:</div>
      <a class="btn btn-sm btn-outline-primary" href="<?= h($appBase) ?>/modules/grocery/pos_sales_list.php<?= h($qsBase) ?>">Sales list</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($appBase) ?>/modules/grocery/pos_reports_payments.php<?= h($qsBase) ?>">Payment summary</a>
    </div>
  </div>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">Top selling items (by revenue)</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>SKU</th>
          <th>Name</th>
          <th class="text-end">Qty</th>
          <th class="text-end">Revenue (incl.)</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topItems as $ti): ?>
          <tr>
            <td><?= h($ti['item_code']) ?></td>
            <td><?= h($ti['name']) ?></td>
            <td class="text-end"><?= h(rtrim(rtrim(number_format((float)$ti['qty_sold'], 4, '.', ''), '0'), '.')) ?></td>
            <td class="text-end"><?= number_format((float)$ti['revenue'], 2) ?></td>
            <td>
              <a class="btn btn-sm btn-outline-primary" href="<?= h($appBase) ?>/modules/grocery/pos_report_item.php?item_id=<?= (int)$ti['id'] ?><?= h($qsBase) ?>">Details</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$topItems): ?>
          <tr><td colspan="5" class="text-muted">No POS sales in this period.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  var p = document.getElementById('presetSel');
  if (!p) return;
  p.addEventListener('change', function () {
    document.querySelectorAll('.custom-range').forEach(function (el) {
      el.style.display = p.value === 'custom' ? '' : 'none';
    });
  });
})();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/grocery_layout_footer.php'; ?>
