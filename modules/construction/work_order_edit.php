<?php
/**
 * Construction Module — Edit Work Order
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';

require_login();
$hasAccess = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_CORE, $conn)
    || has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_PROJECTS, $conn);
if (!$hasAccess) require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: projects.php'); exit; }

// Load work order
$stmt = $conn->prepare("SELECT wo.*, p.project_code, p.project_name FROM co_work_orders wo JOIN co_projects p ON p.id = wo.project_id WHERE wo.id = ? AND wo.company_id = ?");
$stmt->execute([$id, $cid]);
$wo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wo) { header('Location: projects.php'); exit; }
$project_id = (int)$wo['project_id'];

// Load project contractors
$pcs = $conn->prepare("SELECT pc.id, c.contractor_name FROM co_project_contractors pc JOIN co_contractors c ON c.id = pc.contractor_id WHERE pc.project_id = ? AND pc.company_id = ? ORDER BY c.contractor_name");
$pcs->execute([$project_id, $cid]);
$pcs = $pcs->fetchAll(PDO::FETCH_ASSOC);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $work_order_number = trim($_POST['work_order_number'] ?? '');
    $project_contractor_id = (int)($_POST['project_contractor_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $status = $_POST['status'] ?? 'draft';
    $start_date = $_POST['start_date'] ?: null;
    $end_date = $_POST['end_date'] ?: null;
    $notes = trim($_POST['notes'] ?? '');

    if (!$work_order_number || !$description || !$project_contractor_id) $err = 'WO number, contractor and description are required.';
    if (!in_array($status, ['draft','issued','in_progress','completed','cancelled'])) $status = 'draft';
    if (!$err && $work_order_number !== $wo['work_order_number']) {
        $chk = $conn->prepare("SELECT 1 FROM co_work_orders WHERE company_id = ? AND work_order_number = ? AND id != ?");
        $chk->execute([$cid, $work_order_number, $id]);
        if ($chk->fetch()) $err = 'Work order number already exists.';
    }

    if (!$err) {
        $stmt = $conn->prepare("UPDATE co_work_orders SET project_contractor_id=?, work_order_number=?, description=?, amount=?, status=?, start_date=?, end_date=?, notes=? WHERE id=? AND company_id=?");
        $stmt->execute([$project_contractor_id, $work_order_number, $description, $amount, $status, $start_date, $end_date, $notes ?: null, $id, $cid]);
        header('Location: work_orders.php?project_id=' . $project_id);
        exit;
    }
} else {
    $_POST = $wo;
}

$pageTitle = 'Edit Work Order — ' . $wo['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="work_orders.php?project_id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Edit Work Order</h1>
    <p class="text-muted mb-0"><?= h($wo['project_code']) ?></p>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">WO Number *</label><input type="text" name="work_order_number" class="form-control" required value="<?= h($_POST['work_order_number'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Contractor *</label><select name="project_contractor_id" class="form-select" required><option value="">— Select contractor —</option><?php foreach ($pcs as $pc): ?><option value="<?= (int)$pc['id'] ?>" <?= ((int)($_POST['project_contractor_id'] ?? 0)) === (int)$pc['id'] ? 'selected' : '' ?>><?= h($pc['contractor_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Amount (AED)</label><input type="number" step="0.01" name="amount" class="form-control" value="<?= h($_POST['amount'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Description *</label><input type="text" name="description" class="form-control" required value="<?= h($_POST['description'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="draft" <?= ($_POST['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="issued" <?= ($_POST['status'] ?? '') === 'issued' ? 'selected' : '' ?>>Issued</option><option value="in_progress" <?= ($_POST['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In Progress</option><option value="completed" <?= ($_POST['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option><option value="cancelled" <?= ($_POST['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option></select></div>
            <div class="col-md-3"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?= h($_POST['start_date'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="<?= h($_POST['end_date'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Update Work Order</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
