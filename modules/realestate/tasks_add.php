<?php
/**
 * Real Estate Module - Add/Edit Task
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/includes/re_task_access.php';

require_login();
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    if (!re_tasks_user_can_view_shared($conn, (int)current_user_id())) {
        http_response_code(403);
        exit('Forbidden');
    }
} else {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

$success = '';
$error = '';
$task = null;
$isEdit = false;

// Get task if editing
if (!empty($_GET['id'])) {
    $taskId = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM re_tasks WHERE id = ? AND company_id = ?");
    $stmt->execute([$taskId, $currentCompanyId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($task) {
        if (!re_tasks_can_view_task($conn, (int)$task['id'], $currentCompanyId, $userId)) {
            http_response_code(403);
            exit('Forbidden');
        }
        $isEdit = true;
    } else {
        header('Location: tasks.php');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $taskTitle = trim($_POST['task_title'] ?? '');
    $taskDescription = trim($_POST['task_description'] ?? '');
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $taskType = $_POST['task_type'] ?? 'general';
    $priority = $_POST['priority'] ?? 'medium';
    $status = $_POST['status'] ?? 'pending';
    $rawAssignees = $_POST['assigned_to'] ?? null;
    $assigneeIds = [];
    if (is_array($rawAssignees)) {
        $assigneeIds = array_filter(array_map('intval', $rawAssignees));
    } elseif (!empty($rawAssignees)) {
        $assigneeIds = [(int)$rawAssignees];
    }
    $assignedTo = $assigneeIds[0] ?? null;
    $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $estimatedHours = !empty($_POST['estimated_hours']) ? (float)$_POST['estimated_hours'] : null;
    $relatedTypeRaw = trim((string)($_POST['related_type'] ?? ''));
    $relatedType = ($relatedTypeRaw !== '') ? $relatedTypeRaw : null;
    $relatedId = !empty($_POST['related_id']) ? (int)$_POST['related_id'] : null;
    if ($relatedType === null) {
        $relatedId = null;
    }
    $buildingId = !empty($_POST['building_id']) ? (int)$_POST['building_id'] : null;
    $unitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
    $tenantId = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
    $notes = trim($_POST['notes'] ?? '');
    $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
    $recurrencePattern = $_POST['recurrence_pattern'] ?? null;
    $recurrenceInterval = !empty($_POST['recurrence_interval']) ? (int)$_POST['recurrence_interval'] : null;
    
    if (empty($taskTitle)) {
        $error = "Task title is required";
    } else {
        try {
            $conn->beginTransaction();
            $oldAssigneeIds = [];
            if ($isEdit) {
                try {
                    $prevPrimary = !empty($task['assigned_to']) ? (int)$task['assigned_to'] : 0;
                    if ($prevPrimary > 0) {
                        $oldAssigneeIds[$prevPrimary] = true;
                    }
                    $prevAssignees = $conn->prepare("SELECT employee_id FROM re_task_assignees WHERE task_id = ?");
                    $prevAssignees->execute([$task['id']]);
                    foreach ($prevAssignees->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                        $eid = (int)$eid;
                        if ($eid > 0) $oldAssigneeIds[$eid] = true;
                    }
                } catch (Exception $e) { /* optional table */ }
            }
            
            if ($isEdit) {
                // Update task
                $stmt = $conn->prepare("
                    UPDATE re_tasks 
                    SET task_title = ?, task_description = ?, category_id = ?, task_type = ?,
                        priority = ?, status = ?, assigned_to = ?, due_date = ?, start_date = ?,
                        estimated_hours = ?, related_type = ?, related_id = ?,
                        building_id = ?, unit_id = ?, tenant_id = ?, notes = ?,
                        is_recurring = ?, recurrence_pattern = ?, recurrence_interval = ?,
                        updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $taskTitle, $taskDescription, $categoryId, $taskType,
                    $priority, $status, $assignedTo, $dueDate, $startDate,
                    $estimatedHours, $relatedType, $relatedId,
                    $buildingId, $unitId, $tenantId, $notes,
                    $isRecurring, $recurrencePattern, $recurrenceInterval,
                    $task['id'], $currentCompanyId
                ]);
                try {
                    $conn->prepare("DELETE FROM re_task_assignees WHERE task_id = ?")->execute([$task['id']]);
                    foreach ($assigneeIds as $eid) {
                        $conn->prepare("INSERT INTO re_task_assignees (task_id, employee_id) VALUES (?, ?)")->execute([$task['id'], $eid]);
                    }
                } catch (Exception $e) { /* re_task_assignees may not exist yet */ }
                $taskId = (int)$task['id'];
                $success = "Task updated successfully";
            } else {
                // Create task
                $stmt = $conn->prepare("
                    INSERT INTO re_tasks 
                    (company_id, task_title, task_description, category_id, task_type,
                     priority, status, assigned_to, created_by, due_date, start_date,
                     estimated_hours, related_type, related_id,
                     building_id, unit_id, tenant_id, notes,
                     is_recurring, recurrence_pattern, recurrence_interval)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId, $taskTitle, $taskDescription, $categoryId, $taskType,
                    $priority, $status, $assignedTo, $userId, $dueDate, $startDate,
                    $estimatedHours, $relatedType, $relatedId,
                    $buildingId, $unitId, $tenantId, $notes,
                    $isRecurring, $recurrencePattern, $recurrenceInterval
                ]);
                $taskId = $conn->lastInsertId();
                try {
                    foreach ($assigneeIds as $eid) {
                        $conn->prepare("INSERT INTO re_task_assignees (task_id, employee_id) VALUES (?, ?)")->execute([$taskId, $eid]);
                    }
                } catch (Exception $e) { /* re_task_assignees may not exist yet */ }
                // Log history
                $historyStmt = $conn->prepare("
                    INSERT INTO re_task_history (company_id, task_id, action, changed_by)
                    VALUES (?, ?, 'created', ?)
                ");
                $historyStmt->execute([$currentCompanyId, $taskId, $userId]);
                
                // Send assignment notification if assigned
                if ($assignedTo) {
                    // no-op here; notifications are handled centrally below for all assignees
                }
                
                $success = "Task created successfully";
            }

            // Notify newly added assignees (primary + additional)
            $newAssigneeIds = [];
            foreach ($assigneeIds as $eid) {
                $eid = (int)$eid;
                if ($eid > 0) $newAssigneeIds[$eid] = true;
            }
            if (!empty($assignedTo)) {
                $newAssigneeIds[(int)$assignedTo] = true;
            }
            $toNotify = array_keys(array_diff_key($newAssigneeIds, $oldAssigneeIds));
            if (!empty($toNotify) && !empty($taskId)) {
                require_once __DIR__ . '/includes/re_email_helper.php';
                $taskStmt = $conn->prepare("
                    SELECT t.*, c.category_name, b.name as building_name, u.unit_number
                    FROM re_tasks t
                    LEFT JOIN re_task_categories c ON c.id = t.category_id
                    LEFT JOIN re_buildings b ON b.id = t.building_id
                    LEFT JOIN re_units u ON u.id = t.unit_id
                    WHERE t.id = ?
                ");
                $taskStmt->execute([$taskId]);
                $taskDetails = $taskStmt->fetch(PDO::FETCH_ASSOC);
                if ($taskDetails) {
                    $empStmt = $conn->prepare("SELECT id, full_name, email FROM employees WHERE id = ?");
                    foreach ($toNotify as $eid) {
                        $empStmt->execute([(int)$eid]);
                        $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
                        if ($employee) {
                            send_task_assignment_notification($conn, (int)$taskId, $currentCompanyId, (int)$eid, $taskDetails, $employee, $userId);
                        }
                    }
                }
            }
            
            $conn->commit();
            
            if (!$isEdit) {
                header('Location: tasks_view.php?id=' . $taskId);
                exit;
            } else {
                // Reload task
                $stmt = $conn->prepare("SELECT * FROM re_tasks WHERE id = ? AND company_id = ?");
                $stmt->execute([$task['id'], $currentCompanyId]);
                $task = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Get categories
$categories = $conn->prepare("SELECT id, category_name FROM re_task_categories WHERE company_id = ? AND is_active = 1 ORDER BY category_name");
$categories->execute([$currentCompanyId]);
$categories = $categories->fetchAll(PDO::FETCH_ASSOC);

// Get employees
$employees = $conn->prepare("SELECT id, full_name FROM employees ORDER BY full_name");
$employees->execute();
$employees = $employees->fetchAll(PDO::FETCH_ASSOC);

// When editing, load multiple assignees
$taskAssignees = [];
if ($isEdit && !empty($task['id'])) {
    try {
        $st = $conn->prepare("SELECT employee_id FROM re_task_assignees WHERE task_id = ?");
        $st->execute([$task['id']]);
        $taskAssignees = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { }
    if (empty($taskAssignees) && !empty($task['assigned_to'])) {
        $taskAssignees = [(int)$task['assigned_to']];
    }
}

// Get buildings
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Get units (if building selected)
$units = [];
if ($task && $task['building_id']) {
    $unitsStmt = $conn->prepare("SELECT id, unit_number FROM re_units WHERE building_id = ? ORDER BY unit_number");
    $unitsStmt->execute([$task['building_id']]);
    $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get tenants
$tenants = $conn->prepare("SELECT id, first_name, last_name FROM re_tenants WHERE company_id = ? ORDER BY first_name, last_name");
$tenants->execute([$currentCompanyId]);
$tenants = $tenants->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = ($isEdit ? 'Edit' : 'Add') . ' Task';
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    require_once __DIR__ . '/../tasks/includes/tasks_layout_header.php';
} else {
    require_once __DIR__ . '/includes/re_layout_header.php';
}
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-<?= $isEdit ? 'pencil' : 'plus-circle' ?>"></i> <?= $isEdit ? 'Edit' : 'Add' ?> Task</h1>
            <a href="tasks.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Tasks
            </a>
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

        <form method="POST" class="card">
            <?php csrf_field(); ?>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Task Title *</label>
                        <input type="text" name="task_title" class="form-control" 
                               value="<?= h($task['task_title'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0">Category</label>
                            <a href="tasks_categories.php" class="btn btn-link btn-sm p-0">
                                <i class="bi bi-tags"></i> Manage categories
                            </a>
                        </div>
                        <select name="category_id" class="form-select">
                            <option value="">No Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" 
                                        <?= ($task['category_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
                                    <?= h($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="task_description" class="form-control" rows="4"><?= h($task['task_description'] ?? '') ?></textarea>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Task Type *</label>
                        <select name="task_type" class="form-select" required>
                            <option value="general" <?= ($task['task_type'] ?? 'general') === 'general' ? 'selected' : '' ?>>General</option>
                            <option value="inspection" <?= ($task['task_type'] ?? '') === 'inspection' ? 'selected' : '' ?>>Inspection</option>
                            <option value="follow_up" <?= ($task['task_type'] ?? '') === 'follow_up' ? 'selected' : '' ?>>Follow-up</option>
                            <option value="approval" <?= ($task['task_type'] ?? '') === 'approval' ? 'selected' : '' ?>>Approval</option>
                            <option value="maintenance" <?= ($task['task_type'] ?? '') === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            <option value="compliance" <?= ($task['task_type'] ?? '') === 'compliance' ? 'selected' : '' ?>>Compliance</option>
                            <option value="documentation" <?= ($task['task_type'] ?? '') === 'documentation' ? 'selected' : '' ?>>Documentation</option>
                            <option value="other" <?= ($task['task_type'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Priority *</label>
                        <select name="priority" class="form-select" required>
                            <option value="low" <?= ($task['priority'] ?? 'medium') === 'low' ? 'selected' : '' ?>>Low</option>
                            <option value="medium" <?= ($task['priority'] ?? 'medium') === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="high" <?= ($task['priority'] ?? 'medium') === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="urgent" <?= ($task['priority'] ?? 'medium') === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Status *</label>
                        <select name="status" class="form-select" required>
                            <option value="pending" <?= ($task['status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="in_progress" <?= ($task['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="on_hold" <?= ($task['status'] ?? '') === 'on_hold' ? 'selected' : '' ?>>On Hold</option>
                            <option value="completed" <?= ($task['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= ($task['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label d-flex justify-content-between align-items-center">
                            <span>Assign to</span>
                            <span class="badge bg-light text-dark border fw-normal" id="assigneeSelectedBadge">0</span>
                        </label>
                        <div class="assignee-picker border rounded-3 shadow-sm bg-white overflow-hidden">
                            <div class="p-2 border-bottom bg-light">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                    <input type="search" class="form-control border-start-0" id="assigneeSearch" autocomplete="off" placeholder="Search by name…" aria-label="Search assignees" oninput="window.assigneeFilterRun && window.assigneeFilterRun()" onkeyup="window.assigneeFilterRun && window.assigneeFilterRun()">
                                </div>
                            </div>
                            <div class="assignee-scroll" id="assigneeList" style="max-height: 220px; overflow-y: auto;">
                                <?php foreach ($employees as $emp):
                                    $eid = (int)$emp['id'];
                                    $checked = in_array($eid, $taskAssignees ?? [], true);
                                    $nameLower = strtolower((string)($emp['full_name'] ?? ''));
                                    ?>
                                <div class="assignee-item d-flex align-items-center gap-2 px-3 py-2 border-bottom assignee-row" data-assignee-name="<?= h($nameLower) ?>">
                                    <input class="form-check-input flex-shrink-0 mt-0 assignee-cb" type="checkbox" name="assigned_to[]" value="<?= $eid ?>" id="emp<?= $eid ?>" <?= $checked ? 'checked' : '' ?>>
                                    <label class="form-check-label flex-grow-1 mb-0 small" for="emp<?= $eid ?>" style="cursor:pointer;"><?= h($emp['full_name']) ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="px-3 py-2 bg-light border-top">
                                <div class="small text-muted" id="assigneeEmptyHint" style="display:none;">No people match your search.</div>
                                <div class="small text-muted" id="assigneeHelp">Select one or more. The first selected person is stored as the primary assignee.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" class="form-control" 
                               value="<?= $task['due_date'] ?? '' ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" 
                               value="<?= $task['start_date'] ?? '' ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Estimated Hours</label>
                        <input type="number" step="0.5" name="estimated_hours" class="form-control" 
                               value="<?= $task['estimated_hours'] ?? '' ?>" min="0">
                    </div>
                </div>
                <hr>
                <h5>Related Information</h5>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Building</label>
                        <select name="building_id" class="form-select" id="buildingSelect" onchange="loadUnits()">
                            <option value="">Select Building</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" 
                                        <?= ($task['building_id'] ?? null) == $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Unit</label>
                        <select name="unit_id" class="form-select" id="unitSelect">
                            <option value="">Select Unit</option>
                            <?php foreach ($units as $u): ?>
                                <option value="<?= $u['id'] ?>" 
                                        <?= ($task['unit_id'] ?? null) == $u['id'] ? 'selected' : '' ?>>
                                    <?= h($u['unit_number']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Tenant</label>
                        <select name="tenant_id" class="form-select">
                            <option value="">Select Tenant</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= $t['id'] ?>" 
                                        <?= ($task['tenant_id'] ?? null) == $t['id'] ? 'selected' : '' ?>>
                                    <?= h($t['first_name'] . ' ' . $t['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Related To</label>
                        <select name="related_type" class="form-select">
                            <option value="">None</option>
                            <option value="building" <?= ($task['related_type'] ?? '') === 'building' ? 'selected' : '' ?>>Building</option>
                            <option value="unit" <?= ($task['related_type'] ?? '') === 'unit' ? 'selected' : '' ?>>Unit</option>
                            <option value="tenant" <?= ($task['related_type'] ?? '') === 'tenant' ? 'selected' : '' ?>>Tenant</option>
                            <option value="lease" <?= ($task['related_type'] ?? '') === 'lease' ? 'selected' : '' ?>>Lease</option>
                            <option value="maintenance_request" <?= ($task['related_type'] ?? '') === 'maintenance_request' ? 'selected' : '' ?>>Maintenance Request</option>
                            <option value="payment" <?= ($task['related_type'] ?? '') === 'payment' ? 'selected' : '' ?>>Payment</option>
                            <option value="document" <?= ($task['related_type'] ?? '') === 'document' ? 'selected' : '' ?>>Document</option>
                            <option value="other" <?= ($task['related_type'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Related ID</label>
                        <input type="number" name="related_id" class="form-control" 
                               value="<?= $task['related_id'] ?? '' ?>" 
                               placeholder="Enter ID of related record">
                    </div>
                </div>
                <hr>
                <h5>Recurrence (Optional)</h5>
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_recurring" id="isRecurring" 
                                   value="1" <?= ($task['is_recurring'] ?? 0) ? 'checked' : '' ?> onchange="toggleRecurrence()">
                            <label class="form-check-label" for="isRecurring">
                                This is a recurring task
                            </label>
                        </div>
                    </div>
                </div>
                <div id="recurrenceFields" style="display: <?= ($task['is_recurring'] ?? 0) ? 'block' : 'none' ?>;">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Recurrence Pattern</label>
                            <select name="recurrence_pattern" class="form-select">
                                <option value="">Select Pattern</option>
                                <option value="daily" <?= ($task['recurrence_pattern'] ?? '') === 'daily' ? 'selected' : '' ?>>Daily</option>
                                <option value="weekly" <?= ($task['recurrence_pattern'] ?? '') === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                <option value="monthly" <?= ($task['recurrence_pattern'] ?? '') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="quarterly" <?= ($task['recurrence_pattern'] ?? '') === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="yearly" <?= ($task['recurrence_pattern'] ?? '') === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Interval</label>
                            <input type="number" name="recurrence_interval" class="form-control" 
                                   value="<?= $task['recurrence_interval'] ?? '' ?>" 
                                   placeholder="e.g., every 2 weeks = 2" min="1">
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3"><?= h($task['notes'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> <?= $isEdit ? 'Update' : 'Create' ?> Task
                </button>
                <a href="tasks.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <style>
        .assignee-item { transition: background 0.12s ease; }
        .assignee-item:hover { background: #f8f9fa; }
        .assignee-item.assignee-item--on { background: #e8f2ff; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleRecurrence() {
            const isRecurring = document.getElementById('isRecurring').checked;
            document.getElementById('recurrenceFields').style.display = isRecurring ? 'block' : 'none';
        }

        function loadUnits() {
            const buildingId = document.getElementById('buildingSelect').value;
            const unitSelect = document.getElementById('unitSelect');
            
            unitSelect.innerHTML = '<option value="">Loading...</option>';
            
            if (!buildingId) {
                unitSelect.innerHTML = '<option value="">Select Unit</option>';
                return;
            }
            
            fetch(`ajax_get_units.php?building_id=${buildingId}`)
                .then(response => response.json())
                .then(data => {
                    unitSelect.innerHTML = '<option value="">Select Unit</option>';
                    if (data.success && data.units) {
                        data.units.forEach(unit => {
                            const option = document.createElement('option');
                            option.value = unit.id;
                            option.textContent = unit.unit_number;
                            unitSelect.appendChild(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    unitSelect.innerHTML = '<option value="">Error loading units</option>';
                });
        }

        function initAssigneePicker() {
            var search = document.getElementById('assigneeSearch');
            var list = document.getElementById('assigneeList');
            var emptyHint = document.getElementById('assigneeEmptyHint');
            var badge = document.getElementById('assigneeSelectedBadge');
            if (!search || !list || !badge) return;

            function eachNode(nodeList, fn) {
                Array.prototype.forEach.call(nodeList, fn);
            }

            function updateRowHighlight(cb) {
                var row = cb.closest('.assignee-item');
                if (!row) return;
                if (cb.checked) row.classList.add('assignee-item--on');
                else row.classList.remove('assignee-item--on');
            }

            function updateCount() {
                var n = list.querySelectorAll('.assignee-cb:checked').length;
                badge.textContent = String(n);
            }

            function filterAssignees() {
                var q = (search.value || '').trim().toLowerCase();
                var rows = list.querySelectorAll('.assignee-item');
                var shown = 0;
                eachNode(rows, function(row) {
                    var dataName = (row.getAttribute('data-assignee-name') || '').toLowerCase();
                    var visibleName = (row.textContent || '').toLowerCase();
                    var ok = !q || dataName.indexOf(q) !== -1 || visibleName.indexOf(q) !== -1;
                    // Rows use Bootstrap .d-flex (display:flex !important) — inline display:none never wins.
                    if (ok) {
                        row.classList.remove('d-none');
                    } else {
                        row.classList.add('d-none');
                    }
                    if (ok) shown++;
                });
                if (emptyHint) emptyHint.style.display = shown ? 'none' : 'block';
            }
            window.assigneeFilterRun = filterAssignees;

            if (search.dataset.assigneePickerBound === '1') {
                updateCount();
                filterAssignees();
                return;
            }
            search.dataset.assigneePickerBound = '1';

            eachNode(list.querySelectorAll('.assignee-cb'), function(cb) {
                updateRowHighlight(cb);
                cb.addEventListener('change', function() {
                    updateRowHighlight(cb);
                    updateCount();
                });
            });

            search.addEventListener('input', filterAssignees);
            search.addEventListener('keyup', filterAssignees);
            search.addEventListener('search', filterAssignees);
            updateCount();
            filterAssignees();
        }
        // Run now, on DOM ready, and once shortly after to survive delayed renders.
        initAssigneePicker();
        document.addEventListener('DOMContentLoaded', initAssigneePicker);
        setTimeout(initAssigneePicker, 150);
    </script>

<?php
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    require_once __DIR__ . '/../tasks/includes/tasks_layout_footer.php';
} else {
    require_once __DIR__ . '/includes/re_layout_footer.php';
}
?>

