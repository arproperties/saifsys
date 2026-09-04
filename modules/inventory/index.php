<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;

$pageTitle = 'Inventory Dashboard';
require_once __DIR__ . '/includes/inv_layout_header.php';

$stats = [
    'items' => 0,
    'locations' => 0,
    'moves_30d' => 0,
];

if ($companyId) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM inv_items WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $stats['items'] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM inv_locations WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $stats['locations'] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM inv_stock_moves WHERE company_id = ? AND move_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute([$companyId]);
    $stats['moves_30d'] = (int)$stmt->fetchColumn();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div class="page-header-label">Inventory</div>
</div>

<div class="row g-3">
  <div class="col-md-4">
    <div class="card p-3">
      <div class="text-muted small">Items</div>
      <div class="fs-3 fw-bold"><?= (int)$stats['items'] ?></div>
      <a class="btn btn-sm btn-outline-primary mt-2" href="items.php">Manage items</a>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card p-3">
      <div class="text-muted small">Locations</div>
      <div class="fs-3 fw-bold"><?= (int)$stats['locations'] ?></div>
      <a class="btn btn-sm btn-outline-primary mt-2" href="locations.php">Manage locations</a>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card p-3">
      <div class="text-muted small">Moves (last 30 days)</div>
      <div class="fs-3 fw-bold"><?= (int)$stats['moves_30d'] ?></div>
      <a class="btn btn-sm btn-outline-primary mt-2" href="documents.php">View documents</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>

