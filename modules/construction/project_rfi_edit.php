<?php
/**
 * Construction Module — Edit RFI (Phase 3)
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

$stmt = $conn->prepare("SELECT r.*, p.project_code, p.project_name FROM co_rfis r JOIN co_projects p ON p.id = r.project_id WHERE r.id = ? AND r.company_id = ?");
$stmt->execute([$id, $cid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { header('Location: projects.php'); exit; }
$project_id = (int)$row['project_id'];

$pcs = $conn->prepare("SELECT pc.id, c.contractor_name FROM co_project_contractors pc JOIN co_contractors c ON c.id = pc.contractor_id WHERE pc.project_id = ? AND pc.company_id = ? ORDER BY c.contractor_name");
$pcs->execute([$project_id, $cid]);
$pcs = $pcs->fetchAll(PDO::FETCH_ASSOC);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rfi_number = trim($_POST['rfi_number'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'open';
    $project_contractor_id = (int)($_POST['project_contractor_id'] ?? 0) ?: null;
    $issued_date = trim($_POST['issued_date'] ?? date('Y-m-d'));
    $due_date = trim($_POST['due_date'] ?? '') ?: null;
    $answered_date = trim($_POST['answered_date'] ?? '') ?: null;
    $response = trim($_POST['response'] ?? '') ?: null;
    if (!in_array($status, ['open','answered','closed'])) $status = 'open';
    if (!$rfi_number || !$subject || !$issued_date) $err = 'RFI number, subject and issued date are required.';
    if (!$err && $rfi_number !== $row['rfi_number']) {
        $chk = $conn->prepare("SELECT 1 FROM co_rfis WHERE company_id = ? AND rfi_number = ? AND id != ?");
        $chk->execute([$cid, $rfi_number, $id]);
        if ($chk->fetch()) $err = 'RFI number already exists.';
    }
    if (!$err) {
        $stmt = $conn->prepare("
            UPDATE co_rfis SET project_contractor_id=?, rfi_number=?, subject=?, description=?, status=?, issued_date=?, due_date=?, answered_date=?, response=?
            WHERE id=? AND company_id=?
        ");
        $stmt->execute([$project_contractor_id, $rfi_number, $subject, $description ?: null, $status, $issued_date, $due_date, $answered_date, $response, $id, $cid]);
        header('Location: project_rfis.php?project_id=' . $project_id);
        exit;
    }
} else {
    $_POST = $row;
    $_POST['project_contractor_id'] = $row['project_contractor_id'];
}

$pageTitle = 'Edit RFI — ' . $row['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_rfis.php?project_id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Edit RFI</h1>
    <p class="text-muted mb-0"><?= h($row['project_code']) ?> — <?= h($row['project_name']) ?></p>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">RFI Number *</label><input type="text" name="rfi_number" class="form-control" required value="<?= h($_POST['rfi_number'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Issued Date *</label><input type="date" name="issued_date" class="form-control" required value="<?= h($_POST['issued_date'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Contractor (optional)</label><select name="project_contractor_id" class="form-select"><option value="">—</option><?php foreach ($pcs as $pc): ?><option value="<?= (int)$pc['id'] ?>" <?= ((int)($_POST['project_contractor_id'] ?? 0)) === (int)$pc['id'] ? 'selected' : '' ?>><?= h($pc['contractor_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">Subject *</label><input type="text" name="subject" class="form-control" required value="<?= h($_POST['subject'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Description / Question</label><textarea name="description" class="form-control" rows="3"><?= h($_POST['description'] ?? '') ?></textarea></div>
            <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="open" <?= ($_POST['status'] ?? '') === 'open' ? 'selected' : '' ?>>Open</option><option value="answered">Answered</option><option value="closed">Closed</option></select></div>
            <div class="col-md-4"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control" value="<?= h($_POST['due_date'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Answered Date</label><input type="date" name="answered_date" class="form-control" value="<?= h($_POST['answered_date'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Response</label><textarea name="response" class="form-control" rows="3"><?= h($_POST['response'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Update RFI</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
