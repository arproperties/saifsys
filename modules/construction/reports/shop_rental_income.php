<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$stmt = $conn->prepare("
    SELECT i.invoice_number, i.invoice_date, i.due_date, c.client_name,
           COALESCE(" . co_shop_sql_shops_label('rc') . ", u.shop_number) AS shop_number,
           i.total_amount, COALESCE(a.paid_amount, 0) AS paid_amount,
           i.total_amount - COALESCE(a.paid_amount, 0) AS balance_due
    FROM co_client_invoices i
    JOIN co_clients c ON c.id = i.client_id
    LEFT JOIN co_shop_rent_schedules s ON s.id = i.source_id AND i.source_type = 'shop_rental'
    LEFT JOIN co_shop_rental_contracts rc ON rc.id = s.contract_id
    LEFT JOIN co_shop_units u ON u.id = rc.shop_unit_id
    LEFT JOIN (SELECT invoice_id, SUM(allocated_amount) AS paid_amount FROM co_client_payment_allocations GROUP BY invoice_id) a ON a.invoice_id = i.id
    WHERE i.company_id = ? AND i.source_type = 'shop_rental' AND i.status <> 'cancelled' AND i.invoice_date BETWEEN ? AND ?
    ORDER BY i.invoice_date DESC
");
$stmt->execute([$cid, $dateFrom, $dateTo]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
co_report_export($rows, ['invoice_number' => 'Invoice', 'invoice_date' => 'Date', 'client_name' => 'Tenant', 'shop_number' => 'Shop', 'total_amount' => 'Total', 'paid_amount' => 'Paid', 'balance_due' => 'Balance'], 'construction_shop_rental_income_' . $dateFrom . '_' . $dateTo, 'Construction Shop Rental Income');
$pageTitle = 'Shop Rental Income';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print"><div><h1 class="h4 mb-0">Shop Rental Income</h1><p class="text-muted mb-0"><?= h($dateFrom) ?> to <?= h($dateTo) ?></p></div><div class="d-flex gap-2"><?= co_report_export_buttons() ?></div></div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end"><div class="col-md-4"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div><div class="col-md-4"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div><div class="col-md-4"><button class="btn btn-primary">Generate</button></div></div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Invoice</th><th>Date</th><th>Tenant</th><th>Shop</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?= h($row['invoice_number']) ?></td><td><?= h($row['invoice_date']) ?></td><td><?= h($row['client_name']) ?></td><td><?= h($row['shop_number'] ?: '-') ?></td><td class="text-end"><?= co_format_money($row['total_amount']) ?></td><td class="text-end"><?= co_format_money($row['paid_amount']) ?></td><td class="text-end"><?= co_format_money($row['balance_due']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No shop rental income invoices found.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
