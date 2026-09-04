<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = co_shop_require_company_id($conn);
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$rows = [];
if (co_db_table_exists($conn, 'co_shop_units') && co_db_table_exists($conn, 'co_client_invoices')) {
    // Contract-level rent attributed to primary shop for portfolio view (not multiplied)
    $stmt = $conn->prepare("
        SELECT u.shop_number, u.shop_name, u.status,
               COUNT(DISTINCT c.id) AS contracts,
               COALESCE(SUM(CASE WHEN i.id IS NOT NULL THEN i.total_amount ELSE 0 END), 0) AS invoiced,
               COALESCE(SUM(CASE WHEN i.id IS NOT NULL THEN COALESCE(a.paid_amount, 0) ELSE 0 END), 0) AS collected
        FROM co_shop_units u
        LEFT JOIN co_shop_rental_contracts c ON c.shop_unit_id = u.id AND c.company_id = u.company_id
        LEFT JOIN co_shop_rent_schedules s ON s.contract_id = c.id AND s.company_id = c.company_id
        LEFT JOIN co_client_invoices i ON i.source_type = 'shop_rental' AND i.source_id = s.id AND i.company_id = c.company_id
             AND i.status <> 'cancelled' AND i.invoice_date BETWEEN ? AND ?
        LEFT JOIN (
            SELECT invoice_id, company_id, SUM(allocated_amount) AS paid_amount
            FROM co_client_payment_allocations GROUP BY invoice_id, company_id
        ) a ON a.invoice_id = i.id AND a.company_id = i.company_id
        WHERE u.company_id = ?
        GROUP BY u.id, u.shop_number, u.shop_name, u.status
        ORDER BY invoiced DESC, u.shop_number
    ");
    $stmt->execute([$dateFrom, $dateTo, $cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['outstanding'] = max(0, (float)$r['invoiced'] - (float)$r['collected']);
    }
    unset($r);
}
co_report_export($rows, [
    'shop_number' => 'Shop', 'shop_name' => 'Name', 'status' => 'Status', 'contracts' => 'Contracts',
    'invoiced' => 'Invoiced', 'collected' => 'Collected', 'outstanding' => 'Outstanding',
], 'shop_profitability_' . $dateFrom . '_' . $dateTo, 'Shop Profitability');
$pageTitle = 'Shop Profitability';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Shop Profitability</h1>
        <p class="text-muted mb-0">Rent invoiced/collected attributed via primary shop · <?= h($dateFrom) ?> to <?= h($dateTo) ?></p></div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
    <div class="col-md-3"><button class="btn btn-primary">Generate</button></div>
</div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-hover mb-0"><thead class="table-light"><tr>
<th>Shop</th><th>Status</th><th class="text-end">Contracts</th><th class="text-end">Invoiced</th><th class="text-end">Collected</th><th class="text-end">Outstanding</th>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= h($row['shop_number'] . ($row['shop_name'] ? ' — '.$row['shop_name'] : '')) ?></td>
<td><?= h($row['status']) ?></td>
<td class="text-end"><?= (int)$row['contracts'] ?></td>
<td class="text-end"><?= co_format_money($row['invoiced']) ?></td>
<td class="text-end"><?= co_format_money($row['collected']) ?></td>
<td class="text-end"><?= co_format_money($row['outstanding']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No data.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
