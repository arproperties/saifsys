<?php
// hr/payroll_run_view.php


require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/company_helper.php';
require_once __DIR__.'/includes/hr_payroll_company_access.php';
require_once __DIR__.'/includes/hr_payroll_accounting.php';
require_once __DIR__.'/includes/hr_wps_guards.php';
require_role(['Owner','Admin','HR'], $conn);

$run_id = (int)($_GET['id'] ?? 0);
if (!$run_id) { http_response_code(400); exit('Missing run id'); }

// Load run
$st = $conn->prepare("
  SELECT pr.*, c.name AS company_name
  FROM payroll_runs pr
  LEFT JOIN companies c ON c.id = pr.company_id
  WHERE pr.id = ?
");
$st->execute([$run_id]);
$run = $st->fetch(PDO::FETCH_ASSOC);
if (!$run) { http_response_code(404); exit('Run not found'); }

hr_payroll_require_run_access($conn, $run);

$from = $run['period_from'];
$to   = $run['period_to'];
$status = $run['status'];
$companyLabel = trim((string)($run['company_name'] ?? ''));
$payrollType = (($run['payroll_type'] ?? 'wps') === 'cash') ? 'cash' : 'wps';
$hasValidationOverride = hr_payroll_validation_override_schema_ready($conn)
    && !empty($run['validation_override']);
$overrideByName = '';
if ($hasValidationOverride && !empty($run['validation_override_by'])) {
    try {
        $u = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(name), ''), username) AS label FROM `user` WHERE id = ? LIMIT 1");
        $u->execute([(int)$run['validation_override_by']]);
        $overrideByName = trim((string)($u->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $overrideByName = '';
    }
}
$overrideFailures = [];
if ($hasValidationOverride && !empty($run['validation_override_failures'])) {
    $decoded = json_decode((string)$run['validation_override_failures'], true);
    if (is_array($decoded) && !empty($decoded['failures']) && is_array($decoded['failures'])) {
        $overrideFailures = $decoded['failures'];
    }
}

// Items
$rows = $conn->prepare("
  SELECT pi.*, e.employee_code, e.full_name
  FROM payroll_items pi
  JOIN employees e ON e.id=pi.employee_id
  WHERE pi.payroll_run_id=?
  ORDER BY e.full_name
");
$rows->execute([$run_id]);
$items = $rows->fetchAll(PDO::FETCH_ASSOC);

// Totals
$tot = ['base'=>0,'allow'=>0,'bonus'=>0,'ded'=>0,'net'=>0,'adv'=>0,'oth'=>0];
foreach ($items as $r){
  $tot['base']  += (float)$r['base_pay'];
  $tot['allow'] += (float)$r['allowance'];
  $tot['bonus'] += (float)$r['bonus'];
  $tot['ded']   += (float)$r['deductions'];
  $tot['net']   += (float)$r['net_pay'];
  $tot['adv']   += (float)($r['adv_applied'] ?? 0);
  $tot['oth']   += (float)($r['other_applied'] ?? 0);
}

$pageTitle = 'Payroll Run #' . $run_id;
$pageStyles = '.table td,.table th{vertical-align:middle} @media print{.no-print{display:none!important}.hr-settings-card{box-shadow:none;border:0}}';
require_once __DIR__ . '/includes/hr_layout_header.php';

$metaParts = [];
if ($companyLabel !== '') {
    $metaParts[] = '<strong>' . htmlspecialchars($companyLabel) . '</strong>';
}
$metaParts[] = htmlspecialchars(hr_payroll_run_type_label($payrollType));
$metaParts[] = 'Period: <strong>' . htmlspecialchars($from) . '</strong> → <strong>' . htmlspecialchars($to) . '</strong>';
$metaParts[] = 'Status: ' . hr_ui_status_pill($status);
if ($hasValidationOverride) {
    $metaParts[] = '<span class="badge text-bg-danger">Validation Override</span>';
}
if (!empty($run['accounting_journal_id'])) {
    $metaParts[] = (($run['accounting_system'] ?? '') === 'shared' ? 'Shared accounting' : 'Standalone accounting')
        . ' journal #' . (int)$run['accounting_journal_id'];
} elseif (!empty($run['accounting_error'])) {
    $metaParts[] = '<span class="text-danger">Accounting: ' . htmlspecialchars($run['accounting_error']) . '</span>';
}
$runDesc = implode(' · ', $metaParts);

$runActions = '<a class="btn btn-outline-secondary" href="payroll_runs.php">Back</a>';
if (in_array($status, ['open', 'draft'], true)) {
    $runActions .= ' <a class="btn btn-warning" href="payroll_run_build.php?id=' . (int)$run_id . '">Build / Post</a>';
}
if ($items) {
    $runActions .= ' <a class="btn btn-outline-secondary" href="payroll_run_export_csv.php?id=' . (int)$run_id . '">Export CSV</a>';
    $runActions .= ' <a class="btn btn-outline-secondary" href="payroll_run_slips_zip.php?id=' . (int)$run_id . '">Download All Payslips (ZIP)</a>';
    $runActions .= ' <button type="button" class="btn btn-outline-secondary no-print" onclick="print()">Print</button>';
}
echo hr_ui_page_header(
    $pageTitle,
    $runDesc,
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Payroll Runs', 'href' => 'payroll_runs.php'],
        ['label' => 'Run #' . $run_id],
    ],
    $runActions
);
?>

  <?php if ($hasValidationOverride): ?>
  <div class="alert alert-danger mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <span class="badge text-bg-danger">Validation Override</span>
      <strong>This payroll was saved/posted despite failing WPS / take-home validation.</strong>
    </div>
    <?php if (!empty($run['validation_override_reason'])): ?>
      <div><span class="text-muted">Reason:</span> <?= htmlspecialchars((string)$run['validation_override_reason']) ?></div>
    <?php endif; ?>
    <div class="small text-muted mt-1">
      <?php if ($overrideByName !== ''): ?>
        By <?= htmlspecialchars($overrideByName) ?>
        (user #<?= (int)$run['validation_override_by'] ?>)
      <?php elseif (!empty($run['validation_override_by'])): ?>
        User #<?= (int)$run['validation_override_by'] ?>
      <?php endif; ?>
      <?php if (!empty($run['validation_override_at'])): ?>
        · <?= htmlspecialchars((string)$run['validation_override_at']) ?>
      <?php endif; ?>
      · Period <?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?>
    </div>
    <?php if ($overrideFailures): ?>
      <div class="small mt-2">
        <div class="text-muted mb-1">Failed validation(s):</div>
        <ul class="mb-0 ps-3">
          <?php foreach ($overrideFailures as $f): ?>
            <li><?= htmlspecialchars((string)($f['message'] ?? $f['code'] ?? 'Validation failed')) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="hr-settings-card mb-3">
    <div class="settings-header">Summary</div>
    <div class="card-body">
      <?php if (!$items): ?>
        <div class="text-muted">No items found for this run.</div>
      <?php else: ?>
        <div class="row text-center">
          <div class="col"><div class="small text-muted">Base</div><div class="fs-5 fw-semibold"><?= number_format($tot['base'],2) ?></div></div>
          <div class="col"><div class="small text-muted">Allowance</div><div class="fs-5 fw-semibold"><?= number_format($tot['allow'],2) ?></div></div>
          <div class="col"><div class="small text-muted">Bonus / OT</div><div class="fs-5 fw-semibold"><?= number_format($tot['bonus'],2) ?></div></div>
          <div class="col"><div class="small text-muted">Adv Applied</div><div class="fs-5 fw-semibold"><?= number_format($tot['adv'],2) ?></div></div>
          <div class="col"><div class="small text-muted">Other Ded.</div><div class="fs-5 fw-semibold"><?= number_format($tot['oth'],2) ?></div></div>
          <div class="col"><div class="small text-muted">Total Deductions</div><div class="fs-5 fw-semibold"><?= number_format($tot['ded'],2) ?></div></div>
          <div class="col"><div class="small text-muted">Net</div><div class="fs-5 fw-bold"><?= number_format($tot['net'],2) ?></div></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header">Items</div>
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-striped mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Code</th>
            <th>Name</th>
            <th>Base</th>
            <th>Allow</th>
            <th>Bonus / OT</th>
            <th>Deductions</th>
            <th>Net</th>
            <th>Payslip</th>
          </tr>
        </thead>
        <tbody>
          <?php $i=1; foreach($items as $r): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td class="text-muted"><?= htmlspecialchars($r['employee_code']) ?></td>
              <td><?= htmlspecialchars($r['full_name']) ?></td>
              <td><?= number_format($r['base_pay'],2) ?></td>
              <td><?= number_format($r['allowance'],2) ?></td>
              <td><?= number_format($r['bonus'],2) ?></td>
              <td>
                <?= number_format($r['deductions'],2) ?>
                <div class="small text-muted">
                  <?php
                    $bits=[];
                    $a=(float)($r['adv_applied']??0); if($a) $bits[]="Adv ".$a;
                    $o=(float)($r['other_applied']??0); if($o) $bits[]="Other ".$o;
                    echo $bits?implode(' | ',$bits):'';
                  ?>
                </div>
              </td>
              <td class="fw-semibold"><?= number_format($r['net_pay'],2) ?></td>
              <td><a class="btn btn-sm btn-outline-primary" target="_blank" href="payslip.php?run_id=<?= (int)$run_id ?>&employee_id=<?= (int)$r['employee_id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; if(!$items): ?>
            <tr><td colspan="9" class="text-center text-muted">—</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
