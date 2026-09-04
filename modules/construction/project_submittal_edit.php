<?php
/**
 * Construction Module — Edit Submittal (Phase 3)
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
$stmt = $conn->prepare("SELECT s.*, p.project_code, p.project_name FROM co_submittals s JOIN co_projects p ON p.id = s.project_id WHERE s.id = ? AND s.company_id = ?");
$stmt->execute([$id, $cid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { header('Location: projects.php'); exit; }
$project_id = (int)$row['project_id'];
$contractors = $conn->prepare("SELECT id, contractor_name FROM co_contractors WHERE company_id = ? AND is_active = 1 ORDER BY contractor_name");
$contractors->execute([$cid]);
$contractors = $contractors->fetchAll(PDO::FETCH_ASSOC);
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittal_number = trim($_POST['submittal_number'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $submittal_type = $_POST['submittal_type'] ?? 'other';
    $status = $_POST['status'] ?? 'draft';
    $contractor_id = (int)($_POST['contractor_id'] ?? 0) ?: null;
    $due_date = trim($_POST['due_date'] ?? '') ?: null;
    $submitted_date = trim($_POST['submitted_date'] ?? '') ?: null;
    $response_date = trim($_POST['response_date'] ?? '') ?: null;
    $response_notes = trim($_POST['response_notes'] ?? '') ?: null;
    if (!in_array($submittal_type, ['drawing','specification','sample','other'])) $submittal_type = 'other';
    if (!in_array($status, ['draft','submitted','under_review','approved','rejected','revised'])) $status = 'draft';
    if (!$submittal_number || !$title) $err = 'Submittal number and title are required.';
    if (!$err && $submittal_number !== $row['submittal_number']) {
        $chk = $conn->prepare("SELECT 1 FROM co_submittals WHERE company_id = ? AND submittal_number = ? AND id != ?");
        $chk->execute([$cid, $submittal_number, $id]);
        if ($chk->fetch()) $err = 'Submittal number already exists.';
    }
    if (!$err) {
        $stmt = $conn->prepare("UPDATE co_submittals SET contractor_id=?, submittal_number=?, title=?, description=?, submittal_type=?, status=?, due_date=?, submitted_date=?, response_date=?, response_notes=? WHERE id=? AND company_id=?");
        $stmt->execute([$contractor_id, $submittal_number, $title, $description ?: null, $submittal_type, $status, $due_date, $submitted_date, $response_date, $response_notes, $id, $cid]);
        header('Location: project_submittals.php?project_id=' . $project_id);
        exit;
    }
} else {
    $_POST = $row;
    $_POST['contractor_id'] = $row['contractor_id'];
}
$pageTitle = 'Edit Submittal — ' . $row['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4">
    <a href="project_submittals.php?project_id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Edit Submittal</h1>
    <p class="text-muted mb-0"><?= h($row['project_code']) ?> — <?= h($row['project_name']) ?></p>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Submittal Number *</label><input type="text" name="submittal_number" class="form-control" required value="<?= h($_POST['submittal_number'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Type</label><select name="submittal_type" class="form-select"><option value="drawing" <?= ($_POST['submittal_type'] ?? '') === 'drawing' ? 'selected' : '' ?>>Drawing</option><option value="specification" <?= ($_POST['submittal_type'] ?? '') === 'specification' ? 'selected' : '' ?>>Specification</option><option value="sample" <?= ($_POST['submittal_type'] ?? '') === 'sample' ? 'selected' : '' ?>>Sample</option><option value="other" <?= ($_POST['submittal_type'] ?? 'other') === 'other' ? 'selected' : '' ?>>Other</option></select></div>
            <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="draft" <?= ($_POST['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="submitted">Submitted</option><option value="under_review">Under Review</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="revised">Revised</option></select></div>
            <div class="col-12"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required value="<?= h($_POST['title'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"><?= h($_POST['description'] ?? '') ?></textarea></div>
            <div class="col-md-4"><label class="form-label">Contractor</label><select name="contractor_id" class="form-select"><option value="">—</option><?php foreach ($contractors as $c): ?><option value="<?= (int)$c['id'] ?>" <?= ((int)($_POST['contractor_id'] ?? 0)) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['contractor_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control" value="<?= h($_POST['due_date'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Submitted Date</label><input type="date" name="submitted_date" class="form-control" value="<?= h($_POST['submitted_date'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Response Date</label><input type="date" name="response_date" class="form-control" value="<?= h($_POST['response_date'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Response notes</label><textarea name="response_notes" class="form-control" rows="2"><?= h($_POST['response_notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Update Submittal</button></div>
    </div>
</form>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
