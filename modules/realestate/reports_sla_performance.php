<?php
/**
 * Real Estate Module - SLA Performance Report
 * SLA compliance and violation analysis
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

// Get SLA performance data
$slaData = $conn->prepare("
    SELECT 
        mr.id,
        mr.request_date,
        mr.priority,
        mr.category,
        mr.status,
        b.name as building_name,
        u.unit_number,
        st.response_sla_met,
        st.resolution_sla_met,
        st.response_time_minutes,
        st.resolution_time_hours,
        sr.response_time_minutes as target_response_minutes,
        sr.resolution_time_hours as target_resolution_hours,
        CASE 
            WHEN st.response_sla_met = 0 THEN 'Response Violation'
            WHEN st.resolution_sla_met = 0 THEN 'Resolution Violation'
            WHEN st.response_sla_met IS NULL AND st.resolution_sla_met IS NULL THEN 'No SLA Tracking'
            ELSE 'Compliant'
        END as sla_status
    FROM re_maintenance_requests mr
    LEFT JOIN re_units u ON u.id = mr.unit_id
    LEFT JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_sla_tracking st ON st.maintenance_request_id = mr.id
    LEFT JOIN re_sla_rules sr ON sr.id = st.sla_rule_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY mr.request_date DESC
");
$slaData->execute($params);
$slaData = $slaData->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$stats = [
    'total' => count($slaData),
    'compliant' => 0,
    'response_violations' => 0,
    'resolution_violations' => 0,
    'no_tracking' => 0,
    'avg_response_time' => 0,
    'avg_resolution_time' => 0
];

$totalResponseTime = 0;
$responseCount = 0;
$totalResolutionTime = 0;
$resolutionCount = 0;

foreach ($slaData as $row) {
    if ($row['sla_status'] === 'Compliant') $stats['compliant']++;
    elseif ($row['sla_status'] === 'Response Violation') $stats['response_violations']++;
    elseif ($row['sla_status'] === 'Resolution Violation') $stats['resolution_violations']++;
    elseif ($row['sla_status'] === 'No SLA Tracking') $stats['no_tracking']++;
    
    if ($row['response_time_minutes'] !== null) {
        $totalResponseTime += (float)$row['response_time_minutes'];
        $responseCount++;
    }
    if ($row['resolution_time_hours'] !== null) {
        $totalResolutionTime += (float)$row['resolution_time_hours'];
        $resolutionCount++;
    }
}

$stats['avg_response_time'] = $responseCount > 0 ? round($totalResponseTime / $responseCount, 1) : 0;
$stats['avg_resolution_time'] = $resolutionCount > 0 ? round($totalResolutionTime / $resolutionCount, 1) : 0;
$stats['compliance_rate'] = $stats['total'] > 0 ? round(($stats['compliant'] / $stats['total']) * 100, 1) : 0;

// Handle export
if ($exportFormat === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sla_performance_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['SLA Performance Report - ' . date('M d, Y', strtotime($dateFrom)) . ' to ' . date('M d, Y', strtotime($dateTo))]);
    fputcsv($output, []);
    fputcsv($output, ['Request ID', 'Date', 'Priority', 'Category', 'Building', 'Unit', 'Status', 
                      'Response SLA Met', 'Resolution SLA Met', 'Response Time (min)', 'Resolution Time (hrs)',
                      'Target Response (min)', 'Target Resolution (hrs)', 'SLA Status']);
    
    foreach ($slaData as $row) {
        fputcsv($output, [
            $row['id'],
            date('Y-m-d', strtotime($row['request_date'])),
            ucfirst($row['priority']),
            $row['category'] ?: 'N/A',
            $row['building_name'] ?: 'N/A',
            $row['unit_number'] ?: 'N/A',
            ucfirst($row['status']),
            $row['response_sla_met'] === null ? 'N/A' : ($row['response_sla_met'] ? 'Yes' : 'No'),
            $row['resolution_sla_met'] === null ? 'N/A' : ($row['resolution_sla_met'] ? 'Yes' : 'No'),
            $row['response_time_minutes'] ?: 'N/A',
            $row['resolution_time_hours'] ?: 'N/A',
            $row['target_response_minutes'] ?: 'N/A',
            $row['target_resolution_hours'] ?: 'N/A',
            $row['sla_status']
        ]);
    }
    
    fclose($output);
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'SLA Performance Report';
$pageStyles = '@media print { .no-print { display: none; } }';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1><i class="bi bi-speedometer2"></i> SLA Performance Report</h1>
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
                <?php if ($stats['no_tracking'] > 0): ?>
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle"></i> <strong>Note:</strong> <?= $stats['no_tracking'] ?> request(s) have no SLA tracking. 
                        These are likely older requests created before SLA tracking was enabled. 
                        <a href="backfill_sla_tracking.php" class="alert-link">Click here to backfill SLA tracking for existing requests</a>.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Requests</h5>
                        <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Compliant</h5>
                        <h2 class="mb-0 text-success"><?= $stats['compliant'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Compliance Rate</h5>
                        <h2 class="mb-0 text-primary"><?= $stats['compliance_rate'] ?>%</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Violations</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['response_violations'] + $stats['resolution_violations'] ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Violation Breakdown -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Response Violations</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['response_violations'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Resolution Violations</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['resolution_violations'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">No SLA Tracking</h5>
                        <h2 class="mb-0 text-secondary"><?= $stats['no_tracking'] ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- SLA Performance Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">SLA Performance Details (<?= count($slaData) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Request ID</th>
                                <th>Date</th>
                                <th>Priority</th>
                                <th>Category</th>
                                <th>Building</th>
                                <th>Unit</th>
                                <th>Status</th>
                                <th class="text-center">Response SLA</th>
                                <th class="text-center">Resolution SLA</th>
                                <th class="text-center">Response Time</th>
                                <th class="text-center">Resolution Time</th>
                                <th>SLA Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($slaData)): ?>
                                <tr>
                                    <td colspan="12" class="text-center text-muted">No SLA data found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($slaData as $row): ?>
                                    <tr class="<?= $row['sla_status'] === 'Response Violation' || $row['sla_status'] === 'Resolution Violation' ? 'table-warning' : '' ?>">
                                        <td>#<?= $row['id'] ?></td>
                                        <td><?= date('M d, Y', strtotime($row['request_date'])) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $row['priority'] === 'urgent' ? 'danger' : ($row['priority'] === 'high' ? 'warning' : 'info') ?>">
                                                <?= ucfirst($row['priority']) ?>
                                            </span>
                                        </td>
                                        <td><?= h($row['category'] ?: '-') ?></td>
                                        <td><?= h($row['building_name'] ?: '-') ?></td>
                                        <td><?= h($row['unit_number'] ?: '-') ?></td>
                                        <td>
                                            <span class="badge bg-<?= $row['status'] === 'completed' ? 'success' : ($row['status'] === 'pending' ? 'warning' : 'primary') ?>">
                                                <?= ucfirst(str_replace('_', ' ', $row['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($row['response_sla_met'] === null): ?>
                                                <span class="text-muted">N/A</span>
                                            <?php elseif ($row['response_sla_met']): ?>
                                                <span class="badge bg-success">Yes</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($row['resolution_sla_met'] === null): ?>
                                                <span class="text-muted">N/A</span>
                                            <?php elseif ($row['resolution_sla_met']): ?>
                                                <span class="badge bg-success">Yes</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($row['response_time_minutes'] !== null): ?>
                                                <?= $row['response_time_minutes'] ?> min
                                                <?php if ($row['target_response_minutes']): ?>
                                                    <br><small class="text-muted">Target: <?= $row['target_response_minutes'] ?> min</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($row['resolution_time_hours'] !== null): ?>
                                                <?= number_format($row['resolution_time_hours'], 1) ?> hrs
                                                <?php if ($row['target_resolution_hours']): ?>
                                                    <br><small class="text-muted">Target: <?= $row['target_resolution_hours'] ?> hrs</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['sla_status'] === 'Compliant'): ?>
                                                <span class="badge bg-success">Compliant</span>
                                            <?php elseif ($row['sla_status'] === 'Response Violation'): ?>
                                                <span class="badge bg-warning">Response Violation</span>
                                            <?php elseif ($row['sla_status'] === 'Resolution Violation'): ?>
                                                <span class="badge bg-danger">Resolution Violation</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No Tracking</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

