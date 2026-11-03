<?php
/**
 * The attendance reminder, from the command line.
 *
 * Not needed day to day: the first staff-app request after a person's reminder
 * time already sends it (modules/operations/includes/ops_attendance_reminder.php).
 * This is for a scheduled task if one is ever wanted, and for testing. Each
 * employee has their own time, set by HR on their profile.
 *
 *   php cron/attendance_reminder.php                      send to everyone whose time has passed and who has not had today's
 *   php cron/attendance_reminder.php --dry-run            who would get what today, nothing sent
 *   php cron/attendance_reminder.php --send-now           TESTING: forget today's sends and send now, whatever the time
 *   php cron/attendance_reminder.php --send-now --employee=12   the same, one employee only
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/operations/includes/ops_attendance_reminder.php';

if (!ops_reminder_ready($conn)) {
    fwrite(STDERR, "Run migrations/ops_staff_push.sql first.\n");
    exit(1);
}
$now = ops_reminder_now();
$day = $now->format('Y-m-d');
$args = array_slice($argv, 1);
$only = null;
foreach ($args as $a) {
    if (preg_match('/^--employee=(\d+)$/', $a, $m)) {
        $only = (int)$m[1];
    }
}
$print = static function (array $report) use ($day): void {
    echo "Day {$day}: {$report['considered']} people with reminders on, {$report['sent']} reminded.\n";
    foreach ($report['people'] as $p) {
        echo "  {$p['employee']}  {$p['title']}" . (isset($p['phones']) ? "  (phones {$p['phones']}, delivered {$p['delivered']})" : '') . "\n      {$p['body']}\n";
    }
    foreach ($report['skipped'] as $why => $n) {
        echo "  skipped {$n}: {$why}\n";
    }
};

$employees = ops_reminder_employees_with_phones($conn);
if ($only !== null) {
    $employees = array_values(array_intersect($employees, [$only]));
}
if (!$employees) {
    echo "No phones have reminders turned on" . ($only !== null ? " for employee $only" : '') . ".\n";
    exit(0);
}

if (in_array('--dry-run', $args, true)) {
    foreach ($employees as $eid) {
        $s = ops_reminder_settings($conn, $eid);
        echo "-- employee $eid: reminders " . ($s['enabled'] ? "on at {$s['time']}" : 'off') . " (Dubai)\n";
    }
    $print(ops_reminder_run($conn, $day, true, $employees));
} elseif (in_array('--send-now', $args, true)) {
    // Forget today's sends for these people, then send.
    $forgetLog = $conn->prepare("DELETE FROM staff_push_log WHERE work_date = ? AND employee_id = ?");
    $forgetRun = $conn->prepare("DELETE FROM staff_push_runs WHERE work_date = ? AND company_id = ?");
    $claim = $conn->prepare("INSERT INTO staff_push_runs (work_date, company_id) VALUES (?, ?)");
    foreach ($employees as $eid) {
        $forgetLog->execute([$day, $eid]);
        $forgetRun->execute([$day, -$eid]);
        $claim->execute([$day, -$eid]);
    }
    $print(ops_reminder_run($conn, $day, false, $employees));
} else {
    $won = ops_reminder_claim_due($conn, $now, $only !== null ? [$only] : null);
    if (!$won) {
        echo "Nothing due: nobody's reminder time has passed, or each has already had today's, or is switched off.\n";
        exit(0);
    }
    $print(ops_reminder_run($conn, $day, false, $won));
}
