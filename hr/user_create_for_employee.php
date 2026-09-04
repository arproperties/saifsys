<?php


require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/audit_bridge.php';
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
         e.position_title, e.department_id, d.name AS dept_name
  FROM employees e
  LEFT JOIN departments d ON d.id = e.department_id
  WHERE e.id=?
");
$stmt->execute([$emp_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) { header('Location: employees.php'); exit; }
if (!empty($emp['user_id'])) { header('Location: employee_view.php?id='.$emp_id); exit; }

/* ===========================
   Helpers
   =========================== */
function ascii_slug_base(string $s): string {
  $s = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
  $s = strtolower($s);
  return preg_replace('/[^a-z0-9]+/', '', $s) ?: 'user';
}
function make_username(PDO $conn, string $full_name, string $emp_code, int $maxLen = 20): string {
  $base = '';
  $parts = preg_split('/\s+/', trim($full_name ?? ''));
  $parts = array_values(array_filter($parts, fn($p)=>$p!==''));
  if (count($parts) >= 2) {
    $base = ascii_slug_base($parts[0]) . ascii_slug_base(end($parts));
  } else {
    $base = ascii_slug_base($full_name ?: $emp_code);
  }
  $base = substr($base, 0, $maxLen);

  $check = $conn->prepare("SELECT COUNT(*) FROM `user` WHERE username=?");
  $name = $base ?: 'user';
  $i=1;
  while (true) {
    $check->execute([$name]);
    if ($check->fetchColumn() == 0) break;
    $suffix = "-$i";
    $name = substr($base, 0, $maxLen - strlen($suffix)) . $suffix;
    $i++;
    if ($i>99) { $name = substr(($base?:'user').uniqid('',true),0,$maxLen); break; }
  }
  return $name;
}
function random_pw($len=10){
  $chars='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  $pw=''; for($i=0;$i<$len;$i++) $pw.=$chars[random_int(0,strlen($chars)-1)];
  return $pw;
}

/**
 * Determine default roles from department & position.
 * Edit the mappings below to fit your business terminology.
 */
function determine_roles_for_employee(array $emp): array {
  $roles = [];

  $dept = strtolower(trim($emp['dept_name'] ?? ''));
  $pos  = strtolower(trim($emp['position_title'] ?? ''));

  // Map departments -> role
  $mapDept = [
    'cleaners'     => 'Cleaner',
    'field'        => 'Cleaner',
    'drivers'      => 'Driver',
    'transport'    => 'Driver',
    'hr'           => 'HR',
    'human'        => 'HR',
    'accounts'     => 'Accountant',
    'accounting'   => 'Accountant',
    'operations'   => 'Dispatcher',
    'dispatch'     => 'Dispatcher',
    'admin'        => 'Admin',
    'management'   => 'Admin',
  ];
  foreach ($mapDept as $needle => $role) {
    if ($dept !== '' && str_contains($dept, $needle)) $roles[] = $role;
  }

  // Map position keywords -> role
  $mapPos = [
    'driver'      => 'Driver',
    'clean'       => 'Cleaner',
    'housekeep'   => 'Cleaner',
    'account'     => 'Accountant',
    'finance'     => 'Accountant',
    'hr'          => 'HR',
    'recruit'     => 'HR',
    'dispatch'    => 'Dispatcher',
    'coordinator' => 'Dispatcher',
    'manager'     => 'Admin',   // up-level managers get Admin; tweak if needed
    'supervisor'  => 'Admin',
  ];
  foreach ($mapPos as $needle => $role) {
    if ($pos !== '' && str_contains($pos, $needle)) $roles[] = $role;
  }

  // Fallback if nothing matched
  if (!$roles) $roles[] = 'Viewer';

  // De-dup & return
  return array_values(array_unique($roles));
}

/* ===========================
   Create user
   =========================== */
$username      = make_username($conn, $emp['full_name'] ?? '', $emp['employee_code'] ?? '', 20);
$temp_password = random_pw(10);

// Legacy auth: store MD5 because `user.password` is VARCHAR(45)
$pass_to_store = md5($temp_password);

$creator_id = $_SESSION['user']['id'] ?? null;
$ins = $conn->prepare("
  INSERT INTO `user` (username, fullname, email, contactnumber, salary, address, password, status, date, creatorid)
  VALUES (?, ?, ?, ?, NULL, ?, ?, 1, CURDATE(), ?)
");
$ins->execute([
  $username,
  $emp['full_name'] ?: $emp['employee_code'],
  $emp['email'] ?? null,
  $emp['phone'] ?? null,
  $emp['address'] ?? null,
  $pass_to_store,
  $creator_id
]);
$new_user_id = (int)$conn->lastInsertId();

/* Link user to employee */
$conn->prepare("UPDATE employees SET user_id=? WHERE id=?")
     ->execute([$new_user_id, $emp_id]);
$conn->prepare("UPDATE `user` SET employee_id=? WHERE id=?")
     ->execute([$emp_id, $new_user_id]);

/* Assign roles dynamically */
$roleNames = determine_roles_for_employee($emp);
$getRoleId = $conn->prepare("SELECT id FROM roles WHERE name=?");
$createRole= $conn->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");

foreach ($roleNames as $rn) {
  $getRoleId->execute([$rn]);
  $rid = (int)$getRoleId->fetchColumn();
  if (!$rid) {
    $createRole->execute([$rn, "Auto-created from department/position"]);
    $rid = (int)$conn->lastInsertId();
  }
  $exists = $conn->prepare("SELECT 1 FROM user_roles WHERE user_id=? AND role_id=?");
  $exists->execute([$new_user_id, $rid]);
  if (!$exists->fetchColumn()) {
    $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$new_user_id, $rid]);
  }
}

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
