<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_advance_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];
$asOfDate = !empty($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');

$allocationJoin = co_supplier_allocations_ready($conn) ? "
    LEFT JOIN (
        SELECT invoice_id, SUM(allocated_amount) AS paid_amount
        FROM co_supplier_payment_allocations
        GROUP BY invoice_id
    ) a ON a.invoice_id = si.id
" : "";
$paidExpr = co_supplier_allocations_ready($conn) ? "COALESCE(a.paid_amount, 0)" : "0";
if (function_exists('co_supplier_advance_schema_ready') && co_supplier_advance_schema_ready($conn)) {
    $allocationJoin .= "
    LEFT JOIN (
        SELECT supplier_invoice_id, SUM(amount) AS advance_applied
        FROM co_supplier_advance_applications
        WHERE status = 'posted'
        GROUP BY supplier_invoice_id
    ) adv ON adv.supplier_invoice_id = si.id
    ";
    $paidExpr = "({$paidExpr} + COALESCE(adv.advance_applied, 0))";
}
$linkedVatExpr = '0';
if (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn)) {
    $allocationJoin .= "
    LEFT JOIN (
        SELECT supplier_invoice_id, SUM(vat_amount_linked) AS linked_vat
        FROM co_supplier_advance_vat_invoice_links
        WHERE status = 'posted'
        GROUP BY supplier_invoice_id
    ) vlink ON vlink.supplier_invoice_id = si.id
    ";
    $linkedVatExpr = 'COALESCE(vlink.linked_vat, 0)';
}
$statusFilter = function_exists('co_supplier_invoice_open_status_sql')
    ? co_supplier_invoice_open_status_sql($conn, 'si')
    : '';

$stmt = $conn->prepare("
    SELECT s.supplier_name, si.invoice_number, si.invoice_date, si.due_date, si.total,
           {$paidExpr} AS paid_amount,
           GREATEST(si.total - {$linkedVatExpr} - {$paidExpr}, 0) AS balance_due,
           DATEDIFF(?, COALESCE(si.due_date, si.invoice_date)) AS days_past_due
    FROM co_supplier_invoices si
    JOIN co_suppliers s ON s.id = si.supplier_id
    {$allocationJoin}
    WHERE si.company_id = ?
      AND si.journal_id IS NOT NULL
      {$statusFilter}
    HAVING balance_due > 0.005
    ORDER BY COALESCE(si.due_date, si.invoice_date) ASC, s.supplier_name ASC
");
$stmt->execute([$asOfDate, $cid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $days = (int)$row['days_past_due'];
    $row['current_bucket'] = $days <= 0 ? $row['balance_due'] : 0;
    $row['bucket_1_30'] = $days >= 1 && $days <= 30 ? $row['balance_due'] : 0;
    $row['bucket_31_60'] = $days >= 31 && $days <= 60 ? $row['balance_due'] : 0;
    $row['bucket_61_90'] = $days >= 61 && $days <= 90 ? $row['balance_due'] : 0;
    $row['bucket_90_plus'] = $days > 90 ? $row['balance_due'] : 0;
}
unset($row);

co_report_export($rows, [
    'supplier_name' => 'Supplier',
    'invoice_number' => 'Invoice #',
    'invoice_date' => 'Invoice Date',
    'due_date' => 'Due Date',
    'balance_due' => 'Balance Due',
    'current_bucket' => 'Current',
    'bucket_1_30' => '1-30',
    'bucket_31_60' => '31-60',
    'bucket_61_90' => '61-90',
    'bucket_90_plus' => '90+',
], 'construction_supplier_aging_' . $asOfDate, 'Construction Supplier Aging');

$totals = ['balance_due' => 0, 'current_bucket' => 0, 'bucket_1_30' => 0, 'bucket_31_60' => 0, 'bucket_61_90' => 0, 'bucket_90_plus' => 0];
foreach ($rows as $row) foreach ($totals as $key => $_) $totals[$key] += (float)$row[$key];

$companyAdvanceTotal = 0.0;
$companyNetPayable = 0.0;
if (function_exists('co_supplier_advance_schema_ready') && co_supplier_advance_schema_ready($conn)) {
    $st = $conn->prepare("SELECT COALESCE(SUM(balance_aed), 0) FROM co_supplier_advance_balances WHERE company_id = ?");
    $st->execute([$cid]);
    $companyAdvanceTotal = (float)$st->fetchColumn();
}
$companyGrossAp = (float)$totals['balance_due'];
$companyNetPayable = round($companyGrossAp - $companyAdvanceTotal, 2);

$pageTitle = 'Construction Supplier Aging';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Supplier Aging</h1><p class="text-muted mb-0">Outstanding <strong>posted</strong> supplier invoices as of <?= h($asOfDate) ?> (drafts and voided excluded). Aging buckets are gross AP.</p></div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<div class="row g-3 mb-4 no-print">
    <div class="col-md-4"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Gross AP</div><div class="h5 mb-0"><?= co_format_money($companyGrossAp) ?></div></div></div></div>
    <div class="col-md-4"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Supplier Advances</div><div class="h5 mb-0"><?= co_format_money($companyAdvanceTotal) ?></div></div></div></div>
    <div class="col-md-4"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Net Payable</div><div class="h5 mb-0"><?= co_format_money($companyNetPayable) ?></div></div></div></div>
</div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-4"><label class="form-label">As of Date</label><input type="date" name="as_of_date" class="form-control" value="<?= h($asOfDate) ?>"></div>
    <div class="col-md-4"><button class="btn btn-primary">Generate</button></div>
</div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Supplier</th><th>Invoice #</th><th>Invoice Date</th><th>Due Date</th><th class="text-end">Balance</th><th class="text-end">Current</th><th class="text-end">1-30</th><th class="text-end">31-60</th><th class="text-end">61-90</th><th class="text-end">90+</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr><td><?= h($row['supplier_name']) ?></td><td><?= h($row['invoice_number']) ?></td><td><?= h($row['invoice_date']) ?></td><td><?= h($row['due_date'] ?: '-') ?></td><td class="text-end"><?= co_format_money($row['balance_due']) ?></td><td class="text-end"><?= co_format_money($row['current_bucket']) ?></td><td class="text-end"><?= co_format_money($row['bucket_1_30']) ?></td><td class="text-end"><?= co_format_money($row['bucket_31_60']) ?></td><td class="text-end"><?= co_format_money($row['bucket_61_90']) ?></td><td class="text-end"><?= co_format_money($row['bucket_90_plus']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No open supplier invoices.</td></tr><?php endif; ?>
        </tbody>
        <tfoot class="table-light"><tr><th colspan="4" class="text-end">Totals</th><th class="text-end"><?= co_format_money($totals['balance_due']) ?></th><th class="text-end"><?= co_format_money($totals['current_bucket']) ?></th><th class="text-end"><?= co_format_money($totals['bucket_1_30']) ?></th><th class="text-end"><?= co_format_money($totals['bucket_31_60']) ?></th><th class="text-end"><?= co_format_money($totals['bucket_61_90']) ?></th><th class="text-end"><?= co_format_money($totals['bucket_90_plus']) ?></th></tr></tfoot>
    </table>
</div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
