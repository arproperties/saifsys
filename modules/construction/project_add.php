<?php
/**
 * Construction Module — Add Project
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

// Employees for project manager (from HR employees table - company must have employees)
$employees = [];
if ($cid) {
    $st = $conn->prepare("SELECT id, full_name FROM employees WHERE company_id = ? AND status = 'active' ORDER BY full_name");
    $st->execute([$cid]);
    $employees = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$clients = $conn->prepare("SELECT id, client_name FROM co_clients WHERE company_id = ? ORDER BY client_name");
$clients->execute([$cid]);
$clients = $clients->fetchAll(PDO::FETCH_ASSOC);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_code = trim($_POST['project_code'] ?? '');
    $project_name = trim($_POST['project_name'] ?? '');
    $project_type = $_POST['project_type'] ?? 'OWNER';
    $location = trim($_POST['location'] ?? '');
    $start_date = $_POST['start_date'] ?: null;
    $expected_completion_date = $_POST['expected_completion_date'] ?: null;
    $project_manager_id = (int)($_POST['project_manager_id'] ?? 0) ?: null;
    $approved_budget = (float)($_POST['approved_budget'] ?? 0) ?: null;
    $contractor_contract_value = (float)($_POST['contractor_contract_value'] ?? 0) ?: null;
    $client_id = (int)($_POST['client_id'] ?? 0) ?: null;
    $contract_value = (float)($_POST['contract_value'] ?? 0) ?: null;
    $status = $_POST['status'] ?? 'draft';
    $notes = trim($_POST['notes'] ?? '');

    if (!$project_code || !$project_name) $err = 'Project code and name are required.';
    else {
        $chk = $conn->prepare("SELECT 1 FROM co_projects WHERE company_id = ? AND project_code = ?");
        $chk->execute([$cid, $project_code]);
        if ($chk->fetch()) $err = 'Project code already exists.';
    }

    if (!$err) {
        $stmt = $conn->prepare("
            INSERT INTO co_projects (company_id, project_code, project_name, project_type, location, start_date, expected_completion_date, project_manager_id, status, approved_budget, contractor_contract_value, client_id, contract_value, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$cid, $project_code, $project_name, $project_type, $location ?: null, $start_date, $expected_completion_date, $project_manager_id, $status, $approved_budget, $contractor_contract_value, $client_id, $contract_value, $notes ?: null, $userId]);
        header('Location: project_view.php?id=' . (int)$conn->lastInsertId());
        exit;
    }
}

$pageTitle = 'Add Project';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="projects.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Add Project</h1>
</div>

<?php if ($err): ?>
<div class="alert alert-danger"><?= h($err) ?></div>
<?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Project Code *</label><input type="text" name="project_code" class="form-control" required value="<?= h($_POST['project_code'] ?? '') ?>"></div>
            <div class="col-md-8"><label class="form-label">Project Name *</label><input type="text" name="project_name" class="form-control" required value="<?= h($_POST['project_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Project Type</label><select name="project_type" class="form-select"><option value="OWNER" <?= ($_POST['project_type'] ?? '') === 'CLIENT' ? '' : 'selected' ?>>OWNER</option><option value="CLIENT" <?= ($_POST['project_type'] ?? '') === 'CLIENT' ? 'selected' : '' ?>>CLIENT</option></select></div>
            <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="draft" <?= ($_POST['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="active" <?= ($_POST['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option><option value="on_hold" <?= ($_POST['status'] ?? '') === 'on_hold' ? 'selected' : '' ?>>On Hold</option><option value="completed" <?= ($_POST['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option><option value="cancelled" <?= ($_POST['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option></select></div>
            <div class="col-md-4"><label class="form-label">Location</label><input type="text" name="location" class="form-control" value="<?= h($_POST['location'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Project Manager</label><select name="project_manager_id" class="form-select" id="project_manager_select"><option value="">— Select —</option><?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>" <?= ((int)($_POST['project_manager_id'] ?? 0)) === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['full_name']) ?></option><?php endforeach; ?></select><?php if (empty($employees)): ?><small class="text-muted d-block mt-1">No employees found. Add employees in HR for this company.</small><?php endif; ?></div>
            <div class="col-md-4"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?= h($_POST['start_date'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Expected Completion</label><input type="date" name="expected_completion_date" class="form-control" value="<?= h($_POST['expected_completion_date'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Approved Budget (AED)</label><input type="number" step="0.01" name="approved_budget" class="form-control" value="<?= h($_POST['approved_budget'] ?? '') ?>"><small class="text-muted d-block mt-1">Total internal budget for the project</small></div>
            <div class="col-md-4"><label class="form-label">Contractor Contract Value (AED)</label><input type="number" step="0.01" name="contractor_contract_value" class="form-control" value="<?= h($_POST['contractor_contract_value'] ?? '') ?>"><small class="text-muted d-block mt-1">Sum of subcontractor contract values</small></div>
            <div class="col-md-4" id="client-block" style="display:none"><label class="form-label">Client (CLIENT projects)</label><select name="client_id" class="form-select"><option value="">— Select —</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= ((int)($_POST['client_id'] ?? 0)) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4" id="contract-value-block" style="display:none"><label class="form-label">Contract Value (AED)</label><input type="number" step="0.01" name="contract_value" class="form-control" value="<?= h($_POST['contract_value'] ?? '') ?>"><small class="text-muted d-block mt-1">Amount client will pay you</small></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Project</button></div>
    </div>
</form>

<script>
(function(){
    var pt = document.querySelector('select[name="project_type"]');
    var cb = document.getElementById('client-block');
    var cv = document.getElementById('contract-value-block');
    function toggle() {
        var show = pt && pt.value === 'CLIENT';
        if (cb) cb.style.display = show ? '' : 'none';
        if (cv) cv.style.display = show ? '' : 'none';
        if (cv && cv.querySelector('input')) cv.querySelector('input').required = show;
    }
    if (pt) { pt.addEventListener('change', toggle); toggle(); }
})();
</script>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
