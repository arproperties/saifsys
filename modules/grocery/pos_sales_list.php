<?php
/**
 * POS sales list with filters and drill-down links.
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

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$appBase = get_application_web_root();
$f = pos_report_parse_filters();
$postedBy = (int)($_GET['posted_by'] ?? 0);
$dateLink = $_GET['date'] ?? '';

if ($dateLink !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateLink)) {
    $f['preset'] = 'custom';
    $f['date_from'] = $dateLink;
    $f['date_to'] = $dateLink;
    $f['ts_from'] = $dateLink . ' 00:00:00';
    $f['ts_to'] = $dateLink . ' 23:59:59';
}

$where = pos_report_sales_where_time_locations($companyId, $f['ts_from'], $f['ts_to'], $f['location_ids']);
$extra = '';
$params = $where[1];
if ($postedBy > 0) {
    $extra = ' AND ps.posted_by = ? ';
    $params[] = $postedBy;
}

$sql = "
    SELECT ps.id, ps.sale_no, ps.status, ps.subtotal_excl, ps.tax_total, ps.grand_total_incl,
           ps.payment_method, ps.inv_doc_id, ps.posted_by, ps.posted_at, ps.created_at, ps.source_module,
           COALESCE(u.fullname, u.username) AS cashier_name,
           loc.name AS location_name
    FROM pos_sales ps
    LEFT JOIN user u ON u.id = ps.posted_by
    LEFT JOIN inv_locations loc ON loc.id = ps.location_id AND loc.company_id = ps.company_id
    " . $where[0] . $extra . "
    ORDER BY COALESCE(ps.posted_at, ps.created_at) DESC
    LIMIT 500
";

$rows = [];
if ($companyId) {
    if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
        require_permission('grocery_reports.export', MODULE_GROCERY, $conn);
        $st = $conn->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                $r['sale_no'],
                $r['posted_at'] ?? $r['created_at'],
                $r['cashier_name'] ?? '',
                $r['location_name'] ?? '',
                $r['payment_method'] ?? '',
                $r['subtotal_excl'],
                $r['tax_total'],
                $r['grand_total_incl'],
                $r['status'],
                $r['inv_doc_id'] ?? '',
            ];
        }
        pos_report_send_csv('pos_sales.csv', [
            'sale_no', 'date_time', 'cashier', 'location', 'payment', 'sub_excl', 'vat', 'total_incl', 'status', 'inv_doc_id',
        ], $csvRows);
    }
    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

$qsBase = pos_report_query_string([
    'preset' => $f['preset'],
    'date_from' => $f['date_from'],
    'date_to' => $f['date_to'],
    'loc_ids' => $f['location_ids'] ? implode(',', $f['location_ids']) : '',
    'posted_by' => $postedBy > 0 ? (string)$postedBy : '',
]);

$locations = [];
if ($companyId) {
    $st = $conn->prepare("SELECT id, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $st->execute([$companyId]);
    $locations = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'POS sales';
require_once __DIR__ . '/includes/grocery_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="page-header-label">POS sales</div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-primary btn-sm" href="<?= h($appBase) ?>/modules/grocery/pos_dashboard.php<?= h($qsBase) ?>">Dashboard</a>
    <?php if ($companyId && $rows): ?>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h($appBase) ?>/modules/grocery/pos_sales_list.php<?= h($qsBase) ?><?= strpos($qsBase, '?') !== false ? '&' : '?' ?>export=csv">Export CSV</a>
    <?php endif; ?>
  </div>
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
    <div class="col-md-3">
      <label class="form-label">Locations</label>
      <select class="form-select" name="loc_ids[]" multiple size="2">
        <?php foreach ($locations as $loc): ?>
          <option value="<?= (int)$loc['id'] ?>" <?= in_array((int)$loc['id'], $f['location_ids'], true) ? 'selected' : '' ?>><?= h($loc['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Cashier</label>
      <input type="number" class="form-control" name="posted_by" placeholder="User ID" value="<?= $postedBy > 0 ? (int)$postedBy : '' ?>" min="0">
    </div>
    <div class="col-md-1">
      <button type="submit" class="btn btn-primary">Apply</button>
    </div>
  </div>
</form>

<?php if (!$companyId): ?>
  <div class="alert alert-warning">Select a company.</div>
<?php else: ?>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Sale no.</th>
          <th>Date / time</th>
          <th>Cashier</th>
          <th>Location</th>
          <th>Pay</th>
          <th class="text-end">Sub ex VAT</th>
          <th class="text-end">VAT</th>
          <th class="text-end">Total</th>
          <th>Doc</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $dt = $r['posted_at'] ?? $r['created_at'];
            $day = $dt ? substr($dt, 0, 10) : '';
          ?>
          <tr>
            <td class="fw-semibold"><?= h($r['sale_no']) ?></td>
            <td>
              <?= h($dt) ?>
              <?php if ($day): ?>
                <br><a class="small" href="<?= h($appBase) ?>/modules/grocery/pos_sales_list.php?preset=custom&amp;date_from=<?= h($day) ?>&amp;date_to=<?= h($day) ?><?= $f['location_ids'] ? '&amp;loc_ids=' . h(implode(',', $f['location_ids'])) : '' ?>">Same day</a>
              <?php endif; ?>
            </td>
            <td>
              <?= h($r['cashier_name'] ?: '—') ?>
              <?php if (!empty($r['posted_by'])): ?>
                <br><a class="small" href="<?= h($appBase) ?>/modules/grocery/pos_reports_cashiers.php<?= h($qsBase) ?>&amp;highlight=<?= (int)$r['posted_by'] ?>">Cashier report</a>
              <?php endif; ?>
            </td>
            <td><?= h($r['location_name'] ?: '—') ?></td>
            <td><?= h($r['payment_method'] ?: '—') ?></td>
            <td class="text-end"><?= number_format((float)$r['subtotal_excl'], 2) ?></td>
            <td class="text-end"><?= number_format((float)$r['tax_total'], 2) ?></td>
            <td class="text-end"><?= number_format((float)$r['grand_total_incl'], 2) ?></td>
            <td><?= !empty($r['inv_doc_id']) ? '✓' : '—' ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="pos_sale_view.php?id=<?= (int)$r['id'] ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="text-muted">No sales in range.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
(function(){var p=document.getElementById('presetSel');if(!p)return;p.addEventListener('change',function(){document.querySelectorAll('.crg').forEach(function(el){el.style.display=p.value==='custom'?'':'none';});});})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/grocery_layout_footer.php'; ?>
