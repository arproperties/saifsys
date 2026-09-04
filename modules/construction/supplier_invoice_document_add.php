<?php
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
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if (!$invoice_id) { header('Location: supplier_invoices.php'); exit; }
$stmt = $conn->prepare("SELECT si.*, s.supplier_name FROM co_supplier_invoices si JOIN co_suppliers s ON s.id = si.supplier_id WHERE si.id = ? AND si.company_id = ?");
$stmt->execute([$invoice_id, $cid]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { header('Location: supplier_invoices.php'); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '') ?: 'Invoice PDF';
    $upload = co_handle_document_upload('document_file');
    if (isset($upload['error'])) $err = $upload['error'];
    else {
        $conn->prepare("INSERT INTO co_supplier_invoice_documents (company_id, supplier_invoice_id, title, file_path, uploaded_by) VALUES (?,?,?,?,?)")->execute([$cid, $invoice_id, $title, $upload['path'], $userId]);
        header('Location: supplier_invoice_documents.php?invoice_id=' . $invoice_id);
        exit;
    }
}
$pageTitle = 'Attach Invoice PDF';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4"><a href="supplier_invoice_documents.php?invoice_id=<?= $invoice_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Attach Invoice PDF</h1><p class="text-muted mb-0"><?= h($inv['supplier_name']) ?> — <?= h($inv['invoice_number']) ?></p></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card card-round"><div class="card-body">
<label class="form-label">Title</label><input type="text" name="title" class="form-control mb-2" value="<?= h($_POST['title'] ?? 'Invoice PDF') ?>">
<label class="form-label">File *</label><input type="file" name="document_file" class="form-control mb-3" accept=".pdf,.jpg,.jpeg,.png" required>
<button type="submit" class="btn btn-primary">Upload</button></div></form>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
