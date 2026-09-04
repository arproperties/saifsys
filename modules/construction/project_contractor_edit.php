<?php
/**
 * Construction Module — Edit Project–Contractor assignment (contract value, retention, dates)
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
$project_contractor_id = (int)($_GET['project_contractor_id'] ?? 0);
if (!$project_contractor_id) { header('Location: projects.php'); exit; }

$stmt = $conn->prepare("
    SELECT pc.*, p.project_code, p.project_name, p.id AS project_id, c.contractor_name
    FROM co_project_contractors pc
    JOIN co_projects p ON p.id = pc.project_id
    JOIN co_contractors c ON c.id = pc.contractor_id
    WHERE pc.id = ? AND pc.company_id = ?
");
$stmt->execute([$project_contractor_id, $cid]);
$pc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pc) { header('Location: projects.php'); exit; }

$isSubcontractor = !empty($pc['parent_project_contractor_id']);
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contract_value = (float)($_POST['contract_value'] ?? 0);
    $retention_pct = (float)($_POST['retention_pct'] ?? 0);
    $coordination_fee = isset($_POST['coordination_fee']) && $_POST['coordination_fee'] !== '' ? (float)$_POST['coordination_fee'] : null;
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

    if ($contract_value < 0) $err = 'Contract value must be positive.';
    if (!$err) {
        try {
            $up = $conn->prepare("UPDATE co_project_contractors SET contract_value=?, retention_pct=?, coordination_fee=?, start_date=?, end_date=? WHERE id=? AND company_id=?");
            $up->execute([$contract_value, $retention_pct, $coordination_fee, $start_date, $end_date, $project_contractor_id, $cid]);
        } catch (Throwable $e) {
            $up = $conn->prepare("UPDATE co_project_contractors SET contract_value=?, retention_pct=?, start_date=?, end_date=? WHERE id=? AND company_id=?");
            $up->execute([$contract_value, $retention_pct, $start_date, $end_date, $project_contractor_id, $cid]);
        }
        header('Location: project_view.php?id=' . (int)$pc['project_id']);
        exit;
    }
} else {
    $_POST = $pc;
}

$pageTitle = 'Edit Assignment';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_view.php?id=<?= (int)$pc['project_id'] ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Edit <?= $isSubcontractor ? 'Subcontractor' : 'Contractor' ?> Assignment</h1>
    <p class="text-muted mb-0"><?= h($pc['project_code']) ?> — <?= h($pc['project_name']) ?> / <?= h($pc['contractor_name']) ?></p>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Contract Value (AED) *</label><input type="number" step="0.01" name="contract_value" class="form-control" required value="<?= h($_POST['contract_value'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Retention %</label><input type="number" step="0.01" name="retention_pct" class="form-control" value="<?= h($_POST['retention_pct'] ?? '0') ?>"></div>
            <?php if ($isSubcontractor): ?>
            <div class="col-md-6"><label class="form-label">Coordination/Mobilization fee (AED)</label><input type="number" step="0.01" name="coordination_fee" class="form-control" value="<?= h($_POST['coordination_fee'] ?? '') ?>" placeholder="You pay this to the main"><small class="text-muted d-block mt-1">Amount <strong>you (owner)</strong> pay to the main contractor for his coordination/mobilization of this sub.</small></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?= h($_POST['start_date'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="<?= h($_POST['end_date'] ?? '') ?>"></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Changes</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
