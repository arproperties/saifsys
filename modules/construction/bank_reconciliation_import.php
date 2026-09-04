<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_bank_reconciliation.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
co_bank_reco_require_permission($conn, 'construction.bank_reconciliation.import');

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$tablesReady = co_bank_reco_tables_ready($conn);
$bankAccountId = !empty($_REQUEST['bank_account_id']) ? (int) $_REQUEST['bank_account_id'] : 0;

$banks = [];
if ($tablesReady) {
    $st = $conn->prepare("
        SELECT b.id, b.account_name AS name, coa.account_code AS account_no
        FROM re_bank_accounts b
        JOIN re_chart_of_accounts coa ON coa.id = b.gl_account_id AND coa.company_id = b.company_id
        WHERE b.company_id = ? AND b.is_active = 1
        ORDER BY coa.account_code
    ");
    $st->execute([$cid]);
    $banks = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$csrf = csrf_token();
$pageTitle = 'Import Bank Statement';
$pageHead = '<link href="assets/bank_reconciliation.css?v=20260712-contrast" rel="stylesheet">';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="co-breco-hero">
  <div class="d-flex flex-wrap align-items-center gap-2">
    <div class="flex-grow-1">
      <div class="text-uppercase small text-muted">Construction · Bank Reconciliation</div>
      <h3 class="mb-0">Import bank statement</h3>
      <div class="text-muted small">Upload CSV or Excel, review preview, then confirm import. Duplicates are skipped automatically.</div>
    </div>
    <div class="d-flex gap-2">
      <a href="bank_reconciliation_accounts.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
      <a href="bank_reconciliation.php<?= $bankAccountId ? '?bank_account_id=' . (int) $bankAccountId : '' ?>" class="btn btn-primary btn-sm">Reconcile</a>
    </div>
  </div>
</div>

<?php if (!$tablesReady): ?>
  <div class="alert alert-warning">Run <code>migrations/construction_bank_reconciliation.sql</code> and <code>migrations/construction_bank_reconciliation_v2.sql</code>.</div>
<?php elseif (!$banks): ?>
  <div class="alert alert-warning">No bank accounts configured. <a href="bank_reconciliation_accounts.php">Add a bank account</a>.</div>
<?php else: ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Bank account</label>
        <select id="bankSel" class="form-select">
          <?php foreach ($banks as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= $bankAccountId === (int) $b['id'] ? 'selected' : '' ?>><?= h($b['account_no'] . ' — ' . $b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Statement file</label>
        <input type="file" id="importFile" class="form-control" accept=".csv,.xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv">
        <div class="form-text">
          <a href="ajax/bank_reco_import_template.php" class="fw-semibold">Download Excel template (.xlsx)</a>
          · <a href="templates/bank_statement_import_template.csv" download>CSV template</a>
          <br>Columns: <strong>date</strong> (DD-MM-YYYY), <strong>description</strong>, <strong>reference</strong>, <strong>Debit</strong>, <strong>Credit</strong>
          — or use a single <strong>amount</strong> column (+/-) instead of Debit/Credit.
        </div>
      </div>
      <div class="col-md-3">
        <button type="button" class="btn btn-primary w-100" id="btnPreview">Preview import</button>
      </div>
    </div>
  </div>
</div>

<div id="previewCard" class="card shadow-sm d-none">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Import preview</strong>
    <button type="button" class="btn btn-success btn-sm" id="btnConfirm">Confirm import</button>
  </div>
  <div class="card-body">
    <div class="row g-2 mb-3" id="previewSummary"></div>
    <div class="table-responsive" style="max-height:360px">
      <table class="table table-sm table-striped mb-0">
        <thead class="table-light sticky-top"><tr><th>Date</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Amount</th><th>Description</th><th>Ref</th></tr></thead>
        <tbody id="previewRows"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function(){
  const CSRF = <?= json_encode($csrf) ?>;
  const ajaxBase = 'ajax/';
  function esc(s){ const d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

  document.getElementById('btnPreview').addEventListener('click', async function(){
    const f = document.getElementById('importFile').files[0];
    if (!f) { alert('Choose a file'); return; }
    const btn = document.getElementById('btnPreview');
    btn.disabled = true;
    btn.textContent = 'Loading…';
    try {
      const fd = new FormData();
      fd.append('_csrf', CSRF);
      fd.append('bank_account_id', document.getElementById('bankSel').value);
      fd.append('file', f);
      const res = await fetch(ajaxBase + 'bank_reco_import_preview.php', {method:'POST', body:fd});
      const text = await res.text();
      let j;
      try { j = JSON.parse(text); } catch (parseErr) {
        console.error('Preview response:', text);
        alert('Import preview failed (server error). If you see a PHP error in the browser, contact IT. You can also try CSV format.');
        return;
      }
      if (!j.success) { alert(j.error || 'Preview failed'); return; }
      const s = j.summary;
      document.getElementById('previewSummary').innerHTML =
        ['Total rows','Valid rows','Duplicates','Errors','Total debits','Total credits','Closing balance'].map(function(l,i){
          const vals = [s.total_rows,s.valid_rows,s.duplicate_rows,s.error_rows,s.total_debits,s.total_credits,s.closing_balance!=null?s.closing_balance:'—'];
          return '<div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">'+l+'</div><div class="fw-bold">'+esc(vals[i])+'</div></div></div>';
        }).join('');
      const tb = document.getElementById('previewRows');
      tb.innerHTML = '';
      (j.preview_rows || []).forEach(function(r){
        const tr = document.createElement('tr');
        const amt = Number(r.amount);
        const debit = r.debit_amount != null ? Number(r.debit_amount) : (amt < 0 ? Math.abs(amt) : 0);
        const credit = r.credit_amount != null ? Number(r.credit_amount) : (amt > 0 ? amt : 0);
        tr.innerHTML = '<td>'+esc(r.txn_date)+'</td><td class="text-end">'+(debit > 0 ? debit.toFixed(2) : '—')+'</td><td class="text-end">'+(credit > 0 ? credit.toFixed(2) : '—')+'</td><td class="text-end">'+amt.toFixed(2)+'</td><td>'+esc(r.description)+'</td><td>'+esc(r.reference||'')+'</td>';
        tb.appendChild(tr);
      });
      document.getElementById('previewCard').classList.remove('d-none');
      if (s.error_rows > 0 && s.errors && s.errors.length) {
        alert('Imported with ' + s.error_rows + ' row error(s). First: line ' + s.errors[0].line + ' — ' + s.errors[0].reason);
      }
    } catch (e) {
      alert('Network error: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Preview import';
    }
  });

  document.getElementById('btnConfirm').addEventListener('click', async function(){
    const p = new URLSearchParams({_csrf: CSRF, bank_account_id: document.getElementById('bankSel').value});
    const res = await fetch(ajaxBase + 'bank_reco_import_confirm.php', {method:'POST', body:p});
    const j = await res.json();
    if (!j.success) { alert(j.error || 'Import failed'); return; }
    let msg = 'Imported ' + j.inserted + ' lines.';
    if (j.auto_reconcile_enabled && j.auto_confirmed_count > 0) {
      msg += ' Auto-reconciled ' + j.auto_confirmed_count + ' line(s) via bank rules.';
    }
    alert(msg);
    window.location.href = 'bank_reconciliation.php?bank_account_id=' + encodeURIComponent(document.getElementById('bankSel').value);
  });
})();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
