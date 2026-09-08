<?php
/**
 * The Create Login button on the employee profile.
 *
 * The account itself is built by hr_create_login_for_employee() — shared with
 * hr/employee_pin_save.php, which creates the same account when an employee is
 * given a field app PIN before anyone has made them a login.
 */

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/audit_bridge.php';
require_once __DIR__.'/includes/hr_employee_login.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* --- Access control: Owner/Admin/HR only --- */
$roles_session = function_exists('current_user_roles') ? current_user_roles() : [];
if (!array_intersect($roles_session, ['Owner','Admin','HR'])) {
  http_response_code(403); exit('Forbidden');
}

/* --- Input --- */
$emp_id = (int)($_POST['employee_id'] ?? 0);
if (!$emp_id) { header('Location: employees.php'); exit; }

/* --- Load employee (with department name) --- */
$stmt = $conn->prepare("
  SELECT e.id, e.full_name, e.employee_code, e.email, e.phone, e.address, e.user_id,
         e.position_title, e.department_id, e.company_id, d.name AS dept_name
  FROM employees e
  LEFT JOIN departments d ON d.id = e.department_id
  WHERE e.id=?
");
$stmt->execute([$emp_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) { header('Location: employees.php'); exit; }
if (hr_employee_has_login($conn, (int)($emp['user_id'] ?? 0))) {
  header('Location: employee_view.php?id='.$emp_id); exit;
}

$creator_id = $_SESSION['user']['id'] ?? null;
$created = hr_create_login_for_employee($conn, $emp, $creator_id ? (int)$creator_id : null);

$new_user_id   = $created['user_id'];
$username      = $created['username'];
$temp_password = $created['password'];
$roleNames     = $created['roles'];

/* Done */
$empLabel = trim(($emp['full_name'] ?? '') . ' (' . ($emp['employee_code'] ?? ('#' . $emp_id)) . ')');
audit_bridge_hr_ops(
  'employee_login_created',
  'user',
  $new_user_id > 0 ? $new_user_id : $emp_id,
  'Created login for ' . $empLabel . ' — username ' . $username
    . ' (roles: ' . implode(', ', $roleNames) . ')',
  null,
  [
    'employee_id' => $emp_id,
    'user_id' => $new_user_id,
    'username' => $username,
    'roles' => $roleNames,
  ],
  'User #' . ($new_user_id > 0 ? $new_user_id : '?') . ' — ' . $empLabel,
  $creator_id ? (int)$creator_id : null
);

$_SESSION['flash_success'] =
  "Login created. Username: {$username} | Temp password: {$temp_password} ".
  "(roles: ".implode(', ', $roleNames).")";
header('Location: employee_view.php?id='.$emp_id);
