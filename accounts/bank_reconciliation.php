<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

$banks = $conn->query("
    SELECT b.id, b.name, c.account_no
    FROM cleaning_bank_accounts b
    JOIN chart_of_accounts c ON c.id = b.chart_account_id
    WHERE b.is_active = 1
    ORDER BY c.account_no
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$df = date('Y-m-01');
$dt = date('Y-m-d');
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Bank reconciliation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h($csrf) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f6f7f9}
    .hero{background:#fff;border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.06);padding:18px 22px;margin-bottom:18px}
    .sum-card{background:#fff;border-radius:12px;padding:12px 14px;box-shadow:0 4px 14px rgba(0,0,0,.05)}
    .sum-card .lbl{font-size:.75rem;color:#6b7280;text-transform:uppercase}
    .sum-card .val{font-weight:700;font-size:1.1rem}
    tr.row-sel{background:#e7f1ff!important}
    tr.sys-sel{background:#e8fff0!important}
    .table-sm td,.table-sm th{padding:.35rem .5rem;font-size:.9rem}
  </style>
</head>
<body>
<div class="container-fluid my-3 px-3">
  <div class="hero d-flex flex-wrap align-items-center gap-2">
    <div class="flex-grow-1">
      <div class="text-uppercase small text-muted">Cleaning</div>
      <h3 class="mb-0">Bank reconciliation</h3>
      <div class="text-muted small">Import CSV v1, propose matches (auto-match never confirms), confirm when ready. Locked periods block confirm, unmatch, and imports.</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a href="bank_reconciliation_accounts.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i> Accounts</a>
      <a href="reports.php" class="btn btn-outline-secondary btn-sm">Reports</a>
    </div>
  </div>

  <?php if (!$banks): ?>
    <div class="alert alert-warning">No active bank accounts. <a href="bank_reconciliation_accounts.php">Link a chart account first</a>.</div>
  <?php else: ?>

  <div class="row g-2 align-items-end mb-3">
    <div class="col-auto">
      <label class="form-label small mb-0">Bank</label>
      <select id="bankSel" class="form-select form-select-sm">
        <?php foreach ($banks as $b): ?>
          <option value="<?= (int) $b['id'] ?>"><?= h($b['account_no'] . ' — ' . $b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label small mb-0">From</label>
      <input type="date" id="dFrom" class="form-control form-control-sm" value="<?= h($df) ?>">
    </div>
    <div class="col-auto">
      <label class="form-label small mb-0">To</label>
      <input type="date" id="dTo" class="form-control form-control-sm" value="<?= h($dt) ?>">
    </div>
    <div class="col-auto">
      <button type="button" class="btn btn-primary btn-sm" id="btnReload"><i class="bi bi-arrow-clockwise"></i> Load</button>
    </div>
    <div class="col-auto">
      <button type="button" class="btn btn-outline-primary btn-sm" id="btnAuto"><i class="bi bi-magic"></i> Auto-match (proposed)</button>
    </div>
  </div>

  <div class="row g-2 mb-3" id="summaryRow">
    <div class="col-6 col-md-4 col-lg-2">
      <div class="sum-card"><div class="lbl">Statement total</div><div class="val" id="sumStmt">—</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="sum-card"><div class="lbl">Matched (prop+conf)</div><div class="val" id="sumMatched">—</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="sum-card"><div class="lbl">Unmatched (stmt)</div><div class="val text-warning" id="sumUnmatched">—</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="sum-card"><div class="lbl">Book inflows</div><div class="val text-success" id="sumIn">—</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="sum-card"><div class="lbl">Book outflows</div><div class="val text-danger" id="sumOut">—</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="sum-card"><div class="lbl">Book net</div><div class="val" id="sumNet">—</div></div>
    </div>
  </div>

  <div class="alert alert-info py-2 small mb-3" id="dupWarn" style="display:none"></div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header d-flex flex-wrap align-items-center gap-2 py-2">
          <strong>Statement lines</strong>
          <span class="small text-muted ms-auto" id="stmtHint"></span>
        </div>
        <div class="card-body p-0" style="max-height:420px;overflow:auto">
          <table class="table table-sm table-hover mb-0" id="tblStmt">
            <thead class="table-light sticky-top"><tr><th>Date</th><th class="text-end">Amt</th><th>Description</th><th class="text-end">Rem</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="card-footer py-2">
          <div class="row g-2 align-items-end">
            <div class="col-12"><strong class="small">Import CSV</strong> <a href="templates/bank_statement_import_template.csv" download class="small">template</a></div>
            <div class="col-md-8">
              <input type="file" id="csvFile" class="form-control form-control-sm" accept=".csv,text/csv">
            </div>
            <div class="col-md-4">
              <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btnImport">Upload</button>
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-0">Manual date</label>
              <input type="date" id="manDate" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-0">Amt</label>
              <input type="text" id="manAmt" class="form-control form-control-sm" placeholder="-50.00">
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-0">Description</label>
              <input type="text" id="manDesc" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-0">Ref</label>
              <input type="text" id="manRef" class="form-control form-control-sm">
            </div>
            <div class="col-md-1">
              <button type="button" class="btn btn-sm btn-secondary w-100 mt-4" id="btnManual" title="Add line">+</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm mb-3">
        <div class="card-header py-2">
          <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabRec" type="button">Receipts</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabExp" type="button">Expenses</button></li>
          </ul>
        </div>
        <div class="card-body p-0 tab-content" style="max-height:360px;overflow:auto">
          <div class="tab-pane fade show active" id="tabRec">
            <table class="table table-sm table-hover mb-0" id="tblRec">
              <thead class="table-light sticky-top"><tr><th>Date</th><th class="text-end">Amt</th><th class="text-end">Rem</th><th>Ref</th><th>Party</th></tr></thead>
              <tbody></tbody>
            </table>
          </div>
          <div class="tab-pane fade" id="tabExp">
            <table class="table table-sm table-hover mb-0" id="tblExp">
              <thead class="table-light sticky-top"><tr><th>Date</th><th class="text-end">Amt</th><th class="text-end">Rem</th><th>Ref</th><th>Vendor</th></tr></thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
        <div class="card-footer py-2 d-flex gap-2">
          <button type="button" class="btn btn-sm btn-success" id="btnMatch" disabled><i class="bi bi-link-45deg"></i> Create proposed match</button>
          <span class="small text-muted align-self-center" id="matchHint">Select a statement line and a receipt or expense row.</span>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header py-2 d-flex align-items-center gap-2">
          <strong>Matches for selected line</strong>
          <button type="button" class="btn btn-sm btn-primary ms-auto" id="btnConfirm" disabled>Confirm selected</button>
        </div>
        <div class="card-body p-0" style="max-height:220px;overflow:auto">
          <table class="table table-sm mb-0" id="tblMatch">
            <thead class="table-light"><tr><th><input type="checkbox" id="chkAll"></th><th>Type</th><th>ID</th><th class="text-end">Matched</th><th>Status</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <div class="card shadow-sm mt-3">
        <div class="card-header py-2"><strong>Period lock</strong></div>
        <div class="card-body py-2">
          <div class="row g-2 align-items-end">
            <div class="col-auto">
              <label class="form-label small mb-0">Month (YYYY-MM)</label>
              <input type="month" id="lockPeriod" class="form-control form-control-sm">
            </div>
            <div class="col-auto">
              <button type="button" class="btn btn-sm btn-danger" id="btnLock">Lock</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnUnlock">Unlock</button>
            </div>
          </div>
          <div class="small text-muted mt-2" id="lockList">Locks: —</div>
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>
</div>

<?php if ($banks): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  let selLineId = null;
  let selSys = null;

  function esc(s){
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function fd(){
    return new URLSearchParams({_csrf: CSRF});
  }

  async function loadSummary(){
    const b = document.getElementById('bankSel').value;
    const f = document.getElementById('dFrom').value;
    const t = document.getElementById('dTo').value;
    const r = await fetch('ajax/bank_reco_summary.php?bank_account_id='+encodeURIComponent(b)+'&date_from='+encodeURIComponent(f)+'&date_to='+encodeURIComponent(t));
    const j = await r.json();
    if(!j.success){ alert(j.error||'Summary failed'); return; }
    document.getElementById('sumStmt').textContent = Number(j.statement_total).toFixed(2);
    document.getElementById('sumMatched').textContent = Number(j.matched_total).toFixed(2);
    document.getElementById('sumUnmatched').textContent = Number(j.unmatched_total).toFixed(2);
    document.getElementById('sumIn').textContent = Number(j.book_inflows_total).toFixed(2);
    document.getElementById('sumOut').textContent = Number(j.book_outflows_total).toFixed(2);
    document.getElementById('sumNet').textContent = Number(j.book_net_total).toFixed(2);
  }

  async function loadStmt(){
    const b = document.getElementById('bankSel').value;
    const f = document.getElementById('dFrom').value;
    const t = document.getElementById('dTo').value;
    const r = await fetch('ajax/bank_reco_list_statement.php?bank_account_id='+encodeURIComponent(b)+'&date_from='+encodeURIComponent(f)+'&date_to='+encodeURIComponent(t));
    const j = await r.json();
    const tb = document.querySelector('#tblStmt tbody');
    tb.innerHTML = '';
    if(!j.success){ alert(j.error||'Failed'); return; }
    document.getElementById('stmtHint').textContent = j.lines.length+' lines';
    j.lines.forEach(function(l){
      const tr = document.createElement('tr');
      tr.dataset.lineId = l.id;
      if(Number(l.id) === selLineId) tr.classList.add('row-sel');
      tr.innerHTML = '<td>'+esc(l.txn_date)+'</td><td class="text-end">'+esc(String(l.amount))+'</td><td>'+esc(l.description||'')+'</td><td class="text-end">'+(l.remaining!=null?Number(l.remaining).toFixed(2):'')+'</td>';
      tr.addEventListener('click', function(){
        document.querySelectorAll('#tblStmt tbody tr').forEach(function(x){ x.classList.remove('row-sel'); });
        tr.classList.add('row-sel');
        selLineId = Number(l.id);
        loadLineMatches();
        syncMatchBtn();
      });
      tb.appendChild(tr);
    });
    if(selLineId){
      document.querySelectorAll('#tblStmt tbody tr').forEach(function(tr){
        if(Number(tr.dataset.lineId) === selLineId) tr.classList.add('row-sel');
      });
    }
  }

  async function loadSys(){
    const b = document.getElementById('bankSel').value;
    const f = document.getElementById('dFrom').value;
    const t = document.getElementById('dTo').value;
    const r = await fetch('ajax/bank_reco_list_system.php?bank_account_id='+encodeURIComponent(b)+'&date_from='+encodeURIComponent(f)+'&date_to='+encodeURIComponent(t));
    const j = await r.json();
    const tbR = document.querySelector('#tblRec tbody');
    const tbE = document.querySelector('#tblExp tbody');
    tbR.innerHTML = '';
    tbE.innerHTML = '';
    if(!j.success){ alert(j.error||'Failed'); return; }
    j.receipts.forEach(function(x){
      const tr = document.createElement('tr');
      tr.dataset.sysType = 'receipt';
      tr.dataset.sysId = x.system_id;
      tr.innerHTML = '<td>'+esc(x.txn_date)+'</td><td class="text-end">'+Number(x.amount).toFixed(2)+'</td><td class="text-end">'+Number(x.remaining).toFixed(2)+'</td><td>'+esc(x.reference||'')+'</td><td>'+esc(x.counterparty||'')+'</td>';
      tr.addEventListener('click', function(){
        document.querySelectorAll('#tblRec tbody tr, #tblExp tbody tr').forEach(function(z){ z.classList.remove('sys-sel'); });
        tr.classList.add('sys-sel');
        selSys = {type:'receipt', id: x.system_id};
        syncMatchBtn();
      });
      tbR.appendChild(tr);
    });
    j.expenses.forEach(function(x){
      const tr = document.createElement('tr');
      tr.dataset.sysType = 'expense';
      tr.dataset.sysId = x.system_id;
      tr.innerHTML = '<td>'+esc(x.txn_date)+'</td><td class="text-end">'+Number(x.amount).toFixed(2)+'</td><td class="text-end">'+Number(x.remaining).toFixed(2)+'</td><td>'+esc(x.reference||'')+'</td><td>'+esc(x.counterparty||'')+'</td>';
      tr.addEventListener('click', function(){
        document.querySelectorAll('#tblRec tbody tr, #tblExp tbody tr').forEach(function(z){ z.classList.remove('sys-sel'); });
        tr.classList.add('sys-sel');
        selSys = {type:'expense', id: x.system_id};
        syncMatchBtn();
      });
      tbE.appendChild(tr);
    });
  }

  function syncMatchBtn(){
    const ok = selLineId && selSys;
    document.getElementById('btnMatch').disabled = !ok;
  }

  async function loadLineMatches(){
    document.getElementById('btnConfirm').disabled = true;
    document.getElementById('chkAll').checked = false;
    const tb = document.querySelector('#tblMatch tbody');
    tb.innerHTML = '';
    if(!selLineId) return;
    const r = await fetch('ajax/bank_reco_line_matches.php?line_id='+encodeURIComponent(selLineId));
    const j = await r.json();
    if(!j.success) return;
    let hasProp = false;
    j.matches.forEach(function(m){
      if(m.status === 'proposed') hasProp = true;
      const tr = document.createElement('tr');
      const chk = m.status === 'proposed' ? '<input type="checkbox" class="mchk" value="'+esc(String(m.id))+'">' : '';
      tr.innerHTML = '<td>'+chk+'</td><td>'+esc(m.system_type)+'</td><td>'+esc(String(m.system_id))+'</td><td class="text-end">'+Number(m.amount_matched).toFixed(2)+'</td><td>'+esc(m.status)+'</td><td><button type="button" class="btn btn-sm btn-outline-danger btn-unm" data-id="'+esc(String(m.id))+'">Unmatch</button></td>';
      tb.appendChild(tr);
    });
    document.getElementById('btnConfirm').disabled = !hasProp;
    tb.querySelectorAll('.btn-unm').forEach(function(btn){
      btn.addEventListener('click', async function(){
        const p = fd();
        p.append('match_id', btn.getAttribute('data-id'));
        const res = await fetch('ajax/bank_reco_unmatch.php', {method:'POST', body:p});
        const jj = await res.json();
        if(!jj.success){ alert(jj.error||'Unmatch failed'); return; }
        await refreshAll();
      });
    });
  }

  async function refreshAll(){
    document.getElementById('dupWarn').style.display = 'none';
    await loadSummary();
    await loadStmt();
    await loadSys();
    await loadLineMatches();
  }

  document.getElementById('btnReload').addEventListener('click', refreshAll);
  document.getElementById('btnAuto').addEventListener('click', async function(){
    const p = fd();
    p.append('bank_account_id', document.getElementById('bankSel').value);
    p.append('date_from', document.getElementById('dFrom').value);
    p.append('date_to', document.getElementById('dTo').value);
    const res = await fetch('ajax/bank_reco_auto_match.php', {method:'POST', body:p});
    const j = await res.json();
    if(!j.success){ alert(j.error||'Auto-match failed'); return; }
    alert('Proposed matches created: '+j.proposed_count);
    await refreshAll();
  });

  document.getElementById('btnMatch').addEventListener('click', async function(){
    const p = fd();
    p.append('bank_statement_line_id', String(selLineId));
    p.append('system_type', selSys.type);
    p.append('system_id', String(selSys.id));
    const res = await fetch('ajax/bank_reco_match.php', {method:'POST', body:p});
    const j = await res.json();
    if(!j.success){ alert(j.error||'Match failed'); return; }
    if(j.duplicate_warnings && j.duplicate_warnings.length){
      const w = document.getElementById('dupWarn');
      w.style.display = 'block';
      w.textContent = 'Soft warning: this receipt/expense is also matched on other statement line(s). Review matches. IDs: '+j.duplicate_warnings.map(function(x){return '#'+x.id;}).join(', ');
    }
    await refreshAll();
  });

  document.getElementById('btnImport').addEventListener('click', async function(){
    const f = document.getElementById('csvFile').files[0];
    if(!f){ alert('Choose a CSV'); return; }
    const p = new FormData();
    p.append('_csrf', CSRF);
    p.append('bank_account_id', document.getElementById('bankSel').value);
    p.append('file', f);
    const res = await fetch('ajax/bank_reco_import_csv.php', {method:'POST', body:p});
    const j = await res.json();
    if(!j.success){ alert(j.error||'Import failed'); return; }
    alert('Inserted '+j.inserted+'. Skipped locked: '+j.skipped_locked+', dup: '+j.skipped_dup+', bad: '+j.skipped_bad);
    await refreshAll();
  });

  document.getElementById('btnManual').addEventListener('click', async function(){
    const p = fd();
    p.append('bank_account_id', document.getElementById('bankSel').value);
    p.append('txn_date', document.getElementById('manDate').value);
    p.append('amount', document.getElementById('manAmt').value);
    p.append('description', document.getElementById('manDesc').value);
    p.append('reference', document.getElementById('manRef').value);
    const res = await fetch('ajax/bank_reco_manual_line.php', {method:'POST', body:p});
    const j = await res.json();
    if(!j.success){ alert(j.error||'Add failed'); return; }
    await refreshAll();
  });

  document.getElementById('btnConfirm').addEventListener('click', async function(){
    const ids = [];
    document.querySelectorAll('.mchk:checked').forEach(function(c){ ids.push(Number(c.value)); });
    if(!ids.length){ alert('Select proposed matches'); return; }
    const p = fd();
    p.append('match_ids', JSON.stringify(ids));
    const res = await fetch('ajax/bank_reco_confirm.php', {method:'POST', body:p});
    const j = await res.json();
    if(!j.success){ alert(j.error||'Confirm failed'); return; }
    if(j.errors && j.errors.length) alert(j.errors.join('\n'));
    await refreshAll();
  });

  document.getElementById('chkAll').addEventListener('change', function(){
    document.querySelectorAll('.mchk').forEach(function(c){ c.checked = document.getElementById('chkAll').checked; });
  });

  async function loadLocks(){
    const b = document.getElementById('bankSel').value;
    const r = await fetch('ajax/bank_reco_lock.php?bank_account_id='+encodeURIComponent(b));
    const j = await r.json();
    const el = document.getElementById('lockList');
    if(!j.success){ el.textContent = 'Locks: (error)'; return; }
    if(!j.locks.length){ el.textContent = 'Locks: none'; return; }
    el.textContent = 'Locks: '+j.locks.map(function(L){ return L.period+(L.locked=='1'||L.locked===1?' (locked)':''); }).join(', ');
  }

  document.getElementById('btnLock').addEventListener('click', async function(){
    const p = fd();
    p.append('bank_account_id', document.getElementById('bankSel').value);
    p.append('period', document.getElementById('lockPeriod').value);
    p.append('locked', '1');
    const res = await fetch('ajax/bank_reco_lock.php', {method:'POST', body:p});
    const j = await res.json();
    if(!j.success){ alert(j.error||'Lock failed'); return; }
    await loadLocks();
  });
  document.getElementById('btnUnlock').addEventListener('click', async function(){
    const p = fd();
    p.append('bank_account_id', document.getElementById('bankSel').value);
    p.append('period', document.getElementById('lockPeriod').value);
    p.append('locked', '0');
    const res = await fetch('ajax/bank_reco_lock.php', {method:'POST', body:p});
    const j = await res.json();
    if(!j.success){ alert(j.error||'Unlock failed'); return; }
    await loadLocks();
  });

  document.getElementById('bankSel').addEventListener('change', function(){ loadLocks(); });

  loadLocks();
  refreshAll();
})();
</script>
<?php endif; ?>
</body>
</html>
