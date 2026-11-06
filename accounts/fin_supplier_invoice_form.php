<?php
/**
 * Finance > Supplier Invoices: book a supplier's invoice, or change one
 * (fin_voucher_edit.php opens it on a saved invoice as $finEditVoucher).
 * Saving posts it at once: expense and Input VAT Dr, the supplier Cr.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_supplier_invoice_helper.php';

$vt = FIN_VOUCHER_TYPES['si'];
$edit = $finEditVoucher ?? null;
$formAction = $edit ? 'fin_voucher_edit.php?id=' . (int)$edit['id'] : $vt['new'];

$tableOk = fin_coa_table_ready($conn) && fin_si_ready($conn);
$err = '';

$form = [
    'vendor_id' => 0,
    'invoice_no' => '',
    'date' => date('Y-m-d'),
    'narration' => '',
    'vat_rate' => '5',
    'vat_amount' => '',
    'lines' => [],
];
if ($tableOk && $edit) {
    $form = fin_si_form_data($conn, $companyId, $edit);
}

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $form['vendor_id'] = (int)($_POST['vendor_id'] ?? 0);
    $form['invoice_no'] = (string)($_POST['invoice_no'] ?? '');
    $form['date'] = trim((string)($_POST['date'] ?? ''));
    $form['narration'] = (string)($_POST['narration'] ?? '');
    $form['vat_rate'] = (string)($_POST['vat_rate'] ?? '');
    $form['vat_amount'] = (string)($_POST['vat_amount'] ?? '');
    $form['lines'] = [];
    foreach ((array)($_POST['line_account_id'] ?? []) as $i => $accountId) {
        $form['lines'][] = [
            'account_id'  => (int)$accountId,
            'description' => (string)($_POST['line_description'][$i] ?? ''),
            'amount'      => (string)($_POST['line_amount'][$i] ?? ''),
        ];
    }
    try {
        $checked = fin_attach_check($_FILES['files'] ?? []);
        if ($edit) {
            $id = (int)$edit['id'];
            $removeIds = array_map('intval', (array)($_POST['remove_file'] ?? []));
            $done = fin_si_update($conn, $companyId, $id, $form, $checked, $removeIds, $userId) ? 'Changes saved.' : 'Nothing was changed.';
        } else {
            $id = fin_si_create($conn, $companyId, $form, $checked, $userId);
            $done = 'Supplier invoice saved and posted.';
            // Ticked as a fixed asset: straight on to the asset form, filled from the invoice.
            if (!empty($_POST['is_asset']) && fin_auto_ready($conn)) {
                header('Location: fin_asset_form.php?from_si=' . $id);
                exit;
            }
        }
        header('Location: fin_voucher_view.php?id=' . $id . '&msg=' . urlencode($done));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_supplier_invoice_form: ' . $e->getMessage());
        $err = 'The invoice could not be saved.';
    }
}

$ready = $tableOk && $company;
$accounts = $ready ? fin_voucher_ledger_accounts($conn, $companyId) : [];
$suppliers = $ready ? fin_si_suppliers($conn, $companyId) : [];
$lastAccounts = $ready ? fin_si_last_accounts($conn, $companyId) : [];
$settings = $ready ? fin_settings_get($conn, $companyId) : [];
$files = ($ready && $edit) ? fin_attach_list($conn, $companyId, (int)$edit['id']) : [];
$removed = array_map('intval', (array)($_POST['remove_file'] ?? []));
// The two accounts the invoice posts by itself cannot be chosen on a line.
$lineAccounts = array_values(array_filter($accounts, static fn($a) => !in_array((int)$a['id'], [(int)($settings['si_payable'] ?? 0), (int)($settings['si_vat_input'] ?? 0)], true)));
$settingsMissing = $ready && (!$settings['si_payable'] || !$settings['si_vat_input']);

$pageTitle = $edit ? 'Edit Supplier Invoice ' . $edit['voucher_no'] : 'New Supplier Invoice';
$finActive = 'si';
$finReturnPath = dirname(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) . '/' . $vt['new'];
$pageStyles = '
  .v-amt { text-align: right; font-variant-numeric: tabular-nums; }
  .v-line { border: 1px solid #dfe3e8; border-radius: .6rem; background: #fff; padding: .7rem 1rem .9rem; margin-bottom: .6rem; }
  .v-line-head { display: flex; align-items: center; gap: .6rem; margin-bottom: .45rem; }
  .v-line-no { width: 1.6rem; height: 1.6rem; border-radius: 50%; background: #eef2f7; color: #344054; font-size: .8rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex: none; }
  .v-line-title { font-size: .72rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #6c757d; }
  .v-line-rm { margin-left: auto; color: #adb5bd; border: 0; background: none; padding: .15rem .35rem; border-radius: .35rem; }
  .v-line-rm:hover { color: #dc3545; background: #fdecec; }
  .v-row { display: flex; flex-wrap: wrap; gap: .6rem .75rem; align-items: flex-end; }
  .v-account { flex: 1 1 320px; min-width: 0; }
  .v-account > label, .v-fields label { display: block; font-size: .72rem; color: #6c757d; margin-bottom: .15rem; }
  .v-fields { flex: 2 1 380px; min-width: 0; display: grid; grid-template-columns: minmax(0, 1fr) 170px; gap: .75rem; align-items: end; }
  .v-fields .v-amt-label { text-align: right; }
  @media (max-width: 767.98px) { .v-fields { grid-template-columns: 1fr 1fr; } .v-fields > div:first-child { grid-column: 1 / -1; } }
  .v-totals { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem 1.75rem; padding: .85rem 1rem; border: 1px solid #dfe3e8; border-radius: .6rem; background: #f8f9fb; margin-bottom: 1rem; }
  .v-totals .v-total { text-align: right; }
  .v-totals .v-total span { display: block; font-size: .72rem; color: #6c757d; }
  .v-totals .v-total strong { font-size: 1.1rem; font-variant-numeric: tabular-nums; }
  .v-vat-input { width: 130px; }
  body.dark-mode .v-line, body.dark-mode .v-totals { background: rgba(30,41,59,.95); border-color: #334155; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_supplier_invoices.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to book a supplier invoice.</div>
  <?php elseif (!$accounts): ?>
    <div class="alert alert-info">This company has no active accounts yet. <a href="fin_chart_of_accounts.php">Set up the chart of accounts</a> first.</div>
  <?php elseif (!$suppliers): ?>
    <div class="alert alert-info">This company has no active suppliers in Vendor Management yet.</div>
  <?php else: ?>

  <?php if ($settingsMissing): ?>
    <div class="alert alert-warning">Choose the supplier payable and Input VAT accounts in <a href="fin_settings.php">Finance Settings</a> before booking an invoice.</div>
  <?php endif; ?>

  <form method="post" action="<?= h($formAction) ?>" id="vForm" enctype="multipart/form-data">
    <?php csrf_field(); ?>
    <div class="card mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6 col-lg-4">
            <label class="form-label small mb-1" for="vVendor">Supplier</label>
            <select class="form-select" id="vVendor" name="vendor_id" required>
              <option value="">Choose a supplier</option>
              <?php foreach ($suppliers as $sid => $sname): ?>
                <option value="<?= (int)$sid ?>" <?= (int)$sid === (int)$form['vendor_id'] ? 'selected' : '' ?>><?= h($sname) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-1" for="vInvNo">Supplier invoice no.</label>
            <input class="form-control" id="vInvNo" name="invoice_no" maxlength="100" value="<?= h($form['invoice_no']) ?>" required>
          </div>
          <div class="col-sm-6 col-lg-2">
            <label class="form-label small mb-1" for="vDate">Invoice date</label>
            <input type="date" class="form-control" id="vDate" name="date" value="<?= h($form['date']) ?>" required>
          </div>
          <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-1 d-block">VAT input</label>
            <div class="btn-group" role="group" aria-label="VAT rate">
              <?php foreach (FIN_SI_VAT_RATES as $rk => $rv): ?>
                <input type="radio" class="btn-check" name="vat_rate" id="vRate<?= h($rk) ?>" value="<?= h($rk) ?>" <?= (string)$form['vat_rate'] === (string)$rk ? 'checked' : '' ?> required>
                <label class="btn btn-outline-primary" for="vRate<?= h($rk) ?>"><?= h($rk) ?>%</label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label small mb-1" for="vNarr">Description</label>
            <input class="form-control" id="vNarr" name="narration" maxlength="255" value="<?= h($form['narration']) ?>" required>
          </div>
        </div>
      </div>
    </div>

    <h6 class="text-muted text-uppercase small fw-bold mb-2">Charged to <span class="text-lowercase fw-normal">(amounts before VAT)</span></h6>
    <div id="vLines"></div>

    <div class="v-totals">
      <button type="button" class="btn btn-sm btn-outline-primary" id="vAdd"><i class="bi bi-plus-lg"></i> Add line</button>
      <div class="me-auto"></div>
      <div class="v-total"><span>Amount (AED)</span><strong id="vNet">0.00</strong></div>
      <div class="v-total">
        <span>VAT (AED)</span>
        <input class="form-control form-control-sm v-amt v-vat-input" id="vVat" name="vat_amount" inputmode="decimal" value="<?= h($form['vat_amount']) ?>" title="Worked out at the rate chosen. Change it only to match the supplier's rounding (1.00 at most).">
      </div>
      <div class="v-total"><span>Total (AED)</span><strong id="vTotal">0.00</strong></div>
    </div>

    <div class="card mb-3">
      <div class="card-body">
        <label class="form-label small mb-1" for="vFiles">Supporting documents <span class="text-muted">(invoice image or PDF, 10 MB each)</span></label>
        <?php if ($files): ?>
        <ul class="list-unstyled mb-2">
          <?php foreach ($files as $f): ?>
          <li class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-paperclip"></i>
            <a href="fin_attachment_file.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><?= h($f['file_name']) ?></a>
            <div class="form-check ms-2 mb-0">
              <input class="form-check-input" type="checkbox" name="remove_file[]" value="<?= (int)$f['id'] ?>" id="vRm<?= (int)$f['id'] ?>" <?= in_array((int)$f['id'], $removed, true) ? 'checked' : '' ?>>
              <label class="form-check-label small text-danger" for="vRm<?= (int)$f['id'] ?>">Remove</label>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <input type="file" class="form-control" id="vFiles" name="files[]" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple <?= $edit ? '' : 'required' ?>>
        <?php if ($err && !$edit): ?><div class="form-text text-danger">Choose the file again: files are not kept when the form shows an error.</div><?php endif; ?>
      </div>
    </div>

    <?php if (!$edit && fin_auto_ready($conn)): ?>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="is_asset" value="1" id="vAsset" <?= !empty($_POST['is_asset']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="vAsset">This is a fixed asset <span class="text-muted small">(a car, laptop, AC unit... used for years). After saving, add its useful life to the asset list.</span></label>
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2">
      <button class="btn btn-primary" id="vSave"><?= $edit ? 'Save changes' : 'Save and post' ?></button>
      <a href="<?= h($edit ? 'fin_voucher_view.php?id=' . (int)$edit['id'] : $vt['list']) ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  const form = document.getElementById('vForm');
  if (!form) return;
  const ACCOUNTS = <?= json_encode(array_map(static fn($a) => ['id' => (int)$a['id'], 'label' => $a['code'] . ' ' . $a['name']], $lineAccounts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  // The account last booked for each supplier, suggested on an empty first line.
  const LAST = <?= json_encode((object)$lastAccounts) ?>;
  const INITIAL = <?= json_encode($form['lines'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const body = document.getElementById('vLines');
  const vat = document.getElementById('vVat');
  const vendor = document.getElementById('vVendor');
  // The VAT box follows the amount until someone types in it.
  let vatTyped = false;

  const num = v => { const n = parseFloat(String(v).replace(/[,\s]/g, '')); return isFinite(n) ? n : 0; };
  const fmt = n => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const rate = () => { const r = form.querySelector('[name="vat_rate"]:checked'); return r ? num(r.value) : 0; };

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function field(labelText, name, value, cls) {
    const wrap = el('div');
    wrap.appendChild(el('label', cls ? 'v-amt-label' : '', labelText));
    const inp = el('input', 'form-control ' + (cls || ''));
    inp.name = name;
    inp.value = value || '';
    wrap.appendChild(inp);
    return [wrap, inp];
  }

  function renumber() {
    Array.from(body.children).forEach((line, i) => { line.querySelector('.v-line-no').textContent = i + 1; });
  }

  function addLine(data) {
    data = data || {};
    const tr = el('div', 'v-line');
    const head = el('div', 'v-line-head');
    head.appendChild(el('span', 'v-line-no'));
    head.appendChild(el('span', 'v-line-title', 'Line'));
    const rm = el('button', 'v-line-rm');
    rm.type = 'button';
    rm.title = 'Remove line';
    rm.setAttribute('aria-label', 'Remove line');
    rm.innerHTML = '<i class="bi bi-trash"></i>';
    rm.addEventListener('click', () => {
      tr.remove();
      if (!body.children.length) addLine();
      renumber();
      totals();
    });
    head.appendChild(rm);
    tr.appendChild(head);

    const row = el('div', 'v-row');
    const acc = el('div', 'v-account');
    const sel = el('select', 'form-select');
    sel.name = 'line_account_id[]';
    sel.appendChild(new Option('Choose the expense account', ''));
    ACCOUNTS.forEach(a => sel.appendChild(new Option(a.label, a.id)));
    if (data.account_id) sel.value = String(data.account_id);
    acc.appendChild(el('label', '', 'Expense / asset account'));
    acc.appendChild(sel);
    row.appendChild(acc);

    const fields = el('div', 'v-fields');
    const [descWrap, desc] = field('Description', 'line_description[]', data.description);
    desc.maxLength = 255;
    desc.placeholder = 'Optional';
    fields.appendChild(descWrap);
    const [aWrap, a] = field('Amount before VAT (AED)', 'line_amount[]', data.amount, 'v-amt');
    a.inputMode = 'decimal';
    a.placeholder = '0.00';
    fields.appendChild(aWrap);
    row.appendChild(fields);
    tr.appendChild(row);

    body.appendChild(tr);
    renumber();
    window.finSearchable(row);
  }

  function totals() {
    const net = Array.from(form.querySelectorAll('[name="line_amount[]"]')).reduce((t, e) => t + num(e.value), 0);
    if (!vatTyped || rate() === 0) vat.value = (Math.round(net * rate()) / 100).toFixed(2);
    vat.readOnly = rate() === 0;
    document.getElementById('vNet').textContent = fmt(net);
    document.getElementById('vTotal').textContent = fmt(net + num(vat.value));
  }

  // Picking a supplier fills an empty first line with the account last used for it.
  vendor.addEventListener('change', () => {
    const first = body.querySelector('select[name="line_account_id[]"]');
    const last = LAST[vendor.value];
    if (first && !first.value && last && ACCOUNTS.some(a => a.id === last)) window.finSetSelect(first, String(last));
  });

  if (INITIAL.length) INITIAL.forEach(addLine); else addLine();
  // A saved VAT that differs from rate x amount was typed to match the supplier: keep it.
  const net0 = Array.from(form.querySelectorAll('[name="line_amount[]"]')).reduce((t, e) => t + num(e.value), 0);
  vatTyped = vat.value !== '' && Math.abs(num(vat.value) - Math.round(net0 * rate()) / 100) > 0.004;
  totals();

  document.getElementById('vAdd').addEventListener('click', () => addLine());
  body.addEventListener('input', totals);
  vat.addEventListener('input', () => { vatTyped = vat.value.trim() !== ''; totals(); });
  form.querySelectorAll('[name="vat_rate"]').forEach(r => r.addEventListener('change', () => { vatTyped = false; totals(); }));
  form.addEventListener('submit', () => { document.getElementById('vSave').disabled = true; });
})();
</script>
</body>
</html>
