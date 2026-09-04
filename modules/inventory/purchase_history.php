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
require_permission('inventory_purchasing.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;

$vendorId = !empty($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
$dFrom = trim($_GET['date_from'] ?? '');
$dTo = trim($_GET['date_to'] ?? '');

$vendors = [];
if ($companyId) {
    try {
        $stmt = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? ORDER BY vendor_name");
        $stmt->execute([$companyId]);
        $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}

$rows = [];
if ($companyId) {
    $sql = "
        SELECT g.grn_no, g.receipt_date, g.reference_no, g.posted_at,
               v.vendor_name,
               i.item_code, i.name AS item_name,
               gl.qty_received, gl.unit_cost,
               (gl.qty_received * gl.unit_cost) AS line_value
        FROM inv_goods_receipt_lines gl
        JOIN inv_goods_receipts g ON g.id = gl.header_id AND g.company_id = gl.company_id
        JOIN re_vendors v ON v.id = g.vendor_id AND v.company_id = g.company_id
        JOIN inv_items i ON i.id = gl.item_id
        WHERE g.company_id = ? AND g.status = 'posted'
    ";
    $params = [$companyId];
    if ($vendorId) {
        $sql .= " AND g.vendor_id = ? ";
        $params[] = $vendorId;
    }
    if ($dFrom !== '') {
        $sql .= " AND g.receipt_date >= ? ";
        $params[] = $dFrom;
    }
    if ($dTo !== '') {
        $sql .= " AND g.receipt_date <= ? ";
        $params[] = $dTo;
    }
    $sql .= " ORDER BY g.receipt_date DESC, g.id DESC, gl.id DESC LIMIT 500";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}

$pageTitle = 'Purchase history';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="page-header-label mb-3">Purchase history (posted GRNs)</div>

<div class="card p-3 mb-3">
  <form class="row g-2 align-items-end" method="GET">
    <div class="col-md-3">
      <label class="form-label">Supplier</label>
      <select class="form-select" name="vendor_id">
        <option value="">All</option>
        <?php foreach ($vendors as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">From</label>
      <input class="form-control" type="date" name="date_from" value="<?= h($dFrom) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">To</label>
      <input class="form-control" type="date" name="date_to" value="<?= h($dTo) ?>">
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary">Apply</button>
      <a class="btn btn-outline-secondary" href="purchase_history.php">Reset</a>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-sm">
      <thead>
        <tr>
          <th>GRN</th>
          <th>Date</th>
          <th>Supplier</th>
          <th>Ref</th>
          <th>Item</th>
          <th class="text-end">Qty</th>
          <th class="text-end">Unit cost</th>
          <th class="text-end">Line value</th>
        </tr>
      </thead>
      <tbody>
        <?php $tot = 0.0; ?>
        <?php foreach ($rows as $r): ?>
          <?php $tot += (float)$r['line_value']; ?>
          <tr>
            <td class="text-nowrap"><?= h($r['grn_no']) ?></td>
            <td><?= h($r['receipt_date']) ?></td>
            <td><?= h($r['vendor_name']) ?></td>
            <td><?= h($r['reference_no'] ?: '—') ?></td>
            <td><?= h($r['item_code']) ?> — <?= h($r['item_name']) ?></td>
            <td class="text-end"><?= number_format((float)$r['qty_received'], 4) ?></td>
            <td class="text-end"><?= number_format((float)$r['unit_cost'], 4) ?></td>
            <td class="text-end"><?= number_format((float)$r['line_value'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-muted">No rows.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot>
        <tr>
          <td colspan="7" class="text-end fw-semibold">Total (shown)</td>
          <td class="text-end fw-bold"><?= number_format($tot, 2) ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
