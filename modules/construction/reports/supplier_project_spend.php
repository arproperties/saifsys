<?php
/**
 * Construction — Supplier spend & outstanding by project (Phase 4)
 */
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_advance_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_reporting_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

$supplierId = (int)($_GET['supplier_id'] ?? 0);
$suppliersStmt = $conn->prepare("SELECT id, supplier_name FROM co_suppliers WHERE company_id = ? ORDER BY supplier_name");
$suppliersStmt->execute([$cid]);
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

$supplier = null;
foreach ($suppliers as $row) {
    if ((int)$row['id'] === $supplierId) {
        $supplier = $row;
    }
}

$rows = $supplier ? co_supplier_project_spend($conn, $cid, $supplierId) : [];
$totals = ['invoiced' => 0.0, 'outstanding' => 0.0, 'invoice_count' => 0];
foreach ($rows as $r) {
    $totals['invoiced'] += (float)$r['invoiced'];
    $totals['outstanding'] += (float)$r['outstanding'];
    $totals['invoice_count'] += (int)$r['invoice_count'];
}

co_report_export($rows, [
    'project_code' => 'Project Code',
    'project_name' => 'Project',
    'invoiced' => 'Invoiced',
    'outstanding' => 'Outstanding',
    'invoice_count' => 'Invoices',
], 'construction_supplier_project_spend_' . $supplierId, 'Supplier Project Spend');

$pageTitle = 'Supplier Project Spend';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Supplier Project Spend</h1>
        <p class="text-muted mb-0"><?= $supplier ? h($supplier['supplier_name']) : 'Choose a supplier' ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($supplier): ?>
        <a href="../supplier_view.php?id=<?= $supplierId ?>" class="btn btn-outline-secondary btn-sm">Supplier Dashboard</a>
        <?= co_report_export_buttons() ?>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-8">
        <label class="form-label">Supplier</label>
        <select name="supplier_id" class="form-select">
            <option value="0">Select supplier</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $supplierId === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['supplier_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4"><button class="btn btn-primary w-100">Generate</button></div>
</div></form>

<?php if (!$supplier): ?>
<div class="card card-round"><div class="card-body text-center text-muted py-4">Select a supplier to view project spend.</div></div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card card-round"><div class="card-body"><div class="small text-muted">Total Invoiced</div><div class="h5 mb-0"><?= co_format_money($totals['invoiced']) ?></div></div></div></div>
    <div class="col-md-4"><div class="card card-round"><div class="card-body"><div class="small text-muted">Outstanding by Project</div><div class="h5 mb-0"><?= co_format_money($totals['outstanding']) ?></div></div></div></div>
    <div class="col-md-4"><div class="card card-round"><div class="card-body"><div class="small text-muted">Invoice Rows</div><div class="h5 mb-0"><?= (int)$totals['invoice_count'] ?></div></div></div></div>
</div>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-sm mb-0">
<thead class="table-light"><tr><th>Project</th><th class="text-end">Invoiced</th><th class="text-end">Outstanding</th><th class="text-end">Invoices</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= h($r['project_code']) ?> — <?= h($r['project_name']) ?></td>
    <td class="text-end"><?= co_format_money($r['invoiced']) ?></td>
    <td class="text-end"><?= co_format_money($r['outstanding']) ?></td>
    <td class="text-end"><?= (int)$r['invoice_count'] ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4" class="text-center text-muted py-4">No invoices for this supplier.</td></tr><?php endif; ?>
</tbody>
</table>
</div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
