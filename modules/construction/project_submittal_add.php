<?php
/**
 * Construction Module — Add Submittal (Phase 3)
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

$contractors = $conn->prepare("SELECT id, contractor_name FROM co_contractors WHERE company_id = ? AND is_active = 1 ORDER BY contractor_name");
$contractors->execute([$cid]);
$contractors = $contractors->fetchAll(PDO::FETCH_ASSOC);

$nextNum = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(submittal_number, 5) AS UNSIGNED)), 0) + 1 AS n FROM co_submittals WHERE company_id = ? AND submittal_number LIKE 'SUB-%'");
$nextNum->execute([$cid]);
$n = (int)$nextNum->fetchColumn();
$suggestedNumber = 'SUB-' . str_pad($n, 3, '0', STR_PAD_LEFT);

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
    if (!$err) {
        $chk = $conn->prepare("SELECT 1 FROM co_submittals WHERE company_id = ? AND submittal_number = ?");
        $chk->execute([$cid, $submittal_number]);
        if ($chk->fetch()) $err = 'Submittal number already exists.';
    }
    if (!$err) {
        $stmt = $conn->prepare("
            INSERT INTO co_submittals (company_id, project_id, contractor_id, submittal_number, title, description, submittal_type, status, due_date, submitted_date, response_date, response_notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$cid, $project_id, $contractor_id, $submittal_number, $title, $description ?: null, $submittal_type, $status, $due_date, $submitted_date, $response_date, $response_notes, $userId]);
        header('Location: project_submittals.php?project_id=' . $project_id);
        exit;
    }
}

$pageTitle = 'Add Submittal — ' . $project['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_submittals.php?project_id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Add Submittal</h1>
    <p class="text-muted mb-0"><?= h($project['project_code']) ?> — <?= h($project['project_name']) ?></p>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Submittal Number *</label><input type="text" name="submittal_number" class="form-control" required value="<?= h($_POST['submittal_number'] ?? $suggestedNumber) ?>" placeholder="SUB-001"></div>
            <div class="col-md-4"><label class="form-label">Type</label><select name="submittal_type" class="form-select"><option value="drawing" <?= ($_POST['submittal_type'] ?? '') === 'drawing' ? 'selected' : '' ?>>Drawing</option><option value="specification" <?= ($_POST['submittal_type'] ?? '') === 'specification' ? 'selected' : '' ?>>Specification</option><option value="sample" <?= ($_POST['submittal_type'] ?? '') === 'sample' ? 'selected' : '' ?>>Sample</option><option value="other" <?= ($_POST['submittal_type'] ?? 'other') === 'other' ? 'selected' : '' ?>>Other</option></select></div>
            <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="draft" <?= ($_POST['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="submitted">Submitted</option><option value="under_review">Under Review</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="revised">Revised</option></select></div>
            <div class="col-12"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required value="<?= h($_POST['title'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Description / Spec reference</label><textarea name="description" class="form-control" rows="2"><?= h($_POST['description'] ?? '') ?></textarea></div>
            <div class="col-md-4"><label class="form-label">Contractor (optional)</label><select name="contractor_id" class="form-select"><option value="">—</option><?php foreach ($contractors as $c): ?><option value="<?= (int)$c['id'] ?>" <?= ((int)($_POST['contractor_id'] ?? 0)) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['contractor_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control" value="<?= h($_POST['due_date'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Submitted Date</label><input type="date" name="submitted_date" class="form-control" value="<?= h($_POST['submitted_date'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Response Date</label><input type="date" name="response_date" class="form-control" value="<?= h($_POST['response_date'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Response notes</label><textarea name="response_notes" class="form-control" rows="2"><?= h($_POST['response_notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Submittal</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
