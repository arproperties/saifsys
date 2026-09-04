<?php
/**
 * Construction — Advance VAT document view / post / reverse
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_advance_helpers.php';
require_once __DIR__ . '/includes/construction_accounting_integration.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();
$id = (int)($_GET['id'] ?? 0);
$msg = !empty($_GET['saved']) ? 'Draft saved.' : (!empty($_GET['posted']) ? 'VAT document posted.' : '');
$err = '';
$doc = co_supplier_load_advance_vat_doc($conn, $cid, $id);
if (!$doc) { header('Location: supplier_advance_vat_documents.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'post') {
        $res = co_supplier_post_advance_vat_document($conn, $cid, $id, $userId ? (int)$userId : null);
        if (!empty($res['success'])) {
            header('Location: supplier_advance_vat_view.php?id=' . $id . '&posted=1');
            exit;
        }
        $err = $res['error'] ?? 'Post failed';
    } elseif ($action === 'reverse') {
        $res = co_supplier_reverse_advance_vat_document($conn, $cid, $id, trim($_POST['reason'] ?? ''), $userId ? (int)$userId : null);
        if (!empty($res['success'])) {
            $msg = 'VAT document reversed.';
        } else {
            $err = $res['error'] ?? 'Reverse failed';
        }
    }
    $doc = co_supplier_load_advance_vat_doc($conn, $cid, $id) ?: $doc;
}

$sup = $conn->prepare("SELECT supplier_name FROM co_suppliers WHERE id=? AND company_id=?");
$sup->execute([(int)$doc['supplier_id'], $cid]);
$supplierName = (string)$sup->fetchColumn();
$consumed = co_supplier_advance_vat_doc_consumed($conn, $cid, $id);
$remaining = ($doc['status'] ?? '') === 'posted' ? co_supplier_advance_vat_doc_remaining($conn, $cid, $id) : 0.0;
$links = [];
$ls = $conn->prepare("
    SELECT l.*, si.invoice_number FROM co_supplier_advance_vat_invoice_links l
    JOIN co_supplier_invoices si ON si.id = l.supplier_invoice_id AND si.company_id = l.company_id
    WHERE l.company_id=? AND l.advance_vat_document_id=? ORDER BY l.id DESC
");
$ls->execute([$cid, $id]);
$links = $ls->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Advance VAT ' . $doc['supplier_invoice_number'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
    <div>
        <a href="supplier_advance_vat_documents.php" class="btn btn-outline-secondary btn-sm mb-2">Back</a>
        <h1 class="h4 mb-0">Advance VAT <?= h($doc['supplier_invoice_number']) ?>
            <span class="badge bg-<?= $doc['status']==='posted'?'success':($doc['status']==='draft'?'secondary':'dark') ?>"><?= h(ucfirst($doc['status'])) ?></span>
        </h1>
        <p class="text-muted mb-0"><?= h($supplierName) ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if (($doc['status'] ?? '') === 'draft'): ?>
        <a href="supplier_advance_vat_edit.php?id=<?= $id ?>" class="btn btn-outline-primary">Edit</a>
        <form method="post" onsubmit="return confirm('Post this Advance VAT document to GL?');"><?php csrf_field(); ?><input type="hidden" name="action" value="post"><button class="btn btn-warning">Post to GL</button></form>
        <?php elseif (($doc['status'] ?? '') === 'posted'): ?>
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#revModal">Reverse</button>
        <?php endif; ?>
        <a href="supplier_payment_view.php?id=<?= (int)$doc['supplier_payment_id'] ?>" class="btn btn-outline-secondary">Source Payment</a>
    </div>
</div>
<div class="card card-round mb-3"><div class="card-body">
<div class="row g-3">
<div class="col-md-3"><div class="text-muted small">Taxable</div><div class="h5"><?= co_format_money($doc['taxable_amount']) ?></div></div>
<div class="col-md-3"><div class="text-muted small">VAT</div><div class="h5"><?= co_format_money($doc['vat_amount']) ?></div></div>
<div class="col-md-3"><div class="text-muted small">Linked to invoices</div><div class="h5"><?= co_format_money($consumed) ?></div></div>
<div class="col-md-3"><div class="text-muted small">Remaining for invoices</div><div class="h5"><?= co_format_money($remaining) ?></div></div>
</div></div></div>
<?php if ($links): ?>
<div class="card card-round"><div class="card-header bg-white"><h6 class="mb-0">Invoice Links</h6></div>
<div class="card-body p-0 table-responsive"><table class="table table-sm mb-0">
<thead class="table-light"><tr><th>Invoice</th><th class="text-end">VAT linked</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($links as $l): ?>
<tr><td><a href="supplier_invoice_view.php?id=<?= (int)$l['supplier_invoice_id'] ?>"><?= h($l['invoice_number']) ?></a></td>
<td class="text-end"><?= co_format_money($l['vat_amount_linked']) ?></td>
<td><?= h($l['status']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php if (($doc['status'] ?? '') === 'posted'): ?>
<div class="modal fade" id="revModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
<?php csrf_field(); ?><input type="hidden" name="action" value="reverse">
<div class="modal-header"><h5 class="modal-title">Reverse Advance VAT</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><label class="form-label">Reason</label><input type="text" name="reason" class="form-control" required></div>
<div class="modal-footer"><button class="btn btn-warning">Reverse</button></div>
</form></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
