<?php
/**
 * The attendance reminder, from the command line.
 *
 * Not needed day to day: the first staff-app request after a company's reminder
 * time already runs it (modules/operations/includes/ops_attendance_reminder.php).
 * This is for a scheduled task if one is ever wanted, and for testing. Each
 * company runs at its own time (HR → Reminders).
 *
 *   php cron/attendance_reminder.php                     run every company whose time has passed and has not run today
 *   php cron/attendance_reminder.php --dry-run           who would get what today, per company, nothing sent
 *   php cron/attendance_reminder.php --send-now          TESTING: forget today's runs and send now, whatever the time
 *   php cron/attendance_reminder.php --send-now --company=3   the same, one company only
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
    if (preg_match('/^--company=(\d+)$/', $a, $m)) {
        $only = (int)$m[1];
    }
}
$companyName = static function (int $cid) use ($conn): string {
    $st = $conn->prepare("SELECT name FROM companies WHERE id = ?");
    $st->execute([$cid]);
    return (string)($st->fetchColumn() ?: "Company $cid");
};
$print = static function (int $cid, array $report) use ($day, $companyName): void {
    echo "{$companyName($cid)} (company $cid), day {$day}: {$report['considered']} people with reminders on, {$report['sent']} reminded.\n";
    foreach ($report['people'] as $p) {
        echo "  {$p['employee']}  {$p['title']}" . (isset($p['phones']) ? "  (phones {$p['phones']}, delivered {$p['delivered']})" : '') . "\n      {$p['body']}\n";
    }
    foreach ($report['skipped'] as $why => $n) {
        echo "  skipped {$n}: {$why}\n";
    }
};

$companies = ops_reminder_companies_with_phones($conn);
if ($only !== null) {
    $companies = array_values(array_intersect($companies, [$only]));
}
if (!$companies) {
    echo "No phones have reminders turned on" . ($only !== null ? " in company $only" : '') . ".\n";
    exit(0);
}

if (in_array('--dry-run', $args, true)) {
    foreach ($companies as $cid) {
        $s = ops_reminder_settings($conn, $cid);
        echo "-- reminders " . ($s['enabled'] ? "on at {$s['time']}" : 'off') . " (Dubai)\n";
        $print($cid, ops_reminder_run($conn, $day, true, $cid));
    }
} elseif (in_array('--send-now', $args, true)) {
    foreach ($companies as $cid) {
        // Forget today's run and log for this company's people, then send.
        $conn->prepare("DELETE l FROM staff_push_log l JOIN employees e ON e.id = l.employee_id WHERE l.work_date = ? AND e.company_id = ?")
            ->execute([$day, $cid]);
        $conn->prepare("DELETE FROM staff_push_runs WHERE work_date = ? AND company_id = ?")->execute([$day, $cid]);
        $conn->prepare("INSERT INTO staff_push_runs (work_date, company_id) VALUES (?, ?)")->execute([$day, $cid]);
        $print($cid, ops_reminder_run($conn, $day, false, $cid));
    }
} else {
    $won = ops_reminder_claim_due($conn, $now, $only !== null ? [$only] : null);
    if (!$won) {
        echo "Nothing due: no company's reminder time has passed, or each has already run today, or is switched off.\n";
        exit(0);
    }
    foreach ($won as $cid) {
        $print($cid, ops_reminder_run($conn, $day, false, $cid));
    }
}
