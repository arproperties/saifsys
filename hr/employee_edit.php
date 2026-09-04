<?php
// hr/employee_edit.php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_connect.php';
require_once dirname(__DIR__) . '/includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_payroll_accounting.php';
require_role(['Owner','Admin','HR'], $conn);

// ---------- helpers ----------
function fetchAll($conn, $sql, $params = []) {
  $st = $conn->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function fetchOne($conn, $sql, $params = []) {
  $st = $conn->prepare($sql);
  $st->execute($params);
  return $st->fetch(PDO::FETCH_ASSOC);
}
function safeDate($s) {
  if (!$s) return null;
  $t = strtotime($s);
  return $t ? date('Y-m-d', $t) : null;
}

// ---------- lookups ----------
$departments = fetchAll($conn, "SELECT id, name FROM departments ORDER BY name");
$locations   = fetchAll($conn, "SELECT id, name FROM locations ORDER BY name");
$managers    = fetchAll($conn, "SELECT id, COALESCE(NULLIF(full_name,''), employee_code) AS label FROM employees ORDER BY full_name IS NULL, full_name");
$companies   = fetchAll($conn, "SELECT id, name, business_type FROM companies WHERE is_active = 1 ORDER BY name");

// ---------- load existing or defaults ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $id > 0;

$emp = [
  'id' => null,
  'employee_code' => '',
  'first_name' => null,
  'last_name' => null,
  'full_name' => '',
  'nickname' => '',
  'email' => '',
  'phone' => '',
  'address' => '',
  'position_title' => '',
  'department_id' => null,
  'location_id' => null,
  'manager_id' => null,
  'date_joined' => date('Y-m-d'),
  'exit_date' => null,
  'last_working_day' => null,
  'exit_type' => null,
  'exit_reason' => '',
  'eligible_for_rehire' => null,
  'final_settlement_status' => 'not_started',
  'date_of_birth' => null,
  'status' => 'active',
  'basic_salary' => null,
  'allowance' => null,
  'bonus' => null,
  'total_salary' => null,
  'payment_type' => 'wps',
  'bank_account_no' => '',
  'iban' => '',
  // NEW: capacity fields for workers/drivers
  'weekly_cap_hours' => null,
  'daily_cap_hours'  => null,
  'company_id' => null,
];

if ($is_edit) {
  $row = fetchOne($conn, "SELECT * FROM employees WHERE id = ?", [$id]);
  if (!$row) {
    http_response_code(404);
    echo "Employee not found.";
    exit;
  }
  $emp = array_merge($emp, $row);
  if (!empty($emp['date_joined'])) {
    $emp['date_joined'] = date('Y-m-d', strtotime($emp['date_joined']));
  }
  if (!empty($emp['exit_date'])) {
    $emp['exit_date'] = date('Y-m-d', strtotime($emp['exit_date']));
  }
  if (!empty($emp['last_working_day'])) {
    $emp['last_working_day'] = date('Y-m-d', strtotime($emp['last_working_day']));
  }
  if (!empty($emp['date_of_birth'])) {
    $emp['date_of_birth'] = date('Y-m-d', strtotime($emp['date_of_birth']));
  }
}

// ---------- handle post ----------
$errors = [];
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $empBefore = $is_edit ? $emp : null;

  // collect
  $emp['full_name']      = trim($_POST['full_name'] ?? '');
  $emp['nickname']       = trim($_POST['nickname'] ?? '');
  $emp['email']          = trim($_POST['email'] ?? '');
  $emp['phone']          = trim($_POST['phone'] ?? '');
  $emp['address']        = trim($_POST['address'] ?? '');
  $emp['position_title'] = trim($_POST['position_title'] ?? '');
  $emp['department_id']  = $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;
  $emp['location_id']    = $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null;
  $emp['manager_id']     = $_POST['manager_id'] !== '' ? (int)$_POST['manager_id'] : null;
  $emp['date_joined']    = safeDate($_POST['date_joined'] ?? null) ?? null;
  $emp['exit_date']      = safeDate($_POST['exit_date'] ?? null) ?? null;
  $emp['last_working_day'] = safeDate($_POST['last_working_day'] ?? null) ?? null;
  $emp['exit_type']      = ($_POST['exit_type'] ?? '') !== '' ? $_POST['exit_type'] : null;
  $emp['exit_reason']    = trim($_POST['exit_reason'] ?? '');
  $emp['eligible_for_rehire'] = ($_POST['eligible_for_rehire'] ?? '') !== '' ? (int)$_POST['eligible_for_rehire'] : null;
  $emp['final_settlement_status'] = $_POST['final_settlement_status'] ?? 'not_started';
  $emp['date_of_birth']  = safeDate($_POST['date_of_birth'] ?? null) ?? null;
  $emp['status']         = $_POST['status'] ?? 'active';
  $emp['basic_salary']   = $_POST['basic_salary'] !== '' ? number_format((float)$_POST['basic_salary'], 2, '.', '') : null;
  $emp['allowance']      = $_POST['allowance'] !== '' ? number_format((float)$_POST['allowance'],  2, '.', '') : null;
  $emp['bonus']          = $_POST['bonus'] !== '' ? number_format((float)$_POST['bonus'],      2, '.', '') : null;
  $emp['total_salary']   = $_POST['total_salary'] !== '' ? number_format((float)$_POST['total_salary'], 2, '.', '') : null;
  $emp['payment_type']   = $_POST['payment_type'] ?? 'wps';
  $emp['bank_account_no']= trim($_POST['bank_account_no'] ?? '');
  $emp['iban']           = trim($_POST['iban'] ?? '');
  // NEW: capacity fields (only used when dept=Cleaners/Drivers)
  $emp['weekly_cap_hours'] = $_POST['weekly_cap_hours'] !== '' ? number_format((float)$_POST['weekly_cap_hours'], 2, '.', '') : null;
  $emp['daily_cap_hours']  = $_POST['daily_cap_hours']  !== '' ? number_format((float)$_POST['daily_cap_hours'],  2, '.', '') : null;
  $emp['company_id']     = $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : null;

  // basic validations
  if ($emp['full_name'] === '') $errors[] = "Full name is required.";
  if ($emp['status'] === '') $errors[] = "Status is required.";
  if (!array_key_exists($emp['status'], hr_employee_status_options())) $errors[] = "Invalid employee status.";
  if (!array_key_exists($emp['payment_type'], hr_payroll_payment_types())) $errors[] = "Invalid payment type.";
  if ($emp['exit_type'] !== null && !array_key_exists($emp['exit_type'], hr_employee_exit_type_options())) $errors[] = "Invalid exit type.";
  if (!array_key_exists($emp['final_settlement_status'], hr_employee_settlement_options())) $errors[] = "Invalid final settlement status.";
  if (in_array($emp['status'], hr_employee_left_statuses(), true) && !$emp['exit_date'] && !$emp['last_working_day']) {
    $errors[] = "Exit date or last working day is required for employees who left.";
  }
  if ($emp['date_joined'] && $emp['exit_date'] && $emp['exit_date'] < $emp['date_joined']) {
    $errors[] = "Exit date cannot be before date joined.";
  }

  if (!$errors) {

    // We’ll need the selected department name after saving:
    $deptName = null;
    if ($emp['department_id']) {
      $d = fetchOne($conn, "SELECT name FROM departments WHERE id = ?", [$emp['department_id']]);
      $deptName = $d ? trim($d['name']) : null;
    }

    if ($is_edit) {
      // update EMPLOYEE
      $sql = "UPDATE employees SET
        full_name=:full_name, nickname=:nickname, email=:email, phone=:phone, address=:address,
        position_title=:position_title, department_id=:department_id, location_id=:location_id, manager_id=:manager_id,
        date_joined=:date_joined, exit_date=:exit_date, last_working_day=:last_working_day,
        exit_type=:exit_type, exit_reason=:exit_reason, eligible_for_rehire=:eligible_for_rehire,
        final_settlement_status=:final_settlement_status,
        date_of_birth=:date_of_birth, status=:status, basic_salary=:basic_salary, allowance=:allowance, bonus=:bonus,
        total_salary=:total_salary, payment_type=:payment_type, bank_account_no=:bank_account_no, iban=:iban,
        weekly_cap_hours=:weekly_cap_hours, daily_cap_hours=:daily_cap_hours, company_id=:company_id
        WHERE id=:id";
      $st = $conn->prepare($sql);
      $emp['id'] = $id;
      $st->execute([
        ':full_name'=>$emp['full_name'],
        ':nickname'=>$emp['nickname'],
        ':email'=>$emp['email'],
        ':phone'=>$emp['phone'],
        ':address'=>$emp['address'],
        ':position_title'=>$emp['position_title'],
        ':department_id'=>$emp['department_id'],
        ':location_id'=>$emp['location_id'],
        ':manager_id'=>$emp['manager_id'],
        ':date_joined'=>$emp['date_joined'],
        ':exit_date'=>$emp['exit_date'],
        ':last_working_day'=>$emp['last_working_day'],
        ':exit_type'=>$emp['exit_type'],
        ':exit_reason'=>$emp['exit_reason'] !== '' ? $emp['exit_reason'] : null,
        ':eligible_for_rehire'=>$emp['eligible_for_rehire'],
        ':final_settlement_status'=>$emp['final_settlement_status'],
        ':date_of_birth'=>$emp['date_of_birth'],
        ':status'=>$emp['status'],
        ':basic_salary'=>$emp['basic_salary'],
        ':allowance'=>$emp['allowance'],
        ':bonus'=>$emp['bonus'],
        ':total_salary'=>$emp['total_salary'],
        ':payment_type'=>$emp['payment_type'],
        ':bank_account_no'=>$emp['bank_account_no'],
        ':iban'=>$emp['iban'],
        ':weekly_cap_hours'=>$emp['weekly_cap_hours'],
        ':daily_cap_hours'=>$emp['daily_cap_hours'],
        ':company_id'=>$emp['company_id'],
        ':id'=>$emp['id']
      ]);
      $ok = true;

      $empLabel = trim(($emp['full_name'] ?? '') . ' (' . ($emp['employee_code'] ?? ('#' . $id)) . ')');
      $uid = $_SESSION['user']['id'] ?? null;
      audit_bridge_hr_ops(
        'employee_updated',
        'employees',
        $id,
        'Updated employee ' . $empLabel
          . ' (status ' . ($emp['status'] ?? '') . ', total salary AED ' . number_format((float)($emp['total_salary'] ?? 0), 2) . ')',
        isset($emp['company_id']) ? (int)$emp['company_id'] : null,
        [
          'employee_code' => $emp['employee_code'] ?? null,
          'status' => $emp['status'] ?? null,
          'from_status' => $empBefore['status'] ?? null,
          'position_title' => $emp['position_title'] ?? null,
          'department_id' => $emp['department_id'] ?? null,
          'company_id' => $emp['company_id'] ?? null,
          'basic_salary' => $emp['basic_salary'] ?? null,
          'allowance' => $emp['allowance'] ?? null,
          'total_salary' => $emp['total_salary'] ?? null,
          'payment_type' => $emp['payment_type'] ?? null,
          'exit_date' => $emp['exit_date'] ?? null,
        ],
        $empLabel,
        $uid ? (int)$uid : null
      );

      // OPTIONAL: keep linked worker/driver in sync on edit (only if exists)
      if ($deptName && strcasecmp($deptName, 'Cleaners') === 0) {
        // locate worker by emp_num = employee_code
        $code = $emp['employee_code'];
        if ($code) {
          $has = fetchOne($conn, "SELECT id FROM workers WHERE emp_num = ? LIMIT 1", [$code]);
          if ($has) {
            $conn->prepare("
              UPDATE workers SET
                worker_name = :worker_name,
                nickname = :nickname,
                email = :email,
                mobile_num = :mobile_num,
                weekly_cap_hours = :weekly_cap_hours,
                daily_cap_hours  = :daily_cap_hours,
                basic_salary = :basic_salary,
                allowance    = :allowance,
                bonus        = :bonus,
                total_salary = :total_salary
              WHERE emp_num = :emp_num
            ")->execute([
              ':worker_name'=>$emp['full_name'],
              ':nickname'=>$emp['nickname'],
              ':email'=>$emp['email'],
              ':mobile_num'=>$emp['phone'],
              ':weekly_cap_hours'=>$emp['weekly_cap_hours'],
              ':daily_cap_hours'=>$emp['daily_cap_hours'],
              ':basic_salary'=>$emp['basic_salary'],
              ':allowance'=>$emp['allowance'],
              ':bonus'=>$emp['bonus'],
              ':total_salary'=>$emp['total_salary'],
              ':emp_num'=>$code,
            ]);
          }
        }
      } elseif ($deptName && strcasecmp($deptName, 'Drivers') === 0) {
        $code = $emp['employee_code'];
        if ($code) {
          try {
            $has = fetchOne($conn, "SELECT id FROM driver WHERE emp_num = ? LIMIT 1", [$code]);
            if ($has) {
              $conn->prepare("
                UPDATE driver SET
                  driver_name   = :driver_name,
                  nickname      = :nickname,
                  email         = :email,
                  mobile_num    = :mobile_num,
                  basic_salary  = :basic_salary,
                  allowance     = :allowance,
                  bonus         = :bonus,
                  total_salary  = :total_salary,
                  join_date     = :join_date,
                  bank_acc_num  = :bank_acc_num,
                  iban_num      = :iban_num,
                  address       = :address,
                  position      = :position
                WHERE emp_num = :emp_num
              ")->execute([
                ':driver_name'  => $emp['full_name'],
                ':nickname'     => $emp['nickname'],
                ':email'        => $emp['email'],
                ':mobile_num'   => $emp['phone'],
                ':basic_salary' => $emp['basic_salary'],
                ':allowance'    => $emp['allowance'],
                ':bonus'        => $emp['bonus'],
                ':total_salary' => $emp['total_salary'],
                ':join_date'    => $emp['date_joined'],
                ':bank_acc_num' => $emp['bank_account_no'],
                ':iban_num'     => $emp['iban'],
                ':address'      => $emp['address'],
                ':position'     => $emp['position_title'],
                ':emp_num'      => $code,
              ]);
            } else {
              // create if missing
              $conn->prepare("
                INSERT INTO driver
                  (emp_num, driver_name, nickname, email, mobile_num,
                   basic_salary, allowance, bonus, total_salary,
                   join_date, bank_acc_num, iban_num, address, position)
                VALUES
                  (:emp_num, :driver_name, :nickname, :email, :mobile_num,
                   :basic_salary, :allowance, :bonus, :total_salary,
                   :join_date, :bank_acc_num, :iban_num, :address, :position)
              ")->execute([
                ':emp_num'      => $code,
                ':driver_name'  => $emp['full_name'],
                ':nickname'     => $emp['nickname'],
                ':email'        => $emp['email'],
                ':mobile_num'   => $emp['phone'],
                ':basic_salary' => $emp['basic_salary'],
                ':allowance'    => $emp['allowance'],
                ':bonus'        => $emp['bonus'],
                ':total_salary' => $emp['total_salary'],
                ':join_date'    => $emp['date_joined'],
                ':bank_acc_num' => $emp['bank_account_no'],
                ':iban_num'     => $emp['iban'],
                ':address'      => $emp['address'],
                ':position'     => $emp['position_title'],
              ]);
            }
          } catch (Throwable $e) {
             error_log('Driver sync failed: '.$e->getMessage());
          }
        }
      }

    } else {
      // INSERT EMPLOYEE
      // Generate provisional employee_code for UX
      $stmtMax = $conn->query("SELECT MAX(id) AS max_id FROM employees");
      $maxId = (int)$stmtMax->fetch(PDO::FETCH_ASSOC)['max_id'] + 1;
      $provisionalCode = 'E' . str_pad((string)$maxId, 5, '0', STR_PAD_LEFT);

      $sql = "INSERT INTO employees
        (employee_code, full_name, nickname, email, phone, address, position_title, department_id, location_id, manager_id,
         date_joined, date_of_birth, status, basic_salary, allowance, bonus, total_salary, payment_type, bank_account_no, iban,
         exit_date, last_working_day, exit_type, exit_reason, eligible_for_rehire, final_settlement_status,
         weekly_cap_hours, daily_cap_hours, company_id)
      VALUES
        (:employee_code, :full_name,:nickname,:email,:phone,:address,:position_title,:department_id,:location_id,:manager_id,
         :date_joined,:date_of_birth,:status,:basic_salary,:allowance,:bonus,:total_salary,:payment_type,:bank_account_no,:iban,
         :exit_date,:last_working_day,:exit_type,:exit_reason,:eligible_for_rehire,:final_settlement_status,
         :weekly_cap_hours,:daily_cap_hours,:company_id)";
      $st = $conn->prepare($sql);
      $st->execute([
        ':employee_code'=>$provisionalCode,
        ':full_name'=>$emp['full_name'],
        ':nickname'=>$emp['nickname'],
        ':email'=>$emp['email'],
        ':phone'=>$emp['phone'],
        ':address'=>$emp['address'],
        ':position_title'=>$emp['position_title'],
        ':department_id'=>$emp['department_id'],
        ':location_id'=>$emp['location_id'],
        ':manager_id'=>$emp['manager_id'],
        ':date_joined'=>$emp['date_joined'],
        ':date_of_birth'=>$emp['date_of_birth'],
        ':status'=>$emp['status'],
        ':exit_date'=>$emp['exit_date'],
        ':last_working_day'=>$emp['last_working_day'],
        ':exit_type'=>$emp['exit_type'],
        ':exit_reason'=>$emp['exit_reason'] !== '' ? $emp['exit_reason'] : null,
        ':eligible_for_rehire'=>$emp['eligible_for_rehire'],
        ':final_settlement_status'=>$emp['final_settlement_status'],
        ':basic_salary'=>$emp['basic_salary'],
        ':allowance'=>$emp['allowance'],
        ':bonus'=>$emp['bonus'],
        ':total_salary'=>$emp['total_salary'],
        ':payment_type'=>$emp['payment_type'],
        ':bank_account_no'=>$emp['bank_account_no'],
        ':iban'=>$emp['iban'],
        ':weekly_cap_hours'=>$emp['weekly_cap_hours'],
        ':daily_cap_hours'=>$emp['daily_cap_hours'],
        ':company_id'=>$emp['company_id'],
      ]);
      $newId = (int)$conn->lastInsertId();

      // Final employee_code
      $code = 'E' . str_pad((string)$newId, 5, '0', STR_PAD_LEFT);
      $conn->prepare("UPDATE employees SET employee_code=? WHERE id=? AND (employee_code IS NULL OR employee_code='')")
           ->execute([$code, $newId]);

      $empLabel = trim(($emp['full_name'] ?? '') . ' (' . $code . ')');
      $uid = $_SESSION['user']['id'] ?? null;
      audit_bridge_hr_ops(
        'employee_created',
        'employees',
        $newId,
        'Created employee ' . $empLabel
          . ' (status ' . ($emp['status'] ?? '') . ', total salary AED ' . number_format((float)($emp['total_salary'] ?? 0), 2) . ')',
        isset($emp['company_id']) ? (int)$emp['company_id'] : null,
        [
          'employee_code' => $code,
          'status' => $emp['status'] ?? null,
          'position_title' => $emp['position_title'] ?? null,
          'department_id' => $emp['department_id'] ?? null,
          'company_id' => $emp['company_id'] ?? null,
          'basic_salary' => $emp['basic_salary'] ?? null,
          'allowance' => $emp['allowance'] ?? null,
          'total_salary' => $emp['total_salary'] ?? null,
          'payment_type' => $emp['payment_type'] ?? null,
          'date_joined' => $emp['date_joined'] ?? null,
        ],
        $empLabel,
        $uid ? (int)$uid : null
      );

        // ---------- NEW: auto-create Worker/Driver ----------
        if ($deptName && strcasecmp($deptName, 'Cleaners') === 0) {
          // upsert worker by emp_num
          $has = fetchOne($conn, "SELECT id FROM workers WHERE emp_num = ? LIMIT 1", [$code]);
          if ($has) {
            $conn->prepare("
              UPDATE workers SET
                worker_name = :worker_name,
                nickname = :nickname,
                email = :email,
                mobile_num = :mobile_num,
                weekly_cap_hours = :weekly_cap_hours,
                daily_cap_hours  = :daily_cap_hours,
                basic_salary = :basic_salary,
                allowance    = :allowance,
                bonus        = :bonus,
                total_salary = :total_salary
              WHERE emp_num = :emp_num
            ")->execute([
              ':worker_name'=>$emp['full_name'],
              ':nickname'=>$emp['nickname'],
              ':email'=>$emp['email'],
              ':mobile_num'=>$emp['phone'],
              ':weekly_cap_hours'=>$emp['weekly_cap_hours'],
              ':daily_cap_hours'=>$emp['daily_cap_hours'],
              ':basic_salary'=>$emp['basic_salary'],
              ':allowance'=>$emp['allowance'],
              ':bonus'=>$emp['bonus'],
              ':total_salary'=>$emp['total_salary'],
              ':emp_num'=>$code,
            ]);
          } else {
            $conn->prepare("
              INSERT INTO workers
                (emp_num, worker_name, nickname, email, mobile_num,
                 weekly_cap_hours, daily_cap_hours,
                 basic_salary, allowance, bonus, total_salary)
              VALUES
                (:emp_num, :worker_name, :nickname, :email, :mobile_num,
                 :weekly_cap_hours, :daily_cap_hours,
                 :basic_salary, :allowance, :bonus, :total_salary)
            ")->execute([
              ':emp_num'=>$code,
              ':worker_name'=>$emp['full_name'],
              ':nickname'=>$emp['nickname'],
              ':email'=>$emp['email'],
              ':mobile_num'=>$emp['phone'],
              ':weekly_cap_hours'=>$emp['weekly_cap_hours'],
              ':daily_cap_hours'=>$emp['daily_cap_hours'],
              ':basic_salary'=>$emp['basic_salary'],
              ':allowance'=>$emp['allowance'],
              ':bonus'=>$emp['bonus'],
              ':total_salary'=>$emp['total_salary'],
            ]);
          }

        } elseif ($deptName && strcasecmp($deptName, 'Drivers') === 0) {
          // upsert driver with id = employees.id (same-id design)
          $has = fetchOne($conn, "SELECT id FROM driver WHERE id = ? OR emp_num = ? LIMIT 1", [$newId, $code]);

          if ($has) {
            $conn->prepare("
              UPDATE driver SET
                driver_name   = :driver_name,
                nickname      = :nickname,
                email         = :email,
                mobile_num    = :mobile_num,
                basic_salary  = :basic_salary,
                allowance     = :allowance,
                bonus         = :bonus,
                total_salary  = :total_salary,
                join_date     = :join_date,
                bank_acc_num  = :bank_acc_num,
                iban_num      = :iban_num,
                address       = :address,
                position      = :position
              WHERE id = :id
            ")->execute([
              ':driver_name'  => $emp['full_name'],
              ':nickname'     => $emp['nickname'],
              ':email'        => $emp['email'],
              ':mobile_num'   => $emp['phone'],
              ':basic_salary' => $emp['basic_salary'],
              ':allowance'    => $emp['allowance'],
              ':bonus'        => $emp['bonus'],
              ':total_salary' => $emp['total_salary'],
              ':join_date'    => $emp['date_joined'],
              ':bank_acc_num' => $emp['bank_account_no'],
              ':iban_num'     => $emp['iban'],
              ':address'      => $emp['address'],
              ':position'     => $emp['position_title'],
              ':id'           => $newId,
            ]);
          } else {
            $conn->prepare("
              INSERT INTO driver
                (id, emp_num, driver_name, nickname, email, mobile_num,
                 basic_salary, allowance, bonus, total_salary,
                 join_date, bank_acc_num, iban_num, address, position)
              VALUES
                (:id, :emp_num, :driver_name, :nickname, :email, :mobile_num,
                 :basic_salary, :allowance, :bonus, :total_salary,
                 :join_date, :bank_acc_num, :iban_num, :address, :position)
            ")->execute([
              ':id'           => $newId,       // same id as employees.id
              ':emp_num'      => $code,        // E00xxx
              ':driver_name'  => $emp['full_name'],
              ':nickname'     => $emp['nickname'],
              ':email'        => $emp['email'],
              ':mobile_num'   => $emp['phone'],
              ':basic_salary' => $emp['basic_salary'],
              ':allowance'    => $emp['allowance'],
              ':bonus'        => $emp['bonus'],
              ':total_salary' => $emp['total_salary'],
              ':join_date'    => $emp['date_joined'],
              ':bank_acc_num' => $emp['bank_account_no'],
              ':iban_num'     => $emp['iban'],
              ':address'      => $emp['address'],
              ':position'     => $emp['position_title'],
            ]);
          }
        }

      header("Location: employee_edit?id=".$newId."&created=1");
      exit;
    }
  }
}

$pageTitle = $is_edit ? 'Edit Employee' : 'Add Employee';
$pageStyles = '.form-section-title{font-weight:700;color:#334}';
$pageScripts = <<<'JS'
<script>
function toNum(v){ return parseFloat(v||0) || 0; }
function recalc(){
  const b = toNum(document.getElementById('basic_salary').value);
  const a = toNum(document.getElementById('allowance').value);
  const o = toNum(document.getElementById('bonus').value);
  document.getElementById('total_salary').value = (b+a+o).toFixed(2);
}
['basic_salary','allowance','bonus'].forEach(id=>{
  const el = document.getElementById(id);
  if(el) el.addEventListener('input', recalc);
});
const deptSel = document.getElementById('department_id');
const capRow  = document.getElementById('capRow');
function toggleCap() {
  const opt = deptSel.options[deptSel.selectedIndex];
  const name = (opt && (opt.getAttribute('data-name')||'')).toLowerCase();
  if (name === 'cleaners') {
    capRow.classList.remove('d-none');
  } else {
    capRow.classList.add('d-none');
  }
}
if (deptSel) {
  deptSel.addEventListener('change', toggleCap);
  toggleCap();
}
</script>
JS;
require_once __DIR__ . '/includes/hr_layout_header.php';

$editActions = '<a class="btn btn-outline-secondary" href="employees">Back to list</a>';
if ($is_edit) {
    $editActions .= ' <span class="badge text-bg-light align-self-center">Code: ' . htmlspecialchars($emp['employee_code'] ?: '—') . '</span>';
}
echo hr_ui_page_header(
    $pageTitle,
    $is_edit ? 'Update employee profile, compensation, and offboarding details.' : 'Create a new employee record.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Employees', 'href' => 'employees'],
        ['label' => $is_edit ? 'Edit' : 'Add'],
    ],
    $editActions
);
?>

  <?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success">Employee created.</div>
  <?php endif; ?>
  <?php if ($ok): ?>
    <div class="alert alert-success">Saved successfully.</div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger mb-3">
      <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
    </div>
  <?php endif; ?>

  <form method="post" class="hr-settings-card">
    <?php csrf_field(); ?>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Full name <span class="text-danger">*</span></label>
          <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($emp['full_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Nickname</label>
          <input type="text" name="nickname" class="form-control" value="<?= htmlspecialchars($emp['nickname'] ?? '') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($emp['email'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($emp['phone'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Date joined</label>
          <input type="date" name="date_joined" class="form-control" value="<?= htmlspecialchars($emp['date_joined'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Date of Birth</label>
          <input type="date" name="date_of_birth" class="form-control" value="<?= htmlspecialchars($emp['date_of_birth'] ?? '') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Position</label>
          <input type="text" name="position_title" class="form-control" value="<?= htmlspecialchars($emp['position_title'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Department</label>
          <select name="department_id" id="department_id" class="form-select">
            <option value="">—</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= (int)$d['id'] ?>"
                      data-name="<?= htmlspecialchars($d['name']) ?>"
                      <?= $emp['department_id']==$d['id']?'selected':'' ?>>
                <?= htmlspecialchars($d['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Location</label>
          <select name="location_id" class="form-select">
            <option value="">—</option>
            <?php foreach ($locations as $l): ?>
              <option value="<?= (int)$l['id'] ?>" <?= $emp['location_id']==$l['id']?'selected':'' ?>>
                <?= htmlspecialchars($l['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Company</label>
          <select name="company_id" class="form-select">
            <option value="">— Select Company —</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $emp['company_id']==$c['id']?'selected':'' ?>>
                <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['business_type']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Manager</label>
          <select name="manager_id" class="form-select">
            <option value="">—</option>
            <?php foreach ($managers as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= $emp['manager_id']===$m['id']?'selected':'' ?>>
                <?= htmlspecialchars($m['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Status</label>
          <select name="status" id="employee_status" class="form-select">
            <?php
            $opts = hr_employee_status_options();
            foreach ($opts as $k=>$v): ?>
              <option value="<?= $k ?>" <?= ($emp['status'] ?? '')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Current workforce: Active, On leave, Notice period. Left records stay for history.</div>
        </div>

        <div class="col-12"><hr></div>
        <div class="col-12"><div class="form-section-title">Offboarding / Exit</div></div>

        <div class="col-md-3">
          <label class="form-label">Exit type</label>
          <select name="exit_type" id="exit_type" class="form-select">
            <?php foreach (hr_employee_exit_type_options() as $k=>$v): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= (string)($emp['exit_type'] ?? '')===(string)$k?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Exit date</label>
          <input type="date" name="exit_date" id="exit_date" class="form-control" value="<?= htmlspecialchars($emp['exit_date'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Last working day</label>
          <input type="date" name="last_working_day" id="last_working_day" class="form-control" value="<?= htmlspecialchars($emp['last_working_day'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Eligible for rehire</label>
          <select name="eligible_for_rehire" class="form-select">
            <option value="" <?= ($emp['eligible_for_rehire'] ?? null) === null ? 'selected' : '' ?>>—</option>
            <option value="1" <?= (string)($emp['eligible_for_rehire'] ?? '') === '1' ? 'selected' : '' ?>>Yes</option>
            <option value="0" <?= (string)($emp['eligible_for_rehire'] ?? '') === '0' ? 'selected' : '' ?>>No</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Final settlement</label>
          <select name="final_settlement_status" class="form-select">
            <?php foreach (hr_employee_settlement_options() as $k=>$v): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= (string)($emp['final_settlement_status'] ?? 'not_started')===(string)$k?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label">Exit reason / notes</label>
          <input type="text" name="exit_reason" class="form-control" value="<?= htmlspecialchars($emp['exit_reason'] ?? '') ?>" placeholder="Optional reason, handover note, or contract detail">
        </div>

        <div class="col-12"><hr></div>
        <div class="col-12"><div class="form-section-title">Compensation</div></div>

        <div class="col-md-3">
          <label class="form-label">Basic salary</label>
          <input type="number" step="0.01" name="basic_salary" id="basic_salary" class="form-control" value="<?= htmlspecialchars($emp['basic_salary']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Allowance</label>
          <input type="number" step="0.01" name="allowance" id="allowance" class="form-control" value="<?= htmlspecialchars($emp['allowance']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Bonus</label>
          <input type="number" step="0.01" name="bonus" id="bonus" class="form-control" value="<?= htmlspecialchars($emp['bonus']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Total</label>
          <input type="number" step="0.01" name="total_salary" id="total_salary" class="form-control" value="<?= htmlspecialchars($emp['total_salary']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Payment Type</label>
          <select name="payment_type" class="form-select">
            <?php foreach (hr_payroll_payment_types() as $payType => $payLabel): ?>
              <option value="<?= htmlspecialchars($payType) ?>" <?= ($emp['payment_type'] ?? 'wps') === $payType ? 'selected' : '' ?>>
                <?= htmlspecialchars($payLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Used to split company payroll into WPS and Cash runs.</div>
        </div>

        <!-- NEW: capacity fields for Workers/Drivers -->
        <div id="capRow" class="row g-3 mt-0 <?= ($emp['department_id'] ? '' : 'd-none') ?>">
          <div class="col-md-3">
            <label class="form-label">Weekly cap hours</label>
            <input type="number" step="0.25" name="weekly_cap_hours" id="weekly_cap_hours" class="form-control"
                   value="<?= htmlspecialchars($emp['weekly_cap_hours']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Daily cap hours</label>
            <input type="number" step="0.25" name="daily_cap_hours" id="daily_cap_hours" class="form-control"
                   value="<?= htmlspecialchars($emp['daily_cap_hours']) ?>">
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Bank account no.</label>
          <input type="text" name="bank_account_no" class="form-control" value="<?= htmlspecialchars($emp['bank_account_no'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">IBAN</label>
          <input type="text" name="iban" class="form-control" value="<?= htmlspecialchars($emp['iban'] ?? '') ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Address</label>
          <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($emp['address'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <a href="employees" class="btn btn-outline-secondary">Cancel</a>
      <button type="submit" class="btn btn-success"><?= $is_edit ? 'Save Changes' : 'Create Employee' ?></button>
    </div>
  </form>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
