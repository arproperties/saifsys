<?php
/**
 * Construction — Supplier Advance VAT Documents list
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_advance_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$ready = co_supplier_advance_vat_table_ready($conn);
$rows = [];
if ($ready) {
    $st = $conn->prepare("
        SELECT d.*, s.supplier_name
        FROM co_supplier_advance_vat_documents d
        JOIN co_suppliers s ON s.id = d.supplier_id AND s.company_id = d.company_id
        WHERE d.company_id = ?
        ORDER BY d.supplier_invoice_date DESC, d.id DESC
        LIMIT 200
    ");
    $st->execute([$cid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$pageTitle = 'Advance VAT Documents';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0">Advance VAT Documents</h1>
        <p class="text-muted mb-0">Document-driven Input VAT on supplier advances (Dr 2130 / Cr Advances).</p>
    </div>
    <a href="supplier_advance_vat_edit.php" class="btn btn-primary<?= $ready ? '' : ' disabled' ?>">New VAT Document</a>
</div>
<?php if (!$ready): ?>
<div class="alert alert-warning">Run <code>migrations/construction_supplier_ap_phase3_advance_vat_refunds.sql</code>.</div>
<?php endif; ?>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-sm table-hover mb-0">
<thead class="table-light"><tr><th>Date</th><th>Supplier</th><th>Tax Invoice #</th><th>Payment</th><th class="text-end">Taxable</th><th class="text-end">VAT</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
<td><?= h($r['supplier_invoice_date']) ?></td>
<td><?= h($r['supplier_name']) ?></td>
<td><?= h($r['supplier_invoice_number']) ?></td>
<td><a href="supplier_payment_view.php?id=<?= (int)$r['supplier_payment_id'] ?>">#<?= (int)$r['supplier_payment_id'] ?></a></td>
<td class="text-end"><?= co_format_money($r['taxable_amount']) ?></td>
<td class="text-end"><?= co_format_money($r['vat_amount']) ?></td>
<td><span class="badge bg-<?= $r['status']==='posted'?'success':($r['status']==='draft'?'secondary':'dark') ?>"><?= h(ucfirst($r['status'])) ?></span></td>
<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="supplier_advance_vat_view.php?id=<?= (int)$r['id'] ?>">Open</a></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No advance VAT documents.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
