<?php
/**
 * Real Estate Module - Preventive Maintenance Schedules Management
 * Enterprise-grade schedule configuration and management
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
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
            $scheduleName = trim($_POST['schedule_name'] ?? '');
            $assetId = !empty($_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
            $assetType = !empty($_POST['asset_type']) ? $_POST['asset_type'] : null;
            $buildingId = !empty($_POST['building_id']) ? (int)$_POST['building_id'] : null;
            $taskDescription = trim($_POST['task_description'] ?? '');
            $frequencyType = $_POST['frequency_type'] ?? '';
            $frequencyValue = !empty($_POST['frequency_value']) ? (int)$_POST['frequency_value'] : null;
            $frequencyDay = !empty($_POST['frequency_day']) ? (int)$_POST['frequency_day'] : null;
            $frequencyMonth = !empty($_POST['frequency_month']) ? (int)$_POST['frequency_month'] : null;
            $estimatedDuration = !empty($_POST['estimated_duration_minutes']) ? (int)$_POST['estimated_duration_minutes'] : null;
            $estimatedCost = !empty($_POST['estimated_cost']) ? (float)$_POST['estimated_cost'] : 0;
            $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $priority = $_POST['priority'] ?? 'medium';
            $category = trim($_POST['category'] ?? '');
            $requiredParts = trim($_POST['required_parts'] ?? '');
            $instructions = trim($_POST['instructions'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            // Calculate next due date
            $startDate = $_POST['start_date'] ?? date('Y-m-d');
            $nextDueDate = calculate_next_due_date(
                $frequencyType,
                $frequencyValue,
                $frequencyDay,
                $frequencyMonth,
                null,
                $startDate
            );
            
            if (empty($scheduleName)) {
                $error = "Schedule name is required";
            } elseif (empty($taskDescription)) {
                $error = "Task description is required";
            } elseif (empty($frequencyType)) {
                $error = "Frequency type is required";
            } elseif (!$assetId && !$assetType) {
                $error = "Either select an asset or asset type";
            } else {
                try {
                    if ($_POST['action'] === 'add') {
                        $stmt = $conn->prepare("
                            INSERT INTO re_preventive_maintenance_schedules 
                            (company_id, schedule_name, asset_id, asset_type, building_id, task_description,
                             frequency_type, frequency_value, frequency_day, frequency_month,
                             estimated_duration_minutes, estimated_cost, assigned_to, priority, category,
                             required_parts, instructions, is_active, next_due_date, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $currentCompanyId, $scheduleName, $assetId, $assetType, $buildingId, $taskDescription,
                            $frequencyType, $frequencyValue, $frequencyDay, $frequencyMonth,
                            $estimatedDuration, $estimatedCost, $assignedTo, $priority, $category ?: null,
                            $requiredParts ?: null, $instructions ?: null, $isActive, $nextDueDate, $userId
                        ]);
                        $success = "Schedule created successfully. Next due date: " . date('M d, Y', strtotime($nextDueDate));
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE re_preventive_maintenance_schedules 
                            SET schedule_name = ?, asset_id = ?, asset_type = ?, building_id = ?, task_description = ?,
                                frequency_type = ?, frequency_value = ?, frequency_day = ?, frequency_month = ?,
                                estimated_duration_minutes = ?, estimated_cost = ?, assigned_to = ?, priority = ?, category = ?,
                                required_parts = ?, instructions = ?, is_active = ?, next_due_date = ?
                            WHERE id = ? AND company_id = ?
                        ");
                        $stmt->execute([
                            $scheduleName, $assetId, $assetType, $buildingId, $taskDescription,
                            $frequencyType, $frequencyValue, $frequencyDay, $frequencyMonth,
                            $estimatedDuration, $estimatedCost, $assignedTo, $priority, $category ?: null,
                            $requiredParts ?: null, $instructions ?: null, $isActive, $nextDueDate,
                            $id, $currentCompanyId
                        ]);
                        $success = "Schedule updated successfully";
                    }
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            try {
                // Check if schedule has tasks
                $checkStmt = $conn->prepare("SELECT COUNT(*) FROM re_preventive_maintenance_tasks WHERE schedule_id = ?");
                $checkStmt->execute([$id]);
                if ($checkStmt->fetchColumn() > 0) {
                    $error = "Cannot delete schedule: It has associated tasks. Deactivate it instead.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM re_preventive_maintenance_schedules WHERE id = ? AND company_id = ?");
                    $stmt->execute([$id, $currentCompanyId]);
                    $success = "Schedule deleted successfully";
                }
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'generate_tasks') {
            // Manually trigger task generation
            try {
                $generated = generate_preventive_maintenance_tasks($conn, $currentCompanyId, 30);
                $success = "Generated " . count($generated) . " maintenance tasks";
            } catch (Exception $e) {
                $error = "Error generating tasks: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$filterActive = $_GET['active'] ?? 'all';
$filterType = $_GET['asset_type'] ?? 'all';
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;

// Build query
$where = ["s.company_id = ?"];
$params = [$currentCompanyId];

if ($filterActive === 'active') {
    $where[] = "s.is_active = 1";
} elseif ($filterActive === 'inactive') {
    $where[] = "s.is_active = 0";
}

if ($filterType !== 'all') {
    $where[] = "s.asset_type = ?";
    $params[] = $filterType;
}

if ($filterBuilding) {
    $where[] = "s.building_id = ?";
    $params[] = $filterBuilding;
}

// Get schedules
$schedules = $conn->prepare("
    SELECT s.*, 
           a.asset_name, a.asset_type as asset_type_name,
           b.name as building_name,
           e.full_name as assigned_employee,
           (SELECT COUNT(*) FROM re_preventive_maintenance_tasks WHERE schedule_id = s.id AND status NOT IN ('completed', 'cancelled')) as pending_tasks_count
    FROM re_preventive_maintenance_schedules s
    LEFT JOIN re_maintenance_assets a ON a.id = s.asset_id
    LEFT JOIN re_buildings b ON b.id = s.building_id
    LEFT JOIN employees e ON e.id = s.assigned_to
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.is_active DESC, s.next_due_date ASC, s.schedule_name ASC
");
$schedules->execute($params);
$schedules = $schedules->fetchAll(PDO::FETCH_ASSOC);

// Get assets for dropdown
$assets = $conn->prepare("
    SELECT id, asset_name, asset_type, building_id 
    FROM re_maintenance_assets 
    WHERE company_id = ? AND is_active = 1 
    ORDER BY asset_type, asset_name
");
$assets->execute([$currentCompanyId]);
$assets = $assets->fetchAll(PDO::FETCH_ASSOC);

// Get buildings
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Get employees
$employees = $conn->prepare("SELECT id, full_name FROM employees ORDER BY full_name");
$employees->execute();
$employees = $employees->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatFrequency($schedule) {
    $type = $schedule['frequency_type'];
    $value = $schedule['frequency_value'];
    $day = $schedule['frequency_day'];
    $month = $schedule['frequency_month'];
    
    switch ($type) {
        case 'daily':
            return 'Daily';
        case 'weekly':
            if ($day) {
                $days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                return 'Weekly (' . ($days[$day] ?? '') . ')';
            }
            return 'Weekly';
        case 'monthly':
            if ($day) {
                return "Monthly (Day {$day})";
            }
            return 'Monthly';
        case 'quarterly':
            return 'Quarterly';
        case 'semi_annual':
            return 'Semi-Annual';
        case 'annual':
            if ($month && $day) {
                $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                return "Annual ({$months[$month]} {$day})";
            }
            return 'Annual';
        case 'custom':
            return $value ? "Every {$value} days" : 'Custom';
        default:
            return ucfirst($type);
    }
}
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
$pageTitle = 'Maintenance Schedules';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-calendar-event"></i> Maintenance Schedules</h1>
            <div>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Generate tasks for all active schedules?');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="generate_tasks">
                    <button type="submit" class="btn btn-info me-2">
                        <i class="bi bi-arrow-repeat"></i> Generate Tasks
                    </button>
                </form>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal">
                    <i class="bi bi-plus-circle"></i> New Schedule
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= h($success) ?>
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
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="active" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $filterActive === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="active" <?= $filterActive === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $filterActive === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Asset Type</label>
                        <select name="asset_type" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="ac_unit" <?= $filterType === 'ac_unit' ? 'selected' : '' ?>>AC Unit</option>
                            <option value="elevator" <?= $filterType === 'elevator' ? 'selected' : '' ?>>Elevator</option>
                            <option value="fire_system" <?= $filterType === 'fire_system' ? 'selected' : '' ?>>Fire System</option>
                            <option value="plumbing" <?= $filterType === 'plumbing' ? 'selected' : '' ?>>Plumbing</option>
                            <option value="electrical" <?= $filterType === 'electrical' ? 'selected' : '' ?>>Electrical</option>
                            <option value="hvac" <?= $filterType === 'hvac' ? 'selected' : '' ?>>HVAC</option>
                            <option value="generator" <?= $filterType === 'generator' ? 'selected' : '' ?>>Generator</option>
                            <option value="pump" <?= $filterType === 'pump' ? 'selected' : '' ?>>Pump</option>
                            <option value="security_system" <?= $filterType === 'security_system' ? 'selected' : '' ?>>Security System</option>
                            <option value="other" <?= $filterType === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Building</label>
                        <select name="building_id" class="form-select" onchange="this.form.submit()">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $filterBuilding == $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Schedules Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Schedules (<?= count($schedules) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($schedules)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No schedules found. 
                        <a href="#" data-bs-toggle="modal" data-bs-target="#addScheduleModal">Create your first schedule</a>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Schedule Name</th>
                                    <th>Asset/Type</th>
                                    <th>Frequency</th>
                                    <th>Next Due</th>
                                    <th>Last Completed</th>
                                    <th>Assigned To</th>
                                    <th>Pending Tasks</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedules as $schedule): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($schedule['schedule_name']) ?></strong>
                                            <?php if ($schedule['category']): ?>
                                                <br><small class="text-muted"><?= h($schedule['category']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($schedule['asset_name']): ?>
                                                <?= h($schedule['asset_name']) ?>
                                            <?php elseif ($schedule['asset_type']): ?>
                                                <span class="badge bg-info"><?= formatAssetType($schedule['asset_type']) ?></span>
                                                <br><small class="text-muted">All assets of this type</small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                            <?php if ($schedule['building_name']): ?>
                                                <br><small class="text-muted"><?= h($schedule['building_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= formatFrequency($schedule) ?></td>
                                        <td>
                                            <?php if ($schedule['next_due_date']): 
                                                $daysUntil = (strtotime($schedule['next_due_date']) - time()) / 86400;
                                                $badgeClass = $daysUntil <= 7 ? 'bg-warning' : ($daysUntil <= 30 ? 'bg-info' : 'bg-secondary');
                                            ?>
                                                <span class="badge <?= $badgeClass ?>">
                                                    <?= date('M d, Y', strtotime($schedule['next_due_date'])) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($schedule['last_completed_date']): ?>
                                                <?= date('M d, Y', strtotime($schedule['last_completed_date'])) ?>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($schedule['assigned_employee'] ?: '-') ?></td>
                                        <td>
                                            <?php if ($schedule['pending_tasks_count'] > 0): ?>
                                                <span class="badge bg-primary"><?= $schedule['pending_tasks_count'] ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($schedule['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="editSchedule(<?= htmlspecialchars(json_encode($schedule)) ?>)">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this schedule?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $schedule['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
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

    <!-- Add/Edit Schedule Modal -->
    <div class="modal fade" id="addScheduleModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Maintenance Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="scheduleForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="scheduleId">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Schedule Name *</label>
                                <input type="text" name="schedule_name" class="form-control" id="scheduleName" required>
                                <small class="text-muted">e.g., "AC Unit Monthly Service"</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority *</label>
                                <select name="priority" class="form-select" id="schedulePriority" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Asset Assignment</label>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="asset_assignment" id="assetSpecific" value="specific" checked onchange="toggleAssetSelection()">
                                    <label class="form-check-label" for="assetSpecific">
                                        Specific Asset
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="asset_assignment" id="assetType" value="type" onchange="toggleAssetSelection()">
                                    <label class="form-check-label" for="assetType">
                                        All Assets of Type
                                    </label>
                                </div>
                                <select name="asset_id" class="form-select" id="scheduleAssetId">
                                    <option value="">Select Asset</option>
                                    <?php foreach ($assets as $asset): ?>
                                        <option value="<?= $asset['id'] ?>" data-type="<?= $asset['asset_type'] ?>">
                                            <?= h($asset['asset_name']) ?> (<?= formatAssetType($asset['asset_type']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="asset_type" class="form-select" id="scheduleAssetType" style="display: none;">
                                    <option value="">Select Asset Type</option>
                                    <option value="ac_unit">AC Unit</option>
                                    <option value="elevator">Elevator</option>
                                    <option value="fire_system">Fire System</option>
                                    <option value="plumbing">Plumbing</option>
                                    <option value="electrical">Electrical</option>
                                    <option value="hvac">HVAC</option>
                                    <option value="generator">Generator</option>
                                    <option value="pump">Pump</option>
                                    <option value="security_system">Security System</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Building</label>
                                <select name="building_id" class="form-select" id="scheduleBuilding">
                                    <option value="">All Buildings (Optional)</option>
                                    <?php foreach ($buildings as $b): ?>
                                        <option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Leave empty for all buildings</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Task Description *</label>
                            <textarea name="task_description" class="form-control" rows="3" id="scheduleTaskDescription" required></textarea>
                            <small class="text-muted">Describe what maintenance work needs to be performed</small>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Frequency Type *</label>
                                <select name="frequency_type" class="form-select" id="frequencyType" required onchange="toggleFrequencyFields()">
                                    <option value="">Select Frequency</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="semi_annual">Semi-Annual (Every 6 months)</option>
                                    <option value="annual">Annual (Yearly)</option>
                                    <option value="custom">Custom (Days)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" id="scheduleStartDate" value="<?= date('Y-m-d') ?>">
                                <small class="text-muted">When should the schedule start?</small>
                            </div>
                        </div>

                        <!-- Frequency-specific fields -->
                        <div id="frequencyFields">
                            <div class="row" id="weeklyFields" style="display: none;">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Day of Week</label>
                                    <select name="frequency_day" class="form-select" id="frequencyDay">
                                        <option value="">Any day</option>
                                        <option value="1">Monday</option>
                                        <option value="2">Tuesday</option>
                                        <option value="3">Wednesday</option>
                                        <option value="4">Thursday</option>
                                        <option value="5">Friday</option>
                                        <option value="6">Saturday</option>
                                        <option value="7">Sunday</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row" id="monthlyFields" style="display: none;">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Day of Month</label>
                                    <input type="number" name="frequency_day" class="form-control" id="frequencyDayMonth" min="1" max="31">
                                    <small class="text-muted">Day of month (1-31)</small>
                                </div>
                            </div>
                            <div class="row" id="annualFields" style="display: none;">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Month</label>
                                    <select name="frequency_month" class="form-select" id="frequencyMonth">
                                        <option value="">Any month</option>
                                        <option value="1">January</option>
                                        <option value="2">February</option>
                                        <option value="3">March</option>
                                        <option value="4">April</option>
                                        <option value="5">May</option>
                                        <option value="6">June</option>
                                        <option value="7">July</option>
                                        <option value="8">August</option>
                                        <option value="9">September</option>
                                        <option value="10">October</option>
                                        <option value="11">November</option>
                                        <option value="12">December</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Day of Month</label>
                                    <input type="number" name="frequency_day" class="form-control" id="frequencyDayAnnual" min="1" max="31">
                                </div>
                            </div>
                            <div class="row" id="customFields" style="display: none;">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Every X Days</label>
                                    <input type="number" name="frequency_value" class="form-control" id="frequencyValue" min="1">
                                    <small class="text-muted">Number of days between maintenance</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Estimated Duration (minutes)</label>
                                <input type="number" name="estimated_duration_minutes" class="form-control" id="scheduleDuration" min="1">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Estimated Cost (AED)</label>
                                <input type="number" step="0.01" name="estimated_cost" class="form-control" id="scheduleCost" value="0">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Default Assigned To</label>
                                <select name="assigned_to" class="form-select" id="scheduleAssignedTo">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>"><?= h($emp['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category</label>
                                <input type="text" name="category" class="form-control" id="scheduleCategory" placeholder="e.g., Preventive, Inspection">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Required Parts/Materials</label>
                            <textarea name="required_parts" class="form-control" rows="2" id="scheduleParts" placeholder="List required parts, materials, or supplies"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Instructions</label>
                            <textarea name="instructions" class="form-control" rows="4" id="scheduleInstructions" placeholder="Step-by-step instructions for performing this maintenance"></textarea>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" 
                                       id="scheduleIsActive" value="1" checked>
                                <label class="form-check-label" for="scheduleIsActive">
                                    Active (Schedule will generate tasks)
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleAssetSelection() {
            const specific = document.getElementById('assetSpecific').checked;
            document.getElementById('scheduleAssetId').style.display = specific ? 'block' : 'none';
            document.getElementById('scheduleAssetType').style.display = specific ? 'none' : 'block';
            if (specific) {
                document.getElementById('scheduleAssetId').required = true;
                document.getElementById('scheduleAssetType').required = false;
                document.getElementById('scheduleAssetType').value = '';
            } else {
                document.getElementById('scheduleAssetId').required = false;
                document.getElementById('scheduleAssetType').required = true;
                document.getElementById('scheduleAssetId').value = '';
            }
        }

        function toggleFrequencyFields() {
            const freqType = document.getElementById('frequencyType').value;
            
            // Hide all
            document.getElementById('weeklyFields').style.display = 'none';
            document.getElementById('monthlyFields').style.display = 'none';
            document.getElementById('annualFields').style.display = 'none';
            document.getElementById('customFields').style.display = 'none';
            
            // Show relevant
            if (freqType === 'weekly') {
                document.getElementById('weeklyFields').style.display = 'block';
            } else if (freqType === 'monthly') {
                document.getElementById('monthlyFields').style.display = 'block';
            } else if (freqType === 'annual') {
                document.getElementById('annualFields').style.display = 'block';
            } else if (freqType === 'custom') {
                document.getElementById('customFields').style.display = 'block';
            }
        }

        function editSchedule(schedule) {
            document.getElementById('modalTitle').textContent = 'Edit Maintenance Schedule';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('scheduleId').value = schedule.id;
            document.getElementById('scheduleName').value = schedule.schedule_name || '';
            document.getElementById('schedulePriority').value = schedule.priority || 'medium';
            document.getElementById('scheduleTaskDescription').value = schedule.task_description || '';
            document.getElementById('scheduleDuration').value = schedule.estimated_duration_minutes || '';
            document.getElementById('scheduleCost').value = schedule.estimated_cost || '0';
            document.getElementById('scheduleAssignedTo').value = schedule.assigned_to || '';
            document.getElementById('scheduleCategory').value = schedule.category || '';
            document.getElementById('scheduleParts').value = schedule.required_parts || '';
            document.getElementById('scheduleInstructions').value = schedule.instructions || '';
            document.getElementById('scheduleIsActive').checked = schedule.is_active == 1;
            document.getElementById('scheduleBuilding').value = schedule.building_id || '';
            
            // Asset assignment
            if (schedule.asset_id) {
                document.getElementById('assetSpecific').checked = true;
                document.getElementById('scheduleAssetId').value = schedule.asset_id;
                toggleAssetSelection();
            } else if (schedule.asset_type) {
                document.getElementById('assetType').checked = true;
                document.getElementById('scheduleAssetType').value = schedule.asset_type;
                toggleAssetSelection();
            }
            
            // Frequency
            document.getElementById('frequencyType').value = schedule.frequency_type || '';
            toggleFrequencyFields();
            
            if (schedule.frequency_type === 'weekly' || schedule.frequency_type === 'monthly' || schedule.frequency_type === 'annual') {
                document.getElementById('frequencyDay').value = schedule.frequency_day || '';
                document.getElementById('frequencyDayMonth').value = schedule.frequency_day || '';
                document.getElementById('frequencyDayAnnual').value = schedule.frequency_day || '';
            }
            if (schedule.frequency_type === 'annual') {
                document.getElementById('frequencyMonth').value = schedule.frequency_month || '';
            }
            if (schedule.frequency_type === 'custom') {
                document.getElementById('frequencyValue').value = schedule.frequency_value || '';
            }
            
            const modal = new bootstrap.Modal(document.getElementById('addScheduleModal'));
            modal.show();
        }

        // Reset form when modal is closed
        document.getElementById('addScheduleModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('scheduleForm').reset();
            document.getElementById('modalTitle').textContent = 'Add Maintenance Schedule';
            document.getElementById('formAction').value = 'add';
            document.getElementById('scheduleId').value = '';
            document.getElementById('scheduleIsActive').checked = true;
            document.getElementById('assetSpecific').checked = true;
            document.getElementById('scheduleStartDate').value = '<?= date('Y-m-d') ?>';
            toggleAssetSelection();
            toggleFrequencyFields();
        });
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

