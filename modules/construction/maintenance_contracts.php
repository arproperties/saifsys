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
if (co_db_table_exists($conn, 'co_maintenance_contracts')) {
    $stmt = $conn->prepare("
        SELECT c.*, cl.client_name, p.project_code
        FROM co_maintenance_contracts c
        JOIN co_clients cl ON cl.id = c.client_id
        LEFT JOIN co_projects p ON p.id = c.project_id
        WHERE c.company_id = ?
        ORDER BY c.start_date DESC, c.id DESC
    ");
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$pageTitle = 'Maintenance Service Contracts';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div><h1 class="h4 mb-0">Maintenance Service Contracts</h1><p class="text-muted mb-0">One-time and recurring maintenance service income.</p></div>
    <a href="maintenance_contract_add.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> New Contract</a>
</div>
<div class="card card-round"><div class="card-body p-0 table-responsive"><table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Contract #</th><th>Service</th><th>Customer</th><th>Project</th><th>Period</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><td><strong><?= h($row['contract_number']) ?></strong></td><td><?= h($row['service_name']) ?></td><td><?= h($row['client_name']) ?></td><td><?= h($row['project_code'] ?: '-') ?></td><td><?= h($row['start_date']) ?><?= $row['end_date'] ? ' to ' . h($row['end_date']) : '' ?></td><td><?= co_format_money($row['amount']) ?></td><td><span class="badge bg-<?= in_array($row['status'], ['active','completed'], true) ? 'success' : 'secondary' ?>"><?= h($row['status']) ?></span></td><td><a href="maintenance_contract_view.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No maintenance contracts found.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
