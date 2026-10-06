<?php
declare(strict_types=1);

/**
 * A forgotten check out must not follow somebody into the next day.
 *
 * Runs against the local database inside a transaction that is rolled back,
 * on dates far in the future, so nothing real is read or left behind.
 *
 *     php tests/ops_attendance_forgotten_checkout_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/operations/includes/ops_attendance.php';

date_default_timezone_set('Asia/Dubai');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$failures = 0;
function check(string $what, $expected, $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "ok   - {$what}\n";
        return;
    }
    $failures++;
    echo "FAIL - {$what}\n       expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
}

$employee = $conn->query("SELECT id, full_name, employee_code, company_id, user_id FROM employees ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
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

$conn->beginTransaction();
try {
    // 1. Checked in yesterday morning, never checked out. Next morning the app
    //    must not show yesterday as "still checked in" — it is handed over as
    //    the day to close first.
    $forgot = $open('2031-03-03', '07:39:00');
    $state = ops_attendance_payload(
        ops_attendance_current($conn, $employeeId, '2031-03-04 07:40:00'),
        ops_attendance_unclosed($conn, $employeeId, '2031-03-04 07:40:00')
    );
    check('next morning: yesterday is not carried over', 'not_checked_in', $state['state']);
    check('next morning: yesterday is the day to close', ['work_date' => '2031-03-03', 'check_in' => '07:39'], $state['unclosed']);

    // 2. Tapping Check out the next day must not close yesterday at 24 hours.
    $result = ops_attendance_check_out($conn, $employee, $userId, '2031-03-04 16:57:00');
    check('next day: check out is refused', false, $result['ok']);
    check('yesterday has no hours written', null, $rowById($forgot)['hours']);

    // 3. The time they say they left has to make sense.
    $result = ops_attendance_close_previous($conn, $employee, $userId, '2031-03-03', '07:00', '2031-03-04 07:40:00');
    check('a time before the check in is refused', 'bad_time', $result['error'] ?? null);
    $result = ops_attendance_close_previous($conn, $employee, $userId, '2031-03-03', '25:00', '2031-03-04 07:40:00');
    check('a time that is not a time is refused', 'bad_time', $result['error'] ?? null);
    check('nothing was written by a refused time', null, $rowById($forgot)['check_out']);

    // 4. Closing it records their time, the real hours, and leaves it to HR.
    $result = ops_attendance_close_previous($conn, $employee, $userId, '2031-03-03', '17:05', '2031-03-04 07:40:00');
    $closed = $rowById($forgot);
    check('closing yesterday succeeds', true, $result['ok']);
    check('check out is the time they gave', '17:05:00', $closed['check_out']);
    check('hours are in to out, not 24', '9.43', $closed['hours']);
    check('the day stays pending for HR', 'pending', $closed['status']);
    check('the notes say staff entered it', true, strpos((string)$closed['notes'], 'Check out time entered by staff on 4 Mar') !== false);
    check('nothing left to close', null, ops_attendance_unclosed($conn, $employeeId, '2031-03-04 07:40:00'));
    $result = ops_attendance_close_previous($conn, $employee, $userId, '2031-03-03', '19:00', '2031-03-04 07:41:00');
    check('sent twice: answered ok', true, $result['ok']);
    check('sent twice: the first time stands', '17:05:00', $rowById($forgot)['check_out']);

    // 5. Then today's check in opens a row of its own.
    $result = ops_attendance_check_in($conn, $employee, $userId, '2031-03-04 07:41:00');
    check('check in opens today', '2031-03-04', $result['row']['work_date'] ?? null);
    check('check in time is today\'s', '07:41:00', $result['row']['check_in'] ?? null);

    // 6. Days the app must not ask about or touch.
    $old = $open('2031-04-01', '08:00:00');
    check('older than a week: not asked', null, ops_attendance_unclosed($conn, $employeeId, '2031-04-20 08:00:00'));
    $result = ops_attendance_close_previous($conn, $employee, $userId, '2031-04-01', '17:00', '2031-04-20 08:00:00');
    check('older than a week: cannot be closed', 'not_open', $result['error'] ?? null);
    $conn->prepare("UPDATE attendance SET status = 'approved', hours = 9 WHERE id = ?")->execute([$old]);
    check('approved by HR: not asked', null, ops_attendance_unclosed($conn, $employeeId, '2031-04-02 08:00:00'));
    $result = ops_attendance_close_previous($conn, $employee, $userId, '2031-04-01', '17:00', '2031-04-02 08:00:00');
    check('approved by HR: cannot be closed', 'not_open', $result['error'] ?? null);
    $today = $open('2031-05-05', '08:00:00');
    $result = ops_attendance_close_previous($conn, $employee, $userId, '2031-05-05', '17:00', '2031-05-05 18:00:00');
    check('today: closed with Check out, not this', 'not_open', $result['error'] ?? null);

    // 7. Two forgotten days: the latest is asked first, then the one before.
    $open('2031-06-02', '08:00:00');
    $open('2031-06-03', '08:10:00');
    check('two open: the latest first', '2031-06-03', ops_attendance_unclosed($conn, $employeeId, '2031-06-04 08:00:00')['work_date'] ?? null);
    ops_attendance_close_previous($conn, $employee, $userId, '2031-06-03', '17:00', '2031-06-04 08:00:00');
    check('two open: then the one before', '2031-06-02', ops_attendance_unclosed($conn, $employeeId, '2031-06-04 08:00:00')['work_date'] ?? null);

    // 8. An ordinary day is untouched: check out approves itself.
    $late = $open('2031-03-17', '07:45:00');
    $result = ops_attendance_check_out($conn, $employee, $userId, '2031-03-17 17:11:00');
    check('ordinary check out: time', '17:11:00', $rowById($late)['check_out']);
    check('ordinary check out: approves itself', 'approved', $rowById($late)['status']);
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
