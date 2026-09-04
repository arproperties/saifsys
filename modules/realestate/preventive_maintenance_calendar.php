<?php
/**
 * Real Estate Module - Preventive Maintenance Calendar View
 * Visual calendar of scheduled maintenance tasks
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

// Get month/year from URL or use current
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Validate month/year
if ($month < 1 || $month > 12) $month = date('n');
if ($year < 2020 || $year > 2100) $year = date('Y');

// Calculate previous/next month
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Get filter parameters
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$filterAssetType = $_GET['asset_type'] ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';

// Calculate date range for the month
$firstDay = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
$lastDay = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

// Build query for tasks in this month
$where = ["t.company_id = ?", "t.due_date >= ?", "t.due_date <= ?"];
$params = [$currentCompanyId, $firstDay, $lastDay];

if ($filterBuilding) {
    $where[] = "t.building_id = ?";
    $params[] = $filterBuilding;
}

if ($filterAssetType !== 'all') {
    $where[] = "a.asset_type = ?";
    $params[] = $filterAssetType;
}

if ($filterStatus !== 'all') {
    $where[] = "t.status = ?";
    $params[] = $filterStatus;
}

// Get tasks for this month
$tasks = $conn->prepare("
    SELECT t.*, 
           s.schedule_name,
           a.asset_name, a.asset_type,
           b.name as building_name, u.unit_number,
           e.full_name as assigned_employee
    FROM re_preventive_maintenance_tasks t
    JOIN re_preventive_maintenance_schedules s ON s.id = t.schedule_id
    LEFT JOIN re_maintenance_assets a ON a.id = t.asset_id
    LEFT JOIN re_buildings b ON b.id = t.building_id
    LEFT JOIN re_units u ON u.id = t.unit_id
    LEFT JOIN employees e ON e.id = t.assigned_to
    WHERE " . implode(' AND ', $where) . "
    ORDER BY t.due_date ASC, t.status ASC
");
$tasks->execute($params);
$tasks = $tasks->fetchAll(PDO::FETCH_ASSOC);

// Organize tasks by date
$tasksByDate = [];
foreach ($tasks as $task) {
    $date = $task['due_date'];
    if (!isset($tasksByDate[$date])) {
        $tasksByDate[$date] = [];
    }
    $tasksByDate[$date][] = $task;
}

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Generate calendar
$firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = date('t', $firstDayOfMonth);
$dayOfWeek = date('w', $firstDayOfMonth); // 0 = Sunday, 6 = Saturday
$dayOfWeek = $dayOfWeek == 0 ? 7 : $dayOfWeek; // Convert to 1 = Monday, 7 = Sunday

$monthName = date('F', $firstDayOfMonth);
$yearNum = date('Y', $firstDayOfMonth);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatAssetType($type) {
    $types = [
        'ac_unit' => 'AC',
        'elevator' => 'Elevator',
        'fire_system' => 'Fire',
        'plumbing' => 'Plumbing',
        'electrical' => 'Elec',
        'hvac' => 'HVAC',
        'generator' => 'Gen',
        'pump' => 'Pump',
        'security_system' => 'Security',
        'other' => 'Other'
    ];
    return $types[$type] ?? 'Other';
}
function getTaskColor($task) {
    $isOverdue = strtotime($task['due_date']) < time() && in_array($task['status'], ['pending', 'scheduled']);
    if ($isOverdue) return 'danger';
    
    $priority = $task['priority'];
    if ($priority === 'urgent') return 'danger';
    if ($priority === 'high') return 'warning';
    if ($priority === 'medium') return 'info';
    return 'secondary';
}

// Set page title and include layout
$pageTitle = 'Maintenance Calendar';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<style>
        .calendar-day {
            min-height: 120px;
            border: 1px solid #dee2e6;
            padding: 5px;
            position: relative;
        }
        .calendar-day.other-month {
            background-color: #f8f9fa;
            color: #6c757d;
        }
        .calendar-day.today {
            background-color: #e7f3ff;
            border: 2px solid #0d6efd;
        }
        .task-item {
            font-size: 0.75rem;
            padding: 2px 4px;
            margin: 2px 0;
            border-radius: 3px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .task-item:hover {
            opacity: 0.8;
        }
        .day-number {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .task-count {
            position: absolute;
            top: 5px;
            right: 5px;
            font-size: 0.7rem;
            color: #6c757d;
        }
    </style>
<?php
// Close the style tag and continue with the page content
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-calendar"></i> Maintenance Calendar</div>
            <div>
                <a href="preventive_maintenance_tasks.php" class="btn btn-secondary">
                    <i class="bi bi-list-check"></i> List View
                </a>
            </div>
        </div>

        <!-- Filters and Navigation -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-3">
                        <div class="btn-group" role="group">
                            <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?><?= $filterBuilding ? '&building_id=' . $filterBuilding : '' ?><?= $filterAssetType !== 'all' ? '&asset_type=' . $filterAssetType : '' ?><?= $filterStatus !== 'all' ? '&status=' . $filterStatus : '' ?>" 
                               class="btn btn-outline-primary">
                                <i class="bi bi-chevron-left"></i> Previous
                            </a>
                            <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?><?= $filterBuilding ? '&building_id=' . $filterBuilding : '' ?><?= $filterAssetType !== 'all' ? '&asset_type=' . $filterAssetType : '' ?><?= $filterStatus !== 'all' ? '&status=' . $filterStatus : '' ?>" 
                               class="btn btn-outline-secondary">
                                Today
                            </a>
                            <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?><?= $filterBuilding ? '&building_id=' . $filterBuilding : '' ?><?= $filterAssetType !== 'all' ? '&asset_type=' . $filterAssetType : '' ?><?= $filterStatus !== 'all' ? '&status=' . $filterStatus : '' ?>" 
                               class="btn btn-outline-primary">
                                Next <i class="bi bi-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-6 text-center">
                        <h3 class="mb-0"><?= h($monthName) ?> <?= h($yearNum) ?></h3>
                    </div>
                    <div class="col-md-3">
                        <form method="GET" class="d-flex gap-2">
                            <input type="hidden" name="month" value="<?= $month ?>">
                            <input type="hidden" name="year" value="<?= $year ?>">
                            <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>>
                                        <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>>
                                        <?= $y ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </form>
                    </div>
                </div>
                <hr>
                <form method="GET" class="row g-3">
                    <input type="hidden" name="month" value="<?= $month ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <div class="col-md-4">
                        <label class="form-label">Building</label>
                        <select name="building_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $filterBuilding == $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Asset Type</label>
                        <select name="asset_type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $filterAssetType === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="ac_unit" <?= $filterAssetType === 'ac_unit' ? 'selected' : '' ?>>AC Unit</option>
                            <option value="elevator" <?= $filterAssetType === 'elevator' ? 'selected' : '' ?>>Elevator</option>
                            <option value="fire_system" <?= $filterAssetType === 'fire_system' ? 'selected' : '' ?>>Fire System</option>
                            <option value="plumbing" <?= $filterAssetType === 'plumbing' ? 'selected' : '' ?>>Plumbing</option>
                            <option value="electrical" <?= $filterAssetType === 'electrical' ? 'selected' : '' ?>>Electrical</option>
                            <option value="hvac" <?= $filterAssetType === 'hvac' ? 'selected' : '' ?>>HVAC</option>
                            <option value="generator" <?= $filterAssetType === 'generator' ? 'selected' : '' ?>>Generator</option>
                            <option value="pump" <?= $filterAssetType === 'pump' ? 'selected' : '' ?>>Pump</option>
                            <option value="security_system" <?= $filterAssetType === 'security_system' ? 'selected' : '' ?>>Security System</option>
                            <option value="other" <?= $filterAssetType === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="scheduled" <?= $filterStatus === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                            <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Calendar -->
        <div class="card">
            <div class="card-body">
                <table class="table table-bordered" style="table-layout: fixed;">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 14.28%;">Monday</th>
                            <th class="text-center" style="width: 14.28%;">Tuesday</th>
                            <th class="text-center" style="width: 14.28%;">Wednesday</th>
                            <th class="text-center" style="width: 14.28%;">Thursday</th>
                            <th class="text-center" style="width: 14.28%;">Friday</th>
                            <th class="text-center" style="width: 14.28%;">Saturday</th>
                            <th class="text-center" style="width: 14.28%;">Sunday</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $currentDate = 1;
                        $today = date('Y-m-d');
                        
                        // Start from the first day of the week
                        $startDate = 1 - ($dayOfWeek - 1);
                        if ($startDate < 1) {
                            // Previous month days
                            $prevMonthDays = date('t', mktime(0, 0, 0, $month - 1, 1, $year));
                            $startDate = $prevMonthDays + $startDate;
                        }
                        
                        for ($week = 0; $week < 6; $week++):
                            if ($currentDate > $daysInMonth && $startDate > $daysInMonth) break;
                        ?>
                            <tr>
                                <?php for ($day = 0; $day < 7; $day++): 
                                    $dayDate = $startDate + $day;
                                    $isOtherMonth = false;
                                    
                                    if ($dayDate < 1) {
                                        // Previous month
                                        $prevMonthDays = date('t', mktime(0, 0, 0, $month - 1, 1, $year));
                                        $displayDate = $prevMonthDays + $dayDate;
                                        $isOtherMonth = true;
                                        $dateStr = date('Y-m-d', mktime(0, 0, 0, $month - 1, $displayDate, $year));
                                    } elseif ($dayDate > $daysInMonth) {
                                        // Next month
                                        $displayDate = $dayDate - $daysInMonth;
                                        $isOtherMonth = true;
                                        $dateStr = date('Y-m-d', mktime(0, 0, 0, $month + 1, $displayDate, $year));
                                    } else {
                                        $displayDate = $dayDate;
                                        $dateStr = date('Y-m-d', mktime(0, 0, 0, $month, $displayDate, $year));
                                    }
                                    
                                    $isToday = $dateStr === $today;
                                    $dayTasks = $tasksByDate[$dateStr] ?? [];
                                ?>
                                    <td class="calendar-day <?= $isOtherMonth ? 'other-month' : '' ?> <?= $isToday ? 'today' : '' ?>">
                                        <div class="day-number">
                                            <?= $displayDate ?>
                                            <?php if ($isToday): ?>
                                                <span class="badge bg-primary">Today</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (count($dayTasks) > 0): ?>
                                            <span class="task-count"><?= count($dayTasks) ?> task(s)</span>
                                            <div class="tasks-list">
                                                <?php foreach ($dayTasks as $task): 
                                                    $color = getTaskColor($task);
                                                    $isOverdue = strtotime($task['due_date']) < time() && in_array($task['status'], ['pending', 'scheduled']);
                                                ?>
                                                    <div class="task-item bg-<?= $color ?> text-white" 
                                                         onclick="viewTask(<?= $task['id'] ?>)"
                                                         title="<?= h($task['task_name']) ?>">
                                                        <?php if ($isOverdue): ?>
                                                            <strong>⚠️ </strong>
                                                        <?php endif; ?>
                                                        <?= h($task['asset_name'] ?: formatAssetType($task['asset_type'])) ?>
                                                        - <?= h(substr($task['schedule_name'], 0, 20)) ?>
                                                        <?= strlen($task['schedule_name']) > 20 ? '...' : '' ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                            <?php
                            $startDate += 7;
                            if ($startDate > $daysInMonth && !$isOtherMonth) break;
                        endfor;
                        ?>
                    </tbody>
                </table>

                <!-- Legend -->
                <div class="mt-3">
                    <h6>Legend:</h6>
                    <div class="d-flex gap-3 flex-wrap">
                        <span><span class="badge bg-danger">Urgent/Overdue</span></span>
                        <span><span class="badge bg-warning">High Priority</span></span>
                        <span><span class="badge bg-info">Medium Priority</span></span>
                        <span><span class="badge bg-secondary">Low Priority</span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Task Details Modal -->
    <div class="modal fade" id="taskDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Task Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="taskDetailsContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" id="viewTaskLink" class="btn btn-primary" target="_blank">
                        <i class="bi bi-eye"></i> View Full Details
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewTask(taskId) {
            fetch(`ajax_get_task_details.php?task_id=${taskId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const task = data.task;
                        let html = `
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Task Name:</strong><br>
                                    ${escapeHtml(task.task_name)}
                                </div>
                                <div class="col-md-6">
                                    <strong>Schedule:</strong><br>
                                    ${escapeHtml(task.schedule_name)}
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Due Date:</strong><br>
                                    ${new Date(task.due_date).toLocaleDateString()}
                                </div>
                                <div class="col-md-6">
                                    <strong>Status:</strong><br>
                                    <span class="badge bg-${getStatusColor(task.status)}">${task.status}</span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <strong>Description:</strong><br>
                                ${escapeHtml(task.task_description || task.schedule_description || '-')}
                            </div>
                            ${task.asset_name ? `
                                <div class="mb-3">
                                    <strong>Asset:</strong><br>
                                    ${escapeHtml(task.asset_name)} (${escapeHtml(task.asset_type || '')})
                                </div>
                            ` : ''}
                            ${task.building_name ? `
                                <div class="mb-3">
                                    <strong>Location:</strong><br>
                                    ${escapeHtml(task.building_name)}${task.unit_number ? ' - Unit ' + escapeHtml(task.unit_number) : ''}
                                </div>
                            ` : ''}
                            ${task.assigned_employee ? `
                                <div class="mb-3">
                                    <strong>Assigned To:</strong><br>
                                    ${escapeHtml(task.assigned_employee)}
                                </div>
                            ` : ''}
                        `;
                        document.getElementById('taskDetailsContent').innerHTML = html;
                        document.getElementById('viewTaskLink').href = `preventive_maintenance_tasks.php?id=${taskId}`;
                        const modal = new bootstrap.Modal(document.getElementById('taskDetailsModal'));
                        modal.show();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('taskDetailsContent').innerHTML = '<div class="alert alert-danger">Error loading task details</div>';
                });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getStatusColor(status) {
            const colors = {
                'pending': 'warning',
                'scheduled': 'primary',
                'in_progress': 'info',
                'completed': 'success',
                'skipped': 'secondary'
            };
            return colors[status] || 'secondary';
        }
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

