<?php
/**
 * Construction Module — Assign Labor to Project
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$userId = current_user_id();
$project_id = (int)($_GET['project_id'] ?? 0);
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int)($_POST['project_id'] ?? 0);
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $role = $_POST['role'] ?? 'labor';
    $from_date = $_POST['from_date'] ?? date('Y-m-d');
    $to_date = $_POST['to_date'] ?: null;
    $daily_rate = (float)($_POST['daily_rate'] ?? 0);
    $hours_worked = (float)($_POST['hours_worked'] ?? 0);
    $cost_amount = (float)($_POST['cost_amount'] ?? 0);

    if (!$project_id || !$employee_id) $err = 'Project and employee are required.';
    if (!$err) {
        $stmt = $conn->prepare("INSERT INTO co_project_labor (company_id, project_id, employee_id, role, from_date, to_date, daily_rate, hours_worked, cost_amount, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$cid, $project_id, $employee_id, $role, $from_date, $to_date, $daily_rate ?: null, $hours_worked ?: null, $cost_amount ?: null, $userId]);
        header('Location: project_labor.php?project_id=' . $project_id);
        exit;
    }
}

$projects = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE company_id = ? AND status IN ('draft','active') ORDER BY project_name");
$projects->execute([$cid]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);

$employees = $conn->prepare("SELECT id, full_name FROM employees WHERE company_id = ? AND status = 'active' ORDER BY full_name");
$employees->execute([$cid]);
$employees = $employees->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Assign Labor';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4"><a href="project_labor.php<?= $project_id ? '?project_id='.$project_id : '' ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Assign Labor to Project</h1></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Project *</label><select name="project_id" class="form-select" required><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $project_id === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['project_code']) ?> — <?= h($p['project_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Employee *</label><select name="employee_id" class="form-select" required><?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>"><?= h($e['full_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Role</label><select name="role" class="form-select"><option value="labor">labor</option><option value="engineer">engineer</option><option value="supervisor">supervisor</option></select></div>
            <div class="col-md-4"><label class="form-label">From Date *</label><input type="date" name="from_date" class="form-control" required value="<?= h($_POST['from_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><label class="form-label">To Date</label><input type="date" name="to_date" class="form-control" value="<?= h($_POST['to_date'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Daily Rate (AED)</label><input type="number" step="0.01" name="daily_rate" class="form-control" value="<?= h($_POST['daily_rate'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Hours Worked</label><input type="number" step="0.01" name="hours_worked" class="form-control" value="<?= h($_POST['hours_worked'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Cost Amount (AED)</label><input type="number" step="0.01" name="cost_amount" class="form-control" value="<?= h($_POST['cost_amount'] ?? '') ?>"></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Assign</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
