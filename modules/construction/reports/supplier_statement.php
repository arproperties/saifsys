<?php
/**
 * Construction — Printable Supplier Statement (Phase 4 full lifecycle)
 */
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_advance_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_reporting_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

$supplierId = (int)($_GET['supplier_id'] ?? 0);
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
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

$statement = null;
$exportRows = [];
if ($supplier) {
    $statement = co_supplier_statement_build($conn, $cid, $supplierId, $dateFrom, $dateTo);
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
            'balance' => $r['balance'],
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
    'balance' => 'Net Payable',
], 'construction_supplier_statement_' . $supplierId . '_' . $dateFrom . '_' . $dateTo, 'Construction Supplier Statement');

$pageTitle = 'Supplier Statement';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<style>
@media print {
  .no-print { display: none !important; }
  .sidebar, .navbar, .construction-sidebar { display: none !important; }
  .main-content, .content-area { margin: 0 !important; padding: 0 !important; }
  .card { border: none !important; box-shadow: none !important; }
}
.stmt-print-header { display: none; }
@media print { .stmt-print-header { display: block; margin-bottom: 1rem; } }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Supplier Statement</h1>
        <p class="text-muted mb-0"><?= $supplier ? h($supplier['supplier_name']) : 'Choose a supplier' ?> · Full lifecycle (invoices, payments, advances, VAT, refunds)</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($supplier): ?>
        <?= co_report_export_buttons() ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-4">
        <label class="form-label">Supplier</label>
        <select name="supplier_id" class="form-select" required>
            <option value="0">Select supplier</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $supplierId === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['supplier_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Generate</button></div>
</div></form>

<?php if (!$supplier || !$statement): ?>
<div class="card card-round"><div class="card-body text-center text-muted py-4">Select a supplier to generate the statement.</div></div>
<?php else: ?>
<div class="stmt-print-header">
    <h2 class="h5 mb-1"><?= h($brand['company_name'] ?? 'HeroSysgro') ?></h2>
    <div class="fw-semibold">Supplier Statement — <?= h($supplier['supplier_name']) ?></div>
    <div class="small text-muted">Period <?= h($dateFrom) ?> to <?= h($dateTo) ?></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card card-round"><div class="card-body py-2">
        <div class="small text-muted">Opening Net Payable</div>
        <div class="fw-semibold"><?= co_format_money($statement['opening']) ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body py-2">
        <div class="small text-muted">Opening AP / Advance</div>
        <div class="fw-semibold"><?= co_format_money($statement['opening_ap']) ?> / <?= co_format_money($statement['opening_advance']) ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body py-2">
        <div class="small text-muted">Closing Net Payable</div>
        <div class="fw-semibold"><?= co_format_money($statement['closing']) ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body py-2">
        <div class="small text-muted">Closing AP / Advance</div>
        <div class="fw-semibold"><?= co_format_money($statement['closing_ap']) ?> / <?= co_format_money($statement['closing_advance']) ?></div>
    </div></div></div>
</div>

<div class="card card-round"><div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0">
        <thead class="table-light">
            <tr>
                <th>Date</th><th>Type</th><th>Reference</th><th>Description</th>
                <th class="text-end">Debit</th><th class="text-end">Credit</th>
                <th class="text-end">AP Bal</th><th class="text-end">Advance Bal</th><th class="text-end">Net Payable</th>
            </tr>
        </thead>
        <tbody>
            <tr class="table-light">
                <td><?= h($dateFrom) ?></td>
                <td>Opening</td>
                <td></td>
                <td>Opening balances</td>
                <td></td><td></td>
                <td class="text-end"><?= co_format_money($statement['opening_ap']) ?></td>
                <td class="text-end"><?= co_format_money($statement['opening_advance']) ?></td>
                <td class="text-end fw-semibold"><?= co_format_money($statement['opening']) ?></td>
            </tr>
            <?php foreach ($statement['rows'] as $row): ?>
            <tr>
                <td><?= h($row['txn_date']) ?></td>
                <td><?= h($row['txn_type']) ?></td>
                <td>
                    <?php if (!empty($row['link'])): ?>
                    <a class="no-print" href="../<?= h($row['link']) ?>"><?= h($row['reference']) ?></a>
                    <span class="d-none d-print-inline"><?= h($row['reference']) ?></span>
                    <?php else: ?>
                    <?= h($row['reference']) ?>
                    <?php endif; ?>
                </td>
                <td><?= h($row['description']) ?></td>
                <td class="text-end"><?= (float)$row['debit'] > 0.005 ? co_format_money($row['debit']) : '—' ?></td>
                <td class="text-end"><?= (float)$row['credit'] > 0.005 ? co_format_money($row['credit']) : '—' ?></td>
                <td class="text-end"><?= co_format_money($row['ap_balance']) ?></td>
                <td class="text-end"><?= co_format_money($row['advance_balance']) ?></td>
                <td class="text-end fw-semibold"><?= co_format_money($row['balance']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="table-light fw-semibold">
                <td><?= h($dateTo) ?></td>
                <td>Closing</td>
                <td></td>
                <td>Closing balances</td>
                <td></td><td></td>
                <td class="text-end"><?= co_format_money($statement['closing_ap']) ?></td>
                <td class="text-end"><?= co_format_money($statement['closing_advance']) ?></td>
                <td class="text-end"><?= co_format_money($statement['closing']) ?></td>
            </tr>
        </tbody>
    </table>
</div></div>
<p class="small text-muted mt-2 no-print">Net Payable = Outstanding AP − Supplier Advance Balance. Advance VAT and refunds reduce the advance balance (increasing net payable when advances fall).</p>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
