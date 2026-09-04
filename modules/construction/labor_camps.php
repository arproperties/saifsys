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
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campName = trim($_POST['camp_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if ($campName === '') $err = 'Camp name is required.';
    if (!$err) {
        $stmt = $conn->prepare("INSERT INTO co_labor_camps (company_id, camp_name, location, notes, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$cid, $campName, $location ?: null, $notes ?: null, current_user_id() ?: null]);
        header('Location: labor_camps.php');
        exit;
    }
}
$rows = [];
if (co_db_table_exists($conn, 'co_labor_camps')) {
    $stmt = $conn->prepare("SELECT * FROM co_labor_camps WHERE company_id = ? ORDER BY camp_name");
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$pageTitle = 'Labor Camps';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4"><h1 class="h4 mb-0">Labor Camps</h1><p class="text-muted mb-0">Camp master list for camp management income and shop units.</p></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<div class="row g-3">
    <div class="col-lg-4"><form method="post" class="card card-round"><div class="card-header bg-white"><strong>Add Camp</strong></div><div class="card-body">
        <label class="form-label">Camp Name *</label><input type="text" name="camp_name" class="form-control mb-3" required>
        <label class="form-label">Location</label><input type="text" name="location" class="form-control mb-3">
        <label class="form-label">Notes</label><textarea name="notes" class="form-control mb-3" rows="3"></textarea>
        <button class="btn btn-primary">Save Camp</button>
    </div></form></div>
    <div class="col-lg-8"><div class="card card-round"><div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0"><thead class="table-light"><tr><th>Camp</th><th>Location</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr><td><strong><?= h($row['camp_name']) ?></strong></td><td><?= h($row['location'] ?: '-') ?></td><td><span class="badge bg-<?= $row['status'] === 'active' ? 'success' : 'secondary' ?>"><?= h($row['status']) ?></span></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="3" class="text-center text-muted py-4">No labor camps found.</td></tr><?php endif; ?>
        </tbody></table>
    </div></div></div>
</div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
