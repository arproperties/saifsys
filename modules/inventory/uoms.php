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
require_permission('inventory_items.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$message = $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_permission('inventory_items.create', MODULE_INVENTORY, $conn);

    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');

    if ($code === '' || $name === '') {
        $message = 'Code and name are required.';
        $messageType = 'warning';
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO inv_uoms (code, name, is_active) VALUES (?,?,1)");
            $stmt->execute([$code, $name]);
            $message = 'UoM created.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Could not create UoM: ' . $e->getMessage();
            $messageType = 'warning';
        }
    }
}

$uoms = [];
try {
    $uoms = $conn->query("SELECT * FROM inv_uoms WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$pageTitle = 'Units of measure';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Units of measure</div>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'info') ?>"><?= h($message) ?></div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Create UoM</div>
  <form method="POST" class="row g-2">
    <?php csrf_field(); ?>
    <div class="col-md-3">
      <label class="form-label">Code</label>
      <input class="form-control" name="code" required placeholder="EA">
    </div>
    <div class="col-md-6">
      <label class="form-label">Name</label>
      <input class="form-control" name="name" required placeholder="Each">
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Create</button>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">UoMs</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Code</th>
          <th>Name</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($uoms as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td class="fw-semibold"><?= h($u['code']) ?></td>
            <td><?= h($u['name']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$uoms): ?>
          <tr><td colspan="3" class="text-muted">No UoMs yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
