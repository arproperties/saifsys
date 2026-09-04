<?php
/**
 * Construction Module — Delete Contractor Document (with confirmation)
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

$cid = current_company_id($conn) ?: 1;
$id = (int)($_REQUEST['id'] ?? 0);
$contractor_id = (int)($_REQUEST['contractor_id'] ?? 0);
if (!$id || !$contractor_id) { header('Location: contractors.php'); exit; }

$stmt = $conn->prepare("SELECT d.id, d.title, c.contractor_name FROM co_documents d JOIN co_contractors c ON c.id = d.contractor_id WHERE d.id = ? AND d.company_id = ? AND d.contractor_id = ?");
$stmt->execute([$id, $cid, $contractor_id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) { header('Location: contractor_documents.php?contractor_id=' . $contractor_id); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    co_delete_document($conn, $id, $cid);
    header('Location: contractor_documents.php?contractor_id=' . $contractor_id);
    exit;
}

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$pageTitle = 'Delete Document';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="contractor_documents.php?contractor_id=<?= $contractor_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Delete Document</h1>
    <p class="text-muted mb-0"><?= h($doc['contractor_name']) ?></p>
</div>

<div class="card card-round">
    <div class="card-body">
        <p class="mb-3">Delete document <strong><?= h($doc['title']) ?></strong>? This cannot be undone.</p>
        <form method="post">
            <button type="submit" class="btn btn-danger">Delete</button>
            <a href="contractor_documents.php?contractor_id=<?= $contractor_id ?>" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
