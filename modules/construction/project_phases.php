<?php
/**
 * Construction Module — Project Phases (list for one project)
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

$phases = $conn->prepare("
    SELECT * FROM co_project_phases
    WHERE project_id = ? AND company_id = ?
    ORDER BY sequence ASC, id ASC
");
$phases->execute([$project_id, $cid]);
$phases = $phases->fetchAll(PDO::FETCH_ASSOC);

$overallPercent = 0;
if (!empty($phases)) {
    $sum = array_sum(array_column($phases, 'percent_complete'));
    $overallPercent = round($sum / count($phases), 1);
}

$pageTitle = 'Project Phases — ' . $project['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_view.php?id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Project</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0">Project Phases</h1>
            <p class="text-muted mb-0"><?= h($project['project_code']) ?> — <?= h($project['project_name']) ?></p>
        </div>
        <a href="project_phase_add.php?project_id=<?= $project_id ?>" class="btn btn-primary">+ Add Phase</a>
    </div>
</div>

<?php if (!empty($phases)): ?>
<div class="card card-round mb-3">
    <div class="card-body">
        <h6 class="text-muted mb-2">Overall progress</h6>
        <div class="d-flex align-items-center gap-3">
            <div class="progress flex-grow-1" style="height: 24px;">
                <div class="progress-bar bg-success" role="progressbar" style="width: <?= min(100, (float)$overallPercent) ?>%;" aria-valuenow="<?= (float)$overallPercent ?>" aria-valuemin="0" aria-valuemax="100"><?= (float)$overallPercent ?>%</div>
            </div>
            <span class="fw-bold"><?= (float)$overallPercent ?>%</span>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card card-round">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Phases / Milestones</h6>
        <a href="project_phase_add.php?project_id=<?= $project_id ?>" class="btn btn-sm btn-outline-primary">+ Add</a>
    </div>
    <div class="card-body">
        <?php if (empty($phases)): ?>
            <p class="text-muted mb-0">No phases yet. <a href="project_phase_add.php?project_id=<?= $project_id ?>">Add the first phase</a>.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Phase</th>
                            <th>Planned</th>
                            <th>Actual</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($phases as $i => $ph): ?>
                        <tr>
                            <td><?= (int)$ph['sequence'] ?: ($i + 1) ?></td>
                            <td><?= h($ph['phase_name']) ?></td>
                            <td><?= $ph['planned_start_date'] ? date('M j', strtotime($ph['planned_start_date'])) : '-' ?> — <?= $ph['planned_end_date'] ? date('M j, Y', strtotime($ph['planned_end_date'])) : '-' ?></td>
                            <td><?= $ph['actual_start_date'] ? date('M j', strtotime($ph['actual_start_date'])) : '-' ?> — <?= $ph['actual_end_date'] ? date('M j, Y', strtotime($ph['actual_end_date'])) : '-' ?></td>
                            <td>
                                <div class="progress" style="height: 18px; width: 80px;">
                                    <div class="progress-bar bg-<?= (float)$ph['percent_complete'] >= 100 ? 'success' : 'primary' ?>" style="width: <?= min(100, (float)$ph['percent_complete']) ?>%;"></div>
                                </div>
                                <small><?= (float)$ph['percent_complete'] ?>%</small>
                            </td>
                            <td><span class="badge bg-<?= $ph['status'] === 'completed' ? 'success' : ($ph['status'] === 'in_progress' ? 'primary' : 'secondary') ?>"><?= str_replace('_', ' ', $ph['status']) ?></span></td>
                            <td><a href="project_phase_edit.php?id=<?= (int)$ph['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
