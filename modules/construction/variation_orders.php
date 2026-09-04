<?php
/**
 * Construction Module — Variation Orders (list for one project)
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
if (!$project_id) { header('Location: projects.php'); exit; }

$stmt = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE id = ? AND company_id = ?");
$stmt->execute([$project_id, $cid]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { header('Location: projects.php'); exit; }

$vos = $conn->prepare("
    SELECT vo.*, c.contractor_name
    FROM co_variation_orders vo
    LEFT JOIN co_project_contractors pc ON pc.id = vo.project_contractor_id
    LEFT JOIN co_contractors c ON c.id = pc.contractor_id
    WHERE vo.project_id = ? AND vo.company_id = ?
    ORDER BY vo.vo_date DESC, vo.id DESC
");
$vos->execute([$project_id, $cid]);
$vos = $vos->fetchAll(PDO::FETCH_ASSOC);

$totalApprovedVO = 0;
foreach ($vos as $v) {
    if ($v['status'] === 'approved') $totalApprovedVO += (float)$v['amount'];
}

$pageTitle = 'Variation Orders — ' . $project['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_view.php?id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Project</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0">Variation Orders</h1>
            <p class="text-muted mb-0"><?= h($project['project_code']) ?> — <?= h($project['project_name']) ?></p>
        </div>
        <a href="variation_order_add.php?project_id=<?= $project_id ?>" class="btn btn-primary">+ Add VO</a>
    </div>
</div>

<?php if (!empty($vos)): ?>
<div class="card card-round mb-3">
    <div class="card-body">
        <h6 class="text-muted mb-1">Total approved VO</h6>
        <p class="mb-0 fs-5 fw-bold"><?= co_format_money($totalApprovedVO) ?></p>
    </div>
</div>
<?php endif; ?>

<div class="card card-round">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Variation Orders</h6>
        <a href="variation_order_add.php?project_id=<?= $project_id ?>" class="btn btn-sm btn-outline-primary">+ Add</a>
    </div>
    <div class="card-body">
        <?php if (empty($vos)): ?>
            <p class="text-muted mb-0">No variation orders yet. <a href="variation_order_add.php?project_id=<?= $project_id ?>">Add the first VO</a>.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>VO #</th>
                            <th>Description</th>
                            <th>Contractor</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vos as $vo): ?>
                        <tr>
                            <td><?= h($vo['vo_number']) ?></td>
                            <td><?= h($vo['description']) ?></td>
                            <td><?= h($vo['contractor_name'] ?? '-') ?></td>
                            <td><?= date('M j, Y', strtotime($vo['vo_date'])) ?></td>
                            <td><?= co_format_money($vo['amount']) ?></td>
                            <td><span class="badge bg-<?= $vo['status'] === 'approved' ? 'success' : ($vo['status'] === 'rejected' ? 'danger' : 'secondary') ?>"><?= h($vo['status']) ?></span></td>
                            <td><a href="variation_order_edit.php?id=<?= (int)$vo['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
