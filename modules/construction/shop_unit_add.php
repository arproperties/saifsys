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
$userId = current_user_id();
$err = '';
$camps = [];
if (co_db_table_exists($conn, 'co_labor_camps')) {
    $stmt = $conn->prepare("SELECT id, camp_name FROM co_labor_camps WHERE company_id = ? AND status = 'active' ORDER BY camp_name");
    $stmt->execute([$cid]);
    $camps = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campId = (int)($_POST['camp_id'] ?? 0);
    $shopNumber = trim($_POST['shop_number'] ?? '');
    $shopName = trim($_POST['shop_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $status = $_POST['status'] ?? 'available';
    $notes = trim($_POST['notes'] ?? '');
    if ($shopNumber === '') {
        $err = 'Shop number is required.';
    }
    if (!$err) {
        try {
            $stmt = $conn->prepare("INSERT INTO co_shop_units (company_id, camp_id, shop_number, shop_name, location, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$cid, $campId ?: null, $shopNumber, $shopName ?: null, $location ?: null, $status, $notes ?: null, $userId ?: null]);
            header('Location: shop_units.php');
            exit;
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}
$pageTitle = 'Add Shop Unit';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4"><a href="shop_units.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Add Shop Unit</h1></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="card card-round"><div class="card-body"><div class="row g-3">
    <div class="col-md-4"><label class="form-label">Camp</label><select name="camp_id" class="form-select"><option value="0">— none —</option><?php foreach ($camps as $camp): ?><option value="<?= (int)$camp['id'] ?>"><?= h($camp['camp_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Shop Number *</label><input type="text" name="shop_number" class="form-control" required value="<?= h($_POST['shop_number'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label">Shop Name</label><input type="text" name="shop_name" class="form-control" value="<?= h($_POST['shop_name'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">Location</label><input type="text" name="location" class="form-control" value="<?= h($_POST['location'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach (['available','occupied','inactive'] as $status): ?><option value="<?= h($status) ?>"><?= h(ucfirst($status)) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
</div><div class="mt-3"><button class="btn btn-primary">Save Shop</button></div></div></form>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
