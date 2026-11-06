<?php
/**
 * Finance > Allocations: add a prepaid expense or accrued revenue schedule
 * (?kind=, ?from_si= fills a prepaid one from a supplier invoice) or change one
 * (?id=). Once a month is posted only the name and notes can change.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_allocation_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_alc_ready($conn);
$id = (int)($_GET['id'] ?? 0);
$fromSi = (int)($_GET['from_si'] ?? 0);
$err = '';
$edit = ($tableOk && $company && $id) ? fin_alc_get($conn, $companyId, $id) : null;
if ($id && !$edit) {
    header('Location: fin_allocations.php');
    exit;
}
$locked = $edit && fin_alc_posted_count($conn, $companyId, $id) > 0;

$form = ['kind' => isset(FIN_ALC_KINDS[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'prepaid', 'name' => '', 'debit_account_id' => 0, 'credit_account_id' => 0,
         'amount' => '', 'months' => '12', 'start_date' => date('Y-m-d'), 'post_from' => '', 'notes' => '', 'source_voucher_id' => 0, 'source_no' => ''];
if ($edit) {
    $form = array_merge($form, array_intersect_key($edit, $form));
    $form['post_from'] = substr($edit['post_from'], 0, 7);
    $form['source_no'] = (string)$edit['source_no'];
} elseif ($fromSi && $tableOk && $company) {
    $form = array_merge($form, fin_alc_from_invoice($conn, $companyId, $fromSi) ?? []);
}

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (['kind', 'name', 'amount', 'months', 'start_date', 'post_from', 'notes'] as $k) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }
    foreach (['debit_account_id', 'credit_account_id', 'source_voucher_id'] as $k) {
        $form[$k] = (int)($_POST[$k] ?? 0);
    }
    if ($locked) {
        $form['kind'] = $edit['kind'];
    }
    try {
        if ($edit) {
            fin_alc_update($conn, $companyId, $id, $form, $userId);
            $done = 'Saved.';
        } else {
            $id = fin_alc_create($conn, $companyId, $form, $userId);
            $done = 'Added. Months already due are waiting in Pending Approval.';
        }
        header('Location: fin_allocation_view.php?id=' . $id . '&msg=' . urlencode($done));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_allocation_form: ' . $e->getMessage());
        $err = 'The schedule could not be saved.';
    }
}

$accounts = ($tableOk && $company) ? fin_voucher_ledger_accounts($conn, $companyId) : [];
$k = FIN_ALC_KINDS[$form['kind']] ?? FIN_ALC_KINDS['prepaid'];

$pageTitle = ($edit ? 'Edit ' : 'New ') . $k['label'];
$finActive = 'alc';
$finReturnPath = dirname(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) . '/fin_allocations.php';
require __DIR__ . '/../includes/fin_layout_header.php';

$accountSelect = static function (string $name, string $label, int $value, bool $disabled) use ($accounts): void {
    echo '<label class="form-label small mb-1" for="f_' . h($name) . '">' . h($label) . '</label>';
    echo '<select class="form-select" id="f_' . h($name) . '" name="' . h($name) . '"' . ($disabled ? ' disabled' : ' required') . '>';
    echo '<option value="">Choose an account</option>';
    foreach ($accounts as $a) {
        echo '<option value="' . (int)$a['id'] . '"' . ((int)$a['id'] === $value ? ' selected' : '') . '>' . h($a['code'] . ' ' . $a['name']) . '</option>';
    }
    echo '</select>';
};
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_allocation_journals.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company.</div>
  <?php else: ?>

  <?php if ($locked): ?>
    <div class="alert alert-info">Months of this schedule are already posted, so only its name and notes can change. To end it early, use Stop on its page.</div>
  <?php endif; ?>
  <?php if ($form['source_no'] !== ''): ?>
    <div class="alert alert-light border">From supplier invoice <a href="fin_voucher_view.php?id=<?= (int)$form['source_voucher_id'] ?>"><?= h($form['source_no']) ?></a>: name, amount (before VAT), start date and the prepaid account are filled from it. Add the expense account and the months.</div>
  <?php endif; ?>

  <form method="post" class="card" style="max-width: 980px">
    <?php csrf_field(); ?>
    <input type="hidden" name="source_voucher_id" value="<?= (int)$form['source_voucher_id'] ?>">
    <div class="card-body row g-3">
      <div class="col-12">
        <div class="btn-group" role="group" aria-label="Kind">
          <?php foreach (FIN_ALC_KINDS as $kk => $kv): ?>
            <input type="radio" class="btn-check" name="kind" id="f_kind_<?= h($kk) ?>" value="<?= h($kk) ?>" <?= $form['kind'] === $kk ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
            <label class="btn btn-outline-primary" for="f_kind_<?= h($kk) ?>"><?= h($kv['label']) ?></label>
          <?php endforeach; ?>
        </div>
        <div class="form-text" id="f_kind_help"></div>
      </div>
      <div class="col-md-8">
        <label class="form-label small mb-1" for="f_name">Name</label>
        <input class="form-control" id="f_name" name="name" maxlength="150" value="<?= h($form['name']) ?>" required placeholder="e.g. Staff insurance 2026-27">
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1" for="f_amount">Amount to spread (AED)</label>
        <input class="form-control text-end" id="f_amount" name="amount" inputmode="decimal" value="<?= h($form['amount']) ?>" <?= $locked ? 'disabled' : 'required' ?>>
      </div>
      <div class="col-md-6" id="f_debit_wrap"><?php $accountSelect('debit_account_id', $k['debit'], (int)$form['debit_account_id'], $locked); ?></div>
      <div class="col-md-6" id="f_credit_wrap"><?php $accountSelect('credit_account_id', $k['credit'], (int)$form['credit_account_id'], $locked); ?></div>
      <div class="col-sm-4">
        <label class="form-label small mb-1" for="f_months">Months</label>
        <input class="form-control text-end" id="f_months" name="months" inputmode="numeric" value="<?= h((string)$form['months']) ?>" <?= $locked ? 'disabled' : 'required' ?>>
      </div>
      <div class="col-sm-4">
        <label class="form-label small mb-1" for="f_start">Start date</label>
        <input type="date" class="form-control" id="f_start" name="start_date" value="<?= h($form['start_date']) ?>" <?= $locked ? 'disabled' : 'required' ?>>
      </div>
      <div class="col-sm-4">
        <label class="form-label small mb-1" for="f_post">First month to post</label>
        <input type="month" class="form-control" id="f_post" name="post_from" value="<?= h($form['post_from']) ?>" <?= $locked ? 'disabled' : '' ?>>
        <div class="form-text">Leave empty to post from the start. Earlier months count as already in the opening balances.</div>
      </div>
      <div class="col-12">
        <label class="form-label small mb-1" for="f_notes">Notes</label>
        <input class="form-control" id="f_notes" name="notes" maxlength="255" value="<?= h($form['notes']) ?>" placeholder="Optional">
      </div>
      <div class="col-12 small text-muted" id="f_preview"></div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary">Save</button>
        <a href="<?= $edit ? 'fin_allocation_view.php?id=' . (int)$id : 'fin_allocations.php' ?>" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </div>
  </form>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  const amount = document.getElementById('f_amount');
  if (!amount) return;
  const KINDS = <?= json_encode(FIN_ALC_KINDS) ?>;
  const HELP = {
    prepaid: 'Paid ahead (insurance, rent, licence). Each month moves a part from the prepaid account to the expense.',
    accrued: 'Earned but not yet billed. Each month books a part as revenue against the accrued revenue account.'
  };
  const months = document.getElementById('f_months');
  const preview = document.getElementById('f_preview');
  function kind() {
    const k = (document.querySelector('[name="kind"]:checked') || {}).value || 'prepaid';
    document.querySelector('#f_debit_wrap label').textContent = KINDS[k].debit;
    document.querySelector('#f_credit_wrap label').textContent = KINDS[k].credit;
    document.getElementById('f_kind_help').textContent = HELP[k];
  }
  function show() {
    const a = parseFloat(String(amount.value).replace(/[,\s]/g, ''));
    const n = parseInt(months.value, 10);
    preview.textContent = a > 0 && n > 0
      ? 'About ' + (a / n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' AED a month for ' + n + ' months. A part first month counts by its days.'
      : '';
  }
  document.querySelectorAll('[name="kind"]').forEach(r => r.addEventListener('change', kind));
  [amount, months].forEach(e => e.addEventListener('input', show));
  kind();
  show();
})();
</script>
</body>
</html>
