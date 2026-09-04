<?php


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/includes/hr_payroll_company_access.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_schedule_helper.php';
require_once __DIR__ . '/includes/hr_payroll_accounting.php';
require_once __DIR__ . '/includes/hr_loans.php';
require_once __DIR__ . '/includes/hr_wps_guards.php';
require_role(['Owner','Admin','HR'], $conn);

$run_id = (int)($_GET['id'] ?? $_POST['run_id'] ?? $_POST['id'] ?? 0);
if (!$run_id) { http_response_code(400); exit('Missing run id'); }

// Load payroll run
$run = $conn->prepare("SELECT * FROM payroll_runs WHERE id=?");
$run->execute([$run_id]);
$run = $run->fetch(PDO::FETCH_ASSOC);
if (!$run) { http_response_code(404); exit('Run not found'); }

hr_payroll_require_run_access($conn, $run);

$from       = $run['period_from'];
$to         = $run['period_to'];
$run_status = $run['status'];
$canEditRun = in_array($run_status, ['open', 'draft'], true);
$companyId  = (int)($run['company_id'] ?? 1);
$payrollType = (($run['payroll_type'] ?? 'wps') === 'cash') ? 'cash' : 'wps';
$canOverrideValidation = hr_payroll_can_override_validation($conn);
$runHasValidationOverride = hr_payroll_validation_override_schema_ready($conn)
    && !empty($run['validation_override']);

$companyName = '';
try {
    $cn = $conn->prepare("SELECT name FROM companies WHERE id = ? LIMIT 1");
    $cn->execute([$companyId]);
    $companyName = (string)($cn->fetchColumn() ?: '');
} catch (Throwable $e) {
    $companyName = '';
}

function n($v){ return ($v===''||$v===null)?0.0:(float)$v; }

/* ---------------- Employees (company scope) ---------------- */
$emps = $conn->prepare("
  SELECT id, employee_code, full_name, COALESCE(payment_type, 'wps') AS payment_type,
         COALESCE(basic_salary,0) AS base_pay,
         COALESCE(allowance,0)    AS allowance
  FROM employees
  WHERE company_id = ?
    AND COALESCE(payment_type, 'wps') = ?
    AND " . hr_employee_is_employed_for_period_sql('employees') . "
  ORDER BY full_name
");
$emps->execute(array_merge([$companyId, $payrollType, $to], hr_employee_current_statuses(), [$from]));
$emps = $emps->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- Schedule expectations + attendance absences ---------------- */
$scheduleByEmp = [];
foreach ($emps as $employeeRow) {
  $scheduleByEmp[(int)$employeeRow['id']] = hr_schedule_summary($conn, (int)$employeeRow['id'], $from, $to);
}

// Unpaid absence days from attendance: full absent = 1.0, half = 0.5 (on_leave ignored).
$absByEmp = [];
$stAbs = $conn->prepare("
  SELECT a.employee_id, a.work_date, a.status
  FROM attendance a
  INNER JOIN employees e ON e.id = a.employee_id AND e.company_id = ?
  WHERE a.work_date BETWEEN ? AND ?
    AND a.status IN ('absent', 'half')
  ORDER BY a.employee_id, a.work_date
");
$stAbs->execute([$companyId, $from,$to]);
foreach($stAbs->fetchAll(PDO::FETCH_ASSOC) as $r){
  $eid = (int)$r['employee_id'];
  $workDate = (string)$r['work_date'];
  $schedule = $scheduleByEmp[$eid] ?? ['has_schedule' => false, 'days' => []];
  if (!empty($schedule['has_schedule']) && empty($schedule['days'][$workDate]['is_workday'])) {
    continue;
  }
  $add = (($r['status'] ?? '') === 'half') ? 0.5 : 1.0;
  $absByEmp[$eid] = round(($absByEmp[$eid] ?? 0) + $add, 2);
}

/* ---------------- Overtime (approved) ---------------- */
$otByEmp = [];
$stOT = $conn->prepare("
  SELECT o.employee_id,
         COALESCE(SUM(o.pay_hours),0)  AS tot_hours,
         COALESCE(SUM(o.pay_amount),0) AS tot_amount
  FROM overtime_entries o
  INNER JOIN employees e ON e.id = o.employee_id AND e.company_id = ?
  WHERE o.ot_date BETWEEN ? AND ? AND o.status='approved'
  GROUP BY o.employee_id
");
$stOT->execute([$companyId, $from,$to]);
foreach($stOT->fetchAll(PDO::FETCH_ASSOC) as $r){
  $otByEmp[(int)$r['employee_id']] = [
    'hours'=>(float)$r['tot_hours'],
    'amount'=>(float)$r['tot_amount'],
  ];
}

/* ---------------- Current available balances ----------------
   Payroll may be built after the salary period closes, so HR needs the current
   outstanding balances visible while deciding deductions for this run.
----------------------------------------------------------------------------- */

// Loan / salary advance outstanding (prefer remaining_balance when migration applied)
$advGiven = [];
$advApplied = [];
if (hr_loans_schema_ready($conn)) {
  $stAdv = $conn->prepare("
    SELECT ca.employee_id, COALESCE(SUM(COALESCE(ca.remaining_balance, ca.amount)),0) amt
    FROM cash_advances ca
    INNER JOIN employees e ON e.id = ca.employee_id AND e.company_id = ?
    WHERE ca.status = 'open'
      AND (ca.request_status IS NULL OR ca.request_status = 'approved')
    GROUP BY ca.employee_id
  ");
  $stAdv->execute([$companyId]);
  foreach ($stAdv->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $advGiven[(int)$r['employee_id']] = (float)$r['amt'];
    $advApplied[(int)$r['employee_id']] = 0.0;
  }
} else {
  $stAdv = $conn->prepare("
    SELECT ca.employee_id, COALESCE(SUM(ca.amount),0) amt
    FROM cash_advances ca
    INNER JOIN employees e ON e.id = ca.employee_id AND e.company_id = ?
    WHERE ca.status <> 'void'
      AND (ca.request_status IS NULL OR ca.request_status = 'approved')
    GROUP BY ca.employee_id
  ");
  $stAdv->execute([$companyId]);
  foreach ($stAdv->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $advGiven[(int)$r['employee_id']] = (float)$r['amt'];
  }
  $stAdvApp = $conn->prepare("
    SELECT pi.employee_id, COALESCE(SUM(pi.adv_applied),0) repaid
    FROM payroll_items pi
    JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
    WHERE pr.company_id = ?
      AND pr.id <> ?
      AND pr.status IN ('open','posted','finalized','paid')
    GROUP BY pi.employee_id
  ");
  $stAdvApp->execute([$companyId, $run_id]);
  foreach ($stAdvApp->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $advApplied[(int)$r['employee_id']] = (float)$r['repaid'];
  }
}

// Other deductions (fines etc.) — prefer remaining_balance when available
$dedGiven = [];
$dedApplied = [];
$hasDedRemaining = false;
try {
  $chkDed = $conn->query("SHOW COLUMNS FROM employee_deductions LIKE 'remaining_balance'");
  $hasDedRemaining = $chkDed && $chkDed->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $hasDedRemaining = false;
}
if ($hasDedRemaining) {
  $stDed = $conn->prepare("
    SELECT ed.employee_id, COALESCE(SUM(COALESCE(ed.remaining_balance, ed.amount)),0) amt
    FROM employee_deductions ed
    INNER JOIN employees e ON e.id = ed.employee_id AND e.company_id = ?
    WHERE COALESCE(ed.settle_status, 'open') = 'open'
    GROUP BY ed.employee_id
  ");
  $stDed->execute([$companyId]);
  foreach ($stDed->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $dedGiven[(int)$r['employee_id']] = (float)$r['amt'];
    $dedApplied[(int)$r['employee_id']] = 0.0;
  }
} else {
  $stDed = $conn->prepare("
    SELECT ed.employee_id, COALESCE(SUM(ed.amount),0) amt
    FROM employee_deductions ed
    INNER JOIN employees e ON e.id = ed.employee_id AND e.company_id = ?
    GROUP BY ed.employee_id
  ");
  $stDed->execute([$companyId]);
  foreach ($stDed->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $dedGiven[(int)$r['employee_id']] = (float)$r['amt'];
  }
  $stDedApp = $conn->prepare("
    SELECT pi.employee_id, COALESCE(SUM(pi.other_applied),0) used_amt
    FROM payroll_items pi
    JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
    WHERE pr.company_id = ?
      AND pr.id <> ?
      AND pr.status IN ('open','posted','finalized','paid')
    GROUP BY pi.employee_id
  ");
  $stDedApp->execute([$companyId, $run_id]);
  foreach ($stDedApp->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $dedApplied[(int)$r['employee_id']] = (float)$r['used_amt'];
  }
}

/* ---------------- POST (save / post) ---------------- */
$msg = $err = '';
if (isset($_GET['saved']) && (string)$_GET['saved'] === '1') {
  $msg = 'Draft values saved. Post Run to finalize.';
  if (isset($_GET['override']) && (string)$_GET['override'] === '1') {
    $msg = 'Draft saved with Validation Override. Post Run to finalize.';
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $canEditRun){
  csrf_verify();
  $base       = $_POST['base']       ?? [];
  $allow      = $_POST['allow']      ?? [];
  $ot_amt     = $_POST['ot_amt']     ?? [];
  $bonus_plus = $_POST['bonus_plus'] ?? [];
  $abs_days   = $_POST['abs_days']   ?? [];
  $adv_apply  = $_POST['adv_apply']  ?? [];
  $ded_apply  = $_POST['ded_apply']  ?? [];

  $is_post = isset($_POST['post_run']) && $_POST['post_run']==='1';

  $conn->beginTransaction();
  try{
    if (!$emps) {
      throw new RuntimeException('No employees found for this company and payroll type.');
    }

    // Save draft and post use the same persisted payroll_items rows.
    $conn->prepare("DELETE FROM payroll_items WHERE payroll_run_id=?")->execute([$run_id]);

    $guardRows = [];
    $postedItemIds = []; // employee_id => payroll_item_id for settlement linking

    foreach($emps as $e){
      $eid   = (int)$e['id'];
      $b     = n($base[$eid]       ?? $e['base_pay']);
      $a     = n($allow[$eid]      ?? $e['allowance']);
      $otA   = n($ot_amt[$eid]     ?? ($otByEmp[$eid]['amount'] ?? 0));
      $bonp  = n($bonus_plus[$eid] ?? 0);
      $abs   = round((float)($abs_days[$eid] ?? ($absByEmp[$eid] ?? 0)), 2);
      if ($abs < 0) {
        $abs = 0.0;
      }
      $advA  = n($adv_apply[$eid]   ?? 0);
      $dedA  = n($ded_apply[$eid]   ?? 0);
      $advAvailable = max(0.0, ($advGiven[$eid] ?? 0.0) - ($advApplied[$eid] ?? 0.0));
      $dedAvailable = max(0.0, ($dedGiven[$eid] ?? 0.0) - ($dedApplied[$eid] ?? 0.0));

      if ($advA > $advAvailable + 0.005) {
        throw new RuntimeException($e['full_name'] . ' loan/advance deduction exceeds available balance.');
      }
      if ($dedA > $dedAvailable + 0.005) {
        throw new RuntimeException($e['full_name'] . ' other deduction exceeds available balance.');
      }

      $daily  = hr_schedule_salary_daily_rate($b + $a, $scheduleByEmp[$eid] ?? []);
      $absDed = $abs * $daily;

      $bonus = $otA + $bonp;
      $ded   = $advA + $dedA + $absDed;
      $net   = ($b + $a + $bonus) - $ded;

      $guardRows[] = [
        'name' => $e['full_name'],
        'base' => $b,
        'allowance' => $a,
        'bonus' => $bonus,
        'adv' => $advA,
        'ded' => $dedA,
        'abs' => $absDed,
        'net' => $net,
      ];

      $ins = $conn->prepare("
        INSERT INTO payroll_items
          (payroll_run_id, employee_id, base_pay, allowance, bonus, overtime, ot_hours, unpaid_leave_days, deductions, net_pay, notes, adv_applied, other_applied)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      $notes = [];
      if ($otA)   $notes[] = "OT ".number_format($otA,2);
      if ($bonp)  $notes[] = "Bonus+".number_format($bonp,2);
      if ($advA)  $notes[] = "Loan-".number_format($advA,2);
      if ($dedA)  $notes[] = "Ded-".number_format($dedA,2);
      if ($abs > 0.005) $notes[] = "Abs(".rtrim(rtrim(number_format($abs, 2, '.', ''), '0'), '.')." d)-".number_format($absDed,2);

      $ins->execute([
        $run_id,
        $eid,
        $b,
        $a,
        $bonus,
        $otA,
        (float)($otByEmp[$eid]['hours'] ?? 0),
        $abs,
        $ded,
        $net,
        implode(' | ',$notes),
        $advA,
        $dedA
      ]);
      $postedItemIds[$eid] = (int)$conn->lastInsertId();
    }

    // WPS / take-home guards — override only when authorized + reason provided.
    $failures = hr_wps_collect_payroll_validation_failures($guardRows, $payrollType);
    $wantOverride = isset($_POST['validation_override']) && (string)$_POST['validation_override'] === '1';
    $overrideReason = trim((string)($_POST['validation_override_reason'] ?? ''));
    $usedOverride = false;

    if ($failures) {
      if (!$wantOverride) {
        $messages = array_map(static fn(array $f): string => (string)$f['message'], $failures);
        $hint = hr_payroll_can_override_validation($conn)
            ? ' Check Override Validation, enter a reason, then try again.'
            : ' Ask an authorized Owner/Admin (or a role with Payroll Override permission) to override, or fix the deductions.';
        throw new RuntimeException(
            ($is_post ? 'Post blocked' : 'Save draft blocked')
            . ' by payroll validation. '
            . implode(' ', $messages)
            . $hint
        );
      }
      if (!hr_payroll_can_override_validation($conn)) {
        throw new RuntimeException('You are not authorized to override payroll validation.');
      }
      if (strlen($overrideReason) < 5) {
        throw new RuntimeException('Override reason is required (minimum 5 characters).');
      }
      if (strlen($overrideReason) > 2000) {
        throw new RuntimeException('Override reason is too long (maximum 2000 characters).');
      }
      hr_payroll_store_validation_override($conn, $run_id, $overrideReason, $failures, (int)current_user_id());
      $usedOverride = true;
      $run['validation_override'] = 1;
    } else {
      hr_payroll_clear_validation_override($conn, $run_id);
      $run['validation_override'] = 0;
    }

    if ($is_post){
      // Apply loan + deduction settlements against open balances (FIFO).
      foreach ($emps as $e) {
        $eid = (int)$e['id'];
        $advA = n($adv_apply[$eid] ?? 0);
        $dedA = n($ded_apply[$eid] ?? 0);
        $itemId = $postedItemIds[$eid] ?? null;
        $uid = current_user_id();

        if ($advA > 0.005 && hr_loans_schema_ready($conn)) {
          $left = $advA;
          $loanStmt = $conn->prepare("
            SELECT id FROM cash_advances
            WHERE employee_id = ? AND status = 'open'
              AND (request_status IS NULL OR request_status = 'approved')
              AND COALESCE(remaining_balance, amount) > 0
            ORDER BY tx_date ASC, id ASC
            FOR UPDATE
          ");
          $loanStmt->execute([$eid]);
          foreach ($loanStmt->fetchAll(PDO::FETCH_COLUMN) as $loanId) {
            if ($left <= 0.005) break;
            $rem = hr_loan_remaining($conn, (int)$loanId);
            $take = min($left, $rem);
            if ($take <= 0.005) continue;
            hr_loan_record_settlement($conn, (int)$loanId, $take, 'payroll', $to, $uid, $run_id, $itemId, 'Payroll run #' . $run_id);
            $left -= $take;
          }
        }

        if ($dedA > 0.005 && $hasDedRemaining) {
          $left = $dedA;
          $dedStmt = $conn->prepare("
            SELECT id FROM employee_deductions
            WHERE employee_id = ?
              AND COALESCE(settle_status, 'open') = 'open'
              AND COALESCE(remaining_balance, amount) > 0
            ORDER BY tx_date ASC, id ASC
            FOR UPDATE
          ");
          $dedStmt->execute([$eid]);
          foreach ($dedStmt->fetchAll(PDO::FETCH_COLUMN) as $dedId) {
            if ($left <= 0.005) break;
            $stRem = $conn->prepare("SELECT COALESCE(remaining_balance, amount) FROM employee_deductions WHERE id = ?");
            $stRem->execute([(int)$dedId]);
            $rem = (float)$stRem->fetchColumn();
            $take = min($left, $rem);
            if ($take <= 0.005) continue;
            hr_deduction_record_settlement($conn, (int)$dedId, $take, 'payroll', $to, $uid, $run_id, $itemId, 'Payroll run #' . $run_id);
            $left -= $take;
          }
        }
      }

      $conn->prepare("UPDATE payroll_runs SET status='finalized' WHERE id=?")->execute([$run_id]);
      $run['status'] = 'finalized';
      $journalId = hr_payroll_post_accounting($conn, $run, current_user_id());
      $conn->commit();
      try {
        require_once __DIR__ . '/../includes/AuditService.php';
        if ($usedOverride) {
          AuditService::logEvent([
            'action' => 'payroll_validation_override',
            'module' => 'hr',
            'company_id' => (int)$companyId,
            'object_type' => 'payroll_runs',
            'object_id' => (string)$run_id,
            'object_ref' => 'Payroll #' . $run_id,
            'summary' => 'Validation Override on post for payroll #' . $run_id
              . ' (' . $from . ' → ' . $to . ')',
            'new_data' => [
              'period_from' => $from,
              'period_to' => $to,
              'payroll_type' => $payrollType,
              'override_reason' => $overrideReason,
              'failed_validations' => $failures,
              'context' => 'post',
            ],
            'source' => 'user',
            'success' => true,
          ]);
        }
        AuditService::logEvent([
          'action' => 'payroll_posted',
          'module' => 'hr',
          'company_id' => isset($run['company_id']) ? (int)$run['company_id'] : current_company_id($conn),
          'object_type' => 'payroll_runs',
          'object_id' => (string)$run_id,
          'object_ref' => 'Payroll #' . $run_id,
          'summary' => 'Posted payroll run #' . $run_id . ' (journal #' . (int)$journalId . ')'
            . ($usedOverride ? ' [Validation Override]' : ''),
          'new_data' => [
            'journal_id' => (int)$journalId,
            'validation_override' => $usedOverride,
          ],
          'source' => 'user',
          'success' => true,
        ]);
      } catch (Throwable $ignored) {}
      $postMsg = "Run #$run_id posted with accounting journal #$journalId.";
      if ($usedOverride) {
        $postMsg .= ' Marked as Validation Override.';
      }
      header('Location: payroll_runs.php?msg='.urlencode($postMsg));
      exit;
    }

    // Mark run as draft and redirect so refresh keeps ?id= (PRG).
    $conn->prepare("UPDATE payroll_runs SET status='draft' WHERE id=? AND status IN ('open','draft')")->execute([$run_id]);
    $conn->commit();
    try {
      require_once __DIR__ . '/../includes/AuditService.php';
      if ($usedOverride) {
        AuditService::logEvent([
          'action' => 'payroll_validation_override',
          'module' => 'hr',
          'company_id' => (int)$companyId,
          'object_type' => 'payroll_runs',
          'object_id' => (string)$run_id,
          'object_ref' => 'Payroll #' . $run_id,
          'summary' => 'Validation Override on draft save for payroll #' . $run_id
            . ' (' . $from . ' → ' . $to . ')',
          'new_data' => [
            'period_from' => $from,
            'period_to' => $to,
            'payroll_type' => $payrollType,
            'override_reason' => $overrideReason,
            'failed_validations' => $failures,
            'context' => 'draft',
          ],
          'source' => 'user',
          'success' => true,
        ]);
      }
      AuditService::logEvent([
        'action' => 'payroll_draft_saved',
        'module' => 'hr',
        'company_id' => isset($run['company_id']) ? (int)$run['company_id'] : current_company_id($conn),
        'object_type' => 'payroll_runs',
        'object_id' => (string)$run_id,
        'object_ref' => 'Payroll #' . $run_id,
        'summary' => 'Saved payroll draft for run #' . $run_id
          . ($usedOverride ? ' [Validation Override]' : ''),
        'new_data' => ['validation_override' => $usedOverride],
        'source' => 'user',
        'success' => true,
      ]);
    } catch (Throwable $ignored) {}
    header('Location: payroll_run_build.php?id=' . (int)$run_id . '&saved=1' . ($usedOverride ? '&override=1' : ''));
    exit;
  }catch(Throwable $ex){
    if ($conn->inTransaction()) {
      $conn->rollBack();
    }
    if ($is_post) {
      try {
        hr_payroll_mark_accounting_result($conn, $run_id, null, $ex->getMessage());
      } catch (Throwable $ignore) {
        // Keep the original posting error visible to the user.
      }
    }
    $err = ($is_post ? 'Post' : 'Save draft') . ' failed: '.$ex->getMessage();
  }
}

/* ---------------- Existing draft/finalized payroll items ---------------- */
$savedItems = [];
$savedStmt = $conn->prepare("SELECT * FROM payroll_items WHERE payroll_run_id=?");
$savedStmt->execute([$run_id]);
foreach ($savedStmt->fetchAll(PDO::FETCH_ASSOC) as $savedRow) {
  $savedItems[(int)$savedRow['employee_id']] = $savedRow;
}

/* ---------------- Build view rows ---------------- */
$rows = [];
foreach ($emps as $e){
  $eid = (int)$e['id'];
  $saved = $savedItems[$eid] ?? null;
  $b   = $saved ? (float)$saved['base_pay'] : (float)$e['base_pay'];
  $a   = $saved ? (float)$saved['allowance'] : (float)$e['allowance'];

  $autoOT  = $otByEmp[$eid] ?? ['hours'=>0,'amount'=>0];
  $otAmount = $saved && $saved['overtime'] !== null ? (float)$saved['overtime'] : (float)$autoOT['amount'];
  $bonusTotal = $saved ? (float)$saved['bonus'] : $otAmount;
  $bonusPlus = max(0.0, $bonusTotal - $otAmount);
  $autoAbs = $saved && $saved['unpaid_leave_days'] !== null
    ? round((float)$saved['unpaid_leave_days'], 2)
    : round((float)($absByEmp[$eid] ?? 0), 2);
  $advApply = $saved ? (float)($saved['adv_applied'] ?? 0) : 0.0;
  $dedApply = $saved ? (float)($saved['other_applied'] ?? 0) : 0.0;

  // available balances as of end of period
  $adv_open = max(0.0, ($advGiven[$eid] ?? 0.0) - ($advApplied[$eid] ?? 0.0));
  $ded_open = max(0.0, ($dedGiven[$eid] ?? 0.0) - ($dedApplied[$eid] ?? 0.0));

  $scheduleSummary = $scheduleByEmp[$eid] ?? ['has_schedule'=>false,'expected_days'=>0,'expected_hours'=>0.0];
  $daily  = hr_schedule_salary_daily_rate($b + $a, $scheduleSummary);
  $absDed = $autoAbs * $daily;
  $bonus  = $otAmount + $bonusPlus;
  $ded    = $advApply + $dedApply + $absDed;
  $net    = ($b + $a + $bonus) - $ded;

  $rows[] = [
    'id'=>$eid, 'code'=>$e['employee_code'], 'name'=>$e['full_name'],
    'payment_type'=>$e['payment_type'] ?? 'wps',
    'base'=>$b, 'allow'=>$a,
    'ot_hours'=>(float)$autoOT['hours'], 'ot_amount'=>$otAmount,
    'bonus_plus'=>$bonusPlus,
    'abs_days'=>$autoAbs, 'abs_ded'=>$absDed,
    'daily_rate'=>$daily,
    'scheduled_days'=>(int)($scheduleSummary['expected_days'] ?? 0),
    'has_schedule'=>!empty($scheduleSummary['has_schedule']),
    'adv_open'=>$adv_open, 'ded_open'=>$ded_open,
    'adv_apply'=>$advApply, 'ded_apply'=>$dedApply,
    'net'=>$net,
  ];
}
$pageTitle = 'Build Payroll #' . (int)$run_id;
$pageStyles = '
  .payroll-build-wrap { max-width: 100%; }
  .payroll-meta .meta-chip {
    display: inline-flex; align-items: center; gap: .35rem;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 999px;
    padding: .35rem .75rem; font-size: .85rem; color: #495057;
  }
  .payroll-table-card { border-radius: 16px; box-shadow: 0 10px 30px #0001; border: 0; overflow: hidden; }
  .payroll-table-scroll {
    overflow: auto;
    max-height: calc(100vh - 280px);
    border-top: 1px solid #eef0f3;
  }
  .payroll-build-table {
    margin: 0;
    min-width: 1480px;
    border-collapse: separate;
    border-spacing: 0;
  }
  .payroll-build-table thead th {
    position: sticky;
    top: 0;
    z-index: 3;
    background: #f8f9fa;
    white-space: nowrap;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .02em;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    padding: .65rem .5rem;
    vertical-align: bottom;
  }
  .payroll-build-table thead th .small-note {
    display: block;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 500;
    color: #6c757d;
    font-size: .72rem;
    margin-top: .15rem;
  }
  .payroll-build-table tbody td {
    vertical-align: middle;
    padding: .55rem .45rem;
    font-size: .875rem;
    white-space: nowrap;
  }
  .payroll-build-table tbody tr:hover { background: #fafbfc; }
  .col-sticky-code, .col-sticky-name {
    position: sticky;
    background: #fff;
    z-index: 2;
  }
  .payroll-build-table thead .col-sticky-code,
  .payroll-build-table thead .col-sticky-name {
    background: #f8f9fa;
    z-index: 4;
  }
  .col-sticky-code { left: 0; min-width: 84px; max-width: 96px; }
  .col-sticky-name { left: 84px; min-width: 200px; max-width: 240px; box-shadow: 4px 0 8px -4px rgba(0,0,0,.08); }
  .payroll-build-table tbody tr:hover .col-sticky-code,
  .payroll-build-table tbody tr:hover .col-sticky-name { background: #fafbfc; }
  .emp-name {
    font-weight: 600;
    white-space: normal;
    line-height: 1.25;
    max-width: 220px;
  }
  .emp-sub { font-size: .72rem; color: #6c757d; margin-top: .1rem; white-space: normal; }
  .payroll-build-table .form-control-sm {
    min-width: 0;
    width: 100%;
    font-variant-numeric: tabular-nums;
    padding: .25rem .4rem;
  }
  .col-money { min-width: 96px; width: 96px; }
  .col-ot { min-width: 150px; width: 150px; }
  .col-apply { min-width: 118px; width: 118px; }
  .col-abs { min-width: 88px; width: 88px; }
  .col-pct { min-width: 92px; text-align: center; }
  .col-net { min-width: 100px; font-variant-numeric: tabular-nums; }
  .col-pay { min-width: 120px; }
  .balance-pill {
    display: inline-flex; align-items: center; gap: .2rem;
    border-radius: 999px; padding: .18rem .5rem;
    font-size: .72rem; font-weight: 700;
    background: #eef6ff; color: #0d6efd; white-space: nowrap;
  }
  .balance-pill.empty { background: #f1f3f5; color: #6c757d; }
  .td-net { font-weight: 700; color: #1b4332; }
  .footer-actions { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #eef0f3; z-index: 5; }
';
?>
<?php require_once __DIR__ . '/includes/hr_layout_header.php'; ?>

<?php
$buildActions = '<a class="btn btn-outline-secondary" href="payroll_runs.php">Back to runs</a>';
if (!$canEditRun) {
    $buildActions .= '<a class="btn btn-outline-primary" href="payroll_run_view.php?id=' . (int)$run_id . '">View posted</a>';
}
echo hr_ui_page_header(
    'Build ' . hr_payroll_run_type_label($payrollType) . ' #' . (int)$run_id,
    $from . ' → ' . $to . ' · ' . count($rows) . ' employee(s) · Status: ' . $run_status,
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Payroll', 'href' => $hrBase . '/payroll_runs'],
        ['label' => 'Build'],
    ],
    $buildActions
);
?>

  <div class="payroll-build-wrap">
    <div class="payroll-meta d-flex flex-wrap gap-2 mb-3">
      <?php if ($companyName !== ''): ?>
        <span class="meta-chip"><i class="bi bi-building"></i><?= htmlspecialchars($companyName) ?></span>
      <?php endif; ?>
      <span class="meta-chip"><i class="bi bi-calendar3"></i><?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></span>
      <span class="meta-chip"><i class="bi bi-people"></i><?= count($rows) ?> employee(s)</span>
      <span class="meta-chip">
        Status:
        <span class="badge text-bg-<?= $run_status === 'open' ? 'primary' : ($run_status === 'draft' ? 'warning' : 'secondary') ?>"><?= htmlspecialchars($run_status) ?></span>
      </span>
      <?php if ($runHasValidationOverride): ?>
        <span class="meta-chip">
          <span class="badge text-bg-danger">Validation Override</span>
        </span>
      <?php endif; ?>
    </div>

    <?php if ($err): ?>
      <div class="alert alert-danger" id="payrollServerError" role="alert">
        <strong>Action blocked.</strong>
        <div class="mt-1"><?= htmlspecialchars($err) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="alert alert-danger d-none" id="payrollClientError" role="alert"></div>

    <div class="alert alert-warning py-2">
      <strong>WPS / deduction guards:</strong>
      Each employee must keep ≥ <strong>85%</strong> take-home (deductions ≤ <strong>15%</strong> of earnings).
      <?php if ($payrollType === 'wps'): ?>
        WPS company rule: ≥ <strong>90%</strong> of employees in this run must have net &gt; 0.
      <?php endif; ?>
      Violations block Save Draft and Post<?= $canOverrideValidation ? ' unless an authorized Validation Override is confirmed' : '' ?>.
    </div>

    <?php if ($runHasValidationOverride): ?>
      <div class="alert alert-danger py-2">
        <strong>Validation Override</strong> is recorded on this run.
        <?php if (!empty($run['validation_override_reason'])): ?>
          <div class="small mt-1">Reason: <?= htmlspecialchars((string)$run['validation_override_reason']) ?></div>
        <?php endif; ?>
        <?php if (!empty($run['validation_override_at'])): ?>
          <div class="small text-muted">At <?= htmlspecialchars((string)$run['validation_override_at']) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$canEditRun): ?>
      <div class="alert alert-info">This payroll run is <strong><?= htmlspecialchars($run_status) ?></strong> and cannot be edited.</div>
    <?php endif; ?>

    <form method="post" action="payroll_run_build.php?id=<?= (int)$run_id ?>" id="payrollBuildForm">
      <?php csrf_field(); ?>
      <input type="hidden" name="run_id" value="<?= (int)$run_id ?>">
      <input type="hidden" name="validation_override" id="validationOverrideFlag" value="0">
      <input type="hidden" name="validation_override_reason" id="validationOverrideReason" value="">
      <div class="card payroll-table-card">
        <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2 py-3">
          <div>
            <strong>Preview / Adjust</strong>
            <div class="small text-muted">Scroll horizontally if needed. Employee name stays fixed on the left.</div>
          </div>
          <div class="small text-muted d-none d-md-block">Take-home % starts at 100% · red at/below 85%</div>
        </div>

        <div class="payroll-table-scroll">
          <table class="table table-hover payroll-build-table mb-0" id="payrollBuildTable">
            <thead>
              <tr>
                <th class="col-sticky-code">Code</th>
                <th class="col-sticky-name">Employee</th>
                <th class="col-pay">Payment</th>
                <th class="col-money">Base</th>
                <th class="col-money">Allow</th>
                <th class="col-ot">OT hrs / amt</th>
                <th class="col-money">Bonus+</th>
                <th class="col-apply">Loan / Adv<span class="small-note">balance → apply</span></th>
                <th class="col-apply">Other Ded.<span class="small-note">balance → apply</span></th>
                <th class="col-abs">Absence<span class="small-note">days · half=0.5</span></th>
                <th class="col-money">Abs Ded</th>
                <th class="col-pct">Take-home %<span class="small-note">from 100%</span></th>
                <th class="col-net">Net</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): $eid = $r['id']; ?>
              <tr data-scheduled-days="<?= !empty($r['has_schedule']) ? (int)$r['scheduled_days'] : 0 ?>">
                <td class="col-sticky-code text-muted"><?= htmlspecialchars($r['code']) ?></td>
                <td class="col-sticky-name">
                  <div class="emp-name" title="<?= htmlspecialchars($r['name']) ?>"><?= htmlspecialchars($r['name']) ?></div>
                  <div class="emp-sub">
                    Daily <?= number_format($r['daily_rate'], 2) ?>
                    <?php if (!empty($r['has_schedule'])): ?> · Sched <?= (int)$r['scheduled_days'] ?>d<?php endif; ?>
                    · OT <?= number_format($r['ot_hours'], 2) ?>h
                  </div>
                </td>
                <td class="col-pay"><span class="badge text-bg-light border"><?= htmlspecialchars(hr_payroll_payment_type_label($r['payment_type'])) ?></span></td>
                <td class="col-money"><input name="base[<?= $eid ?>]" value="<?= number_format($r['base'], 2, '.', '') ?>" class="form-control form-control-sm"></td>
                <td class="col-money"><input name="allow[<?= $eid ?>]" value="<?= number_format($r['allow'], 2, '.', '') ?>" class="form-control form-control-sm"></td>
                <td class="col-ot">
                  <div class="d-flex gap-1">
                    <input value="<?= number_format($r['ot_hours'], 2, '.', '') ?>" class="form-control form-control-sm" disabled title="OT hours (approved)" style="max-width:58px">
                    <input name="ot_amt[<?= $eid ?>]" value="<?= number_format($r['ot_amount'], 2, '.', '') ?>" class="form-control form-control-sm" title="OT amount">
                  </div>
                </td>
                <td class="col-money"><input name="bonus_plus[<?= $eid ?>]" value="<?= number_format($r['bonus_plus'], 2, '.', '') ?>" class="form-control form-control-sm"></td>
                <td class="col-apply">
                  <div class="balance-pill <?= $r['adv_open'] > 0 ? '' : 'empty' ?>">Bal <?= number_format($r['adv_open'], 2) ?></div>
                  <input name="adv_apply[<?= $eid ?>]" value="<?= number_format($r['adv_apply'], 2, '.', '') ?>" class="form-control form-control-sm mt-1" min="0" max="<?= number_format($r['adv_open'], 2, '.', '') ?>" placeholder="Apply">
                </td>
                <td class="col-apply">
                  <div class="balance-pill <?= $r['ded_open'] > 0 ? '' : 'empty' ?>">Bal <?= number_format($r['ded_open'], 2) ?></div>
                  <input name="ded_apply[<?= $eid ?>]" value="<?= number_format($r['ded_apply'], 2, '.', '') ?>" class="form-control form-control-sm mt-1" min="0" max="<?= number_format($r['ded_open'], 2, '.', '') ?>" placeholder="Apply">
                </td>
                <td class="col-abs"><input name="abs_days[<?= $eid ?>]" type="number" step="0.5" min="0" value="<?= number_format((float)$r['abs_days'], 2, '.', '') ?>" class="form-control form-control-sm" title="Full absent = 1; attendance half-day = 0.5"></td>
                <td class="col-money text-muted td-absded"><?= number_format($r['abs_ded'], 2) ?></td>
                <td class="col-pct td-dedpct"><span class="badge text-bg-secondary">100%</span></td>
                <td class="col-net td-net"><?= number_format($r['net'], 2) ?></td>
              </tr>
            <?php endforeach; if (!$rows): ?>
              <tr>
                <td colspan="13" class="text-center text-muted py-5">
                  No <?= htmlspecialchars(strtolower(hr_payroll_payment_type_label($payrollType))) ?> employees found for this company and period.
                </td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="card-footer footer-actions d-flex flex-wrap gap-2 align-items-center py-3">
          <?php if ($canEditRun): ?>
            <?php if ($canOverrideValidation): ?>
              <div class="form-check me-2">
                <input class="form-check-input" type="checkbox" id="validationOverrideChk" value="1">
                <label class="form-check-label" for="validationOverrideChk">
                  Override Validation
                  <span class="small text-muted d-block">Authorized users only — requires reason &amp; confirmation</span>
                </label>
              </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-outline-secondary" data-payroll-action="draft">Save Draft</button>
            <button type="submit" name="post_run" value="1" class="btn btn-success ms-auto" data-payroll-action="post" <?= !$rows ? 'disabled' : '' ?>>
              Post Run
            </button>
          <?php else: ?>
            <a class="btn btn-secondary" href="payroll_runs.php">Back</a>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <?php if ($canEditRun && $canOverrideValidation): ?>
    <div class="modal fade" id="payrollOverrideModal" tabindex="-1" aria-labelledby="payrollOverrideModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="payrollOverrideModalLabel">Confirm Validation Override</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-warning mb-3">
              You are about to save or post this payroll <strong>despite failing</strong> the take-home / WPS validation rules.
              This action is audited (user, company, period, failed rules, and reason).
            </div>
            <label for="payrollOverrideReasonInput" class="form-label">Override reason <span class="text-danger">*</span></label>
            <textarea class="form-control" id="payrollOverrideReasonInput" rows="3" maxlength="2000" required
              placeholder="Explain why this payroll must proceed despite validation failures"></textarea>
            <div class="form-text">Minimum 5 characters. This reason is stored on the payroll run and in audit history.</div>
            <div class="text-danger small mt-2 d-none" id="payrollOverrideReasonError">Please enter a reason (at least 5 characters).</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-danger" id="payrollOverrideConfirmBtn">Confirm Override &amp; Continue</button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="small text-muted mt-3">
      Loan/advance and fine balances are remaining amounts available to apply.
      Take-home % = net ÷ earnings (starts at 100%). Red at/below 85% take-home.
      Absence: attendance <strong>absent</strong> = 1 day, <strong>half</strong> = 0.5 day (two half-days = 1 day).
      Uses scheduled workdays when set; otherwise (basic + allowance) ÷ 30 × days.
    </div>
  </div>

<script>
(function () {
  const table = document.getElementById('payrollBuildTable');
  const form = document.getElementById('payrollBuildForm');
  const clientError = document.getElementById('payrollClientError');
  const minTakeHomePct = 85;
  const minPositiveNetRatio = 0.90;
  const payrollType = <?= json_encode($payrollType) ?>;
  const canOverride = <?= $canOverrideValidation ? 'true' : 'false' ?>;
  let pendingSubmitter = null;
  let overrideConfirmed = false;
  let allowNativeSubmit = false;

  function num(v) { v = (v ?? '').toString().trim(); return v === '' ? 0 : (parseFloat(v.replace(/,/g,'')) || 0); }
  function fmt2(n) { return (Math.round(n * 100) / 100).toFixed(2); }

  function rowAmounts(row) {
    const base      = num(row.querySelector('input[name^="base["]')?.value);
    const allow     = num(row.querySelector('input[name^="allow["]')?.value);
    const otAmt     = num(row.querySelector('input[name^="ot_amt["]')?.value);
    const bonusPlus = num(row.querySelector('input[name^="bonus_plus["]')?.value);
    const advApply  = num(row.querySelector('input[name^="adv_apply["]')?.value);
    const dedApply  = num(row.querySelector('input[name^="ded_apply["]')?.value);
    const absDays   = num(row.querySelector('input[name^="abs_days["]')?.value);
    const scheduledDays = num(row.dataset.scheduledDays || '0');
    const daily = scheduledDays > 0 ? ((base + allow) / scheduledDays) : ((base + allow) / 30.0);
    const absDed = absDays * daily;
    const bonus = otAmt + bonusPlus;
    const earnings = base + allow + bonus;
    const deductions = advApply + dedApply + absDed;
    const net = earnings - deductions;
    const takeHomePct = earnings > 0.005 ? (net / earnings) * 100 : (deductions > 0.005 ? 0 : 100);
    const name = (row.querySelector('.emp-name')?.textContent || 'Employee').trim();
    return { name, earnings, deductions, net, takeHomePct, absDed };
  }

  function recalcRow(row) {
    if (!row) return;
    const a = rowAmounts(row);
    const absCell = row.querySelector('.td-absded');
    const netCell = row.querySelector('.td-net');
    const pctCell = row.querySelector('.td-dedpct');
    if (absCell) absCell.textContent = fmt2(a.absDed);
    if (netCell) netCell.textContent = fmt2(a.net);
    if (pctCell) {
      let cls = 'text-bg-success';
      if (a.takeHomePct <= minTakeHomePct) cls = 'text-bg-danger';
      else if (a.takeHomePct <= 90) cls = 'text-bg-warning text-dark';
      else if (a.takeHomePct < 99.95) cls = 'text-bg-primary';
      else cls = 'text-bg-secondary';
      pctCell.innerHTML = '<span class="badge ' + cls + '" title="Net ' + fmt2(a.net) + ' of earnings ' + fmt2(a.earnings) + ' (deductions ' + fmt2(a.deductions) + ')">' + a.takeHomePct.toFixed(1) + '%</span>';
    }
  }

  function collectClientViolations() {
    const issues = [];
    let total = 0;
    let positive = 0;
    document.querySelectorAll('#payrollBuildTable tbody tr').forEach(function (row) {
      if (!row.querySelector('input[name^="base["]')) return;
      const a = rowAmounts(row);
      total++;
      if (a.net > 0.005) positive++;
      if (a.earnings > 0.005 && a.takeHomePct <= minTakeHomePct + 0.0001) {
        issues.push(a.name + ' take-home is ' + a.takeHomePct.toFixed(1) + '% (must be above 85%).');
      } else if (a.earnings <= 0.005 && a.deductions > 0.005) {
        issues.push(a.name + ': cannot apply deductions when run earnings are zero.');
      }
    });
    if (payrollType === 'wps' && total > 0) {
      const ratio = positive / total;
      if (ratio + 0.00001 < minPositiveNetRatio) {
        issues.push(
          'WPS company rule: only ' + positive + ' of ' + total +
          ' employees (' + (ratio * 100).toFixed(1) + '%) would receive net pay > 0 (minimum 90%).'
        );
      }
    }
    return issues;
  }

  function showClientError(html) {
    if (!clientError) {
      alert(html.replace(/<[^>]+>/g, ' '));
      return;
    }
    clientError.innerHTML = html;
    clientError.classList.remove('d-none');
    clientError.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function hideClientError() {
    if (!clientError) return;
    clientError.classList.add('d-none');
    clientError.innerHTML = '';
  }

  function blockForValidation(actionLabel) {
    const issues = collectClientViolations();
    if (!issues.length) return false;
    const overrideChk = document.getElementById('validationOverrideChk');
    const wantsOverride = canOverride && !!(overrideChk && overrideChk.checked);
    if (wantsOverride) return false;

    let html = '<strong>' + actionLabel + ' blocked by payroll validation.</strong>';
    html += '<ul class="mb-2 mt-2">';
    issues.slice(0, 8).forEach(function (msg) {
      html += '<li>' + msg + '</li>';
    });
    if (issues.length > 8) {
      html += '<li>…and ' + (issues.length - 8) + ' more.</li>';
    }
    html += '</ul>';
    if (canOverride) {
      html += 'To continue anyway, check <strong>Override Validation</strong>, enter a reason in the confirmation dialog, then try again.';
    } else {
      html += 'Reduce deductions so take-home stays above 85%, or ask an authorized Owner/Admin to use Override Validation.';
    }
    showClientError(html);
    if (canOverride && overrideChk) {
      overrideChk.focus();
      overrideChk.closest('.form-check')?.classList.add('border', 'border-danger', 'rounded', 'p-2');
    }
    return true;
  }

  table?.addEventListener('input', function (e) {
    const t = e.target;
    if (!(t instanceof HTMLInputElement)) return;
    hideClientError();
    recalcRow(t.closest('tr'));
  });

  document.querySelectorAll('#payrollBuildTable tbody tr').forEach(recalcRow);

  const serverErr = document.getElementById('payrollServerError');
  if (serverErr) {
    serverErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function isPostAction(submitter) {
    return !!(submitter && submitter.getAttribute('name') === 'post_run');
  }

  function submitFormNow(submitter) {
    if (!form) return;
    allowNativeSubmit = true;
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit(submitter || undefined);
      return;
    }
    if (submitter && submitter.name === 'post_run') {
      let hidden = form.querySelector('input[name="post_run"][data-temp="1"]');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'post_run';
        hidden.value = '1';
        hidden.dataset.temp = '1';
        form.appendChild(hidden);
      }
    }
    form.submit();
  }

  form?.addEventListener('submit', function (e) {
    const submitter = e.submitter || pendingSubmitter;
    const actionLabel = isPostAction(submitter) ? 'Post' : 'Save draft';
    const overrideChk = document.getElementById('validationOverrideChk');
    const wantsOverride = canOverride && !!(overrideChk && overrideChk.checked);

    if (allowNativeSubmit) {
      allowNativeSubmit = false;
      return;
    }

    // Block immediately with a clear message (before post confirm / reload).
    if (!wantsOverride && blockForValidation(actionLabel)) {
      e.preventDefault();
      delete form.dataset.postConfirmed;
      overrideConfirmed = false;
      return;
    }

    if (wantsOverride && !overrideConfirmed) {
      e.preventDefault();
      hideClientError();
      pendingSubmitter = submitter;
      const reasonErr = document.getElementById('payrollOverrideReasonError');
      if (reasonErr) reasonErr.classList.add('d-none');
      const modalEl = document.getElementById('payrollOverrideModal');
      if (modalEl && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      } else {
        const reason = window.prompt('Override reason (required):', '');
        if (!reason || reason.trim().length < 5) {
          showClientError('<strong>Override reason is required</strong> (minimum 5 characters).');
          return;
        }
        document.getElementById('validationOverrideFlag').value = '1';
        document.getElementById('validationOverrideReason').value = reason.trim();
        overrideConfirmed = true;
        if (isPostAction(submitter)) {
          if (!confirm('Post and lock this payroll run with Validation Override? You will not be able to edit afterwards.')) {
            overrideConfirmed = false;
            return;
          }
        }
        submitFormNow(submitter);
      }
      return;
    }

    if (!wantsOverride) {
      document.getElementById('validationOverrideFlag').value = '0';
      document.getElementById('validationOverrideReason').value = '';
    }

    if (isPostAction(submitter) && !form.dataset.postConfirmed) {
      e.preventDefault();
      if (!confirm('Post and lock this payroll run? You will not be able to edit afterwards.')) {
        overrideConfirmed = false;
        return;
      }
      form.dataset.postConfirmed = '1';
      submitFormNow(submitter);
    }
  });

  document.getElementById('payrollOverrideConfirmBtn')?.addEventListener('click', function () {
    const input = document.getElementById('payrollOverrideReasonInput');
    const reason = (input?.value || '').trim();
    const reasonErr = document.getElementById('payrollOverrideReasonError');
    if (reason.length < 5) {
      reasonErr?.classList.remove('d-none');
      input?.focus();
      return;
    }
    reasonErr?.classList.add('d-none');
    document.getElementById('validationOverrideFlag').value = '1';
    document.getElementById('validationOverrideReason').value = reason;
    overrideConfirmed = true;
    const modalEl = document.getElementById('payrollOverrideModal');
    if (modalEl && window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }
    const submitter = pendingSubmitter;
    if (isPostAction(submitter)) {
      if (!confirm('Post and lock this payroll run with Validation Override? You will not be able to edit afterwards.')) {
        overrideConfirmed = false;
        return;
      }
    }
    submitFormNow(submitter);
  });

  document.getElementById('validationOverrideChk')?.addEventListener('change', function () {
    hideClientError();
    this.closest('.form-check')?.classList.remove('border', 'border-danger', 'rounded', 'p-2');
  });
})();
</script>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
