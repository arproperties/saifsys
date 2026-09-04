<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = co_shop_require_company_id($conn);
$days = max(30, min(365, (int)($_GET['days'] ?? 180)));
$today = date('Y-m-d');
$to = date('Y-m-d', strtotime("+{$days} days"));

$rows = [];
if (co_db_table_exists($conn, 'co_shop_rental_contracts')) {
    $stmt = $conn->prepare("
        SELECT c.id, c.contract_number, c.start_date, c.end_date, c.status, c.rent_amount,
               cl.client_name, DATEDIFF(c.end_date, ?) AS days_remaining
        FROM co_shop_rental_contracts c
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE c.company_id = ? AND c.status IN ('active','draft')
          AND c.end_date BETWEEN ? AND ?
        ORDER BY c.end_date ASC, c.id
    ");
    $stmt->execute([$today, $cid, $today, $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $labels = co_shop_batch_shops_labels($conn, $cid, array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $r['shops'] = $labels[(int)$r['id']] ?? '';
    }
    unset($r);
}
co_report_export($rows, [
    'contract_number' => 'Contract', 'client_name' => 'Tenant', 'shops' => 'Shops',
    'start_date' => 'Start', 'end_date' => 'End', 'days_remaining' => 'Days Left',
    'rent_amount' => 'Rent Net', 'status' => 'Status',
], 'shop_lease_maturity_' . $days . 'd', 'Shop Lease Maturity');
$pageTitle = 'Lease Maturity Report';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Lease Maturity Report</h1><p class="text-muted mb-0">Active/draft contracts ending within <?= (int)$days ?> days</p></div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-4"><label class="form-label">Window (days)</label><input type="number" name="days" class="form-control" value="<?= (int)$days ?>" min="30" max="365"></div>
    <div class="col-md-4"><button class="btn btn-primary">Generate</button></div>
</div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-hover mb-0"><thead class="table-light"><tr>
<th>Contract</th><th>Tenant</th><th>Shops</th><th>End</th><th class="text-end">Days</th><th class="text-end">Rent Net</th><th>Status</th>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= h($row['contract_number']) ?></td><td><?= h($row['client_name']) ?></td><td><?= h($row['shops']) ?></td>
<td><?= h($row['end_date']) ?></td><td class="text-end"><?= (int)$row['days_remaining'] ?></td>
<td class="text-end"><?= co_format_money($row['rent_amount']) ?></td><td><?= h($row['status']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No maturities in this window.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
