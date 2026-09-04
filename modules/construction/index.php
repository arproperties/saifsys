<?php
/**
 * Construction Module — Dashboard (with charts & smart insights)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';

require_login();
$hasAccess = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_CORE, $conn)
    || has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_PROJECTS, $conn)
    || has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn)
    || has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_REPORTS, $conn);
if (!$hasAccess) require_module_access($conn, MODULE_CONSTRUCTION);

$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;

// ——— Stats ———
$stmt = $conn->prepare("SELECT COUNT(*) FROM co_projects WHERE company_id = ?");
$stmt->execute([$cid]);
$totalProjects = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM co_projects WHERE company_id = ? AND status = 'active'");
$stmt->execute([$cid]);
$activeProjects = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM co_contractors WHERE company_id = ? AND is_active = 1");
$stmt->execute([$cid]);
$totalContractors = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM co_project_costs c JOIN co_projects p ON p.id = c.project_id WHERE p.company_id = ?");
$stmt->execute([$cid]);
$totalCost = (float)$stmt->fetchColumn();

$openRfis = 0;
$submittalsPending = 0;
$workOrdersCount = 0;
$retentionHeld = 0.0;
$retentionReleased = 0.0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM co_rfis WHERE company_id = ? AND status = 'open'");
    $stmt->execute([$cid]);
    $openRfis = (int)$stmt->fetchColumn();
} catch (Throwable $e) { }
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM co_submittals WHERE company_id = ? AND status IN ('submitted','under_review')");
    $stmt->execute([$cid]);
    $submittalsPending = (int)$stmt->fetchColumn();
} catch (Throwable $e) { }
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM co_work_orders WHERE company_id = ?");
    $stmt->execute([$cid]);
    $workOrdersCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) { }
try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(retention_held), 0) FROM co_contractor_payments cp JOIN co_project_contractors pc ON pc.id = cp.project_contractor_id WHERE pc.company_id = ?");
    $stmt->execute([$cid]);
    $retentionHeld = (float)$stmt->fetchColumn();
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM co_retention_releases WHERE company_id = ?");
    $stmt->execute([$cid]);
    $retentionReleased = (float)$stmt->fetchColumn();
} catch (Throwable $e) { }

$suppliersCount = 0;
$supplierPayables = 0.0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM co_suppliers WHERE company_id = ? AND is_active = 1");
    $stmt->execute([$cid]);
    $suppliersCount = (int)$stmt->fetchColumn();
    $stmt = $conn->prepare("SELECT (SELECT COALESCE(SUM(total), 0) FROM co_supplier_invoices WHERE company_id = ?) - (SELECT COALESCE(SUM(amount), 0) FROM co_supplier_payments WHERE company_id = ?)");
    $stmt->execute([$cid, $cid]);
    $supplierPayables = (float)$stmt->fetchColumn();
    if ($supplierPayables < 0) $supplierPayables = 0;
} catch (Throwable $e) { }

$clientInvoicesCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM co_client_invoices WHERE company_id = ?");
    $stmt->execute([$cid]);
    $clientInvoicesCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) { }

// ——— Chart: Project status ———
$stmt = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM co_projects WHERE company_id = ? GROUP BY status");
$stmt->execute([$cid]);
$projectStatusRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$projectStatusLabels = [];
$projectStatusData = [];
$projectStatusColors = ['draft' => '#6c757d', 'active' => '#28a745', 'on_hold' => '#ffc107', 'completed' => '#17a2b8', 'cancelled' => '#dc3545'];
foreach ($projectStatusRows as $r) {
    $projectStatusLabels[] = co_project_status_label($r['status']);
    $projectStatusData[] = (int)$r['cnt'];
}

// ——— Chart: Cost trend (last 6 months) ———
$costTrendLabels = [];
$costTrendData = [];
for ($i = 5; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd = date('Y-m-t', strtotime("-$i months"));
    $costTrendLabels[] = date('M Y', strtotime("-$i months"));
    $stmt = $conn->prepare("SELECT COALESCE(SUM(c.amount), 0) FROM co_project_costs c JOIN co_projects p ON p.id = c.project_id WHERE p.company_id = ? AND c.cost_date >= ? AND c.cost_date <= ?");
    $stmt->execute([$cid, $monthStart, $monthEnd]);
    $costTrendData[] = (float)$stmt->fetchColumn();
}

// ——— Chart: Submittal / RFI status (if tables exist) ———
$submittalChartLabels = [];
$submittalChartData = [];
try {
    $stmt = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM co_submittals WHERE company_id = ? GROUP BY status");
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $submittalChartLabels[] = ucfirst(str_replace('_', ' ', $r['status']));
        $submittalChartData[] = (int)$r['cnt'];
    }
} catch (Throwable $e) { }

// ——— Smart Insights ———
$insights = [];

try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM co_rfis WHERE company_id = ? AND status = 'open' AND DATEDIFF(CURDATE(), issued_date) > 7");
    $stmt->execute([$cid]);
    $rfisOld = (int)$stmt->fetchColumn();
    if ($rfisOld > 0) {
        $insights[] = ['type' => 'warning', 'icon' => 'bi-question-circle', 'title' => 'RFIs need response', 'message' => "$rfisOld RFI(s) open for more than 7 days. Consider responding to avoid delays.", 'link' => 'projects.php', 'linkText' => 'Go to projects'];
    }
} catch (Throwable $e) { }

if ($submittalsPending > 0) {
    $insights[] = ['type' => 'info', 'icon' => 'bi-file-earmark-check', 'title' => 'Submittals awaiting review', 'message' => "$submittalsPending submittal(s) are submitted or under review.", 'link' => 'projects.php', 'linkText' => 'Go to projects'];
}

if ($retentionHeld > 0 && $retentionHeld > $retentionReleased) {
    $retentionAvailable = $retentionHeld - $retentionReleased;
    if ($retentionAvailable > 0) {
        $insights[] = ['type' => 'success', 'icon' => 'bi-wallet2', 'title' => 'Retention available', 'message' => co_format_money($retentionAvailable) . ' retention can be released when milestones are met.', 'link' => 'retention_release_add.php', 'linkText' => 'Release retention'];
    }
}

$stmt = $conn->prepare("
    SELECT p.id, p.project_code, p.project_name, p.approved_budget,
           (SELECT COALESCE(SUM(c.amount), 0) FROM co_project_costs c WHERE c.project_id = p.id) AS total_cost
    FROM co_projects p
    WHERE p.company_id = ? AND p.status IN ('active','on_hold') AND p.approved_budget > 0
");
$stmt->execute([$cid]);
$projectBudgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($projectBudgets as $pb) {
    $budget = (float)$pb['approved_budget'];
    $cost = (float)$pb['total_cost'];
    if ($budget > 0 && $cost > $budget) {
        $pct = round((($cost - $budget) / $budget) * 100, 0);
        $insights[] = ['type' => 'danger', 'icon' => 'bi-exclamation-triangle', 'title' => 'Over budget', 'message' => $pb['project_code'] . ' is ' . $pct . '% over approved budget.', 'link' => 'project_view.php?id=' . (int)$pb['id'], 'linkText' => 'View project'];
    }
}

if ($supplierPayables > 0) {
    $insights[] = ['type' => 'info', 'icon' => 'bi-receipt', 'title' => 'Supplier payables', 'message' => co_format_money($supplierPayables) . ' due to suppliers. Review invoices and payments.', 'link' => 'supplier_invoices.php', 'linkText' => 'Supplier Invoices'];
}

if ($activeProjects >= 1 && empty($insights)) {
    $insights[] = ['type' => 'success', 'icon' => 'bi-check2-circle', 'title' => 'On track', 'message' => 'No critical alerts. Keep an eye on RFIs and submittals.', 'link' => '', 'linkText' => ''];
}

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<?= co_ui_page_header(
    'Construction Dashboard',
    $brand['system_name'] . ' — projects, costs, payables, and operational signals',
    [['label' => 'Construction'], ['label' => 'Dashboard']],
    '<a href="project_add.php" class="btn btn-primary btn-sm"><i data-lucide="plus" style="width:14px;height:14px"></i> New Project</a>'
      . '<a href="shop_rental_control_center.php" class="btn btn-outline-secondary btn-sm"><i data-lucide="store" style="width:14px;height:14px"></i> Shop Leasing</a>'
) ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Total Projects', 'value' => (string)$totalProjects, 'icon' => 'briefcase', 'href' => 'projects.php', 'sub' => 'All statuses']) ?></div>
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Active Projects', 'value' => (string)$activeProjects, 'icon' => 'check-circle-2', 'tone' => 'teal', 'href' => 'projects.php?status=active']) ?></div>
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Contractors', 'value' => (string)$totalContractors, 'icon' => 'users', 'tone' => 'info', 'href' => 'contractors.php']) ?></div>
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Total Cost', 'value' => co_format_money($totalCost), 'icon' => 'banknote', 'href' => 'project_costs.php']) ?></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Open RFIs', 'value' => (string)$openRfis, 'icon' => 'help-circle', 'tone' => $openRfis > 0 ? 'danger' : '', 'href' => 'projects.php']) ?></div>
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Submittals Pending', 'value' => (string)$submittalsPending, 'icon' => 'file-check-2', 'tone' => 'info', 'href' => 'projects.php']) ?></div>
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Work Orders', 'value' => (string)$workOrdersCount, 'icon' => 'clipboard-list', 'href' => 'work_orders.php']) ?></div>
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Retention Held', 'value' => co_format_money($retentionHeld), 'icon' => 'lock', 'href' => 'retention_release_add.php', 'sub' => 'Released ' . co_format_money($retentionReleased)]) ?></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Suppliers', 'value' => (string)$suppliersCount, 'icon' => 'truck', 'href' => 'suppliers.php']) ?></div>
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Supplier Payables', 'value' => co_format_money($supplierPayables), 'icon' => 'receipt', 'tone' => $supplierPayables > 0 ? 'danger' : 'teal', 'href' => 'supplier_invoices.php']) ?></div>
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'VAT Report', 'value' => 'Input / Output', 'icon' => 'percent', 'tone' => 'info', 'href' => 'construction_vat_report.php']) ?></div>
    <div class="col-6 col-md-3"><?= co_ui_kpi(['label' => 'Client Invoices', 'value' => (string)$clientInvoicesCount, 'icon' => 'file-text', 'href' => 'client_invoices.php']) ?></div>
</div>

<?php if (!empty($insights)): ?>
<div class="card card-round mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i data-lucide="lightbulb" style="width:18px;height:18px;color:var(--co-gold)"></i>
        <strong>Smart Insights</strong>
    </div>
    <div class="card-body">
        <div class="row g-2">
            <?php foreach (array_slice($insights, 0, 6) as $ins):
                $t = $ins['type'] === 'danger' ? 'danger' : ($ins['type'] === 'success' ? 'success' : ($ins['type'] === 'warning' ? 'warning' : 'info'));
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="p-3 rounded border" style="border-color:var(--co-border)!important;background:var(--co-bg-elevated)">
                    <strong class="text-<?= h($t) ?>"><?= h($ins['title']) ?></strong>
                    <p class="mb-1 small text-muted"><?= h($ins['message']) ?></p>
                    <?php if (!empty($ins['link'])): ?><a href="<?= h($ins['link']) ?>" class="small"><?= h($ins['linkText']) ?> →</a><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card card-round h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i data-lucide="pie-chart" style="width:16px;height:16px;color:var(--co-gold)"></i>
                <strong class="small mb-0">Projects by Status</strong>
            </div>
            <div class="card-body"><div class="co-chart-wrap"><canvas id="chartProjectStatus"></canvas></div></div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card card-round h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i data-lucide="bar-chart-3" style="width:16px;height:16px;color:var(--co-teal)"></i>
                <strong class="small mb-0">Cost Trend (Last 6 Months)</strong>
            </div>
            <div class="card-body"><div class="co-chart-wrap"><canvas id="chartCostTrend"></canvas></div></div>
        </div>
    </div>
</div>

<?php if (!empty($submittalChartData)): ?>
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card card-round h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i data-lucide="file-bar-chart" style="width:16px;height:16px;color:var(--co-info)"></i>
                <strong class="small mb-0">Submittals by Status</strong>
            </div>
            <div class="card-body"><div class="co-chart-wrap" style="height:260px"><canvas id="chartSubmittals"></canvas></div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-header"><strong>Quick Actions</strong></div>
    <div class="card-body">
        <div class="co-quick-actions">
            <a class="co-qa" href="project_add.php"><i data-lucide="folder-plus" style="width:18px;height:18px"></i> New Project</a>
            <a class="co-qa" href="contractor_add.php"><i data-lucide="user-plus" style="width:18px;height:18px"></i> New Contractor</a>
            <a class="co-qa" href="supplier_add.php"><i data-lucide="truck" style="width:18px;height:18px"></i> New Supplier</a>
            <a class="co-qa" href="supplier_invoice_add.php"><i data-lucide="receipt" style="width:18px;height:18px"></i> Supplier Invoice</a>
            <a class="co-qa" href="supplier_payment_add.php"><i data-lucide="banknote" style="width:18px;height:18px"></i> Supplier Payment</a>
            <a class="co-qa" href="project_cost_add.php"><i data-lucide="coins" style="width:18px;height:18px"></i> Add Cost</a>
            <a class="co-qa" href="material_issue_add.php"><i data-lucide="package" style="width:18px;height:18px"></i> Material Issue</a>
            <a class="co-qa" href="work_orders.php"><i data-lucide="clipboard-list" style="width:18px;height:18px"></i> Work Orders</a>
            <a class="co-qa" href="construction_vat_report.php"><i data-lucide="percent" style="width:18px;height:18px"></i> VAT Report</a>
            <a class="co-qa" href="client_invoices.php"><i data-lucide="file-text" style="width:18px;height:18px"></i> Client Invoices</a>
            <a class="co-qa" href="client_invoice_create.php"><i data-lucide="file-plus-2" style="width:18px;height:18px"></i> New Client Invoice</a>
            <a class="co-qa" href="bank_reconciliation.php"><i data-lucide="landmark" style="width:18px;height:18px"></i> Bank Reconciliation</a>
        </div>
    </div>
</div>

<?php
$pageScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
  var theme = (window.CoUiV2 && CoUiV2.chartTheme) ? CoUiV2.chartTheme : {gold:"#d4af37",teal:"#10b981",danger:"#f87171",info:"#38bdf8",muted:"#64748b"};
  if (window.CoUiV2 && CoUiV2.applyChartDefaults) CoUiV2.applyChartDefaults();
  else {
    Chart.defaults.font.family = "DM Sans, Segoe UI, sans-serif";
    Chart.defaults.color = "#94a3b8";
  }
  var projectLabels = ' . json_encode($projectStatusLabels) . ';
  var projectData = ' . json_encode($projectStatusData) . ';
  var projectColors = [theme.muted, theme.teal, theme.gold, theme.info, theme.danger];
  if (document.getElementById("chartProjectStatus")) {
    new Chart(document.getElementById("chartProjectStatus"), {
      type: "doughnut",
      data: {
        labels: projectLabels,
        datasets: [{ data: projectData, backgroundColor: projectColors, borderWidth: 2, borderColor: (theme.border || "#ffffff") }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: "bottom" } }
      }
    });
  }

  var costLabels = ' . json_encode($costTrendLabels) . ';
  var costData = ' . json_encode($costTrendData) . ';
  if (document.getElementById("chartCostTrend")) {
    new Chart(document.getElementById("chartCostTrend"), {
      type: "bar",
      data: {
        labels: costLabels,
        datasets: [{ label: "Cost (AED)", data: costData, backgroundColor: "rgba(16,185,129,0.45)", borderColor: theme.teal, borderWidth: 1 }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, grid: { color: "rgba(148,163,184,0.15)" } }, x: { grid: { display: false } } }
      }
    });
  }

  var subLabels = ' . json_encode($submittalChartLabels) . ';
  var subData = ' . json_encode($submittalChartData) . ';
  if (document.getElementById("chartSubmittals") && subData.length) {
    new Chart(document.getElementById("chartSubmittals"), {
      type: "doughnut",
      data: { labels: subLabels, datasets: [{ data: subData, backgroundColor: [theme.chart1 || theme.info, theme.chart2 || theme.gold, theme.chart3 || theme.teal, theme.chart4 || theme.muted, theme.chart5 || theme.danger], borderColor: (theme.border || "#ffffff"), borderWidth: 2 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom" } } }
    });
  }
})();
</script>';
require_once __DIR__ . '/includes/construction_layout_footer.php';
?>
