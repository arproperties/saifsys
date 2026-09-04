<?php
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];
$asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');
$stmt = $conn->prepare("
    SELECT i.invoice_number, i.invoice_date, i.due_date, COALESCE(i.source_type, 'construction_project') AS source_type,
           c.client_name, i.total_amount, COALESCE(a.paid_amount, 0) AS paid_amount,
           GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0) AS balance_due,
           DATEDIFF(?, COALESCE(i.due_date, i.invoice_date)) AS days_past_due
    FROM co_client_invoices i
    JOIN co_clients c ON c.id = i.client_id
    LEFT JOIN (SELECT invoice_id, SUM(allocated_amount) AS paid_amount FROM co_client_payment_allocations GROUP BY invoice_id) a ON a.invoice_id = i.id
    WHERE i.company_id = ? AND i.status <> 'cancelled'
    HAVING balance_due > 0.005
    ORDER BY COALESCE(i.due_date, i.invoice_date), c.client_name
");
$stmt->execute([$asOfDate, $cid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row) {
    $days = (int)$row['days_past_due'];
    $row['category'] = co_income_source_label($row['source_type']);
    $row['current_bucket'] = $days <= 0 ? $row['balance_due'] : 0;
    $row['bucket_1_30'] = $days >= 1 && $days <= 30 ? $row['balance_due'] : 0;
    $row['bucket_31_60'] = $days >= 31 && $days <= 60 ? $row['balance_due'] : 0;
    $row['bucket_61_90'] = $days >= 61 && $days <= 90 ? $row['balance_due'] : 0;
    $row['bucket_90_plus'] = $days > 90 ? $row['balance_due'] : 0;
}
unset($row);
co_report_export($rows, ['client_name' => 'Customer', 'invoice_number' => 'Invoice', 'category' => 'Category', 'due_date' => 'Due Date', 'balance_due' => 'Balance', 'current_bucket' => 'Current', 'bucket_1_30' => '1-30', 'bucket_31_60' => '31-60', 'bucket_61_90' => '61-90', 'bucket_90_plus' => '90+'], 'construction_receivables_aging_' . $asOfDate, 'Construction Receivables Aging');
$pageTitle = 'Receivables Aging';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print"><div><h1 class="h4 mb-0">Receivables Aging</h1><p class="text-muted mb-0">Open customer/tenant receivables as of <?= h($asOfDate) ?>.</p></div><div class="d-flex gap-2"><?= co_report_export_buttons() ?></div></div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end"><div class="col-md-4"><label class="form-label">As of Date</label><input type="date" name="as_of_date" class="form-control" value="<?= h($asOfDate) ?>"></div><div class="col-md-4"><button class="btn btn-primary">Generate</button></div></div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Customer</th><th>Invoice</th><th>Category</th><th>Due</th><th class="text-end">Balance</th><th class="text-end">Current</th><th class="text-end">1-30</th><th class="text-end">31-60</th><th class="text-end">61-90</th><th class="text-end">90+</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?= h($row['client_name']) ?></td><td><?= h($row['invoice_number']) ?></td><td><?= h($row['category']) ?></td><td><?= h($row['due_date'] ?: $row['invoice_date']) ?></td><td class="text-end"><?= co_format_money($row['balance_due']) ?></td><td class="text-end"><?= co_format_money($row['current_bucket']) ?></td><td class="text-end"><?= co_format_money($row['bucket_1_30']) ?></td><td class="text-end"><?= co_format_money($row['bucket_31_60']) ?></td><td class="text-end"><?= co_format_money($row['bucket_61_90']) ?></td><td class="text-end"><?= co_format_money($row['bucket_90_plus']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No outstanding receivables.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
