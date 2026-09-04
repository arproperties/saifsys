<?php
/**
 * Construction Module — Add Supplier Document
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

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();
$supplier_id = (int)($_GET['supplier_id'] ?? 0);
if (!$supplier_id) { header('Location: suppliers.php'); exit; }

$stmt = $conn->prepare("SELECT id, supplier_name FROM co_suppliers WHERE id = ? AND company_id = ?");
$stmt->execute([$supplier_id, $cid]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$s) { header('Location: suppliers.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $doc_type = $_POST['doc_type'] ?? 'other';
    $notes = trim($_POST['notes'] ?? '');
    if (!in_array($doc_type, ['contract', 'license', 'invoice', 'other'])) $doc_type = 'other';
    if (!$title) $err = 'Title is required.';
    if (!$err) {
        $upload = co_handle_document_upload('document_file');
        if (isset($upload['error'])) $err = $upload['error'];
        else $file_path = $upload['path'];
    }
    if (!$err) {
        $stmt = $conn->prepare("INSERT INTO co_supplier_documents (company_id, supplier_id, doc_type, title, file_path, notes, uploaded_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$cid, $supplier_id, $doc_type, $title, $file_path, $notes ?: null, $userId]);
        header('Location: supplier_documents.php?supplier_id=' . $supplier_id);
        exit;
    }
}

$pageTitle = 'Add Supplier Document';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="supplier_documents.php?supplier_id=<?= $supplier_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Add Supplier Document</h1>
    <p class="text-muted mb-0"><?= h($s['supplier_name']) ?></p>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required value="<?= h($_POST['title'] ?? '') ?>" placeholder="e.g. Trade License, Contract, Invoice"></div>
            <div class="col-md-4"><label class="form-label">Type</label><select name="doc_type" class="form-select"><option value="contract" <?= ($_POST['doc_type'] ?? '') === 'contract' ? 'selected' : '' ?>>Contract</option><option value="license" <?= ($_POST['doc_type'] ?? '') === 'license' ? 'selected' : '' ?>>License</option><option value="invoice" <?= ($_POST['doc_type'] ?? '') === 'invoice' ? 'selected' : '' ?>>Invoice</option><option value="other" <?= ($_POST['doc_type'] ?? 'other') === 'other' ? 'selected' : '' ?>>Other</option></select></div>
            <div class="col-12"><label class="form-label">Attach file *</label><input type="file" name="document_file" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.webp"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Document</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
