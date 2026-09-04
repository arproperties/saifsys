<?php
/**
 * Sales by cashier; drill to sales list filtered by cashier.
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
$highlight = (int)($_GET['highlight'] ?? 0);

$rows = [];
if ($companyId) {
    $sql = "
        SELECT ps.posted_by,
               MAX(COALESCE(u.fullname, u.username)) AS cashier_name,
               COUNT(*) AS cnt,
               SUM(ps.grand_total_incl) AS total_incl,
               SUM(CASE WHEN LOWER(IFNULL(ps.payment_method,'')) = 'cash' THEN ps.grand_total_incl ELSE 0 END) AS cash,
               SUM(CASE WHEN LOWER(IFNULL(ps.payment_method,'')) = 'card' THEN ps.grand_total_incl ELSE 0 END) AS card
        FROM pos_sales ps
        LEFT JOIN user u ON u.id = ps.posted_by
        " . $where[0] . "
        GROUP BY ps.posted_by
        ORDER BY total_incl DESC
    ";
    $st = $conn->prepare($sql);
    $st->execute($where[1]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
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

$pageTitle = 'POS cashiers';
require_once __DIR__ . '/includes/grocery_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">POS sales by cashier</div>
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

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-sm">
      <thead>
        <tr>
          <th>Cashier</th>
          <th class="text-end">Transactions</th>
          <th class="text-end">Total (incl.)</th>
          <th class="text-end">Cash</th>
          <th class="text-end">Card</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $uid = (int)($r['posted_by'] ?? 0); ?>
          <tr class="<?= $highlight && $highlight === $uid ? 'table-warning' : '' ?>">
            <td><?= h($r['cashier_name'] ?: ('User #' . $uid)) ?></td>
            <td class="text-end"><?= (int)$r['cnt'] ?></td>
            <td class="text-end"><?= number_format((float)$r['total_incl'], 2) ?></td>
            <td class="text-end"><?= number_format((float)$r['cash'], 2) ?></td>
            <td class="text-end"><?= number_format((float)$r['card'], 2) ?></td>
            <td>
              <?php if ($uid): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= h($appBase) ?>/modules/grocery/pos_sales_list.php<?= h($qsBase) ?><?= strpos($qsBase, '?') !== false ? '&' : '?' ?>posted_by=<?= $uid ?>">Sales</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-muted">No data.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>(function(){var p=document.getElementById('presetSel');if(!p)return;p.addEventListener('change',function(){document.querySelectorAll('.crg').forEach(function(el){el.style.display=p.value==='custom'?'':'none';});});})();</script>

<?php require_once __DIR__ . '/includes/grocery_layout_footer.php'; ?>
