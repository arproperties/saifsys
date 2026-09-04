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
$message = $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $companyId) {
    csrf_verify();
    require_permission('inventory_purchasing.create', MODULE_INVENTORY, $conn);
    $name = trim($_POST['vendor_name'] ?? '');
    $type = trim($_POST['vendor_type'] ?? 'supplier');
    if ($name === '') {
        $message = 'Vendor name is required.';
        $messageType = 'warning';
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_vendors (company_id, vendor_name, vendor_type, status)
                VALUES (?,?,?, 'active')
            ");
            $stmt->execute([$companyId, $name, $type]);
            $message = 'Supplier saved.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Could not save: ' . $e->getMessage();
            $messageType = 'warning';
        }
    }
}

$vendors = [];
if ($companyId) {
    try {
        $stmt = $conn->prepare("
            SELECT id, vendor_name, vendor_type, status, payment_terms
            FROM re_vendors
            WHERE company_id = ?
            ORDER BY vendor_name
        ");
        $stmt->execute([$companyId]);
        $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $message = 're_vendors table missing — run project vendor migration (phase2_vendor_management / comprehensive).';
        $messageType = 'warning';
    }
}

$pageTitle = 'Suppliers';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Suppliers</div>
</div>
<p class="text-muted small">Uses shared <code>re_vendors</code>. Prefer type <strong>supplier</strong> for purchasing.</p>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'warning' ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($companyId && has_permission('inventory_purchasing.create', MODULE_INVENTORY, $conn)): ?>
<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Add supplier</div>
  <form method="POST" class="row g-2">
    <?php csrf_field(); ?>
    <div class="col-md-5">
      <label class="form-label">Name</label>
      <input class="form-control" name="vendor_name" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">Type</label>
      <select class="form-select" name="vendor_type">
        <option value="supplier">supplier</option>
        <option value="service_provider">service_provider</option>
        <option value="contractor">contractor</option>
        <option value="other">other</option>
      </select>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Save</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($vendors as $v): ?>
          <tr>
            <td><?= (int)$v['id'] ?></td>
            <td class="fw-semibold"><?= h($v['vendor_name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= h($v['vendor_type']) ?></span></td>
            <td><?= h($v['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$vendors && $companyId): ?>
          <tr><td colspan="4" class="text-muted">No vendors.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
