(function(){
  const root = document.getElementById('coBankRecoApp');
  if (!root) return;

  const CSRF = root.dataset.csrf || '';
  const ajaxBase = root.dataset.ajaxBase || 'ajax/';
  const bankId = () => root.dataset.bankId;
  const dateFrom = () => document.getElementById('dFrom')?.value || '';
  const dateTo = () => document.getElementById('dTo')?.value || '';

  let lines = [];
  let selectedLineId = null;

  function esc(s){
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function fd(extra){
    const p = new URLSearchParams(Object.assign({_csrf: CSRF}, extra || {}));
    return p;
  }

  function badge(status){
    const map = {unreconciled:'Unreconciled',suggested:'Suggested',reconciled:'Reconciled',discussed:'Discussed',ignored:'Ignored'};
    return '<span class="co-breco-badge ' + esc(status) + '">' + esc(map[status] || status) + '</span>';
  }

  function fmtAmt(n, spent){
    const v = Number(n).toFixed(2);
    return spent ? ('-' + v) : v;
  }

  async function loadBalances(){
    const r = await fetch(ajaxBase + 'bank_reco_balances.php?bank_account_id=' + encodeURIComponent(bankId()) + '&as_of=' + encodeURIComponent(dateTo()));
    const j = await r.json();
    if (!j.success) return;
    const stmt = j.statement_balance != null ? Number(j.statement_balance).toFixed(2) : '—';
    const erp = Number(j.erp_balance).toFixed(2);
    const diff = j.difference != null ? Number(j.difference).toFixed(2) : '—';
    document.getElementById('hdrStmtBal').textContent = stmt;
    document.getElementById('hdrErpBal').textContent = erp;
    const diffEl = document.getElementById('hdrDiff');
    diffEl.textContent = diff;
    diffEl.closest('.co-breco-balance-item').classList.toggle('diff-ok', j.difference != null && Math.abs(j.difference) < 0.02);
    diffEl.closest('.co-breco-balance-item').classList.toggle('diff-warning', j.difference != null && Math.abs(j.difference) >= 0.02);
    document.getElementById('hdrUnrecCount').textContent = String(j.unreconciled_count || 0);
  }

  async function loadLines(){
    const r = await fetch(ajaxBase + 'bank_reco_list_statement.php?bank_account_id=' + encodeURIComponent(bankId()) + '&date_from=' + encodeURIComponent(dateFrom()) + '&date_to=' + encodeURIComponent(dateTo()) + '&status=open');
    const j = await r.json();
    if (!j.success) { alert(j.error || 'Failed to load lines'); return; }
    lines = j.lines || [];
    renderLines();
    if (!selectedLineId) {
      const next = lines.find(l => !l.is_fully_matched && l.status !== 'reconciled');
      if (next) selectLine(Number(next.id));
    }
    document.getElementById('linesCount').textContent = lines.length + ' lines';
  }

  function renderLines(){
    const box = document.getElementById('linesList');
    box.innerHTML = '';
    if (!lines.length) {
      box.innerHTML = '<div class="co-breco-empty">No statement lines in this period. <a href="bank_reconciliation_import.php?bank_account_id=' + encodeURIComponent(bankId()) + '">Import a statement</a>.</div>';
      return;
    }
    lines.forEach(function(l){
      const spent = Number(l.amount) < 0;
      const div = document.createElement('div');
      div.className = 'co-breco-line' + (Number(l.id) === selectedLineId ? ' active' : '') + (l.is_fully_matched ? ' reconciled' : '');
      div.dataset.lineId = l.id;
      const status = l.status || (l.is_fully_matched ? 'reconciled' : 'unreconciled');
      div.innerHTML =
        '<div class="co-breco-line-top">' +
          '<div><div class="co-breco-line-desc">' + esc(l.description || '(No description)') + '</div>' +
          '<div class="co-breco-line-meta">' + esc(l.txn_date) + (l.reference ? ' · ' + esc(l.reference) : '') + '</div></div>' +
          '<div class="text-end"><div class="co-breco-amt ' + (spent ? 'spent' : 'received') + '">' + fmtAmt(Math.abs(l.amount), spent) + '</div>' +
          badge(status) + '</div>' +
        '</div>';
      div.addEventListener('click', function(){ selectLine(Number(l.id)); });
      box.appendChild(div);
    });
  }

  async function selectLine(id){
    selectedLineId = id;
    renderLines();
    await loadLineDetail(id);
  }

  async function loadLineDetail(id){
    const panel = document.getElementById('panelBody');
    panel.innerHTML = '<div class="co-breco-empty">Loading…</div>';
    try {
      const r = await fetch(ajaxBase + 'bank_reco_line_detail.php?line_id=' + encodeURIComponent(id));
      const text = await r.text();
      let j;
      try { j = JSON.parse(text); } catch (e) {
        panel.innerHTML = '<div class="alert alert-danger">Server error loading line details. Check PHP error log.</div>';
        return;
      }
      if (!j.success) { panel.innerHTML = '<div class="alert alert-danger">' + esc(j.error || 'Failed') + '</div>'; return; }
      renderPanel(j);
    } catch (e) {
      panel.innerHTML = '<div class="alert alert-danger">Network error: ' + esc(e.message) + '</div>';
    }
  }

  function renderPanel(data){
    const line = data.line;
    const spent = line.is_spent;
    const tab = document.querySelector('#actionTabs .nav-link.active')?.getAttribute('data-tab') || 'match';
    let html = '<div class="mb-2"><strong>' + esc(line.description || 'Statement line') + '</strong>' +
      '<div class="small text-muted">' + esc(line.txn_date) + ' · Remaining ' + Number(line.remaining).toFixed(2) + '</div></div>';

    if (tab === 'match') {
      const s = data.primary_suggestion;
      if (s) {
        const cls = s.confidence === 'High' ? '' : ' medium';
        html += '<div class="co-breco-suggest-card' + cls + '">' +
          '<div class="d-flex justify-content-between align-items-start gap-2">' +
            '<div><div class="fw-semibold">' + esc(s.label) + '</div>' +
            '<div class="small text-muted">' + esc(s.txn_date) + ' · ' + Number(s.amount).toFixed(2) + '</div></div>' +
            '<span class="badge bg-success">' + esc(s.confidence) + '</span>' +
          '</div>' +
          '<div class="reasons mt-2">' + (s.reasons || []).map(r => '<span>' + esc(r) + '</span>').join('') + '</div>' +
          '<button type="button" class="btn btn-success btn-sm mt-3" id="btnOkMatch">OK — Reconcile</button>' +
          (data.alternative_count > 0 ? '<div class="small text-muted mt-2">' + data.alternative_count + ' other possible matches — try <a href="#" class="co-breco-goto-tab" data-tab="find">Find &amp; Match</a></div>' : '') +
        '</div>';
      } else {
        html += '<div class="alert alert-light border">No confident match found. Use <strong>Create</strong> for a new transaction or <strong>Discuss</strong> to hold this line.</div>';
      }
      if (data.alternatives && data.alternatives.length) {
        html += '<div class="small text-muted mb-1">Other suggestions</div><ul class="list-group list-group-flush">';
        data.alternatives.forEach(function(a){
          html += '<li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">' +
            '<span>' + esc(a.label) + ' <span class="badge bg-secondary">' + esc(a.confidence) + '</span></span>' +
            '<button type="button" class="btn btn-outline-success btn-sm btn-alt-match" data-type="' + esc(a.system_type) + '" data-id="' + a.system_id + '">OK</button></li>';
        });
        html += '</ul>';
      }

      const candidates = data.gl_candidates || [];
      html += '<div class="mt-3"><div class="small fw-semibold mb-2">ERP bank transactions (unreconciled)</div>';
      if (!candidates.length) {
        html += '<div class="alert alert-light border small mb-0">No unreconciled GL entries found for this bank account near this date. Use the <strong>Create</strong> tab to post a new transaction, then it will reconcile automatically.</div>';
      } else {
        html += '<div class="table-responsive" style="max-height:280px;overflow:auto"><table class="table table-sm table-hover mb-0">' +
          '<thead class="table-light sticky-top"><tr><th>Date</th><th class="text-end">Amount</th><th>Description</th><th></th></tr></thead><tbody>';
        candidates.forEach(function(c){
          html += '<tr><td>' + esc(c.txn_date) + '</td><td class="text-end">' + Number(c.amount).toFixed(2) +
            (c.amount_diff > 0.02 ? ' <span class="text-warning small">(diff ' + Number(c.amount_diff).toFixed(2) + ')</span>' : '') +
            '</td><td><div>' + esc(c.label) + '</div><div class="small text-muted">' + esc(c.reference || '') + '</div></td>' +
            '<td class="text-end"><button type="button" class="btn btn-outline-success btn-sm btn-alt-match" data-type="' + esc(c.system_type) + '" data-id="' + c.system_id + '">Match</button></td></tr>';
        });
        html += '</tbody></table></div>';
        html += '<div class="small text-muted mt-2">Pick the ERP transaction that corresponds to this bank line, then click Match. Amounts must match (or be very close).</div>';
      }
      html += '</div>';
    } else if (tab === 'create') {
      if (data.rule_suggestion) {
        html += '<div class="alert alert-info py-2 small">Bank rule: <strong>' + esc(data.rule_suggestion.rule_name) + '</strong> — fields pre-filled below. ' +
          '<a href="bank_reconciliation_rules.php?line_id=' + encodeURIComponent(data.line.id) + '">Edit rule</a></div>';
      }
      html += renderCreateForm(line, data.rule_suggestion ? data.rule_suggestion.prefill : null);
    } else if (tab === 'discuss') {
      html += renderDiscussForm(line, data.notes || []);
    } else if (tab === 'transfer') {
      html += renderTransferForm(line);
    } else if (tab === 'find') {
      html += renderFindMatchPanel(line);
    } else {
      html += '<div class="alert alert-secondary">Select an action tab.</div>';
    }

    document.getElementById('panelBody').innerHTML = html;
    bindPanelActions(data);
  }

  function buildContactOptions(line, selectedValue){
    const c = window.CO_BRECO_CONTACTS || {clients:[], suppliers:[], contractors:[]};
    const groups = [
      {key:'clients', type:'client', label:'Clients'},
      {key:'suppliers', type:'supplier', label:'Suppliers'},
      {key:'contractors', type:'contractor', label:'Contractors'}
    ];
    let html = '<option value="">— Optional —</option>';
    groups.forEach(function(g){
      const items = c[g.key] || [];
      if (!items.length) return;
      html += '<optgroup label="' + esc(g.label) + '">';
      items.forEach(function(item){
        const val = g.type + ':' + item.id;
        html += '<option value="' + val + '"' + (val === selectedValue ? ' selected' : '') + '>' + esc(item.name) + '</option>';
      });
      html += '</optgroup>';
    });
    return html;
  }

  function guessContactFromLine(line){
    const text = ((line.description || '') + ' ' + (line.reference || '')).trim().toLowerCase();
    if (text.length < 3) return '';
    const c = window.CO_BRECO_CONTACTS || {clients:[], suppliers:[], contractors:[]};
    const preferClients = !line.is_spent;
    const order = preferClients
      ? [['clients','client'],['suppliers','supplier'],['contractors','contractor']]
      : [['suppliers','supplier'],['contractors','contractor'],['clients','client']];
    let best = null;
    order.forEach(function(pair){
      (c[pair[0]] || []).forEach(function(item){
        const name = String(item.name || '').trim().toLowerCase();
        if (name.length < 3) return;
        let score = 0;
        if (text === name) score = 100;
        else if (text.indexOf(name) >= 0) score = 80 + Math.min(15, name.length / 3);
        else if (name.indexOf(text) >= 0) score = 60;
        if (score >= 55 && (!best || score > best.score)) {
          best = {value: pair[1] + ':' + item.id, score: score};
        }
      });
    });
    return best ? best.value : '';
  }

  function accountOptions(selectedId){
    return '<option value="">— Select —</option>' + (window.CO_BRECO_ACCOUNTS || []).map(function(a){
      return '<option value="' + a.id + '"' + (String(a.id) === String(selectedId || '') ? ' selected' : '') + '>' + esc(a.account_code + ' — ' + a.account_name) + '</option>';
    }).join('');
  }

  function projectOptions(selectedId){
    return '<option value="">— Optional —</option>' + (window.CO_BRECO_PROJECTS || []).map(function(p){
      return '<option value="' + p.id + '"' + (String(p.id) === String(selectedId || '') ? ' selected' : '') + '>' + esc(p.project_code + ' — ' + p.project_name) + '</option>';
    }).join('');
  }

  function vatConfig(){
    return window.CO_BRECO_VAT || { default_rate: 5, options: [] };
  }

  function vatOptionsHtml(selected){
    const cfg = vatConfig();
    const opts = cfg.options && cfg.options.length ? cfg.options : [
      {value:'none', label:'No VAT'},
      {value:'standard', label:(cfg.default_rate || 5) + '% Standard VAT'},
      {value:'zero_rated', label:'Zero rated (0%)'},
      {value:'exempt', label:'Exempt'},
      {value:'out_of_scope', label:'Out of scope'}
    ];
    return opts.map(function(o){
      return '<option value="' + o.value + '"' + (String(o.value) === String(selected || 'none') ? ' selected' : '') + '>' + esc(o.label) + '</option>';
    }).join('');
  }

  function calcVatBreakdown(gross, treatment){
    const rate = Number(vatConfig().default_rate || 5);
    gross = Number(gross) || 0;
    if (treatment !== 'standard' || gross <= 0) {
      return { net: gross, vat: 0, gross: gross };
    }
    const vat = Math.round(gross * rate / (100 + rate) * 100) / 100;
    const net = Math.round((gross - vat) * 100) / 100;
    return { net: net, vat: vat, gross: gross };
  }

  function vatBreakdownHtml(gross, treatment){
    if (treatment !== 'standard') {
      return '<div class="form-text text-muted">No VAT split — full bank amount posts to the selected account.</div>';
    }
    const b = calcVatBreakdown(gross, treatment);
    return '<div class="small text-muted border rounded px-2 py-1 mt-1">' +
      'Net: <strong>' + b.net.toFixed(2) + '</strong> · VAT: <strong>' + b.vat.toFixed(2) + '</strong> · ' +
      'Bank total: <strong>' + b.gross.toFixed(2) + '</strong> (tax inclusive)</div>';
  }

  function renderCreateForm(line, prefill){
    prefill = prefill || null;
    const defaultType = prefill && prefill.transaction_type
      ? prefill.transaction_type
      : (line.is_received ? 'client_receipt' : 'quick_expense');
    const guessedContact = (prefill && prefill.contact) ? prefill.contact : guessContactFromLine(line);
    const contactOpts = buildContactOptions(line, guessedContact);
    const descVal = (prefill && prefill.description) ? prefill.description : (line.description || '');
    const refVal = (prefill && prefill.reference) ? prefill.reference : (line.reference || '');
    const defaultVat = (prefill && prefill.vat_treatment) ? prefill.vat_treatment : 'none';
    return '<form id="createForm" class="row g-2">' +
      '<div class="col-12 d-flex justify-content-end"><a href="bank_reconciliation_rules.php?line_id=' + encodeURIComponent(line.id) + '" class="small">Create rule from this line</a></div>' +
      '<div class="col-12"><label class="form-label small">Type</label><select name="transaction_type" class="form-select form-select-sm">' +
        '<option value="bank_charge"' + (defaultType === 'bank_charge' ? ' selected' : '') + '>Bank Charge</option>' +
        '<option value="direct_project_expense"' + (defaultType === 'direct_project_expense' ? ' selected' : '') + '>Direct Project Expense</option>' +
        '<option value="quick_expense"' + (defaultType === 'quick_expense' ? ' selected' : '') + '>Quick Expense / Adjustment</option>' +
        '<option value="cash_withdrawal"' + (defaultType === 'cash_withdrawal' ? ' selected' : '') + '>Cash Withdrawal / Petty Cash Funding</option>' +
        '<option value="client_receipt"' + (defaultType === 'client_receipt' ? ' selected' : '') + '>Client Receipt / Income</option>' +
      '</select></div>' +
      '<div class="col-12"><label class="form-label small">Who (Contact)</label><select name="contact" class="form-select form-select-sm">' + contactOpts + '</select>' +
        (guessedContact ? '<div class="form-text">Suggested from statement description</div>' : '') +
      '</div>' +
      '<div class="col-12"><label class="form-label small">What (Account)</label><select name="account_id" class="form-select form-select-sm">' + accountOptions(prefill ? prefill.account_id : '') + '</select></div>' +
      '<div class="col-12"><label class="form-label small">VAT / Tax</label><select name="vat_treatment" class="form-select form-select-sm create-vat-sel">' + vatOptionsHtml(defaultVat) + '</select>' +
        '<input type="hidden" name="vat_rate" value="' + esc(String(vatConfig().default_rate || 5)) + '">' +
        '<div id="createVatBreakdown">' + vatBreakdownHtml(line.abs_amount, defaultVat) + '</div></div>' +
      '<div class="col-12"><label class="form-label small">Why (Description / Memo)</label><input type="text" name="description" class="form-control form-control-sm" value="' + esc(descVal) + '"></div>' +
      '<div class="col-12"><label class="form-label small">Project</label><select name="project_id" class="form-select form-select-sm">' + projectOptions(prefill ? prefill.project_id : '') + '</select></div>' +
      '<div class="col-md-6"><label class="form-label small">Reference</label><input type="text" name="reference" class="form-control form-control-sm" value="' + esc(refVal) + '"></div>' +
      '<div class="col-md-6"><label class="form-label small">Amount</label><input type="text" class="form-control form-control-sm" value="' + Number(line.abs_amount).toFixed(2) + '" readonly></div>' +
      '<div class="col-12"><button type="submit" class="btn btn-primary btn-sm">OK — Create & Reconcile</button></div>' +
    '</form>';
  }

  function renderDiscussForm(line, notes){
    let html = '<form id="discussForm" class="mb-3"><label class="form-label small">Note</label>' +
      '<textarea name="note" class="form-control form-control-sm" rows="3" required placeholder="Discuss with accountant…"></textarea>' +
      '<div class="row g-2 mt-2"><div class="col-md-4"><label class="form-check"><input type="checkbox" name="need_invoice" value="1" class="form-check-input"> Need invoice</label></div>' +
      '<div class="col-md-4"><label class="form-check"><input type="checkbox" name="need_approval" value="1" class="form-check-input"> Need approval</label></div>' +
      '<div class="col-md-4"><label class="form-check"><input type="checkbox" name="need_vendor_confirmation" value="1" class="form-check-input"> Need vendor confirmation</label></div>' +
      '<div class="col-md-6"><label class="form-label small">Follow-up date</label><input type="date" name="follow_up_date" class="form-control form-control-sm"></div>' +
      '<div class="col-12"><button type="submit" class="btn btn-outline-secondary btn-sm">Save discussion</button></div></form>';
    if (notes.length) {
      html += '<div class="small fw-semibold mb-2">History</div>';
      notes.forEach(function(n){
        html += '<div class="co-breco-note"><div>' + esc(n.note) + '</div><div class="meta">' + esc(n.created_at) + (n.author_name ? ' · ' + esc(n.author_name) : '') + '</div></div>';
      });
    } else {
      html += '<div class="text-muted small">No discussion yet.</div>';
    }
    return html;
  }

  function renderTransferForm(line){
    const banks = (window.CO_BRECO_BANKS || []).filter(b => String(b.id) !== String(bankId()));
    const opts = banks.map(b => '<option value="' + b.id + '">' + esc(b.label) + '</option>').join('');
    return '<form id="transferForm" class="row g-2">' +
      '<div class="col-12"><div class="co-breco-transfer-note">Record a transfer between bank accounts. If the opposite statement line exists in the destination account, it will be matched automatically.</div></div>' +
      '<div class="col-12"><label class="form-label small">To bank account</label><select name="to_bank_account_id" class="form-select form-select-sm" required><option value="">— Select —</option>' + opts + '</select></div>' +
      '<div class="col-md-6"><label class="form-label small">Reference</label><input type="text" name="reference" class="form-control form-control-sm" value="' + esc(line.reference || '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label small">Amount</label><input type="text" class="form-control form-control-sm" value="' + Number(line.abs_amount).toFixed(2) + '" readonly></div>' +
      '<div class="col-12"><label class="form-label small">Description</label><input type="text" name="description" class="form-control form-control-sm" value="' + esc(line.description || 'Bank transfer') + '"></div>' +
      '<div class="col-12"><button type="submit" class="btn btn-primary btn-sm">OK — Transfer &amp; Reconcile</button></div>' +
    '</form>';
  }

  function renderFindMatchPanel(line){
    const df = dateFrom() || line.txn_date;
    const dt = dateTo() || line.txn_date;
    return '<div id="findMatchRoot">' +
      '<div class="co-breco-find-filters row g-2">' +
        '<div class="col-md-4"><label class="form-label small">From</label><input type="date" id="findFrom" class="form-control form-control-sm" value="' + esc(df) + '"></div>' +
        '<div class="col-md-4"><label class="form-label small">To</label><input type="date" id="findTo" class="form-control form-control-sm" value="' + esc(dt) + '"></div>' +
        '<div class="col-md-4"><label class="form-label small">Amount</label><input type="text" id="findAmount" class="form-control form-control-sm" placeholder="Exact amount"></div>' +
        '<div class="col-md-4"><label class="form-label small">Reference</label><input type="text" id="findRef" class="form-control form-control-sm"></div>' +
        '<div class="col-md-4"><label class="form-label small">Keyword</label><input type="text" id="findKeyword" class="form-control form-control-sm" placeholder="Description"></div>' +
        '<div class="col-md-4"><label class="form-label small">Type</label><select id="findType" class="form-select form-select-sm"><option value="">All</option><option value="co_client_payment">Client receipt</option><option value="co_supplier_payment">Supplier payment</option><option value="co_contractor_payment">Contractor payment</option></select></div>' +
        '<div class="col-12"><label class="form-check small"><input type="checkbox" id="findUnrec" class="form-check-input" checked> Unreconciled only</label></div>' +
        '<div class="col-12"><button type="button" class="btn btn-outline-primary btn-sm" id="btnFindSearch">Search</button></div>' +
      '</div>' +
      '<div id="findResults"><div class="co-breco-empty">Click Search to find ERP transactions.</div></div>' +
    '</div>';
  }

  function renderFindResults(line, results){
    const target = Number(line.remaining).toFixed(2);
    let html = '<div class="co-breco-find-total warn" id="findTotalBar">' +
      '<span>Selected <strong id="findSelectedAmt">0.00</strong> of <strong>' + target + '</strong></span>' +
      '<span id="findTotalStatus" class="small text-muted">Select transactions below</span></div>';
    if (!results.length) {
      html += '<div class="alert alert-light border small">No transactions found. Widen the date range or clear filters.</div>';
      return html;
    }
    html += '<div class="table-responsive" style="max-height:240px;overflow:auto"><table class="table table-sm table-hover mb-0">' +
      '<thead class="table-light sticky-top"><tr><th></th><th>Date</th><th class="text-end">Remaining</th><th>Description</th></tr></thead><tbody>';
    results.forEach(function(c){
      html += '<tr><td><input type="checkbox" class="form-check-input find-pick" data-type="' + esc(c.system_type) + '" data-id="' + c.system_id + '" data-amt="' + c.remaining + '"></td>' +
        '<td>' + esc(c.txn_date) + '</td><td class="text-end">' + Number(c.remaining).toFixed(2) + '</td>' +
        '<td><div>' + esc(c.label) + '</div><div class="small text-muted">' + esc(c.transaction_type || '') + '</div></td></tr>';
    });
    html += '</tbody></table></div>';
    html += '<div id="findAdjustWrap" class="row g-2 mt-2 d-none">' +
      '<div class="col-md-4"><label class="form-label small">Adjustment amount</label><input type="text" id="findAdjAmt" class="form-control form-control-sm" readonly></div>' +
      '<div class="col-md-8"><label class="form-label small">Adjustment account</label><select id="findAdjAcct" class="form-select form-select-sm"><option value="">— Select —</option>' +
      (window.CO_BRECO_ACCOUNTS || []).map(a => '<option value="' + a.id + '">' + esc(a.account_code + ' — ' + a.account_name) + '</option>').join('') +
      '</select></div></div>';
    html += '<button type="button" class="btn btn-success btn-sm mt-2" id="btnFindReconcile" disabled>OK — Reconcile selected</button>';
    return html;
  }

  function updateFindTotals(line){
    const picks = document.querySelectorAll('.find-pick:checked');
    let total = 0;
    picks.forEach(function(el){ total += Number(el.getAttribute('data-amt') || 0); });
    total = Math.round(total * 100) / 100;
    const target = Math.round(Number(line.remaining) * 100) / 100;
    const diff = Math.round((target - total) * 100) / 100;
    const bar = document.getElementById('findTotalBar');
    const status = document.getElementById('findTotalStatus');
    const btn = document.getElementById('btnFindReconcile');
    const adjWrap = document.getElementById('findAdjustWrap');
    const adjAmt = document.getElementById('findAdjAmt');
    document.getElementById('findSelectedAmt').textContent = total.toFixed(2);
    if (Math.abs(diff) < 0.02 && total > 0) {
      bar.classList.add('ready'); bar.classList.remove('warn');
      status.textContent = 'Ready to reconcile';
      if (btn) btn.disabled = false;
      if (adjWrap) adjWrap.classList.add('d-none');
    } else if (diff > 0 && diff <= 5 && total > 0) {
      bar.classList.remove('ready'); bar.classList.add('warn');
      status.textContent = 'Add adjustment of ' + diff.toFixed(2);
      if (adjWrap) { adjWrap.classList.remove('d-none'); if (adjAmt) adjAmt.value = diff.toFixed(2); }
      if (btn) btn.disabled = !document.getElementById('findAdjAcct')?.value;
    } else {
      bar.classList.remove('ready'); bar.classList.add('warn');
      status.textContent = diff > 0 ? ('Need ' + diff.toFixed(2) + ' more') : (total > target ? 'Selected exceeds bank line' : 'Select transactions below');
      if (btn) btn.disabled = true;
      if (adjWrap) adjWrap.classList.add('d-none');
    }
  }

  async function runFindSearch(line){
    const box = document.getElementById('findResults');
    box.innerHTML = '<div class="co-breco-empty">Searching…</div>';
    const q = new URLSearchParams({
      line_id: String(line.id),
      date_from: document.getElementById('findFrom')?.value || '',
      date_to: document.getElementById('findTo')?.value || '',
      amount: document.getElementById('findAmount')?.value || '',
      reference: document.getElementById('findRef')?.value || '',
      keyword: document.getElementById('findKeyword')?.value || '',
      transaction_type: document.getElementById('findType')?.value || '',
    });
    if (document.getElementById('findUnrec')?.checked) q.append('unreconciled_only', '1');
    const r = await fetch(ajaxBase + 'bank_reco_find_match.php?' + q.toString());
    const j = await r.json();
    if (!j.success) { box.innerHTML = '<div class="alert alert-danger">' + esc(j.error || 'Search failed') + '</div>'; return; }
    box.innerHTML = renderFindResults(line, j.results || []);
    bindFindMatchActions(line);
  }

  function bindFindMatchActions(line){
    document.querySelectorAll('.find-pick').forEach(function(el){
      el.addEventListener('change', function(){ updateFindTotals(line); });
    });
    document.getElementById('findAdjAcct')?.addEventListener('change', function(){ updateFindTotals(line); });
    document.getElementById('btnFindReconcile')?.addEventListener('click', async function(){
      const selections = [];
      document.querySelectorAll('.find-pick:checked').forEach(function(el){
        selections.push({
          system_type: el.getAttribute('data-type'),
          system_id: Number(el.getAttribute('data-id')),
          amount: Number(el.getAttribute('data-amt')),
        });
      });
      let adjustment = null;
      const adjAmt = Number(document.getElementById('findAdjAmt')?.value || 0);
      const adjAcct = Number(document.getElementById('findAdjAcct')?.value || 0);
      if (adjAmt > 0 && adjAcct > 0) {
        adjustment = { amount: adjAmt, account_id: adjAcct, type: 'rounding', description: 'Find & Match adjustment' };
      }
      const p = fd();
      p.append('line_id', String(line.id));
      p.append('selections', JSON.stringify(selections));
      p.append('adjustment', adjustment ? JSON.stringify(adjustment) : '');
      const res = await fetch(ajaxBase + 'bank_reco_match_selections.php', {method:'POST', body:p});
      const j = await res.json();
      if (!j.success) { alert(j.error || 'Reconcile failed'); return; }
      await afterReconcile(line.id);
    });
  }

  function bindPanelActions(data){
    const ok = document.getElementById('btnOkMatch');
    if (ok && data.primary_suggestion) {
      ok.addEventListener('click', function(){
        reconcileMatch(data.line.id, data.primary_suggestion.system_type, data.primary_suggestion.system_id);
      });
    }
    document.querySelectorAll('.btn-alt-match').forEach(function(btn){
      btn.addEventListener('click', function(){
        reconcileMatch(data.line.id, btn.getAttribute('data-type'), Number(btn.getAttribute('data-id')));
      });
    });
    const createForm = document.getElementById('createForm');
    if (createForm) {
      createForm.addEventListener('submit', async function(ev){
        ev.preventDefault();
        const p = fd();
        p.append('line_id', String(data.line.id));
        ['transaction_type','account_id','project_id','description','reference','contact','vat_treatment','vat_rate'].forEach(function(name){
          const el = createForm.querySelector('[name="' + name + '"]');
          if (el) p.append(name, el.value);
        });
        const res = await fetch(ajaxBase + 'bank_reco_create.php', {method:'POST', body:p});
        const j = await res.json();
        if (!j.success) { alert(j.error || 'Create failed'); return; }
        await afterReconcile(data.line.id);
      });
      const vatSel = createForm.querySelector('[name=vat_treatment]');
      if (vatSel) {
        vatSel.addEventListener('change', function(){
          const div = document.getElementById('createVatBreakdown');
          if (div) div.innerHTML = vatBreakdownHtml(data.line.abs_amount, vatSel.value);
        });
      }
    }
    const discussForm = document.getElementById('discussForm');
    if (discussForm) {
      discussForm.addEventListener('submit', async function(ev){
        ev.preventDefault();
        const p = fd();
        p.append('line_id', String(data.line.id));
        p.append('note', discussForm.querySelector('[name=note]').value);
        ['need_invoice','need_approval','need_vendor_confirmation'].forEach(function(name){
          const el = discussForm.querySelector('[name="' + name + '"]');
          if (el && el.checked) p.append(name, '1');
        });
        const fu = discussForm.querySelector('[name=follow_up_date]');
        if (fu && fu.value) p.append('follow_up_date', fu.value);
        const res = await fetch(ajaxBase + 'bank_reco_discuss.php', {method:'POST', body:p});
        const j = await res.json();
        if (!j.success) { alert(j.error || 'Save failed'); return; }
        await loadLines();
        await loadLineDetail(data.line.id);
      });
    }
    const transferForm = document.getElementById('transferForm');
    if (transferForm) {
      transferForm.addEventListener('submit', async function(ev){
        ev.preventDefault();
        const p = fd();
        p.append('line_id', String(data.line.id));
        ['to_bank_account_id','reference','description'].forEach(function(name){
          const el = transferForm.querySelector('[name="' + name + '"]');
          if (el) p.append(name, el.value);
        });
        const res = await fetch(ajaxBase + 'bank_reco_transfer.php', {method:'POST', body:p});
        const j = await res.json();
        if (!j.success) { alert(j.error || 'Transfer failed'); return; }
        await afterReconcile(data.line.id);
      });
    }
    document.getElementById('btnFindSearch')?.addEventListener('click', function(){
      runFindSearch(data.line);
    });
    document.querySelectorAll('.co-breco-goto-tab').forEach(function(link){
      link.addEventListener('click', function(ev){
        ev.preventDefault();
        const t = link.getAttribute('data-tab');
        const tabBtn = document.querySelector('#actionTabs .nav-link[data-tab="' + t + '"]');
        if (tabBtn) { tabBtn.click(); }
      });
    });
  }

  async function reconcileMatch(lineId, systemType, systemId){
    const p = fd();
    p.append('line_id', String(lineId));
    p.append('system_type', systemType);
    p.append('system_id', String(systemId));
    const res = await fetch(ajaxBase + 'bank_reco_reconcile.php', {method:'POST', body:p});
    const j = await res.json();
    if (!j.success) { alert(j.error || 'Reconcile failed'); return; }
    await afterReconcile(lineId);
  }

  async function afterReconcile(doneLineId){
    selectedLineId = null;
    await loadBalances();
    await loadLines();
    const next = lines.find(l => !l.is_fully_matched && Number(l.id) !== doneLineId);
    if (next) selectLine(Number(next.id));
    else document.getElementById('panelBody').innerHTML = '<div class="co-breco-empty">All lines in view are reconciled.</div>';
  }

  document.querySelectorAll('#actionTabs .nav-link').forEach(function(tab){
    tab.addEventListener('click', function(){
      if (tab.classList.contains('co-breco-tab-disabled')) return;
      document.querySelectorAll('#actionTabs .nav-link').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      if (selectedLineId) loadLineDetail(selectedLineId);
    });
  });

  document.getElementById('btnReload')?.addEventListener('click', async function(){
    await loadBalances();
    await loadLines();
  });

  document.getElementById('bankSel')?.addEventListener('change', function(){
    root.dataset.bankId = document.getElementById('bankSel').value;
    const u = new URL(window.location.href);
    u.searchParams.set('bank_account_id', root.dataset.bankId);
    window.history.replaceState({}, '', u);
    selectedLineId = null;
    loadBalances();
    loadLines();
  });

  loadBalances();
  loadLines();
})();
