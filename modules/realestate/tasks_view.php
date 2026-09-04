<?php
/**
 * Real Estate Module - Task View
 * View task details, comments, attachments, and history
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

// Get task ID
$taskId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$taskId) {
    header('Location: tasks.php');
    exit;
}
if (!re_tasks_can_view_task($conn, $taskId, $currentCompanyId, $userId)) {
    http_response_code(403);
    exit('Forbidden');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_comment') {
            $comment = trim($_POST['comment'] ?? '');
            $isInternal = isset($_POST['is_internal']) ? 1 : 0;
            
            if (empty($comment)) {
                $error = "Comment cannot be empty";
            } else {
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO re_task_comments (company_id, task_id, user_id, comment, is_internal)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$currentCompanyId, $taskId, $userId, $comment, $isInternal]);
                    $success = "Comment added successfully";
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'update_status') {
            $newStatus = $_POST['status'];
            $completionNotes = trim($_POST['completion_notes'] ?? '');
            
            try {
                $conn->beginTransaction();
                
                $stmt = $conn->prepare("
                    UPDATE re_tasks 
                    SET status = ?, 
                        completed_date = CASE WHEN ? = 'completed' THEN CURDATE() ELSE completed_date END,
                        completion_notes = CASE WHEN ? = 'completed' AND ? != '' THEN CONCAT(COALESCE(completion_notes, ''), '\n', ?) ELSE completion_notes END,
                        updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$newStatus, $newStatus, $newStatus, $completionNotes, $completionNotes, $taskId, $currentCompanyId]);
                
                // Log history
                $historyStmt = $conn->prepare("
                    INSERT INTO re_task_history (company_id, task_id, action, old_value, new_value, changed_by)
                    SELECT company_id, id, 'status_changed', status, ?, ?
                    FROM re_tasks WHERE id = ? AND company_id = ?
                ");
                $historyStmt->execute([$newStatus, $userId, $taskId, $currentCompanyId]);
                
                $conn->commit();
                $success = "Task status updated successfully";
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'update_priority') {
            $newPriority = $_POST['priority'];
            
            try {
                $conn->beginTransaction();
                
                $stmt = $conn->prepare("UPDATE re_tasks SET priority = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
                $stmt->execute([$newPriority, $taskId, $currentCompanyId]);
                
                // Log history
                $historyStmt = $conn->prepare("
                    INSERT INTO re_task_history (company_id, task_id, action, old_value, new_value, changed_by)
                    SELECT company_id, id, 'priority_changed', priority, ?, ?
                    FROM re_tasks WHERE id = ? AND company_id = ?
                ");
                $historyStmt->execute([$newPriority, $userId, $taskId, $currentCompanyId]);
                
                $conn->commit();
                $success = "Priority updated successfully";
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'assign' || $_POST['action'] === 'update_assignees') {
            // Support multiple assignees: assigned_to[] array
            $raw = $_POST['assigned_to'] ?? null;
            $assigneeIds = [];
            if (is_array($raw)) {
                $assigneeIds = array_filter(array_map('intval', $raw));
            } elseif ($raw !== '' && $raw !== null) {
                $assigneeIds = [(int)$raw];
            }
            $primaryAssignee = $assigneeIds[0] ?? null;
            $oldAssigneeIds = [];
            try {
                $prev = $conn->prepare("SELECT assigned_to FROM re_tasks WHERE id = ? AND company_id = ? LIMIT 1");
                $prev->execute([$taskId, $currentCompanyId]);
                $prevPrimary = (int)($prev->fetchColumn() ?: 0);
                if ($prevPrimary > 0) {
                    $oldAssigneeIds[$prevPrimary] = true;
                }
                $prevMany = $conn->prepare("SELECT employee_id FROM re_task_assignees WHERE task_id = ?");
                $prevMany->execute([$taskId]);
                foreach ($prevMany->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                    $eid = (int)$eid;
                    if ($eid > 0) $oldAssigneeIds[$eid] = true;
                }
            } catch (Exception $e) { /* optional table */ }

            try {
                $conn->beginTransaction();

                $stmt = $conn->prepare("UPDATE re_tasks SET assigned_to = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
                $stmt->execute([$primaryAssignee, $taskId, $currentCompanyId]);

                $delAssignees = $conn->prepare("DELETE FROM re_task_assignees WHERE task_id = ?");
                $delAssignees->execute([$taskId]);
                if (!empty($assigneeIds)) {
                    $insAssignees = $conn->prepare("INSERT INTO re_task_assignees (task_id, employee_id) VALUES (?, ?)");
                    foreach ($assigneeIds as $eid) {
                        $insAssignees->execute([$taskId, $eid]);
                    }
                }

                $names = [];
                foreach ($assigneeIds as $eid) {
                    $e = $conn->prepare("SELECT full_name FROM employees WHERE id = ?");
                    $e->execute([$eid]);
                    $names[] = $e->fetchColumn() ?: 'Unknown';
                }
                $newAssignedName = empty($names) ? 'Unassigned' : implode(', ', $names);
                $historyStmt = $conn->prepare("
                    INSERT INTO re_task_history (company_id, task_id, action, old_value, new_value, changed_by)
                    VALUES (?, ?, 'assigned', ?, ?, ?)
                ");
                $historyStmt->execute([$currentCompanyId, $taskId, '', $newAssignedName, $userId]);

                $newAssigneeIds = [];
                foreach ($assigneeIds as $eid) {
                    $eid = (int)$eid;
                    if ($eid > 0) $newAssigneeIds[$eid] = true;
                }
                if ($primaryAssignee) {
                    $newAssigneeIds[(int)$primaryAssignee] = true;
                }
                $toNotify = array_keys(array_diff_key($newAssigneeIds, $oldAssigneeIds));
                if (!empty($toNotify)) {
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
                                send_task_assignment_notification($conn, $taskId, $currentCompanyId, (int)$eid, $taskDetails, $employee, $userId);
                            }
                        }
                    }
                }

                $conn->commit();
                $success = "Assignees updated successfully";
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'add_subtask') {
            $title = trim($_POST['subtask_title'] ?? '');
            if ($title === '') {
                $error = "Subtask title cannot be empty";
            } else {
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO re_task_subtasks (company_id, task_id, title, is_completed, sort_order, created_by)
                        VALUES (?, ?, ?, 0,
                            (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM re_task_subtasks WHERE task_id = ? AND company_id = ?),
                            ?)
                    ");
                    $stmt->execute([$currentCompanyId, $taskId, $title, $taskId, $currentCompanyId, $userId]);
                    $success = "Subtask added successfully";
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'toggle_subtask') {
            $subtaskId = (int)($_POST['subtask_id'] ?? 0);
            $newCompleted = isset($_POST['is_completed']) && $_POST['is_completed'] === '1' ? 1 : 0;
            try {
                $stmt = $conn->prepare("
                    UPDATE re_task_subtasks 
                    SET is_completed = ?, completed_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END,
                        updated_at = NOW()
                    WHERE id = ? AND task_id = ? AND company_id = ?
                ");
                $stmt->execute([$newCompleted, $newCompleted, $subtaskId, $taskId, $currentCompanyId]);
                $success = "Subtask updated";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'update_labels') {
            $selected = isset($_POST['labels']) && is_array($_POST['labels']) ? array_map('intval', $_POST['labels']) : [];
            try {
                $conn->beginTransaction();
                $del = $conn->prepare("DELETE FROM re_task_label_links WHERE task_id = ?");
                $del->execute([$taskId]);
                if (!empty($selected)) {
                    $ins = $conn->prepare("INSERT INTO re_task_label_links (task_id, label_id) VALUES (?, ?)");
                    foreach ($selected as $lid) {
                        $ins->execute([$taskId, $lid]);
                    }
                }
                $conn->commit();
                $success = "Labels updated";
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'update_reminder') {
            $reminderAt = !empty($_POST['reminder_at']) ? $_POST['reminder_at'] : null;
            $method = !empty($_POST['reminder_method']) ? $_POST['reminder_method'] : null;
            try {
                $stmt = $conn->prepare("
                    UPDATE re_tasks 
                    SET reminder_at = ?, reminder_method = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$reminderAt, $method, $taskId, $currentCompanyId]);
                $success = "Reminder updated";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get task
$stmt = $conn->prepare("
    SELECT t.*, 
           c.category_name, c.color as category_color,
           e.full_name as assigned_employee, e.email as assigned_email,
           u.username as created_by_name,
           b.name as building_name,
           un.unit_number,
           tn.first_name as tenant_first_name, tn.last_name as tenant_last_name
    FROM re_tasks t
    LEFT JOIN re_task_categories c ON c.id = t.category_id
    LEFT JOIN employees e ON e.id = t.assigned_to
    LEFT JOIN user u ON u.id = t.created_by
    LEFT JOIN re_buildings b ON b.id = t.building_id
    LEFT JOIN re_units un ON un.id = t.unit_id
    LEFT JOIN re_tenants tn ON tn.id = t.tenant_id
    WHERE t.id = ? AND t.company_id = ?
");
$stmt->execute([$taskId, $currentCompanyId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    header('Location: tasks.php');
    exit;
}

// Get comments
$comments = $conn->prepare("
    SELECT c.*, u.username as user_name
    FROM re_task_comments c
    LEFT JOIN user u ON u.id = c.user_id
    WHERE c.task_id = ? AND c.company_id = ?
    ORDER BY c.created_at DESC
");
$comments->execute([$taskId, $currentCompanyId]);
$comments = $comments->fetchAll(PDO::FETCH_ASSOC);

// Get attachments
$attachments = $conn->prepare("
    SELECT a.*, u.username as uploaded_by_name
    FROM re_task_attachments a
    LEFT JOIN user u ON u.id = a.uploaded_by
    WHERE a.task_id = ? AND a.company_id = ?
    ORDER BY a.uploaded_at DESC
");
$attachments->execute([$taskId, $currentCompanyId]);
$attachments = $attachments->fetchAll(PDO::FETCH_ASSOC);

// Get history
$history = $conn->prepare("
    SELECT h.*, u.username as changed_by_name
    FROM re_task_history h
    LEFT JOIN user u ON u.id = h.changed_by
    WHERE h.task_id = ? AND h.company_id = ?
    ORDER BY h.created_at DESC
");
$history->execute([$taskId, $currentCompanyId]);
$history = $history->fetchAll(PDO::FETCH_ASSOC);

// Get employees for assignment
$employees = $conn->prepare("SELECT id, full_name FROM employees ORDER BY full_name");
$employees->execute();
$employees = $employees->fetchAll(PDO::FETCH_ASSOC);

// Get subtasks
$subtasksStmt = $conn->prepare("
    SELECT * FROM re_task_subtasks 
    WHERE task_id = ? AND company_id = ?
    ORDER BY COALESCE(sort_order, id)
");
$subtasksStmt->execute([$taskId, $currentCompanyId]);
$subtasks = $subtasksStmt->fetchAll(PDO::FETCH_ASSOC);

// Assignment notification history for this task (see re_email_helper.php ->
// send_task_assignment_notification(), which logs every attempt to re_email_logs).
// Shown in the Task Details card so the creator can confirm the assignee was told.
$assignmentEmails = [];
try {
    $mailLogStmt = $conn->prepare("
        SELECT recipient_email, status, error_message, sent_at, created_at
        FROM re_email_logs
        WHERE company_id = ?
          AND related_type = 'task'
          AND related_id = ?
          AND notification_type = 'task_assignment'
        ORDER BY created_at DESC
    ");
    $mailLogStmt->execute([$currentCompanyId, $taskId]);
    $assignmentEmails = $mailLogStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // re_email_logs may be missing on older installs; the block simply stays empty.
    $assignmentEmails = [];
}

// Get labels for this task and all available labels
$labelsAllStmt = $conn->prepare("
    SELECT id, label_name, color 
    FROM re_task_labels 
    WHERE company_id = ? AND is_active = 1
    ORDER BY label_name
");
$labelsAllStmt->execute([$currentCompanyId]);
$allLabels = $labelsAllStmt->fetchAll(PDO::FETCH_ASSOC);

$labelsForTaskStmt = $conn->prepare("
    SELECT l.id, l.label_name, l.color
    FROM re_task_label_links tl
    JOIN re_task_labels l ON l.id = tl.label_id
    WHERE tl.task_id = ? 
    ORDER BY l.label_name
");
$labelsForTaskStmt->execute([$taskId]);
$taskLabels = $labelsForTaskStmt->fetchAll(PDO::FETCH_ASSOC);

// Multiple assignees: load from re_task_assignees; fallback to single assigned_to if table empty
$taskAssignees = [];
try {
    $assigneesStmt = $conn->prepare("SELECT employee_id FROM re_task_assignees WHERE task_id = ? ORDER BY employee_id");
    $assigneesStmt->execute([$taskId]);
    $taskAssignees = $assigneesStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { /* table may not exist yet */ }
if (empty($taskAssignees) && !empty($task['assigned_to'])) {
    $taskAssignees = [(int)$task['assigned_to']];
}
$taskAssignees = array_map('intval', $taskAssignees);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function getPriorityBadge($priority) {
    $badges = ['urgent' => 'bg-danger', 'high' => 'bg-warning', 'medium' => 'bg-info', 'low' => 'bg-secondary'];
    return $badges[$priority] ?? 'bg-secondary';
}
function getStatusBadge($status) {
    $badges = ['pending' => 'bg-warning', 'in_progress' => 'bg-primary', 'on_hold' => 'bg-secondary', 'completed' => 'bg-success', 'cancelled' => 'bg-dark'];
    return $badges[$status] ?? 'bg-secondary';
}
function getTaskTypeLabel($type) {
    $types = ['inspection' => 'Inspection', 'follow_up' => 'Follow-up', 'approval' => 'Approval', 'general' => 'General', 'maintenance' => 'Maintenance', 'compliance' => 'Compliance', 'documentation' => 'Documentation', 'other' => 'Other'];
    return $types[$type] ?? ucfirst($type);
}
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

// Layout mode: full page vs compact panel (no sidebar)
$isPanel = isset($_GET['panel']) && $_GET['panel'] === '1';
$pageTitle = 'Task Details';

if ($isPanel): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; }
        .panel-container { padding: 1rem 1.25rem 1.5rem; }
    </style>
</head>
<body>
    <div class="panel-container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0"><i class="bi bi-list-check"></i> Task Details</h5>
        </div>
<?php else:
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    require_once __DIR__ . '/../tasks/includes/tasks_layout_header.php';
} else {
    require_once __DIR__ . '/includes/re_layout_header.php';
}
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-list-check"></i> Task Details</h1>
            <div>
                <a href="tasks_add.php?id=<?= $taskId ?>" class="btn btn-primary">
                    <i class="bi bi-pencil"></i> Edit Task
                </a>
                <a href="tasks.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Tasks
                </a>
            </div>
        </div>
<?php endif; ?>

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

        <div class="row">
            <!-- Main Content -->
            <div class="col-md-8">
                <!-- Task Details -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><?= h($task['task_title']) ?></h5>
                        <div class="text-end">
                            <span class="badge <?= getStatusBadge($task['status']) ?>">
                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                            </span>
                            <span class="badge <?= getPriorityBadge($task['priority']) ?>">
                                <?= ucfirst($task['priority']) ?>
                            </span>
                            <?php if ($task['category_name']): ?>
                                <span class="badge" style="background-color: <?= h($task['category_color'] ?: '#007bff') ?>">
                                    <?= h($task['category_name']) ?>
                                </span>
                            <?php endif; ?>
                            <?php foreach ($taskLabels as $lbl): ?>
                                <span class="badge" style="background-color: <?= h($lbl['color'] ?: '#6c757d') ?>">
                                    <?= h($lbl['label_name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($task['task_description']): ?>
                            <div class="mb-3">
                                <h6>Description</h6>
                                <p><?= nl2br(h($task['task_description'])) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Task Type:</strong> <?= getTaskTypeLabel($task['task_type']) ?><br>
                                <strong>Created:</strong> <?= date('M d, Y H:i', strtotime($task['created_at'])) ?><br>
                                <strong>Created By:</strong> <?= h($task['created_by_name'] ?: 'System') ?>
                            </div>
                            <div class="col-md-6">
                                <?php if ($task['due_date']): ?>
                                    <strong>Due Date:</strong> 
                                    <span class="<?= strtotime($task['due_date']) < time() && in_array($task['status'], ['pending', 'in_progress']) ? 'text-danger' : '' ?>">
                                        <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                    </span>
                                    <?php if (strtotime($task['due_date']) < time() && in_array($task['status'], ['pending', 'in_progress'])): ?>
                                        <span class="badge bg-danger">Overdue</span>
                                    <?php endif; ?>
                                    <br>
                                <?php endif; ?>
                                <?php if ($task['start_date']): ?>
                                    <strong>Start Date:</strong> <?= date('M d, Y', strtotime($task['start_date'])) ?><br>
                                <?php endif; ?>
                                <?php if ($task['estimated_hours']): ?>
                                    <strong>Estimated Hours:</strong> <?= number_format($task['estimated_hours'], 1) ?> hrs<br>
                                <?php endif; ?>
                                <?php if ($task['actual_hours']): ?>
                                    <strong>Actual Hours:</strong> <?= number_format($task['actual_hours'], 1) ?> hrs<br>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <strong>Assignment Notification:</strong>
                            <?php if (empty($assignmentEmails)): ?>
                                <span class="badge bg-secondary">Not sent</span>
                                <div class="small text-muted mt-1">
                                    No assignment email has been sent for this task yet.
                                </div>
                            <?php else: ?>
                                <?php
                                $anySent = false;
                                foreach ($assignmentEmails as $mailRow) {
                                    if ($mailRow['status'] === 'sent') { $anySent = true; break; }
                                }
                                ?>
                                <?php if ($anySent): ?>
                                    <span class="badge bg-success">Email sent</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Sending failed</span>
                                <?php endif; ?>
                                <div class="small mt-1">
                                    <?php foreach ($assignmentEmails as $mailRow): ?>
                                        <?php $mailTime = $mailRow['sent_at'] ?: $mailRow['created_at']; ?>
                                        <div class="mb-1">
                                            <?php if ($mailRow['status'] === 'sent'): ?>
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                                Sent to <strong><?= h($mailRow['recipient_email']) ?></strong>
                                                on <?= $mailTime ? date('M d, Y H:i', strtotime($mailTime)) : 'unknown date' ?>
                                            <?php else: ?>
                                                <i class="bi bi-x-circle-fill text-danger"></i>
                                                Failed to <strong><?= h($mailRow['recipient_email']) ?></strong>
                                                on <?= $mailTime ? date('M d, Y H:i', strtotime($mailTime)) : 'unknown date' ?>
                                                <?php if (!empty($mailRow['error_message'])): ?>
                                                    <div class="text-danger ms-4"><?= h($mailRow['error_message']) ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($subtasks)): ?>
                            <hr>
                            <h6>Subtasks</h6>
                            <ul class="list-unstyled">
                                <?php foreach ($subtasks as $st): ?>
                                    <li class="d-flex align-items-center mb-1">
                                        <form method="POST" class="d-flex align-items-center w-100">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_subtask">
                                            <input type="hidden" name="subtask_id" value="<?= (int)$st['id'] ?>">
                                            <input type="hidden" name="is_completed" value="<?= $st['is_completed'] ? 0 : 1 ?>">
                                            <button type="submit" class="btn btn-sm me-2">
                                                <i class="bi <?= $st['is_completed'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?>"></i>
                                            </button>
                                            <span class="<?= $st['is_completed'] ? 'text-muted text-decoration-line-through' : '' ?>">
                                                <?= h($st['title']) ?>
                                            </span>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <form method="POST" class="mt-2 mb-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="add_subtask">
                            <div class="input-group input-group-sm">
                                <input type="text" name="subtask_title" class="form-control" placeholder="Add subtask...">
                                <button class="btn btn-outline-secondary" type="submit">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </form>

                        <?php if ($task['building_name'] || $task['tenant_first_name']): ?>
                            <hr>
                            <h6>Related Information</h6>
                            <div class="row">
                                <?php if ($task['building_name']): ?>
                                    <div class="col-md-6">
                                        <strong>Building:</strong> <?= h($task['building_name']) ?><br>
                                        <?php if ($task['unit_number']): ?>
                                            <strong>Unit:</strong> <?= h($task['unit_number']) ?><br>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($task['tenant_first_name']): ?>
                                    <div class="col-md-6">
                                        <strong>Tenant:</strong> <?= h($task['tenant_first_name'] . ' ' . $task['tenant_last_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($task['notes']): ?>
                            <hr>
                            <h6>Notes</h6>
                            <p><?= nl2br(h($task['notes'])) ?></p>
                        <?php endif; ?>

                        <?php if ($task['completion_notes']): ?>
                            <hr>
                            <h6>Completion Notes</h6>
                            <p><?= nl2br(h($task['completion_notes'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Comments -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Comments (<?= count($comments) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="add_comment">
                            <div class="mb-2">
                                <textarea name="comment" class="form-control" rows="3" placeholder="Add a comment..." required></textarea>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="is_internal" id="isInternal" value="1" checked>
                                <label class="form-check-label" for="isInternal">
                                    Internal note (not visible to tenant)
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-chat"></i> Add Comment
                            </button>
                        </form>
                        <hr>
                        <?php if (empty($comments)): ?>
                            <p class="text-muted">No comments yet.</p>
                        <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                                <div class="mb-3 p-3 border rounded">
                                    <div class="d-flex justify-content-between mb-2">
                                        <strong><?= h($comment['user_name'] ?: 'System') ?></strong>
                                        <small class="text-muted"><?= date('M d, Y H:i', strtotime($comment['created_at'])) ?></small>
                                    </div>
                                    <?php if ($comment['is_internal']): ?>
                                        <span class="badge bg-secondary mb-2">Internal</span>
                                    <?php endif; ?>
                                    <p class="mb-0"><?= nl2br(h($comment['comment'])) ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Attachments -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Attachments (<?= count($attachments) ?>)</h5>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                            <i class="bi bi-upload"></i> Upload
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($attachments)): ?>
                            <p class="text-muted">No attachments yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>File Name</th>
                                            <th>Size</th>
                                            <th>Uploaded By</th>
                                            <th>Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($attachments as $att): ?>
                                            <tr>
                                                <td><?= h($att['file_name']) ?></td>
                                                <td><?= formatFileSize($att['file_size']) ?></td>
                                                <td><?= h($att['uploaded_by_name'] ?: 'System') ?></td>
                                                <td><?= date('M d, Y', strtotime($att['uploaded_at'])) ?></td>
                                                <td>
                                                    <a href="<?= h($att['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-download"></i> Download
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
            </div>

            <!-- Sidebar -->
            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="update_status">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select" onchange="this.form.submit()">
                                    <option value="pending" <?= $task['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="in_progress" <?= $task['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="on_hold" <?= $task['status'] === 'on_hold' ? 'selected' : '' ?>>On Hold</option>
                                    <option value="completed" <?= $task['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="cancelled" <?= $task['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                            <div id="completionNotesField" style="display: <?= $task['status'] === 'completed' ? 'block' : 'none' ?>;">
                                <label class="form-label">Completion Notes</label>
                                <textarea name="completion_notes" class="form-control" rows="2"></textarea>
                            </div>
                        </form>

                        <form method="POST" class="mb-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="update_priority">
                            <div class="mb-3">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-select" onchange="this.form.submit()">
                                    <option value="low" <?= $task['priority'] === 'low' ? 'selected' : '' ?>>Low</option>
                                    <option value="medium" <?= $task['priority'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="high" <?= $task['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="urgent" <?= $task['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                                </select>
                            </div>
                        </form>

                        <form method="POST" class="mb-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="update_assignees">
                            <div class="mb-3">
                                <label class="form-label">Assigned To</label>
                                <select name="assigned_to[]" class="form-select" multiple size="5" onchange="this.form.submit()">
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>" <?= in_array((int)$emp['id'], $taskAssignees, true) ? 'selected' : '' ?>>
                                            <?= h($emp['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hold Ctrl (Cmd on Mac) to select multiple.</small>
                            </div>
                        </form>

                        <form method="POST" class="mb-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="update_labels">
                            <div class="mb-3">
                                <label class="form-label">Labels</label>
                                <select name="labels[]" class="form-select" multiple size="4" onchange="this.form.submit()">
                                    <?php
                                    $selectedIds = array_column($taskLabels, 'id');
                                    foreach ($allLabels as $lbl):
                                    ?>
                                        <option value="<?= $lbl['id'] ?>" <?= in_array($lbl['id'], $selectedIds, true) ? 'selected' : '' ?>>
                                            <?= h($lbl['label_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hold Ctrl (Cmd on Mac) to select multiple.</small>
                            </div>
                        </form>

                        <form method="POST" class="mb-0">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="update_reminder">
                            <div class="mb-3">
                                <label class="form-label">Reminder</label>
                                <input type="datetime-local" name="reminder_at" class="form-control form-control-sm"
                                       value="<?= $task['reminder_at'] ? date('Y-m-d\TH:i', strtotime($task['reminder_at'])) : '' ?>">
                                <select name="reminder_method" class="form-select form-select-sm mt-2">
                                    <option value="">No reminder</option>
                                    <option value="email" <?= ($task['reminder_method'] ?? '') === 'email' ? 'selected' : '' ?>>Email</option>
                                    <option value="in_app" <?= ($task['reminder_method'] ?? '') === 'in_app' ? 'selected' : '' ?>>In App</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Save Reminder</button>
                        </form>
                    </div>
                </div>

                <!-- History -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">History</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($history)): ?>
                            <p class="text-muted small">No history available.</p>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($history as $h): ?>
                                    <div class="mb-2 pb-2 border-bottom">
                                        <div class="small">
                                            <strong><?= h($h['changed_by_name'] ?: 'System') ?></strong>
                                            <span class="text-muted"><?= h($h['action']) ?></span>
                                            <?php if ($h['old_value'] && $h['new_value']): ?>
                                                <br>
                                                <span class="text-muted"><?= h($h['old_value']) ?></span> → 
                                                <span class="text-primary"><?= h($h['new_value']) ?></span>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted"><?= date('M d, Y H:i', strtotime($h['created_at'])) ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Attachment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" action="ajax_upload_task_attachment.php">
                    <input type="hidden" name="task_id" value="<?= $taskId ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">File</label>
                            <input type="file" name="file" class="form-control" required>
                            <small class="text-muted">Max 10MB</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelector('select[name="status"]').addEventListener('change', function() {
            if (this.value === 'completed') {
                document.getElementById('completionNotesField').style.display = 'block';
            } else {
                document.getElementById('completionNotesField').style.display = 'none';
            }
        });
    </script>

<?php if ($isPanel): ?>
    </div>
</body>
</html>
<?php else: ?>
<?php
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    require_once __DIR__ . '/../tasks/includes/tasks_layout_footer.php';
} else {
    require_once __DIR__ . '/includes/re_layout_footer.php';
}
?>
<?php endif; ?>

