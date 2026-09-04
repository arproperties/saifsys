<?php
/**
 * Item sales: top sellers & slow movers by period/location.
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
$locSql = pos_report_location_clause($f['location_ids']);

$top = [];
$slow = [];

if ($companyId) {
    $base = "
        FROM pos_sale_lines l
        JOIN pos_sales ps ON ps.id = l.header_id AND ps.company_id = l.company_id
        JOIN inv_items i ON i.id = l.item_id AND i.company_id = l.company_id
        WHERE ps.company_id = ?
          AND ps.status = 'posted'
          AND COALESCE(ps.posted_at, ps.created_at) >= ?
          AND COALESCE(ps.posted_at, ps.created_at) <= ?
        " . $locSql[0] . "
        GROUP BY i.id, i.item_code, i.name
    ";
    $params = array_merge([$companyId, $f['ts_from'], $f['ts_to']], $locSql[1]);

    $sqlTop = "SELECT i.id, i.item_code, i.name, SUM(l.qty) AS qty_sold, SUM(l.line_total_incl) AS revenue " . $base . " ORDER BY revenue DESC LIMIT 30";
    $st = $conn->prepare($sqlTop);
    $st->execute($params);
    $top = $st->fetchAll(PDO::FETCH_ASSOC);

    $sqlSlow = "SELECT i.id, i.item_code, i.name, SUM(l.qty) AS qty_sold, SUM(l.line_total_incl) AS revenue " . $base . " ORDER BY qty_sold ASC, revenue ASC LIMIT 30";
    $st = $conn->prepare($sqlSlow);
    $st->execute($params);
    $slow = $st->fetchAll(PDO::FETCH_ASSOC);
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

$pageTitle = 'POS item sales';
require_once __DIR__ . '/includes/grocery_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="page-header-label">POS item sales</div>
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

<?php if ($companyId): ?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="fw-semibold mb-2">Top sellers (by revenue)</div>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>SKU</th><th>Name</th><th class="text-end">Qty</th><th class="text-end">Revenue</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($top as $r): ?>
              <tr>
                <td><?= h($r['item_code']) ?></td>
                <td><?= h($r['name']) ?></td>
                <td class="text-end"><?= h(number_format((float)$r['qty_sold'], 2)) ?></td>
                <td class="text-end"><?= number_format((float)$r['revenue'], 2) ?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="<?= h($appBase) ?>/modules/grocery/pos_report_item.php?item_id=<?= (int)$r['id'] ?><?= h($qsBase) ?>">Details</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$top): ?>
              <tr><td colspan="5" class="text-muted">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="fw-semibold mb-2">Slow movers (lowest qty sold in period)</div>
      <p class="small text-muted">Among items that had at least one POS sale in this range.</p>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>SKU</th><th>Name</th><th class="text-end">Qty</th><th class="text-end">Revenue</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($slow as $r): ?>
              <tr>
                <td><?= h($r['item_code']) ?></td>
                <td><?= h($r['name']) ?></td>
                <td class="text-end"><?= h(number_format((float)$r['qty_sold'], 2)) ?></td>
                <td class="text-end"><?= number_format((float)$r['revenue'], 2) ?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="<?= h($appBase) ?>/modules/grocery/pos_report_item.php?item_id=<?= (int)$r['id'] ?><?= h($qsBase) ?>">Details</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$slow): ?>
              <tr><td colspan="5" class="text-muted">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>(function(){var p=document.getElementById('presetSel');if(!p)return;p.addEventListener('change',function(){document.querySelectorAll('.crg').forEach(function(el){el.style.display=p.value==='custom'?'':'none';});});})();</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/grocery_layout_footer.php'; ?>
