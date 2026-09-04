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
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if (!$invoice_id) { header('Location: supplier_invoices.php'); exit; }
$stmt = $conn->prepare("SELECT si.*, s.supplier_name FROM co_supplier_invoices si JOIN co_suppliers s ON s.id = si.supplier_id WHERE si.id = ? AND si.company_id = ?");
$stmt->execute([$invoice_id, $cid]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { header('Location: supplier_invoices.php'); exit; }
$docs = $conn->prepare("SELECT * FROM co_supplier_invoice_documents WHERE company_id = ? AND supplier_invoice_id = ? ORDER BY uploaded_at DESC");
$docs->execute([$cid, $invoice_id]);
$docs = $docs->fetchAll(PDO::FETCH_ASSOC);
$appBase = (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';
$pageTitle = 'Invoice Attachments';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4"><a href="supplier_view.php?id=<?= (int)$inv['supplier_id'] ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Supplier</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2"><div><h1 class="h4 mb-0">Invoice Attachments</h1><p class="text-muted mb-0"><?= h($inv['supplier_name']) ?> — <?= h($inv['invoice_number']) ?></p></div>
<a href="supplier_invoice_document_add.php?invoice_id=<?= $invoice_id ?>" class="btn btn-primary">+ Attach PDF</a></div></div>
<div class="card card-round"><div class="card-body">
<?php if (empty($docs)): ?><p class="text-muted mb-0">No attachments yet. <a href="supplier_invoice_document_add.php?invoice_id=<?= $invoice_id ?>">Attach invoice PDF</a>.</p>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Title</th><th>File</th><th>Uploaded</th><th></th></tr></thead><tbody>
<?php foreach ($docs as $d): ?><tr><td><?= h($d['title']) ?></td><td><a href="<?= h($appBase . '/' . $d['file_path']) ?>" target="_blank" rel="noopener">Open</a></td><td><?= date('M j, Y H:i', strtotime($d['uploaded_at'])) ?></td><td><a href="supplier_invoice_document_delete.php?id=<?= (int)$d['id'] ?>&invoice_id=<?= $invoice_id ?>" class="btn btn-sm btn-outline-danger">Delete</a></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
