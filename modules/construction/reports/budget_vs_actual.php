<?php
/**
 * Construction Module — Budget vs Actual Report
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/construction_helpers.php';

require_login();
require_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_REPORTS, $conn);

require_once __DIR__ . '/../../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;

$costQueries = [
    "SELECT project_id, amount FROM co_project_costs WHERE company_id = ?",
    "SELECT project_id, subtotal AS amount FROM co_supplier_invoices WHERE company_id = ? AND project_id IS NOT NULL",
];
$costParams = [$cid, $cid];
// Legacy co_contractor_payments excluded — Supplier/AP is sole financial source (BR-CO-BP-003)
if (co_db_column_exists($conn, 'erp_expense_headers', 'project_id')) {
    $costQueries[] = "SELECT project_id, subtotal AS amount FROM erp_expense_headers WHERE company_id = ? AND source_module = 'construction' AND status <> 'cancelled' AND project_id IS NOT NULL";
    $costParams[] = $cid;
}
$stmt = $conn->prepare("
    SELECT p.id, p.project_code, p.project_name, p.approved_budget, p.contractor_contract_value,
           COALESCE(c.cost, 0) as actual_cost
    FROM co_projects p
    LEFT JOIN (
        SELECT project_id, SUM(amount) as cost
        FROM (" . implode(" UNION ALL ", $costQueries) . ") cost_sources
        GROUP BY project_id
    ) c ON c.project_id = p.id
    WHERE p.company_id = ? AND p.approved_budget > 0
    ORDER BY p.project_name
");
$stmt->execute(array_merge($costParams, [$cid]));
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../realestate/accounting/export_excel_helper.php';
$exportRows = [];
foreach ($projects as $p) {
    $budget = (float)$p['approved_budget'];
    $actual = (float)$p['actual_cost'];
    $exportRows[] = [
        'project' => trim(($p['project_code'] ? $p['project_code'] . ' - ' : '') . $p['project_name']),
        'budget' => $budget,
        'actual' => $actual,
        'pct_used' => $budget > 0 ? round(($actual / $budget) * 100, 1) : 0,
        'remaining' => $budget - $actual,
    ];
}
if (($_GET['export'] ?? '') === 'excel') {
    accounting_export_excel_or_csv($exportRows, ['project' => 'Project', 'budget' => 'Budget', 'actual' => 'Actual', 'pct_used' => '% Used', 'remaining' => 'Remaining'], 'construction_budget_vs_actual', 'Construction Budget vs Actual');
} elseif (($_GET['export'] ?? '') === 'csv') {
    accounting_export_csv($exportRows, ['project' => 'Project', 'budget' => 'Budget', 'actual' => 'Actual', 'pct_used' => '% Used', 'remaining' => 'Remaining'], 'construction_budget_vs_actual');
}

$pageTitle = 'Budget vs Actual';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Budget vs Actual</h1><p class="text-muted mb-0">Projects with approved budget and consolidated actual costs.</p></div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-sm" href="?export=csv"><i class="bi bi-download"></i> CSV</a>
        <a class="btn btn-outline-success btn-sm" href="?export=excel"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
</div>

<div class="card card-round">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Project</th><th>Budget</th><th>Actual</th><th>% Used</th><th>Remaining</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $p):
                        $budget = (float)$p['approved_budget'];
                        $actual = (float)$p['actual_cost'];
                        $pct = $budget > 0 ? round(($actual / $budget) * 100, 1) : 0;
                        $remaining = $budget - $actual;
                    ?>
                    <tr>
                        <td><a href="../project_view.php?id=<?= (int)$p['id'] ?>"><?= h($p['project_code']) ?> — <?= h($p['project_name']) ?></a></td>
                        <td><?= co_format_money($budget) ?></td>
                        <td><?= co_format_money($actual) ?></td>
                        <td><span class="badge bg-<?= $pct > 100 ? 'danger' : ($pct > 90 ? 'warning' : 'success') ?>"><?= $pct ?>%</span></td>
                        <td class="<?= $remaining < 0 ? 'text-danger' : '' ?>"><?= co_format_money($remaining) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($projects)): ?>
        <div class="p-4 text-center text-muted">No projects with approved budget found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
