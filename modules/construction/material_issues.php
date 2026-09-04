<?php
/**
 * Construction Module — Material Issues List
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_request_links.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$project_id = (int)($_GET['project_id'] ?? 0);

$where = "WHERE m.company_id = ?";
$params = [$cid];
if ($project_id) { $where .= " AND m.project_id = ?"; $params[] = $project_id; }

$stmt = $conn->prepare("
    SELECT m.*, p.project_code, p.project_name
    FROM co_material_issues m
    JOIN co_projects p ON p.id = m.project_id
    $where
    ORDER BY m.issue_date DESC, m.id DESC
");
$stmt->execute($params);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Material Issues';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<?= co_ui_page_header(
    'Material Issues',
    'Materials issued to construction projects',
    [['label' => 'Construction', 'href' => 'index.php'], ['label' => 'Material Issues']],
    ($project_id && has_permission('inventory_requests.create', MODULE_INVENTORY, $conn) && inv_user_can_access_material_request_create($conn, 'construction')
        ? '<a class="btn btn-outline-secondary btn-sm" href="' . h(inv_request_material_create_url($conn, [
            'source_module' => 'construction',
            'source_table' => 'co_projects',
            'source_id' => $project_id,
            'context_project_id' => $project_id,
        ])) . '">Request inventory materials</a>'
        : '')
    . '<a href="material_issue_add.php' . ($project_id ? '?project_id=' . $project_id : '') . '" class="btn btn-primary btn-sm"><i data-lucide="plus" style="width:14px;height:14px"></i> Add Issue</a>'
) ?>

<div class="card card-round">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Date</th><th>Project</th><th>Item</th><th>Qty</th><th>Unit Cost</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($issues as $m): ?>
                    <tr>
                        <td><?= $m['issue_date'] ? date('Y-m-d', strtotime($m['issue_date'])) : '-' ?></td>
                        <td><a href="project_view.php?id=<?= (int)$m['project_id'] ?>"><?= h($m['project_code']) ?></a></td>
                        <td><?= h($m['item_name']) ?></td>
                        <td><?= number_format((float)$m['quantity'], 4) ?></td>
                        <td><?= co_format_money($m['unit_cost']) ?></td>
                        <td><?= co_format_money($m['total_cost']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($issues)): ?>
        <div class="p-4 text-center text-muted">No material issues found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
