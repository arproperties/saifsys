<?php
/**
 * Real Estate Module - Main Dashboard
 * Modern, professional dashboard with branding support
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
// Check department access (backward compatible: fallback to module access)
// Dashboard requires at least one department access
$hasCore = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn);
$hasFinancial = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn);
$hasMaintenance = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_MAINTENANCE, $conn);
$hasOperations = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_OPERATIONS, $conn);
$hasCompliance = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_COMPLIANCE, $conn);

$hasAccess = $hasCore || $hasFinancial || $hasMaintenance || $hasOperations || $hasCompliance;
if (!$hasAccess) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get user info for avatar
$U = current_user();
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

// Get statistics - Show all data regardless of company (same fix as reports)
$stmt = $conn->prepare("SELECT COUNT(*) FROM re_buildings");
$stmt->execute();
$totalBuildings = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_units");
$stmt->execute();
$totalUnits = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_units WHERE status = 'occupied'");
$stmt->execute();
$occupiedUnits = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_units WHERE status = 'vacant'");
$stmt->execute();
$vacantUnits = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_leases WHERE status = 'active'");
$stmt->execute();
$activeLeases = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_maintenance_requests WHERE status = 'pending'");
$stmt->execute();
$pendingMaintenance = (int)$stmt->fetchColumn();

// Task statistics
$stmt = $conn->prepare("SELECT COUNT(*) FROM re_tasks WHERE status IN ('pending', 'in_progress')");
$stmt->execute();
$activeTasks = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_tasks WHERE status IN ('pending', 'in_progress') AND due_date < CURDATE()");
$stmt->execute();
$overdueTasks = (int)$stmt->fetchColumn();

// Get overdue tasks for widget
$overdueTasksList = $conn->prepare("
    SELECT t.id, t.task_title, t.due_date, t.priority, e.full_name as assigned_employee
    FROM re_tasks t
    LEFT JOIN employees e ON e.id = t.assigned_to
    WHERE t.status IN ('pending', 'in_progress') AND t.due_date < CURDATE()
    ORDER BY t.due_date ASC
    LIMIT 5
");
$overdueTasksList->execute();
$overdueTasksList = $overdueTasksList->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming tasks (next 7 days)
$upcomingTasks = $conn->prepare("
    SELECT t.id, t.task_title, t.due_date, t.priority, e.full_name as assigned_employee
    FROM re_tasks t
    LEFT JOIN employees e ON e.id = t.assigned_to
    WHERE t.status IN ('pending', 'in_progress') 
    AND t.due_date >= CURDATE() AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY t.due_date ASC
    LIMIT 5
");
$upcomingTasks->execute();
$upcomingTasks = $upcomingTasks->fetchAll(PDO::FETCH_ASSOC);

// Chart Data: Revenue (Last 6 months)
$revenueData = [];
$revenueLabels = [];
for ($i = 5; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd = date('Y-m-t', strtotime("-$i months"));
    $monthLabel = date('M Y', strtotime("-$i months"));
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM re_payments
        WHERE payment_date >= ? AND payment_date <= ?
    ");
    $stmt->execute([$monthStart, $monthEnd]);
    $revenue = (float)$stmt->fetchColumn();
    
    $revenueLabels[] = $monthLabel;
    $revenueData[] = $revenue;
}

// AMC Statistics
$amcStats = $conn->prepare("
    SELECT 
        COUNT(*) as total_contracts,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_contracts,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_contracts,
        SUM(CASE WHEN DATEDIFF(end_date, CURDATE()) <= 30 AND status = 'active' THEN 1 ELSE 0 END) as expiring_soon,
        SUM(CASE WHEN DATEDIFF(end_date, CURDATE()) <= 7 AND status = 'active' THEN 1 ELSE 0 END) as expiring_critical
    FROM re_amc_contracts
    WHERE company_id = ?
");
$amcStats->execute([$currentCompanyId]);
$amcStatistics = $amcStats->fetch(PDO::FETCH_ASSOC) ?: [];

// AMC Alerts Count
$amcAlertsCount = $conn->prepare("
    SELECT COUNT(*) FROM re_amc_alerts a
    JOIN re_amc_contracts ac ON ac.id = a.contract_id
    WHERE ac.company_id = ? AND a.status = 'pending'
");
$amcAlertsCount->execute([$currentCompanyId]);
$amcAlertsPending = (int)$amcAlertsCount->fetchColumn();

// Chart Data: Maintenance Status Distribution
$maintenanceStatus = $conn->query("
    SELECT status, COUNT(*) as count
    FROM re_maintenance_requests
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);
$maintenanceLabels = [];
$maintenanceData = [];
$maintenanceColors = ['pending' => '#ffc107', 'in_progress' => '#0d6efd', 'completed' => '#28a745', 'cancelled' => '#dc3545'];
foreach ($maintenanceStatus as $row) {
    $maintenanceLabels[] = ucfirst(str_replace('_', ' ', $row['status']));
    $maintenanceData[] = (int)$row['count'];
}

// Chart Data: Occupancy by Building
$occupancyByBuilding = $conn->query("
    SELECT b.name as building_name,
           COUNT(u.id) as total_units,
           SUM(CASE WHEN u.status = 'occupied' THEN 1 ELSE 0 END) as occupied_units
    FROM re_buildings b
    LEFT JOIN re_units u ON u.building_id = b.id
    GROUP BY b.id, b.name
    ORDER BY b.name
")->fetchAll(PDO::FETCH_ASSOC);

// AI Smart Insights
$insights = [];

// Insight 1: Occupancy Rate
$occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;
if ($occupancyRate < 70) {
    $insights[] = [
        'type' => 'warning',
        'icon' => 'bi-exclamation-triangle',
        'title' => 'Low Occupancy Rate',
        'message' => "Current occupancy is {$occupancyRate}%. Consider marketing vacant units.",
        'priority' => 7
    ];
} elseif ($occupancyRate >= 90) {
    $insights[] = [
        'type' => 'success',
        'icon' => 'bi-check-circle',
        'title' => 'High Occupancy Rate',
        'message' => "Excellent! {$occupancyRate}% occupancy rate achieved.",
        'priority' => 3
    ];
}

// Insight 2: Pending Maintenance
if ($pendingMaintenance > 5) {
    $insights[] = [
        'type' => 'danger',
        'icon' => 'bi-tools',
        'title' => 'High Maintenance Backlog',
        'message' => "{$pendingMaintenance} pending maintenance requests need attention.",
        'priority' => 9
    ];
}

// Insight 3: Overdue Tasks
if ($overdueTasks > 0) {
    $insights[] = [
        'type' => 'danger',
        'icon' => 'bi-exclamation-circle',
        'title' => 'Overdue Tasks',
        'message' => "{$overdueTasks} task(s) are overdue. Review and prioritize.",
        'priority' => 8
    ];
}

// Insight 4: Lease Expiry (next 30 days)
$expiringLeases = $conn->prepare("
    SELECT COUNT(*) FROM re_leases
    WHERE status = 'active'
    AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");
$expiringLeases->execute();
$expiringCount = (int)$expiringLeases->fetchColumn();
if ($expiringCount > 0) {
    $insights[] = [
        'type' => 'info',
        'icon' => 'bi-calendar-event',
        'title' => 'Leases Expiring Soon',
        'message' => "{$expiringCount} lease(s) expiring in the next 30 days. Prepare renewal offers.",
        'priority' => 6
    ];
}

// Insight 5: AMC Alerts
if ($amcAlertsPending > 0) {
    $insights[] = [
        'type' => 'warning',
        'icon' => 'bi-file-earmark-check',
        'title' => 'AMC Alerts Pending',
        'message' => "{$amcAlertsPending} AMC alert(s) require attention. Review contracts and certificates.",
        'priority' => 8
    ];
}

// Insight 5: Revenue Trend
$currentMonthRevenue = end($revenueData);
$prevMonthRevenue = count($revenueData) > 1 ? $revenueData[count($revenueData) - 2] : 0;
if ($prevMonthRevenue > 0) {
    $revenueChange = (($currentMonthRevenue - $prevMonthRevenue) / $prevMonthRevenue) * 100;
    if ($revenueChange < -10) {
        $insights[] = [
            'type' => 'warning',
            'icon' => 'bi-graph-down',
            'title' => 'Revenue Decline',
            'message' => "Revenue decreased by " . abs(round($revenueChange, 1)) . "% compared to last month.",
            'priority' => 7
        ];
    } elseif ($revenueChange > 10) {
        $insights[] = [
            'type' => 'success',
            'icon' => 'bi-graph-up',
            'title' => 'Revenue Growth',
            'message' => "Revenue increased by " . round($revenueChange, 1) . "% compared to last month.",
            'priority' => 4
        ];
    }
}

// Sort insights by priority (highest first)
usort($insights, function($a, $b) {
    return $b['priority'] - $a['priority'];
});

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Dashboard';
$pageStyles = '<style>
.stat-card { border-left: 4px solid var(--primary); border-radius: 12px; transition: all 0.2s; }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
.stat-value { font-size: 2.5rem; font-weight: 700; color: var(--primary); margin: 0.5rem 0; }
.stat-label { font-size: 0.9rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
body.dark-mode .stat-card { background: #1e293b; color: #e4e4e7; border-color: #334155; }
body.dark-mode .stat-label { color: #94a3b8; }
body.dark-mode .stat-value { color: var(--accent); }
</style>';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
            <div class="d-flex align-items-center mb-4">
                <div class="page-header-label flex-grow-1">Real Estate Dashboard</div>
            </div>

            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card card-round stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="stat-label">Buildings</div>
                                    <div class="stat-value"><?= $totalBuildings ?></div>
                                </div>
                                <div class="text-primary" style="font-size: 2.5rem; opacity: 0.3;">
                                    <i class="bi bi-building"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-round stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="stat-label">Total Units</div>
                                    <div class="stat-value"><?= $totalUnits ?></div>
                                </div>
                                <div class="text-primary" style="font-size: 2.5rem; opacity: 0.3;">
                                    <i class="bi bi-door-open"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-round stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="stat-label">Occupied</div>
                                    <div class="stat-value text-success"><?= $occupiedUnits ?></div>
                                </div>
                                <div class="text-success" style="font-size: 2.5rem; opacity: 0.3;">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-round stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="stat-label">Vacant</div>
                                    <div class="stat-value text-warning"><?= $vacantUnits ?></div>
                                </div>
                                <div class="text-warning" style="font-size: 2.5rem; opacity: 0.3;">
                                    <i class="bi bi-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Second Row - Additional Statistics -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card card-round stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="stat-label">Active Leases</div>
                                    <div class="stat-value"><?= $activeLeases ?></div>
                                </div>
                                <div class="text-primary" style="font-size: 2.5rem; opacity: 0.3;">
                                    <i class="bi bi-file-text"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-round stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="stat-label">Pending Maintenance</div>
                                    <div class="stat-value text-danger"><?= $pendingMaintenance ?></div>
                                </div>
                                <div class="text-danger" style="font-size: 2.5rem; opacity: 0.3;">
                                    <i class="bi bi-tools"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-round stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="stat-label">AMC Contracts</div>
                                    <div class="stat-value"><?= number_format($amcStatistics['active_contracts'] ?? 0) ?></div>
                                    <?php if (($amcStatistics['expiring_critical'] ?? 0) > 0): ?>
                                        <small class="text-danger"><?= $amcStatistics['expiring_critical'] ?> critical</small>
                                    <?php elseif (($amcStatistics['expiring_soon'] ?? 0) > 0): ?>
                                        <small class="text-warning"><?= $amcStatistics['expiring_soon'] ?> expiring</small>
                                    <?php endif; ?>
                                </div>
                                <div class="text-warning" style="font-size: 2.5rem; opacity: 0.3;">
                                    <i class="bi bi-file-earmark-check"></i>
                                </div>
                            </div>
                            <?php if ($amcAlertsPending > 0): ?>
                                <a href="amc_alerts.php" class="btn btn-sm btn-outline-danger mt-2 w-100">
                                    <i class="bi bi-bell"></i> <?= $amcAlertsPending ?> Alerts
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-round stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="stat-label">Active Tasks</div>
                                    <div class="stat-value"><?= $activeTasks ?></div>
                                    <?php if ($overdueTasks > 0): ?>
                                        <small class="text-danger"><?= $overdueTasks ?> overdue</small>
                                    <?php endif; ?>
                                </div>
                                <div class="text-primary" style="font-size: 2.5rem; opacity: 0.3;">
                                    <i class="bi bi-list-check"></i>
                                </div>
                            </div>
                            <?php if ($overdueTasks > 0): ?>
                                <a href="tasks.php?status=pending&priority=urgent" class="btn btn-sm btn-outline-danger mt-2 w-100">View Overdue</a>
                            <?php else: ?>
                                <a href="tasks.php" class="btn btn-sm btn-outline-primary mt-2 w-100">View Tasks</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="row g-4 mb-4">
                <!-- Revenue Chart -->
                <div class="col-md-6">
                    <div class="card card-round">
                        <div class="card-header bg-white border-0 pb-2">
                            <h5 class="mb-0 fw-bold" style="color:var(--primary)">
                                <i class="bi bi-graph-up me-2"></i>Revenue Trend (Last 6 Months)
                            </h5>
                        </div>
                        <div class="card-body">
                            <canvas id="revenueChart" style="max-height: 300px;"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Maintenance Status Chart -->
                <div class="col-md-6">
                    <div class="card card-round">
                        <div class="card-header bg-white border-0 pb-2">
                            <h5 class="mb-0 fw-bold" style="color:var(--primary)">
                                <i class="bi bi-pie-chart me-2"></i>Maintenance Status
                            </h5>
                        </div>
                        <div class="card-body">
                            <canvas id="maintenanceChart" style="max-height: 300px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Occupancy Chart -->
            <div class="row g-4 mb-4">
                <div class="col-md-12">
                    <div class="card card-round">
                        <div class="card-header bg-white border-0 pb-2">
                            <h5 class="mb-0 fw-bold" style="color:var(--primary)">
                                <i class="bi bi-building me-2"></i>Occupancy by Building
                            </h5>
                        </div>
                        <div class="card-body">
                            <canvas id="occupancyChart" style="max-height: 300px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AI Smart Insights -->
            <div class="card card-round mb-4">
                <div class="card-header bg-white border-0 pb-2">
                    <h5 class="mb-0 fw-bold" style="color:var(--primary)">
                        <i class="bi bi-lightbulb-fill me-2"></i>Smart Insights
                    </h5>
                    <small class="text-muted">AI-powered recommendations based on your data</small>
                </div>
                <div class="card-body">
                    <?php if (empty($insights)): ?>
                        <div class="alert alert-success mb-0">
                            <i class="bi bi-check-circle me-2"></i>
                            <strong>All Good!</strong> No critical issues detected. Your property portfolio is performing well.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($insights as $insight): ?>
                                <div class="col-md-6">
                                    <div class="alert alert-<?= $insight['type'] ?> d-flex align-items-start mb-0">
                                        <i class="bi <?= $insight['icon'] ?> me-3 fs-5"></i>
                                        <div class="flex-grow-1">
                                            <strong><?= h($insight['title']) ?></strong>
                                            <div class="small"><?= h($insight['message']) ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Task Widgets -->
            <div class="row g-4 mb-4">
                <?php if (!empty($overdueTasksList)): ?>
                <div class="col-md-6">
                    <div class="card card-round border-danger">
                        <div class="card-header bg-danger text-white border-0">
                            <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Overdue Tasks (<?= count($overdueTasksList) ?>)</h5>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <?php foreach ($overdueTasksList as $task): ?>
                                    <a href="tasks_view.php?id=<?= $task['id'] ?>" class="list-group-item list-group-item-action border-0">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?= h($task['task_title']) ?></h6>
                                            <small class="text-danger"><?= date('M d', strtotime($task['due_date'])) ?></small>
                                        </div>
                                        <p class="mb-1 small">
                                            <span class="badge bg-<?= $task['priority'] === 'urgent' ? 'danger' : ($task['priority'] === 'high' ? 'warning' : 'info') ?>">
                                                <?= ucfirst($task['priority']) ?>
                                            </span>
                                            <?php if ($task['assigned_employee']): ?>
                                                <span class="text-muted">• <?= h($task['assigned_employee']) ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3">
                                <a href="tasks.php?status=pending" class="btn btn-sm btn-outline-danger w-100">View All Overdue Tasks</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($upcomingTasks)): ?>
                <div class="col-md-6">
                    <div class="card card-round border-warning">
                        <div class="card-header bg-warning border-0">
                            <h5 class="mb-0"><i class="bi bi-calendar-event"></i> Upcoming Tasks (Next 7 Days)</h5>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <?php foreach ($upcomingTasks as $task): ?>
                                    <a href="tasks_view.php?id=<?= $task['id'] ?>" class="list-group-item list-group-item-action border-0">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?= h($task['task_title']) ?></h6>
                                            <small class="text-muted"><?= date('M d', strtotime($task['due_date'])) ?></small>
                                        </div>
                                        <p class="mb-1 small">
                                            <span class="badge bg-<?= $task['priority'] === 'urgent' ? 'danger' : ($task['priority'] === 'high' ? 'warning' : 'info') ?>">
                                                <?= ucfirst($task['priority']) ?>
                                            </span>
                                            <?php if ($task['assigned_employee']): ?>
                                                <span class="text-muted">• <?= h($task['assigned_employee']) ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3">
                                <a href="tasks.php" class="btn btn-sm btn-outline-warning w-100">View All Tasks</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

<?php
$pageScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script><script>
(function(){
var revenueCtx = document.getElementById("revenueChart");
if (revenueCtx) {
    new Chart(revenueCtx, {
        type: "line",
        data: {
            labels: ' . json_encode($revenueLabels) . ',
            datasets: [{ label: "Revenue (AED)", data: ' . json_encode($revenueData) . ',
                borderColor: "' . addslashes($brand['primary_color']) . '",
                backgroundColor: "' . addslashes($brand['primary_color']) . '33",
                tension: 0.4, fill: true }]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return "Revenue: " + c.parsed.y.toLocaleString("en-US", {minimumFractionDigits: 2, maximumFractionDigits: 2}) + " AED"; } } } },
            scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return v.toLocaleString("en-US") + " AED"; } } } }
        }
    });
}
var maintenanceCtx = document.getElementById("maintenanceChart");
if (maintenanceCtx) {
    new Chart(maintenanceCtx, {
        type: "doughnut",
        data: { labels: ' . json_encode($maintenanceLabels) . ', datasets: [{ data: ' . json_encode($maintenanceData) . ', backgroundColor: ["#ffc107","#0d6efd","#28a745","#dc3545"] }] },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: "bottom" } } }
    });
}
var occupancyCtx = document.getElementById("occupancyChart");
if (occupancyCtx) {
    var buildingLabels = ' . json_encode(array_column($occupancyByBuilding, 'building_name')) . ';
    var totalUnits = ' . json_encode(array_column($occupancyByBuilding, 'total_units')) . ';
    var occupiedUnits = ' . json_encode(array_column($occupancyByBuilding, 'occupied_units')) . ';
    new Chart(occupancyCtx, {
        type: "bar",
        data: {
            labels: buildingLabels,
            datasets: [
                { label: "Occupied", data: occupiedUnits, backgroundColor: "' . addslashes($brand['primary_color']) . '" },
                { label: "Vacant", data: totalUnits.map(function(t,i){ return t - occupiedUnits[i]; }), backgroundColor: "#ffc107" }
            ]
        },
        options: { responsive: true, maintainAspectRatio: true, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } } }, plugins: { legend: { position: "bottom" } } }
    });
}
})();
</script>';
require_once __DIR__ . '/includes/re_layout_footer.php';
