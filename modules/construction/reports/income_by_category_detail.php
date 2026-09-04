<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_income_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$sourceType = trim((string)($_GET['source_type'] ?? ''));

$categoryOptions = co_income_source_options();

$rows = [];
if ($sourceType !== '') {
    $stmt = $conn->prepare("
        SELECT i.id, i.invoice_number, i.invoice_date, i.status, i.description,
               i.subtotal, i.vat_amount, i.total_amount,
               COALESCE(a.paid_amount, 0) AS paid_amount,
               i.total_amount - COALESCE(a.paid_amount, 0) AS balance_due,
               c.client_name, p.project_code, p.project_name
        FROM co_client_invoices i
        JOIN co_clients c ON c.id = i.client_id
        LEFT JOIN co_projects p ON p.id = i.project_id
        LEFT JOIN (
            SELECT invoice_id, SUM(allocated_amount) AS paid_amount
            FROM co_client_payment_allocations
            GROUP BY invoice_id
        ) a ON a.invoice_id = i.id
        WHERE i.company_id = ?
          AND i.status <> 'cancelled'
          AND i.invoice_date BETWEEN ? AND ?
          AND COALESCE(i.source_type, 'construction_project') = ?
        ORDER BY i.invoice_date, i.invoice_number
    ");
    $stmt->execute([$cid, $dateFrom, $dateTo, $sourceType]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalSubtotal = array_sum(array_map(fn($r) => (float)$r['subtotal'], $rows));
$totalVat = array_sum(array_map(fn($r) => (float)$r['vat_amount'], $rows));
$totalAmount = array_sum(array_map(fn($r) => (float)$r['total_amount'], $rows));
$totalPaid = array_sum(array_map(fn($r) => (float)$r['paid_amount'], $rows));
$totalBalance = array_sum(array_map(fn($r) => (float)$r['balance_due'], $rows));

co_report_export($rows, [
    'invoice_number' => 'Invoice #',
    'invoice_date' => 'Date',
    'client_name' => 'Client/Tenant',
    'project_name' => 'Project',
    'status' => 'Status',
    'subtotal' => 'Subtotal',
    'vat_amount' => 'VAT',
    'total_amount' => 'Total',
    'paid_amount' => 'Paid',
    'balance_due' => 'Balance',
], 'construction_income_by_category_detail_' . $sourceType . '_' . $dateFrom . '_' . $dateTo, 'Construction Income by Category Detail');

$pageTitle = 'Income by Category — Detail';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Income by Category — Detail</h1>
        <p class="text-muted mb-0"><?= $sourceType !== '' ? h(co_income_source_label($sourceType)) : 'Select a category' ?> · <?= h($dateFrom) ?> to <?= h($dateTo) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="income_by_category.php?date_from=<?= h(urlencode($dateFrom)) ?>&date_to=<?= h(urlencode($dateTo)) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back to Summary</a>
        <?= $sourceType !== '' ? co_report_export_buttons() : '' ?>
    </div>
</div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-4"><label class="form-label">Category</label><select name="source_type" class="form-select"><option value="">Select category</option><?php foreach ($categoryOptions as $key => $label): ?><option value="<?= h($key) ?>" <?= $sourceType === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Generate</button></div>
</div></form>
<?php if ($sourceType === ''): ?>
<div class="card card-round"><div class="card-body text-center text-muted py-4">Select a category to view invoice detail.</div></div>
<?php else: ?>
<div class="card card-round"><div class="card-body p-0 table-responsive">
    <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>Invoice #</th><th>Date</th><th>Client/Tenant</th><th>Project</th><th>Status</th><th class="text-end">Subtotal</th><th class="text-end">VAT</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr class="report-row-link" data-href="../client_invoice_pdf.php?id=<?= (int)$row['id'] ?>">
                <td><strong><?= h($row['invoice_number']) ?></strong></td>
                <td><?= $row['invoice_date'] ? date('Y-m-d', strtotime($row['invoice_date'])) : '-' ?></td>
                <td><?= h($row['client_name']) ?></td>
                <td><?= $row['project_name'] ? h(($row['project_code'] ? $row['project_code'] . ' — ' : '') . $row['project_name']) : '-' ?></td>
                <td><span class="badge bg-<?= co_invoice_status_badge($row['status']) ?>"><?= h($row['status']) ?></span></td>
                <td class="text-end"><?= co_format_money($row['subtotal']) ?></td>
                <td class="text-end"><?= co_format_money($row['vat_amount']) ?></td>
                <td class="text-end"><?= co_format_money($row['total_amount']) ?></td>
                <td class="text-end"><?= co_format_money($row['paid_amount']) ?></td>
                <td class="text-end"><?= co_format_money($row['balance_due']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No income invoices found for this category.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot class="table-light"><tr>
            <th colspan="5" class="text-end">Total</th>
            <th class="text-end"><?= co_format_money($totalSubtotal) ?></th>
            <th class="text-end"><?= co_format_money($totalVat) ?></th>
            <th class="text-end"><?= co_format_money($totalAmount) ?></th>
            <th class="text-end"><?= co_format_money($totalPaid) ?></th>
            <th class="text-end"><?= co_format_money($totalBalance) ?></th>
        </tr></tfoot>
        <?php endif; ?>
    </table>
</div></div>
<?php endif; ?>
<script>
document.querySelectorAll('tr.report-row-link').forEach(function (tr) {
    tr.style.cursor = 'pointer';
    tr.addEventListener('click', function () {
        window.open(tr.dataset.href, '_blank');
    });
});
</script>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
