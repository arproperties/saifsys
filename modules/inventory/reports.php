<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_reports.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;

$locId = !empty($_GET['location_id']) ? (int)$_GET['location_id'] : 0;

$locations = [];
if ($companyId) {
    $stmt = $conn->prepare("SELECT id, code, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$companyId]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$rows = [];
$moveRows = [];
if ($companyId) {
    $sql = "
        SELECT oh.location_id, l.code, l.name AS location_name,
               oh.item_id, i.item_code, i.name AS item_name,
               oh.lot_id, lo.lot_number, lo.expiry_date,
               oh.qty_on_hand,
               cs.avg_cost,
               (oh.qty_on_hand * COALESCE(cs.avg_cost,0)) AS value_est
        FROM inv_onhand oh
        JOIN inv_items i ON i.id = oh.item_id
        JOIN inv_locations l ON l.id = oh.location_id
        LEFT JOIN inv_lots lo ON lo.id = oh.lot_id
        LEFT JOIN inv_item_cost_state cs ON cs.company_id = oh.company_id AND cs.item_id = oh.item_id
        WHERE oh.company_id = ?
    ";
    $params = [$companyId];
    if ($locId) {
        $sql .= " AND oh.location_id = ? ";
        $params[] = $locId;
    }
    $sql .= " ORDER BY l.name, i.name, lo.lot_number";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mStmt = $conn->prepare("
        SELECT sm.move_date, sm.doc_type, sm.doc_id, sm.doc_line_id,
               dh.doc_no, i.item_code, i.name AS item_name, l.code AS loc_code, l.name AS loc_name,
               sm.qty_in, sm.qty_out, sm.value_in, sm.value_out, sm.unit_cost_base
        FROM inv_stock_moves sm
        JOIN inv_items i ON i.id = sm.item_id AND i.company_id = sm.company_id
        JOIN inv_locations l ON l.id = sm.location_id AND l.company_id = sm.company_id
        LEFT JOIN inv_doc_headers dh ON dh.id = sm.doc_id AND dh.company_id = sm.company_id
        WHERE sm.company_id = ?
        ORDER BY sm.id DESC
        LIMIT 300
    ");
    $mStmt->execute([$companyId]);
    $moveRows = $mStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Reports';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Reports</div>
</div>

<div class="card p-3 mb-3">
  <form class="row g-2 align-items-end" method="GET">
    <div class="col-md-4">
      <label class="form-label">Location</label>
      <select class="form-select" name="location_id">
        <option value="">All</option>
        <?php foreach ($locations as $l): ?>
          <option value="<?= (int)$l['id'] ?>" <?= $locId == (int)$l['id'] ? 'selected' : '' ?>><?= h($l['code'] . ' — ' . $l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <button class="btn btn-primary">Apply</button>
      <a class="btn btn-outline-secondary ms-2" href="reports.php">Reset</a>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">Stock on hand (WAC valuation)</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Location</th>
          <th>Item</th>
          <th>Lot</th>
          <th>Expiry</th>
          <th class="text-end">On hand</th>
          <th class="text-end">Avg cost</th>
          <th class="text-end">Value (est)</th>
        </tr>
      </thead>
      <tbody>
        <?php $total = 0.0; ?>
        <?php foreach ($rows as $r): ?>
          <?php $total += (float)$r['value_est']; ?>
          <tr>
            <td><?= h($r['code'] . ' — ' . $r['location_name']) ?></td>
            <td class="fw-semibold"><?= h($r['item_code']) ?> <span class="text-muted">— <?= h($r['item_name']) ?></span></td>
            <td><?= h($r['lot_number'] ?: '—') ?></td>
            <td><?= h($r['expiry_date'] ?: '—') ?></td>
            <td class="text-end"><?= number_format((float)$r['qty_on_hand'], 4) ?></td>
            <td class="text-end"><?= number_format((float)($r['avg_cost'] ?? 0), 4) ?></td>
            <td class="text-end"><?= number_format((float)$r['value_est'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-muted">No stock yet.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot>
        <tr>
          <td colspan="6" class="text-end fw-semibold">Total</td>
          <td class="text-end fw-bold"><?= number_format($total, 2) ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="card p-3 mt-3">
  <div class="fw-semibold mb-2">Movement history (latest 300)</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>When</th>
          <th>Doc</th>
          <th>Type</th>
          <th>Item</th>
          <th>Location</th>
          <th class="text-end">In</th>
          <th class="text-end">Out</th>
          <th class="text-end">Value in</th>
          <th class="text-end">Value out</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($moveRows as $m): ?>
          <tr>
            <td class="text-nowrap small"><?= h($m['move_date']) ?></td>
            <td>
              <?php if (!empty($m['doc_id'])): ?>
                <a href="document_edit.php?id=<?= (int)$m['doc_id'] ?>"><?= h($m['doc_no'] ?: ('#' . (int)$m['doc_id'])) ?></a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><span class="badge bg-light text-dark border"><?= h($m['doc_type']) ?></span></td>
            <td><?= h($m['item_code']) ?> <span class="text-muted">— <?= h($m['item_name']) ?></span></td>
            <td><?= h($m['loc_code']) ?></td>
            <td class="text-end"><?= number_format((float)$m['qty_in'], 4) ?></td>
            <td class="text-end"><?= number_format((float)$m['qty_out'], 4) ?></td>
            <td class="text-end"><?= number_format((float)$m['value_in'], 2) ?></td>
            <td class="text-end"><?= number_format((float)$m['value_out'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$moveRows): ?>
          <tr><td colspan="9" class="text-muted">No movements yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>

