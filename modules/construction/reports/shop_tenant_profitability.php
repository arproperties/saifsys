<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = co_shop_require_company_id($conn);
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$rows = [];
if (co_db_table_exists($conn, 'co_clients')) {
    $stmt = $conn->prepare("
        SELECT cl.client_name,
               COUNT(DISTINCT c.id) AS contracts,
               COALESCE(SUM(CASE WHEN i.source_type = 'shop_rental' THEN i.total_amount ELSE 0 END), 0) AS rent_invoiced,
               COALESCE(SUM(CASE WHEN i.source_type = 'shop_commission' THEN i.total_amount ELSE 0 END), 0) AS commission_invoiced,
               COALESCE(SUM(CASE WHEN i.source_type = 'shop_charge' THEN i.total_amount ELSE 0 END), 0) AS charge_invoiced,
               COALESCE(SUM(CASE WHEN i.source_type = 'shop_termination_penalty' THEN i.total_amount ELSE 0 END), 0) AS penalty_invoiced,
               COALESCE(SUM(COALESCE(a.paid_amount, 0)), 0) AS collected,
               COALESCE(SUM(GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0)), 0) AS outstanding
        FROM co_clients cl
        JOIN co_shop_rental_contracts c ON c.client_id = cl.id AND c.company_id = cl.company_id
        LEFT JOIN co_client_invoices i ON i.client_id = cl.id AND i.company_id = cl.company_id
             AND i.status <> 'cancelled'
             AND i.source_type IN ('shop_rental','shop_commission','shop_termination_penalty','shop_charge')
             AND i.invoice_date BETWEEN ? AND ?
             AND (
               (i.source_type = 'shop_rental' AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE contract_id = c.id AND company_id = c.company_id))
               OR (i.source_type IN ('shop_commission','shop_termination_penalty','shop_charge') AND i.source_id = c.id)
             )
        LEFT JOIN (
            SELECT invoice_id, company_id, SUM(allocated_amount) AS paid_amount
            FROM co_client_payment_allocations GROUP BY invoice_id, company_id
        ) a ON a.invoice_id = i.id AND a.company_id = i.company_id
        WHERE cl.company_id = ?
        GROUP BY cl.id, cl.client_name
        HAVING contracts > 0
        ORDER BY outstanding DESC, rent_invoiced DESC
    ");
    $stmt->execute([$dateFrom, $dateTo, $cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
co_report_export($rows, [
    'client_name' => 'Tenant', 'contracts' => 'Contracts',
    'rent_invoiced' => 'Rent', 'commission_invoiced' => 'Commission', 'charge_invoiced' => 'Charges/Key Money',
    'penalty_invoiced' => 'Penalty',
    'collected' => 'Collected', 'outstanding' => 'Outstanding',
], 'shop_tenant_profitability_' . $dateFrom . '_' . $dateTo, 'Tenant Profitability');
$pageTitle = 'Tenant Profitability';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Tenant Profitability</h1><p class="text-muted mb-0"><?= h($dateFrom) ?> to <?= h($dateTo) ?></p></div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
    <div class="col-md-3"><button class="btn btn-primary">Generate</button></div>
</div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-hover mb-0"><thead class="table-light"><tr>
<th>Tenant</th><th class="text-end">Contracts</th><th class="text-end">Rent</th><th class="text-end">Commission</th><th class="text-end">Charges/Key Money</th><th class="text-end">Penalty</th><th class="text-end">Collected</th><th class="text-end">Outstanding</th>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= h($row['client_name']) ?></td>
<td class="text-end"><?= (int)$row['contracts'] ?></td>
<td class="text-end"><?= co_format_money($row['rent_invoiced']) ?></td>
<td class="text-end"><?= co_format_money($row['commission_invoiced']) ?></td>
<td class="text-end"><?= co_format_money($row['charge_invoiced'] ?? 0) ?></td>
<td class="text-end"><?= co_format_money($row['penalty_invoiced']) ?></td>
<td class="text-end"><?= co_format_money($row['collected']) ?></td>
<td class="text-end"><?= co_format_money($row['outstanding']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No tenants.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
