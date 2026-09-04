<?php
/**
 * Real Estate Module - Task Management
 * Internal operations tracking for property management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/includes/re_task_access.php';

require_login();
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    if (!re_tasks_user_can_view_shared($conn, (int)current_user_id())) {
        http_response_code(403);
        exit('Forbidden');
    }
} else {
    // Check department access (backward compatible: fallback to module access)
    if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_OPERATIONS, $conn)) {
        require_module_access($conn, MODULE_REALESTATE);
    }
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

$success = '';
$error = '';

$isAdminViewer = re_tasks_is_admin_viewer($conn, $userId);

// Shared module fallback: if current company has no visible tasks, switch to an accessible company that does.
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    $userCompanies = get_user_companies($conn, (int)$userId);
    $companyIds = [];
    foreach ($userCompanies as $uc) {
        $cid = (int)($uc['id'] ?? 0);
        if ($cid > 0) {
            $companyIds[] = $cid;
        }
    }
    if (!empty($companyIds) && !in_array((int)$currentCompanyId, $companyIds, true)) {
        $currentCompanyId = (int)$companyIds[0];
    }

    if (!empty($companyIds)) {
        $hasVisibleInCurrent = false;
        $visCurrent = re_tasks_visibility_clause($conn, $userId, $currentCompanyId, 't');
        $checkSql = "SELECT COUNT(*) FROM re_tasks t WHERE t.company_id = ?";
        $checkParams = [$currentCompanyId];
        if (!$isAdminViewer) {
            $checkSql .= " AND " . $visCurrent['sql'];
            $checkParams = array_merge($checkParams, $visCurrent['params']);
        }
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->execute($checkParams);
        $hasVisibleInCurrent = ((int)$checkStmt->fetchColumn() > 0);

        if (!$hasVisibleInCurrent) {
            foreach ($companyIds as $candidateCompanyId) {
                $visCandidate = re_tasks_visibility_clause($conn, $userId, (int)$candidateCompanyId, 't');
                $candidateSql = "SELECT COUNT(*) FROM re_tasks t WHERE t.company_id = ?";
                $candidateParams = [(int)$candidateCompanyId];
                if (!$isAdminViewer) {
                    $candidateSql .= " AND " . $visCandidate['sql'];
                    $candidateParams = array_merge($candidateParams, $visCandidate['params']);
                }
                $candidateStmt = $conn->prepare($candidateSql);
                $candidateStmt->execute($candidateParams);
                if ((int)$candidateStmt->fetchColumn() > 0) {
                    $currentCompanyId = (int)$candidateCompanyId;
                    break;
                }
            }
        }
    }
}

$currentEmployeeId = re_tasks_current_employee_id($conn, $userId, $currentCompanyId);
$taskVisibility = re_tasks_visibility_clause($conn, $userId, $currentCompanyId, 't');

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    csrf_verify();
    $taskId = (int)$_POST['task_id'];
    $newStatus = $_POST['status'];
    $completionNotes = trim($_POST['completion_notes'] ?? '');
    if (!re_tasks_can_view_task($conn, $taskId, $currentCompanyId, $userId)) {
        http_response_code(403);
        exit('Forbidden');
    }
    
    try {
        $conn->beginTransaction();
        
        // Update task
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
        $redirectView = isset($_POST['view']) && in_array($_POST['view'], ['today', 'upcoming', 'all', 'completed', 'my_tasks']) ? $_POST['view'] : 'today';
        header('Location: tasks.php?view=' . $redirectView . '&success=1');
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Handle reschedule (quick due date update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule') {
    csrf_verify();
    $taskId = (int)$_POST['task_id'];
    $newDueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    if (!re_tasks_can_view_task($conn, $taskId, $currentCompanyId, $userId)) {
        http_response_code(403);
        exit('Forbidden');
    }
    try {
        $stmt = $conn->prepare("UPDATE re_tasks SET due_date = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
        $stmt->execute([$newDueDate, $taskId, $currentCompanyId]);
        $historyStmt = $conn->prepare("
            INSERT INTO re_task_history (company_id, task_id, action, new_value, changed_by)
            VALUES (?, ?, 'due_date_changed', ?, ?)
        ");
        $historyStmt->execute([$currentCompanyId, $taskId, $newDueDate, $userId]);
        $success = "Task rescheduled successfully";
        $redirectView = isset($_POST['view']) && in_array($_POST['view'], ['today', 'upcoming', 'all', 'completed', 'my_tasks']) ? $_POST['view'] : 'today';
        header('Location: tasks.php?view=' . $redirectView . '&success=1');
        exit;
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle save view
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_view') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    $viewUrl = trim($_POST['view_url'] ?? '');
    if ($name !== '' && $viewUrl !== '') {
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_task_views (company_id, user_id, name, view_url)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$currentCompanyId, $userId, $name, $viewUrl]);
            $success = "View saved";
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Name is required to save view";
    }
}

// Handle bulk update (complete / assign)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_update') {
    csrf_verify();
    $idsRaw = $_POST['task_ids'] ?? '';
    $bulkAction = $_POST['bulk_action'] ?? '';
    $taskIds = array_filter(array_map('intval', explode(',', $idsRaw)));
    if (!empty($taskIds) && in_array($bulkAction, ['complete', 'assign'], true)) {
        try {
            $conn->beginTransaction();
            foreach ($taskIds as $tid) {
                if (!re_tasks_can_view_task($conn, $tid, $currentCompanyId, $userId)) {
                    continue;
                }
                if ($bulkAction === 'complete') {
                    $stmt = $conn->prepare("
                        UPDATE re_tasks 
                        SET status = 'completed', completed_date = CURDATE(), updated_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$tid, $currentCompanyId]);
                    $historyStmt = $conn->prepare("
                        INSERT INTO re_task_history (company_id, task_id, action, old_value, new_value, changed_by)
                        VALUES (?, ?, 'status_changed', 'bulk', 'completed', ?)
                    ");
                    $historyStmt->execute([$currentCompanyId, $tid, $userId]);
                } elseif ($bulkAction === 'assign') {
                    $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
                    $stmt = $conn->prepare("
                        UPDATE re_tasks 
                        SET assigned_to = ?, updated_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$assignedTo, $tid, $currentCompanyId]);
                    $historyStmt = $conn->prepare("
                        INSERT INTO re_task_history (company_id, task_id, action, old_value, new_value, changed_by)
                        VALUES (?, ?, 'assigned_bulk', '', ?, ?)
                    ");
                    $historyStmt->execute([$currentCompanyId, $tid, (string)$assignedTo, $userId]);
                }
            }
            $conn->commit();
            $success = "Bulk update applied";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Handle quick add (Todoist-style: title + optional due date only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_add') {
    csrf_verify();
    $title = trim($_POST['task_title'] ?? '');
    $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d');
    $redirectView = isset($_POST['view']) && in_array($_POST['view'], ['today', 'upcoming', 'all', 'completed', 'my_tasks']) ? $_POST['view'] : 'today';
    if ($title !== '') {
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_tasks (company_id, task_title, task_description, category_id, task_type,
                    priority, status, assigned_to, created_by, due_date, start_date,
                    estimated_hours, related_type, related_id, building_id, unit_id, tenant_id, notes,
                    is_recurring, recurrence_pattern, recurrence_interval)
                VALUES (?, ?, '', NULL, 'general', 'medium', 'pending', NULL, ?, ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL)
            ");
            $stmt->execute([$currentCompanyId, $title, $userId, $dueDate]);
            $historyStmt = $conn->prepare("INSERT INTO re_task_history (company_id, task_id, action, changed_by) VALUES (?, ?, 'created', ?)");
            $historyStmt->execute([$currentCompanyId, $conn->lastInsertId(), $userId]);
            $success = "Task added successfully";
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Task title is required";
    }
    $redirectUrl = 'tasks.php?view=' . $redirectView;
    if (!empty($_POST['status']) && $_POST['status'] !== 'all') $redirectUrl .= '&status=' . urlencode($_POST['status']);
    if (!empty($_POST['priority']) && $_POST['priority'] !== 'all') $redirectUrl .= '&priority=' . urlencode($_POST['priority']);
    header('Location: ' . $redirectUrl . ($success ? '&success=added' : '') . ($error ? '&error=' . urlencode($error) : ''));
    exit;
}

// View mode: today | upcoming | all | completed | my_tasks (Todoist-style)
$view = isset($_GET['view']) && in_array($_GET['view'], ['today', 'upcoming', 'all', 'completed', 'my_tasks']) ? $_GET['view'] : 'today';
if (!$isAdminViewer && $view === 'all') {
    $view = 'my_tasks';
}

// Get filter parameters (apply in addition to view)
$filterStatus = $_GET['status'] ?? 'all';
$filterPriority = $_GET['priority'] ?? 'all';
$filterType = $_GET['task_type'] ?? 'all';
$filterCategory = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$filterAssigned = !empty($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build base where
$where = ["t.company_id = ?"];
$params = [$currentCompanyId];
if (!$isAdminViewer) {
    $where[] = $taskVisibility['sql'];
    $params = array_merge($params, $taskVisibility['params']);
}

// View-specific filters
if ($view === 'today') {
    $where[] = "t.due_date = CURDATE()";
    $where[] = "t.status IN ('pending', 'in_progress', 'on_hold')";
} elseif ($view === 'upcoming') {
    $where[] = "(t.due_date > CURDATE() OR t.due_date IS NULL)";
    $where[] = "t.status IN ('pending', 'in_progress', 'on_hold')";
} elseif ($view === 'completed') {
    $where[] = "t.status = 'completed'";
} elseif ($view === 'my_tasks') {
    if ($currentEmployeeId) {
        $where[] = "(t.created_by = ? OR t.assigned_to = ? OR EXISTS (SELECT 1 FROM re_task_assignees a WHERE a.task_id = t.id AND a.employee_id = ?))";
        $params[] = $userId;
        $params[] = $currentEmployeeId;
        $params[] = $currentEmployeeId;
    } else {
        $where[] = "t.created_by = ?";
        $params[] = $userId;
    }
    $where[] = "t.status IN ('pending', 'in_progress', 'on_hold')";
} elseif ($view === 'all') {
    // no extra status filter
}

if ($filterStatus !== 'all') {
    $where[] = "t.status = ?";
    $params[] = $filterStatus;
}
if ($filterPriority !== 'all') {
    $where[] = "t.priority = ?";
    $params[] = $filterPriority;
}
if ($filterType !== 'all') {
    $where[] = "t.task_type = ?";
    $params[] = $filterType;
}
if ($filterCategory) {
    $where[] = "t.category_id = ?";
    $params[] = $filterCategory;
}
if ($filterAssigned) {
    $where[] = "t.assigned_to = ?";
    $params[] = $filterAssigned;
}
if ($filterBuilding) {
    $where[] = "t.building_id = ?";
    $params[] = $filterBuilding;
}
if ($dateFrom) {
    $where[] = "t.due_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo) {
    $where[] = "t.due_date <= ?";
    $params[] = $dateTo;
}

$orderBy = "CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END, t.due_date IS NULL, t.due_date ASC, t.created_at DESC";
if ($view === 'completed') {
    $orderBy = "t.completed_date DESC, t.updated_at DESC";
}

// Get tasks
$tasks = $conn->prepare("
    SELECT t.*, 
           c.category_name, c.color as category_color,
           e.full_name as assigned_employee,
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
    WHERE " . implode(' AND ', $where) . "
    ORDER BY " . $orderBy . "
");
$tasks->execute($params);
$tasks = $tasks->fetchAll(PDO::FETCH_ASSOC);

// Saved views (per user)
$viewsStmt = $conn->prepare("
    SELECT id, name, view_url 
    FROM re_task_views 
    WHERE company_id = ? AND (user_id IS NULL OR user_id = ?)
    ORDER BY name
");
$viewsStmt->execute([$currentCompanyId, $userId]);
$savedViews = $viewsStmt->fetchAll(PDO::FETCH_ASSOC);

// Overdue tasks: separate query only for "today" view (main query has only today's due date)
$overdueTasks = [];
if ($view === 'today') {
    $overdueWhere = ["t.company_id = ?", "t.due_date < CURDATE()", "t.due_date IS NOT NULL", "t.status IN ('pending', 'in_progress', 'on_hold')"];
    $overdueParams = [$currentCompanyId];
    if (!$isAdminViewer) {
        $overdueWhere[] = $taskVisibility['sql'];
        $overdueParams = array_merge($overdueParams, $taskVisibility['params']);
    }
    if ($filterPriority !== 'all') { $overdueWhere[] = "t.priority = ?"; $overdueParams[] = $filterPriority; }
    if ($filterCategory) { $overdueWhere[] = "t.category_id = ?"; $overdueParams[] = $filterCategory; }
    if ($filterAssigned) { $overdueWhere[] = "t.assigned_to = ?"; $overdueParams[] = $filterAssigned; }
    $overdueQ = $conn->prepare("
        SELECT t.*, c.category_name, c.color as category_color, e.full_name as assigned_employee,
               b.name as building_name, un.unit_number
        FROM re_tasks t
        LEFT JOIN re_task_categories c ON c.id = t.category_id
        LEFT JOIN employees e ON e.id = t.assigned_to
        LEFT JOIN re_buildings b ON b.id = t.building_id
        LEFT JOIN re_units un ON un.id = t.unit_id
        WHERE " . implode(' AND ', $overdueWhere) . "
        ORDER BY t.due_date ASC, CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END
    ");
    $overdueQ->execute($overdueParams);
    $overdueTasks = $overdueQ->fetchAll(PDO::FETCH_ASSOC);
}

// Group tasks by section for display
$todayTasks = [];
$upcomingByDate = [];
$todayDate = date('Y-m-d');
if ($view === 'today') {
    $todayTasks = $tasks;
} elseif ($view === 'upcoming') {
    foreach ($tasks as $task) {
        $due = $task['due_date'] ?: 'No date';
        $upcomingByDate[$due][] = $task;
    }
    ksort($upcomingByDate);
} elseif ($view === 'all') {
    foreach ($tasks as $task) {
        $due = $task['due_date'];
        if ($due && $due < $todayDate && in_array($task['status'], ['pending', 'in_progress', 'on_hold'])) {
            $overdueTasks[] = $task;
        } elseif ($due === $todayDate) {
            $todayTasks[] = $task;
        } elseif ($due && $due > $todayDate) {
            $upcomingByDate[$due][] = $task;
        } else {
            $upcomingByDate['No date'][] = $task;
        }
    }
    ksort($upcomingByDate);
}

// Get statistics and view counts (for tab badges)
$stats = [];
$statsWhere = "company_id = ?";
$statsParams = [$currentCompanyId];
if (!$isAdminViewer) {
    $statsVis = re_tasks_visibility_clause($conn, $userId, $currentCompanyId, 're_tasks');
    $statsWhere .= " AND (" . $statsVis['sql'] . ")";
    $statsParams = array_merge($statsParams, $statsVis['params']);
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_tasks WHERE {$statsWhere} AND status = 'pending'");
$stmt->execute($statsParams);
$stats['pending'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_tasks WHERE {$statsWhere} AND status = 'in_progress'");
$stmt->execute($statsParams);
$stats['in_progress'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_tasks WHERE {$statsWhere} AND status = 'completed' AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$stmt->execute($statsParams);
$stats['completed_30_days'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_tasks WHERE {$statsWhere} AND status IN ('pending', 'in_progress', 'on_hold') AND due_date < CURDATE() AND due_date IS NOT NULL");
$stmt->execute($statsParams);
$stats['overdue'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_tasks WHERE {$statsWhere} AND due_date = CURDATE() AND status IN ('pending', 'in_progress', 'on_hold')");
$stmt->execute($statsParams);
$stats['today_count'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_tasks WHERE {$statsWhere} AND (due_date > CURDATE() OR due_date IS NULL) AND status IN ('pending', 'in_progress', 'on_hold')");
$stmt->execute($statsParams);
$stats['upcoming_count'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_tasks WHERE {$statsWhere} AND status = 'completed'");
$stmt->execute($statsParams);
$stats['completed_count'] = (int)$stmt->fetchColumn();

$stats['my_tasks_count'] = 0;
if ($currentEmployeeId) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM re_tasks t
        WHERE t.company_id = ? AND t.status IN ('pending', 'in_progress', 'on_hold')
        AND (t.created_by = ? OR t.assigned_to = ? OR EXISTS (SELECT 1 FROM re_task_assignees a WHERE a.task_id = t.id AND a.employee_id = ?))
    ");
    $stmt->execute([$currentCompanyId, $userId, $currentEmployeeId, $currentEmployeeId]);
    $stats['my_tasks_count'] = (int)$stmt->fetchColumn();
} else {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM re_tasks t
        WHERE t.company_id = ? AND t.status IN ('pending', 'in_progress', 'on_hold')
        AND t.created_by = ?
    ");
    $stmt->execute([$currentCompanyId, $userId]);
    $stats['my_tasks_count'] = (int)$stmt->fetchColumn();
}

// Get categories for filter
$categories = $conn->prepare("SELECT id, category_name, color FROM re_task_categories WHERE company_id = ? AND is_active = 1 ORDER BY category_name");
$categories->execute([$currentCompanyId]);
$categories = $categories->fetchAll(PDO::FETCH_ASSOC);

// Get employees for filter
$employees = $conn->prepare("SELECT id, full_name FROM employees ORDER BY full_name");
$employees->execute();
$employees = $employees->fetchAll(PDO::FETCH_ASSOC);

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Collect all task IDs that will be displayed (for labels lookup)
$displayTaskIds = [];
foreach ($overdueTasks as $t) { $displayTaskIds[$t['id']] = true; }
foreach ($todayTasks as $t) { $displayTaskIds[$t['id']] = true; }
foreach ($upcomingByDate as $dateTasks) { foreach ($dateTasks as $t) { $displayTaskIds[$t['id']] = true; } }
foreach ($tasks as $t) { $displayTaskIds[$t['id']] = true; }
$taskLabelsMap = [];
$taskAssigneesMap = [];
if (!empty($displayTaskIds)) {
    $ids = array_keys($displayTaskIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $labelsStmt = $conn->prepare("
        SELECT tl.task_id, l.label_name, l.color
        FROM re_task_label_links tl
        JOIN re_task_labels l ON l.id = tl.label_id
        WHERE tl.task_id IN ($placeholders) AND l.is_active = 1
        ORDER BY l.label_name
    ");
    $labelsStmt->execute($ids);
    while ($row = $labelsStmt->fetch(PDO::FETCH_ASSOC)) {
        $taskLabelsMap[$row['task_id']][] = ['label_name' => $row['label_name'], 'color' => $row['color'] ?: '#6c757d'];
    }
    try {
        $assigneesStmt = $conn->prepare("
            SELECT a.task_id, e.full_name
            FROM re_task_assignees a
            JOIN employees e ON e.id = a.employee_id
            WHERE a.task_id IN ($placeholders)
            ORDER BY e.full_name
        ");
        $assigneesStmt->execute($ids);
        while ($row = $assigneesStmt->fetch(PDO::FETCH_ASSOC)) {
            $taskAssigneesMap[$row['task_id']][] = $row['full_name'];
        }
    } catch (Exception $e) { /* table may not exist */ }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function getPriorityBadge($priority) {
    $badges = [
        'urgent' => 'bg-danger',
        'high' => 'bg-warning',
        'medium' => 'bg-info',
        'low' => 'bg-secondary'
    ];
    return $badges[$priority] ?? 'bg-secondary';
}
function getStatusBadge($status) {
    $badges = [
        'pending' => 'bg-warning',
        'in_progress' => 'bg-primary',
        'on_hold' => 'bg-secondary',
        'completed' => 'bg-success',
        'cancelled' => 'bg-dark'
    ];
    return $badges[$status] ?? 'bg-secondary';
}
function getTaskTypeLabel($type) {
    $types = [
        'inspection' => 'Inspection',
        'follow_up' => 'Follow-up',
        'approval' => 'Approval',
        'general' => 'General',
        'maintenance' => 'Maintenance',
        'compliance' => 'Compliance',
        'documentation' => 'Documentation',
        'other' => 'Other'
    ];
    return $types[$type] ?? ucfirst($type);
}

// Set page title and include layout
$pageTitle = 'Task Management';
$baseQuery = ['view' => $view];
if ($filterStatus !== 'all') $baseQuery['status'] = $filterStatus;
if ($filterPriority !== 'all') $baseQuery['priority'] = $filterPriority;
if ($filterType !== 'all') $baseQuery['task_type'] = $filterType;
if ($filterCategory) $baseQuery['category_id'] = $filterCategory;
if ($filterAssigned) $baseQuery['assigned_to'] = $filterAssigned;
if ($filterBuilding) $baseQuery['building_id'] = $filterBuilding;
if ($dateFrom) $baseQuery['date_from'] = $dateFrom;
if ($dateTo) $baseQuery['date_to'] = $dateTo;
$pageStyles = '
    .task-row { border-left: 4px solid #dee2e6; transition: background 0.15s; }
    .task-row:hover { background: #f8f9fa; }
    .task-row.priority-urgent { border-left-color: #dc3545; }
    .task-row.priority-high { border-left-color: #fd7e14; }
    .task-row.priority-medium { border-left-color: #17a2b8; }
    .task-row.priority-low { border-left-color: #6c757d; }
    .task-row.overdue-row { border-left-color: #dc3545; }
    .view-tab { text-decoration: none; color: #495057; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 500; }
    .view-tab:hover { background: #e9ecef; color: var(--primary); }
    .view-tab.active { background: var(--primary); color: #fff; }
    .task-list-section { margin-bottom: 1.5rem; }
    .task-list-section h6 { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6c757d; margin-bottom: 0.75rem; }
    .quick-add-bar { background: #f8f9fa; border: 1px dashed #dee2e6; border-radius: 10px; padding: 0.75rem 1rem; }
    #taskDetailPanel.offcanvas-end { width: min(900px, 100%); }
    @media (max-width: 768px) {
        .view-tab { width: 100%; text-align: left; padding: 0.65rem 0.8rem; }
        .task-row { padding: 0.75rem !important; }
        .task-row .task-actions { display: none; }
        .page-header-label { font-size: 1.35rem; }
        .quick-add-bar { padding: 0.55rem 0.65rem; }
    }
';
// Flash messages after redirect
if ($success === '' && isset($_GET['success'])) {
    $success = ($_GET['success'] === 'added') ? 'Task added successfully' : 'Task updated successfully';
}
if ($error === '' && isset($_GET['error'])) $error = (string)$_GET['error'];

$tasksSharedFallbackUrl = '';
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    require_once __DIR__ . '/../../includes/url_helper.php';
    $tasksSharedAppRoot = get_application_web_root();
    $tasksSharedFallbackUrl = ($tasksSharedAppRoot !== '' ? $tasksSharedAppRoot : '') . '/select-module';
}

if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    require_once __DIR__ . '/../tasks/includes/tasks_layout_header.php';
} else {
    require_once __DIR__ . '/includes/re_layout_header.php';
}

// Helper to render one task row (Todoist-style compact) with label/category chips
$renderTaskRow = function($task) use ($taskLabelsMap, $taskAssigneesMap) {
    $isOverdue = $task['due_date'] && $task['due_date'] < date('Y-m-d') && in_array($task['status'], ['pending', 'in_progress', 'on_hold']);
    $priorityClass = 'priority-' . $task['priority'];
    if ($isOverdue) $priorityClass .= ' overdue-row';
    $viewUrl = 'tasks_view.php?id=' . $task['id'];
    $rowLabels = $taskLabelsMap[$task['id']] ?? [];
    ?>
    <div class="task-row d-flex align-items-center py-3 px-3 rounded <?= $priorityClass ?>" data-task-id="<?= $task['id'] ?>" onclick="openTaskPanel(<?= $task['id'] ?>)">
        <div class="flex-shrink-0 me-3 d-flex align-items-center">
            <input type="checkbox" class="form-check-input me-2 task-select" value="<?= $task['id'] ?>" onclick="event.stopPropagation(); updateBulkSelection();">
            <?php if ($task['status'] !== 'completed' && $task['status'] !== 'cancelled'): ?>
                <button type="button" class="btn btn-link p-0 text-success" onclick="event.stopPropagation(); quickUpdateStatus(<?= $task['id'] ?>, 'completed')" title="Complete"><i class="bi bi-circle"></i></button>
            <?php else: ?>
                <span class="text-success"><i class="bi bi-check-circle-fill"></i></span>
            <?php endif; ?>
        </div>
        <div class="flex-grow-1 min-w-0">
            <a href="<?= $viewUrl ?>" class="text-decoration-none text-dark fw-medium" onclick="event.stopPropagation(); openTaskPanel(<?= $task['id'] ?>); return false;"><?= h($task['task_title']) ?></a>
            <div class="small text-muted mt-1 d-flex flex-wrap align-items-center gap-1">
                <?php if ($task['due_date']): ?>
                    <span class="<?= $isOverdue ? 'text-danger fw-medium' : '' ?>"><i class="bi bi-calendar3"></i> <?= date('M j, Y', strtotime($task['due_date'])) ?></span>
                    <?php if ($isOverdue): ?> <span class="badge bg-danger">Overdue</span><?php endif; ?>
                <?php endif; ?>
                <span class="badge <?= getStatusBadge($task['status']) ?> me-1" style="font-size:0.7rem;"><?= ucfirst(str_replace('_', ' ', $task['status'])) ?></span>
                <span class="badge <?= getPriorityBadge($task['priority']) ?> me-1" style="font-size:0.7rem;"><?= ucfirst($task['priority']) ?></span>
                <?php if (!empty($task['category_name'])): ?>
                    <span class="badge me-1" style="background-color:<?= h($task['category_color'] ?: '#6c757d') ?>; font-size:0.7rem;"><?= h($task['category_name']) ?></span>
                <?php endif; ?>
                <?php foreach ($rowLabels as $lbl): ?>
                    <span class="badge me-1" style="background-color:<?= h($lbl['color']) ?>; font-size:0.7rem;"><?= h($lbl['label_name']) ?></span>
                <?php endforeach; ?>
                <?php
                $rowAssignees = $taskAssigneesMap[$task['id']] ?? [];
                if (empty($rowAssignees) && !empty($task['assigned_employee'])) $rowAssignees = [$task['assigned_employee']];
                if (!empty($rowAssignees)): ?>
                    <span class="ms-1"><i class="bi bi-person"></i> <?= h(implode(', ', $rowAssignees)) ?></span>
                <?php endif; ?>
                <?php if (!empty($task['building_name'])): ?>
                    <span class="ms-1"><i class="bi bi-building"></i> <?= h($task['building_name']) ?><?= $task['unit_number'] ? ' - ' . h($task['unit_number']) : '' ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex-shrink-0 task-actions">
            <?php if ($task['status'] !== 'completed' && $task['status'] !== 'cancelled'): ?>
                <button type="button" class="btn btn-sm btn-link text-primary p-1" onclick="event.stopPropagation(); openReschedule(<?= $task['id'] ?>, '<?= h($task['due_date'] ?? '') ?>')" title="Reschedule"><i class="bi bi-calendar-event"></i></button>
            <?php endif; ?>
            <a href="<?= $viewUrl ?>" class="btn btn-sm btn-link text-secondary p-1" title="View" onclick="event.stopPropagation(); openTaskPanel(<?= $task['id'] ?>); return false;"><i class="bi bi-eye"></i></a>
        </div>
    </div>
    <?php
};
?>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <?php if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE && $tasksSharedFallbackUrl !== ''): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm align-self-center" id="tasksSharedBackBtn" data-fallback-url="<?= h($tasksSharedFallbackUrl) ?>" title="Return to previous page or module list">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <?php endif; ?>
                <div class="page-header-label"><i class="bi bi-list-check"></i> Task Management</div>
                <?php if (!empty($savedViews)): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-bookmark"></i> Saved views
                        </button>
                        <ul class="dropdown-menu">
                            <?php foreach ($savedViews as $v): ?>
                                <li><a class="dropdown-item" href="<?= h($v['view_url']) ?>"><?= h($v['name']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <button type="button" class="btn btn-link btn-sm text-secondary" data-bs-toggle="modal" data-bs-target="#saveViewModal">
                    <i class="bi bi-plus-circle"></i> Save current view
                </button>
            </div>
            <a href="tasks_add.php?due_date=<?= date('Y-m-d') ?>" class="btn btn-primary rounded-pill">
                <i class="bi bi-plus-lg"></i> Add task
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

        <!-- View tabs (Todoist-style) -->
        <nav class="nav nav-pills gap-1 mb-4 flex-wrap">
            <a href="tasks.php?<?= http_build_query(array_merge($baseQuery, ['view' => 'today'])) ?>" class="view-tab <?= $view === 'today' ? 'active' : '' ?>">
                <i class="bi bi-calendar-day"></i> Today <?php if ($stats['today_count'] > 0): ?><span class="badge bg-light text-dark ms-1"><?= $stats['today_count'] ?></span><?php endif; ?>
            </a>
            <a href="tasks.php?<?= http_build_query(array_merge($baseQuery, ['view' => 'upcoming'])) ?>" class="view-tab <?= $view === 'upcoming' ? 'active' : '' ?>">
                <i class="bi bi-calendar-week"></i> Upcoming <?php if ($stats['upcoming_count'] > 0): ?><span class="badge bg-light text-dark ms-1"><?= $stats['upcoming_count'] ?></span><?php endif; ?>
            </a>
            <?php if ($isAdminViewer): ?>
            <a href="tasks.php?<?= http_build_query(array_merge($baseQuery, ['view' => 'all'])) ?>" class="view-tab <?= $view === 'all' ? 'active' : '' ?>">
                <i class="bi bi-list-ul"></i> All Tasks
            </a>
            <?php endif; ?>
            <a href="tasks.php?<?= http_build_query(array_merge($baseQuery, ['view' => 'my_tasks'])) ?>" class="view-tab <?= $view === 'my_tasks' ? 'active' : '' ?>">
                <i class="bi bi-person-check"></i> My Tasks <?php if ($stats['my_tasks_count'] > 0): ?><span class="badge bg-light text-dark ms-1"><?= $stats['my_tasks_count'] ?></span><?php endif; ?>
            </a>
            <a href="tasks.php?<?= http_build_query(array_merge($baseQuery, ['view' => 'completed'])) ?>" class="view-tab <?= $view === 'completed' ? 'active' : '' ?>">
                <i class="bi bi-check2-square"></i> Completed
            </a>
        </nav>

        <!-- Quick add bar (Todoist-style) -->
        <div class="quick-add-bar mb-4">
            <form method="POST" class="d-flex align-items-center gap-2" id="quickAddForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="quick_add">
                <input type="hidden" name="view" value="<?= h($view) ?>">
                <input type="hidden" name="due_date" value="<?= $view === 'today' ? date('Y-m-d') : date('Y-m-d') ?>">
                <input type="hidden" name="status" value="<?= h($filterStatus) ?>">
                <input type="hidden" name="priority" value="<?= h($filterPriority) ?>">
                <i class="bi bi-plus-lg text-muted"></i>
                <input type="text" name="task_title" class="form-control form-control-lg border-0 bg-transparent flex-grow-1" placeholder="Add task..." autocomplete="off" id="quickAddInput">
                <button type="submit" class="btn btn-primary btn-sm rounded-pill d-none d-md-inline-block">Add</button>
            </form>
            <p class="small text-muted mt-1 mb-0">Press Enter to add. <a href="tasks_add.php?due_date=<?= date('Y-m-d') ?>">Full form</a></p>
        </div>

        <!-- Collapsible Filters -->
        <div class="mb-4">
            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#taskFilters">
                <i class="bi bi-funnel"></i> Filters & options
            </button>
            <div class="collapse mt-2" id="taskFilters">
                <div class="card card-body">
                    <form method="GET" class="row g-2">
                        <input type="hidden" name="view" value="<?= h($view) ?>">
                        <div class="col-md-2"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm"><?php foreach (['all'=>'All','pending'=>'Pending','in_progress'=>'In Progress','on_hold'=>'On Hold','completed'=>'Completed'] as $v => $l): ?><option value="<?= $v ?>" <?= $filterStatus === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label class="form-label small">Priority</label><select name="priority" class="form-select form-select-sm"><?php foreach (['all'=>'All','urgent'=>'Urgent','high'=>'High','medium'=>'Medium','low'=>'Low'] as $v => $l): ?><option value="<?= $v ?>" <?= $filterPriority === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label class="form-label small">Category</label><select name="category_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $filterCategory == $c['id'] ? 'selected' : '' ?>><?= h($c['category_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label class="form-label small">Assigned</label><select name="assigned_to" class="form-select form-select-sm"><option value="">All</option><?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>" <?= $filterAssigned == $e['id'] ? 'selected' : '' ?>><?= h($e['full_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label class="form-label small">From</label><input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>"></div>
                        <div class="col-md-2"><label class="form-label small">To</label><input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>"></div>
                        <div class="col-md-12"><button type="submit" class="btn btn-primary btn-sm">Apply</button> <a href="tasks.php?view=<?= h($view) ?>" class="btn btn-secondary btn-sm">Reset</a></div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Summary stats (compact) -->
        <div class="d-flex gap-3 mb-4 flex-wrap">
            <span class="text-muted small"><strong class="text-warning"><?= $stats['pending'] ?></strong> Pending</span>
            <span class="text-muted small"><strong class="text-primary"><?= $stats['in_progress'] ?></strong> In progress</span>
            <span class="text-muted small"><strong class="text-danger"><?= $stats['overdue'] ?></strong> Overdue</span>
            <span class="text-muted small"><strong class="text-success"><?= $stats['completed_30_days'] ?></strong> Completed (30d)</span>
        </div>

        <!-- Bulk actions bar -->
        <div id="bulkActionsBar" class="alert alert-secondary py-2 px-3 d-none d-flex justify-content-between align-items-center">
            <div>
                <span id="bulkSelectedCount">0</span> selected
            </div>
            <form method="POST" class="d-flex align-items-center gap-2 mb-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="bulk_update">
                <input type="hidden" id="bulkTaskIds" name="task_ids" value="">
                <button type="submit" class="btn btn-sm btn-success" onclick="document.getElementById('bulkAction').value='complete';">
                    <i class="bi bi-check2-circle"></i> Mark completed
                </button>
                <select name="assigned_to" class="form-select form-select-sm" onchange="document.getElementById('bulkAction').value='assign'; this.form.submit();">
                    <option value="">Assign selected...</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= h($e['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="bulk_action" id="bulkAction" value="">
            </form>
        </div>

        <!-- Overdue section -->
        <?php if (count($overdueTasks) > 0 && $view !== 'completed'): ?>
        <div class="task-list-section">
            <h6><i class="bi bi-exclamation-triangle text-danger"></i> Overdue (<?= count($overdueTasks) ?>) <a href="#" class="small text-danger ms-2" data-bs-toggle="collapse" data-bs-target="#overdueList">Show</a></h6>
            <div class="collapse show" id="overdueList">
                <div class="card">
                    <div class="card-body p-0">
                        <?php foreach ($overdueTasks as $task) $renderTaskRow($task); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Today section -->
        <?php if (($view === 'today' || $view === 'all') && count($todayTasks) >= 0): ?>
        <div class="task-list-section">
            <h6><?= date('l, M j', strtotime($todayDate)) ?> — Today <?= count($todayTasks) ? '' : '' ?></h6>
            <?php if (empty($todayTasks)): ?>
                <p class="text-muted small">No tasks due today.</p>
            <?php else: ?>
                <div class="card">
                    <div class="card-body p-0">
                        <?php foreach ($todayTasks as $task) $renderTaskRow($task); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Upcoming by date -->
        <?php if (($view === 'upcoming' || $view === 'all') && !empty($upcomingByDate)): ?>
            <?php foreach ($upcomingByDate as $dateLabel => $dateTasks): ?>
            <div class="task-list-section">
                <h6><?= $dateLabel === 'No date' ? 'No due date' : date('l, M j, Y', strtotime($dateLabel)) ?></h6>
                <div class="card">
                    <div class="card-body p-0">
                        <?php foreach ($dateTasks as $task) $renderTaskRow($task); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Single list for completed or my_tasks when no grouping -->
        <?php if ($view === 'completed' || $view === 'my_tasks'): ?>
        <div class="task-list-section">
            <h6><?= $view === 'completed' ? 'Completed' : 'My Tasks' ?> (<?= count($tasks) ?>)</h6>
            <?php if (empty($tasks)): ?>
                <p class="text-muted small"><?= $view === 'completed' ? 'No completed tasks.' : 'No tasks assigned to you.' ?></p>
            <?php else: ?>
                <div class="card">
                    <div class="card-body p-0">
                        <?php foreach ($tasks as $task) $renderTaskRow($task); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Empty state when no sections have content -->
        <?php if ($view === 'upcoming' && empty($upcomingByDate) && empty($overdueTasks)): ?>
            <p class="text-muted">No upcoming tasks. <a href="tasks_add.php">Add a task</a></p>
        <?php endif; ?>
        <?php if ($view === 'all' && empty($overdueTasks) && empty($todayTasks) && empty($upcomingByDate)): ?>
            <p class="text-muted">No active tasks. <a href="tasks_add.php">Add a task</a></p>
        <?php endif; ?>

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusUpdateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Task Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="statusUpdateForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="view" value="<?= h($view) ?>">
                    <input type="hidden" name="task_id" id="statusTaskId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required id="statusSelect">
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="on_hold">On Hold</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="mb-3" id="completionNotesField" style="display: none;">
                            <label class="form-label">Completion Notes</label>
                            <textarea name="completion_notes" class="form-control" rows="3" placeholder="Add notes about the completion"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reschedule Modal -->
    <div class="modal fade" id="rescheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reschedule task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="rescheduleForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reschedule">
                    <input type="hidden" name="view" value="<?= h($view) ?>">
                    <input type="hidden" name="task_id" id="rescheduleTaskId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Due date</label>
                            <input type="date" name="due_date" class="form-control" id="rescheduleDueDate">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Reschedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function quickUpdateStatus(taskId, status) {
            document.getElementById('statusTaskId').value = taskId;
            document.getElementById('statusSelect').value = status;
            document.getElementById('completionNotesField').style.display = status === 'completed' ? 'block' : 'none';
            new bootstrap.Modal(document.getElementById('statusUpdateModal')).show();
        }
        document.getElementById('statusSelect').addEventListener('change', function() {
            document.getElementById('completionNotesField').style.display = this.value === 'completed' ? 'block' : 'none';
        });
        function openReschedule(taskId, currentDue) {
            document.getElementById('rescheduleTaskId').value = taskId;
            document.getElementById('rescheduleDueDate').value = currentDue || '<?= date('Y-m-d') ?>';
            new bootstrap.Modal(document.getElementById('rescheduleModal')).show();
        }
        function openTaskPanel(taskId) {
            var frame = document.getElementById('taskDetailFrame');
            frame.src = 'tasks_view.php?id=' + taskId + '&panel=1';
            var offcanvasEl = document.getElementById('taskDetailPanel');
            var off = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
            off.show();
        }
        function updateBulkSelection() {
            var checkboxes = document.querySelectorAll('.task-select');
            var selected = [];
            checkboxes.forEach(function(cb) {
                if (cb.checked) selected.push(cb.value);
            });
            var bar = document.getElementById('bulkActionsBar');
            var countSpan = document.getElementById('bulkSelectedCount');
            var idsInput = document.getElementById('bulkTaskIds');
            if (!bar || !countSpan || !idsInput) return;
            countSpan.textContent = selected.length;
            idsInput.value = selected.join(',');
            if (selected.length > 0) {
                bar.classList.remove('d-none');
            } else {
                bar.classList.add('d-none');
            }
        }
        // Focus quick-add input on load (Todoist-style)
        document.addEventListener('DOMContentLoaded', function() {
            var q = document.getElementById('quickAddInput');
            if (q && !document.querySelector('.alert')) q.focus();
            var backBtn = document.getElementById('tasksSharedBackBtn');
            if (backBtn) {
                backBtn.addEventListener('click', function() {
                    var fb = backBtn.getAttribute('data-fallback-url') || '/';
                    try {
                        if (history.length > 1 && document.referrer) {
                            var refHost = new URL(document.referrer).hostname;
                            if (refHost === window.location.hostname && document.referrer.indexOf('/modules/tasks/') === -1) {
                                history.back();
                                return;
                            }
                        }
                    } catch (e) {}
                    window.location.href = fb;
                });
            }
        });
    </script>

    <!-- Task detail side panel -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="taskDetailPanel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">Task details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body p-0" style="height:100vh;">
            <iframe id="taskDetailFrame" src="" style="border:0;width:100%;height:100%;"></iframe>
        </div>
    </div>

    <!-- Save view modal -->
    <div class="modal fade" id="saveViewModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Save current view</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_view">
                    <input type="hidden" name="view_url" value="tasks.php?<?= h($_SERVER['QUERY_STRING'] ?? 'view=' . $view) ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. My Overdue Tasks" required>
                        </div>
                        <p class="small text-muted mb-0">This will save the current filters and view as a quick shortcut.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save view</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    require_once __DIR__ . '/../tasks/includes/tasks_layout_footer.php';
} else {
    require_once __DIR__ . '/includes/re_layout_footer.php';
}
?>

