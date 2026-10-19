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

// Posted supplier bills charged to a project count as project cost too.
$projStmt = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE company_id = ?");
$projStmt->execute([$cid]);
$projectsById = [];
foreach ($projStmt->fetchAll(PDO::FETCH_ASSOC) as $p) $projectsById[(int)$p['id']] = $p;
foreach (co_project_supplier_bill_costs($conn, $cid, $project_id) as $b) {
    $p = $projectsById[(int)$b['project_id']] ?? null;
    if (!$p) continue;
    $desc = trim(($b['supplier_name'] ?? '') . ' — Bill ' . $b['invoice_number'] . ($b['description'] ? ': ' . $b['description'] : ''));
    $costs[] = [
        'project_id' => $b['project_id'],
        'project_code' => $p['project_code'],
        'project_name' => $p['project_name'],
        'cost_date' => $b['cost_date'],
        'cost_type' => 'Supplier Bill',
        'description' => $desc,
        'amount' => $b['amount'],
        'invoice_id' => $b['invoice_id'],
    ];
}
usort($costs, static fn($a, $b) => strcmp((string)$b['cost_date'], (string)$a['cost_date']));
$costTotal = array_sum(array_map(static fn($c) => (float)$c['amount'], $costs));

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
                        <td><a href="project_view.php?id=<?= (int)$c['project_id'] ?>"><?= h($c['project_code']) ?></a><div class="small text-muted"><?= h($c['project_name'] ?? '') ?></div></td>
                        <td><span class="badge bg-secondary"><?= h($c['cost_type']) ?></span></td>
                        <td><?php if (!empty($c['invoice_id'])): ?><a href="supplier_invoice_view.php?id=<?= (int)$c['invoice_id'] ?>"><?= h($c['description']) ?></a><?php else: ?><?= h($c['description'] ?? '-') ?><?php endif; ?></td>
                        <td><?= co_format_money($c['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($costs): ?>
                <tfoot class="table-light">
                    <tr><th colspan="4" class="text-end">Total</th><th><?= co_format_money($costTotal) ?></th></tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php if (empty($costs)): ?>
        <div class="p-4 text-center text-muted">No costs found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
