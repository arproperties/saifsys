<?php
/**
 * A night shift (in 17:00, out 03:00 the next morning) belongs to the day it
 * started: the app keeps it open past midnight, Check out closes it with the
 * real hours, and no extra day is made. A morning check in left open is still
 * a forgotten day, as before.
 *
 * Runs against the local database inside a transaction that is rolled back,
 * on dates far in the future, so nothing real is read or left behind.
 *
 *     php tests/ops_attendance_night_shift_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/hr_attendance_attachments.php';
require_once __DIR__ . '/../modules/operations/includes/ops_attendance.php';
require_once __DIR__ . '/../modules/operations/includes/ops_attendance_reminder.php';

$failures = 0;
function check(string $name, $want, $got): void
{
    global $failures;
    $ok = $want === $got;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'ok   - ' : 'FAIL - ') . $name . ($ok ? '' : ' (want ' . var_export($want, true) . ', got ' . var_export($got, true) . ')') . "\n";
}

$employee = $conn->query("SELECT id, full_name, employee_code, company_id, user_id, location_id FROM employees ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    echo "No employee to test with.\n";
    exit(1);
}
$employeeId = (int)$employee['id'];
$userId = (int)($employee['user_id'] ?? 0);
$open = function (string $date, string $in) use ($conn, $employeeId): int {
    $conn->prepare("INSERT INTO attendance (employee_id, work_date, check_in, status, source) VALUES (?, ?, ?, 'pending', 'self')")
         ->execute([$employeeId, $date, $in]);
    return (int)$conn->lastInsertId();
};
$rowById = function (int $id) use ($conn): array {
    $st = $conn->prepare("SELECT * FROM attendance WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
};
$rowsOn = function (string $date) use ($conn, $employeeId): int {
    $st = $conn->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND work_date = ?");
    $st->execute([$employeeId, $date]);
    return (int)$st->fetchColumn();
};

// HR's hours rule.
check('hours: 17:00 to 03:00 is 10', 10.0, hr_attendance_hours('17:00', '03:00'));
check('hours: 09:00 to 17:30 is 8.5', 8.5, hr_attendance_hours('09:00:00', '17:30:00'));
check('hours: same time is none', null, hr_attendance_hours('09:00', '09:00'));
check('overnight: 17:00 to 03:00', true, hr_attendance_is_overnight('17:00:00', '03:00:00'));
check('overnight: 09:00 to 17:00 is not', false, hr_attendance_is_overnight('09:00', '17:00'));

$conn->beginTransaction();
try {
    // 1. In Monday 17:00. At 03:00 Tuesday it is still Monday's shift, open.
    $night = $open('2031-07-07', '17:00:00');
    $state = ops_attendance_payload(
        ops_attendance_current($conn, $employeeId, '2031-07-08 03:00:00'),
        ops_attendance_unclosed($conn, $employeeId, '2031-07-08 03:00:00')
    );
    check('after midnight: still checked in', 'checked_in', $state['state']);
    check('after midnight: on Monday', '2031-07-07', $state['work_date']);
    check('after midnight: not a forgotten day', null, $state['unclosed']);

    // 2. A Check in tap at 03:00 does not open a Tuesday.
    ops_attendance_check_in($conn, $employee, $userId, '2031-07-08 03:00:00');
    check('check in after midnight: no Tuesday row', 0, $rowsOn('2031-07-08'));

    // 3. Check out at 03:00 closes Monday with the real hours.
    $result = ops_attendance_check_out($conn, $employee, $userId, '2031-07-08 03:00:00');
    $closed = $rowById($night);
    check('check out after midnight: ok', true, $result['ok']);
    check('check out time', '03:00:00', $closed['check_out']);
    check('hours 17:00 to 03:00', '10.00', $closed['hours']);
    check('approves itself like any check out', 'approved', $closed['status']);

    // 4. Tuesday evening is a day of its own.
    $result = ops_attendance_check_in($conn, $employee, $userId, '2031-07-08 17:02:00');
    check('next evening opens Tuesday', '2031-07-08', $result['row']['work_date'] ?? null);

    // 5. A morning check in left open is still a forgotten day the next morning.
    $day = $open('2031-08-04', '08:00:00');
    check('morning shift: not carried over', null, ops_attendance_current($conn, $employeeId, '2031-08-05 07:40:00'));
    check('morning shift: asked as forgotten', '2031-08-04', ops_attendance_unclosed($conn, $employeeId, '2031-08-05 07:40:00')['work_date'] ?? null);

    // 6. A night shift left open past the night limit becomes a forgotten day,
    //    and the time they left may still be the next morning.
    $late = $open('2031-09-01', '17:00:00');
    check('past the limit: not current', null, ops_attendance_current($conn, $employeeId, '2031-09-02 09:30:00'));
    check('past the limit: asked as forgotten', '2031-09-01', ops_attendance_unclosed($conn, $employeeId, '2031-09-02 09:30:00')['work_date'] ?? null);
    $result = ops_attendance_close_previous($conn, $employee, $userId, '2031-09-01', '16:00', '2031-09-02 09:30:00');
    check('left at 16:00 the next day is too long', 'bad_time', $result['error'] ?? null);
    $result = ops_attendance_close_previous($conn, $employee, $userId, '2031-09-01', '03:00', '2031-09-02 09:30:00');
    check('left at 03:00 next morning: accepted', true, $result['ok']);
    check('left at 03:00 next morning: 10 hours', '10.00', $rowById($late)['hours']);

    // 7. Reminders: a schedule of 17:00 to 03:00 means still at work at 18:00.
    $conn->prepare("INSERT INTO employee_work_schedules (employee_id, schedule_type, monday_hours, effective_from) VALUES (?, 'shift', '5:00 PM - 3:00 AM', '2031-01-01')")
         ->execute([$employeeId]);
    $tz = new DateTimeZone('Asia/Dubai');
    $shift = ['work_date' => '2031-10-06', 'check_in' => '17:05:00'];
    check('reminder: on duty at 18:00', true, ops_reminder_still_on_duty($conn, $employee, $shift, new DateTimeImmutable('2031-10-06 18:00', $tz)));
    check('reminder: on duty at 02:00', true, ops_reminder_still_on_duty($conn, $employee, $shift, new DateTimeImmutable('2031-10-07 02:00', $tz)));
    check('reminder: shift over at 03:30', false, ops_reminder_still_on_duty($conn, $employee, $shift, new DateTimeImmutable('2031-10-07 03:30', $tz)));
    check('reminder: a morning check in is not this shift', false, ops_reminder_still_on_duty($conn, $employee, ['work_date' => '2031-10-06', 'check_in' => '08:00:00'], new DateTimeImmutable('2031-10-06 18:00', $tz)));
    check('reminder: Tuesday has no duty', false, ops_reminder_still_on_duty($conn, $employee, ['work_date' => '2031-10-07', 'check_in' => '17:05:00'], new DateTimeImmutable('2031-10-07 18:00', $tz)));

    // 8. Double duty (Monday 09:00 to 13:00 and 18:00 to 03:00) checks in in the
    //    morning and still checks out after midnight, by its schedule.
    hr_weekly_off_save_employee($conn, $employeeId, 7, '2031-11-01');
    $conn->prepare("INSERT INTO employee_work_schedules (employee_id, schedule_type, monday_hours, tuesday_hours, effective_from) VALUES (?, 'shift', '9:00 AM - 1:00 PM, 6:00 PM - 3:00 AM', '9:00 AM - 6:00 PM', '2031-11-01')")
         ->execute([$employeeId]);
    $double = $open('2031-11-03', '09:00:00');
    check('double duty at 03:00: still Monday', '2031-11-03', ops_attendance_current($conn, $employeeId, '2031-11-04 03:00:00')['work_date'] ?? null);
    check('double duty at 03:00: not a forgotten day', null, ops_attendance_unclosed($conn, $employeeId, '2031-11-04 03:00:00'));
    $result = ops_attendance_check_out($conn, $employee, $userId, '2031-11-04 03:05:00');
    check('double duty check out at 03:05: ok', true, $result['ok']);
    check('double duty: 09:00 to 03:05 is 18.08 hours', '18.08', $rowById($double)['hours']);
    check('double duty: no Tuesday row', 0, $rowsOn('2031-11-04'));
    $left = $open('2031-11-10', '09:00:00');
    check('double duty past 03:00 + 3 h: not current', null, ops_attendance_current($conn, $employeeId, '2031-11-11 06:30:00'));
    check('double duty past 03:00 + 3 h: asked as forgotten', '2031-11-10', ops_attendance_unclosed($conn, $employeeId, '2031-11-11 06:30:00')['work_date'] ?? null);
    $open('2031-11-04', '09:00:00');
    check('day shift (Tuesday 09:00 to 18:00): not carried past midnight', null, ops_attendance_current($conn, $employeeId, '2031-11-05 02:00:00'));
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
