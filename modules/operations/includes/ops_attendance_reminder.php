<?php
/**
 * The daily attendance reminder for the staff app (/staff/), by web push.
 * Each employee has their own time and on/off switch, set by HR on the employee
 * profile (Overview → Mobile App PIN → Check-in reminders); on at 18:00 until
 * HR changes it.
 *
 * WHO GETS ONE
 * ------------
 * Someone with reminders turned on in the staff app (staff_push_subscriptions)
 * whose employee is current, whose switch is on, who is due to work today, and
 * who has either not checked in at all or checked in and not out. Not when the
 * day is HR-marked (leave, absent, half, excused), covered by approved leave, a
 * holiday, their weekly off, or a day off on their work schedule (only where a schedule exists —
 * shifts vary, so no schedule means every day counts).
 *
 * ONCE A DAY, WITHOUT A CRON
 * --------------------------
 * The first request after a person's reminder time claims that person's day in
 * staff_push_runs (INSERT IGNORE on work_date + company_id, where a person's
 * claim is stored as company_id = -employee_id; only one request can win) and
 * sends after its own reply has gone out where the server allows it.
 * staff_push_log holds one row per person per day, so nothing is ever sent
 * twice. cron/attendance_reminder.php does the same if a scheduled task is ever
 * added; whichever comes first does the day.
 *
 * The time is company time (Asia/Dubai), whatever the server's own clock is.
 * Off until migrations/ops_staff_push.sql has run.
 */

require_once __DIR__ . '/ops_attendance.php';
require_once dirname(__DIR__, 3) . '/includes/web_push.php';
require_once dirname(__DIR__, 3) . '/hr/includes/hr_schedule_helper.php';
require_once dirname(__DIR__, 3) . '/hr/includes/hr_employee_lifecycle.php';
require_once dirname(__DIR__, 3) . '/hr/includes/hr_weekly_off.php';

/** The texts, exactly as agreed. */
function ops_reminder_message(string $kind, string $firstName, string $checkIn = ''): array
{
    if ($kind === 'no_check_out') {
        return [
            'title' => 'Check-out missing',
            'body' => $firstName . ", you checked in at " . $checkIn . " but haven't checked out. If this isn't corrected, today may be marked as leave. Tap to check out.",
        ];
    }
    return [
        'title' => 'Attendance not recorded today',
        'body' => $firstName . ", you haven't checked in or out today. If this isn't corrected, today may be marked as leave. Tap to check in.",
    ];
}

/** Off (and harmless) until migrations/ops_staff_push.sql has run, including the per-company runs table. */
function ops_reminder_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (int)$conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('staff_push_subscriptions','staff_push_log','staff_push_runs')")->fetchColumn() === 3
                && (int)$conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'staff_push_runs' AND COLUMN_NAME = 'company_id'")->fetchColumn() === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * One employee's reminder time (H:i, Dubai) and on/off switch, kept in
 * `settings` as attendance_reminder_time.e12 / attendance_reminder_enabled.e12.
 * Someone HR has not set is on at 18:00.
 */
function ops_reminder_settings(PDO $conn, ?int $employeeId = null): array
{
    $all = ops_reminder_settings_rows($conn);
    $out = ['time' => '18:00', 'enabled' => true];
    if (!$employeeId) {
        return $out;
    }
    $time = (string)($all['attendance_reminder_time.e' . $employeeId] ?? '');
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $out['time'] = $time;
    }
    if (array_key_exists('attendance_reminder_enabled.e' . $employeeId, $all)) {
        $out['enabled'] = !in_array(strtolower(trim((string)$all['attendance_reminder_enabled.e' . $employeeId])), ['0', 'off', 'no', 'false'], true);
    }
    return $out;
}

/** Save one employee's reminder time and switch. */
function ops_reminder_save_settings(PDO $conn, int $employeeId, bool $enabled, string $time): void
{
    $save = $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $save->execute(['attendance_reminder_enabled.e' . $employeeId, $enabled ? '1' : '0']);
    $save->execute(['attendance_reminder_time.e' . $employeeId, $time]);
    ops_reminder_settings_rows($conn, true);
}

/** Every attendance_reminder_* setting, read once per request. */
function ops_reminder_settings_rows(PDO $conn, bool $refresh = false): array
{
    static $rows = null;
    if ($rows === null || $refresh) {
        try {
            $st = $conn->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'attendance\_reminder\_%'");
            $rows = $st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        } catch (Throwable $e) {
            $rows = [];
        }
    }
    return $rows;
}

/** The employee record a person's reminder follows: the first one on their login. */
function ops_reminder_user_employee(PDO $conn, int $userId): ?int
{
    $st = $conn->prepare("SELECT id FROM employees WHERE user_id = ? ORDER BY id ASC LIMIT 1");
    $st->execute([$userId]);
    $eid = $st->fetchColumn();
    return $eid ? (int)$eid : null;
}

/** Employees with at least one phone turned on, by the same employee record the run uses. */
function ops_reminder_employees_with_phones(PDO $conn): array
{
    return array_map('intval', $conn->query("
        SELECT DISTINCT (SELECT e.id FROM employees e WHERE e.user_id = s.user_id ORDER BY e.id ASC LIMIT 1) AS eid
        FROM staff_push_subscriptions s
        HAVING eid IS NOT NULL
    ")->fetchAll(PDO::FETCH_COLUMN));
}

function ops_reminder_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Dubai'));
}

/**
 * Claim today's run for each employee whose reminder time has passed (switched
 * on, with a phone to send to). INSERT IGNORE on (work_date, company_id), with
 * the employee stored as company_id = -employee_id: only one request or cron
 * run can win each person's day.
 *
 * @param list<int>|null $only limit to these employees
 * @return list<int> the employees this caller won
 */
function ops_reminder_claim_due(PDO $conn, DateTimeImmutable $now, ?array $only = null): array
{
    $day = $now->format('Y-m-d');
    $claim = $conn->prepare("INSERT IGNORE INTO staff_push_runs (work_date, company_id, started_at) VALUES (?, ?, ?)");
    $won = [];
    $employees = ops_reminder_employees_with_phones($conn);
    if ($only !== null) {
        $employees = array_values(array_intersect($employees, $only));
    }
    foreach ($employees as $eid) {
        $s = ops_reminder_settings($conn, $eid);
        if (!$s['enabled'] || $now->format('H:i') < $s['time']) {
            continue;
        }
        $claim->execute([$day, -$eid, $now->format('Y-m-d H:i:s')]);
        if ($claim->rowCount() === 1) {
            $won[] = $eid;
        }
    }
    return $won;
}

/**
 * Call on any request. Does nothing until someone's reminder time; then the
 * first caller after it sends to them (after its own response, where possible).
 */
function ops_reminder_maybe_run(PDO $conn): void
{
    if (!ops_reminder_ready($conn)) {
        return;
    }
    $now = ops_reminder_now();
    try {
        $employees = ops_reminder_claim_due($conn, $now);
    } catch (Throwable $e) {
        return;
    }
    if (!$employees) {
        return;
    }
    $day = $now->format('Y-m-d');
    // Send after the caller's reply where the server allows it, so nobody waits on push services.
    register_shutdown_function(static function () use ($conn, $day, $employees): void {
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        try {
            ops_reminder_run($conn, $day, false, $employees);
        } catch (Throwable $e) {
            error_log('attendance reminder failed: ' . $e->getMessage());
        }
    });
}

/**
 * Whether someone checked in and not out is simply still at work: their
 * schedule that day has a duty that started near their check in (within 3
 * hours) and has not ended yet — a 17:00 to 03:00 shift is not "Check-out
 * missing" at 18:00. Without a schedule, nothing changes.
 */
function ops_reminder_still_on_duty(PDO $conn, array $employee, array $row, DateTimeImmutable $now): bool
{
    if (empty($row['check_in']) || empty($row['work_date'])) {
        return false;
    }
    $day = (string)$row['work_date'];
    $tz = $now->getTimezone();
    $summary = hr_schedule_summary($conn, (int)$employee['id'], $day, $day);
    $checkIn = new DateTimeImmutable($day . ' ' . $row['check_in'], $tz);
    // Checked in for one duty and never out: on duty until that duty, or a
    // later one the same day (double duty entered as one span), has ended.
    $matched = false;
    foreach (hr_schedule_ranges($summary['days'][$day]['label'] ?? '') as $r) {
        $start = new DateTimeImmutable($day . ' ' . $r['start'], $tz);
        $end = new DateTimeImmutable($day . ' ' . $r['end'], $tz);
        if ($r['overnight']) {
            $end = $end->modify('+1 day');
        }
        $matched = $matched || abs($checkIn->getTimestamp() - $start->getTimestamp()) <= 3 * 3600;
        if ($matched && $now < $end) {
            return true;
        }
    }
    return false;
}

/**
 * Whether today is a working day for the reminder: not the person's weekly off,
 * not a holiday, not approved leave, and not a day off where the person has a
 * work schedule.
 */
function ops_reminder_is_workday(PDO $conn, array $employee, string $day): bool
{
    $weeklyOff = hr_weekly_off_for_employee($conn, (int)$employee['id'], isset($employee['company_id']) ? (int)$employee['company_id'] : null, $day);
    if ((int)date('N', strtotime($day)) === $weeklyOff) {
        return false;
    }
    $hol = $conn->prepare("
        SELECT COUNT(*) FROM holidays
        WHERE (location_id IS NULL OR location_id = ?)
          AND (holiday_date = ? OR (repeats_annually = 1 AND DATE_FORMAT(holiday_date, '%m-%d') = DATE_FORMAT(?, '%m-%d')))
    ");
    $hol->execute([$employee['location_id'] ?? null, $day, $day]);
    if ((int)$hol->fetchColumn() > 0) {
        return false;
    }
    $leave = $conn->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND ? BETWEEN date_from AND date_to");
    $leave->execute([(int)$employee['id'], $day]);
    if ((int)$leave->fetchColumn() > 0) {
        return false;
    }
    $sched = hr_schedule_summary($conn, (int)$employee['id'], $day, $day);
    if (!empty($sched['has_schedule']) && empty($sched['days'][$day]['is_workday'])) {
        return false;
    }
    return true;
}

/**
 * Work out who needs a reminder for $day and send it. With $employeeIds, only
 * those people (each person runs at their own time).
 *
 * @param list<int>|null $employeeIds
 * @return array{considered:int, sent:int, skipped:array<string,int>, people:list<array>}
 */
function ops_reminder_run(PDO $conn, string $day, bool $dryRun = false, ?array $employeeIds = null): array
{
    $report = ['considered' => 0, 'sent' => 0, 'skipped' => [], 'people' => []];
    $skip = static function (string $why) use (&$report): void {
        $report['skipped'][$why] = ($report['skipped'][$why] ?? 0) + 1;
    };
    $users = $conn->query("SELECT DISTINCT user_id FROM staff_push_subscriptions")->fetchAll(PDO::FETCH_COLUMN);
    $current = hr_employee_current_statuses();
    $empStmt = $conn->prepare("SELECT id, full_name, employee_code, company_id, location_id, status FROM employees WHERE user_id = ? ORDER BY id ASC LIMIT 1");
    $subsStmt = $conn->prepare("SELECT id, endpoint, p256dh, auth FROM staff_push_subscriptions WHERE user_id = ?");
    $logStmt = $conn->prepare("INSERT IGNORE INTO staff_push_log (user_id, employee_id, work_date, kind) VALUES (?, ?, ?, ?)");
    $sentTo = [];

    foreach ($users as $userId) {
        $userId = (int)$userId;
        $empStmt->execute([$userId]);
        $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
        if ($employeeIds !== null && (!$employee || !in_array((int)$employee['id'], $employeeIds, true))) {
            continue; // someone else's run
        }
        $report['considered']++;
        if (!$employee) { $skip('no employee record'); continue; }
        if (!in_array((string)$employee['status'], $current, true)) { $skip('not a current employee'); continue; }
        if (!ops_reminder_settings($conn, (int)$employee['id'])['enabled']) { $skip('switched off by HR'); continue; }

        $row = ops_attendance_current($conn, (int)$employee['id'], $day . ' 12:00:00');
        if ($row && !ops_attendance_status_is_open($row['status'] ?? null)) { $skip('day marked by HR'); continue; }
        if ($row && !empty($row['check_out'])) { $skip('checked in and out'); continue; }
        if (!ops_reminder_is_workday($conn, $employee, $day)) { $skip('holiday, leave, weekly off or day off'); continue; }

        $kind = ($row && !empty($row['check_in'])) ? 'no_check_out' : 'not_checked_in';
        if ($kind === 'no_check_out' && ops_reminder_still_on_duty($conn, $employee, $row, ops_reminder_now())) { $skip('still on duty (schedule)'); continue; }
        $firstName = preg_split('/\s+/', trim((string)$employee['full_name']))[0] ?: 'Hello';
        $msg = ops_reminder_message($kind, $firstName, $row ? substr((string)$row['check_in'], 0, 5) : '');
        $person = ['user_id' => $userId, 'employee' => $employee['employee_code'], 'kind' => $kind, 'title' => $msg['title'], 'body' => $msg['body']];

        if ($dryRun) { $report['people'][] = $person; continue; }
        $logStmt->execute([$userId, (int)$employee['id'], $day, $kind]);
        if ($logStmt->rowCount() !== 1) { $skip('already reminded today'); continue; }

        $subsStmt->execute([$userId]);
        $subs = $subsStmt->fetchAll(PDO::FETCH_ASSOC);
        try {
            $results = web_push_send_many($conn, $subs, $msg + ['url' => './', 'tag' => 'attendance-reminder']);
        } catch (Throwable $e) {
            // One person's send failing must not stop everyone else's.
            error_log('attendance reminder: send to user ' . $userId . ' failed: ' . $e->getMessage());
            $results = [];
        }
        $delivered = 0;
        foreach ($results as $r) {
            $sid = (int)$r['target']['id'];
            if ($r['gone']) {
                $conn->prepare("DELETE FROM staff_push_subscriptions WHERE id = ?")->execute([$sid]);
                continue;
            }
            $conn->prepare("UPDATE staff_push_subscriptions SET last_sent_at = NOW(), last_status = ? WHERE id = ?")->execute([$r['status'], $sid]);
            if ($r['status'] >= 200 && $r['status'] < 300) {
                $delivered++;
            }
        }
        $conn->prepare("UPDATE staff_push_log SET phones = ?, delivered = ? WHERE user_id = ? AND work_date = ?")
            ->execute([count($subs), $delivered, $userId, $day]);
        $report['sent'] += $delivered > 0 ? 1 : 0;
        $sentTo[(int)$employee['id']] = $delivered > 0 ? 1 : 0;
        $report['people'][] = $person + ['phones' => count($subs), 'delivered' => $delivered];
    }
    if (!$dryRun && $employeeIds !== null) {
        $done = $conn->prepare("UPDATE staff_push_runs SET finished_at = NOW(), sent = ? WHERE work_date = ? AND company_id = ?");
        foreach ($employeeIds as $eid) {
            $done->execute([$sentTo[(int)$eid] ?? 0, $day, -(int)$eid]);
        }
    }
    return $report;
}

/** Save (or refresh) one phone's subscription for a user. */
function ops_push_subscribe(PDO $conn, int $userId, string $endpoint, string $p256dh, string $auth, string $userAgent): bool
{
    if (!preg_match('#^https://#i', $endpoint) && !preg_match('#^http://localhost(:\d+)?/#i', $endpoint)) {
        return false;
    }
    if (strlen(web_push_b64u_decode($p256dh)) !== 65 || strlen(web_push_b64u_decode($auth)) !== 16) {
        return false;
    }
    $conn->prepare("
        INSERT INTO staff_push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, user_agent, last_seen_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth),
            user_agent = VALUES(user_agent), last_seen_at = NOW()
    ")->execute([$userId, hash('sha256', $endpoint), $endpoint, $p256dh, $auth, mb_substr($userAgent, 0, 255)]);
    return true;
}

/**
 * What HR sees on the profile: this person's phones with reminders on, and the
 * last reminder sent. Read-only.
 *
 * @return array{phones:list<array{device:string,last_seen:?string}>, last:?array}
 */
function ops_reminder_status(PDO $conn, int $userId): array
{
    $subs = $conn->prepare("SELECT user_agent, COALESCE(last_seen_at, created_at) AS seen
                            FROM staff_push_subscriptions WHERE user_id = ? ORDER BY seen DESC");
    $subs->execute([$userId]);
    $phones = [];
    foreach ($subs->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $phones[] = ['device' => ops_reminder_device_label((string)$s['user_agent']), 'last_seen' => $s['seen']];
    }
    $last = $conn->prepare("SELECT work_date, kind, phones, delivered FROM staff_push_log
                            WHERE user_id = ? ORDER BY work_date DESC LIMIT 1");
    $last->execute([$userId]);
    return ['phones' => $phones, 'last' => $last->fetch(PDO::FETCH_ASSOC) ?: null];
}

/** "Android · Chrome", "iPhone · Safari" — from the browser's user agent. */
function ops_reminder_device_label(string $ua): string
{
    $device = match (true) {
        (bool)preg_match('/iPhone|iPod/i', $ua) => 'iPhone',
        (bool)preg_match('/iPad/i', $ua) => 'iPad',
        (bool)preg_match('/Android/i', $ua) => 'Android',
        (bool)preg_match('/Windows/i', $ua) => 'Windows',
        (bool)preg_match('/Macintosh|Mac OS X/i', $ua) => 'Mac',
        default => 'Phone',
    };
    $browser = match (true) {
        (bool)preg_match('/SamsungBrowser/i', $ua) => 'Samsung Internet',
        (bool)preg_match('/Edg\//i', $ua) => 'Edge',
        (bool)preg_match('/Firefox|FxiOS/i', $ua) => 'Firefox',
        (bool)preg_match('/Chrome|CriOS/i', $ua) => 'Chrome',
        (bool)preg_match('/Safari/i', $ua) => 'Safari',
        default => '',
    };
    return $browser !== '' ? "$device · $browser" : $device;
}

function ops_push_unsubscribe(PDO $conn, int $userId, string $endpoint): void
{
    $conn->prepare("DELETE FROM staff_push_subscriptions WHERE endpoint_hash = ? AND user_id = ?")
        ->execute([hash('sha256', $endpoint), $userId]);
}
