<?php
/**
 * Finance > Lease Posting: pick a contract and post its opening entry. The
 * tenant is debited under Sundry Debtors; the contract, fees and deposit are
 * credited to their accounts. Saving raises a journal voucher (?lease_id=).
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_lease_posting_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn) && fin_lease_posting_ready($conn);
$err = '';
$leaseId = (int)($_POST['lease_id'] ?? $_GET['lease_id'] ?? 0);

$leases = ($tableOk && $company) ? fin_lease_posting_leases($conn, $companyId) : [];
$lease = null;
foreach ($leases as $l) {
    if ((int)$l['id'] === $leaseId) {
        $lease = $l;
    }
}
$accounts = ($tableOk && $company) ? fin_lease_posting_accounts($conn, $companyId) : [];
// The contract and commission rows pick their account from a list, starting at the lease's default.
$choices = [];
$form = ['date' => '', 'amounts' => [], 'accounts' => []];
if ($lease) {
    foreach (FIN_LEASE_POSTING_CHOOSABLE as $key) {
        $choices[$key] = fin_lease_posting_choices($conn, $companyId, $key);
        $accounts[$key] = fin_lease_posting_default($key, $choices[$key], $lease);
        $form['accounts'][$key] = (int)($accounts[$key]['id'] ?? 0);
    }
}

$amount = static fn($n): string => (float)$n > 0 ? number_format((float)$n, 2, '.', '') : '';
if ($lease) {
    $form['date'] = $lease['start_date'] ?: date('Y-m-d');
    $form['amounts'] = array_map($amount, fin_lease_posting_amounts($lease));
}

if ($lease && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $form['date'] = trim((string)($_POST['date'] ?? ''));
    foreach (FIN_LEASE_POSTING_CHOOSABLE as $key) {
        $form['accounts'][$key] = (int)($_POST['account'][$key] ?? 0);
    }
    foreach (FIN_LEASE_POSTING_ROWS as $key => $row) {
        $form['amounts'][$key] = trim((string)($_POST['amount'][$key] ?? ''));
    }
    try {
        $id = fin_lease_post($conn, $companyId, $leaseId, $form, $userId);
        header('Location: fin_voucher_view.php?id=' . $id . '&msg=' . urlencode('Lease ' . $lease['lease_number'] . ' posted.'));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_lease_posting: ' . $e->getMessage());
        $err = 'The lease could not be posted.';
    }
}

$missing = [];
foreach ($accounts as $key => $a) {
    if (!$a) {
        $missing[] = FIN_LEASE_POSTING_ROWS[$key]['code'] . ' (' . FIN_LEASE_POSTING_ROWS[$key]['label'] . ')';
    }
}

$pageTitle = 'Lease Posting';
$finActive = 'lease';
$pageStyles = '
  .lp-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; background: #f4f2ee; }
  .lp-table td { vertical-align: middle; }
  .lp-table .lp-amt { width: 190px; }
  .lp-table input.lp-in { text-align: right; font-variant-numeric: tabular-nums; }
  .lp-none { text-align: center; color: #adb5bd; }
  .lp-acct { display: block; padding: .4rem .7rem; border-radius: .45rem; border: 1px solid transparent; font-weight: 600; }
  .lp-debtor .lp-acct, .lp-debtor input.lp-in { background: #e4edfb; border-color: #3b74d6; color: #1b3f7d; }
  .lp-income .lp-acct, .lp-income input.lp-in { background: #dff2e8; border-color: #2f7d5b; color: #17513a; }
  .lp-liability .lp-acct, .lp-liability input.lp-in { background: #f7dfae; border-color: #c98a1b; color: #7a4f08; }
  .lp-liability .select2-selection { background-color: #f7dfae !important; border-color: #c98a1b !important; cursor: pointer; }
  .lp-liability .select2-selection__rendered { color: #7a4f08 !important; font-weight: 600; }
  .lp-liability .select2-selection:hover { border-color: #7a4f08 !important; }
  .lp-income .select2-selection { background-color: #dff2e8 !important; border-color: #2f7d5b !important; cursor: pointer; }
  .lp-income .select2-selection__rendered { color: #17513a !important; font-weight: 600; }
  .lp-income .select2-selection:hover { border-color: #17513a !important; }
  .lp-miss .lp-acct { background: #fdecec; border-color: #dc3545; color: #b02a37; }
  .lp-total { display: inline-block; min-width: 160px; padding: .45rem .8rem; border-radius: .45rem; border: 1px solid; text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
  .lp-total small { display: block; font-weight: 400; font-size: .7rem; }
  .lp-total-dr { background: #e4edfb; border-color: #3b74d6; color: #1b3f7d; }
  .lp-total-cr { background: #dff2e8; border-color: #2f7d5b; color: #17513a; }
  .lp-total-cr.lp-off { background: #f8d7da; border-color: #b02a37; color: #842029; }
  #lpBalance { font-size: .85rem; font-weight: 600; }
  body.dark-mode .lp-table th { background: #1e293b; color: #cbd5e1; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql, fin_lease_posting.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to post a lease.</div>
  <?php elseif (!$leases): ?>
    <div class="alert alert-info"><?= h($company['name']) ?> has no active leases to post.</div>
  <?php else: ?>

  <?php if ($missing): ?>
    <div class="alert alert-warning">Not in the chart of accounts of <?= h($company['name']) ?>: <?= h(implode(', ', $missing)) ?>. <a href="fin_chart_of_accounts.php">Add <?= count($missing) === 1 ? 'it' : 'them' ?></a> to post an amount on <?= count($missing) === 1 ? 'that row' : 'those rows' ?>.</div>
  <?php endif; ?>

  <form method="post" action="fin_lease_posting.php" id="lpForm">
    <?php csrf_field(); ?>
    <div class="card mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-lg-5">
            <label class="form-label small mb-1" for="lpLease">Select contract</label>
            <select class="form-select" id="lpLease" name="lease_id">
              <option value="">Choose a contract</option>
              <?php foreach ($leases as $l): ?>
                <option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === $leaseId ? 'selected' : '' ?>><?= h($l['lease_number'] . ' · ' . $l['tenant_name'] . ($l['voucher_id'] ? ' (posted)' : '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-1" for="lpTenant">Tenant</label>
            <input class="form-control" id="lpTenant" value="<?= h($lease['tenant_name'] ?? '') ?>" placeholder="Auto" readonly>
          </div>
          <div class="col-sm-6 col-lg-2">
            <label class="form-label small mb-1" for="lpNumber">Lease #</label>
            <input class="form-control" id="lpNumber" value="<?= h($lease['lease_number'] ?? '') ?>" placeholder="Auto" readonly>
          </div>
          <div class="col-sm-6 col-lg-2">
            <label class="form-label small mb-1" for="lpDate">Posting date</label>
            <input type="date" class="form-control" id="lpDate" name="date" value="<?= h($form['date']) ?>" <?= $lease && !$lease['voucher_id'] ? 'required' : 'disabled' ?>>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$lease): ?>
      <div class="alert alert-info">Choose a contract to see its posting.</div>
    <?php elseif ($lease['voucher_id']): ?>
      <div class="alert alert-success">This contract is already posted as <a href="fin_voucher_view.php?id=<?= (int)$lease['voucher_id'] ?>" class="alert-link"><?= h($lease['voucher_no']) ?></a>. Open the voucher to edit it, or delete it to post the contract again.</div>
    <?php else: ?>

    <div class="card mb-3">
      <div class="table-responsive">
        <table class="table lp-table mb-0">
          <thead><tr><th>Account / Line</th><th>Chart of account</th><th class="text-end lp-amt">Debit (AED)</th><th class="text-end lp-amt">Credit (AED)</th></tr></thead>
          <tbody>
          <?php foreach (FIN_LEASE_POSTING_ROWS as $key => $row):
              $a = $accounts[$key];
              $isDebit = $row['side'] === 'debit';
              $input = '<input class="form-control lp-in" name="amount[' . h($key) . ']" data-side="' . h($row['side']) . '" value="' . h($form['amounts'][$key] ?? '') . '" inputmode="decimal" placeholder="0.00" aria-label="' . h($row['label'] . ' ' . $row['side']) . '"' . ($a ? '' : ' disabled') . '>';
          ?>
            <tr class="lp-<?= h($row['kind']) ?><?= $a ? '' : ' lp-miss' ?>">
              <td class="fw-semibold"><?= h($key === 'tenant' ? $lease['tenant_name'] : $row['label']) ?></td>
              <?php if (!empty($choices[$key])): ?>
              <td>
                <select class="form-select" name="account[<?= h($key) ?>]" aria-label="<?= h($row['label']) ?> account">
                  <?php foreach ($choices[$key] as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $form['accounts'][$key] ? 'selected' : '' ?>><?= h($c['code'] . ' ' . $c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <?php else: ?>
              <td><span class="lp-acct"><?= $a ? h($a['code'] . ' ' . $a['name']) : h($row['code'] . ' is not in the chart') ?></span></td>
              <?php endif; ?>
              <td class="lp-amt"><?= $isDebit ? $input : '<div class="lp-none">--</div>' ?></td>
              <td class="lp-amt"><?= $isDebit ? '<div class="lp-none">--</div>' : $input ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2"><span id="lpBalance" class="text-muted">Totals must match (Dr = Cr) before posting.</span></td>
              <td class="text-end"><span class="lp-total lp-total-dr"><small>Total Dr</small><span id="lpTotalDr">0.00</span></span></td>
              <td class="text-end"><span class="lp-total lp-total-cr" id="lpCrBox"><small>Total Cr</small><span id="lpTotalCr">0.00</span></span></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary" id="lpSave">Save / Post</button>
      <a href="fin_lease_posting.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
    <?php endif; ?>
  </form>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  const form = document.getElementById('lpForm');
  if (!form) return;

  // Picking a contract loads its amounts.
  document.getElementById('lpLease').addEventListener('change', function () {
    window.location = 'fin_lease_posting.php' + (this.value ? '?lease_id=' + encodeURIComponent(this.value) : '');
  });

  const save = document.getElementById('lpSave');
  if (!save) return;
  const num = v => { const n = parseFloat(String(v).replace(/[,\s]/g, '')); return isFinite(n) ? n : 0; };
  const fmt = n => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const sum = side => Array.from(form.querySelectorAll('input[data-side="' + side + '"]')).reduce((t, el) => t + num(el.value), 0);
  const debit = form.querySelector('input[data-side="debit"]');
  // The tenant's debit follows the credits until it is typed over.
  let debitTyped = Math.round((num(debit.value) - sum('credit')) * 100) !== 0;

  function totals() {
    const c = sum('credit');
    if (!debitTyped && !debit.disabled) debit.value = c > 0 ? c.toFixed(2) : '';
    const d = sum('debit');
    document.getElementById('lpTotalDr').textContent = fmt(d);
    document.getElementById('lpTotalCr').textContent = fmt(c);
    const diff = Math.round((d - c) * 100) / 100;
    const bal = document.getElementById('lpBalance');
    const ok = diff === 0 && d > 0;
    if (d === 0 && c === 0) {
      bal.className = 'text-muted';
      bal.textContent = 'Totals must match (Dr = Cr) before posting.';
    } else if (ok) {
      bal.className = 'text-success';
      bal.innerHTML = '<i class="bi bi-check-circle-fill"></i> Balanced';
    } else {
      bal.className = 'text-danger';
      bal.textContent = 'Out of balance by ' + fmt(Math.abs(diff)) + ' AED (' + (diff > 0 ? 'debit is higher' : 'credits are higher') + ').';
    }
    document.getElementById('lpCrBox').classList.toggle('lp-off', !ok && (d > 0 || c > 0));
    save.disabled = !ok;
  }

  form.addEventListener('input', e => {
    if (e.target === debit) debitTyped = debit.value.trim() !== '';
    totals();
  });
  form.addEventListener('submit', () => { save.disabled = true; });
  totals();
})();
</script>
</body>
</html>
