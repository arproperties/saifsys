<?php
/**
 * Set, change or remove an employee's field app PIN.
 *
 * The PIN is what a cleaner or technician types into the Operations app to
 * reach their jobs — see modules/operations/includes/ops_pin.php for what it
 * is and why four digits is defensible. This page only writes it; it can never
 * read one back, so a forgotten PIN is replaced, not looked up.
 *
 * It posts back to the employee profile with a flash message either way, which
 * is the same pattern user_create_for_employee.php uses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/../modules/operations/includes/ops_pin.php';
require_once __DIR__ . '/includes/hr_employee_login.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* Same gate as creating a login: this hands someone access to a company phone. */
$roles = function_exists('current_user_roles') ? current_user_roles() : [];
if (!array_intersect($roles, ['Owner', 'Admin', 'HR'])) {
    http_response_code(403);
    exit('Forbidden');
}

csrf_verify();

$empId = (int)($_POST['employee_id'] ?? 0);
if ($empId <= 0) {
    header('Location: employees.php');
    exit;
}

$back = 'employee_view.php?id=' . urlencode((string)$empId) . '#tab-overview';

/* Everything hr_create_login_for_employee() needs, in case there is no login. */
$stmt = $conn->prepare("
    SELECT e.id, e.full_name, e.employee_code, e.email, e.phone, e.address, e.user_id,
           e.position_title, e.department_id, e.company_id, d.name AS dept_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.id = ?
    LIMIT 1
");
$stmt->execute([$empId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    header('Location: employees.php');
    exit;
}

$userId = (int)($emp['user_id'] ?? 0);
$actorId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
$empLabel = trim((string)($emp['full_name'] ?? '') . ' (' . (string)($emp['employee_code'] ?? ('#' . $empId)) . ')');

/**
 * Both branches audit. Who can open the field app is an access decision, and
 * it has to be answerable months later without the PIN itself being in the log.
 */
if (isset($_POST['remove_pin'])) {
    if ($userId <= 0 || !ops_pin_clear($conn, $userId)) {
        $_SESSION['flash_error'] = 'That employee had no field app PIN to remove.';
        header('Location: ' . $back);
        exit;
    }

    audit_bridge_hr_ops(
        'ops_pin_removed',
        'user',
        $userId,
        'Removed the Operations app PIN for ' . $empLabel . ' — their phone is signed out',
        null,
        ['employee_id' => $empId, 'user_id' => $userId],
        'User #' . $userId . ' — ' . $empLabel,
        $actorId
    );

    $_SESSION['flash_success'] = 'Field app PIN removed. That phone is signed out on its next use.';
    header('Location: ' . $back);
    exit;
}

$pin = trim((string)($_POST['pin'] ?? ''));
$confirm = trim((string)($_POST['pin_confirm'] ?? ''));

if ($confirm !== '' && $confirm !== $pin) {
    $_SESSION['flash_error'] = 'The two PINs did not match. Type the same four digits twice.';
    header('Location: ' . $back);
    exit;
}

/**
 * No login yet? Make one.
 *
 * Jobs are assigned to a `user` row, not to an employee row, so a PIN has to
 * hang off an account — but that is our plumbing, not something the office
 * should have to know about before it can hand somebody four digits. So the
 * account is created here, exactly as the Create Login button would have made
 * it, and the credentials are reported alongside the PIN.
 *
 * A `user_id` pointing at a deleted account counts as no login: some employee
 * rows carry one, and treating it as real would fail every write that
 * references it.
 */
$createdLogin = null;
if (!hr_employee_has_login($conn, $userId)) {
    // Check the PIN itself before creating an account we may not end up using.
    if (!ops_pin_format_ok($pin)) {
        $_SESSION['flash_error'] = 'A PIN must be exactly ' . ops_pin_length() . ' digits.';
        header('Location: ' . $back);
        exit;
    }

    $createdLogin = hr_create_login_for_employee($conn, $emp, $actorId);
    $userId = $createdLogin['user_id'];

    audit_bridge_hr_ops(
        'employee_login_created',
        'user',
        $userId,
        'Created login for ' . $empLabel . ' — username ' . $createdLogin['username']
            . ' (roles: ' . implode(', ', $createdLogin['roles']) . ') while setting a field app PIN',
        null,
        [
            'employee_id' => $empId,
            'user_id' => $userId,
            'username' => $createdLogin['username'],
            'roles' => $createdLogin['roles'],
        ],
        'User #' . $userId . ' — ' . $empLabel,
        $actorId
    );
}

$result = ops_pin_set($conn, $userId, $pin, $actorId);

if (!$result['ok']) {
    // A login made moments ago and then not used is still a real account, so
    // say so rather than leaving one to be discovered later.
    $_SESSION['flash_error'] = $result['error']
        . ($createdLogin
            ? ' A login was created for this employee (username ' . $createdLogin['username']
              . ', temporary password ' . $createdLogin['password'] . ') — set a PIN to finish.'
            : '');
    header('Location: ' . $back);
    exit;
}

audit_bridge_hr_ops(
    'ops_pin_set',
    'user',
    $userId,
    'Set the Operations app PIN for ' . $empLabel,
    null,
    ['employee_id' => $empId, 'user_id' => $userId],
    'User #' . $userId . ' — ' . $empLabel,
    $actorId
);

/* The PIN is deliberately not echoed back. Whoever set it just typed it twice,
   so they know it; repeating it here would put it on a screen, into a session
   file and into browser history for no one's benefit. */
$message = 'Field app PIN saved for ' . ($emp['full_name'] ?: 'this employee')
    . '. Pass it on now — it cannot be looked up again.';

if ($createdLogin) {
    $message .= ' A login was created for them too — username ' . $createdLogin['username']
        . ', temporary password ' . $createdLogin['password']
        . ' (roles: ' . implode(', ', $createdLogin['roles']) . ').'
        . ' They do not need it for the app, only for the website.';
}

$_SESSION['flash_success'] = $message;
header('Location: ' . $back);
