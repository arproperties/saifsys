<?php
/**
 * Construction Module — Add RFI (Phase 3)
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
$nextNum = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(rfi_number, 5) AS UNSIGNED)), 0) + 1 AS n FROM co_rfis WHERE company_id = ? AND rfi_number LIKE 'RFI-%'");
$nextNum->execute([$cid]);
$n = (int)$nextNum->fetchColumn();
$suggestedNumber = 'RFI-' . str_pad($n, 3, '0', STR_PAD_LEFT);
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
    if (!$err) {
        $chk = $conn->prepare("SELECT 1 FROM co_rfis WHERE company_id = ? AND rfi_number = ?");
        $chk->execute([$cid, $rfi_number]);
        if ($chk->fetch()) $err = 'RFI number already exists.';
    }
    if (!$err) {
        $stmt = $conn->prepare("
            INSERT INTO co_rfis (company_id, project_id, project_contractor_id, rfi_number, subject, description, status, issued_date, due_date, answered_date, response, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$cid, $project_id, $project_contractor_id, $rfi_number, $subject, $description ?: null, $status, $issued_date, $due_date, $answered_date, $response, $userId]);
        header('Location: project_rfis.php?project_id=' . $project_id);
        exit;
    }
}
$pageTitle = 'Add RFI — ' . $project['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4">
    <a href="project_rfis.php?project_id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Add RFI</h1>
    <p class="text-muted mb-0"><?= h($project['project_code']) ?> — <?= h($project['project_name']) ?></p>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">RFI Number *</label><input type="text" name="rfi_number" class="form-control" required value="<?= h($_POST['rfi_number'] ?? $suggestedNumber) ?>" placeholder="RFI-001"></div>
            <div class="col-md-4"><label class="form-label">Issued Date *</label><input type="date" name="issued_date" class="form-control" required value="<?= h($_POST['issued_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Contractor</label><select name="project_contractor_id" class="form-select"><option value="">—</option><?php foreach ($pcs as $pc): ?><option value="<?= (int)$pc['id'] ?>" <?= ((int)($_POST['project_contractor_id'] ?? 0)) === (int)$pc['id'] ? 'selected' : '' ?>><?= h($pc['contractor_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">Subject *</label><input type="text" name="subject" class="form-control" required value="<?= h($_POST['subject'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Description / Question</label><textarea name="description" class="form-control" rows="3"><?= h($_POST['description'] ?? '') ?></textarea></div>
            <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="open" <?= ($_POST['status'] ?? 'open') === 'open' ? 'selected' : '' ?>>Open</option><option value="answered">Answered</option><option value="closed">Closed</option></select></div>
            <div class="col-md-4"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control" value="<?= h($_POST['due_date'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Answered Date</label><input type="date" name="answered_date" class="form-control" value="<?= h($_POST['answered_date'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Response</label><textarea name="response" class="form-control" rows="3"><?= h($_POST['response'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save RFI</button></div>
    </div>
</form>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
