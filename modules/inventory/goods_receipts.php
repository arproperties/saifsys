<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_purchasing.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_purchasing.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$message = $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $companyId && ($_POST['action'] ?? '') === 'create_standalone') {
    csrf_verify();
    require_permission('inventory_purchasing.create', MODULE_INVENTORY, $conn);
    $vendorId = (int)($_POST['vendor_id'] ?? 0);
    $locId = (int)($_POST['location_to_id'] ?? 0);
    $rd = $_POST['receipt_date'] ?? date('Y-m-d');
    $ref = trim($_POST['reference_no'] ?? '');
    $over = !empty($_POST['allow_over_receipt']);
    if (!$vendorId || !$locId) {
        $message = 'Supplier and receive location required.';
        $messageType = 'warning';
    } else {
        try {
            $id = inv_grn_create($conn, [
                'company_id' => $companyId,
                'vendor_id' => $vendorId,
                'po_id' => null,
                'receipt_date' => $rd,
                'location_to_id' => $locId,
                'reference_no' => $ref !== '' ? $ref : null,
                'allow_over_receipt' => $over ? 1 : 0,
                'created_by' => current_user_id(),
            ]);
            header('Location: goods_receipt_edit.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'warning';
        }
    }
}

$vendors = [];
$locs = [];
if ($companyId) {
    try {
        $stmt = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? AND status = 'active' ORDER BY vendor_name");
        $stmt->execute([$companyId]);
        $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
    $stmt = $conn->prepare("SELECT id, code, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$companyId]);
    $locs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$grns = [];
if ($companyId) {
    try {
        $stmt = $conn->prepare("
            SELECT g.*, v.vendor_name
            FROM inv_goods_receipts g
            JOIN re_vendors v ON v.id = g.vendor_id AND v.company_id = g.company_id
            WHERE g.company_id = ?
            ORDER BY g.id DESC
            LIMIT 100
        ");
        $stmt->execute([$companyId]);
        $grns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}

$pageTitle = 'Goods receipts';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="page-header-label mb-3">Goods receipts (GRN)</div>

<?php if ($message): ?>
  <div class="alert alert-warning"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($companyId && has_permission('inventory_purchasing.create', MODULE_INVENTORY, $conn)): ?>
<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">New standalone GRN (no PO)</div>
  <form method="POST" class="row g-2">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="create_standalone">
    <div class="col-md-3">
      <label class="form-label">Supplier</label>
      <select class="form-select" name="vendor_id" required>
        <option value="">—</option>
        <?php foreach ($vendors as $v): ?>
          <option value="<?= (int)$v['id'] ?>"><?= h($v['vendor_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Receive into</label>
      <select class="form-select" name="location_to_id" required>
        <?php foreach ($locs as $l): ?>
          <option value="<?= (int)$l['id'] ?>"><?= h($l['code'] . ' — ' . $l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Date</label>
      <input class="form-control" type="date" name="receipt_date" value="<?= h(date('Y-m-d')) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Reference</label>
      <input class="form-control" name="reference_no" placeholder="Delivery note / invoice #">
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="allow_over_receipt" id="ov">
        <label class="form-check-label" for="ov">Allow over-qty</label>
      </div>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Create draft</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card p-3">
  <table class="table table-sm">
    <thead><tr><th>GRN #</th><th>Date</th><th>Supplier</th><th>Ref</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($grns as $g): ?>
        <tr>
          <td class="fw-semibold"><?= h($g['grn_no']) ?></td>
          <td><?= h($g['receipt_date']) ?></td>
          <td><?= h($g['vendor_name']) ?></td>
          <td><?= h($g['reference_no'] ?: '—') ?></td>
          <td><span class="badge bg-light text-dark border"><?= h($g['status']) ?></span></td>
          <td><a class="btn btn-sm btn-outline-primary" href="goods_receipt_edit.php?id=<?= (int)$g['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$grns): ?>
        <tr><td colspan="6" class="text-muted">No goods receipts.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
