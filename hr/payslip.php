<?php
// hr/payslip.php

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../lib/Guard.php';
require_once __DIR__.'/includes/hr_payroll_company_access.php';
require_once __DIR__.'/includes/hr_schedule_helper.php';
require_once __DIR__.'/includes/hr_payroll_accounting.php';

$roles = current_user_roles($conn);
$isWorkerSelfService = Guard::isWorker($roles);

if ($isWorkerSelfService) {
    $selfEmployeeId = Guard::currentEmployeeId($conn);
    if (!$selfEmployeeId) {
        http_response_code(500);
        exit('Employee record not linked. Contact administrator.');
    }
    $requestedEmployeeId = (int)($_GET['employee_id'] ?? 0);
    if ($requestedEmployeeId && $requestedEmployeeId !== $selfEmployeeId) {
        require_once __DIR__ . '/../includes/AuditService.php';
        AuditService::log([
            'action' => 'worker_payslip_blocked',
            'object_type' => 'payslip',
            'object_id' => $requestedEmployeeId,
            'summary' => "Worker attempted to view another employee's payslip",
            'new_data' => ['employee_id' => $selfEmployeeId],
            'success' => false,
        ]);
        http_response_code(403);
        exit('Forbidden');
    }
    $_GET['employee_id'] = $selfEmployeeId;
} else {
    require_role(['Owner','Admin','HR'], $conn);
}

/*
  Shows one employee’s payslip for a payroll run.
  Viewable when run status is: open, finalized, or paid.
*/

$run_id = (int)($_GET['run_id'] ?? 0);
$emp_id = (int)($_GET['employee_id'] ?? 0);
if (!$run_id || !$emp_id) { http_response_code(400); exit('Missing run_id or employee_id.'); }

/* Run + item + employee */
$stmt = $conn->prepare("
  SELECT
    pr.id                AS run_id,
    pr.company_id        AS run_company_id,
    pr.period_from,
    pr.period_to,
    pr.payroll_type,
    pr.status            AS run_status,
    e.id                 AS employee_id,
    e.employee_code,
    e.full_name,
    COALESCE(pi.base_pay,0)    AS base_pay,
    COALESCE(pi.allowance,0)   AS allowance,
    COALESCE(pi.bonus,0)       AS bonus,
    COALESCE(pi.deductions,0)  AS deductions,
    COALESCE(pi.net_pay,0)     AS net_pay,
    COALESCE(pi.adv_applied,0) AS adv_applied,     -- amount of cash advance taken this run
    COALESCE(pi.other_applied,0) AS other_applied, -- other deductions (fines/charges) this run
    pi.notes
  FROM payroll_runs pr
  JOIN payroll_items pi ON pi.payroll_run_id = pr.id
  JOIN employees     e  ON e.id = pi.employee_id
  WHERE pr.id = ?
    AND pi.employee_id = ?
    AND pr.status IN ('open','finalized','paid')
    AND (pr.company_id IS NULL OR pr.company_id = 0 OR e.company_id = pr.company_id)
  LIMIT 1
");
$stmt->execute([$run_id, $emp_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  http_response_code(404);
  exit('Run not found, not eligible, or payslip item missing.');
}

if (!$isWorkerSelfService) {
  hr_payroll_require_run_access($conn, ['company_id' => (int)($row['run_company_id'] ?? 1)]);
}

/* Recompute absence for a clear breakdown */
$absStmt = $conn->prepare("
  SELECT work_date
  FROM attendance
  WHERE employee_id = ?
    AND work_date BETWEEN ? AND ?
    AND status = 'absent'
");
$absStmt->execute([$row['employee_id'], $row['period_from'], $row['period_to']]);
$scheduleSummary = hr_schedule_summary($conn, (int)$row['employee_id'], $row['period_from'], $row['period_to']);
$absent_days = 0;
foreach ($absStmt->fetchAll(PDO::FETCH_ASSOC) as $absenceRow) {
  $absenceDate = (string)($absenceRow['work_date'] ?? '');
  if (!empty($scheduleSummary['has_schedule']) && empty($scheduleSummary['days'][$absenceDate]['is_workday'])) {
    continue;
  }
  $absent_days++;
}

/* Calculations */
$base    = (float)$row['base_pay'];
$allow   = (float)$row['allowance'];
$bonus   = (float)$row['bonus'];          // includes OT + manual bonus
$dedTot  = (float)$row['deductions'];
$net     = (float)$row['net_pay'];

$daily_rate   = hr_schedule_salary_daily_rate($base + $allow, $scheduleSummary);
$abs_ded      = round($absent_days * $daily_rate, 2);
$adv_applied  = (float)$row['adv_applied'];
$other_applied= (float)$row['other_applied'];

/* If there’s any tiny math drift, put it under “Adjustment” */
$ded_known  = round($adv_applied + $other_applied + $abs_ded, 2);
$ded_adjust = round($dedTot - $ded_known, 2);
if (abs($ded_adjust) < 0.01) $ded_adjust = 0.00;

$period = $row['period_from'] . ' → ' . $row['period_to'];

function nf($n){ return number_format((float)$n, 2); }

/* Badge color */
$badgeClass = $row['run_status']==='open' ? 'warning text-dark' : ($row['run_status']==='finalized' ? 'primary' : 'success');

$pageTitle = 'Payslip — ' . $row['full_name'];
$pageStyles = <<<'CSS'
:root{--card-radius:18px}
.sheet{
  max-width:900px;margin:0 auto;
  background:#fff;border-radius:var(--card-radius);
  box-shadow:0 18px 45px rgba(22,29,37,.08);
  overflow:hidden;
}
.sheet .header{
  padding:22px 28px;
  background:linear-gradient(135deg,#f8fbff,#eef3ff);
  border-bottom:1px solid #eef2f7;
}
.sheet .body{padding:24px 28px}
.kpi{font-size:28px;font-weight:700}
.muted{color:#6b7280}
.pill{
  display:inline-block;padding:.25rem .6rem;border-radius:999px;font-size:.75rem;
  background:#eef2ff;color:#334155;border:1px solid #e5e7eb;
}
.tile{
  border:1px solid #eef2f7;border-radius:14px;padding:16px;background:#fff;
}
.tile h6{font-size:.95rem;margin:0 0 6px;color:#111827}
.tile .line{display:flex;justify-content:space-between;padding:6px 0}
.tile .line + .line{border-top:1px dashed #e5e7eb}
.total-row{
  background:#0ea5e9;color:#fff;border-radius:14px;padding:14px 16px;font-weight:700;
}
@media print{
  .hr-sidebar,.hr-topbar,.hr-unsaved-bar{display:none!important}
  .hr-shell{display:block}
  .hr-content{padding:0}
  body{background:#fff}
  .no-print{display:none!important}
  .sheet{box-shadow:none;border:0;margin:0}
}
CSS;
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Payslip',
    htmlspecialchars($row['full_name']) . ' · ' . htmlspecialchars($period),
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Payroll', 'href' => 'payroll_runs.php'],
        ['label' => 'Payslip'],
    ],
    '<button type="button" class="btn btn-sm btn-outline-secondary no-print" onclick="print()">Print</button>'
);
?>

<div class="sheet">
  <div class="header d-flex align-items-start gap-3">
    <div class="flex-grow-1">
      <div class="d-flex align-items-center gap-2">
        <h5 class="mb-0">Payslip</h5>
        <span class="pill">Run: <strong><?= htmlspecialchars($row['run_status']) ?></strong></span>
      </div>
      <div class="muted small mt-1">Period: <strong><?= htmlspecialchars($period) ?></strong></div>
      <div class="muted small">Payroll Type: <strong><?= htmlspecialchars(hr_payroll_run_type_label($row['payroll_type'] ?? 'wps')) ?></strong></div>
      <div class="mt-2">
        <strong><?= htmlspecialchars($row['full_name']) ?></strong>
        <span class="muted"> (<?= htmlspecialchars($row['employee_code']) ?>)</span>
      </div>
    </div>
  </div>

  <div class="body">
    <div class="row g-3">
      <!-- Earnings -->
      <div class="col-md-6">
        <div class="tile">
          <h6>Earnings</h6>
          <div class="line"><span>Basic</span><span><?= nf($base) ?></span></div>
          <div class="line"><span>Allowance</span><span><?= nf($allow) ?></span></div>
          <div class="line"><span>Bonus / OT</span><span><?= nf($bonus) ?></span></div>
          <div class="total-row d-flex justify-content-between mt-2">
            <span>Total Earnings</span>
            <span><?= nf($base + $allow + $bonus) ?></span>
          </div>
        </div>
      </div>

      <!-- Deductions -->
      <div class="col-md-6">
        <div class="tile">
          <h6>Deductions</h6>
          <div class="line"><span>Cash advance (applied)</span><span><?= nf($adv_applied) ?></span></div>
          <div class="line">
            <span>Absence
              <?php if ($absent_days>0): ?>
                <span class="muted">(<?= (int)$absent_days ?> day<?= $absent_days==1?'':'s' ?> × <?= nf($daily_rate) ?>)</span>
              <?php endif; ?>
            </span>
            <span><?= nf($abs_ded) ?></span>
          </div>
          <div class="line"><span>Other deductions</span><span><?= nf($other_applied) ?></span></div>
          <?php if ($ded_adjust != 0.00): ?>
            <div class="line"><span>Adjustment</span><span><?= nf($ded_adjust) ?></span></div>
          <?php endif; ?>
          <div class="total-row d-flex justify-content-between mt-2">
            <span>Total Deductions</span>
            <span><?= nf($dedTot) ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Net -->
    <div class="mt-3 tile">
      <div class="d-flex justify-content-between align-items-center">
        <div class="muted">Net Pay</div>
        <div class="kpi"><?= nf($net) ?></div>
      </div>
    </div>

    <?php if (!empty($row['notes'])): ?>
      <div class="mt-3 muted small">
        Notes: <?= htmlspecialchars($row['notes']) ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
