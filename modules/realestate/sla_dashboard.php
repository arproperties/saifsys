<?php
/**
 * Real Estate Module - SLA Dashboard
 * View SLA metrics, compliance, and violations
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

// Date range filter
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-d'); // Today

// Get SLA metrics
$metrics = $conn->prepare("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN st.response_sla_met = 1 THEN 1 ELSE 0 END) as response_met,
        SUM(CASE WHEN st.response_sla_met = 0 THEN 1 ELSE 0 END) as response_violated,
        SUM(CASE WHEN st.resolution_sla_met = 1 THEN 1 ELSE 0 END) as resolution_met,
        SUM(CASE WHEN st.resolution_sla_met = 0 THEN 1 ELSE 0 END) as resolution_violated,
        AVG(st.response_time_minutes) as avg_response_minutes,
        AVG(st.resolution_time_hours) as avg_resolution_hours
    FROM re_sla_tracking st
    JOIN re_maintenance_requests mr ON mr.id = st.maintenance_request_id
    WHERE st.company_id = ? 
      AND mr.request_date >= ? 
      AND mr.request_date <= ?
");
$metrics->execute([$currentCompanyId, $dateFrom, $dateTo]);
$metrics = $metrics->fetch(PDO::FETCH_ASSOC);

// Calculate percentages
$responseCompliance = $metrics['total_requests'] > 0 
    ? round(($metrics['response_met'] / $metrics['total_requests']) * 100, 1) 
    : 0;
$resolutionCompliance = $metrics['total_requests'] > 0 
    ? round(($metrics['resolution_met'] / $metrics['total_requests']) * 100, 1) 
    : 0;

// Get violations
$violations = $conn->prepare("
    SELECT 
        mr.id,
        mr.request_date,
        mr.priority,
        mr.category,
        mr.description,
        mr.status,
        u.unit_number,
        b.name as building_name,
        st.response_sla_met,
        st.resolution_sla_met,
        st.response_time_minutes,
        st.resolution_time_hours,
        st.target_response_time,
        st.target_resolution_time,
        e.full_name as assigned_employee
    FROM re_sla_tracking st
    JOIN re_maintenance_requests mr ON mr.id = st.maintenance_request_id
    JOIN re_units u ON u.id = mr.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN employees e ON e.id = mr.assigned_to
    WHERE st.company_id = ? 
      AND mr.request_date >= ? 
      AND mr.request_date <= ?
      AND (st.response_sla_met = 0 OR st.resolution_sla_met = 0)
    ORDER BY mr.request_date DESC
    LIMIT 50
");
$violations->execute([$currentCompanyId, $dateFrom, $dateTo]);
$violations = $violations->fetchAll(PDO::FETCH_ASSOC);

// Get metrics by priority
$metricsByPriority = $conn->prepare("
    SELECT 
        mr.priority,
        COUNT(*) as total,
        SUM(CASE WHEN st.response_sla_met = 1 THEN 1 ELSE 0 END) as response_met,
        SUM(CASE WHEN st.resolution_sla_met = 1 THEN 1 ELSE 0 END) as resolution_met,
        AVG(st.response_time_minutes) as avg_response,
        AVG(st.resolution_time_hours) as avg_resolution
    FROM re_sla_tracking st
    JOIN re_maintenance_requests mr ON mr.id = st.maintenance_request_id
    WHERE st.company_id = ? 
      AND mr.request_date >= ? 
      AND mr.request_date <= ?
    GROUP BY mr.priority
    ORDER BY FIELD(mr.priority, 'urgent', 'high', 'medium', 'low')
");
$metricsByPriority->execute([$currentCompanyId, $dateFrom, $dateTo]);
$metricsByPriority = $metricsByPriority->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatMinutes($minutes) {
    if ($minutes < 60) return round($minutes) . ' min';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $hours . 'h ' . round($mins) . 'm';
}

// Set page title and include layout
$pageTitle = 'SLA Dashboard';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-speedometer2"></i> SLA Dashboard</div>
        </div>

        <!-- Date Range Filter -->
        <div class="card card-round mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary d-block">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Overall Metrics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Requests</h5>
                        <h2 class="mb-0"><?= h($metrics['total_requests'] ?: 0) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Response Compliance</h5>
                        <h2 class="mb-0 <?= $responseCompliance >= 90 ? 'text-success' : ($responseCompliance >= 70 ? 'text-warning' : 'text-danger') ?>">
                            <?= h($responseCompliance) ?>%
                        </h2>
                        <small class="text-muted">
                            <?= h($metrics['response_met'] ?: 0) ?> met / <?= h($metrics['response_violated'] ?: 0) ?> violated
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Resolution Compliance</h5>
                        <h2 class="mb-0 <?= $resolutionCompliance >= 90 ? 'text-success' : ($resolutionCompliance >= 70 ? 'text-warning' : 'text-danger') ?>">
                            <?= h($resolutionCompliance) ?>%
                        </h2>
                        <small class="text-muted">
                            <?= h($metrics['resolution_met'] ?: 0) ?> met / <?= h($metrics['resolution_violated'] ?: 0) ?> violated
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Avg Response Time</h5>
                        <h2 class="mb-0"><?= $metrics['avg_response_minutes'] ? formatMinutes($metrics['avg_response_minutes']) : '-' ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metrics by Priority -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">SLA Performance by Priority</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Priority</th>
                                <th>Total Requests</th>
                                <th>Response Compliance</th>
                                <th>Resolution Compliance</th>
                                <th>Avg Response Time</th>
                                <th>Avg Resolution Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($metricsByPriority as $mp): 
                                $respCompliance = $mp['total'] > 0 ? round(($mp['response_met'] / $mp['total']) * 100, 1) : 0;
                                $resCompliance = $mp['total'] > 0 ? round(($mp['resolution_met'] / $mp['total']) * 100, 1) : 0;
                                $priorityClass = [
                                    'low' => 'secondary',
                                    'medium' => 'info',
                                    'high' => 'warning',
                                    'urgent' => 'danger'
                                ];
                                $class = $priorityClass[$mp['priority']] ?? 'secondary';
                            ?>
                                <tr>
                                    <td><span class="badge bg-<?= $class ?>"><?= ucfirst($mp['priority']) ?></span></td>
                                    <td><?= h($mp['total']) ?></td>
                                    <td>
                                        <span class="<?= $respCompliance >= 90 ? 'text-success' : ($respCompliance >= 70 ? 'text-warning' : 'text-danger') ?>">
                                            <?= h($respCompliance) ?>%
                                        </span>
                                        <small class="text-muted">(<?= h($mp['response_met']) ?>/<?= h($mp['total']) ?>)</small>
                                    </td>
                                    <td>
                                        <span class="<?= $resCompliance >= 90 ? 'text-success' : ($resCompliance >= 70 ? 'text-warning' : 'text-danger') ?>">
                                            <?= h($resCompliance) ?>%
                                        </span>
                                        <small class="text-muted">(<?= h($mp['resolution_met']) ?>/<?= h($mp['total']) ?>)</small>
                                    </td>
                                    <td><?= $mp['avg_response'] ? formatMinutes($mp['avg_response']) : '-' ?></td>
                                    <td><?= $mp['avg_resolution'] ? round($mp['avg_resolution'], 1) . ' hours' : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- SLA Violations -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">SLA Violations</h5>
            </div>
            <div class="card-body">
                <?php if (empty($violations)): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i> No SLA violations found for the selected period!
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Date</th>
                                    <th>Priority</th>
                                    <th>Unit</th>
                                    <th>Category</th>
                                    <th>Assigned To</th>
                                    <th>Response SLA</th>
                                    <th>Resolution SLA</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($violations as $v): 
                                    $priorityClass = [
                                        'low' => 'secondary',
                                        'medium' => 'info',
                                        'high' => 'warning',
                                        'urgent' => 'danger'
                                    ];
                                    $class = $priorityClass[$v['priority']] ?? 'secondary';
                                ?>
                                    <tr>
                                        <td>#<?= h($v['id']) ?></td>
                                        <td><?= date('Y-m-d', strtotime($v['request_date'])) ?></td>
                                        <td><span class="badge bg-<?= $class ?>"><?= ucfirst($v['priority']) ?></span></td>
                                        <td><?= h($v['building_name'] . ' - ' . $v['unit_number']) ?></td>
                                        <td><?= h($v['category'] ?: '-') ?></td>
                                        <td><?= h($v['assigned_employee'] ?: '-') ?></td>
                                        <td>
                                            <?php if ($v['response_sla_met'] === 0): ?>
                                                <span class="badge bg-danger">
                                                    Violated (<?= formatMinutes($v['response_time_minutes']) ?>)
                                                </span>
                                            <?php elseif ($v['response_sla_met'] === 1): ?>
                                                <span class="badge bg-success">Met</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($v['resolution_sla_met'] === 0): ?>
                                                <span class="badge bg-danger">
                                                    Violated (<?= round($v['resolution_time_hours'], 1) ?>h)
                                                </span>
                                            <?php elseif ($v['resolution_sla_met'] === 1): ?>
                                                <span class="badge bg-success">Met</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="maintenance_view.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

