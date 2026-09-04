<?php
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$stmt = $conn->prepare("
    SELECT COALESCE(i.source_type, 'construction_project') AS source_type,
           COUNT(*) AS invoice_count,
           SUM(i.subtotal) AS subtotal,
           SUM(i.vat_amount) AS vat_amount,
           SUM(i.total_amount) AS total_amount,
           COALESCE(SUM(a.paid_amount), 0) AS paid_amount,
           SUM(i.total_amount) - COALESCE(SUM(a.paid_amount), 0) AS balance_due
    FROM co_client_invoices i
    LEFT JOIN (
        SELECT invoice_id, SUM(allocated_amount) AS paid_amount
        FROM co_client_payment_allocations
        GROUP BY invoice_id
    ) a ON a.invoice_id = i.id
    WHERE i.company_id = ?
      AND i.status <> 'cancelled'
      AND i.invoice_date BETWEEN ? AND ?
    GROUP BY COALESCE(i.source_type, 'construction_project')
    ORDER BY source_type
");
$stmt->execute([$cid, $dateFrom, $dateTo]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row) $row['category'] = co_income_source_label($row['source_type']);
unset($row);
co_report_export($rows, ['category' => 'Category', 'invoice_count' => 'Invoices', 'subtotal' => 'Subtotal', 'vat_amount' => 'VAT', 'total_amount' => 'Total', 'paid_amount' => 'Paid', 'balance_due' => 'Balance'], 'construction_income_by_category_' . $dateFrom . '_' . $dateTo, 'Construction Income by Category');
$pageTitle = 'Income by Category';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print"><div><h1 class="h4 mb-0">Income by Category</h1><p class="text-muted mb-0"><?= h($dateFrom) ?> to <?= h($dateTo) ?></p></div><div class="d-flex gap-2"><?= co_report_export_buttons() ?></div></div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end"><div class="col-md-4"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div><div class="col-md-4"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div><div class="col-md-4"><button class="btn btn-primary">Generate</button></div></div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Category</th><th>Invoices</th><th class="text-end">Subtotal</th><th class="text-end">VAT</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead><tbody>
<?php foreach ($rows as $row):
    $detailHref = 'income_by_category_detail.php?' . http_build_query(['source_type' => $row['source_type'], 'date_from' => $dateFrom, 'date_to' => $dateTo]);
?><tr class="report-row-link" data-href="<?= h($detailHref) ?>"><td><?= h($row['category']) ?></td><td><?= (int)$row['invoice_count'] ?></td><td class="text-end"><?= co_format_money($row['subtotal']) ?></td><td class="text-end"><?= co_format_money($row['vat_amount']) ?></td><td class="text-end"><?= co_format_money($row['total_amount']) ?></td><td class="text-end"><?= co_format_money($row['paid_amount']) ?></td><td class="text-end"><?= co_format_money($row['balance_due']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No income invoices found.</td></tr><?php endif; ?>
</tbody></table></div></div>
<script>
document.querySelectorAll('tr.report-row-link').forEach(function (tr) {
    tr.style.cursor = 'pointer';
    tr.addEventListener('click', function () { window.location.href = tr.dataset.href; });
});
</script>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
