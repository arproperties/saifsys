<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_income_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$ready = co_db_table_exists($conn, 'co_shop_units');
$units = [];
if ($ready) {
    $stmt = $conn->prepare("
        SELECT u.*, c.camp_name
        FROM co_shop_units u
        LEFT JOIN co_labor_camps c ON c.id = u.camp_id
        WHERE u.company_id = ?
        ORDER BY COALESCE(c.camp_name, ''), u.shop_number
    ");
    $stmt->execute([$cid]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$pageTitle = 'Shop Units';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div><h1 class="h4 mb-0">Shop Units</h1><p class="text-muted mb-0">Ground-floor shop master list for labor camp rental income.</p></div>
    <a href="shop_unit_add.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Shop</a>
</div>
<?php if (!$ready): ?><div class="alert alert-warning">Run <code>migrations/construction_income_workflow.sql</code> to enable shop rental income.</div><?php endif; ?>
<div class="card card-round"><div class="card-body p-0 table-responsive">
    <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>Shop #</th><th>Name</th><th>Camp</th><th>Location</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($units as $unit): ?>
            <tr>
                <td><strong><?= h($unit['shop_number']) ?></strong></td>
                <td><?= h($unit['shop_name'] ?: '-') ?></td>
                <td><?= h($unit['camp_name'] ?: '-') ?></td>
                <td><?= h($unit['location'] ?: '-') ?></td>
                <td><span class="badge bg-<?= $unit['status'] === 'occupied' ? 'success' : ($unit['status'] === 'available' ? 'primary' : 'secondary') ?>"><?= h($unit['status']) ?></span></td>
                <td><a href="shop_unit_edit.php?id=<?= (int)$unit['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$units): ?><tr><td colspan="6" class="text-center text-muted py-4">No shop units found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
