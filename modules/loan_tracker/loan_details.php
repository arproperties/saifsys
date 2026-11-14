<?php
/**
 * Loan Tracker — New loan, step 3: loan details.
 * Date of issue, amount, instalments, interest (if any), how it is paid back, how it was paid out,
 * the first due date and a proof file. Next goes to the review (loan_review.php).
 *
 *   loan_details.php?id=12
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_helper.php';

$companyId = lt_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';

$q = $conn->prepare("SELECT * FROM lt_loans WHERE id = ? AND company_id = ?");
$q->execute([(int)($_GET['id'] ?? 0), $companyId]);
$loan = $q->fetch(PDO::FETCH_ASSOC);
if (!$loan) {
    lt_flash('That loan was not found.', 'warning');
    header('Location: ' . $ltBase . '/index.php');
    exit;
}
if ($loan['status'] !== 'draft') {
    lt_flash(lt_loan_no((int)$loan['id']) . ' is already submitted.', 'info');
    header('Location: ' . $ltBase . '/loan_view.php?id=' . (int)$loan['id']);
    exit;
}
$id = (int)$loan['id'];
$selfUrl = $ltBase . '/loan_details.php?id=' . $id;
$modes = lt_repayment_modes($loan['loan_to']);
$paidOut = ['cash' => 'Cash', 'bank' => 'Bank transfer'];

$v = [
    'issue_date' => $loan['issue_date'] ?: date('Y-m-d'),
    'amount' => $loan['amount'] !== null ? rtrim(rtrim((string)$loan['amount'], '0'), '.') : '',
    'installments' => $loan['installments'] ?: '1',
    'interest_rate' => $loan['interest_rate'] !== null && (float)$loan['interest_rate'] > 0 ? rtrim(rtrim((string)$loan['interest_rate'], '0'), '.') : '',
    'repayment_mode' => $loan['repayment_mode'] ?: array_key_first($modes),
    'paid_out_by' => $loan['paid_out_by'] ?: 'cash',
    'first_due_date' => $loan['first_due_date'] ?: lt_add_months(date('Y-m-d'), 1),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($v as $k => $_) { $v[$k] = trim((string)($_POST[$k] ?? '')); }
    $validDate = function ($s) { $d = DateTime::createFromFormat('Y-m-d', $s); return $d && $d->format('Y-m-d') === $s; };

    if (!$validDate($v['issue_date'])) { $errors[] = 'Please enter the date of issue.'; }
    $amount = round((float)str_replace(',', '', $v['amount']), 2);
    if ($amount <= 0) { $errors[] = 'Please enter the loan amount.'; }
    elseif ($amount > 10000000) { $errors[] = 'The amount looks too large. Please check it.'; }
    $n = (int)$v['installments'];
    if ((string)$n !== $v['installments'] || $n < 1 || $n > 120) { $errors[] = 'Instalments must be a whole number from 1 to 120.'; }
    $rate = $v['interest_rate'] === '' ? 0.0 : (float)$v['interest_rate'];
    if ($v['interest_rate'] !== '' && (!is_numeric($v['interest_rate']) || $rate < 0 || $rate > 100)) { $errors[] = 'Interest must be a % from 0 to 100, or left empty.'; }
    if (!isset($modes[$v['repayment_mode']])) { $errors[] = 'Please choose the mode of repayment.'; }
    if (!isset($paidOut[$v['paid_out_by']])) { $errors[] = 'Please choose how the loan was paid out.'; }
    if (!$validDate($v['first_due_date'])) { $errors[] = 'Please enter the due date of the first instalment.'; }
    elseif ($validDate($v['issue_date']) && $v['first_due_date'] < $v['issue_date']) { $errors[] = 'The due date cannot be before the date of issue.'; }
    $file = $_FILES['proof'] ?? null;
    if ($e = lt_upload_error($file, 'Proof')) { $errors[] = $e; }

    if (!$errors) {
        $stored = null;
        try {
            if (lt_has_upload($file)) { $stored = lt_store_upload($file, $id); }
            $conn->prepare("UPDATE lt_loans SET issue_date = ?, amount = ?, installments = ?, interest_rate = ?, total_payable = ?,
                                   repayment_mode = ?, paid_out_by = ?, first_due_date = ?, details_saved_at = NOW()"
                           . ($stored ? ", proof_path = ?, proof_name = ?" : '') . " WHERE id = ? AND status = 'draft'")
                ->execute(array_merge(
                    [$v['issue_date'], $amount, $n, $rate > 0 ? $rate : null, lt_total_payable($amount, $rate),
                     $v['repayment_mode'], $v['paid_out_by'], $v['first_due_date']],
                    $stored ? [$stored, mb_substr(basename((string)$file['name']), 0, 255)] : [],
                    [$id]));
            if ($stored && !empty($loan['proof_path'])) {
                @unlink(dirname(__DIR__, 2) . '/' . $loan['proof_path']);
            }
            header('Location: ' . $ltBase . '/loan_review.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($stored) { @unlink(dirname(__DIR__, 2) . '/' . $stored); }
            error_log('loan_tracker details: ' . $e->getMessage());
            $errors[] = 'The details could not be saved. Please try again.';
        }
    }
}

$t = lt_types()[$loan['loan_to']];
$pageTitle = 'Loan details';
$pageStyles = '
  .lt-calc{ background:var(--lt-tint); border:1px solid var(--lt-tint-3); border-radius:12px; padding:1rem 1.15rem; }
  .lt-calc small{ display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--primary-dark); opacity:.8; }
  .lt-calc b{ font-size:1.2rem; color:var(--primary-dark); font-variant-numeric:tabular-nums; }
';
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4" style="max-width:960px">
  <div>
    <h1 class="lt-title">New loan</h1>
    <p class="lt-sub"><?= h(lt_loan_no($id)) ?> · <?= h($loan['borrower_name']) ?></p>
  </div>
  <div class="lt-loan-to">
    <span class="lt-loan-to-label">Loan to</span>
    <span class="lt-loan-to-value"><i class="bi <?= $t['icon'] ?>"></i> <?= h($t['label']) ?></span>
  </div>
</div>

<?php lt_steps(3); ?>

<?php if ($errors): ?>
  <div class="alert alert-danger" style="max-width:960px">
    <div class="fw-semibold mb-1">Please check the following:</div>
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <?php if (!empty($_FILES['proof']['name'])): ?><div class="small mt-2">Please attach the proof again.</div><?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" action="<?= h($selfUrl) ?>" enctype="multipart/form-data" style="max-width:960px" id="ltDetails">
  <?php csrf_field(); ?>
  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="lt-section mb-3">Loan details</div>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="issue_date">Date of issue <span class="lt-req">*</span></label>
        <input type="date" class="form-control" id="issue_date" name="issue_date" value="<?= h($v['issue_date']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="amount">Amount (AED) <span class="lt-req">*</span></label>
        <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" value="<?= h($v['amount']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="installments">Instalments <span class="lt-req">*</span></label>
        <input type="number" class="form-control" id="installments" name="installments" min="1" max="120" step="1" value="<?= h($v['installments']) ?>" required>
        <div class="form-text">Number of monthly payments.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="interest_rate">Interest % <span class="text-muted fw-normal">(if applicable)</span></label>
        <input type="number" class="form-control" id="interest_rate" name="interest_rate" min="0" max="100" step="0.01" value="<?= h($v['interest_rate']) ?>" placeholder="0">
        <div class="form-text">Flat, on the amount.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="repayment_mode">Mode of repayment <span class="lt-req">*</span></label>
        <select class="form-select" id="repayment_mode" name="repayment_mode" required>
          <?php foreach ($modes as $k => $l): ?><option value="<?= $k ?>" <?= $v['repayment_mode'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="paid_out_by">Paid in <span class="lt-req">*</span></label>
        <select class="form-select" id="paid_out_by" name="paid_out_by" required>
          <?php foreach ($paidOut as $k => $l): ?><option value="<?= $k ?>" <?= $v['paid_out_by'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
        </select>
        <div class="form-text">How the loan money was given.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="first_due_date">Due date <span class="lt-req">*</span></label>
        <input type="date" class="form-control" id="first_due_date" name="first_due_date" value="<?= h($v['first_due_date']) ?>" required>
        <div class="form-text">Of the first instalment.</div>
      </div>
      <div class="col-md-8">
        <label class="form-label small fw-semibold" for="proof">Proof document <span class="text-muted fw-normal">(if any)</span></label>
        <?php if (!empty($loan['proof_path'])): ?>
          <div class="lt-hr-file mb-2"><i class="bi bi-paperclip"></i> <?= h($loan['proof_name'] ?: 'Proof attached') ?>
            <a class="ms-auto fw-semibold" style="color:var(--primary-dark)" target="_blank" rel="noopener" href="<?= h($ltBase) ?>/file.php?proof=<?= $id ?>">View</a></div>
        <?php endif; ?>
        <input type="file" class="form-control" id="proof" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp">
        <div class="form-text">Receipt, transfer slip or signed agreement. PDF, JPG, PNG or WEBP, up to 10 MB.<?= !empty($loan['proof_path']) ? ' Attach a file only to replace the one above.' : '' ?></div>
      </div>
    </div>

    <div class="row g-2 mt-3">
      <div class="col-6 col-md-3"><div class="lt-calc"><small>Total to pay back</small><b>AED <span id="cTotal">—</span></b></div></div>
      <div class="col-6 col-md-3"><div class="lt-calc"><small>Each instalment</small><b>AED <span id="cEach">—</span></b></div></div>
      <div class="col-6 col-md-3"><div class="lt-calc"><small>Interest</small><b>AED <span id="cInt">—</span></b></div></div>
      <div class="col-6 col-md-3"><div class="lt-calc"><small>Last instalment due</small><b id="cLast">—</b></div></div>
    </div>
  </div></div>

  <div class="d-flex justify-content-between align-items-center">
    <a href="<?= h($ltBase) ?>/loan_view.php?id=<?= $id ?>" class="btn btn-lt-ghost"><i class="bi bi-arrow-left me-1"></i> Back</a>
    <button type="submit" class="btn btn-lt">Next: review <i class="bi bi-arrow-right ms-1"></i></button>
  </div>
</form>

<?php
$pageScripts = <<<'JS'
<script>
(function () {
  var f = document.getElementById('ltDetails');
  var fmt = function (n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  function addMonths(iso, k) {
    var p = iso.split('-').map(Number); if (p.length !== 3 || !p[0]) { return null; }
    var y = p[0], m = p[1] - 1 + k, d = p[2];
    y += Math.floor(m / 12); m = ((m % 12) + 12) % 12;
    var last = new Date(y, m + 1, 0).getDate();
    return String(Math.min(d, last)).padStart(2, '0') + ' ' + months[m] + ' ' + y;
  }
  function calc() {
    var a = parseFloat(f.amount.value) || 0, n = parseInt(f.installments.value, 10) || 0, r = parseFloat(f.interest_rate.value) || 0;
    var total = Math.round(a * (1 + r / 100) * 100) / 100;
    document.getElementById('cTotal').textContent = a > 0 ? fmt(total) : '—';
    document.getElementById('cInt').textContent = a > 0 ? fmt(total - a) : '—';
    document.getElementById('cEach').textContent = a > 0 && n > 0 ? fmt(Math.floor(total / n * 100) / 100) : '—';
    document.getElementById('cLast').textContent = n > 0 && f.first_due_date.value ? addMonths(f.first_due_date.value, n - 1) : '—';
  }
  f.addEventListener('input', calc);
  f.addEventListener('change', calc);
  calc();
})();
</script>
JS;
require __DIR__ . '/includes/lt_layout_footer.php';
