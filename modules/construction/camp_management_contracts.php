<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_income_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$rows = [];
if (co_db_table_exists($conn, 'co_camp_management_contracts')) {
    $stmt = $conn->prepare("
        SELECT c.*, camp.camp_name, cl.client_name
        FROM co_camp_management_contracts c
        JOIN co_labor_camps camp ON camp.id = c.camp_id
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE c.company_id = ?
        ORDER BY c.start_date DESC, c.id DESC
    ");
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$pageTitle = 'Camp Agent Agreements';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div><h1 class="h4 mb-0">Camp Agent Agreements</h1><p class="text-muted mb-0">Agents collect camp rent from tenants, deduct commission, and remit net settlements.</p></div>
    <a href="camp_management_contract_add.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> New Agreement</a>
</div>
<div class="card card-round"><div class="card-body p-0 table-responsive"><table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Agreement #</th><th>Camp</th><th>Agent</th><th>Period</th><th>Commission</th><th>Remittance</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?><tr><td><strong><?= h($row['contract_number']) ?></strong></td><td><?= h($row['camp_name']) ?></td><td><?= h($row['client_name']) ?></td><td><?= h($row['start_date']) ?> to <?= h($row['end_date']) ?></td><td><?= h(number_format((float)($row['commission_rate'] ?? 0), 2)) ?>%</td><td><?= h(ucwords(str_replace('_', ' ', $row['remittance_frequency'] ?? 'monthly'))) ?></td><td><span class="badge bg-<?= $row['status'] === 'active' ? 'success' : 'secondary' ?>"><?= h($row['status']) ?></span></td><td><a href="camp_management_contract_view.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No camp agent agreements found.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
