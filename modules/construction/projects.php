<?php
/**
 * Construction Module — Projects List
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';

require_login();
$hasAccess = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_CORE, $conn)
    || has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_PROJECTS, $conn);
if (!$hasAccess) require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;

$status = $_GET['status'] ?? '';
$where = "WHERE p.company_id = ?";
$params = [$cid];
if ($status) {
    $where .= " AND p.status = ?";
    $params[] = $status;
}

$stmt = $conn->prepare("
    SELECT p.*, e.full_name as manager_name
    FROM co_projects p
    LEFT JOIN employees e ON e.id = p.project_manager_id
    $where
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Projects';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<?= co_ui_page_header(
    'Projects',
    'Construction project portfolio — status, costs, and operations',
    [['label' => 'Construction', 'href' => 'index.php'], ['label' => 'Projects']],
    '<a href="project_add.php" class="btn btn-primary btn-sm"><i data-lucide="plus" style="width:14px;height:14px"></i> Add Project</a>'
) ?>

<div class="mb-3 co-stat-toggles">
    <a href="projects.php" class="co-stat-toggle <?= !$status ? 'active' : '' ?>">All</a>
    <a href="projects.php?status=active" class="co-stat-toggle <?= $status === 'active' ? 'active' : '' ?>">Active</a>
    <a href="projects.php?status=completed" class="co-stat-toggle <?= $status === 'completed' ? 'active' : '' ?>">Completed</a>
</div>

<div class="card card-round co-table-shell">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Manager</th>
                        <th>Budget</th>
                        <th>Start</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $p): ?>
                    <tr>
                        <td><strong><?= h($p['project_code']) ?></strong></td>
                        <td><?= h($p['project_name']) ?></td>
                        <td><span class="badge bg-secondary"><?= h($p['project_type']) ?></span></td>
                        <td><span class="badge bg-<?= co_project_status_badge($p['status']) ?>"><?= h(co_project_status_label($p['status'])) ?></span></td>
                        <td><?= h($p['manager_name'] ?? '-') ?></td>
                        <td><?= co_format_money($p['approved_budget'] ?? 0) ?></td>
                        <td><?= $p['start_date'] ? date('M j, Y', strtotime($p['start_date'])) : '-' ?></td>
                        <td>
                            <a href="project_view.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($projects)): ?>
        <div class="p-4 text-center text-muted">No projects found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
