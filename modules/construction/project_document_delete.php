<?php
/**
 * Construction Module — Delete Project Document (with confirmation)
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
$project_id = (int)($_REQUEST['project_id'] ?? 0);
if (!$id || !$project_id) { header('Location: projects.php'); exit; }

$stmt = $conn->prepare("SELECT d.id, d.title, p.project_code, p.project_name FROM co_documents d JOIN co_projects p ON p.id = d.project_id WHERE d.id = ? AND d.company_id = ? AND d.project_id = ?");
$stmt->execute([$id, $cid, $project_id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) { header('Location: project_documents.php?project_id=' . $project_id); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    co_delete_document($conn, $id, $cid);
    header('Location: project_documents.php?project_id=' . $project_id);
    exit;
}

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$pageTitle = 'Delete Document';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_documents.php?project_id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Delete Document</h1>
    <p class="text-muted mb-0"><?= h($doc['project_code']) ?> — <?= h($doc['project_name']) ?></p>
</div>

<div class="card card-round">
    <div class="card-body">
        <p class="mb-3">Delete document <strong><?= h($doc['title']) ?></strong>? This cannot be undone.</p>
        <form method="post">
            <button type="submit" class="btn btn-danger">Delete</button>
            <a href="project_documents.php?project_id=<?= $project_id ?>" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
