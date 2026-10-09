(function(){
  const root = document.getElementById('reBankRecoApp');
  if (!root) return;

  const CSRF = root.dataset.csrf || '';
  const ajaxBase = root.dataset.ajaxBase || 'ajax/';
  const bankId = () => root.dataset.bankId;
  const dateFrom = () => document.getElementById('dFrom')?.value || '';
  const dateTo = () => document.getElementById('dTo')?.value || '';

  let lines = [];
  let selectedLineId = null;
  let selectedSplitId = null;
  // A bank charge ticked together with its VAT line, to reconcile both against one ERP entry.
  const pickedIds = new Set();

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
    const map = {unmatched:'Unmatched',unreconciled:'Unmatched',matched:'Matched',reconciled:'Matched',partially_matched:'Partial',investigating:'Discussing',discussed:'Discussing',ignored:'Ignored',suggested:'Suggested'};
    return '<span class="co-breco-badge ' + esc(status) + '">' + esc(map[status] || status) + '</span>';
  }

  // "View receipt" link for a suggestion that is a tenant receipt.
  function receiptLink(paymentId, cls){
    if (!paymentId) return '';
    return ' <a class="btn btn-outline-secondary btn-sm ' + (cls || '') + '" target="_blank" href="../payment_view.php?id=' + encodeURIComponent(paymentId) + '">View receipt</a>';
  }

  // Receipts found for a bank line that do not add up to it: how far off, and which way.
  function splitOff(sp){
    if (!sp) return null;
    if (Number(sp.short) > 0) return {amt: Number(sp.short).toFixed(2), word: 'missing', less: true};
    if (Number(sp.over) > 0) return {amt: Number(sp.over).toFixed(2), word: 'over', less: false};
    return null;
  }

  function fmtAmt(n, spent){
    const v = Number(n).toFixed(2);
    return spent ? ('-' + v) : v;
  }

  async function loadBalances(){
    const r = await fetch(ajaxBase + 're_bank_reco_balances.php?bank_account_id=' + encodeURIComponent(bankId()) + '&as_of=' + encodeURIComponent(dateTo()));
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
    const r = await fetch(ajaxBase + 're_bank_reco_list_statement.php?bank_account_id=' + encodeURIComponent(bankId()) + '&date_from=' + encodeURIComponent(dateFrom()) + '&date_to=' + encodeURIComponent(dateTo()) + '&status=open');
    const j = await r.json();
    if (!j.success) { alert(j.error || 'Failed to load lines'); return; }
    lines = j.lines || [];
    Array.from(pickedIds).forEach(function(id){
      if (!lines.some(function(l){ return Number(l.id) === id && l.related; })) pickedIds.clear();
    });
    renderLines();
    if (!selectedLineId) {
      const next = lines.find(l => !l.is_fully_matched && l.status !== 'reconciled');
      if (next) selectLine(Number(next.id));
    }
    const oldest = lines.length ? String(lines[lines.length - 1].txn_date || '') : '';
    const from = dateFrom();
    let countLabel = lines.length + ' unmatched lines';
    if (j.truncated) {
      countLabel += ' · showing newest ' + (j.limit || lines.length) + ' (narrow the date range to see older)';
    } else if (oldest && from && oldest > from) {
      countLabel += ' · from ' + oldest + ' (earlier may be reconciled)';
    }
    document.getElementById('linesCount').textContent = countLabel;
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
          '<div class="co-breco-line-meta">' + esc(l.txn_date) + (l.reference ? ' · ' + esc(l.reference) : '') + '</div>' +
          (l.related
            ? '<label class="co-breco-related small"><input type="checkbox" class="form-check-input co-breco-pick"' + (pickedIds.has(Number(l.id)) ? ' checked' : '') + '> ' +
              'Reconcile with its ' + (l.related.line_ids.length - 1 > 1 ? (l.related.line_ids.length - 1) + ' related lines' : 'related line') + ' · total ' + Number(l.related.total).toFixed(2) + '</label>'
            : '') +
          '</div>' +
          '<div class="text-end"><div class="co-breco-amt ' + (spent ? 'spent' : 'received') + '">' + fmtAmt(Math.abs(l.amount), spent) + '</div>' +
          badge(status) + '</div>' +
        '</div>';
      if (l.split && l.split.items && l.split.items.length) {
        const wrap = document.createElement('div');
        wrap.className = 'co-breco-split';
        wrap.innerHTML = '<div class="co-breco-split-note">' +
          (splitOff(l.split)
            ? 'Receipts total ' + Number(l.split.total).toFixed(2) + ' · <span class="text-danger">' + splitOff(l.split).amt + ' ' + splitOff(l.split).word + '</span>'
            : (l.split.items.length > 1 ? 'Recorded as ' + l.split.items.length + ' receipts' : 'Receipt for the remaining ' + Number(l.split.total).toFixed(2))) + '</div>';
        l.split.items.forEach(function(it){
          const row = document.createElement('div');
          row.className = 'co-breco-split-row' + (Number(l.id) === selectedLineId && Number(it.system_id) === selectedSplitId ? ' active' : '');
          row.innerHTML = '<span>' + esc(it.receipt_number || 'Receipt') + '</span>' +
            '<span class="co-breco-amt received">' + Number(it.amount).toFixed(2) + '</span>';
          row.addEventListener('click', function(ev){
            ev.stopPropagation();
            selectLine(Number(l.id), Number(it.system_id));
          });
          wrap.appendChild(row);
        });
        div.appendChild(wrap);
      }
      const pick = div.querySelector('.co-breco-pick');
      if (pick) {
        // The charge and its VAT line always go together: ticking one ticks its partners.
        pick.closest('label').addEventListener('click', function(ev){ ev.stopPropagation(); });
        pick.addEventListener('change', function(){
          pickedIds.clear();
          if (pick.checked) l.related.line_ids.forEach(function(id){ pickedIds.add(Number(id)); });
          pickedChanged();
        });
      }
      div.addEventListener('click', function(){ selectLine(Number(l.id)); });
      box.appendChild(div);
    });
  }

  // Ticked lines replace the single-line panel with the grouped one.
  async function pickedChanged(){
    renderLines();
    if (pickedIds.size >= 2) {
      await loadGroupPanel();
    } else if (selectedLineId) {
      await loadLineDetail(selectedLineId);
    } else {
      document.getElementById('panelBody').innerHTML = '<div class="co-breco-empty">Select a statement line.</div>';
    }
  }

  async function loadGroupPanel(){
    const panel = document.getElementById('panelBody');
    panel.innerHTML = '<div class="co-breco-empty">Loading…</div>';
    const ids = Array.from(pickedIds);
    let j;
    try {
      const r = await fetch(ajaxBase + 're_bank_reco_line_group.php?line_ids=' + encodeURIComponent(ids.join(',')));
      j = await r.json();
    } catch (e) {
      panel.innerHTML = '<div class="alert alert-danger">Could not load the selected lines.</div>';
      return;
    }
    if (ids.join(',') !== Array.from(pickedIds).join(',')) return;
    const clearBtn = '<button type="button" class="btn btn-outline-secondary btn-sm" id="btnGroupClear">Clear selection</button>';
    const bindClear = function(){
      document.getElementById('btnGroupClear').addEventListener('click', function(){ pickedIds.clear(); pickedChanged(); });
    };
    if (!j.success) {
      panel.innerHTML = '<div class="alert alert-warning">' + esc(j.error || 'These lines cannot be reconciled together.') + '</div>' + clearBtn;
      bindClear();
      return;
    }
    const total = Number(j.total).toFixed(2);
    let html = '<div class="mb-2"><strong>' + j.lines.length + ' bank lines, one ERP transaction</strong>' +
      '<div class="small text-muted">The bank shows these separately; the ERP holds them as one entry of ' + total + '.</div></div>' +
      '<table class="table table-sm mb-3"><thead class="table-light"><tr><th>Date</th><th>Bank line</th><th class="text-end">Amount</th></tr></thead><tbody>' +
      j.lines.map(function(ln){
        return '<tr><td class="text-nowrap">' + esc(ln.txn_date) + '</td><td>' + esc(ln.description) + '</td><td class="text-end">' + fmtAmt(ln.remaining, ln.is_spent) + '</td></tr>';
      }).join('') +
      '<tr class="fw-semibold"><td colspan="2">Total</td><td class="text-end">' + fmtAmt(j.total, j.is_spent) + '</td></tr></tbody></table>';
    if (!j.candidates.length) {
      html += '<div class="alert alert-light border small">No unreconciled ERP transaction of ' + total + ' is dated on or within 3 days of ' + esc(j.lines[0].txn_date) + '. Record the expense first, then press Refresh.</div>';
    } else {
      j.candidates.forEach(function(c){
        const same = c.day_gap === 0;
        html += '<div class="co-breco-suggest-card' + (same ? '' : ' medium') + ' mb-2">' +
          '<div class="d-flex justify-content-between align-items-start gap-2">' +
            '<div class="flex-grow-1"><div class="fw-semibold">' + esc(c.label) + '</div>' +
            '<div class="small text-muted mt-1"><strong>Date:</strong> ' + esc(c.txn_date) + ' · <strong>Amount:</strong> ' + Number(c.remaining).toFixed(2) + '</div></div>' +
            '<span class="badge ' + (same ? 'bg-success' : 'bg-warning text-dark') + '">' + (same ? 'Same date' : c.day_gap + ' day' + (c.day_gap > 1 ? 's' : '') + ' apart') + '</span>' +
          '</div>' +
          '<button type="button" class="btn btn-success btn-sm mt-3 btn-group-match" data-id="' + c.system_id + '">OK — Reconcile ' + j.lines.length + ' lines</button>' +
        '</div>';
      });
    }
    html += '<div class="mt-3">' + clearBtn + '</div>';
    panel.innerHTML = html;
    bindClear();
    panel.querySelectorAll('.btn-group-match').forEach(function(btn){
      btn.addEventListener('click', async function(){
        btn.disabled = true;
        const p = fd();
        p.append('line_ids', ids.join(','));
        p.append('system_id', btn.getAttribute('data-id'));
        const res = await fetch(ajaxBase + 're_bank_reco_match_lines.php', {method:'POST', body:p});
        const out = await res.json();
        if (!out.success) { btn.disabled = false; alert(out.error || 'Reconcile failed'); return; }
        pickedIds.clear();
        await afterReconcile(ids[0]);
      });
    });
  }

  async function selectLine(id, splitId){
    pickedIds.clear();
    selectedLineId = id;
    selectedSplitId = splitId || null;
    renderLines();
    await loadLineDetail(id);
  }

  async function loadLineDetail(id){
    const panel = document.getElementById('panelBody');
    panel.innerHTML = '<div class="co-breco-empty">Loading…</div>';
    try {
      const r = await fetch(ajaxBase + 're_bank_reco_line_detail.php?line_id=' + encodeURIComponent(id) +
        '&date_from=' + encodeURIComponent(dateFrom()) + '&date_to=' + encodeURIComponent(dateTo()));
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
        const badgeCls = s.confidence === 'High' ? 'bg-success' : (s.confidence === 'Medium' ? 'bg-warning text-dark' : 'bg-secondary');
        html += '<div class="co-breco-suggest-card' + cls + '">' +
          '<div class="d-flex justify-content-between align-items-start gap-2">' +
            '<div class="flex-grow-1">' +
              '<div class="fw-semibold">' + esc(s.label || 'Suggested match') + '</div>' +
              '<div class="small text-muted mt-1">' +
                '<div><strong>Date:</strong> ' + esc(s.txn_date || '—') +
                ' · <strong>Amount:</strong> ' + Number(s.amount || 0).toFixed(2) + '</div>' +
                '<div><strong>Party:</strong> ' + esc(s.party_name || '—') +
                ' · <strong>Ref:</strong> ' + esc(s.reference || '—') + '</div>' +
                '<div><strong>Lease Number:</strong> ' + esc(s.lease_number || '—') + '</div>' +
              '</div>' +
            '</div>' +
            '<span class="badge ' + badgeCls + '">' + esc(s.confidence || 'Low') + '</span>' +
          '</div>' +
          ((s.reasons && s.reasons.length)
            ? '<ul class="small text-muted mb-0 mt-2 ps-3">' + s.reasons.map(function(r){ return '<li>' + esc(r) + '</li>'; }).join('') + '</ul>'
            : '') +
          '<button type="button" class="btn btn-success btn-sm mt-3" id="btnOkMatch">OK — Reconcile</button>' +
          receiptLink(s.payment_id, 'mt-3') +
          (data.alternative_count > 0 ? '<div class="small text-muted mt-2">' + data.alternative_count + ' other possible matches — review below or use <a href="#" class="co-breco-goto-tab" data-tab="find">Find &amp; Match</a></div>' : '') +
        '</div>';
      } else if (data.combined_suggestion && data.combined_suggestion.items.some(function(it){ return Number(it.system_id) === selectedSplitId; })) {
        const cs = data.combined_suggestion;
        const it = cs.items.find(function(x){ return Number(x.system_id) === selectedSplitId; });
        html += '<div class="co-breco-suggest-card' + (cs.confidence === 'High' ? '' : ' medium') + '">' +
          '<div class="d-flex justify-content-between align-items-start gap-2">' +
            '<div class="flex-grow-1">' +
              '<div class="fw-semibold">' + esc(it.receipt_number || 'Receipt') + ' — ' + Number(it.amount).toFixed(2) + '</div>' +
              '<div class="small text-muted mt-1">' +
                '<div><strong>Date:</strong> ' + esc(it.txn_date || '—') +
                ' · <strong>Amount:</strong> ' + Number(it.amount).toFixed(2) + '</div>' +
                '<div><strong>Party:</strong> ' + esc(cs.party_name || '—') +
                ' · <strong>Ref:</strong> ' + esc(it.reference || '—') + '</div>' +
                '<div><strong>Lease Number:</strong> ' + esc(cs.lease_number || '—') + '</div>' +
                '<div><strong>Bank line:</strong> ' + Number(cs.line_amount).toFixed(2) +
                ' · <strong>Left after this:</strong> ' + (Number(line.remaining) - Number(it.amount)).toFixed(2) + '</div>' +
              '</div>' +
            '</div>' +
            '<span class="badge ' + (cs.confidence === 'High' ? 'bg-success' : 'bg-warning text-dark') + '">' + esc(cs.confidence) + '</span>' +
          '</div>' +
          (splitOff(cs)
            ? '<div class="alert alert-warning small py-2 mt-2 mb-0">The receipts for this bank line total ' + Number(cs.total).toFixed(2) + ', ' + splitOff(cs).amt + (splitOff(cs).less ? ' less' : ' more') + ' than the line. Correct the receipt' + (splitOff(cs).less ? ' or add the missing one' : '') + ', then press Refresh to reconcile.</div>'
            : '<button type="button" class="btn btn-success btn-sm mt-3" id="btnOkSplit">OK — Reconcile this receipt</button>') +
          receiptLink(it.payment_id, 'mt-3') +
        '</div>';
      } else if (data.combined_suggestion) {
        const cs = data.combined_suggestion;
        const csBadge = cs.confidence === 'High' ? 'bg-success' : 'bg-warning text-dark';
        html += '<div class="co-breco-suggest-card' + (cs.confidence === 'High' ? '' : ' medium') + '">' +
          '<div class="d-flex justify-content-between align-items-start gap-2">' +
            '<div class="flex-grow-1">' +
              '<div class="fw-semibold">' + (cs.items.length > 1 ? cs.items.length + ' receipts' : '1 receipt') + ' for this one bank line</div>' +
              '<div class="small text-muted mt-1">' +
                '<div><strong>Party:</strong> ' + esc(cs.party_name || '—') + '</div>' +
                '<div><strong>Lease Number:</strong> ' + esc(cs.lease_number || '—') + '</div>' +
              '</div>' +
            '</div>' +
            '<span class="badge ' + csBadge + '">' + esc(cs.confidence) + '</span>' +
          '</div>' +
          '<table class="table table-sm mb-0 mt-2"><thead><tr><th>Receipt</th><th>Date</th><th class="text-end">Amount</th></tr></thead><tbody>' +
          cs.items.map(function(it){
            return '<tr><td>' + esc(it.receipt_number || '—') + '</td><td>' + esc(it.txn_date) + '</td><td class="text-end">' + Number(it.amount).toFixed(2) + receiptLink(it.payment_id, 'ms-2') + '</td></tr>';
          }).join('') +
          (splitOff(cs)
            ? '<tr class="fw-semibold"><td colspan="2">Receipts total</td><td class="text-end">' + Number(cs.total).toFixed(2) + '</td></tr>' +
              '<tr class="fw-semibold text-danger"><td colspan="2">' + (splitOff(cs).less ? 'Missing' : 'Over') + ' (bank line ' + Number(line.remaining).toFixed(2) + ')</td><td class="text-end">' + splitOff(cs).amt + '</td></tr>'
            : '<tr class="fw-semibold"><td colspan="2">Total = bank line</td><td class="text-end">' + Number(cs.total).toFixed(2) + '</td></tr>') +
          '</tbody></table>' +
          ((cs.reasons && cs.reasons.length)
            ? '<ul class="small text-muted mb-0 mt-2 ps-3">' + cs.reasons.map(function(r){ return '<li>' + esc(r) + '</li>'; }).join('') + '</ul>'
            : '') +
          (splitOff(cs)
            ? '<div class="alert alert-warning small py-2 mt-2 mb-0">The receipts are ' + splitOff(cs).amt + (splitOff(cs).less ? ' less' : ' more') + ' than the bank line. Correct the receipt' + (splitOff(cs).less ? ' or add the missing one' : '') + ', then press Refresh to reconcile.</div>'
            : '<button type="button" class="btn btn-success btn-sm mt-3" id="btnOkCombined">OK — Reconcile all ' + cs.items.length + '</button>') +
        '</div>';
      } else {
        html += '<div class="alert alert-light border">No confident match found. Use <strong>Create</strong> for a new transaction or <strong>Discuss</strong> to hold this line.</div>';
      }
      if (data.alternatives && data.alternatives.length) {
        html += '<div class="small text-muted mb-1 mt-3">Other suggestions</div><ul class="list-group list-group-flush">';
        data.alternatives.forEach(function(a){
          const aBadge = a.confidence === 'High' ? 'bg-success' : (a.confidence === 'Medium' ? 'bg-warning text-dark' : 'bg-secondary');
          html += '<li class="list-group-item px-0 py-2">' +
            '<div class="d-flex justify-content-between align-items-start gap-2">' +
              '<div class="flex-grow-1">' +
                '<div class="fw-semibold small">' + esc(a.label || '—') +
                ' <span class="badge ' + aBadge + '">' + esc(a.confidence || 'Low') + '</span></div>' +
                '<div class="small text-muted">' +
                  '<div><strong>Date:</strong> ' + esc(a.txn_date || '—') +
                  ' · <strong>Amount:</strong> ' + Number(a.amount || 0).toFixed(2) + '</div>' +
                  '<div><strong>Party:</strong> ' + esc(a.party_name || '—') +
                  ' · <strong>Ref:</strong> ' + esc(a.reference || '—') + '</div>' +
                  '<div><strong>Lease Number:</strong> ' + esc(a.lease_number || '—') + '</div>' +
                '</div>' +
                ((a.reasons && a.reasons.length)
                  ? '<ul class="small text-muted mb-0 mt-1 ps-3">' + a.reasons.map(function(r){ return '<li>' + esc(r) + '</li>'; }).join('') + '</ul>'
                  : '') +
              '</div>' +
              '<div class="flex-shrink-0 text-end"><button type="button" class="btn btn-outline-success btn-sm btn-alt-match" data-type="' + esc(a.system_type) + '" data-id="' + a.system_id + '">OK</button>' +
              (a.payment_id ? '<div class="mt-1">' + receiptLink(a.payment_id) + '</div>' : '') + '</div>' +
            '</div></li>';
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
            '<td class="text-end text-nowrap"><button type="button" class="btn btn-outline-success btn-sm btn-alt-match" data-type="' + esc(c.system_type) + '" data-id="' + c.system_id + '">Match</button>' +
            receiptLink(c.payment_id) + '</td></tr>';
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
    const c = window.RE_BRECO_CONTACTS || {tenants:[], vendors:[]};
    const groups = [
      {key:'tenants', type:'tenant', label:'Tenants'},
      {key:'vendors', type:'vendor', label:'Vendors'}
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
    const c = window.RE_BRECO_CONTACTS || {tenants:[], vendors:[]};
    const preferTenants = !line.is_spent;
    const order = preferTenants
      ? [['tenants','tenant'],['vendors','vendor']]
      : [['vendors','vendor'],['tenants','tenant']];
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
    return '<option value="">— Select —</option>' + (window.RE_BRECO_ACCOUNTS || []).map(function(a){
      return '<option value="' + a.id + '"' + (String(a.id) === String(selectedId || '') ? ' selected' : '') + '>' + esc(a.account_code + ' — ' + a.account_name) + '</option>';
    }).join('');
  }

  function projectOptions(selectedId){
    return '<option value="">— Optional —</option>' + (window.RE_BRECO_PROJECTS || []).map(function(p){
      return '<option value="' + p.id + '"' + (String(p.id) === String(selectedId || '') ? ' selected' : '') + '>' + esc(p.project_code + ' — ' + p.project_name) + '</option>';
    }).join('');
  }

  function vatConfig(){
    return window.RE_BRECO_VAT || { default_rate: 5, options: [] };
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
      : (line.is_received ? 'tenant_receipt' : 'quick_expense');
    const guessedContact = (prefill && prefill.contact) ? prefill.contact : guessContactFromLine(line);
    const contactOpts = buildContactOptions(line, guessedContact);
    const descVal = (prefill && prefill.description) ? prefill.description : (line.description || '');
    const refVal = (prefill && prefill.reference) ? prefill.reference : (line.reference || '');
    const defaultVat = (prefill && prefill.vat_treatment) ? prefill.vat_treatment : 'none';
    return '<form id="createForm" class="row g-2">' +
      '<div class="col-12"><label class="form-label small">Type</label><select name="transaction_type" class="form-select form-select-sm">' +
        '<option value="bank_charge"' + (defaultType === 'bank_charge' ? ' selected' : '') + '>Bank Charge</option>' +
        '<option value="quick_expense"' + (defaultType === 'quick_expense' ? ' selected' : '') + '>Expense / Adjustment</option>' +
        '<option value="cash_withdrawal"' + (defaultType === 'cash_withdrawal' ? ' selected' : '') + '>Cash Withdrawal / Petty Cash</option>' +
        '<option value="tenant_receipt"' + (defaultType === 'tenant_receipt' ? ' selected' : '') + '>Tenant Receipt / Income</option>' +
      '</select></div>' +
      '<div class="col-12"><label class="form-label small">Who (Contact)</label><select name="contact" class="form-select form-select-sm">' + contactOpts + '</select>' +
        (guessedContact ? '<div class="form-text">Suggested from statement description</div>' : '') +
      '</div>' +
      '<div class="col-12"><label class="form-label small">What (Account)</label><select name="account_id" class="form-select form-select-sm">' + accountOptions(prefill ? prefill.account_id : '') + '</select></div>' +
      '<div class="col-12"><label class="form-label small">VAT / Tax</label><select name="vat_treatment" class="form-select form-select-sm create-vat-sel">' + vatOptionsHtml(defaultVat) + '</select>' +
        '<input type="hidden" name="vat_rate" value="' + esc(String(vatConfig().default_rate || 5)) + '">' +
        '<div id="createVatBreakdown">' + vatBreakdownHtml(line.abs_amount, defaultVat) + '</div></div>' +
      '<div class="col-12"><label class="form-label small">Why (Description / Memo)</label><input type="text" name="description" class="form-control form-control-sm" value="' + esc(descVal) + '"></div>' +
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
    const banks = (window.RE_BRECO_BANKS || []).filter(b => String(b.id) !== String(bankId()));
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
        '<div class="col-md-4"><label class="form-label small">Type</label><select id="findType" class="form-select form-select-sm"><option value="">All</option><option value="bank_reconciliation">Bank reconciliation</option><option value="re_payments">Tenant receipt</option><option value="re_vendor_payments">Vendor payment</option></select></div>' +
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
      (window.RE_BRECO_ACCOUNTS || []).map(a => '<option value="' + a.id + '">' + esc(a.account_code + ' — ' + a.account_name) + '</option>').join('') +
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
    const r = await fetch(ajaxBase + 're_bank_reco_find_match.php?' + q.toString());
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
      const res = await fetch(ajaxBase + 're_bank_reco_match_selections.php', {method:'POST', body:p});
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
    const okSplit = document.getElementById('btnOkSplit');
    if (okSplit && data.combined_suggestion) {
      okSplit.addEventListener('click', async function(){
        const it = data.combined_suggestion.items.find(function(x){ return Number(x.system_id) === selectedSplitId; });
        if (!it) return;
        okSplit.disabled = true;
        const p = fd();
        p.append('line_id', String(data.line.id));
        p.append('partial', '1');
        p.append('selections', JSON.stringify([{system_type: it.system_type, system_id: it.system_id, amount: it.amount}]));
        const res = await fetch(ajaxBase + 're_bank_reco_match_selections.php', {method:'POST', body:p});
        const j = await res.json();
        if (!j.success) { okSplit.disabled = false; alert(j.error || 'Reconcile failed'); return; }
        // Stay on this bank line while it still has a receipt left to reconcile.
        await loadBalances();
        await loadLines();
        const same = lines.find(function(l){ return Number(l.id) === Number(data.line.id); });
        if (same && same.split && same.split.items.length) selectLine(Number(same.id), Number(same.split.items[0].system_id));
        else if (same) selectLine(Number(same.id));
        else await afterReconcile(data.line.id);
      });
    }
    const okCombined = document.getElementById('btnOkCombined');
    if (okCombined && data.combined_suggestion) {
      okCombined.addEventListener('click', async function(){
        okCombined.disabled = true;
        const p = fd();
        p.append('line_id', String(data.line.id));
        p.append('selections', JSON.stringify(data.combined_suggestion.items.map(function(it){
          return {system_type: it.system_type, system_id: it.system_id, amount: it.amount};
        })));
        const res = await fetch(ajaxBase + 're_bank_reco_match_selections.php', {method:'POST', body:p});
        const j = await res.json();
        if (!j.success) { okCombined.disabled = false; alert(j.error || 'Reconcile failed'); return; }
        await afterReconcile(data.line.id);
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
        ['transaction_type','account_id','description','reference','contact','vat_treatment','vat_rate'].forEach(function(name){
          const el = createForm.querySelector('[name="' + name + '"]');
          if (el) p.append(name, el.value);
        });
        const res = await fetch(ajaxBase + 're_bank_reco_create.php', {method:'POST', body:p});
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
        const res = await fetch(ajaxBase + 're_bank_reco_discuss.php', {method:'POST', body:p});
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
        const res = await fetch(ajaxBase + 're_bank_reco_transfer.php', {method:'POST', body:p});
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
    const res = await fetch(ajaxBase + 're_bank_reco_reconcile.php', {method:'POST', body:p});
    const j = await res.json();
    if (!j.success) { alert(j.error || 'Reconcile failed'); return; }
    await afterReconcile(lineId);
  }

  async function afterReconcile(doneLineId){
    selectedLineId = null;
    selectedSplitId = null;
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
      if (pickedIds.size >= 2) loadGroupPanel();
      else if (selectedLineId) loadLineDetail(selectedLineId);
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
    selectedSplitId = null;
    pickedIds.clear();
    document.getElementById('panelBody').innerHTML = '<div class="co-breco-empty">Loading…</div>';
    loadBalances();
    loadLines();
  });

  loadBalances();
  loadLines();
})();
