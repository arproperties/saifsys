<?php
/**
 * Attendance locks for the HR screens (added 2026-11-12, after the Glaiza
 * E00029 review).
 *
 *  1. Date lock: a day older than HR_ATTENDANCE_LOCK_DAYS days (Dubai date) is
 *     locked. Only an Owner can add, change or delete it, and a change needs a
 *     written reason. (Admin is not enough: on 12 Nov 2026 every HR user also
 *     had the Admin role, so Owner / Admin would have locked nobody.)
 *  2. Exit lock: nobody can add or change attendance after an employee's last
 *     working day (last_working_day, else exit_date). Deleting stays possible
 *     for an Owner so wrong rows can be cleaned up.
 *
 * Staff self check-in, cameras and the Operations sync do not use this file:
 * they only write today's row.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/hr_employee_lifecycle.php';

const HR_ATTENDANCE_LOCK_DAYS = 3;
/** Roles that may change locked days. Add 'Admin' here to let Admins do it too. */
const HR_ATTENDANCE_LOCK_OVERRIDE_ROLES = ['Owner'];

/** Today's date in Dubai (the server runs in UTC on live). */
function hr_attendance_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
}

/** First date that is still open for normal HR edits. */
function hr_attendance_lock_cutoff(): string
{
    return (new DateTimeImmutable(hr_attendance_today()))->modify('-' . HR_ATTENDANCE_LOCK_DAYS . ' days')->format('Y-m-d');
}

/** True when the day is older than the lock period. */
function hr_attendance_date_locked(string $workDate): bool
{
    return $workDate !== '' && $workDate < hr_attendance_lock_cutoff();
}

/** Who may change locked days (HR_ATTENDANCE_LOCK_OVERRIDE_ROLES). */
function hr_attendance_lock_admin(PDO $conn): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = false;
        foreach (HR_ATTENDANCE_LOCK_OVERRIDE_ROLES as $role) {
            if (has_role($role, $conn)) { $cache = true; break; }
        }
    }
    return $cache;
}

/**
 * The last working day of an employee who has LEFT (status is not a current
 * one: inactive, resigned, terminated, not renewed), or null.
 * Current staff are never exit-locked, even with an old exit date on the
 * profile (12 Nov 2026: 19 active staff had one, e.g. a contract end date),
 * and junk dates before 2000 (one profile had 0001-01-01) are ignored.
 */
function hr_attendance_exit_day(PDO $conn, int $employeeId): ?string
{
    static $cache = [];
    if (!array_key_exists($employeeId, $cache)) {
        $st = $conn->prepare("SELECT status, COALESCE(last_working_day, exit_date) AS last_day FROM employees WHERE id = ? LIMIT 1");
        $st->execute([$employeeId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $v = (string)($r['last_day'] ?? '');
        $left = isset($r['status']) && !in_array($r['status'], hr_employee_current_statuses(), true);
        $cache[$employeeId] = ($left && $v >= '2000-01-01') ? $v : null;
    }
    return $cache[$employeeId];
}

/**
 * Why this write is not allowed, or null when it is.
 *
 * @param string $kind    'change' (add, edit, status) or 'delete'
 * @param string $reason  the reason / note typed for this change ('' when none)
 */
function hr_attendance_write_block(PDO $conn, int $employeeId, string $workDate, string $kind = 'change', string $reason = ''): ?string
{
    if ($workDate === '') {
        return null;
    }
    $exit = hr_attendance_exit_day($conn, $employeeId);
    if ($kind !== 'delete' && $exit !== null && $workDate > $exit) {
        return 'This employee\'s last working day is ' . date('j M Y', strtotime($exit))
            . '. Attendance after that date cannot be added or changed.';
    }
    if (hr_attendance_date_locked($workDate)) {
        if (!hr_attendance_lock_admin($conn)) {
            return date('j M Y', strtotime($workDate)) . ' is locked: attendance older than '
                . HR_ATTENDANCE_LOCK_DAYS . ' days can only be changed by an Owner.';
        }
        if ($kind !== 'delete' && trim($reason) === '') {
            return date('j M Y', strtotime($workDate)) . ' is older than ' . HR_ATTENDANCE_LOCK_DAYS
                . ' days. Write the reason for changing it in Notes.';
        }
    }
    return null;
}

/** Statuses that never carry worked hours. */
function hr_attendance_no_hours_status(string $status): bool
{
    return in_array($status, ['absent', 'excused_absent'], true);
}
