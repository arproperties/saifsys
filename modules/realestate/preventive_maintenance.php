<?php
/**
 * Real Estate Module - Preventive Maintenance Dashboard
 * Enterprise-grade facility management preventive maintenance
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

// Get statistics
$stats = [];

// Total assets
$stmt = $conn->prepare("SELECT COUNT(*) FROM re_maintenance_assets WHERE company_id = ? AND is_active = 1");
$stmt->execute([$currentCompanyId]);
$stats['total_assets'] = (int)$stmt->fetchColumn();

// Active schedules
$stmt = $conn->prepare("SELECT COUNT(*) FROM re_preventive_maintenance_schedules WHERE company_id = ? AND is_active = 1");
$stmt->execute([$currentCompanyId]);
$stats['active_schedules'] = (int)$stmt->fetchColumn();

// Pending tasks
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM re_preventive_maintenance_tasks 
    WHERE company_id = ? AND status IN ('pending', 'scheduled') AND due_date >= CURDATE()
");
$stmt->execute([$currentCompanyId]);
$stats['pending_tasks'] = (int)$stmt->fetchColumn();

// Overdue tasks
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM re_preventive_maintenance_tasks 
    WHERE company_id = ? AND status IN ('pending', 'scheduled') AND due_date < CURDATE()
");
$stmt->execute([$currentCompanyId]);
$stats['overdue_tasks'] = (int)$stmt->fetchColumn();

// Completed this month
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM re_preventive_maintenance_tasks 
    WHERE company_id = ? AND status = 'completed' 
    AND MONTH(completed_date) = MONTH(CURDATE()) 
    AND YEAR(completed_date) = YEAR(CURDATE())
");
$stmt->execute([$currentCompanyId]);
$stats['completed_this_month'] = (int)$stmt->fetchColumn();

// Upcoming tasks (next 7 days)
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM re_preventive_maintenance_tasks 
    WHERE company_id = ? AND status IN ('pending', 'scheduled')
    AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
");
$stmt->execute([$currentCompanyId]);
$stats['upcoming_7_days'] = (int)$stmt->fetchColumn();

// Get upcoming tasks
$upcomingTasks = $conn->prepare("
    SELECT t.*, s.schedule_name, a.asset_name, a.asset_type,
           b.name as building_name, u.unit_number,
           e.full_name as assigned_employee
    FROM re_preventive_maintenance_tasks t
    JOIN re_preventive_maintenance_schedules s ON s.id = t.schedule_id
    LEFT JOIN re_maintenance_assets a ON a.id = t.asset_id
    LEFT JOIN re_buildings b ON b.id = t.building_id
    LEFT JOIN re_units u ON u.id = t.unit_id
    LEFT JOIN employees e ON e.id = t.assigned_to
    WHERE t.company_id = ? AND t.status IN ('pending', 'scheduled')
    AND t.due_date >= CURDATE()
    ORDER BY t.due_date ASC
    LIMIT 10
");
$upcomingTasks->execute([$currentCompanyId]);
$upcomingTasks = $upcomingTasks->fetchAll(PDO::FETCH_ASSOC);

// Get overdue tasks
$overdueTasks = $conn->prepare("
    SELECT t.*, s.schedule_name, a.asset_name, a.asset_type,
           b.name as building_name, u.unit_number,
           e.full_name as assigned_employee
    FROM re_preventive_maintenance_tasks t
    JOIN re_preventive_maintenance_schedules s ON s.id = t.schedule_id
    LEFT JOIN re_maintenance_assets a ON a.id = t.asset_id
    LEFT JOIN re_buildings b ON b.id = t.building_id
    LEFT JOIN re_units u ON u.id = t.unit_id
    LEFT JOIN employees e ON e.id = t.assigned_to
    WHERE t.company_id = ? AND t.status IN ('pending', 'scheduled')
    AND t.due_date < CURDATE()
    ORDER BY t.due_date ASC
    LIMIT 10
");
$overdueTasks->execute([$currentCompanyId]);
$overdueTasks = $overdueTasks->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatAssetType($type) {
    $types = [
        'ac_unit' => 'AC Unit',
        'elevator' => 'Elevator',
        'fire_system' => 'Fire System',
        'plumbing' => 'Plumbing',
        'electrical' => 'Electrical',
        'hvac' => 'HVAC',
        'generator' => 'Generator',
        'pump' => 'Pump',
        'security_system' => 'Security System',
        'other' => 'Other'
    ];
    return $types[$type] ?? ucfirst($type);
}

// Set page title and include layout
$pageTitle = 'Preventive Maintenance';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-calendar-check"></i> Preventive Maintenance</div>
            <div>
                <a href="preventive_maintenance_schedules.php?action=add" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> New Schedule
                </a>
                <a href="preventive_maintenance_assets.php?action=add" class="btn btn-secondary">
                    <i class="bi bi-tools"></i> Manage Assets
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Assets</h5>
                        <h2 class="mb-0"><?= $stats['total_assets'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Active Schedules</h5>
                        <h2 class="mb-0"><?= $stats['active_schedules'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Pending Tasks</h5>
                        <h2 class="mb-0"><?= $stats['pending_tasks'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Overdue</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['overdue_tasks'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Upcoming (7 days)</h5>
                        <h2 class="mb-0"><?= $stats['upcoming_7_days'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Completed (Month)</h5>
                        <h2 class="mb-0 text-success"><?= $stats['completed_this_month'] ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Overdue Tasks -->
            <div class="col-md-6 mb-4">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Overdue Tasks</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($overdueTasks)): ?>
                            <div class="alert alert-success mb-0">
                                <i class="bi bi-check-circle"></i> No overdue tasks!
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Task</th>
                                            <th>Due Date</th>
                                            <th>Asset</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($overdueTasks as $task): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= h($task['task_name']) ?></strong><br>
                                                    <small class="text-muted"><?= h($task['schedule_name']) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger">
                                                        <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($task['asset_name']): ?>
                                                        <?= h($task['asset_name']) ?><br>
                                                        <small class="text-muted"><?= formatAssetType($task['asset_type']) ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="preventive_maintenance_tasks.php?id=<?= $task['id'] ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-center mt-2">
                                <a href="preventive_maintenance_tasks.php?status=overdue" class="btn btn-sm btn-outline-danger">
                                    View All Overdue Tasks
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Upcoming Tasks -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-calendar-event"></i> Upcoming Tasks (Next 10)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($upcomingTasks)): ?>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle"></i> No upcoming tasks scheduled.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Task</th>
                                            <th>Due Date</th>
                                            <th>Asset</th>
                                            <th>Assigned To</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcomingTasks as $task): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= h($task['task_name']) ?></strong><br>
                                                    <small class="text-muted"><?= h($task['schedule_name']) ?></small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $daysUntil = (strtotime($task['due_date']) - time()) / 86400;
                                                    $badgeClass = $daysUntil <= 1 ? 'bg-warning' : ($daysUntil <= 3 ? 'bg-info' : 'bg-secondary');
                                                    ?>
                                                    <span class="badge <?= $badgeClass ?>">
                                                        <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($task['asset_name']): ?>
                                                        <?= h($task['asset_name']) ?><br>
                                                        <small class="text-muted"><?= formatAssetType($task['asset_type']) ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= h($task['assigned_employee'] ?: '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-center mt-2">
                                <a href="preventive_maintenance_tasks.php" class="btn btn-sm btn-outline-primary">
                                    View All Tasks
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header">
                <h5>Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <a href="preventive_maintenance_assets.php" class="btn btn-primary w-100">
                            <i class="bi bi-tools"></i> Manage Assets
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="preventive_maintenance_schedules.php" class="btn btn-primary w-100">
                            <i class="bi bi-calendar-event"></i> Manage Schedules
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="preventive_maintenance_tasks.php" class="btn btn-primary w-100">
                            <i class="bi bi-list-check"></i> View Tasks
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="preventive_maintenance_calendar.php" class="btn btn-primary w-100">
                            <i class="bi bi-calendar"></i> Calendar View
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="preventive_maintenance_history.php" class="btn btn-secondary w-100">
                            <i class="bi bi-clock-history"></i> Maintenance History
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="preventive_maintenance_templates.php" class="btn btn-secondary w-100">
                            <i class="bi bi-file-earmark-text"></i> Templates
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>


<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

