<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = co_shop_require_company_id($conn);
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$rows = [];
if (co_db_table_exists($conn, 'co_client_invoices')) {
    $stmt = $conn->prepare("
        SELECT i.invoice_number, i.invoice_date, c.contract_number, cl.client_name,
               i.subtotal, i.vat_amount, i.total_amount,
               COALESCE(a.paid_amount, 0) AS collected,
               GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0) AS outstanding,
               i.status, i.journal_id
        FROM co_client_invoices i
        JOIN co_clients cl ON cl.id = i.client_id
        LEFT JOIN co_shop_rental_contracts c ON c.id = i.source_id AND c.company_id = i.company_id
        LEFT JOIN (
            SELECT invoice_id, company_id, SUM(allocated_amount) AS paid_amount
            FROM co_client_payment_allocations GROUP BY invoice_id, company_id
        ) a ON a.invoice_id = i.id AND a.company_id = i.company_id
        WHERE i.company_id = ? AND i.source_type = 'shop_termination_penalty' AND i.status <> 'cancelled'
          AND i.invoice_date BETWEEN ? AND ?
        ORDER BY i.invoice_date DESC, i.id DESC
    ");
    $stmt->execute([$cid, $dateFrom, $dateTo]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
co_report_export($rows, [
    'invoice_number' => 'Invoice', 'invoice_date' => 'Date', 'contract_number' => 'Contract',
    'client_name' => 'Tenant', 'subtotal' => 'Net', 'total_amount' => 'Gross',
    'collected' => 'Collected', 'outstanding' => 'Outstanding', 'status' => 'Status',
], 'shop_termination_penalty_' . $dateFrom . '_' . $dateTo, 'Early Termination Penalty Income');
$pageTitle = 'Early Termination Penalty Income';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Early Termination Penalty Income</h1>
        <p class="text-muted mb-0">Income account 4150 · <?= h($dateFrom) ?> to <?= h($dateTo) ?></p></div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
    <div class="col-md-3"><button class="btn btn-primary">Generate</button></div>
</div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-hover mb-0"><thead class="table-light"><tr>
<th>Invoice</th><th>Date</th><th>Contract</th><th>Tenant</th><th class="text-end">Net</th><th class="text-end">Gross</th><th class="text-end">Collected</th><th class="text-end">Outstanding</th><th>Status</th>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= h($row['invoice_number']) ?></td><td><?= h($row['invoice_date']) ?></td>
<td><?= h($row['contract_number'] ?? '') ?></td><td><?= h($row['client_name']) ?></td>
<td class="text-end"><?= co_format_money($row['subtotal']) ?></td>
<td class="text-end"><?= co_format_money($row['total_amount']) ?></td>
<td class="text-end"><?= co_format_money($row['collected']) ?></td>
<td class="text-end"><?= co_format_money($row['outstanding']) ?></td>
<td><?= h($row['status']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No penalty invoices in period.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
