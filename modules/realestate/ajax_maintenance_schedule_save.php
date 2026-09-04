<?php
/**
 * AJAX endpoint for Maintenance Schedule board drag operations:
 *   - op=resize / op=move_time : change a work order's start/end time
 *   - op=reassign              : move a work order from one employee row to another
 *
 * Returns JSON: { success, message, conflicts: [...] }
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/maintenance_schedule_helper.php';

header('Content-Type: application/json');
require_login();

if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_MAINTENANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$currentCompanyId = current_company_id($conn) ?: 1;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}
if (!csrf_verify(false)) {
    echo json_encode(['success' => false, 'message' => 'Security token invalid. Please refresh the page.']);
    exit;
}

re_ms_ensure_schema($conn);

$op = $_POST['op'] ?? '';
$scheduleId = (int)($_POST['schedule_id'] ?? 0);
$override = !empty($_POST['override']) && re_ms_can_override($conn);

if ($scheduleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing work order id']);
    exit;
}

// Load schedule (company-scoped)
$st = $conn->prepare("SELECT * FROM re_maintenance_schedules WHERE id = ? AND company_id = ?");
$st->execute([$scheduleId, $currentCompanyId]);
$schedule = $st->fetch(PDO::FETCH_ASSOC);
if (!$schedule) {
    echo json_encode(['success' => false, 'message' => 'Work order not found']);
    exit;
}

$date = $schedule['schedule_date'];

try {
    if ($op === 'resize' || $op === 'move_time') {
        $start = re_ms_normalize_time((string)($_POST['start_time'] ?? ''), 15);
        $end = re_ms_normalize_time((string)($_POST['end_time'] ?? ''), 15);
        if (strtotime($end) <= strtotime($start)) {
            echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
            exit;
        }
        // Current assignees of this schedule
        $aStmt = $conn->prepare("SELECT employee_id FROM re_maintenance_schedule_assignees WHERE schedule_id = ?");
        $aStmt->execute([$scheduleId]);
        $empIds = array_map('intval', $aStmt->fetchAll(PDO::FETCH_COLUMN));

        $conflicts = re_ms_detect_conflicts($conn, $currentCompanyId, $empIds, $date, $start, $end, $scheduleId);
        if (!empty($conflicts) && !$override) {
            echo json_encode(['success' => false, 'conflicts' => $conflicts, 'message' => 'Scheduling conflict.']);
            exit;
        }
        $up = $conn->prepare("UPDATE re_maintenance_schedules SET start_time = ?, end_time = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
        $up->execute([$start, $end, $scheduleId, $currentCompanyId]);
        echo json_encode(['success' => true, 'message' => 'Time updated.']);
        exit;
    }

    if ($op === 'reassign') {
        $fromEmp = (int)($_POST['from_employee_id'] ?? 0);
        $toEmp = (int)($_POST['to_employee_id'] ?? 0);
        if ($fromEmp <= 0 || $toEmp <= 0) {
            echo json_encode(['success' => false, 'message' => 'Missing employee ids']);
            exit;
        }
        if ($fromEmp === $toEmp) {
            echo json_encode(['success' => true, 'message' => 'No change.']);
            exit;
        }
        // Optional time change carried with the move
        $start = isset($_POST['start_time']) && $_POST['start_time'] !== '' ? re_ms_normalize_time((string)$_POST['start_time'], 15) : substr($schedule['start_time'], 0, 8);
        $end = isset($_POST['end_time']) && $_POST['end_time'] !== '' ? re_ms_normalize_time((string)$_POST['end_time'], 15) : substr($schedule['end_time'], 0, 8);
        if (strtotime($end) <= strtotime($start)) {
            $start = substr($schedule['start_time'], 0, 8);
            $end = substr($schedule['end_time'], 0, 8);
        }

        // If target already assigned to this WO, just drop the source (merge)
        $exists = $conn->prepare("SELECT id FROM re_maintenance_schedule_assignees WHERE schedule_id = ? AND employee_id = ?");
        $exists->execute([$scheduleId, $toEmp]);
        $targetAlready = (bool)$exists->fetchColumn();

        // Conflict for the target employee (exclude this schedule)
        $conflicts = re_ms_detect_conflicts($conn, $currentCompanyId, [$toEmp], $date, $start, $end, $scheduleId);
        if (!empty($conflicts) && !$override) {
            echo json_encode(['success' => false, 'conflicts' => $conflicts, 'message' => 'Target employee has a conflict.']);
            exit;
        }

        $conn->beginTransaction();
        // role for the new employee: keep source role, else infer
        $roleStmt = $conn->prepare("SELECT team_role FROM re_maintenance_schedule_assignees WHERE schedule_id = ? AND employee_id = ?");
        $roleStmt->execute([$scheduleId, $fromEmp]);
        $role = $roleStmt->fetchColumn() ?: 'technician';

        // remove source assignee
        $conn->prepare("DELETE FROM re_maintenance_schedule_assignees WHERE schedule_id = ? AND employee_id = ?")->execute([$scheduleId, $fromEmp]);
        if (!$targetAlready) {
            $conn->prepare("INSERT INTO re_maintenance_schedule_assignees (company_id, schedule_id, employee_id, team_role) VALUES (?,?,?,?)")
                 ->execute([$currentCompanyId, $scheduleId, $toEmp, $role]);
        }
        $conn->prepare("UPDATE re_maintenance_schedules SET start_time = ?, end_time = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
             ->execute([$start, $end, $scheduleId, $currentCompanyId]);
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Reassigned.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown operation']);
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
