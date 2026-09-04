<?php
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];
$asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');
$daysAhead = (int)($_GET['days_ahead'] ?? 90);
$until = date('Y-m-d', strtotime($asOfDate . ' +' . $daysAhead . ' days'));
$rows = [];
$queries = [
    ["Shop Rental", "SELECT 'Shop Rental' AS contract_type, contract_number, start_date, end_date, status, cl.client_name, COALESCE((SELECT GROUP_CONCAT(u2.shop_number ORDER BY cs.is_primary DESC, u2.shop_number SEPARATOR ', ') FROM co_shop_rental_contract_shops cs JOIN co_shop_units u2 ON u2.id = cs.shop_unit_id WHERE cs.contract_id = c.id AND cs.company_id = c.company_id), u.shop_number) AS asset_name FROM co_shop_rental_contracts c JOIN co_clients cl ON cl.id=c.client_id JOIN co_shop_units u ON u.id=c.shop_unit_id WHERE c.company_id=? AND c.end_date BETWEEN ? AND ?"],
    ["Camp Management", "SELECT 'Camp Management' AS contract_type, contract_number, start_date, end_date, status, cl.client_name, camp.camp_name AS asset_name FROM co_camp_management_contracts c JOIN co_clients cl ON cl.id=c.client_id JOIN co_labor_camps camp ON camp.id=c.camp_id WHERE c.company_id=? AND c.end_date BETWEEN ? AND ?"],
    ["Maintenance", "SELECT 'Maintenance' AS contract_type, contract_number, start_date, end_date, status, cl.client_name, c.service_name AS asset_name FROM co_maintenance_contracts c JOIN co_clients cl ON cl.id=c.client_id WHERE c.company_id=? AND c.end_date IS NOT NULL AND c.end_date BETWEEN ? AND ?"],
];
foreach ($queries as [, $sql]) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$cid, $asOfDate, $until]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        // Tables may not exist until migration is run.
    }
}
usort($rows, fn($a, $b) => strcmp($a['end_date'], $b['end_date']));
co_report_export($rows, ['contract_type' => 'Type', 'contract_number' => 'Contract #', 'client_name' => 'Customer/Tenant', 'asset_name' => 'Asset/Service', 'start_date' => 'Start', 'end_date' => 'End', 'status' => 'Status'], 'construction_contract_expiry_' . $asOfDate, 'Construction Contract Expiry');
$pageTitle = 'Contract Expiry';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print"><div><h1 class="h4 mb-0">Contract Expiry</h1><p class="text-muted mb-0">Contracts expiring by <?= h($until) ?>.</p></div><div class="d-flex gap-2"><?= co_report_export_buttons() ?></div></div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end"><div class="col-md-4"><label class="form-label">As of Date</label><input type="date" name="as_of_date" class="form-control" value="<?= h($asOfDate) ?>"></div><div class="col-md-4"><label class="form-label">Days Ahead</label><input type="number" name="days_ahead" class="form-control" value="<?= (int)$daysAhead ?>"></div><div class="col-md-4"><button class="btn btn-primary">Generate</button></div></div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Type</th><th>Contract #</th><th>Customer/Tenant</th><th>Asset/Service</th><th>Start</th><th>End</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?= h($row['contract_type']) ?></td><td><?= h($row['contract_number']) ?></td><td><?= h($row['client_name']) ?></td><td><?= h($row['asset_name']) ?></td><td><?= h($row['start_date']) ?></td><td><?= h($row['end_date']) ?></td><td><?= h($row['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No contracts expiring in this window.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
