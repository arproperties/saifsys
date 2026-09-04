<?php
/**
 * Real Estate Module - Maintenance by Building Report
 * Maintenance costs and requests grouped by building
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$exportFormat = $_GET['export'] ?? '';

// Build query - Show all maintenance regardless of company (same fix as other reports)
$where = ["mr.request_date >= ?", "mr.request_date <= ?"];
$params = [$dateFrom, $dateTo];

// Get maintenance by building
$byBuilding = $conn->prepare("
    SELECT 
        b.id,
        b.name as building_name,
        COUNT(*) as total_requests,
        SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
        SUM(CASE WHEN mr.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN mr.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(mr.cost) as total_cost,
        AVG(CASE WHEN mr.status = 'completed' AND mr.completed_at IS NOT NULL 
            THEN TIMESTAMPDIFF(HOUR, mr.created_at, mr.completed_at) ELSE NULL END) as avg_resolution_hours
    FROM re_maintenance_requests mr
    LEFT JOIN re_units u ON u.id = mr.unit_id
    LEFT JOIN re_buildings bu ON bu.id = u.building_id
    JOIN re_buildings b ON b.id = COALESCE(mr.building_id, u.building_id)
    WHERE " . implode(' AND ', $where) . "
    GROUP BY b.id, b.name
    ORDER BY total_cost DESC, total_requests DESC
");
$byBuilding->execute($params);
$byBuilding = $byBuilding->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'total_requests' => 0,
    'total_cost' => 0,
    'avg_resolution_hours' => 0
];

$totalHours = 0;
$completedBuildings = 0;

foreach ($byBuilding as $row) {
    $totals['total_requests'] += (int)$row['total_requests'];
    $totals['total_cost'] += (float)($row['total_cost'] ?: 0);
    if ($row['avg_resolution_hours'] !== null) {
        $totalHours += (float)$row['avg_resolution_hours'];
        $completedBuildings++;
    }
}

$totals['avg_resolution_hours'] = $completedBuildings > 0 ? round($totalHours / $completedBuildings, 1) : 0;

// Handle export
if ($exportFormat === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="maintenance_by_building_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Maintenance by Building Report - ' . date('M d, Y', strtotime($dateFrom)) . ' to ' . date('M d, Y', strtotime($dateTo))]);
    fputcsv($output, []);
    fputcsv($output, ['Building', 'Total Requests', 'Pending', 'In Progress', 'Completed', 'Cancelled', 'Total Cost', 'Avg Resolution (Hours)']);
    
    foreach ($byBuilding as $row) {
        fputcsv($output, [
            $row['building_name'],
            $row['total_requests'],
            $row['pending_count'],
            $row['in_progress_count'],
            $row['completed_count'],
            $row['cancelled_count'],
            number_format($row['total_cost'] ?: 0, 2),
            $row['avg_resolution_hours'] ? number_format($row['avg_resolution_hours'], 1) : 'N/A'
        ]);
    }
    
    fclose($output);
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Maintenance by Building Report';
$pageStyles = '@media print { .no-print { display: none; } }';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1><i class="bi bi-building"></i> Maintenance by Building Report</h1>
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
                    <div class="col-md-4">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-funnel"></i> Generate Report
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Requests</h5>
                        <h2 class="mb-0"><?= $totals['total_requests'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Cost</h5>
                        <h2 class="mb-0"><?= number_format($totals['total_cost'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Avg Resolution Time</h5>
                        <h2 class="mb-0"><?= $totals['avg_resolution_hours'] ?> hrs</h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Maintenance by Building Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Maintenance by Building (<?= count($byBuilding) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Building</th>
                                <th class="text-center">Total Requests</th>
                                <th class="text-center">Pending</th>
                                <th class="text-center">In Progress</th>
                                <th class="text-center">Completed</th>
                                <th class="text-center">Cancelled</th>
                                <th class="text-end">Total Cost</th>
                                <th class="text-center">Avg Resolution</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($byBuilding)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No maintenance data found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($byBuilding as $row): ?>
                                    <tr>
                                        <td><?= h($row['building_name']) ?></td>
                                        <td class="text-center"><?= $row['total_requests'] ?></td>
                                        <td class="text-center text-warning"><?= $row['pending_count'] ?></td>
                                        <td class="text-center text-primary"><?= $row['in_progress_count'] ?></td>
                                        <td class="text-center text-success"><?= $row['completed_count'] ?></td>
                                        <td class="text-center"><?= $row['cancelled_count'] ?></td>
                                        <td class="text-end"><?= number_format($row['total_cost'] ?: 0, 2) ?> AED</td>
                                        <td class="text-center"><?= $row['avg_resolution_hours'] ? number_format($row['avg_resolution_hours'], 1) . ' hrs' : 'N/A' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td class="text-end">TOTALS:</td>
                                    <td class="text-center"><?= $totals['total_requests'] ?></td>
                                    <td colspan="4"></td>
                                    <td class="text-end"><?= number_format($totals['total_cost'], 2) ?> AED</td>
                                    <td class="text-center"><?= $totals['avg_resolution_hours'] ?> hrs</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

