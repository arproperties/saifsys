<?php
/**
 * Construction Module — Delete Supplier Document
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

$cid = co_supplier_require_company_id($conn);
$id = (int)($_REQUEST['id'] ?? 0);
$supplier_id = (int)($_REQUEST['supplier_id'] ?? 0);
if (!$id || !$supplier_id) { header('Location: suppliers.php'); exit; }

$stmt = $conn->prepare("SELECT d.id, d.title, d.file_path, s.supplier_name FROM co_supplier_documents d JOIN co_suppliers s ON s.id = d.supplier_id WHERE d.id = ? AND d.company_id = ? AND d.supplier_id = ?");
$stmt->execute([$id, $cid, $supplier_id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) { header('Location: supplier_documents.php?supplier_id=' . $supplier_id); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->prepare("DELETE FROM co_supplier_documents WHERE id = ? AND company_id = ?")->execute([$id, $cid]);
    $appRoot = dirname(__DIR__, 3);
    $fullPath = $appRoot . '/' . $doc['file_path'];
    if (file_exists($fullPath)) @unlink($fullPath);
    header('Location: supplier_documents.php?supplier_id=' . $supplier_id);
    exit;
}

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$pageTitle = 'Delete Document';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="supplier_documents.php?supplier_id=<?= $supplier_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Delete Document</h1>
    <p class="text-muted mb-0"><?= h($doc['supplier_name']) ?></p>
</div>

<div class="card card-round">
    <div class="card-body">
        <p class="mb-3">Delete document <strong><?= h($doc['title']) ?></strong>? This cannot be undone.</p>
        <form method="post">
            <button type="submit" class="btn btn-danger">Delete</button>
            <a href="supplier_documents.php?supplier_id=<?= $supplier_id ?>" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
