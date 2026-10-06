<?php
// hr/payroll_run_view.php


require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/company_helper.php';
require_once __DIR__.'/../includes/audit_bridge.php';
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

// A posted run is the "latest" while no later run exists for the company and
// payroll type. Only there can a bonus added afterwards still be attached.
$runEditable = in_array($status, ['open', 'draft'], true);
$isLatestRun = false;
try {
  $later = $conn->prepare("
    SELECT COUNT(*) FROM payroll_runs
    WHERE company_id = ? AND COALESCE(payroll_type, 'wps') = ? AND period_from > ?
  ");
  $later->execute([(int)($run['company_id'] ?? 0), $payrollType, $to]);
  $isLatestRun = !(int)$later->fetchColumn();
} catch (Throwable $e) {
  $isLatestRun = false;
}
// Cash only: a WPS run's bank file already went out with the old net pay.
$canAddPendingBonus = !$runEditable && $isLatestRun && $payrollType === 'cash'
    && !empty($run['accounting_journal_id']);

$viewMsg = (string)($_SESSION['payroll_view_msg'] ?? '');
$viewErr = (string)($_SESSION['payroll_view_err'] ?? '');
unset($_SESSION['payroll_view_msg'], $_SESSION['payroll_view_err']);

// Add the waiting profile bonuses to this posted run: the saved items grow by
// the bonus, the bonuses are marked paid here, and the added amount gets its
// own journal. The run's original journal is not touched.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_pending_bonus'])) {
  csrf_verify();
  if (!$canAddPendingBonus) {
    $_SESSION['payroll_view_err'] = 'A bonus can only be added to the latest posted Cash run.';
  } else {
    // From a row's "+ ... next run" button: that employee only. From the note's
    // button: everyone waiting.
    $onlyEmployeeId = (int)($_POST['bonus_employee_id'] ?? 0);
    $conn->beginTransaction();
    try {
      $pend = $conn->prepare("
        SELECT eb.id, eb.employee_id, eb.amount, pi.id AS item_id, pi.notes
        FROM employee_bonuses eb
        INNER JOIN employees e ON e.id = eb.employee_id AND e.company_id = ?
        INNER JOIN payroll_items pi ON pi.payroll_run_id = ? AND pi.employee_id = eb.employee_id
        WHERE eb.paid_run_id IS NULL AND COALESCE(e.payment_type, 'wps') = ?
          " . ($onlyEmployeeId > 0 ? "AND eb.employee_id = ?" : "") . "
        ORDER BY eb.id
        FOR UPDATE
      ");
      $pendParams = [(int)$run['company_id'], $run_id, $payrollType];
      if ($onlyEmployeeId > 0) {
        $pendParams[] = $onlyEmployeeId;
      }
      $pend->execute($pendParams);
      $byItem = [];
      $bonusIds = [];
      foreach ($pend->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $itemId = (int)$p['item_id'];
        $byItem[$itemId]['amount'] = round(($byItem[$itemId]['amount'] ?? 0) + (float)$p['amount'], 2);
        $byItem[$itemId]['notes'] = (string)($p['notes'] ?? '');
        $byItem[$itemId]['employee_id'] = (int)$p['employee_id'];
        $bonusIds[] = (int)$p['id'];
      }
      if (!$bonusIds) {
        throw new RuntimeException('No waiting bonus was found for the employees in this run.');
      }
      $addTotal = 0.0;
      $updItem = $conn->prepare("
        UPDATE payroll_items
        SET bonus = bonus + ?, profile_bonus = profile_bonus + ?, net_pay = net_pay + ?, notes = ?
        WHERE id = ?
      ");
      foreach ($byItem as $itemId => $add) {
        $note = 'Bonus ' . number_format($add['amount'], 2) . ' (added after posting)';
        $notes = $add['notes'] !== '' ? $add['notes'] . ' | ' . $note : $note;
        $updItem->execute([$add['amount'], $add['amount'], $add['amount'], mb_substr($notes, 0, 255), $itemId]);
        $addTotal += $add['amount'];
      }
      $addTotal = round($addTotal, 2);
      $conn->prepare("UPDATE employee_bonuses SET paid_run_id = ? WHERE paid_run_id IS NULL AND id IN ("
          . implode(',', array_fill(0, count($bonusIds), '?')) . ")")
        ->execute(array_merge([$run_id], $bonusIds));
      $bonusJournalId = hr_payroll_post_bonus_adjustment($conn, $run, $addTotal, $bonusIds[0], current_user_id());
      $conn->commit();
      $_SESSION['payroll_view_msg'] = 'Bonus ' . number_format($addTotal, 2) . ' added to this run. Accounting journal #' . $bonusJournalId . ' posted.';
      audit_bridge_hr_ops(
        'payroll_bonus_added_after_post',
        'payroll_runs',
        $run_id,
        'Added bonus AED ' . number_format($addTotal, 2) . ' to posted payroll run #' . $run_id,
        (int)$run['company_id'],
        [
          'amount' => $addTotal,
          'bonus_ids' => $bonusIds,
          'employee_ids' => array_values(array_column($byItem, 'employee_id')),
          'journal_id' => $bonusJournalId,
        ],
        'Run #' . $run_id,
        current_user_id()
      );
    } catch (Throwable $ex) {
      if ($conn->inTransaction()) {
        $conn->rollBack();
      }
      error_log('add_pending_bonus failed: ' . $ex->getMessage());
      $_SESSION['payroll_view_err'] = 'Could not add the bonus: ' . $ex->getMessage();
    }
  }
  header('Location: payroll_run_view.php?id=' . (int)$run_id);
  exit;
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

// Unpaid profile bonuses (employee Bonus tab) dated up to the end of this period.
// They only reach payroll_items when Build / Post saves, so while the run is
// editable list the ones it does not hold: not saved yet, or the employee is
// paid through the other payroll type. A posted run needs no notice; whatever
// it did not pay carries into the next run.
$bonusNotSaved = [];
$bonusOtherType = [];
if ($runEditable) try {
  $savedProfileBonus = [];
  foreach ($items as $r) {
    $savedProfileBonus[(int)$r['employee_id']] = (float)($r['profile_bonus'] ?? 0);
  }
  $bq = $conn->prepare("
    SELECT e.id, e.employee_code, e.full_name, COALESCE(e.payment_type, 'wps') AS payment_type,
           COALESCE(SUM(eb.amount),0) AS amt
    FROM employee_bonuses eb
    INNER JOIN employees e ON e.id = eb.employee_id AND e.company_id = ?
    WHERE eb.paid_run_id IS NULL AND eb.bonus_date <= ?
    GROUP BY e.id, e.employee_code, e.full_name, e.payment_type
    ORDER BY e.full_name
  ");
  $bq->execute([(int)($run['company_id'] ?? 0), $to]);
  $liveBonusEmp = [];
  foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $b) {
    $b['amt'] = round((float)$b['amt'], 2);
    $liveBonusEmp[(int)$b['id']] = true;
    if ($b['payment_type'] !== $payrollType) {
      $bonusOtherType[] = $b;
    } elseif (abs($b['amt'] - ($savedProfileBonus[(int)$b['id']] ?? 0)) > 0.005) {
      $bonusNotSaved[] = $b;
    }
  }
  // Saved with a bonus that has since been deleted or paid by another run.
  foreach ($items as $r) {
    if ((float)($r['profile_bonus'] ?? 0) > 0.005 && empty($liveBonusEmp[(int)$r['employee_id']])) {
      $bonusNotSaved[] = ['id' => (int)$r['employee_id'], 'employee_code' => $r['employee_code'], 'full_name' => $r['full_name'], 'payment_type' => $payrollType, 'amt' => 0.0];
    }
  }
} catch (Throwable $e) {
  // employee_bonuses is not there until migrations/hr_employee_bonuses.sql has run.
  $bonusNotSaved = [];
  $bonusOtherType = [];
}

// On the latest posted run, show the unpaid bonuses still waiting for the next
// run. The posted amounts stay as they are; this is only a heads-up.
$bonusPending = [];
if (!$runEditable && $isLatestRun) try {
  $bq = $conn->prepare("
    SELECT e.id, e.employee_code, e.full_name, COALESCE(SUM(eb.amount),0) AS amt
    FROM employee_bonuses eb
    INNER JOIN employees e ON e.id = eb.employee_id AND e.company_id = ?
    WHERE eb.paid_run_id IS NULL AND COALESCE(e.payment_type, 'wps') = ?
    GROUP BY e.id, e.employee_code, e.full_name
    ORDER BY e.full_name
  ");
  $bq->execute([(int)($run['company_id'] ?? 0), $payrollType]);
  $bonusPending = $bq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $bonusPending = [];
}
$bonusPendingByEmp = [];
foreach ($bonusPending as $b) {
  $bonusPendingByEmp[(int)$b['id']] = (float)$b['amt'];
}
// The button only helps employees who have a row in this run.
$bonusAddable = false;
if ($canAddPendingBonus) {
  foreach ($items as $r) {
    if (!empty($bonusPendingByEmp[(int)$r['employee_id']])) { $bonusAddable = true; break; }
  }
}

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

  <?php if ($bonusNotSaved || $bonusOtherType): ?>
  <div class="alert alert-warning mb-3 no-print">
    <?php if ($bonusNotSaved): ?>
      <div class="fw-semibold">
        Bonus added or changed since this run was saved — open Build / Post and save to update it:
      </div>
      <ul class="mb-0 ps-3">
        <?php foreach ($bonusNotSaved as $b): ?>
          <li><?= htmlspecialchars($b['full_name']) ?> <span class="text-muted">(<?= htmlspecialchars((string)$b['employee_code']) ?>)</span> — <?= number_format($b['amt'], 2) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($bonusOtherType): ?>
      <div class="fw-semibold<?= $bonusNotSaved ? ' mt-2' : '' ?>">Bonus for employees paid through the other payroll type — not part of this <?= htmlspecialchars(hr_payroll_run_type_label($payrollType)) ?> run:</div>
      <ul class="mb-0 ps-3">
        <?php foreach ($bonusOtherType as $b): ?>
          <li><?= htmlspecialchars($b['full_name']) ?> <span class="text-muted">(<?= htmlspecialchars((string)$b['employee_code']) ?>)</span> — <?= number_format($b['amt'], 2) ?>, paid in the <?= htmlspecialchars(hr_payroll_run_type_label($b['payment_type'] === 'cash' ? 'cash' : 'wps')) ?> run</li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($viewMsg !== ''): ?><div class="alert alert-success mb-3 no-print"><?= htmlspecialchars($viewMsg) ?></div><?php endif; ?>
  <?php if ($viewErr !== ''): ?><div class="alert alert-danger mb-3 no-print"><?= htmlspecialchars($viewErr) ?></div><?php endif; ?>

  <?php if ($bonusPending): ?>
  <div class="alert alert-info mb-3 no-print">
    <div class="fw-semibold">Bonus waiting for the next <?= htmlspecialchars(hr_payroll_run_type_label($payrollType)) ?> run — this run is already posted, so it is not included here:</div>
    <ul class="mb-0 ps-3">
      <?php foreach ($bonusPending as $b): ?>
        <li><?= htmlspecialchars($b['full_name']) ?> <span class="text-muted">(<?= htmlspecialchars((string)$b['employee_code']) ?>)</span> — <?= number_format((float)$b['amt'], 2) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($bonusAddable): ?>
    <form method="post" class="mt-2" onsubmit="return confirm('Add the waiting bonus to this posted run? Net pay and the payslip go up, and an extra accounting journal is posted. This cannot be undone here.')">
      <?php csrf_field(); ?>
      <input type="hidden" name="add_pending_bonus" value="1">
      <button class="btn btn-sm btn-primary">Add all to this run</button>
      <span class="small text-muted ms-2">Or click one employee's "+ … next run" in the table to add only theirs. Left alone, the next run pays it.</span>
    </form>
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
            <th title="Deductions above the WPS 15% cap (15% of base + allowance + bonus)">Over WPS Cap</th>
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
              <td>
                <?= number_format($r['bonus'],2) ?>
                <div class="small text-muted">
                  <?php
                    $bits=[];
                    $pb=(float)($r['profile_bonus']??0);
                    $ot=(float)($r['overtime']??0);
                    $bp=round((float)$r['bonus']-$ot-$pb,2);
                    if($ot) $bits[]="OT ".$ot;
                    if($bp>0.005) $bits[]="Bonus+ ".$bp;
                    if($pb) $bits[]="Bonus ".$pb;
                    echo ($pb && $bits)?implode(' | ',$bits):'';
                  ?>
                </div>
                <?php $pend=(float)($bonusPendingByEmp[(int)$r['employee_id']]??0); if($pend>0.005): ?>
                  <?php if ($canAddPendingBonus): ?>
                  <form method="post" class="no-print" onsubmit="return confirm('Add <?= number_format($pend,2) ?> bonus for <?= htmlspecialchars(addslashes((string)$r['full_name']), ENT_QUOTES, 'UTF-8') ?> to this posted run? Net pay and the payslip go up, and an extra accounting journal is posted.')">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="add_pending_bonus" value="1">
                    <input type="hidden" name="bonus_employee_id" value="<?= (int)$r['employee_id'] ?>">
                    <button class="btn btn-link btn-sm p-0 text-decoration-underline" title="Add this employee's waiting bonus to this run now. If you leave it, the next run pays it.">+ <?= number_format($pend,2) ?> next run</button>
                  </form>
                  <?php else: ?>
                  <div class="small text-primary" title="Added after this run was posted. Paid in the next run; not part of this run's totals.">+ <?= number_format($pend,2) ?> next run</div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
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
              <?php
                $earn = (float)$r['base_pay'] + (float)$r['allowance'] + (float)$r['bonus'];
                $capMax = round($earn * hr_wps_max_deduction_ratio(), 2);
                $overCap = round((float)$r['deductions'] - $capMax, 2);
              ?>
              <td>
                <?php if ($overCap > 0.005): ?>
                  <span class="fw-semibold text-danger"><?= number_format($overCap,2) ?></span>
                  <!-- <div class="small text-muted">Max <?= number_format($capMax,2) ?></div> -->
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="fw-semibold"><?= number_format($r['net_pay'],2) ?></td>
              <td><a class="btn btn-sm btn-outline-primary" target="_blank" href="payslip.php?run_id=<?= (int)$run_id ?>&employee_id=<?= (int)$r['employee_id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; if(!$items): ?>
            <tr><td colspan="10" class="text-center text-muted">—</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
