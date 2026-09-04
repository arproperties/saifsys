<?php
/**
 * Construction — Supplier Ledger (operational AP + advance ledger view)
 * Uses the same lifecycle statement builder as the printable statement.
 */
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_advance_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_reporting_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

$supplierId = (int)($_GET['supplier_id'] ?? 0);
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-01-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

$suppliersStmt = $conn->prepare("SELECT id, supplier_name FROM co_suppliers WHERE company_id = ? ORDER BY supplier_name");
$suppliersStmt->execute([$cid]);
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);
$supplier = null;
foreach ($suppliers as $row) {
    if ((int)$row['id'] === $supplierId) {
        $supplier = $row;
    }
}

$statement = $supplier ? co_supplier_statement_build($conn, $cid, $supplierId, $dateFrom, $dateTo) : null;
$kpis = $supplier ? co_supplier_dashboard_kpis($conn, $cid, $supplierId) : null;

$exportRows = [];
if ($statement) {
    foreach ($statement['rows'] as $r) {
        $exportRows[] = [
            'txn_date' => $r['txn_date'],
            'txn_type' => $r['txn_type'],
            'reference' => $r['reference'],
            'description' => $r['description'],
            'debit' => $r['debit'],
            'credit' => $r['credit'],
            'ap_balance' => $r['ap_balance'],
            'advance_balance' => $r['advance_balance'],
            'net_payable' => $r['balance'],
        ];
    }
}

co_report_export($exportRows, [
    'txn_date' => 'Date',
    'txn_type' => 'Type',
    'reference' => 'Reference',
    'description' => 'Description',
    'debit' => 'Debit',
    'credit' => 'Credit',
    'ap_balance' => 'AP Balance',
    'advance_balance' => 'Advance Balance',
    'net_payable' => 'Net Payable',
], 'construction_supplier_ledger_' . $supplierId . '_' . $dateFrom . '_' . $dateTo, 'Construction Supplier Ledger');

$pageTitle = 'Supplier Ledger';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Supplier Ledger</h1>
        <p class="text-muted mb-0">Operational AP + advance ledger (Construction-owned; not a RE vendor sub-ledger).</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($supplier): ?>
        <a href="../supplier_view.php?id=<?= $supplierId ?>" class="btn btn-outline-secondary btn-sm">Dashboard</a>
        <?= co_report_export_buttons() ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print</button>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-4"><label class="form-label">Supplier</label>
        <select name="supplier_id" class="form-select"><option value="0">Select</option>
        <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $supplierId === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['supplier_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Generate</button></div>
</div></form>

<?php if (!$supplier || !$statement || !$kpis): ?>
<div class="card card-round"><div class="card-body text-center text-muted py-4">Select a supplier.</div></div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card card-round"><div class="card-body py-2"><div class="small text-muted">Current Outstanding AP</div><div class="fw-semibold"><?= co_format_money($kpis['outstanding_ap']) ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body py-2"><div class="small text-muted">Advance Balance</div><div class="fw-semibold"><?= co_format_money($kpis['advance_balance']) ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body py-2"><div class="small text-muted">Net Payable</div><div class="fw-semibold"><?= co_format_money($kpis['net_payable']) ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body py-2"><div class="small text-muted">Period Closing Net</div><div class="fw-semibold"><?= co_format_money($statement['closing']) ?></div></div></div></div>
</div>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-sm mb-0">
<thead class="table-light"><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">AP</th><th class="text-end">Advance</th><th class="text-end">Net</th></tr></thead>
<tbody>
<tr class="table-light"><td><?= h($dateFrom) ?></td><td>Opening</td><td></td><td>Opening</td><td></td><td></td>
<td class="text-end"><?= co_format_money($statement['opening_ap']) ?></td>
<td class="text-end"><?= co_format_money($statement['opening_advance']) ?></td>
<td class="text-end"><?= co_format_money($statement['opening']) ?></td></tr>
<?php foreach ($statement['rows'] as $row): ?>
<tr>
<td><?= h($row['txn_date']) ?></td>
<td><?= h($row['txn_type']) ?></td>
<td><?= h($row['reference']) ?></td>
<td><?= h($row['description']) ?></td>
<td class="text-end"><?= (float)$row['debit'] > 0.005 ? co_format_money($row['debit']) : '—' ?></td>
<td class="text-end"><?= (float)$row['credit'] > 0.005 ? co_format_money($row['credit']) : '—' ?></td>
<td class="text-end"><?= co_format_money($row['ap_balance']) ?></td>
<td class="text-end"><?= co_format_money($row['advance_balance']) ?></td>
<td class="text-end"><?= co_format_money($row['balance']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
