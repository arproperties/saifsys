<?php
declare(strict_types=1);

/**
 * Main app pop-up: a forgotten check-out is asked about before check-in.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * The pop-up works from the real clock, so this uses the real yesterday and
 * picks an employee with nothing recorded in the days it touches.
 *
 *     php tests/attendance_self_forgotten_checkout_test.php
 */

$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/select-module';

function current_user_id(): ?int { return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null; }
function current_user_roles(?PDO $conn = null): array { return []; }
function is_logged_in(): bool { return current_user_id() !== null; }

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance_self.php';
require_once __DIR__ . '/../includes/csrf.php';

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

// Working hours: 5 AM up to 8 PM. Outside them nobody is stopped or asked.
check('04:59 is outside working hours', false, attendance_self_within_hours('04:59'));
check('05:00 is inside working hours', true, attendance_self_within_hours('05:00'));
check('19:59 is inside working hours', true, attendance_self_within_hours('19:59'));
check('20:00 is outside working hours', false, attendance_self_within_hours('20:00'));
check('22:36 is outside working hours', false, attendance_self_within_hours('22:36'));
check('00:00 is outside working hours', false, attendance_self_within_hours('00:00'));
check('blocking follows the hours', attendance_self_within_hours(), attendance_self_blocking());

if (!attendance_self_within_hours()) {
    echo "\nOutside working hours: the pop-up asks nothing now, so the rest is skipped. Run between 5 AM and 8 PM.\n";
    exit($failures === 0 ? 0 : 1);
}

$now       = attendance_self_now();
$today     = $now->format('Y-m-d');
$yesterday = $now->modify('-1 day')->format('Y-m-d');
$twoAgo    = $now->modify('-2 days')->format('Y-m-d');
$longAgo   = $now->modify('-20 days')->format('Y-m-d');

$st = $conn->prepare(
    "SELECT e.id, e.user_id FROM employees e
      WHERE e.status = 'active' AND e.user_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.employee_id = e.id AND (a.work_date >= ? OR a.work_date = ?))
      ORDER BY e.id LIMIT 1"
);
$st->execute([$now->modify('-8 days')->format('Y-m-d'), $longAgo]);
$who = $st->fetch(PDO::FETCH_ASSOC);
if (!$who) {
    echo "No free employee to test with.\n";
    exit(1);
}
$employeeId = (int)$who['id'];
$_SESSION['user'] = ['id' => (int)$who['user_id'], 'employee_id' => $employeeId];

$open = function (string $date, string $in, ?string $breakStart = null) use ($conn, $employeeId): int {
    $conn->prepare("INSERT INTO attendance (employee_id, work_date, check_in, break_start, status, source) VALUES (?, ?, ?, ?, 'pending', 'self')")
         ->execute([$employeeId, $date, $in, $breakStart]);
    return (int)$conn->lastInsertId();
};
$rowById = function (int $id) use ($conn): array {
    $st = $conn->prepare("SELECT * FROM attendance WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
};
$widget = function () use ($conn): string {
    ob_start();
    require __DIR__ . '/../includes/attendance_self_widget.php';
    return (string)ob_get_clean();
};

$conn->beginTransaction();
try {
    // 1. Nothing forgotten: the ordinary check-in.
    check('nothing open: stage is check_in', 'check_in', attendance_self_state($conn)['stage']);

    // 2. Too old to ask about: still the ordinary check-in.
    $ancient = $open($longAgo, '08:00:00');
    check('open 20 days ago: not asked', 'check_in', attendance_self_state($conn)['stage']);

    // 3. Yesterday left open, on a break that was never ended.
    $forgot = $open($yesterday, '07:39:00', '13:00:00');
    $state = attendance_self_state($conn);
    check('yesterday open: stage is close_previous', 'close_previous', $state['stage']);
    check('yesterday open: the day asked about', ['work_date' => $yesterday, 'check_in' => '07:39'], $state['unclosed']);

    $html = $widget();
    check('pop-up asks about yesterday', true, strpos($html, 'You did not check out yesterday') !== false);
    check('pop-up has the time box', true, strpos($html, 'name="time"') !== false);
    check('pop-up offers no Check In', false, strpos($html, 'value="in"') !== false);

    // 4. Check-in and check-out are refused until it is answered.
    check('check-in refused first', false, attendance_self_check_in($conn)['ok']);
    $st = $conn->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND work_date = ?");
    $st->execute([$employeeId, $today]);
    check('no row opened for today', 0, (int)$st->fetchColumn());

    // 5. The time has to make sense.
    check('a time before the check-in is refused', false, attendance_self_close_previous($conn, $yesterday, '07:00')['ok']);
    check('a time that is not a time is refused', false, attendance_self_close_previous($conn, $yesterday, '25:00')['ok']);
    check('nothing written by a refused time', null, $rowById($forgot)['check_out']);

    // 6. Closing it.
    $result = attendance_self_close_previous($conn, $yesterday, '17:05');
    $closed = $rowById($forgot);
    check('closing yesterday succeeds', true, $result['ok']);
    check('check-out is the time they gave', '17:05:00', $closed['check_out']);
    check('hours are in to out', '9.43', $closed['hours']);
    check('the day stays pending for HR', 'pending', $closed['status']);
    check('the notes say staff entered it', true, strpos((string)$closed['notes'], 'Check out time entered by staff on ' . $now->format('j M')) !== false);
    check('the open break ends with the day', '17:05:00', $closed['break_end']);
    check('the break length is recorded', 245, (int)$closed['break_minutes']);
    check('the old day is untouched', null, $rowById($ancient)['check_out']);

    // 7. Posted again: nothing moves.
    check('sent twice: answered ok', true, attendance_self_close_previous($conn, $yesterday, '19:00')['ok']);
    check('sent twice: the first time stands', '17:05:00', $rowById($forgot)['check_out']);

    // 8. Back to the ordinary check-in, and it works.
    check('after closing: stage is check_in', 'check_in', attendance_self_state($conn)['stage']);
    check('pop-up now offers Check In', true, strpos($widget(), 'value="in"') !== false);
    check('check-in now accepted', true, attendance_self_check_in($conn)['ok']);
    check('after check-in: stage is check_out', 'check_out', attendance_self_state($conn)['stage']);

    // 9. A day HR has approved is never asked about.
    $approved = $open($twoAgo, '08:00:00');
    check('two days ago open: asked', 'close_previous', attendance_self_state($conn)['stage']);
    $conn->prepare("UPDATE attendance SET status = 'approved', hours = 9 WHERE id = ?")->execute([$approved]);
    check('approved by HR: not asked', 'check_out', attendance_self_state($conn)['stage']);
    attendance_self_close_previous($conn, $twoAgo, '17:00');
    check('approved by HR: not written over', null, $rowById($approved)['check_out']);
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
