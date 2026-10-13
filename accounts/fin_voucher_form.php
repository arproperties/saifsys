<?php
/**
 * Finance > Vouchers: record a new voucher. Saving posts it to the ledger of the
 * company selected in the session. Opened through fin_journal_voucher_new.php,
 * fin_petty_cash_new.php, fin_payment_voucher_new.php or
 * fin_receipt_voucher_new.php, which set $finVoucherType.
 * fin_voucher_edit.php opens it on a saved voucher ($finEditVoucher) to change it.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_voucher_helper.php';

$type = (string)($finVoucherType ?? $_GET['type'] ?? 'jv');
if (!isset(FIN_VOUCHER_TYPES[$type])) {
    $type = 'jv';
}
$vt = FIN_VOUCHER_TYPES[$type];
$isJv = $type === 'jv';
$edit = $finEditVoucher ?? null;
$formAction = $edit ? 'fin_voucher_edit.php?id=' . (int)$edit['id'] : $vt['new'];

$tableOk = fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn);
$err = '';

$form = [
    'date' => date('Y-m-d'),
    'party' => '',
    'reference' => '',
    'narration' => '',
    'cash_account_id' => 0,
    'lines' => [],
];
if ($edit) {
    $form = fin_voucher_form_data($edit);
}

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $form['date'] = trim((string)($_POST['date'] ?? ''));
    $form['party'] = (string)($_POST['party'] ?? '');
    $form['reference'] = (string)($_POST['reference'] ?? '');
    $form['narration'] = (string)($_POST['narration'] ?? '');
    $form['cash_account_id'] = (int)($_POST['cash_account_id'] ?? 0);
    $form['lines'] = [];
    $acc = (array)($_POST['line_account_id'] ?? []);
    foreach ($acc as $i => $accountId) {
        $form['lines'][] = [
            'account_id'  => (int)$accountId,
            'description' => (string)($_POST['line_description'][$i] ?? ''),
            'partner'     => (string)($_POST['line_partner'][$i] ?? ''),
            'debit'       => (string)($_POST['line_debit'][$i] ?? ''),
            'credit'      => (string)($_POST['line_credit'][$i] ?? ''),
            'amount'      => (string)($_POST['line_amount'][$i] ?? ''),
        ];
    }
    try {
        if ($edit) {
            $id = (int)$edit['id'];
            $done = fin_voucher_update($conn, $companyId, $id, $form, $userId) ? 'Changes saved.' : 'Nothing was changed.';
        } else {
            $id = fin_voucher_create($conn, $companyId, $type, $form, $userId);
            $done = $vt['label'] . ' saved and posted.';
        }
        header('Location: fin_voucher_view.php?id=' . $id . '&msg=' . urlencode($done));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_voucher_form: ' . $e->getMessage());
        $err = 'The voucher could not be saved.';
    }
}

$accounts = ($tableOk && $company) ? fin_voucher_ledger_accounts($conn, $companyId) : [];
$partners = ($tableOk && $company) ? fin_partners($conn, $companyId) : [];
$cashAccounts = ($tableOk && $company && !$isJv) ? fin_voucher_cash_accounts($conn, $companyId, $type) : [];
if (!$isJv && !$form['cash_account_id'] && count($cashAccounts) === 1) {
    $form['cash_account_id'] = (int)$cashAccounts[0]['id'];
}

$pageTitle = $edit ? 'Edit ' . $vt['label'] . ' ' . $edit['voucher_no'] : 'New ' . $vt['label'];
$finActive = $type;
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
  .v-partner { flex: 1 1 240px; min-width: 0; }
  .v-account > label, .v-partner > label { display: block; font-size: .72rem; color: #6c757d; margin-bottom: .15rem; }
  .v-fields { flex: 2 1 480px; min-width: 0; display: grid; grid-template-columns: minmax(0, 1fr) 150px 150px; gap: .75rem; align-items: end; }
  .v-fields.v-fields-one { flex-basis: 380px; grid-template-columns: minmax(0, 1fr) 170px; }
  .v-fields label { display: block; font-size: .72rem; color: #6c757d; margin-bottom: .15rem; }
  .v-fields .v-amt-label { text-align: right; }
  @media (max-width: 767.98px) { .v-fields, .v-fields.v-fields-one { grid-template-columns: 1fr 1fr; } .v-fields > div:first-child { grid-column: 1 / -1; } }
  .v-totals { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem 1.75rem; padding: .85rem 1rem; border: 1px solid #dfe3e8; border-radius: .6rem; background: #f8f9fb; margin-bottom: 1rem; }
  .v-totals .v-total { text-align: right; }
  .v-totals .v-total span { display: block; font-size: .72rem; color: #6c757d; }
  .v-totals .v-total strong { font-size: 1.1rem; font-variant-numeric: tabular-nums; }
  #vBalance { font-size: .85rem; font-weight: 600; }
  body.dark-mode .v-line, body.dark-mode .v-totals { background: rgba(30,41,59,.95); border-color: #334155; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to record a voucher.</div>
  <?php elseif (!$accounts): ?>
    <div class="alert alert-info">This company has no active accounts yet. <a href="fin_chart_of_accounts.php">Set up the chart of accounts</a> first.</div>
  <?php else: ?>

  <form method="post" action="<?= h($formAction) ?>" id="vForm">
    <?php csrf_field(); ?>
    <div class="card mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-sm-6 col-lg-2">
            <label class="form-label small mb-1" for="vDate">Date</label>
            <input type="date" class="form-control" id="vDate" name="date" value="<?= h($form['date']) ?>" required>
          </div>
          <?php if (!$isJv): ?>
          <div class="col-sm-6 col-lg-4">
            <label class="form-label small mb-1" for="vCash"><?= h($vt['cash']) ?></label>
            <select class="form-select" id="vCash" name="cash_account_id" required>
              <option value="">Choose an account</option>
              <?php foreach ($cashAccounts as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === (int)$form['cash_account_id'] ? 'selected' : '' ?>><?= h($a['code'] . ' ' . $a['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-1" for="vParty"><?= h($vt['party']) ?></label>
            <input class="form-control" id="vParty" name="party" maxlength="150" value="<?= h($form['party']) ?>">
          </div>
          <?php endif; ?>
          <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-1" for="vRef">Reference</label>
            <input class="form-control" id="vRef" name="reference" maxlength="100" value="<?= h($form['reference']) ?>" placeholder="<?= $isJv ? 'Optional' : 'Cheque, transfer or bill no.' ?>">
          </div>
          <div class="<?= $isJv ? 'col-lg-7' : 'col-12' ?>">
            <label class="form-label small mb-1" for="vNarr">Narration</label>
            <input class="form-control" id="vNarr" name="narration" maxlength="255" value="<?= h($form['narration']) ?>">
          </div>
        </div>
      </div>
    </div>

    <h6 class="text-muted text-uppercase small fw-bold mb-2"><?= $isJv ? 'Lines' : ($type === 'rv' ? 'Received for' : 'Paid for') ?></h6>
    <div id="vLines"></div>

    <div class="v-totals">
      <button type="button" class="btn btn-sm btn-outline-primary" id="vAdd"><i class="bi bi-plus-lg"></i> Add line</button>
      <?php if ($isJv): ?>
      <div id="vBalance" class="me-auto"></div>
      <div class="v-total"><span>Total debit (AED)</span><strong id="vTotalDebit">0.00</strong></div>
      <div class="v-total"><span>Total credit (AED)</span><strong id="vTotalCredit">0.00</strong></div>
      <?php else: ?>
      <div class="me-auto"></div>
      <div class="v-total"><span>Total (AED)</span><strong id="vTotal">0.00</strong></div>
      <?php endif; ?>
    </div>

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
  const IS_JV = <?= json_encode($isJv) ?>;
  // The ledger accounts a line can post to.
  const ACCOUNTS = <?= json_encode(array_map(static fn($a) => ['id' => (int)$a['id'], 'label' => $a['code'] . ' ' . $a['name']], $accounts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  // The tenants and suppliers a line can belong to.
  const PARTNERS = <?= json_encode(array_map(static fn($p) => ['key' => $p['key'], 'kind' => $p['kind'], 'name' => $p['name']], array_values($partners)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const PARTNER_KINDS = <?= json_encode(FIN_PARTNER_KINDS) ?>;
  const INITIAL = <?= json_encode($form['lines'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const body = document.getElementById('vLines');
  const cash = document.getElementById('vCash');

  const num = v => { const n = parseFloat(String(v).replace(/[,\s]/g, '')); return isFinite(n) ? n : 0; };
  const fmt = n => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  // A labelled text box: returns the wrapper and the input.
  function field(labelText, name, value, cls) {
    const wrap = el('div');
    const label = el('label', cls ? 'v-amt-label' : '', labelText);
    const inp = el('input', 'form-control ' + (cls || ''));
    inp.name = name;
    inp.value = value || '';
    wrap.appendChild(label);
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

    // Account: one searchable dropdown of the ledger accounts.
    const row = el('div', 'v-row');
    const acc = el('div', 'v-account');
    const sel = el('select', 'form-select');
    sel.name = 'line_account_id[]';
    sel.appendChild(new Option('Choose a ledger account', ''));
    ACCOUNTS.forEach(a => sel.appendChild(new Option(a.label, a.id)));
    if (data.account_id) sel.value = String(data.account_id);
    acc.appendChild(el('label', '', 'Ledger account'));
    acc.appendChild(sel);
    row.appendChild(acc);
    tr.appendChild(row);

    // Partner: whose line this is, so the tenant's or supplier's balance can be told apart.
    if (PARTNERS.length) {
      const par = el('div', 'v-partner');
      const psel = el('select', 'form-select');
      psel.name = 'line_partner[]';
      psel.appendChild(new Option('No tenant or supplier', ''));
      Object.keys(PARTNER_KINDS).forEach(kind => {
        const group = document.createElement('optgroup');
        group.label = PARTNER_KINDS[kind];
        PARTNERS.filter(p => p.kind === kind).forEach(p => group.appendChild(new Option(p.name, p.key)));
        if (group.children.length) psel.appendChild(group);
      });
      if (data.partner) psel.value = data.partner;
      par.appendChild(el('label', '', 'Tenant / supplier'));
      par.appendChild(psel);
      row.appendChild(par);
    }
    // The account the money moves through cannot also be a line.
    tr.finSyncCash = () => {
      if (!cash) return;
      Array.from(sel.options).forEach(o => { o.disabled = o.value !== '' && o.value === cash.value; });
      if (sel.value && sel.value === cash.value) window.finSetSelect(sel, '');
    };
    tr.finSyncCash();

    const fields = el('div', 'v-fields' + (IS_JV ? '' : ' v-fields-one'));
    const [descWrap, desc] = field('Description', 'line_description[]', data.description);
    desc.maxLength = 255;
    desc.placeholder = 'Optional';
    fields.appendChild(descWrap);
    if (IS_JV) {
      const [dWrap, d] = field('Debit (AED)', 'line_debit[]', data.debit, 'v-amt');
      const [cWrap, c] = field('Credit (AED)', 'line_credit[]', data.credit, 'v-amt');
      d.inputMode = c.inputMode = 'decimal';
      d.placeholder = c.placeholder = '0.00';
      // A line is a debit or a credit: typing in one side clears the other.
      d.addEventListener('input', () => { if (num(d.value) > 0) c.value = ''; });
      c.addEventListener('input', () => { if (num(c.value) > 0) d.value = ''; });
      fields.appendChild(dWrap);
      fields.appendChild(cWrap);
    } else {
      const [aWrap, a] = field('Amount (AED)', 'line_amount[]', data.amount, 'v-amt');
      a.inputMode = 'decimal';
      a.placeholder = '0.00';
      fields.appendChild(aWrap);
    }
    row.appendChild(fields);

    body.appendChild(tr);
    renumber();
    window.finSearchable(row);
  }

  function sum(name) {
    return Array.from(form.querySelectorAll('[name="' + name + '"]')).reduce((t, el) => t + num(el.value), 0);
  }

  function totals() {
    if (IS_JV) {
      const d = sum('line_debit[]'), c = sum('line_credit[]');
      document.getElementById('vTotalDebit').textContent = fmt(d);
      document.getElementById('vTotalCredit').textContent = fmt(c);
      const diff = Math.round((d - c) * 100) / 100;
      const bal = document.getElementById('vBalance');
      if (d === 0 && c === 0) {
        bal.className = 'me-auto text-muted';
        bal.textContent = 'Debits and credits must be equal.';
      } else if (diff === 0) {
        bal.className = 'me-auto text-success';
        bal.innerHTML = '<i class="bi bi-check-circle-fill"></i> Balanced';
      } else {
        bal.className = 'me-auto text-danger';
        bal.textContent = 'Out of balance by ' + fmt(Math.abs(diff)) + ' AED (' + (diff > 0 ? 'debits are higher' : 'credits are higher') + ').';
      }
    } else {
      document.getElementById('vTotal').textContent = fmt(sum('line_amount[]'));
    }
  }

  function syncCash() {
    Array.from(body.children).forEach(tr => tr.finSyncCash());
  }

  if (INITIAL.length) INITIAL.forEach(addLine); else { addLine(); if (IS_JV) addLine(); }
  totals();

  document.getElementById('vAdd').addEventListener('click', () => addLine());
  body.addEventListener('input', totals);
  if (cash) cash.addEventListener('change', syncCash);
  form.addEventListener('submit', () => {
    document.getElementById('vSave').disabled = true;
  });
})();
</script>
</body>
</html>
