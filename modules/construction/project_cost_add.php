<?php
/**
 * Construction Module — Add Project Cost
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_accounting_integration.php';

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
    $cost_date = $_POST['cost_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $cost_type = $_POST['cost_type'] ?? 'miscellaneous';
    $description = trim($_POST['description'] ?? '');
    $reference = trim($_POST['reference'] ?? '');
    $employee_id = (int)($_POST['employee_id'] ?? 0) ?: null;
    $contractor_id = (int)($_POST['contractor_id'] ?? 0) ?: null;

    if (!$project_id || $amount <= 0) $err = 'Project and amount are required.';
    if (!$err) {
        $stmt = $conn->prepare("INSERT INTO co_project_costs (company_id, project_id, cost_date, amount, cost_type, description, reference, employee_id, contractor_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$cid, $project_id, $cost_date, $amount, $cost_type, $description ?: null, $reference ?: null, $employee_id, $contractor_id, $userId]);
        $costId = (int)$conn->lastInsertId();
        $postResult = co_post_project_cost_to_accounting($costId, $cid, $userId);
        if ($postResult['success'] && !empty($postResult['journal_id'])) {
            $up = $conn->prepare("UPDATE co_project_costs SET journal_id = ? WHERE id = ?");
            $up->execute([$postResult['journal_id'], $costId]);
        } elseif (!$postResult['success'] && !empty($postResult['error'])) {
            $err = 'Cost saved but accounting posting failed: ' . $postResult['error'];
        }
        if (empty($err)) {
            header('Location: project_costs.php?project_id=' . $project_id);
            exit;
        }
    }
}

$projects = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE company_id = ? AND status IN ('draft','active') ORDER BY project_name");
$projects->execute([$cid]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);

$employees = $conn->prepare("SELECT id, full_name FROM employees WHERE company_id = ? AND status = 'active' ORDER BY full_name");
$employees->execute([$cid]);
$employees = $employees->fetchAll(PDO::FETCH_ASSOC);
$contractors = $conn->prepare("SELECT id, contractor_name FROM co_contractors WHERE company_id = ? AND is_active = 1 ORDER BY contractor_name");
$contractors->execute([$cid]);
$contractors = $contractors->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Add Project Cost';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4"><a href="project_costs.php<?= $project_id ? '?project_id='.$project_id : '' ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Add Project Cost</h1></div>
<?php if ($err): ?>
<div class="alert alert-danger">
    <?= h($err) ?>
    <?php if (strpos($err, 'not found') !== false && strpos($err, 'account') !== false): ?>
    <hr class="my-2">
    <p class="mb-2 small">Add the construction accounts (1515, 5125, 2145, 2125) so costs can post to accounting.</p>
    <a href="setup_construction_coa.php" class="btn btn-sm btn-warning">Setup construction accounts now</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Project *</label><select name="project_id" class="form-select" required><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $project_id === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['project_code']) ?> — <?= h($p['project_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Date *</label><input type="date" name="cost_date" class="form-control" required value="<?= h($_POST['cost_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Amount (AED) *</label><input type="number" step="0.01" name="amount" class="form-control" required value="<?= h($_POST['amount'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Cost Type</label><select name="cost_type" class="form-select"><option value="materials">materials</option><option value="labor">labor</option><option value="subcontractor">subcontractor</option><option value="equipment">equipment</option><option value="miscellaneous">miscellaneous</option></select></div>
            <div class="col-md-4"><label class="form-label">Labor (if labor)</label><select name="employee_id" class="form-select"><option value="">—</option><?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>"><?= h($e['full_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Contractor (if subcontractor)</label><select name="contractor_id" class="form-select"><option value="">—</option><?php foreach ($contractors as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['contractor_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-8"><label class="form-label">Description</label><input type="text" name="description" class="form-control" value="<?= h($_POST['description'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Reference</label><input type="text" name="reference" class="form-control" value="<?= h($_POST['reference'] ?? '') ?>"></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Cost</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
