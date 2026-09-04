<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_income_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$clientId = (int)($_GET['client_id'] ?? 0);

$clientsStmt = $conn->prepare("SELECT id, client_name, COALESCE(client_type, 'customer') AS client_type FROM co_clients WHERE company_id = ? ORDER BY client_name");
$clientsStmt->execute([$cid]);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

$client = null;
foreach ($clients as $row) if ((int)$row['id'] === $clientId) $client = $row;

$rows = [];
if ($client) {
    $stmt = $conn->prepare("
        SELECT i.id, i.invoice_number, i.invoice_date, i.status,
               COALESCE(i.source_type, 'construction_project') AS source_type,
               i.subtotal, i.vat_amount, i.total_amount,
               COALESCE(a.paid_amount, 0) AS paid_amount,
               i.total_amount - COALESCE(a.paid_amount, 0) AS balance_due,
               p.project_code, p.project_name
        FROM co_client_invoices i
        LEFT JOIN co_projects p ON p.id = i.project_id
        LEFT JOIN (
            SELECT invoice_id, SUM(allocated_amount) AS paid_amount
            FROM co_client_payment_allocations
            GROUP BY invoice_id
        ) a ON a.invoice_id = i.id
        WHERE i.company_id = ?
          AND i.status <> 'cancelled'
          AND i.invoice_date BETWEEN ? AND ?
          AND i.client_id = ?
        ORDER BY i.invoice_date, i.invoice_number
    ");
    $stmt->execute([$cid, $dateFrom, $dateTo, $clientId]);
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
    'source_type' => 'Category',
    'project_name' => 'Project',
    'status' => 'Status',
    'subtotal' => 'Subtotal',
    'vat_amount' => 'VAT',
    'total_amount' => 'Total',
    'paid_amount' => 'Paid',
    'balance_due' => 'Balance',
], 'construction_income_by_customer_detail_' . $clientId . '_' . $dateFrom . '_' . $dateTo, 'Construction Income by Customer Detail');

$pageTitle = 'Income by Customer — Detail';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Income by Customer — Detail</h1>
        <p class="text-muted mb-0"><?= $client ? h($client['client_name']) : 'Select a customer' ?> · <?= h($dateFrom) ?> to <?= h($dateTo) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="income_by_customer.php?date_from=<?= h(urlencode($dateFrom)) ?>&date_to=<?= h(urlencode($dateTo)) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back to Summary</a>
        <?= $client ? co_report_export_buttons() : '' ?>
    </div>
</div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-4"><label class="form-label">Customer/Tenant</label><select name="client_id" class="form-select"><option value="0">Select customer</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $clientId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Generate</button></div>
</div></form>
<?php if (!$client): ?>
<div class="card card-round"><div class="card-body text-center text-muted py-4">Select a customer to view invoice detail.</div></div>
<?php else: ?>
<div class="card card-round"><div class="card-body p-0 table-responsive">
    <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>Invoice #</th><th>Date</th><th>Category</th><th>Project</th><th>Status</th><th class="text-end">Subtotal</th><th class="text-end">VAT</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr class="report-row-link" data-href="../client_invoice_pdf.php?id=<?= (int)$row['id'] ?>">
                <td><strong><?= h($row['invoice_number']) ?></strong></td>
                <td><?= $row['invoice_date'] ? date('Y-m-d', strtotime($row['invoice_date'])) : '-' ?></td>
                <td><span class="badge bg-info text-dark"><?= h(co_income_source_label($row['source_type'])) ?></span></td>
                <td><?= $row['project_name'] ? h(($row['project_code'] ? $row['project_code'] . ' — ' : '') . $row['project_name']) : '-' ?></td>
                <td><span class="badge bg-<?= co_invoice_status_badge($row['status']) ?>"><?= h($row['status']) ?></span></td>
                <td class="text-end"><?= co_format_money($row['subtotal']) ?></td>
                <td class="text-end"><?= co_format_money($row['vat_amount']) ?></td>
                <td class="text-end"><?= co_format_money($row['total_amount']) ?></td>
                <td class="text-end"><?= co_format_money($row['paid_amount']) ?></td>
                <td class="text-end"><?= co_format_money($row['balance_due']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No income invoices found for this customer.</td></tr><?php endif; ?>
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
