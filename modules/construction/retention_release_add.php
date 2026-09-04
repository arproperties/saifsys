<?php
/**
 * Construction Module — Release Retention for Project–Contractor
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
require_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$userId = current_user_id();

$project_contractor_id = (int)($_GET['project_contractor_id'] ?? 0);
if (!$project_contractor_id) { header('Location: retention_release.php'); exit; }

// Load project–contractor and related info
$stmt = $conn->prepare("SELECT pc.*, p.project_code, p.project_name, c.contractor_name FROM co_project_contractors pc JOIN co_projects p ON p.id = pc.project_id JOIN co_contractors c ON c.id = pc.contractor_id WHERE pc.id = ? AND pc.company_id = ?");
$stmt->execute([$project_contractor_id, $cid]);
$pc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pc) { header('Location: retention_release.php'); exit; }
$project_id = (int)$pc['project_id'];

// Calculate retention balance
$totals = $conn->prepare("SELECT COALESCE(SUM(retention_held),0) AS held FROM co_contractor_payments WHERE project_contractor_id = ? AND company_id = ?");
$totals->execute([$project_contractor_id, $cid]);
$held = (float)$totals->fetchColumn();

$rel = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS released FROM co_retention_releases WHERE project_contractor_id = ? AND company_id = ?");
$rel->execute([$project_contractor_id, $cid]);
$released = (float)$rel->fetchColumn();

$balance = max(0, $held - $released);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $release_date = $_POST['release_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($amount <= 0) $err = 'Amount must be greater than zero.';
    if ($amount > $balance + 0.01) $err = 'Amount exceeds available retention balance.';

    if (!$err) {
        $stmt = $conn->prepare("INSERT INTO co_retention_releases (company_id, project_contractor_id, release_date, amount, notes, created_by) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$cid, $project_contractor_id, $release_date, $amount, $notes ?: null, $userId]);
        $releaseId = (int)$conn->lastInsertId();

        $postResult = co_post_retention_release_to_accounting($releaseId, $cid, $userId);
        if ($postResult['success'] && !empty($postResult['journal_id'])) {
            $up = $conn->prepare("UPDATE co_retention_releases SET journal_id = ? WHERE id = ?");
            $up->execute([$postResult['journal_id'], $releaseId]);
        } elseif (!$postResult['success'] && !empty($postResult['error'])) {
            $err = 'Release saved but accounting posting failed: ' . $postResult['error'];
        }

        if (empty($err)) {
            header('Location: project_view.php?id=' . $project_id . '#retention');
            exit;
        }
    }
}

$pageTitle = 'Release Retention — ' . $pc['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_view.php?id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Project</a>
    <h1 class="h4 mb-0">Release Retention</h1>
    <p class="text-muted mb-0"><?= h($pc['project_code']) ?> — <?= h($pc['project_name']) ?> / <?= h($pc['contractor_name']) ?></p>
</div>

<div class="card card-round mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><strong>Total Retention Held:</strong> <?= co_format_money($held) ?></div>
            <div class="col-md-4"><strong>Previously Released:</strong> <?= co_format_money($released) ?></div>
            <div class="col-md-4"><strong>Available Balance:</strong> <?= co_format_money($balance) ?></div>
        </div>
    </div>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Release Date *</label><input type="date" name="release_date" class="form-control" required value="<?= h($_POST['release_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Amount (AED) *</label><input type="number" step="0.01" name="amount" class="form-control" required value="<?= h($_POST['amount'] ?? ($balance > 0 ? $balance : '')) ?>"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary" <?= $balance <= 0 ? 'disabled' : '' ?>>Release Retention</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
