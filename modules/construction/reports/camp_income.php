<?php
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$stmt = $conn->prepare("
    SELECT s.settlement_number, s.settlement_date, s.period_start, s.period_end,
           c.client_name, camp.camp_name, s.gross_rent_amount, s.output_vat_amount,
           s.commission_amount, s.commission_vat_amount, s.other_deductions,
           s.net_receivable, COALESCE(p.paid_amount, 0) AS paid_amount,
           GREATEST(s.net_receivable - COALESCE(p.paid_amount, 0), 0) AS balance_due,
           s.status
    FROM co_camp_agent_settlements s
    JOIN co_camp_management_contracts cc ON cc.id = s.contract_id
    JOIN co_clients c ON c.id = cc.client_id
    JOIN co_labor_camps camp ON camp.id = cc.camp_id
    LEFT JOIN (
        SELECT settlement_id, SUM(amount) AS paid_amount
        FROM co_camp_agent_receipts
        GROUP BY settlement_id
    ) p ON p.settlement_id = s.id
    WHERE s.company_id = ?
      AND s.status <> 'cancelled'
      AND s.settlement_date BETWEEN ? AND ?
    ORDER BY s.settlement_date DESC, s.id DESC
");
$stmt->execute([$cid, $dateFrom, $dateTo]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
co_report_export($rows, ['settlement_number' => 'Settlement', 'settlement_date' => 'Date', 'client_name' => 'Agent', 'camp_name' => 'Camp', 'gross_rent_amount' => 'Gross Rent', 'output_vat_amount' => 'Output VAT', 'commission_amount' => 'Commission', 'commission_vat_amount' => 'Commission VAT', 'other_deductions' => 'Other Deductions', 'net_receivable' => 'Net Receivable', 'paid_amount' => 'Paid', 'balance_due' => 'Balance', 'status' => 'Status'], 'construction_camp_agent_settlements_' . $dateFrom . '_' . $dateTo, 'Construction Camp Agent Settlements');
$pageTitle = 'Camp Agent Settlements';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print"><div><h1 class="h4 mb-0">Camp Agent Settlements</h1><p class="text-muted mb-0"><?= h($dateFrom) ?> to <?= h($dateTo) ?></p></div><div class="d-flex gap-2"><?= co_report_export_buttons() ?></div></div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end"><div class="col-md-4"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div><div class="col-md-4"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div><div class="col-md-4"><button class="btn btn-primary">Generate</button></div></div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Settlement</th><th>Date</th><th>Agent</th><th>Camp</th><th class="text-end">Gross Rent</th><th class="text-end">VAT</th><th class="text-end">Commission/Deductions</th><th class="text-end">Net Receivable</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?= h($row['settlement_number']) ?><br><small class="text-muted"><?= h($row['period_start']) ?> to <?= h($row['period_end']) ?></small></td><td><?= h($row['settlement_date']) ?></td><td><?= h($row['client_name']) ?></td><td><?= h($row['camp_name'] ?: '-') ?></td><td class="text-end"><?= co_format_money($row['gross_rent_amount']) ?></td><td class="text-end"><?= co_format_money($row['output_vat_amount']) ?></td><td class="text-end"><?= co_format_money((float)$row['commission_amount'] + (float)$row['commission_vat_amount'] + (float)$row['other_deductions']) ?></td><td class="text-end"><?= co_format_money($row['net_receivable']) ?></td><td class="text-end"><?= co_format_money($row['paid_amount']) ?></td><td class="text-end"><?= co_format_money($row['balance_due']) ?></td><td><span class="badge bg-<?= $row['status'] === 'paid' ? 'success' : ($row['status'] === 'partial' ? 'warning text-dark' : 'primary') ?>"><?= h($row['status']) ?></span></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-4">No camp agent settlements found.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
