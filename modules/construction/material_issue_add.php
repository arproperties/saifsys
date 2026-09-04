<?php
/**
 * Construction Module — Add Material Issue
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
    $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
    $item_code = trim($_POST['item_code'] ?? '');
    $item_name = trim($_POST['item_name'] ?? '');
    $quantity = (float)($_POST['quantity'] ?? 1);
    $unit_cost = (float)($_POST['unit_cost'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (!$project_id || !$item_name || $quantity <= 0) $err = 'Project, item name, and quantity are required.';
    $total_cost = $quantity * $unit_cost;
    if (!$err) {
        $stmt = $conn->prepare("INSERT INTO co_material_issues (company_id, project_id, issue_date, item_code, item_name, quantity, unit_cost, total_cost, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$cid, $project_id, $issue_date, $item_code ?: null, $item_name, $quantity, $unit_cost, $total_cost, $notes ?: null, $userId]);
        header('Location: material_issues.php?project_id=' . $project_id);
        exit;
    }
}

$projects = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE company_id = ? AND status IN ('draft','active') ORDER BY project_name");
$projects->execute([$cid]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Add Material Issue';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4"><a href="material_issues.php<?= $project_id ? '?project_id='.$project_id : '' ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Add Material Issue</h1></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Project *</label><select name="project_id" class="form-select" required><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $project_id === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['project_code']) ?> — <?= h($p['project_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Issue Date *</label><input type="date" name="issue_date" class="form-control" required value="<?= h($_POST['issue_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Item Code</label><input type="text" name="item_code" class="form-control" value="<?= h($_POST['item_code'] ?? '') ?>"></div>
            <div class="col-md-8"><label class="form-label">Item Name *</label><input type="text" name="item_name" class="form-control" required value="<?= h($_POST['item_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Quantity *</label><input type="number" step="0.0001" name="quantity" class="form-control" required value="<?= h($_POST['quantity'] ?? '1') ?>"></div>
            <div class="col-md-4"><label class="form-label">Unit Cost (AED)</label><input type="number" step="0.01" name="unit_cost" class="form-control" value="<?= h($_POST['unit_cost'] ?? '0') ?>"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Issue</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
