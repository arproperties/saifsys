<?php
/**
 * Construction Module — Work Orders (per project)
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
$project_id = (int)($_GET['project_id'] ?? 0);

if (!$project_id) {
    // Landing: list projects with link to work orders
    $projects = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE company_id = ? AND status IN ('draft','active','on_hold') ORDER BY project_name");
    $projects->execute([$cid]);
    $projects = $projects->fetchAll(PDO::FETCH_ASSOC);
    $pageTitle = 'Work Orders';
    require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4">
    <a href="index.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
    <h1 class="h4 mb-0">Work Orders</h1>
    <p class="text-muted mb-0">Select a project to view or add work orders.</p>
</div>
<div class="card card-round">
    <div class="card-body">
        <?php if (empty($projects)): ?>
            <p class="text-muted mb-0">No projects found. <a href="project_add.php">Add a project</a> first.</p>
        <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($projects as $p): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><?= h($p['project_code']) ?> — <?= h($p['project_name']) ?></span>
                <a href="work_orders.php?project_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary">View work orders</a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; exit; ?>

<?php
}

// Load project
$stmt = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE id = ? AND company_id = ?");
$stmt->execute([$project_id, $cid]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { header('Location: work_orders.php'); exit; }

// Load work orders
$wos = $conn->prepare("
    SELECT wo.*, c.contractor_name
    FROM co_work_orders wo
    JOIN co_project_contractors pc ON pc.id = wo.project_contractor_id
    JOIN co_contractors c ON c.id = pc.contractor_id
    WHERE wo.project_id = ? AND wo.company_id = ?
    ORDER BY wo.work_order_number
");
$wos->execute([$project_id, $cid]);
$wos = $wos->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Work Orders — ' . $project['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_view.php?id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Project</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0">Work Orders</h1>
            <p class="text-muted mb-0"><?= h($project['project_code']) ?> — <?= h($project['project_name']) ?></p>
        </div>
        <a href="work_order_add.php?project_id=<?= $project_id ?>" class="btn btn-primary">+ Work Order</a>
    </div>
</div>

<div class="card card-round">
    <div class="card-body">
        <?php if (empty($wos)): ?>
            <p class="text-muted mb-0">No work orders yet. <a href="work_order_add.php?project_id=<?= $project_id ?>">Create the first work order</a>.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>WO #</th>
                        <th>Contractor</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Dates</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($wos as $wo): ?>
                    <tr>
                        <td><?= h($wo['work_order_number']) ?></td>
                        <td><?= h($wo['contractor_name']) ?></td>
                        <td><?= h($wo['description']) ?></td>
                        <td><?= co_format_money($wo['amount']) ?></td>
                        <td><span class="badge bg-<?php
                            echo $wo['status'] === 'completed' ? 'success' : ($wo['status'] === 'in_progress' || $wo['status'] === 'issued' ? 'primary' : 'secondary');
                        ?>"><?= h($wo['status']) ?></span></td>
                        <td><?= $wo['start_date'] ? date('M j, Y', strtotime($wo['start_date'])) : '-' ?> — <?= $wo['end_date'] ? date('M j, Y', strtotime($wo['end_date'])) : '-' ?></td>
                        <td><a href="work_order_edit.php?id=<?= (int)$wo['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
