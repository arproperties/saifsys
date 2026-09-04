<?php
/**
 * Construction Module — Contractors List
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;

$activeOnly = !isset($_GET['all']);
$where = "WHERE company_id = ?";
$params = [$cid];
if ($activeOnly) {
    $where .= " AND is_active = 1";
}

$stmt = $conn->prepare("SELECT * FROM co_contractors $where ORDER BY contractor_name");
$stmt->execute($params);
$contractors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Contractors';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<?= co_ui_page_header(
    'Contractors',
    'Subcontractors and contractor payments',
    [['label' => 'Construction', 'href' => 'index.php'], ['label' => 'Contractors']],
    '<a href="contractor_add.php" class="btn btn-primary btn-sm"><i data-lucide="plus" style="width:14px;height:14px"></i> Add Contractor</a>'
) ?>

<div class="mb-3 co-stat-toggles">
    <a href="contractors.php" class="co-stat-toggle <?= $activeOnly ? 'active' : '' ?>">Active</a>
    <a href="contractors.php?all=1" class="co-stat-toggle <?= !$activeOnly ? 'active' : '' ?>">All</a>
</div>

<div class="card card-round co-table-shell">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contractors as $c): ?>
                    <tr>
                        <td><strong><?= h($c['contractor_name']) ?></strong></td>
                        <td><?= h($c['contact_person'] ?? '-') ?></td>
                        <td><?= h($c['phone'] ?? '-') ?></td>
                        <td><?= h($c['email'] ?? '-') ?></td>
                        <td><span class="badge bg-<?= $c['is_active'] ? 'success' : 'secondary' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td><a href="contractor_view.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($contractors)): ?>
        <div class="p-4 text-center text-muted">No contractors found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
