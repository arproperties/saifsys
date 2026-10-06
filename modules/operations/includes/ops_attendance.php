<?php
/**
 * Operations — staff check in and out from the field app.
 *
 * WRITES HR'S OWN ATTENDANCE, NOT A COPY OF IT
 * --------------------------------------------
 * One row per person per day in `attendance` — the table hr/attendance.php
 * reads and writes, the one v_attendance_daily_emp turns into present days,
 * hours and overtime, and the one payroll runs on. The shape is exactly what
 * A check-in from the app writes status 'pending' — the time is recorded, the
 * day is not yet decided. The check out approves it; until then HR can approve
 * it by hand (or mark it absent, half, on leave or excused).
 *
 * HR's own Quick add writes: status 'approved' (present), check_in, check_out,
 * and hours = check_out − check_in rounded to two places. The only difference
 * is `source = 'self'` — an enum value the table already had for exactly this,
 * so HR can always tell a row a person recorded from one HR typed.
 *
 * Check in is the day's first entry, check out its last. One of each: the
 * unique key on (employee_id, work_date) is HR's, and a second check in the
 * same day is answered with the first rather than moving the time.
 *
 * A FORGOTTEN CHECK OUT
 * ---------------------
 * A day is never carried into the next one. It used to be — yesterday's open
 * row was shown as "still checked in", so the morning after a forgotten check
 * out the app offered Check out instead of Check in, and tapping it closed
 * yesterday at 24 hours. Now the open day is handed to the app as `unclosed`,
 * and the app asks the person what time they left before it offers anything
 * else (ops_attendance_close_previous). That time is their word, not a tap, so
 * the day stays 'pending' for HR and says so in its notes.
 *
 * THE TAP, WITHIN LIMITS
 * ----------------------
 * These times are pay. A check in tapped with no signal is kept on the phone
 * and sent later, so the time recorded is when it was tapped — the moment the
 * server received it would pay somebody from when they walked out of the
 * basement. The phone's clock is only believed within a day and a half back and
 * five minutes ahead (ops_api_client_time); outside that the server's own time
 * is used. A row whose time came from the phone rather than the moment it
 * arrived says so in its notes, so HR can always tell.
 *
 * HR STILL DECIDES
 * ----------------
 * If HR has already marked the day as leave, absent or a half day, the app
 * does not overwrite it — the person is told to speak to HR. And a row HR has
 * entered with a check-in already on it is left as HR wrote it.
 */

/**
 * The employee record behind a signed-in user, or null.
 *
 * Attendance is keyed by employees.id, and app users are user.id — the link is
 * employees.user_id, the same one maintenance write-back uses.
 */
function ops_attendance_employee(PDO $conn, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT id, full_name, employee_code, company_id
        FROM employees
        WHERE user_id = ?
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    return $employee ?: null;
}

/** How many days back the app asks about a forgotten check out. Older is HR's. */
const OPS_ATTENDANCE_UNCLOSED_DAYS = 7;

/** The attendance row for the day a moment falls on (default: now), or null. */
function ops_attendance_current(PDO $conn, int $employeeId, ?string $at = null): ?array
{
    $today = substr($at ?? date('Y-m-d H:i:s'), 0, 10);
    $stmt = $conn->prepare("SELECT * FROM attendance WHERE employee_id = ? AND work_date = ? LIMIT 1");
    $stmt->execute([$employeeId, $today]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * The latest earlier day this person checked in on and never checked out of.
 *
 * Only days still 'pending': once HR has approved or marked a day it is
 * decided, and the app must not ask about it or write over it.
 */
function ops_attendance_unclosed(PDO $conn, int $employeeId, ?string $at = null): ?array
{
    $today = substr($at ?? date('Y-m-d H:i:s'), 0, 10);
    $stmt = $conn->prepare("
        SELECT * FROM attendance
        WHERE employee_id = ? AND work_date < ? AND work_date >= ?
          AND check_in IS NOT NULL AND check_out IS NULL
          AND source = 'self' AND status = 'pending'
        ORDER BY work_date DESC
        LIMIT 1
    ");
    $stmt->execute([
        $employeeId,
        $today,
        date('Y-m-d', strtotime($today . ' -' . OPS_ATTENDANCE_UNCLOSED_DAYS . ' days')),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * The attendance state as the app reads it.
 *
 * `unclosed` is an earlier day with a check in and no check out. While it is
 * set the app asks for the time they left and offers nothing else.
 *
 * @return array{state:string, work_date:?string, check_in:?string, check_out:?string, hours:?float, message:?string, unclosed:?array}
 */
function ops_attendance_payload(?array $row, ?array $unclosed = null): array
{
    $state = 'not_checked_in';
    $message = null;
    if ($row) {
        if (!ops_attendance_status_is_open($row['status'] ?? null) && empty($row['check_in'])) {
            $state = 'hr_marked';
            $message = 'HR has marked today as ' . ops_attendance_status_words((string)$row['status']) . '.';
        } elseif (!empty($row['check_out'])) {
            $state = 'checked_out';
        } elseif (!empty($row['check_in'])) {
            $state = 'checked_in';
        }
    }

    return [
        'state' => $state,
        'work_date' => $row['work_date'] ?? null,
        'check_in' => !empty($row['check_in']) ? substr((string)$row['check_in'], 0, 5) : null,
        'check_out' => !empty($row['check_out']) ? substr((string)$row['check_out'], 0, 5) : null,
        'hours' => isset($row['hours']) && $row['hours'] !== null ? (float)$row['hours'] : null,
        'message' => $message,
        'unclosed' => $unclosed ? [
            'work_date' => $unclosed['work_date'],
            'check_in' => substr((string)$unclosed['check_in'], 0, 5),
        ] : null,
    ];
}

/**
 * Is the day still the worker's to fill in? 'pending' is what a fresh row is,
 * and 'approved' is HR agreeing they worked — neither blocks a check-in. The
 * rest mean HR has already decided the day and the app must not overwrite it.
 */
function ops_attendance_status_is_open(?string $status): bool
{
    return in_array((string)($status ?: 'pending'), ['pending', 'approved'], true);
}

function ops_attendance_status_words(string $status): string
{
    return ['absent' => 'absent', 'half' => 'a half day', 'on_leave' => 'leave', 'excused_absent' => 'an excused absence', 'pending' => 'not yet approved'][$status] ?? $status;
}

/**
 * Check in: the day's first entry.
 *
 * @return array{ok:bool, error?:string, message?:string, row?:?array}
 */
function ops_attendance_check_in(PDO $conn, array $employee, int $userId, ?string $at = null): array
{
    $employeeId = (int)$employee['id'];
    $at = $at ?? date('Y-m-d H:i:s');
    $today = substr($at, 0, 10);
    $now = date('H:i:00', strtotime($at));
    $notes = ops_attendance_note('Checked in on the operations app', $at);

    $existing = ops_attendance_current($conn, $employeeId, $at);

    // Already in. Answer with it rather than moving the time: the first tap is
    // the one that counts.
    if ($existing && !empty($existing['check_in'])) {
        return ['ok' => true, 'row' => $existing];
    }

    if ($existing && $existing['work_date'] === $today) {
        if (!ops_attendance_status_is_open($existing['status'] ?? null)) {
            return [
                'ok' => false,
                'error' => 'hr_marked',
                'message' => 'HR has marked today as ' . ops_attendance_status_words((string)$existing['status'])
                           . '. Speak to HR if that is wrong.',
            ];
        }
        // HR created the day but left the time empty: fill it in, nothing else.
        $conn->prepare("
            UPDATE attendance SET check_in = ?, updated_by = ?, updated_at = ?
            WHERE id = ? AND check_in IS NULL
        ")->execute([$now, $userId, date('Y-m-d H:i:s'), (int)$existing['id']]);
    } else {
        try {
            $conn->prepare("
                INSERT INTO attendance
                    (employee_id, work_date, check_in, check_out, hours, status, source, notes,
                     created_at, updated_at, created_by, updated_by, company_id)
                VALUES (?, ?, ?, NULL, NULL, 'pending', 'self', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $employeeId, $today, $now, $notes,
                date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $userId, $userId,
                (int)($employee['company_id'] ?? 1),
            ]);
        } catch (PDOException $e) {
            // Two taps in the same second, both inserting: HR's unique key keeps
            // one, and the other is simply the same check in.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    $row = ops_attendance_current($conn, $employeeId, $at);
    ops_attendance_audit($conn, 'attendance_recorded', $employee, $row, $notes, $userId);
    return ['ok' => true, 'row' => $row];
}

/**
 * Check out: the day's last entry. Hours are worked out the way HR's page does
 * — out minus in, in hours, two decimals.
 *
 * @return array{ok:bool, error?:string, message?:string, row?:?array}
 */
function ops_attendance_check_out(PDO $conn, array $employee, int $userId, ?string $at = null): array
{
    $employeeId = (int)$employee['id'];
    $at = $at ?? date('Y-m-d H:i:s');
    $row = ops_attendance_current($conn, $employeeId, $at);

    if (!$row || empty($row['check_in'])) {
        return ['ok' => false, 'error' => 'not_checked_in', 'message' => 'Check in first.'];
    }
    if (!empty($row['check_out'])) {
        return ['ok' => true, 'row' => $row];
    }

    $in = strtotime($row['work_date'] . ' ' . $row['check_in']);
    // Never before the check in it closes.
    $outTs = max((int)strtotime(date('Y-m-d H:i:00', strtotime($at))), (int)$in);
    $hours = $in !== false ? round(max(0, $outTs - $in) / 3600, 2) : null;
    // A check out closes a complete day, so it approves itself (pending only;
    // anything HR already set is left alone).
    $conn->prepare("
        UPDATE attendance SET check_out = ?, hours = ?, updated_by = ?, updated_at = ?,
               status = IF(status = 'pending', 'approved', status)
        WHERE id = ? AND check_out IS NULL
    ")->execute([date('H:i:00', $outTs), $hours, $userId, date('Y-m-d H:i:s'), (int)$row['id']]);

    $stmt = $conn->prepare("SELECT * FROM attendance WHERE id = ?");
    $stmt->execute([(int)$row['id']]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;

    ops_attendance_audit($conn, 'attendance_updated', $employee, $fresh, ops_attendance_note('Checked out on the operations app', $at), $userId);
    return ['ok' => true, 'row' => $fresh];
}

/**
 * Close an earlier day the person forgot to check out of, at the time they say
 * they left.
 *
 * Nothing here is a tap, so nothing here is trusted the way a tap is: the time
 * has to fall after that day's check in and on that same day, the day has to
 * be recent and still undecided, and it is left 'pending' — a real check out
 * approves itself, this one waits for HR. The notes say who entered it, when.
 *
 * @return array{ok:bool, error?:string, message?:string}
 */
function ops_attendance_close_previous(PDO $conn, array $employee, int $userId, string $workDate, string $time, ?string $at = null): array
{
    $employeeId = (int)$employee['id'];
    $at = $at ?? date('Y-m-d H:i:s');
    $today = substr($at, 0, 10);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        return ['ok' => false, 'error' => 'bad_time', 'message' => 'Choose the time you left.'];
    }

    $stmt = $conn->prepare("SELECT * FROM attendance WHERE employee_id = ? AND work_date = ? LIMIT 1");
    $stmt->execute([$employeeId, $workDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Sent twice, or HR got there first: the day is closed either way.
    if ($row && !empty($row['check_out'])) {
        return ['ok' => true];
    }
    $earliest = date('Y-m-d', strtotime($today . ' -' . OPS_ATTENDANCE_UNCLOSED_DAYS . ' days'));
    if (!$row || empty($row['check_in']) || $row['source'] !== 'self' || $row['status'] !== 'pending'
        || $workDate >= $today || $workDate < $earliest) {
        return ['ok' => false, 'error' => 'not_open', 'message' => 'That day cannot be closed from the app. Speak to HR.'];
    }

    $checkIn = substr((string)$row['check_in'], 0, 5);
    if ($time <= $checkIn) {
        return ['ok' => false, 'error' => 'bad_time', 'message' => 'The time you left must be after your check in at ' . $checkIn . '.'];
    }
    $hours = round((strtotime($workDate . ' ' . $time) - strtotime($workDate . ' ' . $checkIn)) / 3600, 2);

    $what = 'Check out time entered by staff on ' . date('j M', strtotime($at)) . ' (forgot to check out)';
    $notes = trim((string)($row['notes'] ?? ''));
    $notes = mb_substr(($notes !== '' ? $notes . ' · ' : '') . $what, 0, 255);

    $conn->prepare("
        UPDATE attendance SET check_out = ?, hours = ?, notes = ?, updated_by = ?, updated_at = ?
        WHERE id = ? AND check_out IS NULL
    ")->execute([$time . ':00', $hours, $notes, $userId, date('Y-m-d H:i:s'), (int)$row['id']]);

    $stmt = $conn->prepare("SELECT * FROM attendance WHERE id = ?");
    $stmt->execute([(int)$row['id']]);
    ops_attendance_audit($conn, 'attendance_updated', $employee, $stmt->fetch(PDO::FETCH_ASSOC) ?: $row, $what, $userId);
    return ['ok' => true];
}

/** "Checked in on the operations app", plus when it reached the server if that was later. */
function ops_attendance_note(string $what, string $at): string
{
    $arrived = time();
    $tapped = strtotime($at);
    if ($tapped !== false && $arrived - $tapped >= 120) {
        return $what . ' — tapped with no signal at ' . date('H:i', $tapped)
             . ', received ' . date('j M H:i', $arrived);
    }
    return $what;
}

/** The same audit trail HR's own attendance page writes. Never allowed to fail the tap. */
function ops_attendance_audit(PDO $conn, string $action, array $employee, ?array $row, string $what, int $userId): void
{
    if (!$row) {
        return;
    }
    try {
        require_once dirname(__DIR__, 3) . '/includes/audit_bridge.php';
        if (!function_exists('audit_bridge_hr_ops')) {
            return;
        }
        $label = trim(($employee['full_name'] ?? '') . ' (' . ($employee['employee_code'] ?? ('#' . $employee['id'])) . ')');
        audit_bridge_hr_ops(
            $action,
            'attendance',
            (int)$row['id'],
            $what . ' — ' . $label . ' on ' . $row['work_date'],
            isset($employee['company_id']) ? (int)$employee['company_id'] : null,
            [
                'employee_id' => (int)$employee['id'],
                'work_date' => $row['work_date'],
                'check_in' => $row['check_in'],
                'check_out' => $row['check_out'],
                'hours' => $row['hours'],
                'source' => 'self',
            ],
            $label . ' @ ' . $row['work_date'],
            $userId
        );
    } catch (Throwable $e) {
        error_log('ops_attendance_audit failed: ' . $e->getMessage());
    }
}
