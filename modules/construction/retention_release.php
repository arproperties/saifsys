<?php
/**
 * Construction Module — Retention Release (landing: choose project–contractor)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';

require_login();
$hasAccess = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn);
if (!$hasAccess) require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;

// List project–contractors with retention balance (held - released > 0)
$stmt = $conn->prepare("
    SELECT pc.id AS project_contractor_id, p.id AS project_id, p.project_code, p.project_name, c.contractor_name,
           COALESCE(pay.held, 0) AS total_held,
           COALESCE(rel.released, 0) AS total_released,
           COALESCE(pay.held, 0) - COALESCE(rel.released, 0) AS balance
    FROM co_project_contractors pc
    JOIN co_projects p ON p.id = pc.project_id
    JOIN co_contractors c ON c.id = pc.contractor_id
    LEFT JOIN (SELECT project_contractor_id, SUM(retention_held) AS held FROM co_contractor_payments WHERE company_id = ? GROUP BY project_contractor_id) pay ON pay.project_contractor_id = pc.id
    LEFT JOIN (SELECT project_contractor_id, SUM(amount) AS released FROM co_retention_releases WHERE company_id = ? GROUP BY project_contractor_id) rel ON rel.project_contractor_id = pc.id
    WHERE pc.company_id = ? AND pc.status = 'active'
    HAVING balance > 0
    ORDER BY p.project_name, c.contractor_name
");
$stmt->execute([$cid, $cid, $cid]);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also show all project-contractors with any retention (held > 0) so user can release partial
$stmt2 = $conn->prepare("
    SELECT pc.id AS project_contractor_id, p.id AS project_id, p.project_code, p.project_name, c.contractor_name,
           COALESCE(pay.held, 0) AS total_held,
           COALESCE(rel.released, 0) AS total_released,
           COALESCE(pay.held, 0) - COALESCE(rel.released, 0) AS balance
    FROM co_project_contractors pc
    JOIN co_projects p ON p.id = pc.project_id
    JOIN co_contractors c ON c.id = pc.contractor_id
    LEFT JOIN (SELECT project_contractor_id, SUM(retention_held) AS held FROM co_contractor_payments WHERE company_id = ? GROUP BY project_contractor_id) pay ON pay.project_contractor_id = pc.id
    LEFT JOIN (SELECT project_contractor_id, SUM(amount) AS released FROM co_retention_releases WHERE company_id = ? GROUP BY project_contractor_id) rel ON rel.project_contractor_id = pc.id
    WHERE pc.company_id = ? AND pc.status = 'active' AND COALESCE(pay.held, 0) > 0
    ORDER BY p.project_name, c.contractor_name
");
$stmt2->execute([$cid, $cid, $cid]);
$allWithRetention = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Retention Release';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="index.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
    <h1 class="h4 mb-0">Release Retention</h1>
    <p class="text-muted mb-0">Select a project–contractor to release retention.</p>
</div>

<div class="card card-round">
    <div class="card-body">
        <?php if (empty($allWithRetention)): ?>
            <p class="text-muted mb-0">No project–contractors with retention held. Retention is accumulated when you make contractor payments; then you can release it from here or from the project view.</p>
            <a href="projects.php" class="btn btn-outline-primary mt-3">View Projects</a>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Project</th><th>Contractor</th><th>Held</th><th>Released</th><th>Available</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($allWithRetention as $r): ?>
                    <tr>
                        <td><?= h($r['project_code']) ?> — <?= h($r['project_name']) ?></td>
                        <td><?= h($r['contractor_name']) ?></td>
                        <td><?= co_format_money($r['total_held']) ?></td>
                        <td><?= co_format_money($r['total_released']) ?></td>
                        <td><strong><?= co_format_money($r['balance']) ?></strong></td>
                        <td>
                            <a href="retention_release_add.php?project_contractor_id=<?= (int)$r['project_contractor_id'] ?>" class="btn btn-sm btn-primary">Release</a>
                            <a href="project_view.php?id=<?= (int)$r['project_id'] ?>" class="btn btn-sm btn-outline-secondary">Project</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
