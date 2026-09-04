<?php
/**
 * Construction Module — Project Profitability Report
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

// Load projects with basic financials
$stmt = $conn->prepare("
    SELECT p.id, p.project_code, p.project_name, p.project_type, p.status,
           p.contract_value, p.approved_budget, p.contractor_contract_value
    FROM co_projects p
    WHERE p.company_id = ?
    ORDER BY p.project_name
");
$stmt->execute([$cid]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Preload consolidated project costs from direct costs, supplier invoices, contractor payments, and historical ERP expenses.
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
$costsStmt = $conn->prepare("
    SELECT project_id, SUM(amount) AS total_cost
    FROM (" . implode(" UNION ALL ", $costQueries) . ") cost_sources
    GROUP BY project_id
");
$costsStmt->execute($costParams);
$costs = [];
foreach ($costsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $costs[(int)$row['project_id']] = (float)$row['total_cost'];
}

$voStmt = $conn->prepare("
    SELECT project_id, SUM(amount) AS vo_total
    FROM co_variation_orders
    WHERE company_id = ? AND status = 'approved'
    GROUP BY project_id
");
$voStmt->execute([$cid]);
$vos = [];
foreach ($voStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $vos[(int)$row['project_id']] = (float)$row['vo_total'];
}

$maintenanceRevenue = [];
if (co_db_column_exists($conn, 'co_client_invoices', 'source_type')) {
    $maintStmt = $conn->prepare("
        SELECT project_id, SUM(subtotal) AS revenue
        FROM co_client_invoices
        WHERE company_id = ?
          AND source_type = 'maintenance_service'
          AND status <> 'cancelled'
          AND project_id IS NOT NULL
        GROUP BY project_id
    ");
    $maintStmt->execute([$cid]);
    foreach ($maintStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $maintenanceRevenue[(int)$row['project_id']] = (float)$row['revenue'];
    }
}

require_once __DIR__ . '/../../realestate/accounting/export_excel_helper.php';
$exportRows = [];
foreach ($projects as $p) {
    $pid = (int)$p['id'];
    $base = (float)($p['contract_value'] ?? 0);
    $vo = $vos[$pid] ?? 0.0;
    $maintenance = $maintenanceRevenue[$pid] ?? 0.0;
    $revenue = $base + $vo + $maintenance;
    $cost = $costs[$pid] ?? 0.0;
    $margin = $revenue - $cost;
    $exportRows[] = [
        'project' => trim(($p['project_code'] ? $p['project_code'] . ' - ' : '') . $p['project_name']),
        'type' => $p['project_type'],
        'status' => co_project_status_label($p['status']),
        'base_contract' => $base,
        'approved_vo' => $vo,
        'maintenance_income' => $maintenance,
        'revenue' => $revenue,
        'cost' => $cost,
        'margin' => $margin,
        'margin_pct' => $revenue > 0 ? round(($margin / $revenue) * 100, 1) : 0,
    ];
}
if (($_GET['export'] ?? '') === 'excel') {
    accounting_export_excel_or_csv($exportRows, ['project' => 'Project', 'type' => 'Type', 'status' => 'Status', 'base_contract' => 'Base Contract', 'approved_vo' => 'Approved VOs', 'maintenance_income' => 'Maintenance Income', 'revenue' => 'Total Revenue', 'cost' => 'Total Cost', 'margin' => 'Margin', 'margin_pct' => 'Margin %'], 'construction_project_profitability', 'Construction Project Profitability');
} elseif (($_GET['export'] ?? '') === 'csv') {
    accounting_export_csv($exportRows, ['project' => 'Project', 'type' => 'Type', 'status' => 'Status', 'base_contract' => 'Base Contract', 'approved_vo' => 'Approved VOs', 'maintenance_income' => 'Maintenance Income', 'revenue' => 'Total Revenue', 'cost' => 'Total Cost', 'margin' => 'Margin', 'margin_pct' => 'Margin %'], 'construction_project_profitability');
}

$pageTitle = 'Project Profitability';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Project Profitability</h1>
        <p class="text-muted mb-0">Contract value, variation orders, maintenance income, consolidated cost and margin per project</p>
    </div>
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
                    <tr>
                        <th>Project</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Base Contract</th>
                        <th>Approved VOs</th>
                        <th>Maintenance Income</th>
                        <th>Total Revenue</th>
                        <th>Total Cost</th>
                        <th>Margin</th>
                        <th>Margin %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $p):
                        $pid = (int)$p['id'];
                        $base = (float)($p['contract_value'] ?? 0);
                        $vo = $vos[$pid] ?? 0.0;
                        $maintenance = $maintenanceRevenue[$pid] ?? 0.0;
                        $revenue = $base + $vo + $maintenance;
                        $cost = $costs[$pid] ?? 0.0;
                        $margin = $revenue - $cost;
                        $marginPct = $revenue > 0 ? round(($margin / $revenue) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td>
                            <a href="../project_view.php?id=<?= $pid ?>">
                                <?= h($p['project_code']) ?> — <?= h($p['project_name']) ?>
                            </a>
                        </td>
                        <td><span class="badge bg-secondary"><?= h($p['project_type']) ?></span></td>
                        <td><span class="badge bg-<?= co_project_status_badge($p['status']) ?>"><?= h(co_project_status_label($p['status'])) ?></span></td>
                        <td><?= co_format_money($base) ?></td>
                        <td><?= co_format_money($vo) ?></td>
                        <td><?= co_format_money($maintenance) ?></td>
                        <td><?= co_format_money($revenue) ?></td>
                        <td><?= co_format_money($cost) ?></td>
                        <td class="<?= $margin < 0 ? 'text-danger' : 'text-success' ?>"><?= co_format_money($margin) ?></td>
                        <td class="<?= $margin < 0 ? 'text-danger' : 'text-success' ?>"><?= $marginPct ?>%</td>
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

<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>

