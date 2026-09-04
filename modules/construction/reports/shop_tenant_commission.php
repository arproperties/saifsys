<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = co_shop_require_company_id($conn);
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$rows = [];
if (co_shop_commission_schema_ready($conn)) {
    $shopsExpr = co_shop_phase175_schema_ready($conn)
        ? ('COALESCE(' . co_shop_sql_shops_label('c') . ', u.shop_number)')
        : 'u.shop_number';
    $stmt = $conn->prepare("
        SELECT c.contract_number, cl.client_name, {$shopsExpr} AS shops,
               c.rent_amount, c.commission_basis, c.commission_percent, c.commission_net_amount,
               c.commission_vat_rate, c.commission_vat_enabled,
               i.invoice_number, i.invoice_date, i.subtotal AS commission_net,
               i.vat_amount, i.total_amount,
               COALESCE(a.paid_amount, 0) AS collected,
               GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0) AS outstanding,
               i.status AS invoice_status, i.journal_id
        FROM co_shop_rental_contracts c
        JOIN co_clients cl ON cl.id = c.client_id
        JOIN co_shop_units u ON u.id = c.shop_unit_id
        LEFT JOIN co_client_invoices i
               ON i.id = c.commission_invoice_id AND i.company_id = c.company_id AND i.status <> 'cancelled'
        LEFT JOIN (
            SELECT invoice_id, company_id, SUM(allocated_amount) AS paid_amount
            FROM co_client_payment_allocations
            GROUP BY invoice_id, company_id
        ) a ON a.invoice_id = i.id AND a.company_id = i.company_id
        WHERE c.company_id = ?
          AND c.commission_enabled = 1
          AND (
                i.id IS NULL
                OR i.invoice_date BETWEEN ? AND ?
              )
        ORDER BY COALESCE(i.invoice_date, c.start_date) DESC, c.id DESC
    ");
    $stmt->execute([$cid, $dateFrom, $dateTo]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

co_report_export($rows, [
    'contract_number' => 'Contract',
    'client_name' => 'Tenant',
    'shops' => 'Shops',
    'rent_amount' => 'Contract Rent Net',
    'commission_basis' => 'Basis',
    'commission_percent' => 'Percent',
    'invoice_number' => 'Invoice',
    'invoice_date' => 'Invoice Date',
    'commission_net' => 'Commission Net',
    'vat_amount' => 'VAT',
    'total_amount' => 'Gross',
    'collected' => 'Collected',
    'outstanding' => 'Outstanding',
    'invoice_status' => 'Status',
], 'construction_shop_tenant_commission_' . $dateFrom . '_' . $dateTo, 'Shop Tenant Commission');

$pageTitle = 'Shop Tenant Commission';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Shop Tenant Commission</h1>
        <p class="text-muted mb-0">Madar Al Wadi commission charged to tenants · <?= h($dateFrom) ?> to <?= h($dateTo) ?></p>
    </div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<?php if (!co_shop_commission_schema_ready($conn)): ?>
<div class="alert alert-warning">Run <code>migrations/construction_shop_rental_phase16_commission.sql</code>.</div>
<?php endif; ?>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-4"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
    <div class="col-md-4"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
    <div class="col-md-4"><button class="btn btn-primary">Generate</button></div>
</div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-hover mb-0 align-middle">
    <thead class="table-light">
        <tr>
            <th>Contract</th><th>Tenant</th><th>Shops</th><th class="text-end">Rent Net</th>
            <th>Basis</th><th>Invoice</th><th class="text-end">Comm Net</th><th class="text-end">VAT</th>
            <th class="text-end">Gross</th><th class="text-end">Collected</th><th class="text-end">Outstanding</th><th>Status</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= h($row['contract_number']) ?></td>
            <td><?= h($row['client_name']) ?></td>
            <td><?= h($row['shops'] ?? '') ?></td>
            <td class="text-end"><?= co_format_money($row['rent_amount']) ?></td>
            <td><?= h($row['commission_basis'] === 'fixed' ? 'Fixed' : (number_format((float)$row['commission_percent'], 2) . '%')) ?></td>
            <td><?= h($row['invoice_number'] ?: '—') ?><?php if (!empty($row['invoice_date'])): ?><div class="small text-muted"><?= h($row['invoice_date']) ?></div><?php endif; ?></td>
            <td class="text-end"><?= $row['invoice_number'] ? co_format_money($row['commission_net']) : co_format_money($row['commission_net_amount']) ?></td>
            <td class="text-end"><?= $row['invoice_number'] ? co_format_money($row['vat_amount']) : '—' ?></td>
            <td class="text-end"><?= $row['invoice_number'] ? co_format_money($row['total_amount']) : '—' ?></td>
            <td class="text-end"><?= $row['invoice_number'] ? co_format_money($row['collected']) : '—' ?></td>
            <td class="text-end"><?= $row['invoice_number'] ? co_format_money($row['outstanding']) : '—' ?></td>
            <td><?= h($row['invoice_status'] ?: 'not invoiced') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="12" class="text-center text-muted py-4">No commission rows in this period.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
