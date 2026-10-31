<?php
// hr/attendance_weekly_off.php — saves and deletes weekly off groups from the
// Weekly off popup on the Attendance page, then goes back there with the popup
// open (hr_weekly_off_plan_modal). No page of its own.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_weekly_off_plans.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: attendance.php');
    exit;
}
csrf_verify();

$me_id = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
$dayNames = hr_weekly_off_day_names();
$action = (string)($_POST['action'] ?? '');

if (!hr_weekly_off_plans_ready($conn)) {
    $_SESSION['wo_plan_flash'] = ['err', 'Weekly off cannot be saved yet: run migrations/hr_attendance_weekly_off.sql first.'];
} elseif ($action === 'add') {
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['employee_ids'] ?? []))));
    $repeat = (string)($_POST['repeat_weeks'] ?? '1');
    $res = hr_weekly_off_plan_add($conn, $ids, $_POST['weekday'] ?? '', (string)($_POST['date_from'] ?? ''), (string)($_POST['date_to'] ?? ''), $me_id, $repeat);
    if ($res['ok']) {
        $summary = 'Weekly off ' . $dayNames[(int)$_POST['weekday']] . ((int)$repeat > 1 ? ' every ' . (int)$repeat . ' weeks' : '') . ', ' . $_POST['date_from'] . ' to ' . $_POST['date_to'] . ', for ' . count($ids) . ' employee(s)';
        audit_bridge_hr_ops('weekly_off_plan_added', 'hr_weekly_off_plans', $res['plan_id'], $summary, null,
            ['weekday' => (int)$_POST['weekday'], 'repeat_weeks' => (int)$repeat, 'date_from' => $_POST['date_from'], 'date_to' => $_POST['date_to'], 'employee_ids' => $ids],
            'Weekly off #' . $res['plan_id'], $me_id);
        $_SESSION['wo_plan_flash'] = ['ok', $summary . ' added.'];
    } else {
        $_SESSION['wo_plan_flash'] = ['err', $res['message']];
    }
} elseif ($action === 'delete') {
    $plan = hr_weekly_off_plan_delete($conn, (int)($_POST['plan_id'] ?? 0));
    if ($plan) {
        $summary = 'Weekly off ' . $dayNames[(int)$plan['weekday']] . ', ' . $plan['date_from'] . ' to ' . $plan['date_to'] . ', for ' . count($plan['employee_ids']) . ' employee(s)';
        audit_bridge_hr_ops('weekly_off_plan_deleted', 'hr_weekly_off_plans', (int)$plan['id'], $summary . ' deleted', null, $plan, 'Weekly off #' . (int)$plan['id'], $me_id);
        $_SESSION['wo_plan_flash'] = ['ok', $summary . ' deleted.'];
    } else {
        $_SESSION['wo_plan_flash'] = ['err', 'That weekly off was already deleted.'];
    }
} elseif ($action === 'remove') {
    $planId = (int)($_POST['plan_id'] ?? 0);
    $empId = (int)($_POST['employee_id'] ?? 0);
    if (hr_weekly_off_plan_remove_employee($conn, $planId, $empId)) {
        $st = $conn->prepare("SELECT CONCAT(full_name, ' (', employee_code, ')') FROM employees WHERE id = ?");
        $st->execute([$empId]);
        $name = (string)$st->fetchColumn();
        audit_bridge_hr_ops('weekly_off_plan_employee_removed', 'hr_weekly_off_plans', $planId, $name . ' removed from weekly off #' . $planId, null,
            ['plan_id' => $planId, 'employee_id' => $empId], 'Weekly off #' . $planId, $me_id);
        $_SESSION['wo_plan_flash'] = ['ok', $name . ' removed.'];
    } else {
        $_SESSION['wo_plan_flash'] = ['err', 'That person was already removed.'];
    }
}

header('Location: attendance.php');
exit;
