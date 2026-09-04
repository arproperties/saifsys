<?php
/**
 * Construction Module — Project Labor Assignments
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

$where = "WHERE pl.company_id = ?";
$params = [$cid];
if ($project_id) { $where .= " AND pl.project_id = ?"; $params[] = $project_id; }

$stmt = $conn->prepare("
    SELECT pl.*, p.project_code, p.project_name, e.full_name as employee_name
    FROM co_project_labor pl
    JOIN co_projects p ON p.id = pl.project_id
    JOIN employees e ON e.id = pl.employee_id
    $where
    ORDER BY pl.from_date DESC
");
$stmt->execute($params);
$labor = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Labor Assignments';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0">Labor Assignments</h1>
    <a href="project_labor_add.php<?= $project_id ? '?project_id='.$project_id : '' ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Assign Labor</a>
</div>

<div class="card card-round">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Project</th><th>Employee</th><th>Role</th><th>From</th><th>To</th><th>Daily Rate</th><th>Cost</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($labor as $l): ?>
                    <tr>
                        <td><a href="project_view.php?id=<?= (int)$l['project_id'] ?>"><?= h($l['project_code']) ?></a></td>
                        <td><?= h($l['employee_name']) ?></td>
                        <td><span class="badge bg-info"><?= h($l['role']) ?></span></td>
                        <td><?= $l['from_date'] ? date('Y-m-d', strtotime($l['from_date'])) : '-' ?></td>
                        <td><?= $l['to_date'] ? date('Y-m-d', strtotime($l['to_date'])) : '-' ?></td>
                        <td><?= co_format_money($l['daily_rate'] ?? 0) ?></td>
                        <td><?= co_format_money($l['cost_amount'] ?? 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($labor)): ?>
        <div class="p-4 text-center text-muted">No labor assignments found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
