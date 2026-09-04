<?php
/**
 * Construction Module — Link Contractor to Project
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

$project_id = (int)($_GET['project_id'] ?? 0);
$contractor_id = (int)($_GET['contractor_id'] ?? 0);
$parent_project_contractor_id = (int)($_GET['parent_project_contractor_id'] ?? 0);
$err = '';
$isSubcontractor = false;
$mainContractorName = '';
$subcontractorMigrationRequired = false;

if ($parent_project_contractor_id) {
    try {
        $parentRow = $conn->prepare("SELECT pc.project_id, pc.company_id, c.contractor_name FROM co_project_contractors pc JOIN co_contractors c ON c.id = pc.contractor_id WHERE pc.id = ? AND pc.company_id = ? AND pc.parent_project_contractor_id IS NULL");
        $parentRow->execute([$parent_project_contractor_id, $cid]);
        $parentRow = $parentRow->fetch(PDO::FETCH_ASSOC);
        if ($parentRow) {
            $isSubcontractor = true;
            $project_id = (int)$parentRow['project_id'];
            $mainContractorName = $parentRow['contractor_name'];
        }
    } catch (Throwable $e) {
        $subcontractorMigrationRequired = true;
        $parent_project_contractor_id = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int)($_POST['project_id'] ?? 0);
    $contractor_id = (int)($_POST['contractor_id'] ?? 0);
    $parent_project_contractor_id = (int)($_POST['parent_project_contractor_id'] ?? 0);
    $contract_value = (float)($_POST['contract_value'] ?? 0);
    $coordination_fee = isset($_POST['coordination_fee']) && $_POST['coordination_fee'] !== '' ? (float)$_POST['coordination_fee'] : null;
    $retention_pct = (float)($_POST['retention_pct'] ?? 0);
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

    if (!$project_id || !$contractor_id) $err = 'Project and contractor are required.';
    if ($contract_value < 0) $err = 'Contract value must be positive.';
    if (!$err && $parent_project_contractor_id) {
        try {
            $verify = $conn->prepare("SELECT id FROM co_project_contractors WHERE id = ? AND company_id = ? AND project_id = ? AND parent_project_contractor_id IS NULL");
            $verify->execute([$parent_project_contractor_id, $cid, $project_id]);
            if (!$verify->fetch()) $err = 'Invalid main contractor for this project.';
        } catch (Throwable $e) { $err = 'Subcontractor feature requires database migration. Run migrations/construction_subcontractors.sql'; }
    }
    if (!$err) {
        $chk = $conn->prepare("SELECT 1 FROM co_project_contractors WHERE company_id=? AND project_id=? AND contractor_id=?");
        $chk->execute([$cid, $project_id, $contractor_id]);
        if ($chk->fetch()) $err = 'This contractor is already linked to this project.';
    }
    if (!$err) {
            try {
                $stmt = $conn->prepare("INSERT INTO co_project_contractors (company_id, project_id, contractor_id, parent_project_contractor_id, contract_value, coordination_fee, retention_pct, start_date, end_date, status) VALUES (?,?,?,?,?,?,?,?,?,'active')");
                $stmt->execute([$cid, $project_id, $contractor_id, $parent_project_contractor_id ?: null, $contract_value, $coordination_fee, $retention_pct, $start_date, $end_date]);
            } catch (Throwable $e) {
                $stmt = $conn->prepare("INSERT INTO co_project_contractors (company_id, project_id, contractor_id, contract_value, retention_pct, start_date, end_date, status) VALUES (?,?,?,?,?,?,?,'active')");
                $stmt->execute([$cid, $project_id, $contractor_id, $contract_value, $retention_pct, $start_date, $end_date]);
            }
            header('Location: project_view.php?id=' . $project_id);
            exit;
        }
    }
$projects = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE company_id = ? AND status IN ('draft','active') ORDER BY project_name");
$projects->execute([$cid]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);

$contractors = $conn->prepare("SELECT id, contractor_name FROM co_contractors WHERE company_id = ? AND is_active = 1 ORDER BY contractor_name");
$contractors->execute([$cid]);
$contractors = $contractors->fetchAll(PDO::FETCH_ASSOC);

$mainContractorsForProject = [];
if ($project_id) {
    try {
        $mains = $conn->prepare("SELECT pc.id, c.contractor_name FROM co_project_contractors pc JOIN co_contractors c ON c.id = pc.contractor_id WHERE pc.project_id = ? AND pc.company_id = ? AND (pc.parent_project_contractor_id IS NULL OR pc.parent_project_contractor_id = 0) ORDER BY c.contractor_name");
        $mains->execute([$project_id, $cid]);
        $mainContractorsForProject = $mains->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $mainContractorsForProject = [];
        $subcontractorMigrationRequired = true;
    }
}

$pageTitle = $isSubcontractor ? 'Add Subcontractor' : 'Project–Contractor';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="<?= $project_id ? 'project_view.php?id=' . $project_id : 'projects.php' ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0"><?= $isSubcontractor ? 'Add Subcontractor' : 'Link Contractor to Project' ?></h1>
    <?php if ($isSubcontractor): ?><p class="text-muted mb-0">Under main contractor: <strong><?= h($mainContractorName) ?></strong></p><?php endif; ?>
</div>

<?php if ($subcontractorMigrationRequired): ?>
<div class="alert alert-warning">
    <strong><i class="bi bi-exclamation-triangle me-1"></i> Subcontractor feature requires a database update.</strong><br>
    To add subcontractors (or use "+ Sub"), run this SQL on your database once: <code>migrations/construction_subcontractors.sql</code><br>
    Until then, you can still link contractors as <strong>main contractors</strong> using the form below.
</div>
<?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <input type="hidden" name="parent_project_contractor_id" value="<?= (int)$parent_project_contractor_id ?>">
        <div class="row g-3">
            <?php if ($isSubcontractor): ?>
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <div class="col-12"><div class="alert alert-info py-2 mb-0"><i class="bi bi-info-circle me-1"></i> Subcontractor will work under <strong><?= h($mainContractorName) ?></strong>. As <strong>owner</strong>, you pay the main contractor a coordination/mobilization fee for the services he provides to manage and mobilize this subcontractor.</div></div>
            <?php else: ?>
            <div class="col-md-6"><label class="form-label">Project *</label><select name="project_id" id="project_id" class="form-select" required><option value="">— Select —</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $project_id === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['project_code']) ?> — <?= h($p['project_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Link as subcontractor under (optional)</label><select name="parent_project_contractor_id" id="parent_pc" class="form-select"><option value="">— No (main contractor) —</option><?php foreach ($mainContractorsForProject as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['contractor_name']) ?></option><?php endforeach; ?></select><small class="text-muted d-block mt-1">Leave as "No" for main contractor; select a main to add a subcontractor under them.</small></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label">Contractor *</label><select name="contractor_id" class="form-select" required><option value="">— Select —</option><?php foreach ($contractors as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $contractor_id === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['contractor_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Contract Value (AED) *</label><input type="number" step="0.01" name="contract_value" class="form-control" required value="<?= h($_POST['contract_value'] ?? '') ?>"></div>
            <?php if ($isSubcontractor || !empty($mainContractorsForProject)): ?>
            <div class="col-md-4"><label class="form-label">Coordination/Mobilization fee (AED)</label><input type="number" step="0.01" name="coordination_fee" class="form-control" value="<?= h($_POST['coordination_fee'] ?? '') ?>" placeholder="You pay this to the main"><small class="text-muted d-block mt-1">Amount <strong>you (owner)</strong> pay to the main contractor for his coordination/mobilization of this sub.</small></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label">Retention %</label><input type="number" step="0.01" name="retention_pct" class="form-control" value="<?= h($_POST['retention_pct'] ?? '0') ?>"></div>
            <div class="col-md-4"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?= h($_POST['start_date'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="<?= h($_POST['end_date'] ?? '') ?>"></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary"><?= $isSubcontractor ? 'Add Subcontractor' : 'Link' ?></button></div>
    </div>
</form>
<script>
document.getElementById('project_id') && document.getElementById('project_id').addEventListener('change', function(){ window.location = 'project_contractors.php?project_id=' + this.value; });
</script>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
