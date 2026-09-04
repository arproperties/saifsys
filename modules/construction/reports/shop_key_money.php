<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_charge_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = co_shop_require_company_id($conn);
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$rows = [];
if (co_shop_charges_schema_ready($conn)) {
    $shopsExpr = co_shop_phase175_schema_ready($conn)
        ? ('COALESCE(' . co_shop_sql_shops_label('c') . ', u.shop_number)')
        : 'u.shop_number';
    $stmt = $conn->prepare("
        SELECT c.contract_number, cl.client_name, {$shopsExpr} AS shops,
               cc.amount AS key_money_net, cc.vat_amount, cc.gross_amount, cc.status AS charge_status,
               COALESCE(cc.gl_account_override, ct.default_coa_code, '4170') AS income_account,
               cc.invoice_timing,
               i.invoice_number, i.invoice_date, i.subtotal AS invoiced_net,
               i.vat_amount AS invoiced_vat, i.total_amount,
               COALESCE(a.paid_amount, 0) AS collected,
               GREATEST(COALESCE(i.total_amount, 0) - COALESCE(a.paid_amount, 0), 0) AS outstanding,
               i.status AS invoice_status, i.journal_id
        FROM co_shop_contract_charges cc
        JOIN co_shop_charge_types ct
          ON ct.id = cc.charge_type_id AND ct.company_id = cc.company_id AND ct.code = 'key_money'
        JOIN co_shop_rental_contracts c ON c.id = cc.contract_id AND c.company_id = cc.company_id
        JOIN co_clients cl ON cl.id = c.client_id
        JOIN co_shop_units u ON u.id = c.shop_unit_id
        LEFT JOIN co_client_invoices i
               ON i.contract_charge_id = cc.id AND i.company_id = cc.company_id AND i.status <> 'cancelled'
        LEFT JOIN (
            SELECT invoice_id, company_id, SUM(allocated_amount) AS paid_amount
            FROM co_client_payment_allocations
            GROUP BY invoice_id, company_id
        ) a ON a.invoice_id = i.id AND a.company_id = i.company_id
        WHERE cc.company_id = ?
          AND cc.amount > 0
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
    'key_money_net' => 'Key Money Net',
    'income_account' => 'Income COA',
    'invoice_number' => 'Invoice',
    'invoice_date' => 'Invoice Date',
    'invoiced_net' => 'Invoiced Net',
    'invoiced_vat' => 'VAT',
    'total_amount' => 'Gross',
    'collected' => 'Collected',
    'outstanding' => 'Outstanding',
    'invoice_status' => 'Status',
], 'construction_shop_key_money_' . $dateFrom . '_' . $dateTo, 'Shop Key Money Income');

$pageTitle = 'Shop Key Money Income';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Shop Key Money Income</h1>
        <p class="text-muted mb-0">One-time Key Money revenue (account <code>4170</code>) · <?= h($dateFrom) ?> to <?= h($dateTo) ?></p>
    </div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<?php if (!co_shop_charges_schema_ready($conn)): ?>
<div class="alert alert-warning">Run <code>migrations/construction_shop_rental_phase_charges.sql</code> and Key Money migration.</div>
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
            <th>Contract</th><th>Tenant</th><th>Shops</th><th>COA</th>
            <th>Invoice</th><th class="text-end">Net</th><th class="text-end">VAT</th>
            <th class="text-end">Gross</th><th class="text-end">Collected</th><th class="text-end">Outstanding</th><th>Status</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= h($row['contract_number']) ?></td>
            <td><?= h($row['client_name']) ?></td>
            <td><?= h($row['shops'] ?? '') ?></td>
            <td><code><?= h($row['income_account'] ?? '4170') ?></code></td>
            <td><?= h($row['invoice_number'] ?: '—') ?><?php if (!empty($row['invoice_date'])): ?><div class="small text-muted"><?= h($row['invoice_date']) ?></div><?php endif; ?></td>
            <td class="text-end"><?= co_format_money($row['invoiced_net'] ?? $row['key_money_net']) ?></td>
            <td class="text-end"><?= isset($row['invoiced_vat']) && $row['invoice_number'] ? co_format_money($row['invoiced_vat']) : co_format_money($row['vat_amount'] ?? 0) ?></td>
            <td class="text-end"><?= $row['invoice_number'] ? co_format_money($row['total_amount']) : co_format_money($row['gross_amount'] ?? 0) ?></td>
            <td class="text-end"><?= co_format_money($row['collected'] ?? 0) ?></td>
            <td class="text-end"><?= co_format_money($row['outstanding'] ?? 0) ?></td>
            <td><?= h($row['invoice_status'] ?: ($row['charge_status'] ?? '—')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-4">No Key Money charges found.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
