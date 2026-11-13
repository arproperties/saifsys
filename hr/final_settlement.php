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

// ---------------------------------------------------------------- save
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $header = [];
        foreach (hr_fs_header_fields() as $k => $label) {
            $header[$k] = mb_substr(trim((string)($_POST['h'][$k] ?? '')), 0, 200);
        }
        $due = hr_fs_rows_from_post('due');
        $ded = array_map(static function ($r) { $r['amount'] = abs($r['amount']); return $r; }, hr_fs_rows_from_post('ded'));
        if (!$due && !$ded) {
            throw new RuntimeException('Add at least one amount.');
        }
        $method = (string)($_POST['payment_method'] ?? '');
        if (!array_key_exists($method, hr_fs_payment_methods())) {
            $method = '';
        }
        $paid = trim((string)($_POST['paid_date'] ?? ''));
        if ($paid !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid)) {
            throw new RuntimeException('Date paid is not a valid date.');
        }
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
        AuditService::log([
            'action' => $action,
            'object_type' => 'hr_final_settlement',
            'object_id' => $sheetId,
            'summary' => 'Final settlement sheet for ' . $emp['employee_code'] . ': net AED ' . number_format($net, 2),
            'old_data' => $sheet ? ['net' => $sheet['net_amount'], 'due' => $sheet['due'], 'deductions' => $sheet['deductions']] : null,
            'new_data' => ['net' => $net, 'due' => $due, 'deductions' => $ded],
        ]);
        header('Location: final_settlement.php?employee_id=' . $employeeId . '&id=' . $sheetId . '&saved=1');
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
} elseif ($sheet) {
    $header = $sheet['header'];
    $due = $sheet['due'];
    $ded = $sheet['deductions'];
    $note = (string)$sheet['note'];
    $method = (string)$sheet['payment_method'];
    $chequeNo = (string)$sheet['cheque_no'];
    $paidDate = (string)$sheet['paid_date'];
} else {
    $header = hr_fs_header_from_employee($emp);
    $due = [];
    $ded = [];
    $note = 'Daily rate = monthly salary ÷ 30.';
    $method = $chequeNo = $paidDate = '';
}
if (!$due) {
    $due = [['item' => '', 'details' => '', 'amount' => '']];
}
if (!$ded) {
    $ded = [['item' => '', 'details' => '', 'amount' => '']];
}

$others = [];
if ($ready) {
    $st = $conn->prepare("SELECT id, net_amount, created_at, updated_at FROM hr_final_settlements WHERE employee_id = ? ORDER BY id DESC");
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

<?php if (!empty($_GET['saved'])): ?>
  <div class="alert alert-success">Saved. Use <strong>Print PDF</strong> to open the sheet on the letterhead.</div>
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
      #<?= (int)$o['id'] ?> · AED <?= number_format((float)$o['net_amount'], 2) ?> · <?= h(date('j M Y', strtotime($o['updated_at'] ?: $o['created_at']))) ?>
    </a>
  <?php endforeach; ?>
  <?php if ($sheet): ?>
    <a class="btn btn-sm btn-outline-primary" href="final_settlement.php?employee_id=<?= $employeeId ?>">+ New sheet</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<form method="post" id="fsForm" autocomplete="off">
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
  <div class="fs-card">
    <h6><?= h($title) ?>
      <?php if ($p === 'due'): ?><span class="text-muted fw-normal small">— type a minus for money already paid, e.g. -0.10</span>
      <?php else: ?><span class="text-muted fw-normal small">— type the amount without a minus; it prints as a deduction</span><?php endif; ?>
    </h6>
    <table class="w-100 fs-rows" data-prefix="<?= $p ?>">
      <thead><tr class="small text-muted"><th style="width:32%">Item</th><th>Details</th><th class="text-end">AED</th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $r) { echo $rowHtml($p, $r); } ?></tbody>
      <tfoot><tr>
        <td><button type="button" class="btn btn-sm btn-outline-primary fs-add">+ Add row</button></td>
        <td class="fs-total"><?= h($totLabel) ?></td>
        <td class="fs-total" id="tot_<?= $p ?>">0.00</td><td></td>
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
    <h6>Payment</h6>
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
        <label class="form-label small mb-1">Date paid (leave empty to fill by hand)</label>
        <input type="date" class="form-control form-control-sm" name="paid_date" value="<?= h($paidDate) ?>">
      </div>
    </div>
    <div class="small text-muted mt-2">Saving only keeps this sheet. It does not pay anything, change payroll or accounts, close fines, or change the employee's status.</div>
  </div>

  <div class="d-flex gap-2 mb-4">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
    <?php if ($sheet): ?>
      <a class="btn btn-outline-primary" target="_blank" href="final_settlement_pdf.php?id=<?= (int)$sheet['id'] ?>"><i class="bi bi-printer me-1"></i>Print PDF</a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="employee_view?id=<?= $employeeId ?>">Back to employee</a>
  </div>
</form>

<template id="fsRowTpl_due"><?= $rowHtml('due', ['item' => '', 'details' => '', 'amount' => '']) ?></template>
<template id="fsRowTpl_ded"><?= $rowHtml('ded', ['item' => '', 'details' => '', 'amount' => '']) ?></template>

<script>
(function () {
  var form = document.getElementById('fsForm');
  var num = function (v) { var n = parseFloat(String(v).replace(/[ ,]/g, '')); return isNaN(n) ? 0 : n; };
  var fmt = function (n) { return n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
  function sum(p) {
    var t = 0;
    form.querySelectorAll('input[name="' + p + '_amount[]"]').forEach(function (i) { t += p === 'ded' ? Math.abs(num(i.value)) : num(i.value); });
    return Math.round(t * 100) / 100;
  }
  function recalc() {
    var d = sum('due'), x = sum('ded');
    document.getElementById('tot_due').textContent = fmt(d);
    document.getElementById('tot_ded').textContent = fmt(x);
    document.getElementById('tot_net').textContent = fmt(Math.round((d - x) * 100) / 100);
  }
  form.addEventListener('input', recalc);
  form.addEventListener('click', function (e) {
    var add = e.target.closest('.fs-add'), del = e.target.closest('.fs-del');
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
