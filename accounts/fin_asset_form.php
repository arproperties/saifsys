<?php
/**
 * Finance > Fixed Assets: add an asset (?from_si= fills it from a supplier
 * invoice) or change one (?id=). Once a month is posted only the name and
 * notes can change.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_asset_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_asset_ready($conn);
$id = (int)($_GET['id'] ?? 0);
$fromSi = (int)($_GET['from_si'] ?? 0);
$err = '';
$edit = ($tableOk && $company && $id) ? fin_asset_get($conn, $companyId, $id) : null;
if ($id && !$edit) {
    header('Location: fin_assets.php');
    exit;
}
$locked = $edit && fin_asset_posted_count($conn, $companyId, $id) > 0;

$form = ['name' => '', 'asset_account_id' => 0, 'accum_account_id' => 0, 'expense_account_id' => 0, 'cost' => '',
         'life' => '', 'life_unit' => 'years', 'start_date' => date('Y-m-d'), 'post_from' => '', 'notes' => '', 'source_voucher_id' => 0, 'source_no' => ''];
if ($edit) {
    $form = array_merge($form, array_intersect_key($edit, $form));
    $form['life'] = $edit['life_months'] % 12 === 0 ? (string)($edit['life_months'] / 12) : (string)$edit['life_months'];
    $form['life_unit'] = $edit['life_months'] % 12 === 0 ? 'years' : 'months';
    $form['post_from'] = substr($edit['post_from'], 0, 7);
    $form['source_no'] = (string)$edit['source_no'];
} elseif ($fromSi && $tableOk && $company) {
    $form = array_merge($form, fin_asset_from_invoice($conn, $companyId, $fromSi) ?? []);
}

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (['name', 'cost', 'life', 'life_unit', 'start_date', 'post_from', 'notes'] as $k) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }
    foreach (['asset_account_id', 'accum_account_id', 'expense_account_id', 'source_voucher_id'] as $k) {
        $form[$k] = (int)($_POST[$k] ?? 0);
    }
    $data = $form;
    $life = ctype_digit($form['life']) ? (int)$form['life'] : 0;
    $data['life_months'] = $form['life_unit'] === 'months' ? $life : $life * 12;
    try {
        if ($edit) {
            fin_asset_update($conn, $companyId, $id, $data, $userId);
            $done = 'Asset saved.';
        } else {
            $id = fin_asset_create($conn, $companyId, $data, $userId);
            $done = 'Asset added. Depreciation months already due are waiting in Pending Approval.';
        }
        header('Location: fin_asset_view.php?id=' . $id . '&msg=' . urlencode($done));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_asset_form: ' . $e->getMessage());
        $err = 'The asset could not be saved.';
    }
}

$accounts = ($tableOk && $company) ? fin_voucher_ledger_accounts($conn, $companyId) : [];
$lastAccounts = ($tableOk && $company) ? fin_asset_last_accounts($conn, $companyId) : [];

$pageTitle = $edit ? 'Edit Asset' : 'New Asset';
$finActive = 'assets';
$finReturnPath = dirname(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) . '/fin_assets.php';
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
    <div class="alert alert-warning">Run migrations/fin_fixed_assets.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company.</div>
  <?php else: ?>

  <?php if ($locked): ?>
    <div class="alert alert-info">Depreciation of this asset is already posted, so only its name and notes can change. To end it, use Stop on the asset page.</div>
  <?php endif; ?>
  <?php if ($form['source_no'] !== ''): ?>
    <div class="alert alert-light border">From supplier invoice <a href="fin_voucher_view.php?id=<?= (int)$form['source_voucher_id'] ?>"><?= h($form['source_no']) ?></a>: name, cost (before VAT), start date and asset account are filled from it. Check them and add the useful life.</div>
  <?php endif; ?>

  <form method="post" class="card" style="max-width: 980px">
    <?php csrf_field(); ?>
    <input type="hidden" name="source_voucher_id" value="<?= (int)$form['source_voucher_id'] ?>">
    <div class="card-body row g-3">
      <div class="col-md-8">
        <label class="form-label small mb-1" for="f_name">Asset name</label>
        <input class="form-control" id="f_name" name="name" maxlength="150" value="<?= h($form['name']) ?>" required placeholder="e.g. Toyota Hiace - plate 12345">
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1" for="f_cost">Purchase cost (AED, before VAT)</label>
        <input class="form-control text-end" id="f_cost" name="cost" inputmode="decimal" value="<?= h($form['cost']) ?>" <?= $locked ? 'disabled' : 'required' ?>>
      </div>
      <div class="col-md-4"><?php $accountSelect('asset_account_id', 'Asset account', (int)$form['asset_account_id'], $locked); ?></div>
      <div class="col-md-4"><?php $accountSelect('accum_account_id', 'Accumulated depreciation account', (int)$form['accum_account_id'], $locked); ?></div>
      <div class="col-md-4"><?php $accountSelect('expense_account_id', 'Depreciation expense account', (int)$form['expense_account_id'], $locked); ?></div>
      <div class="col-sm-6 col-md-4">
        <label class="form-label small mb-1" for="f_life">Useful life</label>
        <div class="input-group">
          <input class="form-control text-end" id="f_life" name="life" inputmode="numeric" value="<?= h($form['life']) ?>" <?= $locked ? 'disabled' : 'required' ?>>
          <select class="form-select" name="life_unit" style="max-width: 120px" <?= $locked ? 'disabled' : '' ?>>
            <option value="years" <?= $form['life_unit'] === 'years' ? 'selected' : '' ?>>years</option>
            <option value="months" <?= $form['life_unit'] === 'months' ? 'selected' : '' ?>>months</option>
          </select>
        </div>
      </div>
      <div class="col-sm-6 col-md-4">
        <label class="form-label small mb-1" for="f_start">Depreciation start date</label>
        <input type="date" class="form-control" id="f_start" name="start_date" value="<?= h($form['start_date']) ?>" <?= $locked ? 'disabled' : 'required' ?>>
      </div>
      <div class="col-sm-6 col-md-4">
        <label class="form-label small mb-1" for="f_post">First month to post</label>
        <input type="month" class="form-control" id="f_post" name="post_from" value="<?= h($form['post_from']) ?>" <?= $locked ? 'disabled' : '' ?>>
        <div class="form-text">Leave empty to post from the start. For an older asset, pick the month the system takes over; earlier months count as already in the opening balances.</div>
      </div>
      <div class="col-12">
        <label class="form-label small mb-1" for="f_notes">Notes</label>
        <input class="form-control" id="f_notes" name="notes" maxlength="255" value="<?= h($form['notes']) ?>" placeholder="Optional">
      </div>
      <div class="col-12 small text-muted" id="f_preview"></div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary">Save</button>
        <a href="<?= $edit ? 'fin_asset_view.php?id=' . (int)$id : 'fin_assets.php' ?>" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </div>
  </form>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  const cost = document.getElementById('f_cost');
  if (!cost) return;
  // The accumulated and expense accounts used last with an asset account, suggested when empty.
  const LAST = <?= json_encode((object)$lastAccounts) ?>;
  const asset = document.getElementById('f_asset_account_id');
  const accum = document.getElementById('f_accum_account_id');
  const expense = document.getElementById('f_expense_account_id');
  const life = document.getElementById('f_life');
  const unit = document.querySelector('[name="life_unit"]');
  const preview = document.getElementById('f_preview');
  asset.addEventListener('change', () => {
    const l = LAST[asset.value];
    if (!l) return;
    if (!accum.value) window.finSetSelect(accum, String(l[0]));
    if (!expense.value) window.finSetSelect(expense, String(l[1]));
  });
  function show() {
    const c = parseFloat(String(cost.value).replace(/[,\s]/g, ''));
    const n = parseInt(life.value, 10) * (unit.value === 'years' ? 12 : 1);
    preview.textContent = c > 0 && n > 0
      ? 'About ' + (c / n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' AED a month for ' + n + ' months. A part first month counts by its days.'
      : '';
  }
  [cost, life, unit].forEach(e => e.addEventListener('input', show));
  unit.addEventListener('change', show);
  show();
})();
</script>
</body>
</html>
