<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = co_shop_require_company_id($conn);
$mode = ($_GET['mode'] ?? 'all') === 'vacant' ? 'vacant' : (($_GET['mode'] ?? '') === 'occupied' ? 'occupied' : 'all');

$rows = [];
if (co_db_table_exists($conn, 'co_shop_units')) {
    $sql = "
        SELECT u.id, u.shop_number, u.shop_name, u.status, u.location,
               (SELECT GROUP_CONCAT(c.contract_number ORDER BY c.id SEPARATOR ', ')
                FROM co_shop_rental_contract_shops cs
                JOIN co_shop_rental_contracts c ON c.id = cs.contract_id AND c.company_id = cs.company_id
                WHERE cs.shop_unit_id = u.id AND cs.company_id = u.company_id AND c.status = 'active'
               ) AS active_contracts
        FROM co_shop_units u
        WHERE u.company_id = ?
    ";
    $params = [$cid];
    if ($mode === 'vacant') {
        $sql .= " AND u.status = 'available'";
    } elseif ($mode === 'occupied') {
        $sql .= " AND u.status = 'occupied'";
    }
    $sql .= " ORDER BY u.shop_number";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$occ = 0; $vac = 0; $inact = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'occupied') $occ++;
    elseif ($r['status'] === 'available') $vac++;
    else $inact++;
}
co_report_export($rows, [
    'shop_number' => 'Shop', 'shop_name' => 'Name', 'status' => 'Status',
    'location' => 'Location', 'active_contracts' => 'Active Contracts',
], 'shop_occupancy_' . $mode, 'Shop Occupancy / Vacancy');
$pageTitle = 'Occupancy / Vacancy Report';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Occupancy / Vacancy Report</h1>
        <p class="text-muted mb-0">Occupied <?= $occ ?> · Vacant <?= $vac ?> · Inactive <?= $inact ?></p></div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-4"><label class="form-label">View</label>
        <select name="mode" class="form-select">
            <option value="all" <?= $mode==='all'?'selected':'' ?>>All shops</option>
            <option value="occupied" <?= $mode==='occupied'?'selected':'' ?>>Occupied only</option>
            <option value="vacant" <?= $mode==='vacant'?'selected':'' ?>>Vacant only</option>
        </select>
    </div>
    <div class="col-md-4"><button class="btn btn-primary">Generate</button></div>
</div></form>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-hover mb-0"><thead class="table-light"><tr>
<th>Shop</th><th>Name</th><th>Status</th><th>Location</th><th>Active Contracts</th>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= h($row['shop_number']) ?></td><td><?= h($row['shop_name'] ?? '') ?></td>
<td><?= h($row['status']) ?></td><td><?= h($row['location'] ?? '') ?></td>
<td><?= h($row['active_contracts'] ?: '—') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No shops.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
