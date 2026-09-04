<?php
/**
 * Real Estate Module - Preventive Maintenance Tasks Management
 * View and manage generated preventive maintenance tasks
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/preventive_maintenance_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_request') {
            $taskId = (int)$_POST['task_id'];
            try {
                $requestId = create_maintenance_request_from_task($conn, $taskId, $userId);
                if ($requestId) {
                    $success = "Maintenance request created successfully. <a href='maintenance_view.php?id={$requestId}'>View Request</a>";
                } else {
                    $error = "Failed to create maintenance request";
                }
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'update_task') {
            $taskId = (int)$_POST['task_id'];
            $scheduledDate = !empty($_POST['scheduled_date']) ? $_POST['scheduled_date'] : null;
            $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $status = $_POST['status'] ?? 'pending';
            $notes = trim($_POST['notes'] ?? '');
            
            try {
                $stmt = $conn->prepare("
                    UPDATE re_preventive_maintenance_tasks 
                    SET scheduled_date = ?, assigned_to = ?, status = ?, notes = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$scheduledDate, $assignedTo, $status, $notes ?: null, $taskId, $currentCompanyId]);
                $success = "Task updated successfully";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'complete_task') {
            $taskId = (int)$_POST['task_id'];
            $completedDate = $_POST['completed_date'] ?? date('Y-m-d');
            $duration = !empty($_POST['duration_minutes']) ? (int)$_POST['duration_minutes'] : null;
            $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : 0;
            $notes = trim($_POST['completion_notes'] ?? '');
            $statusBefore = trim($_POST['status_before'] ?? '');
            $statusAfter = trim($_POST['status_after'] ?? '');
            $issuesFound = trim($_POST['issues_found'] ?? '');
            $partsReplaced = trim($_POST['parts_replaced'] ?? '');
            $nextServiceDue = !empty($_POST['next_service_due']) ? $_POST['next_service_due'] : null;
            
            $completionData = [
                'completed_date' => $completedDate,
                'duration_minutes' => $duration,
                'cost' => $cost,
                'notes' => $notes,
                'status_before' => $statusBefore ?: null,
                'status_after' => $statusAfter ?: null,
                'issues_found' => $issuesFound ?: null,
                'parts_replaced' => $partsReplaced ?: null,
                'next_service_due' => $nextServiceDue
            ];
            
            // Get task to find completed_by
            $taskStmt = $conn->prepare("SELECT assigned_to FROM re_preventive_maintenance_tasks WHERE id = ?");
            $taskStmt->execute([$taskId]);
            $task = $taskStmt->fetch(PDO::FETCH_ASSOC);
            $completedBy = $task['assigned_to'] ?: $userId; // Use assigned employee or current user
            
            if (complete_preventive_maintenance_task($conn, $taskId, $completedBy, $completionData)) {
                $success = "Task completed successfully";
            } else {
                $error = "Failed to complete task";
            }
        } elseif ($_POST['action'] === 'skip_task') {
            $taskId = (int)$_POST['task_id'];
            $reason = trim($_POST['skip_reason'] ?? '');
            try {
                $stmt = $conn->prepare("
                    UPDATE re_preventive_maintenance_tasks 
                    SET status = 'skipped', notes = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $notes = $reason ? "Skipped: {$reason}" : "Task skipped";
                $stmt->execute([$notes, $taskId, $currentCompanyId]);
                $success = "Task skipped";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$dateFilter = $_GET['date'] ?? 'all';
$overdueOnly = isset($_GET['overdue']) && $_GET['overdue'] === '1';

// Build query
$where = ["t.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = "t.status = ?";
    $params[] = $statusFilter;
}

if ($overdueOnly) {
    $where[] = "t.status IN ('pending', 'scheduled') AND t.due_date < CURDATE()";
} elseif ($dateFilter === 'today') {
    $where[] = "t.due_date = CURDATE()";
} elseif ($dateFilter === 'week') {
    $where[] = "t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($dateFilter === 'month') {
    $where[] = "t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}

// Get tasks
$tasks = $conn->prepare("
    SELECT t.*, 
           s.schedule_name, s.task_description as schedule_description,
           a.asset_name, a.asset_type,
           b.name as building_name, u.unit_number,
           e.full_name as assigned_employee,
           e2.full_name as completed_by_name
    FROM re_preventive_maintenance_tasks t
    JOIN re_preventive_maintenance_schedules s ON s.id = t.schedule_id
    LEFT JOIN re_maintenance_assets a ON a.id = t.asset_id
    LEFT JOIN re_buildings b ON b.id = t.building_id
    LEFT JOIN re_units u ON u.id = t.unit_id
    LEFT JOIN employees e ON e.id = t.assigned_to
    LEFT JOIN employees e2 ON e2.id = t.completed_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY 
        CASE WHEN t.due_date < CURDATE() AND t.status IN ('pending', 'scheduled') THEN 0 ELSE 1 END,
        t.due_date ASC,
        t.status ASC
");
$tasks->execute($params);
$tasks = $tasks->fetchAll(PDO::FETCH_ASSOC);

// Get employees
$employees = $conn->prepare("SELECT id, full_name FROM employees ORDER BY full_name");
$employees->execute();
$employees = $employees->fetchAll(PDO::FETCH_ASSOC);

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
$pageTitle = 'Maintenance Tasks';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
                <i class="bi bi-list-check"></i> Real Estate - Maintenance Tasks
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="preventive_maintenance.php">Preventive Maintenance</a>
                <a class="nav-link" href="index.php">Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-list-check"></i> Maintenance Tasks</h1>
            <div>
                <a href="preventive_maintenance_schedules.php" class="btn btn-secondary">
                    <i class="bi bi-calendar-event"></i> Manage Schedules
                </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                            <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="skipped" <?= $statusFilter === 'skipped' ? 'selected' : '' ?>>Skipped</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date Range</label>
                        <select name="date" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All Dates</option>
                            <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>Next 7 Days</option>
                            <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>Next 30 Days</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="overdue" value="1" id="overdueCheck" 
                                   <?= $overdueOnly ? 'checked' : '' ?> onchange="this.form.submit()">
                            <label class="form-check-label" for="overdueCheck">
                                Show Overdue Only
                            </label>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tasks Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Tasks (<?= count($tasks) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($tasks)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No tasks found. 
                        <a href="preventive_maintenance_schedules.php">Generate tasks from schedules</a>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Task</th>
                                    <th>Schedule</th>
                                    <th>Asset</th>
                                    <th>Location</th>
                                    <th>Due Date</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Assigned To</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $task): 
                                    $isOverdue = strtotime($task['due_date']) < time() && in_array($task['status'], ['pending', 'scheduled']);
                                    $rowClass = $isOverdue ? 'table-danger' : '';
                                    $priorityClass = [
                                        'low' => 'secondary',
                                        'medium' => 'info',
                                        'high' => 'warning',
                                        'urgent' => 'danger'
                                    ];
                                    $statusClass = [
                                        'pending' => 'warning',
                                        'scheduled' => 'primary',
                                        'in_progress' => 'info',
                                        'completed' => 'success',
                                        'skipped' => 'secondary',
                                        'cancelled' => 'dark'
                                    ];
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td>
                                            <strong><?= h($task['task_name']) ?></strong>
                                            <?php if ($isOverdue): ?>
                                                <span class="badge bg-danger">OVERDUE</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= h($task['schedule_name']) ?>
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
                                            <?php if ($task['building_name']): ?>
                                                <?= h($task['building_name']) ?>
                                                <?php if ($task['unit_number']): ?>
                                                    - Unit <?= h($task['unit_number']) ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $daysUntil = (strtotime($task['due_date']) - time()) / 86400;
                                            if ($isOverdue) {
                                                $badgeClass = 'bg-danger';
                                                $text = date('M d, Y', strtotime($task['due_date'])) . ' (Overdue)';
                                            } elseif ($daysUntil <= 1) {
                                                $badgeClass = 'bg-warning';
                                                $text = date('M d, Y', strtotime($task['due_date'])) . ' (Today)';
                                            } elseif ($daysUntil <= 7) {
                                                $badgeClass = 'bg-info';
                                                $text = date('M d, Y', strtotime($task['due_date']));
                                            } else {
                                                $badgeClass = 'bg-secondary';
                                                $text = date('M d, Y', strtotime($task['due_date']));
                                            }
                                            ?>
                                            <span class="badge <?= $badgeClass ?>"><?= $text ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $priorityClass[$task['priority']] ?? 'secondary' ?>">
                                                <?= ucfirst($task['priority']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $statusClass[$task['status']] ?? 'secondary' ?>">
                                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= h($task['assigned_employee'] ?: '-') ?></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-primary" onclick="viewTask(<?= $task['id'] ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($task['status'] === 'pending' || $task['status'] === 'scheduled'): ?>
                                                    <button class="btn btn-sm btn-success" onclick="createRequest(<?= $task['id'] ?>)">
                                                        <i class="bi bi-plus-circle"></i> Request
                                                    </button>
                                                    <button class="btn btn-sm btn-warning" onclick="editTask(<?= htmlspecialchars(json_encode($task)) ?>)">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-info" onclick="completeTask(<?= htmlspecialchars(json_encode($task)) ?>)">
                                                        <i class="bi bi-check-circle"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- View Task Modal -->
    <div class="modal fade" id="viewTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Task Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="taskDetails">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Task Modal -->
    <div class="modal fade" id="editTaskModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editTaskForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_task">
                    <input type="hidden" name="task_id" id="editTaskId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" id="editTaskStatus">
                                <option value="pending">Pending</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="in_progress">In Progress</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Scheduled Date</label>
                            <input type="date" name="scheduled_date" class="form-control" id="editTaskScheduledDate">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign To</label>
                            <select name="assigned_to" class="form-select" id="editTaskAssignedTo">
                                <option value="">Unassigned</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>"><?= h($emp['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" id="editTaskNotes"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Request Modal -->
    <div class="modal fade" id="createRequestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Maintenance Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="createRequestForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_request">
                    <input type="hidden" name="task_id" id="createRequestTaskId">
                    <div class="modal-body">
                        <p>This will create a maintenance request from this preventive maintenance task.</p>
                        <p class="text-muted">The request will include all task details, instructions, and required parts.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Complete Task Modal -->
    <div class="modal fade" id="completeTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Complete Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="completeTaskForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="complete_task">
                    <input type="hidden" name="task_id" id="completeTaskId">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Completed Date *</label>
                                <input type="date" name="completed_date" class="form-control" id="completeTaskDate" 
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duration (minutes)</label>
                                <input type="number" name="duration_minutes" class="form-control" id="completeTaskDuration" min="1">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Actual Cost (AED)</label>
                                <input type="number" step="0.01" name="cost" class="form-control" id="completeTaskCost" value="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Next Service Due</label>
                                <input type="date" name="next_service_due" class="form-control" id="completeTaskNextService">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status Before</label>
                                <input type="text" name="status_before" class="form-control" id="completeTaskStatusBefore" 
                                       placeholder="e.g., Normal, Needs attention">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status After</label>
                                <input type="text" name="status_after" class="form-control" id="completeTaskStatusAfter" 
                                       placeholder="e.g., Serviced, Good condition">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Issues Found</label>
                            <textarea name="issues_found" class="form-control" rows="2" id="completeTaskIssues" 
                                      placeholder="Any issues or problems found during maintenance"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Parts Replaced</label>
                            <textarea name="parts_replaced" class="form-control" rows="2" id="completeTaskParts" 
                                      placeholder="List any parts or materials replaced"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Completion Notes *</label>
                            <textarea name="completion_notes" class="form-control" rows="3" id="completeTaskNotes" 
                                      placeholder="Describe work performed, results, recommendations" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Complete Task</button>
                    </div>
                </form>
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
                            ${task.notes ? `
                                <div class="mb-3">
                                    <strong>Notes:</strong><br>
                                    ${escapeHtml(task.notes)}
                                </div>
                            ` : ''}
                        `;
                        document.getElementById('taskDetails').innerHTML = html;
                        const modal = new bootstrap.Modal(document.getElementById('viewTaskModal'));
                        modal.show();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('taskDetails').innerHTML = '<div class="alert alert-danger">Error loading task details</div>';
                });
        }

        function editTask(task) {
            document.getElementById('editTaskId').value = task.id;
            document.getElementById('editTaskStatus').value = task.status || 'pending';
            document.getElementById('editTaskScheduledDate').value = task.scheduled_date || '';
            document.getElementById('editTaskAssignedTo').value = task.assigned_to || '';
            document.getElementById('editTaskNotes').value = task.notes || '';
            
            const modal = new bootstrap.Modal(document.getElementById('editTaskModal'));
            modal.show();
        }

        function createRequest(taskId) {
            document.getElementById('createRequestTaskId').value = taskId;
            const modal = new bootstrap.Modal(document.getElementById('createRequestModal'));
            modal.show();
        }

        function completeTask(task) {
            document.getElementById('completeTaskId').value = task.id;
            document.getElementById('completeTaskDate').value = task.completed_date || '<?= date('Y-m-d') ?>';
            document.getElementById('completeTaskDuration').value = task.actual_duration_minutes || '';
            document.getElementById('completeTaskCost').value = task.actual_cost || '0';
            
            const modal = new bootstrap.Modal(document.getElementById('completeTaskModal'));
            modal.show();
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

