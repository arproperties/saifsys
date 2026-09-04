<?php
/**
 * Construction Module — Add Variation Order
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
$userId = current_user_id();
$project_id = (int)($_GET['project_id'] ?? 0);
if (!$project_id) { header('Location: projects.php'); exit; }

$stmt = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE id = ? AND company_id = ?");
$stmt->execute([$project_id, $cid]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { header('Location: projects.php'); exit; }

$pcs = $conn->prepare("SELECT pc.id, c.contractor_name FROM co_project_contractors pc JOIN co_contractors c ON c.id = pc.contractor_id WHERE pc.project_id = ? AND pc.company_id = ? ORDER BY c.contractor_name");
$pcs->execute([$project_id, $cid]);
$pcs = $pcs->fetchAll(PDO::FETCH_ASSOC);

$nextVO = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(vo_number, 4) AS UNSIGNED)), 0) + 1 AS n FROM co_variation_orders WHERE company_id = ? AND vo_number LIKE 'VO-%'");
$nextVO->execute([$cid]);
$nextNum = (int)$nextVO->fetchColumn();
$suggestedVO = 'VO-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vo_number = trim($_POST['vo_number'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $project_contractor_id = (int)($_POST['project_contractor_id'] ?? 0) ?: null;
    $status = $_POST['status'] ?? 'draft';
    $vo_date = $_POST['vo_date'] ?? date('Y-m-d');
    $approved_date = $_POST['approved_date'] ?: null;
    $notes = trim($_POST['notes'] ?? '');

    if (!$vo_number || !$description) $err = 'VO number and description are required.';
    if (!in_array($status, ['draft', 'approved', 'rejected'])) $status = 'draft';
    if (!$err) {
        $chk = $conn->prepare("SELECT 1 FROM co_variation_orders WHERE company_id = ? AND vo_number = ?");
        $chk->execute([$cid, $vo_number]);
        if ($chk->fetch()) $err = 'VO number already exists.';
    }
    if (!$err) {
        $stmt = $conn->prepare("
            INSERT INTO co_variation_orders (company_id, project_id, project_contractor_id, vo_number, description, amount, status, vo_date, approved_date, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$cid, $project_id, $project_contractor_id, $vo_number, $description, $amount, $status, $vo_date, $status === 'approved' ? ($approved_date ?: $vo_date) : null, $notes ?: null, $userId]);
        header('Location: variation_orders.php?project_id=' . $project_id);
        exit;
    }
}

$pageTitle = 'Add Variation Order — ' . $project['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="variation_orders.php?project_id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Add Variation Order</h1>
    <p class="text-muted mb-0"><?= h($project['project_code']) ?> — <?= h($project['project_name']) ?></p>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">VO Number *</label><input type="text" name="vo_number" class="form-control" required value="<?= h($_POST['vo_number'] ?? $suggestedVO) ?>" placeholder="e.g. VO-001"></div>
            <div class="col-md-4"><label class="form-label">VO Date *</label><input type="date" name="vo_date" class="form-control" required value="<?= h($_POST['vo_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Contractor (optional)</label><select name="project_contractor_id" class="form-select"><option value="">— General —</option><?php foreach ($pcs as $pc): ?><option value="<?= (int)$pc['id'] ?>" <?= ((int)($_POST['project_contractor_id'] ?? 0)) === (int)$pc['id'] ? 'selected' : '' ?>><?= h($pc['contractor_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">Description *</label><input type="text" name="description" class="form-control" required value="<?= h($_POST['description'] ?? '') ?>" placeholder="Brief description of the variation"></div>
            <div class="col-md-4"><label class="form-label">Amount (AED) *</label><input type="number" step="0.01" name="amount" class="form-control" required value="<?= h($_POST['amount'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="draft" <?= ($_POST['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="approved" <?= ($_POST['status'] ?? '') === 'approved' ? 'selected' : '' ?>>Approved</option><option value="rejected" <?= ($_POST['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option></select></div>
            <div class="col-md-4"><label class="form-label">Approved Date</label><input type="date" name="approved_date" class="form-control" value="<?= h($_POST['approved_date'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save VO</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
