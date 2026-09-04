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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $companyId && ($_POST['action'] ?? '') === 'create') {
    csrf_verify();
    require_permission('inventory_purchasing.create', MODULE_INVENTORY, $conn);
    $vendorId = (int)($_POST['vendor_id'] ?? 0);
    $poDate = $_POST['po_date'] ?? date('Y-m-d');
    if (!$vendorId) {
        $message = 'Select a supplier.';
        $messageType = 'warning';
    } else {
        try {
            $id = inv_po_create($conn, [
                'company_id' => $companyId,
                'vendor_id' => $vendorId,
                'po_date' => $poDate,
                'status' => 'draft',
                'created_by' => current_user_id(),
            ]);
            header('Location: purchase_order_edit.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'warning';
        }
    }
}

$vendors = [];
if ($companyId) {
    try {
        $stmt = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? AND status = 'active' ORDER BY vendor_name");
        $stmt->execute([$companyId]);
        $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}

$pos = [];
if ($companyId) {
    try {
        $stmt = $conn->prepare("
            SELECT p.*, v.vendor_name
            FROM inv_purchase_orders p
            JOIN re_vendors v ON v.id = p.vendor_id AND v.company_id = p.company_id
            WHERE p.company_id = ?
            ORDER BY p.id DESC
            LIMIT 100
        ");
        $stmt->execute([$companyId]);
        $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}

$pageTitle = 'Purchase orders';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Purchase orders</div>
</div>

<?php if ($message): ?>
  <div class="alert alert-warning"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($companyId && has_permission('inventory_purchasing.create', MODULE_INVENTORY, $conn)): ?>
<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">New PO</div>
  <form method="POST" class="row g-2 align-items-end">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="create">
    <div class="col-md-4">
      <label class="form-label">Supplier</label>
      <select class="form-select" name="vendor_id" required>
        <option value="">—</option>
        <?php foreach ($vendors as $v): ?>
          <option value="<?= (int)$v['id'] ?>"><?= h($v['vendor_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">PO date</label>
      <input class="form-control" type="date" name="po_date" value="<?= h(date('Y-m-d')) ?>">
    </div>
    <div class="col-md-3">
      <button class="btn btn-primary">Create draft</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>PO #</th><th>Date</th><th>Supplier</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pos as $p): ?>
          <tr>
            <td class="fw-semibold"><?= h($p['po_no']) ?></td>
            <td><?= h($p['po_date']) ?></td>
            <td><?= h($p['vendor_name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= h($p['status']) ?></span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="purchase_order_edit.php?id=<?= (int)$p['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$pos): ?>
          <tr><td colspan="5" class="text-muted">No purchase orders.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
