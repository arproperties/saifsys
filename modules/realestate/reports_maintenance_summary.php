<?php
/**
 * Real Estate Module - Maintenance Summary Report
 * Summary of all maintenance requests with status, costs, and performance
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/maintenance_location_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = re_maint_require_company($conn);

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$filterStatus = $_GET['status'] ?? 'all';
$exportFormat = $_GET['export'] ?? '';

// Build query
$where = ["mr.company_id = ?", "mr.request_date >= ?", "mr.request_date <= ?"];
$params = [$currentCompanyId, $dateFrom, $dateTo];

if ($filterBuilding) {
    $where[] = "b.id = ?";
    $params[] = $filterBuilding;
}

if ($filterStatus !== 'all') {
    $where[] = "mr.status = ?";
    $params[] = $filterStatus;
}

// Get maintenance summary
$maintenance = $conn->prepare("
    SELECT 
        mr.*,
        b.name as building_name,
        u.unit_number,
        ca.area_name AS common_area_name,
        t.first_name,
        t.last_name,
        e.full_name as assigned_employee,
        TIMESTAMPDIFF(HOUR, mr.created_at, COALESCE(mr.completed_at, NOW())) as hours_elapsed
    FROM re_maintenance_requests mr
    LEFT JOIN re_units u ON u.id = mr.unit_id
    LEFT JOIN re_buildings bu ON bu.id = u.building_id
    LEFT JOIN re_buildings b ON b.id = COALESCE(mr.building_id, u.building_id)
    LEFT JOIN re_building_common_areas ca ON ca.id = mr.common_area_id
    LEFT JOIN re_tenants t ON t.id = mr.tenant_id
    LEFT JOIN employees e ON e.id = mr.assigned_to
    WHERE " . implode(' AND ', $where) . "
    ORDER BY mr.request_date DESC
");
$maintenance->execute($params);
$maintenance = $maintenance->fetchAll(PDO::FETCH_ASSOC);
foreach ($maintenance as &$mRow) {
    $mRow['location_label'] = re_maint_location_label(
        $mRow['building_name'] ?? null,
        (string)($mRow['location_type'] ?? 'unit'),
        $mRow['unit_number'] ?? null,
        $mRow['common_area_name'] ?? null
    );
}
unset($mRow);

// Calculate statistics
$stats = [
    'total' => count($maintenance),
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'total_cost' => 0,
    'avg_resolution_hours' => 0
];

$totalHours = 0;
$completedCount = 0;

foreach ($maintenance as $req) {
    if ($req['status'] === 'pending') $stats['pending']++;
    if ($req['status'] === 'in_progress') $stats['in_progress']++;
    if ($req['status'] === 'completed') {
        $stats['completed']++;
        $totalHours += (float)$req['hours_elapsed'];
        $completedCount++;
    }
    if ($req['status'] === 'cancelled') $stats['cancelled']++;
    $stats['total_cost'] += (float)($req['cost'] ?: 0);
}

$stats['avg_resolution_hours'] = $completedCount > 0 ? round($totalHours / $completedCount, 1) : 0;

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if ($exportFormat === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="maintenance_summary_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Request ID', 'Date', 'Location', 'Tenant', 'Priority', 'Category',
                      'Description', 'Status', 'Assigned To', 'Cost', 'Hours Elapsed']);
    
    foreach ($maintenance as $req) {
        fputcsv($output, [
            $req['id'],
            date('Y-m-d', strtotime($req['request_date'])),
            $req['location_label'] ?: 'N/A',
            ($req['first_name'] ? $req['first_name'] . ' ' . $req['last_name'] : 'N/A'),
            ucfirst($req['priority']),
            $req['category'] ?: 'N/A',
            $req['description'],
            ucfirst($req['status']),
            $req['assigned_employee'] ?: 'N/A',
            number_format($req['cost'] ?: 0, 2),
            $req['hours_elapsed'] ?: 'N/A'
        ]);
    }
    
    fclose($output);
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Maintenance Summary Report';
$pageStyles = '@media print { .no-print { display: none; } }';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1><i class="bi bi-clipboard-data"></i> Maintenance Summary Report</h1>
            <div>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4 no-print">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Building</label>
                        <select name="building_id" class="form-select form-select-sm">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $filterBuilding == $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-funnel"></i> Generate Report
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total</h5>
                        <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Pending</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['pending'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">In Progress</h5>
                        <h2 class="mb-0 text-primary"><?= $stats['in_progress'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Completed</h5>
                        <h2 class="mb-0 text-success"><?= $stats['completed'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Cost</h5>
                        <h2 class="mb-0"><?= number_format($stats['total_cost'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Avg Resolution</h5>
                        <h2 class="mb-0"><?= $stats['avg_resolution_hours'] ?> hrs</h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Maintenance Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Maintenance Requests (<?= count($maintenance) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Location</th>
                                <th>Tenant</th>
                                <th>Priority</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Cost</th>
                                <th>Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($maintenance)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No maintenance requests found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($maintenance as $req): ?>
                                    <tr>
                                        <td>#<?= $req['id'] ?></td>
                                        <td><?= date('M d, Y', strtotime($req['request_date'])) ?></td>
                                        <td><?= h($req['location_label'] ?: '-') ?></td>
                                        <td><?= $req['first_name'] ? h($req['first_name'] . ' ' . $req['last_name']) : '-' ?></td>
                                        <td>
                                            <span class="badge bg-<?= $req['priority'] === 'urgent' ? 'danger' : ($req['priority'] === 'high' ? 'warning' : 'info') ?>">
                                                <?= ucfirst($req['priority']) ?>
                                            </span>
                                        </td>
                                        <td><?= h($req['category'] ?: '-') ?></td>
                                        <td><?= h(substr($req['description'], 0, 50)) ?><?= strlen($req['description']) > 50 ? '...' : '' ?></td>
                                        <td>
                                            <span class="badge bg-<?= $req['status'] === 'completed' ? 'success' : ($req['status'] === 'pending' ? 'warning' : 'primary') ?>">
                                                <?= ucfirst(str_replace('_', ' ', $req['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= h($req['assigned_employee'] ?: '-') ?></td>
                                        <td class="text-end"><?= number_format($req['cost'] ?: 0, 2) ?> AED</td>
                                        <td class="text-center"><?= $req['hours_elapsed'] ? round($req['hours_elapsed']) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td colspan="10" class="text-end">TOTALS:</td>
                                    <td class="text-end"><?= number_format($stats['total_cost'], 2) ?> AED</td>
                                    <td></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

