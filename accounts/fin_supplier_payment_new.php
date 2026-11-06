<?php
/**
 * Finance > Pay a Supplier: a payment voucher from a Cash & banks account to a
 * supplier, Against Bills (settles the invoices ticked), On Account or Advance
 * (linked to invoices later in Supplier Allocations).
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_supplier_payment_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_sp_ready($conn);
$err = '';
$form = [
    'date' => date('Y-m-d'),
    'cash_account_id' => 0,
    'vendor_id' => (int)($_GET['vendor'] ?? 0),
    'mode' => 'bills',
    'amount' => '',
    'reference' => '',
    'narration' => '',
    'allocations' => [],
];

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (['date', 'mode', 'amount', 'reference', 'narration'] as $k) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $form['cash_account_id'] = (int)($_POST['cash_account_id'] ?? 0);
    $form['vendor_id'] = (int)($_POST['vendor_id'] ?? 0);
    $form['allocations'] = array_map('strval', (array)($_POST['alloc'] ?? []));
    try {
        $id = fin_sp_create($conn, $companyId, $form, $userId);
        header('Location: fin_voucher_view.php?id=' . $id . '&msg=' . urlencode('Supplier payment saved and posted.'));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_supplier_payment_new: ' . $e->getMessage());
        $err = 'The payment could not be saved.';
    }
}

$ready = $tableOk && $company;
$cashAccounts = $ready ? fin_voucher_cash_accounts($conn, $companyId, 'pv') : [];
if (!$form['cash_account_id'] && count($cashAccounts) === 1) {
    $form['cash_account_id'] = (int)$cashAccounts[0]['id'];
}
$suppliers = $ready ? fin_si_suppliers($conn, $companyId) : [];
$settings = $ready ? fin_settings_get($conn, $companyId) : [];
// Every open invoice, grouped by supplier, for the Against Bills table.
$openBySupplier = [];
foreach ($ready ? fin_sp_invoices($conn, $companyId) : [] as $r) {
    $openBySupplier[(int)$r['vendor_id']][] = [
        'id' => (int)$r['id'], 'no' => $r['voucher_no'], 'inv' => $r['invoice_no'],
        'date' => date('d M Y', strtotime($r['voucher_date'])), 'total' => (float)$r['total_amount'], 'outstanding' => $r['outstanding'],
    ];
}

$pageTitle = 'Pay a Supplier';
$finActive = 'pv';
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .v-pay { width: 150px; text-align: right; margin-left: auto; }
  .v-totals { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem 1.75rem; padding: .85rem 1rem; border: 1px solid #dfe3e8; border-radius: .6rem; background: #f8f9fb; margin-bottom: 1rem; }
  .v-totals strong { font-size: 1.1rem; font-variant-numeric: tabular-nums; }
  body.dark-mode .v-totals { background: rgba(30,41,59,.95); border-color: #334155; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_supplier_invoices.sql and fin_supplier_payments.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to pay a supplier.</div>
  <?php elseif (!$suppliers): ?>
    <div class="alert alert-info">This company has no active suppliers in Vendor Management yet.</div>
  <?php else: ?>

  <ul class="nav nav-tabs mb-3 fin-noprint">
    <li class="nav-item"><a class="nav-link" href="fin_payment_voucher_new.php">General payment</a></li>
    <li class="nav-item"><a class="nav-link active" aria-current="page" href="fin_supplier_payment_new.php">Pay a supplier</a></li>
  </ul>

  <?php if (!$settings['si_payable'] || !$settings['sp_advance']): ?>
    <div class="alert alert-warning">Choose the supplier payable and supplier advance accounts in <a href="fin_settings.php">Finance Settings</a> first.</div>
  <?php endif; ?>

  <form method="post" id="pForm">
    <?php csrf_field(); ?>
    <div class="card mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-sm-6 col-lg-2">
            <label class="form-label small mb-1" for="pDate">Date</label>
            <input type="date" class="form-control" id="pDate" name="date" value="<?= h($form['date']) ?>" required>
          </div>
          <div class="col-sm-6 col-lg-4">
            <label class="form-label small mb-1" for="pCash">Paid from</label>
            <select class="form-select" id="pCash" name="cash_account_id" required>
              <option value="">Choose an account</option>
              <?php foreach ($cashAccounts as $acc): ?>
                <option value="<?= (int)$acc['id'] ?>" <?= (int)$acc['id'] === (int)$form['cash_account_id'] ? 'selected' : '' ?>><?= h($acc['code'] . ' ' . $acc['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 col-lg-3">
            <label class="form-label small mb-1" for="pVendor">Supplier</label>
            <select class="form-select" id="pVendor" name="vendor_id" required>
              <option value="">Choose a supplier</option>
              <?php foreach ($suppliers as $sid => $sname): ?>
                <option value="<?= (int)$sid ?>" <?= (int)$sid === (int)$form['vendor_id'] ? 'selected' : '' ?>><?= h($sname) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-1" for="pRef">Reference</label>
            <input class="form-control" id="pRef" name="reference" maxlength="100" value="<?= h($form['reference']) ?>" placeholder="Cheque or transfer no.">
          </div>
          <div class="col-12">
            <label class="form-label small mb-1 d-block">Payment is</label>
            <div class="btn-group flex-wrap" role="group" aria-label="Payment type">
              <?php foreach (FIN_SP_MODES as $mk => $ml): ?>
                <input type="radio" class="btn-check" name="mode" id="pMode_<?= h($mk) ?>" value="<?= h($mk) ?>" <?= $form['mode'] === $mk ? 'checked' : '' ?>>
                <label class="btn btn-outline-primary" for="pMode_<?= h($mk) ?>"><?= h($ml) ?></label>
              <?php endforeach; ?>
            </div>
            <div class="form-text" id="pModeHelp"></div>
          </div>
          <div class="col-12">
            <label class="form-label small mb-1" for="pNarr">Narration</label>
            <input class="form-control" id="pNarr" name="narration" maxlength="255" value="<?= h($form['narration']) ?>" placeholder="Optional">
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-3" id="pBills">
      <div class="card-header">Open invoices of the supplier</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Invoice</th><th>Supplier inv. no.</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Outstanding</th><th class="text-end">Pay now (AED)</th></tr></thead>
          <tbody id="pBillRows"></tbody>
        </table>
      </div>
    </div>

    <div class="card mb-3" id="pAmountCard">
      <div class="card-body">
        <label class="form-label small mb-1" for="pAmount">Amount paid (AED)</label>
        <input class="form-control text-end" style="max-width: 220px" id="pAmount" name="amount" inputmode="decimal" placeholder="0.00" value="<?= h($form['amount']) ?>">
      </div>
    </div>

    <div class="v-totals">
      <div class="me-auto"></div>
      <div class="text-end"><span class="d-block small text-muted">Total paid (AED)</span><strong id="pTotal">0.00</strong></div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary" id="pSave">Save and post</button>
      <a href="fin_payment_vouchers.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  const form = document.getElementById('pForm');
  if (!form) return;
  const OPEN = <?= json_encode((object)$openBySupplier, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const TYPED = <?= json_encode((object)$form['allocations'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const HELP = {
    bills: 'Settles the invoices below now.',
    on_account: 'Paid to the supplier with no invoice yet. Link it to invoices later in Supplier Allocations.',
    advance: 'Paid before the invoice, into the supplier advance account. Link it to the invoice when it comes, in Supplier Allocations.'
  };
  const vendor = document.getElementById('pVendor');
  const rows = document.getElementById('pBillRows');
  const amount = document.getElementById('pAmount');
  const num = v => { const n = parseFloat(String(v).replace(/[,\s]/g, '')); return isFinite(n) ? n : 0; };
  const fmt = n => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const mode = () => (form.querySelector('[name="mode"]:checked') || {}).value;

  function cell(text, cls) { const td = document.createElement('td'); if (cls) td.className = cls; td.textContent = text; return td; }

  function drawBills() {
    rows.innerHTML = '';
    const list = OPEN[vendor.value] || [];
    if (!vendor.value || !list.length) {
      const tr = document.createElement('tr');
      const td = cell(vendor.value ? 'This supplier has no open invoices.' : 'Choose a supplier.', 'text-muted text-center py-3');
      td.colSpan = 6;
      tr.appendChild(td);
      rows.appendChild(tr);
      return;
    }
    list.forEach(inv => {
      const tr = document.createElement('tr');
      tr.appendChild(cell(inv.no));
      tr.appendChild(cell(inv.inv));
      tr.appendChild(cell(inv.date));
      tr.appendChild(cell(fmt(inv.total), 'v-num'));
      tr.appendChild(cell(fmt(inv.outstanding), 'v-num'));
      const td = document.createElement('td');
      const wrap = document.createElement('div');
      wrap.className = 'd-flex align-items-center gap-2 justify-content-end';
      const full = document.createElement('button');
      full.type = 'button';
      full.className = 'btn btn-sm btn-link p-0';
      full.textContent = 'Full';
      const inp = document.createElement('input');
      inp.className = 'form-control form-control-sm v-pay';
      inp.name = 'alloc[' + inv.id + ']';
      inp.inputMode = 'decimal';
      inp.placeholder = '0.00';
      inp.value = TYPED[inv.id] || '';
      full.addEventListener('click', () => { inp.value = inv.outstanding.toFixed(2); total(); });
      wrap.appendChild(full);
      wrap.appendChild(inp);
      td.appendChild(wrap);
      tr.appendChild(td);
      rows.appendChild(tr);
    });
  }

  function total() {
    const t = mode() === 'bills'
      ? Array.from(rows.querySelectorAll('input')).reduce((s, i) => s + num(i.value), 0)
      : num(amount.value);
    document.getElementById('pTotal').textContent = fmt(t);
  }

  function sync() {
    const m = mode();
    document.getElementById('pBills').classList.toggle('d-none', m !== 'bills');
    document.getElementById('pAmountCard').classList.toggle('d-none', m === 'bills');
    // Only the inputs of the way chosen are sent.
    rows.querySelectorAll('input').forEach(i => { i.disabled = m !== 'bills'; });
    amount.disabled = m === 'bills';
    document.getElementById('pModeHelp').textContent = HELP[m] || '';
    total();
  }

  vendor.addEventListener('change', () => { drawBills(); sync(); });
  form.querySelectorAll('[name="mode"]').forEach(r => r.addEventListener('change', sync));
  rows.addEventListener('input', total);
  amount.addEventListener('input', total);
  form.addEventListener('submit', () => { document.getElementById('pSave').disabled = true; });
  drawBills();
  sync();
})();
</script>
</body>
</html>
