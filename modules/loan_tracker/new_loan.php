<?php
/**
 * Loan Tracker — New loan, step 1: who the loan is to.
 * Employee → pick the employee. Next goes to loan_docs.php.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_helper.php';

lt_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';
$types = lt_types();
$employees = lt_employees($conn);

$to = isset($types[$_GET['to'] ?? '']) ? $_GET['to'] : '';
$empId = (int)($_GET['employee'] ?? 0);
$error = $_GET['error'] ?? '';

$byCompany = [];
foreach ($employees as $e) {
    $byCompany[$e['company_name'] ?: 'Other'][] = $e;
}

$pageTitle = 'New loan';
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="mb-4">
  <h1 class="lt-title">New loan</h1>
  <p class="lt-sub">Start with who the loan is to.</p>
</div>

<?php lt_steps(1); ?>

<div class="card card-round" style="max-width:640px">
  <div class="card-body p-4">
    <?php if ($error === 'employee'): ?>
      <div class="alert alert-warning py-2">Please choose the employee.</div>
    <?php elseif ($error === 'to'): ?>
      <div class="alert alert-warning py-2">Please choose who the loan is to.</div>
    <?php endif; ?>

    <form method="get" action="<?= h($ltBase) ?>/loan_docs.php" id="ltStep1" novalidate>
      <div class="mb-4">
        <label for="ltTo" class="form-label fw-semibold">Loan to <span class="lt-req">*</span></label>
        <select name="to" id="ltTo" class="form-select form-select-lg" required>
          <option value="">Choose…</option>
          <?php foreach ($types as $k => $t): ?>
            <option value="<?= h($k) ?>" <?= $to === $k ? 'selected' : '' ?>><?= h($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-4" id="ltEmpWrap" <?= $to === 'employee' ? '' : 'hidden' ?>>
        <label for="ltEmp" class="form-label fw-semibold">Employee <span class="lt-req">*</span></label>
        <select name="employee" id="ltEmp" class="form-select lt-search" data-placeholder="Search by name or code">
          <option value=""></option>
          <?php foreach ($byCompany as $company => $list): ?>
            <optgroup label="<?= h($company) ?>">
              <?php foreach ($list as $e): ?>
                <option value="<?= (int)$e['id'] ?>" <?= $empId === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['full_name']) ?> (<?= h($e['employee_code']) ?>)</option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Their Emirates ID, passport and visa will be filled in from HR on the next page.</div>
      </div>

      <div class="d-flex justify-content-between align-items-center pt-2 border-top">
        <a href="<?= h($ltBase) ?>/index.php" class="btn btn-lt-ghost mt-3">Cancel</a>
        <button type="submit" class="btn btn-lt mt-3">Next <i class="bi bi-arrow-right ms-1"></i></button>
      </div>
    </form>
  </div>
</div>

<?php
$pageScripts = <<<'JS'
<script>
(function () {
  var to = document.getElementById('ltTo');
  var wrap = document.getElementById('ltEmpWrap');
  var emp = document.getElementById('ltEmp');
  function sync() { wrap.hidden = to.value !== 'employee'; }
  to.addEventListener('change', sync);
  sync();
  document.getElementById('ltStep1').addEventListener('submit', function (e) {
    if (!to.value) { e.preventDefault(); to.focus(); to.classList.add('is-invalid'); return; }
    to.classList.remove('is-invalid');
    if (to.value === 'employee' && !emp.value) { e.preventDefault(); if (window.$) { $(emp).select2('open'); } }
    if (to.value !== 'employee') { emp.disabled = true; }
  });
})();
</script>
JS;
require __DIR__ . '/includes/lt_layout_footer.php';
