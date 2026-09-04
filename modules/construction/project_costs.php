<?php
/**
 * Construction Module — Project Costs List
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$project_id = (int)($_GET['project_id'] ?? 0);

$where = "WHERE p.company_id = ?";
$params = [$cid];
if ($project_id) {
    $where .= " AND p.id = ?";
    $params[] = $project_id;
}

$stmt = $conn->prepare("
    SELECT c.*, p.project_code, p.project_name
    FROM co_project_costs c
    JOIN co_projects p ON p.id = c.project_id
    $where
    ORDER BY c.cost_date DESC, c.id DESC
");
$stmt->execute($params);
$costs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Project Costs';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0">Project Costs</h1>
    <a href="project_cost_add.php<?= $project_id ? '?project_id='.$project_id : '' ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Cost</a>
</div>

<div class="card card-round">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Date</th><th>Project</th><th>Type</th><th>Description</th><th>Amount</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($costs as $c): ?>
                    <tr>
                        <td><?= $c['cost_date'] ? date('Y-m-d', strtotime($c['cost_date'])) : '-' ?></td>
                        <td><a href="project_view.php?id=<?= (int)$c['project_id'] ?>"><?= h($c['project_code']) ?></a></td>
                        <td><span class="badge bg-secondary"><?= h($c['cost_type']) ?></span></td>
                        <td><?= h($c['description'] ?? '-') ?></td>
                        <td><?= co_format_money($c['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($costs)): ?>
        <div class="p-4 text-center text-muted">No costs found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
