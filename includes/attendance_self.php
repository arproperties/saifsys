<?php
/**
 * Staff self check-in / check-out.
 *
 * Office staff record their own arrival and departure from the admin panel
 * instead of HR typing it in afterwards. One check-in and one check-out per
 * person per day, written to the existing `attendance` table with
 * source='self' — so HR's attendance pages, the summary and payroll all read
 * it exactly as they read a row HR typed by hand.
 *
 * Deliberately NOT tied to login: people log in many times a day and from
 * home at night, and nothing fires when they leave, so a login is not an
 * arrival and there is no event to pair a departure with.
 *
 * Timezone: live MySQL and PHP both run UTC, while the company works to
 * Asia/Dubai. Every time in here is worked out in PHP in Dubai time and sent
 * to the database as a literal. NOW() and CURDATE() must never be used for a
 * check-in — on live they land four hours early.
 */

require_once __DIR__ . '/db_connect.php';

/**
 * Master switch. Ships OFF so the files can go up without anything changing
 * for staff. Flip to true when you are ready to pilot it.
 */
function attendance_self_enabled(): bool
{
    return true;
}

/**
 * Whether a person who has not checked in is stopped from using the system.
 * Only has any effect while attendance_self_enabled() is true.
 */
function attendance_self_blocking(): bool
{
    return true;
}

/**
 * Field staff — cleaners, drivers — are outside this entirely. They record
 * attendance on the PIN app, and Guard keeps them to a handful of pages. One
 * of those is their own profile, which is drawn with the HR layout, so without
 * this check the pop-up would appear there and trap them: no launcher to
 * reach, nothing else allowed, no way out.
 */
function attendance_self_is_worker(PDO $conn): bool
{
    static $isWorker = null;
    if ($isWorker !== null) {
        return $isWorker;
    }
    $isWorker = false;
    require_once __DIR__ . '/../lib/Guard.php';
    try {
        $isWorker = Guard::isWorker(current_user_roles($conn));
    } catch (Throwable $e) {
        $isWorker = false;
    }
    return $isWorker;
}

/** Company time. Set here only — changing it globally would shift other modules. */
function attendance_self_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone('Asia/Dubai');
    }
    return $tz;
}

/** Right now, in company time. */
function attendance_self_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', attendance_self_tz());
}

/**
 * The employee record behind the logged-in user, or null.
 *
 * Returns null — meaning the feature simply does not apply — when the user has
 * no employee record at all, or when that employee has left. An ex-employee
 * must never be able to record attendance, and someone with no employee record
 * (the Owner account, for one) must never be locked out over it.
 */
function attendance_self_employee(PDO $conn): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache ?: null;
    }
    $cache = false;

    if (!function_exists('current_user_id')) {
        return null;
    }
    $userId = current_user_id();
    if (!$userId) {
        return null;
    }

    require_once __DIR__ . '/../lib/Guard.php';
    try {
        $employeeId = Guard::resolveEmployeeId($conn, (int)$userId);
    } catch (Throwable $e) {
        return null;
    }
    if (!$employeeId) {
        return null;
    }

    try {
        $st = $conn->prepare(
            "SELECT id, full_name, employee_code, company_id, status
               FROM employees
              WHERE id = ?
              LIMIT 1"
        );
        $st->execute([(int)$employeeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    if (!$row) {
        return null;
    }

    // Only people currently on the books. 'on_leave' is included so their own
    // page still works, but the blocking gate lets them past untouched.
    if (!in_array((string)$row['status'], ['active', 'on_leave', 'notice_period'], true)) {
        return null;
    }

    $cache = $row;
    return $row;
}

/**
 * Whether the break columns are on this database yet.
 *
 * The migration is run before the files go up, but a database that has not had
 * it — a local copy, an older dump — must not break the check-in bar over a
 * feature it does not have. When this is false the break button simply never
 * appears and everything else works exactly as before.
 */
function attendance_self_breaks_available(PDO $conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $has = false;
    try {
        $st = $conn->query("SHOW COLUMNS FROM attendance LIKE 'break_start'");
        $has = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        $has = false;
    }
    return $has;
}

/** Today's attendance row for this employee, or null. */
function attendance_self_today_row(PDO $conn, int $employeeId, string $workDate): ?array
{
    $breakCols = attendance_self_breaks_available($conn) ? ', break_start, break_end, break_minutes' : '';
    try {
        $st = $conn->prepare(
            "SELECT id, work_date, check_in, check_out, hours, status, source, notes{$breakCols}
               FROM attendance
              WHERE employee_id = ? AND work_date = ?
              LIMIT 1"
        );
        $st->execute([$employeeId, $workDate]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    return $row ?: null;
}

/** The caller's IP, as far as it can be trusted. */
function attendance_self_client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * The office IP allow-list. An empty list means no restriction, which is how
 * this ships — fill the setting in once you have the office public IP.
 */
function attendance_self_office_ips(PDO $conn): array
{
    static $ips = null;
    if ($ips !== null) {
        return $ips;
    }
    $ips = [];
    try {
        $st = $conn->prepare("SELECT `value` FROM settings WHERE `key` = 'attendance_self_office_ips' LIMIT 1");
        $st->execute();
        $raw = (string)($st->fetchColumn() ?: '');
    } catch (PDOException $e) {
        return $ips;
    }
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $ips[] = $part;
        }
    }
    return $ips;
}

/** Whether a check-in is allowed from where this request came from. */
function attendance_self_ip_allowed(PDO $conn): bool
{
    $allowed = attendance_self_office_ips($conn);
    if (empty($allowed)) {
        return true; // no restriction configured
    }
    return in_array(attendance_self_client_ip(), $allowed, true);
}

/**
 * Everything the UI and the gate need to know, worked out once.
 *
 * 'stage' is the thing to act on:
 *   'n/a'        - feature off, field staff, or no employee record
 *   'check_in'   - nothing recorded yet today
 *   'check_out'  - checked in, still here
 *   'break'      - away from the desk; the only thing they can do is come back
 *   'done'       - checked in and out already
 *   'excused'    - HR has already marked today (leave, absent, half day)
 *
 * 'reason' says why a stage is 'n/a', because the widget treats them
 * differently: 'disabled' and 'worker' print nothing at all, while
 * 'no_employee' still shows a bar telling the person to talk to HR.
 */
function attendance_self_state(PDO $conn): array
{
    $now = attendance_self_now();
    $state = [
        'stage'       => 'n/a',
        'reason'      => '',
        'att_status'  => null,
        'employee'    => null,
        'work_date'   => $now->format('Y-m-d'),
        'now_time'    => $now->format('H:i'),
        'now_label'   => $now->format('g:i A'),
        'day_label'   => $now->format('l, j M Y'),
        'check_in'    => null,
        'check_out'   => null,
        'hours'       => null,
        'ip_allowed'  => true,
        'breaks_on'   => false,
        'break_start' => null,
        'break_end'   => null,
        'break_mins'  => null,
    ];

    if (!attendance_self_enabled()) {
        $state['reason'] = 'disabled';
        return $state;
    }
    if (attendance_self_is_worker($conn)) {
        $state['reason'] = 'worker'; // PIN app, not this
        return $state;
    }

    $employee = attendance_self_employee($conn);
    if (!$employee) {
        $state['reason'] = 'no_employee';
        return $state;
    }
    $state['employee']   = $employee;
    $state['ip_allowed'] = attendance_self_ip_allowed($conn);
    $state['breaks_on']  = attendance_self_breaks_available($conn);

    $row = attendance_self_today_row($conn, (int)$employee['id'], $state['work_date']);
    if ($row) {
        $state['check_in']  = $row['check_in'] ?: null;
        $state['check_out'] = $row['check_out'] ?: null;
        $state['hours']     = $row['hours'];
        $state['att_status'] = (string)$row['status'];
        if ($state['breaks_on']) {
            $state['break_start'] = $row['break_start'] ?: null;
            $state['break_end']   = $row['break_end'] ?: null;
            $state['break_mins']  = isset($row['break_minutes']) && $row['break_minutes'] !== null
                ? (int)$row['break_minutes']
                : null;
        }

        // HR has already said what today is. Leave it alone.
        if (in_array((string)$row['status'], ['on_leave', 'absent', 'half', 'excused_absent'], true)) {
            $state['stage'] = 'excused';
            return $state;
        }
    }

    if (empty($state['check_in'])) {
        $state['stage'] = 'check_in';
    } elseif (empty($state['check_out'])) {
        // A break that was started and not ended outranks everything: until
        // they tap Back, the only thing they can do is come back.
        $state['stage'] = (!empty($state['break_start']) && empty($state['break_end']))
            ? 'break'
            : 'check_out';
    } else {
        $state['stage'] = 'done';
    }

    return $state;
}

/** Hours between two H:i times on the same day, or null. */
function attendance_self_hours(?string $in, ?string $out): ?float
{
    if (!$in || !$out) {
        return null;
    }
    $a = strtotime('1970-01-01 ' . $in . ' UTC');
    $b = strtotime('1970-01-01 ' . $out . ' UTC');
    if ($a === false || $b === false || $b <= $a) {
        return null;
    }
    return round(($b - $a) / 3600, 2);
}

/** Write an audit line, matching how the HR attendance pages record theirs. */
function attendance_self_audit(PDO $conn, string $action, int $attendanceId, array $employee, string $summary, array $data): void
{
    $bridge = __DIR__ . '/audit_bridge.php';
    if (!is_file($bridge)) {
        return;
    }
    require_once $bridge;
    if (!function_exists('audit_bridge_hr_ops')) {
        return;
    }
    $label = trim((string)($employee['full_name'] ?? '') . ' (' . (string)($employee['employee_code'] ?? '') . ')');
    $companyId = isset($employee['company_id']) ? (int)$employee['company_id'] : 0;
    try {
        audit_bridge_hr_ops(
            $action,
            'attendance',
            $attendanceId > 0 ? $attendanceId : (int)$employee['id'],
            $summary,
            $companyId > 0 ? $companyId : null,
            $data,
            $label . ' @ ' . ($data['work_date'] ?? ''),
            function_exists('current_user_id') ? (current_user_id() ?: null) : null
        );
    } catch (Throwable $e) {
        // Never let an audit failure stop someone recording their arrival.
    }
}

/**
 * Record arrival. Returns ['ok' => bool, 'message' => string].
 */
function attendance_self_check_in(PDO $conn): array
{
    if (!attendance_self_enabled()) {
        return ['ok' => false, 'message' => 'Self check-in is not switched on.'];
    }

    $state = attendance_self_state($conn);
    $employee = $state['employee'];
    if (!$employee) {
        return ['ok' => false, 'message' => 'Your login is not linked to an employee record. Please ask HR.'];
    }
    if (!$state['ip_allowed']) {
        return ['ok' => false, 'message' => 'Check-in is only allowed on the office network.'];
    }
    if ($state['stage'] === 'excused') {
        return ['ok' => false, 'message' => 'HR has already recorded today for you.'];
    }
    if ($state['stage'] !== 'check_in') {
        return ['ok' => false, 'message' => 'You have already checked in today.'];
    }

    $employeeId = (int)$employee['id'];
    $workDate   = $state['work_date'];
    $time       = $state['now_time'];
    $ip         = attendance_self_client_ip();
    $userId     = function_exists('current_user_id') ? (current_user_id() ?: null) : null;

    try {
        // The table is unique on (employee_id, work_date). Insert, and if a row
        // is already there — HR created one, or two tabs were open — fill in the
        // check-in only when it is still empty, so nothing gets overwritten.
        $ins = $conn->prepare(
            "INSERT INTO attendance
                (employee_id, work_date, check_in, check_in_ip, status, source, created_by, updated_by)
             VALUES (?, ?, ?, ?, 'pending', 'self', ?, ?)
             ON DUPLICATE KEY UPDATE
                check_in    = COALESCE(check_in, VALUES(check_in)),
                check_in_ip = COALESCE(check_in_ip, VALUES(check_in_ip)),
                updated_by  = VALUES(updated_by)"
        );
        $ins->execute([$employeeId, $workDate, $time, $ip, $userId, $userId]);
    } catch (PDOException $e) {
        return ['ok' => false, 'message' => 'Could not save your check-in. Please try again.'];
    }

    $row = attendance_self_today_row($conn, $employeeId, $workDate);
    $attendanceId = (int)($row['id'] ?? 0);

    attendance_self_audit(
        $conn,
        'attendance_self_check_in',
        $attendanceId,
        $employee,
        'Checked in at ' . $time . ' on ' . $workDate,
        ['employee_id' => $employeeId, 'work_date' => $workDate, 'check_in' => $time, 'ip' => $ip, 'source' => 'self']
    );

    return ['ok' => true, 'message' => 'Checked in at ' . $state['now_label'] . '.'];
}

/**
 * Record departure. The last tap of the day wins, so someone who taps by
 * mistake and taps again on the way out ends with the later time.
 */
function attendance_self_check_out(PDO $conn): array
{
    if (!attendance_self_enabled()) {
        return ['ok' => false, 'message' => 'Self check-in is not switched on.'];
    }

    $state = attendance_self_state($conn);
    $employee = $state['employee'];
    if (!$employee) {
        return ['ok' => false, 'message' => 'Your login is not linked to an employee record. Please ask HR.'];
    }
    if ($state['stage'] === 'excused') {
        return ['ok' => false, 'message' => 'HR has already recorded today for you.'];
    }
    if (empty($state['check_in'])) {
        return ['ok' => false, 'message' => 'You have not checked in today.'];
    }
    if ($state['stage'] === 'break') {
        return ['ok' => false, 'message' => 'End your break first, then check out.'];
    }

    $employeeId = (int)$employee['id'];
    $workDate   = $state['work_date'];
    $time       = $state['now_time'];
    $checkIn    = substr((string)$state['check_in'], 0, 5);
    $hours      = attendance_self_hours($checkIn, $time);
    $ip         = attendance_self_client_ip();
    $userId     = function_exists('current_user_id') ? (current_user_id() ?: null) : null;

    if ($hours === null) {
        return ['ok' => false, 'message' => 'Check-out must be later than your check-in time.'];
    }

    try {
        $upd = $conn->prepare(
            "UPDATE attendance
                SET check_out = ?, check_out_ip = ?, hours = ?, updated_by = ?, updated_at = NOW()
              WHERE employee_id = ? AND work_date = ?
              LIMIT 1"
        );
        $upd->execute([$time, $ip, $hours, $userId, $employeeId, $workDate]);
    } catch (PDOException $e) {
        return ['ok' => false, 'message' => 'Could not save your check-out. Please try again.'];
    }

    $row = attendance_self_today_row($conn, $employeeId, $workDate);
    $attendanceId = (int)($row['id'] ?? 0);

    attendance_self_audit(
        $conn,
        'attendance_self_check_out',
        $attendanceId,
        $employee,
        'Checked out at ' . $time . ' on ' . $workDate . ' (' . $hours . ' h)',
        ['employee_id' => $employeeId, 'work_date' => $workDate, 'check_in' => $checkIn, 'check_out' => $time, 'hours' => $hours, 'ip' => $ip, 'source' => 'self']
    );

    return ['ok' => true, 'message' => 'Checked out at ' . $state['now_label'] . '. ' . $hours . ' hours today.'];
}

/** Minutes between two H:i times on the same day. Never negative. */
function attendance_self_minutes(?string $from, ?string $to): int
{
    if (!$from || !$to) {
        return 0;
    }
    $a = strtotime('1970-01-01 ' . $from . ' UTC');
    $b = strtotime('1970-01-01 ' . $to . ' UTC');
    if ($a === false || $b === false || $b <= $a) {
        return 0;
    }
    return (int)round(($b - $a) / 60);
}

/**
 * Start the break. One per person per day.
 *
 * The length is recorded but never subtracted: `hours` stays check-in to
 * check-out, so payroll, the summary and performance read exactly the number
 * they read before this existed.
 */
function attendance_self_break_start(PDO $conn): array
{
    $state = attendance_self_state($conn);
    $employee = $state['employee'];
    if (!$employee) {
        return ['ok' => false, 'message' => 'Your login is not linked to an employee record. Please ask HR.'];
    }
    if (!$state['breaks_on']) {
        return ['ok' => false, 'message' => 'Breaks are not switched on yet.'];
    }
    if ($state['stage'] === 'break') {
        return ['ok' => false, 'message' => 'You are already on a break.'];
    }
    if ($state['stage'] !== 'check_out') {
        return ['ok' => false, 'message' => 'Check in before taking a break.'];
    }
    if (!empty($state['break_start'])) {
        return ['ok' => false, 'message' => 'You have already taken your break today.'];
    }

    $employeeId = (int)$employee['id'];
    $workDate   = $state['work_date'];
    $time       = $state['now_time'];
    $userId     = function_exists('current_user_id') ? (current_user_id() ?: null) : null;

    try {
        // break_start IS NULL in the WHERE, so two taps in two tabs cannot
        // move a break that has already started.
        $upd = $conn->prepare(
            "UPDATE attendance
                SET break_start = ?, updated_by = ?, updated_at = NOW()
              WHERE employee_id = ? AND work_date = ? AND break_start IS NULL
              LIMIT 1"
        );
        $upd->execute([$time, $userId, $employeeId, $workDate]);
        if ($upd->rowCount() === 0) {
            return ['ok' => false, 'message' => 'You have already taken your break today.'];
        }
    } catch (PDOException $e) {
        return ['ok' => false, 'message' => 'Could not start your break. Please try again.'];
    }

    $row = attendance_self_today_row($conn, $employeeId, $workDate);
    attendance_self_audit(
        $conn,
        'attendance_self_break_start',
        (int)($row['id'] ?? 0),
        $employee,
        'Break started at ' . $time . ' on ' . $workDate,
        ['employee_id' => $employeeId, 'work_date' => $workDate, 'break_start' => $time]
    );

    return ['ok' => true, 'message' => 'Break started at ' . $state['now_label'] . '.'];
}

/**
 * The second check-in of the day: back from the break, at the desk again.
 *
 * Stored as the end of the break rather than a second check-in row, because
 * the day is still one row with one arrival and one departure — the pair in
 * the middle is what the person was away for.
 */
function attendance_self_break_end(PDO $conn): array
{
    $state = attendance_self_state($conn);
    $employee = $state['employee'];
    if (!$employee) {
        return ['ok' => false, 'message' => 'Your login is not linked to an employee record. Please ask HR.'];
    }
    if ($state['stage'] !== 'break') {
        return ['ok' => false, 'message' => 'You are not on a break.'];
    }

    $employeeId = (int)$employee['id'];
    $workDate   = $state['work_date'];
    $time       = $state['now_time'];
    $started    = substr((string)$state['break_start'], 0, 5);
    $minutes    = attendance_self_minutes($started, $time);
    $userId     = function_exists('current_user_id') ? (current_user_id() ?: null) : null;

    try {
        $upd = $conn->prepare(
            "UPDATE attendance
                SET break_end = ?, break_minutes = ?, updated_by = ?, updated_at = NOW()
              WHERE employee_id = ? AND work_date = ? AND break_end IS NULL
              LIMIT 1"
        );
        $upd->execute([$time, $minutes, $userId, $employeeId, $workDate]);
    } catch (PDOException $e) {
        return ['ok' => false, 'message' => 'Could not end your break. Please try again.'];
    }

    $row = attendance_self_today_row($conn, $employeeId, $workDate);
    attendance_self_audit(
        $conn,
        'attendance_self_break_end',
        (int)($row['id'] ?? 0),
        $employee,
        'Break ended at ' . $time . ' on ' . $workDate . ' (' . $minutes . ' min)',
        ['employee_id' => $employeeId, 'work_date' => $workDate, 'break_start' => $started, 'break_end' => $time, 'break_minutes' => $minutes]
    );

    return ['ok' => true, 'message' => 'Checked in again at ' . $state['now_label'] . '. Break was ' . $minutes . ' minutes.'];
}

/* ---------------------------------------------------------------------------
 * The gate
 * ------------------------------------------------------------------------- */

/** Pages that must keep working for someone who has not checked in yet. */
function attendance_self_gate_exempt(string $path): bool
{
    $path = strtolower($path);

    // Clean URLs are in use, so match with and without the .php.
    $exact = [
        '/login', '/logout', '/forgot_password', '/reset_password',
        '/profile', '/account',
    ];
    foreach ($exact as $p) {
        if ($path === $p || $path === $p . '.php') {
            return true;
        }
    }

    // Anything under /api/ — including the check-in endpoint itself, which the
    // pop-up posts to, and the profile endpoints the worker allow-list needs.
    if (strpos($path, '/api/') !== false) {
        return true;
    }

    // Suffix match, so the app still works when it is served from a subfolder.
    $suffixes = ['/login.php', '/logout.php', '/forgot_password.php', '/reset_password.php'];
    foreach ($suffixes as $s) {
        if (substr($path, -strlen($s)) === $s) {
            return true;
        }
    }

    return false;
}

/** The path of the request being served. */
function attendance_self_current_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    if ($path === '' && isset($_SERVER['SCRIPT_NAME'])) {
        $path = (string)$_SERVER['SCRIPT_NAME'];
    }
    return $path ?: '';
}

/** Static files must never be redirected or the page loses its styling. */
function attendance_self_is_asset(string $path): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '') {
        return false;
    }
    return in_array($ext, ['css','js','json','map','png','jpg','jpeg','gif','svg','webp','ico','woff','woff2','ttf','otf','txt','pdf'], true);
}

/**
 * Stop anyone who has not checked in yet, or who is on a break, and show them
 * the pop-up on whatever page they asked for. Called once per request from
 * includes/auth.php.
 *
 * Someone on leave, someone HR has already marked, someone with no employee
 * record and anyone who has already checked in all pass through untouched.
 *
 * The break wall does not answer to attendance_self_blocking(): that switch is
 * about forcing people to check in, while a break is something the person
 * asked for themselves — nobody should be working while marked away.
 */
function attendance_self_gate_enforce(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!attendance_self_enabled()) {
        return;
    }
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (!function_exists('is_logged_in') || !is_logged_in()) {
        return;
    }

    // Field staff are outside this — sending them to the launcher would only
    // bounce them back to their profile.
    try {
        if (attendance_self_is_worker($conn)) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $path = attendance_self_current_path();
    if ($path === '' || attendance_self_is_asset($path) || attendance_self_gate_exempt($path)) {
        return;
    }

    // Never interrupt a form being submitted or a background request — that
    // would throw away what the person typed.
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET') {
        return;
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return;
    }

    try {
        $state = attendance_self_state($conn);
    } catch (Throwable $e) {
        return; // never lock the whole system out over this
    }

    $wall = ($state['stage'] === 'break')
        || ($state['stage'] === 'check_in' && attendance_self_blocking());
    if (!$wall) {
        return;
    }

    // Draw the pop-up here, on the page they asked for, instead of redirecting
    // to the launcher and trusting that page to draw it. A redirect can be
    // bounced straight back — the launcher forwards single-module staff into
    // their module — and a launcher that draws nothing leaves the person with
    // no way in at all. Rendering in place cannot loop and depends on nothing
    // but this file and the widget beside it.
    attendance_self_gate_render($conn);
}

/**
 * The check-in wall: the pop-up on a page of its own.
 *
 * The widget is drawn into a buffer first. If it comes back empty — the switch
 * was turned off mid-request, the state moved on, anything at all — the person
 * is let through rather than left staring at a blank page they cannot leave.
 * A missed check-in is a far smaller failure than somebody unable to work.
 */
function attendance_self_gate_render(PDO $conn): void
{
    ob_start();
    try {
        require __DIR__ . '/attendance_self_widget.php';
        $html = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        return;
    }

    if (trim($html) === '') {
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Check in</title>'
       . '<style>html,body{margin:0;padding:0;min-height:100vh;background:#f5f0e8;}</style>'
       . '</head><body>'
       . $html
       . '</body></html>';
    exit;
}
