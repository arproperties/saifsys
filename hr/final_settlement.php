<?php
/**
 * Final settlement form (HR). HR types every value; Save keeps the sheet,
 * Print opens it as a letterhead PDF (final_settlement_pdf.php).
 * Nothing else in the system is changed. See includes/hr_final_settlement.php.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/AuditService.php';
require_once __DIR__ . '/includes/hr_final_settlement.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

$employeeId = (int)($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);
$sheetId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$st = $conn->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
$st->execute([$employeeId]);
$emp = $st->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    http_response_code(404);
    exit('Employee not found.');
}

$ready = hr_fs_table_ready($conn);
$error = '';
$sheet = null;
if ($ready && $sheetId > 0) {
    $sheet = hr_fs_load($conn, $sheetId);
    if (!$sheet || (int)$sheet['employee_id'] !== $employeeId) {
        http_response_code(404);
        exit('Settlement sheet not found.');
    }
}

$paidReady = $ready && hr_fs_paid_ready($conn);

// ---------------------------------------------------------------- delete a completed sheet
// Removes the sheet and its documents and sets the employee's status back to Not started.
if ($paidReady && $sheet && !empty($sheet['completed_at']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['fs_delete'])) {
    csrf_verify();
    $conn->prepare("DELETE FROM hr_final_settlements WHERE id = ?")->execute([$sheetId]);
    foreach ($sheet['files'] as $rel) {
        if ($abs = hr_fs_file_abs($rel)) {
            @unlink($abs);
        }
    }
    $conn->prepare("UPDATE employees SET final_settlement_status = 'not_started' WHERE id = ? AND final_settlement_status = 'completed'")->execute([$employeeId]);
    AuditService::log([
        'action' => 'hr_final_settlement_delete',
        'object_type' => 'hr_final_settlement',
        'object_id' => $sheetId,
        'summary' => 'Final settlement sheet #' . $sheetId . ' deleted for ' . $emp['employee_code'] . ' (net AED ' . number_format((float)$sheet['net_amount'], 2) . ')',
        'old_data' => ['header' => $sheet['header'], 'due' => $sheet['due'], 'deductions' => $sheet['deductions'], 'net' => $sheet['net_amount'],
                       'paid' => $sheet['paid_amount'], 'paid_date' => $sheet['paid_date'], 'completed_at' => $sheet['completed_at'], 'files' => count($sheet['files'])],
    ]);
    header('Location: final_settlement.php?employee_id=' . $employeeId . '&deleted=1');
    exit;
}

// ---------------------------------------------------------------- save
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['fs_delete'])) {
    csrf_verify();
    try {
        $header = [];
        foreach (hr_fs_header_fields() as $k => $label) {
            $header[$k] = mb_substr(trim((string)($_POST['h'][$k] ?? '')), 0, 200);
        }
        $due = hr_fs_rows_from_post('due');
        $ded = array_map(static function ($r) { $r['amount'] = abs($r['amount']); return $r; }, hr_fs_rows_from_post('ded'));
        // No rows but a total typed: keep it as one row, so the sheet and the PDF add up the same way.
        foreach (['due' => 'Total due', 'ded' => 'Total deductions'] as $tp => $tLabel) {
            $tRaw = str_replace([',', ' '], '', (string)($_POST[$tp . '_total'] ?? ''));
            if (!${$tp} && $tRaw !== '') {
                if (!is_numeric($tRaw)) {
                    throw new RuntimeException($tLabel . ' "' . $_POST[$tp . '_total'] . '" is not a number.');
                }
                if ((float)$tRaw != 0) {
                    ${$tp} = [['item' => $tLabel, 'details' => '', 'amount' => round($tp === 'ded' ? abs((float)$tRaw) : (float)$tRaw, 2)]];
                }
            }
        }
        // Final settlement paid: amount paid and the attached documents (date received = paid_date below).
        $paidRaw = str_replace([',', ' '], '', (string)($_POST['paid_amount'] ?? ''));
        if ($paidRaw !== '' && (!is_numeric($paidRaw) || (float)$paidRaw < 0)) {
            throw new RuntimeException('Amount paid is not a number.');
        }
        if (!empty($_POST['done']) && (float)$paidRaw <= 0) {
            throw new RuntimeException('Enter the amount paid before marking the final settlement completed.');
        }
        $keptFiles = [];
        foreach ($sheet['files'] ?? [] as $n => $rel) {
            if (!in_array((string)$n, array_map('strval', (array)($_POST['remove_file'] ?? [])), true)) {
                $keptFiles[] = $rel;
            }
        }
        $hasNewFile = (bool)array_filter((array)($_FILES['paid_files']['name'] ?? []));
        if (!$due && !$ded && $paidRaw === '' && !$keptFiles && !$hasNewFile) {
            throw new RuntimeException('Add at least one amount.');
        }
        $method = (string)($_POST['payment_method'] ?? '');
        if (!array_key_exists($method, hr_fs_payment_methods())) {
            $method = '';
        }
        $paid = trim((string)($_POST['paid_date'] ?? ''));
        if ($paid !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid)) {
            throw new RuntimeException('Date received is not a valid date.');
        }
        // Last check, so a refused sheet leaves no file behind.
        $newFiles = $paidReady ? hr_fs_save_files('paid_files', $employeeId, HR_FS_MAX_FILES - count($keptFiles)) : [];
        $totDue = hr_fs_sum($due);
        $totDed = hr_fs_sum($ded);
        $net = round($totDue - $totDed, 2);
        $uid = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0) ?: null;
        $vals = [
            json_encode($header, JSON_UNESCAPED_UNICODE),
            json_encode($due, JSON_UNESCAPED_UNICODE),
            json_encode($ded, JSON_UNESCAPED_UNICODE),
            mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500) ?: null,
            $method ?: null,
            mb_substr(trim((string)($_POST['cheque_no'] ?? '')), 0, 60) ?: null,
            $paid ?: null,
            $totDue, $totDed, $net,
        ];
        if ($sheet) {
            $conn->prepare("UPDATE hr_final_settlements SET header_json=?, due_json=?, deduction_json=?, note=?, payment_method=?, cheque_no=?, paid_date=?,
                                   total_due=?, total_deduct=?, net_amount=?, updated_by=?, updated_at=NOW() WHERE id=?")
                 ->execute(array_merge($vals, [$uid, $sheetId]));
            $action = 'hr_final_settlement_update';
        } else {
            $conn->prepare("INSERT INTO hr_final_settlements (header_json, due_json, deduction_json, note, payment_method, cheque_no, paid_date,
                                   total_due, total_deduct, net_amount, employee_id, company_id, created_by)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                 ->execute(array_merge($vals, [$employeeId, (int)$emp['company_id'] ?: null, $uid]));
            $sheetId = (int)$conn->lastInsertId();
            $action = 'hr_final_settlement_create';
        }
        if ($paidReady) {
            $conn->prepare("UPDATE hr_final_settlements SET paid_amount = ?, files_json = ? WHERE id = ?")
                 ->execute([$paidRaw === '' ? null : round((float)$paidRaw, 2), json_encode(array_merge($keptFiles, $newFiles)), $sheetId]);
            foreach (array_diff($sheet['files'] ?? [], $keptFiles) as $rel) {
                if ($abs = hr_fs_file_abs($rel)) {
                    @unlink($abs);
                }
            }
        }
        AuditService::log([
            'action' => $action,
            'object_type' => 'hr_final_settlement',
            'object_id' => $sheetId,
            'summary' => 'Final settlement sheet for ' . $emp['employee_code'] . ': net AED ' . number_format($net, 2),
            'old_data' => $sheet ? ['net' => $sheet['net_amount'], 'due' => $sheet['due'], 'deductions' => $sheet['deductions']] : null,
            'new_data' => ['net' => $net, 'due' => $due, 'deductions' => $ded, 'paid' => $paidRaw, 'date' => $paid, 'files' => count($keptFiles) + count($newFiles)],
        ]);
        // "Final settlement completed": the sheet is saved as usual, then the employee is marked Completed.
        $done = $paidReady && !empty($_POST['done']);
        if ($done) {
            $conn->prepare("UPDATE hr_final_settlements SET completed_at = NOW() WHERE id = ?")->execute([$sheetId]);
            $conn->prepare("UPDATE employees SET final_settlement_status = 'completed' WHERE id = ?")->execute([$employeeId]);
            AuditService::log([
                'action' => 'hr_final_settlement_done',
                'object_type' => 'employee',
                'object_id' => $employeeId,
                'summary' => 'Final settlement marked completed for ' . $emp['employee_code'] . ' (sheet #' . $sheetId . ')',
                'old_data' => ['final_settlement_status' => $emp['final_settlement_status'] ?? null],
                'new_data' => ['final_settlement_status' => 'completed'],
            ]);
        }
        header('Location: final_settlement.php?employee_id=' . $employeeId . '&id=' . $sheetId . '&saved=' . ($done ? 'done' : '1'));
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

// ---------------------------------------------------------------- values for the form
if ($error !== '') {
    // keep what was typed
    $header = array_map('strval', (array)($_POST['h'] ?? []));
    $rowsBack = static function (string $p): array {
        $out = [];
        foreach ((array)($_POST[$p . '_item'] ?? []) as $i => $it) {
            $out[] = ['item' => $it, 'details' => $_POST[$p . '_details'][$i] ?? '', 'amount' => $_POST[$p . '_amount'][$i] ?? ''];
        }
        return $out;
    };
    $due = $rowsBack('due');
    $ded = $rowsBack('ded');
    $note = (string)($_POST['note'] ?? '');
    $method = (string)($_POST['payment_method'] ?? '');
    $chequeNo = (string)($_POST['cheque_no'] ?? '');
    $paidDate = (string)($_POST['paid_date'] ?? '');
    $paidAmount = (string)($_POST['paid_amount'] ?? '');
} elseif ($sheet) {
    $header = $sheet['header'];
    $due = $sheet['due'];
    $ded = $sheet['deductions'];
    $note = (string)$sheet['note'];
    $method = (string)$sheet['payment_method'];
    $chequeNo = (string)$sheet['cheque_no'];
    $paidDate = (string)$sheet['paid_date'];
    $paidAmount = ($sheet['paid_amount'] ?? null) === null ? '' : number_format((float)$sheet['paid_amount'], 2, '.', '');
} else {
    $header = hr_fs_header_from_employee($emp);
    $due = [];
    $ded = [];
    $note = '';
    $method = $chequeNo = $paidDate = $paidAmount = '';
}
if (!$due) {
    $due = [['item' => '', 'details' => '', 'amount' => '']];
}
if (!$ded) {
    $ded = [['item' => '', 'details' => '', 'amount' => '']];
}

$others = [];
if ($ready) {
    $st = $conn->prepare("SELECT id, net_amount, " . ($paidReady ? "paid_amount" : "NULL AS paid_amount") . ", created_at, updated_at FROM hr_final_settlements WHERE employee_id = ? ORDER BY id DESC");
    $st->execute([$employeeId]);
    $others = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Final settlement · ' . $emp['full_name'];
$pageStyles = <<<'CSS'
.fs-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:16px}
.fs-card h6{font-weight:700;margin-bottom:12px}
.fs-rows td{vertical-align:top;padding:4px}
.fs-rows input{font-size:.9rem}
.fs-amt{width:130px;text-align:right}
.fs-total{font-weight:700;text-align:right}
.fs-closed thead,.fs-closed tbody,.fs-closed .fs-add{display:none}
.fs-net{background:#0b3d5c;color:#fff;border-radius:10px;padding:12px 16px;display:flex;justify-content:space-between;font-size:1.15rem;font-weight:700}
CSS;
require_once __DIR__ . '/includes/hr_layout_header.php';

$printBtn = $sheet
    ? '<a class="btn btn-sm btn-primary" target="_blank" href="final_settlement_pdf.php?id=' . (int)$sheet['id'] . '"><i class="bi bi-printer me-1"></i>Print PDF</a>'
    : '';
echo hr_ui_page_header(
    'Final settlement',
    h($emp['full_name']) . ' · ' . h($emp['employee_code']) . ($sheet ? ' · sheet #' . (int)$sheet['id'] : ' · new sheet'),
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Employees', 'href' => 'employees'],
        ['label' => $emp['full_name'], 'href' => 'employee_view?id=' . (int)$emp['id']],
        ['label' => 'Final settlement'],
    ],
    $printBtn
);

$rowHtml = static function (string $p, array $r): string {
    $amt = $r['amount'] === '' ? '' : number_format((float)$r['amount'], 2, '.', '');
    return '<tr>'
        . '<td><input class="form-control form-control-sm" name="' . $p . '_item[]" value="' . h($r['item']) . '" placeholder="Item"></td>'
        . '<td><input class="form-control form-control-sm" name="' . $p . '_details[]" value="' . h($r['details']) . '" placeholder="Details (how it was worked out)"></td>'
        . '<td><input class="form-control form-control-sm fs-amt" name="' . $p . '_amount[]" value="' . h($amt) . '" inputmode="decimal" placeholder="0.00"></td>'
        . '<td><button type="button" class="btn btn-sm btn-outline-danger fs-del" title="Remove row">&times;</button></td>'
        . '</tr>';
};
?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">The final settlement table is not set up yet. Run <code>live_hotfix/2026-11-13_hr_final_settlements.sql</code> in phpMyAdmin first.</div>
<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; exit; endif; ?>

<?php if (!empty($_GET['deleted'])): ?>
  <div class="alert alert-success">The final settlement was deleted.</div>
<?php endif; ?>
<?php if (!empty($_GET['saved'])): ?>
  <div class="alert alert-success"><?= $_GET['saved'] === 'done' ? 'Saved, and the final settlement is marked <strong>completed</strong>.' : 'Saved.' ?> Use <strong>Print PDF</strong> to open the sheet on the letterhead.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-danger"><?= h($error) ?> Nothing was saved.</div>
<?php endif; ?>

<?php if ($others): ?>
<div class="fs-card py-2">
  <span class="text-muted small me-2">Saved sheets for this employee:</span>
  <?php foreach ($others as $o): ?>
    <a class="btn btn-sm <?= $sheet && (int)$sheet['id'] === (int)$o['id'] ? 'btn-secondary' : 'btn-outline-secondary' ?> me-1"
       href="final_settlement.php?employee_id=<?= $employeeId ?>&id=<?= (int)$o['id'] ?>">
      #<?= (int)$o['id'] ?> · <?= $o['paid_amount'] !== null ? 'Paid AED ' . number_format((float)$o['paid_amount'], 2) : 'AED ' . number_format((float)$o['net_amount'], 2) ?> · <?= h(date('j M Y', strtotime($o['updated_at'] ?: $o['created_at']))) ?>
    </a>
  <?php endforeach; ?>
  <?php if ($sheet): ?>
    <a class="btn btn-sm btn-outline-primary" href="final_settlement.php?employee_id=<?= $employeeId ?>">+ New sheet</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<form method="post" id="fsForm" autocomplete="off" enctype="multipart/form-data">
  <?php csrf_field(); ?>
  <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
  <input type="hidden" name="id" value="<?= $sheet ? (int)$sheet['id'] : 0 ?>">

  <div class="fs-card">
    <h6>Employee details <span class="text-muted fw-normal small">— filled from the profile, change anything</span></h6>
    <div class="row g-2">
      <?php foreach (hr_fs_header_fields() as $k => $label): ?>
        <div class="col-md-6 col-lg-3">
          <label class="form-label small mb-1"><?= h($label) ?></label>
          <input class="form-control form-control-sm" name="h[<?= h($k) ?>]" value="<?= h($header[$k] ?? '') ?>">
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php foreach (['due' => ['Amounts due to the employee', 'Total due', $due], 'ded' => ['Deductions', 'Total deductions', $ded]] as $p => [$title, $totLabel, $rows]): ?>
  <?php
    // A sheet saved with only a total holds it as one row named after the total: show it back in the total box.
    $typedTotal = $error !== '' ? (string)($_POST[$p . '_total'] ?? '') : '';
    if ($error === '' && count($rows) === 1 && $rows[0]['item'] === $totLabel && trim((string)$rows[0]['details']) === '') {
        $typedTotal = number_format((float)$rows[0]['amount'], 2, '.', '');
        $rows = [['item' => '', 'details' => '', 'amount' => '']];
    }
    // The rows start closed unless one of them is filled in.
    $open = (bool)array_filter($rows, static fn($r) => trim((string)$r['item']) !== '' || trim((string)$r['details']) !== '' || trim((string)$r['amount']) !== '');
  ?>
  <div class="fs-card">
    <h6><button type="button" class="btn btn-sm btn-link p-0 me-1 text-decoration-none fs-toggle" title="Show or hide the rows"><i class="bi <?= $open ? 'bi-chevron-up' : 'bi-chevron-down' ?>"></i></button><?= h($title) ?>
      <?php if ($p === 'due'): ?><span class="text-muted fw-normal small">— rows are optional: you can type only the total. A minus is for money already paid, e.g. -0.10</span>
      <?php else: ?><span class="text-muted fw-normal small">— rows are optional: you can type only the total, without a minus</span><?php endif; ?>
    </h6>
    <table class="w-100 fs-rows<?= $open ? '' : ' fs-closed' ?>" data-prefix="<?= $p ?>">
      <thead><tr class="small text-muted"><th style="width:32%">Item</th><th>Details</th><th class="text-end">AED</th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $r) { echo $rowHtml($p, $r); } ?></tbody>
      <tfoot><tr>
        <td><button type="button" class="btn btn-sm btn-outline-primary fs-add">+ Add row</button></td>
        <td class="fs-total"><?= h($totLabel) ?></td>
        <td><input class="form-control form-control-sm fs-amt fw-bold" id="tot_<?= $p ?>" name="<?= $p ?>_total" value="<?= h($typedTotal) ?>" inputmode="decimal" placeholder="0.00" title="Adds up the rows. With no rows, type the total here."></td><td></td>
      </tr></tfoot>
    </table>
  </div>
  <?php endforeach; ?>

  <div class="fs-card">
    <div class="fs-net mb-3"><span>NET AMOUNT PAYABLE TO EMPLOYEE</span><span>AED <span id="tot_net">0.00</span></span></div>
    <label class="form-label small mb-1">Note under the net amount (optional)</label>
    <input class="form-control form-control-sm" name="note" value="<?= h($note) ?>" placeholder="e.g. No end-of-service gratuity is due (service less than one year).">
  </div>

  <div class="fs-card">
    <h6>Final settlement paid</h6>
    <div class="row g-2">
      <div class="col-md-4">
        <label class="form-label small mb-1">Method</label>
        <select class="form-select form-select-sm" name="payment_method">
          <?php foreach (hr_fs_payment_methods() as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= $method === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1">Cheque no. (if cheque)</label>
        <input class="form-control form-control-sm" name="cheque_no" value="<?= h($chequeNo) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1">Date received (leave empty to fill by hand)</label>
        <input type="date" class="form-control form-control-sm" name="paid_date" value="<?= h($paidDate) ?>">
      </div>
      <?php if ($paidReady): ?>
      <div class="col-md-4">
        <label class="form-label small mb-1">Amount paid (AED)</label>
        <input class="form-control form-control-sm" name="paid_amount" value="<?= h($paidAmount) ?>" inputmode="decimal" placeholder="0.00">
      </div>
      <div class="col-md-8">
        <label class="form-label small mb-1">Attach documents (receipt, PDF or photo; you can pick several)</label>
        <input type="file" class="form-control form-control-sm" name="paid_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp">
      </div>
      <?php if ($sheet && $sheet['files']): ?>
      <div class="col-12">
        <?php foreach ($sheet['files'] as $n => $rel): ?>
          <span class="d-inline-flex align-items-center border rounded px-2 py-1 me-2 mb-1 small">
            <a target="_blank" href="final_settlement_file.php?id=<?= (int)$sheet['id'] ?>&n=<?= (int)$n ?>"><i class="bi bi-paperclip"></i> Document <?= (int)$n + 1 ?></a>
            <label class="ms-2 text-danger mb-0"><input type="checkbox" name="remove_file[]" value="<?= (int)$n ?>"> remove</label>
          </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="small text-muted mt-2">Saving only keeps this sheet. It does not pay anything, change payroll or accounts or close fines. <strong>Final settlement completed</strong> saves and sets the employee's final settlement status to Completed.</div>
  </div>

  <div class="d-flex gap-2 mb-4">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
    <?php if (!$paidReady): ?>
    <?php elseif ($sheet && !empty($sheet['completed_at'])): ?>
      <span class="btn btn-success disabled"><i class="bi bi-check-circle me-1"></i>Completed on <?= h(date('j M Y', strtotime($sheet['completed_at']))) ?></span>
      <button class="btn btn-outline-danger" form="fsDelForm" onclick="return confirm('Delete this final settlement and its documents? This cannot be undone.');"><i class="bi bi-trash me-1"></i>Delete final settlement</button>
    <?php else: ?>
      <button class="btn btn-success" id="fsDone" name="done" value="1" disabled title="Enter the amount paid first" onclick="return confirm('Save this sheet and mark the final settlement as completed?');"><i class="bi bi-check-circle me-1"></i>Final settlement completed</button>
    <?php endif; ?>
    <?php if ($sheet): ?>
      <a class="btn btn-outline-primary" target="_blank" href="final_settlement_pdf.php?id=<?= (int)$sheet['id'] ?>"><i class="bi bi-printer me-1"></i>Print PDF</a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="employee_view?id=<?= $employeeId ?>">Back to employee</a>
  </div>
</form>

<?php if ($sheet && !empty($sheet['completed_at'])): ?>
<form method="post" id="fsDelForm">
  <?php csrf_field(); ?>
  <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
  <input type="hidden" name="id" value="<?= (int)$sheet['id'] ?>">
  <input type="hidden" name="fs_delete" value="1">
</form>
<?php endif; ?>

<template id="fsRowTpl_due"><?= $rowHtml('due', ['item' => '', 'details' => '', 'amount' => '']) ?></template>
<template id="fsRowTpl_ded"><?= $rowHtml('ded', ['item' => '', 'details' => '', 'amount' => '']) ?></template>

<script>
(function () {
  var form = document.getElementById('fsForm');
  var num = function (v) { var n = parseFloat(String(v).replace(/[ ,]/g, '')); return isNaN(n) ? 0 : n; };
  var fmt = function (n) { return n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
  // The total adds up the rows. With no amount in any row, the total box is free to type in.
  function sum(p) {
    var t = 0, any = false, box = document.getElementById('tot_' + p);
    form.querySelectorAll('input[name="' + p + '_amount[]"]').forEach(function (i) {
      if (i.value.trim() !== '') { any = true; }
      t += p === 'ded' ? Math.abs(num(i.value)) : num(i.value);
    });
    if (!any) {
      if (box.readOnly) { box.readOnly = false; box.value = ''; }
      t = p === 'ded' ? Math.abs(num(box.value)) : num(box.value);
      return Math.round(t * 100) / 100;
    }
    t = Math.round(t * 100) / 100;
    box.readOnly = true;
    box.value = t.toFixed(2);
    return t;
  }
  function recalc() {
    var d = sum('due'), x = sum('ded');
    document.getElementById('tot_net').textContent = fmt(Math.round((d - x) * 100) / 100);
  }
  // "Final settlement completed" is on only while an amount paid is typed.
  var doneBtn = document.getElementById('fsDone'), paidBox = form.querySelector('input[name="paid_amount"]');
  function doneState() { if (doneBtn && paidBox) { doneBtn.disabled = !(num(paidBox.value) > 0); } }
  form.addEventListener('input', doneState);
  doneState();
  form.addEventListener('input', recalc);
  form.addEventListener('click', function (e) {
    var add = e.target.closest('.fs-add'), del = e.target.closest('.fs-del'), tog = e.target.closest('.fs-toggle');
    if (tog) {
      var closed = tog.closest('.fs-card').querySelector('table.fs-rows').classList.toggle('fs-closed');
      tog.querySelector('i').className = 'bi ' + (closed ? 'bi-chevron-down' : 'bi-chevron-up');
    }
    if (add) {
      var table = add.closest('table');
      var tpl = document.getElementById('fsRowTpl_' + table.dataset.prefix);
      table.querySelector('tbody').appendChild(tpl.content.cloneNode(true));
    }
    if (del) {
      var body = del.closest('tbody');
      if (body.rows.length > 1) { del.closest('tr').remove(); }
      else { del.closest('tr').querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
      recalc();
    }
  });
  recalc();
})();
</script>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
