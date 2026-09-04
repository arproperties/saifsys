<?php
/**
 * Drill-down: POS performance for a single item in the selected period.
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
$itemId = (int)($_GET['item_id'] ?? 0);
$f = pos_report_parse_filters();
$locSql = pos_report_location_clause($f['location_ids']);

$item = null;
$agg = ['qty' => 0.0, 'revenue' => 0.0];
$lines = [];

if ($companyId && $itemId) {
    $st = $conn->prepare("SELECT * FROM inv_items WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$itemId, $companyId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if ($item) {
        $sql = "
            SELECT
                SUM(l.qty) AS qty,
                SUM(l.line_total_incl) AS revenue
            FROM pos_sale_lines l
            JOIN pos_sales ps ON ps.id = l.header_id AND ps.company_id = l.company_id
            WHERE l.company_id = ? AND l.item_id = ?
              AND ps.status = 'posted'
              AND COALESCE(ps.posted_at, ps.created_at) >= ?
              AND COALESCE(ps.posted_at, ps.created_at) <= ?
            " . $locSql[0];
        $params = array_merge([$companyId, $itemId, $f['ts_from'], $f['ts_to']], $locSql[1]);
        $st = $conn->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $agg['qty'] = (float)$row['qty'];
            $agg['revenue'] = (float)$row['revenue'];
        }

        $sql2 = "
            SELECT ps.sale_no, ps.id AS sale_id,
                   COALESCE(ps.posted_at, ps.created_at) AS sale_time,
                   l.qty, l.line_total_incl, l.unit_price_excl, l.vat_rate
            FROM pos_sale_lines l
            JOIN pos_sales ps ON ps.id = l.header_id AND ps.company_id = l.company_id
            WHERE l.company_id = ? AND l.item_id = ?
              AND ps.status = 'posted'
              AND COALESCE(ps.posted_at, ps.created_at) >= ?
              AND COALESCE(ps.posted_at, ps.created_at) <= ?
            " . $locSql[0] . "
            ORDER BY sale_time DESC
            LIMIT 200
        ";
        $params2 = array_merge([$companyId, $itemId, $f['ts_from'], $f['ts_to']], $locSql[1]);
        $st = $conn->prepare($sql2);
        $st->execute($params2);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
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

$pageTitle = 'POS item detail';
require_once __DIR__ . '/includes/grocery_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="page-header-label">POS item: <?= $item ? h($item['name']) : '—' ?></div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= h($appBase) ?>/modules/grocery/pos_reports_items.php<?= h($qsBase) ?>">← Item sales</a>
    <a class="btn btn-outline-primary btn-sm" href="<?= h($appBase) ?>/modules/inventory/item_edit.php?id=<?= (int)$itemId ?>">Edit item</a>
  </div>
</div>

<form class="card p-3 mb-3" method="get">
  <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
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

<?php if (!$item): ?>
  <div class="alert alert-warning">Item not found.</div>
<?php else: ?>
<div class="card p-3 mb-3">
  <div class="row">
    <div class="col-md-6">
      <div><strong>SKU</strong> <?= h($item['item_code']) ?></div>
      <div><strong>Qty sold (period)</strong> <?= number_format($agg['qty'], 4) ?></div>
      <div><strong>Revenue incl. VAT</strong> <?= number_format($agg['revenue'], 2) ?></div>
    </div>
  </div>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">Lines (recent)</div>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead>
        <tr>
          <th>Sale</th>
          <th>Time</th>
          <th class="text-end">Qty</th>
          <th class="text-end">Line total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $ln): ?>
          <tr>
            <td><?= h($ln['sale_no']) ?></td>
            <td>
              <?= h($ln['sale_time']) ?>
              <?php $d = $ln['sale_time'] ? substr($ln['sale_time'], 0, 10) : ''; ?>
              <?php if ($d): ?>
                <br><a class="small" href="<?= h($appBase) ?>/modules/grocery/pos_sales_list.php?preset=custom&amp;date_from=<?= h($d) ?>&amp;date_to=<?= h($d) ?>">Sales this day</a>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= number_format((float)$ln['qty'], 4) ?></td>
            <td class="text-end"><?= number_format((float)$ln['line_total_incl'], 2) ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="pos_sale_view.php?id=<?= (int)$ln['sale_id'] ?>">Sale</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lines): ?>
          <tr><td colspan="5" class="text-muted">No POS lines in range.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>(function(){var p=document.getElementById('presetSel');if(!p)return;p.addEventListener('change',function(){document.querySelectorAll('.crg').forEach(function(el){el.style.display=p.value==='custom'?'':'none';});});})();</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/grocery_layout_footer.php'; ?>
