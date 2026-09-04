<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = co_shop_require_company_id($conn);

$rows = [];
if (co_db_table_exists($conn, 'co_shop_rent_schedules')) {
    $stmt = $conn->prepare("
        SELECT c.contract_number, cl.client_name, s.period_start, s.period_end, s.amount,
               COALESCE((SELECT SUM(r.amount) FROM co_shop_rent_recognitions r
                         WHERE r.schedule_id = s.id AND r.company_id = s.company_id), 0) AS recognized,
               GREATEST(s.amount - COALESCE((SELECT SUM(r.amount) FROM co_shop_rent_recognitions r
                         WHERE r.schedule_id = s.id AND r.company_id = s.company_id), 0), 0) AS deferred_remaining
        FROM co_shop_rent_schedules s
        JOIN co_shop_rental_contracts c ON c.id = s.contract_id AND c.company_id = s.company_id
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE s.company_id = ? AND c.accrual_deferred_rent = 1 AND s.status = 'invoiced'
          AND s.schedule_type = 'rent'
        HAVING deferred_remaining > 0.005 OR recognized > 0.005
        ORDER BY s.period_start, c.contract_number
    ");
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$totalDef = array_sum(array_map(static fn($r) => (float)$r['deferred_remaining'], $rows));
$totalRec = array_sum(array_map(static fn($r) => (float)$r['recognized'], $rows));
co_report_export($rows, [
    'contract_number' => 'Contract', 'client_name' => 'Tenant',
    'period_start' => 'Period Start', 'period_end' => 'Period End',
    'amount' => 'Invoiced (Deferred)', 'recognized' => 'Recognized', 'deferred_remaining' => 'Still Deferred',
], 'shop_deferred_analysis', 'Deferred Revenue Analysis');
$pageTitle = 'Deferred Revenue Analysis';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Deferred Revenue Analysis</h1>
        <p class="text-muted mb-0">Still deferred <?= co_format_money($totalDef) ?> · Recognized on these rows <?= co_format_money($totalRec) ?></p></div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?>
        <a class="btn btn-outline-primary btn-sm" href="deferred_rent_recognition.php">Post Recognition</a>
    </div>
</div>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-hover mb-0"><thead class="table-light"><tr>
<th>Contract</th><th>Tenant</th><th>Period</th><th class="text-end">Invoiced</th><th class="text-end">Recognized</th><th class="text-end">Still Deferred</th>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= h($row['contract_number']) ?></td><td><?= h($row['client_name']) ?></td>
<td><?= h($row['period_start']) ?> → <?= h($row['period_end']) ?></td>
<td class="text-end"><?= co_format_money($row['amount']) ?></td>
<td class="text-end"><?= co_format_money($row['recognized']) ?></td>
<td class="text-end"><?= co_format_money($row['deferred_remaining']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No deferred balances.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
