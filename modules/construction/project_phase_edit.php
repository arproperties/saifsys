<?php
/**
 * Construction Module — Edit Project Phase
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

$stmt = $conn->prepare("SELECT ph.*, p.project_code, p.project_name FROM co_project_phases ph JOIN co_projects p ON p.id = ph.project_id WHERE ph.id = ? AND ph.company_id = ?");
$stmt->execute([$id, $cid]);
$ph = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ph) { header('Location: projects.php'); exit; }
$project_id = (int)$ph['project_id'];

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phase_name = trim($_POST['phase_name'] ?? '');
    $sequence = (int)($_POST['sequence'] ?? 0);
    $planned_start_date = $_POST['planned_start_date'] ?: null;
    $planned_end_date = $_POST['planned_end_date'] ?: null;
    $actual_start_date = $_POST['actual_start_date'] ?: null;
    $actual_end_date = $_POST['actual_end_date'] ?: null;
    $percent_complete = (float)($_POST['percent_complete'] ?? 0);
    $status = $_POST['status'] ?? 'not_started';
    $notes = trim($_POST['notes'] ?? '');

    if (!$phase_name) $err = 'Phase name is required.';
    if ($percent_complete < 0 || $percent_complete > 100) $err = 'Percent complete must be 0–100.';
    if (!in_array($status, ['not_started', 'in_progress', 'completed'])) $status = 'not_started';

    if (!$err) {
        $stmt = $conn->prepare("
            UPDATE co_project_phases SET phase_name=?, sequence=?, planned_start_date=?, planned_end_date=?, actual_start_date=?, actual_end_date=?, percent_complete=?, status=?, notes=?
            WHERE id=? AND company_id=?
        ");
        $stmt->execute([$phase_name, $sequence, $planned_start_date, $planned_end_date, $actual_start_date, $actual_end_date, $percent_complete, $status, $notes ?: null, $id, $cid]);
        header('Location: project_phases.php?project_id=' . $project_id);
        exit;
    }
} else {
    $_POST = $ph;
}

$pageTitle = 'Edit Phase — ' . $ph['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_phases.php?project_id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Edit Phase</h1>
    <p class="text-muted mb-0"><?= h($ph['project_code']) ?> — <?= h($ph['project_name']) ?></p>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Phase Name *</label><input type="text" name="phase_name" class="form-control" required value="<?= h($_POST['phase_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Sequence</label><input type="number" name="sequence" class="form-control" value="<?= h($_POST['sequence'] ?? '0') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Planned Start</label><input type="date" name="planned_start_date" class="form-control" value="<?= h($_POST['planned_start_date'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Planned End</label><input type="date" name="planned_end_date" class="form-control" value="<?= h($_POST['planned_end_date'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Actual Start</label><input type="date" name="actual_start_date" class="form-control" value="<?= h($_POST['actual_start_date'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Actual End</label><input type="date" name="actual_end_date" class="form-control" value="<?= h($_POST['actual_end_date'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Percent Complete</label><input type="number" name="percent_complete" class="form-control" step="0.01" min="0" max="100" value="<?= h($_POST['percent_complete'] ?? '0') ?>"></div>
            <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="not_started" <?= ($_POST['status'] ?? '') === 'not_started' ? 'selected' : '' ?>>Not Started</option><option value="in_progress" <?= ($_POST['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In Progress</option><option value="completed" <?= ($_POST['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option></select></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Update Phase</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
