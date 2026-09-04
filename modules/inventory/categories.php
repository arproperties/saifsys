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
$companyId = current_company_id($conn) ?: 0;
$message = $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_permission('inventory_items.create', MODULE_INVENTORY, $conn);

    $name = trim($_POST['name'] ?? '');
    $parentId = (int)($_POST['parent_id'] ?? 0);

    if (!$companyId) {
        $message = 'Select a company first.';
        $messageType = 'warning';
    } elseif ($name === '') {
        $message = 'Name is required.';
        $messageType = 'warning';
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO inv_item_categories (company_id, parent_id, name, is_active)
                VALUES (?,?,?,1)
            ");
            $stmt->execute([$companyId, $parentId ?: null, $name]);
            $message = 'Category created.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Could not create category: ' . $e->getMessage();
            $messageType = 'warning';
        }
    }
}

$categories = [];
if ($companyId) {
    $stmt = $conn->prepare("SELECT * FROM inv_item_categories WHERE company_id = ? ORDER BY name");
    $stmt->execute([$companyId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Categories';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Item categories</div>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'info') ?>"><?= h($message) ?></div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Create category</div>
  <form method="POST" class="row g-2">
    <?php csrf_field(); ?>
    <div class="col-md-6">
      <label class="form-label">Name</label>
      <input class="form-control" name="name" required placeholder="Beverages">
    </div>
    <div class="col-md-4">
      <label class="form-label">Parent (optional)</label>
      <select class="form-select" name="parent_id">
        <option value="">— None —</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Create</button>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">Categories</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th>Parent ID</th>
          <th>Active</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($categories as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td class="fw-semibold"><?= h($c['name']) ?></td>
            <td><?= $c['parent_id'] !== null ? (int)$c['parent_id'] : '—' ?></td>
            <td><?= !empty($c['is_active']) ? 'Yes' : 'No' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$categories): ?>
          <tr><td colspan="4" class="text-muted">No categories yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
