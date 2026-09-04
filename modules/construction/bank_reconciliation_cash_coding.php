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
co_bank_reco_require_permission($conn, 'construction.bank_reconciliation.cash_coding');

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$csrf = csrf_token();
$tablesReady = co_bank_reco_tables_ready($conn);

$banks = [];
$coaAccounts = [];
$projects = [];
$contacts = ['clients' => [], 'suppliers' => [], 'contractors' => []];
if ($tablesReady) {
    $st = $conn->prepare("
        SELECT b.id, b.account_name AS name, coa.account_code AS account_no
        FROM re_bank_accounts b
        JOIN re_chart_of_accounts coa ON coa.id = b.gl_account_id AND coa.company_id = b.company_id
        WHERE b.company_id = ? AND b.is_active = 1 ORDER BY coa.account_code
    ");
    $st->execute([$cid]);
    $banks = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $st = $conn->prepare('SELECT id, account_code, account_name FROM re_chart_of_accounts WHERE company_id = ? AND is_active = 1 ORDER BY account_code');
    $st->execute([$cid]);
    $coaAccounts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (co_db_table_exists($conn, 'co_projects')) {
        $st = $conn->prepare('SELECT id, project_code, project_name FROM co_projects WHERE company_id = ? ORDER BY project_code');
        $st->execute([$cid]);
        $projects = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $contacts = co_bank_reco_contacts($conn, $cid);
}

$selectedBank = !empty($_GET['bank_account_id']) ? (int) $_GET['bank_account_id'] : ((int) ($banks[0]['id'] ?? 0));
$df = $_GET['date_from'] ?? date('Y-m-01');
$dt = $_GET['date_to'] ?? date('Y-m-d');

$pageTitle = 'Cash Coding';
$pageHead = '<link href="assets/bank_reconciliation.css?v=20260712-contrast" rel="stylesheet">';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="co-breco-hero">
  <div class="d-flex flex-wrap align-items-start gap-3">
    <div class="flex-grow-1">
      <div class="text-uppercase small text-muted">Construction · Bank Reconciliation</div>
      <h3 class="mb-1">Cash coding</h3>
      <div class="text-muted small">Code unreconciled statement lines in bulk (up to 200 per page), then save &amp; reconcile selected rows.</div>
    </div>
    <a href="bank_reconciliation.php?bank_account_id=<?= (int)$selectedBank ?>" class="btn btn-outline-primary btn-sm">Reconcile workbench</a>
  </div>
</div>

<?php if (!$tablesReady || !$banks): ?>
  <div class="alert alert-warning">Import statement lines first, then use cash coding.</div>
<?php else: ?>

<form class="row g-2 align-items-end mb-3" id="filterForm">
  <div class="col-auto"><label class="form-label small mb-0">Bank</label>
    <select id="bankSel" class="form-select form-select-sm">
      <?php foreach ($banks as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= $selectedBank === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['account_no'].' — '.$b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto"><label class="form-label small mb-0">From</label><input type="date" id="dFrom" class="form-control form-control-sm" value="<?= h($df) ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0">To</label><input type="date" id="dTo" class="form-control form-control-sm" value="<?= h($dt) ?>"></div>
  <div class="col-auto"><button type="button" class="btn btn-primary btn-sm" id="btnLoad">Load lines</button></div>
</form>

<div class="card shadow-sm mb-2">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label small mb-0">Bulk apply account</label>
        <select id="bulkAccount" class="form-select form-select-sm"><option value="">—</option>
          <?php foreach ($coaAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['account_code'].' — '.$a['account_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label small mb-0">Bulk apply type</label>
        <select id="bulkType" class="form-select form-select-sm">
          <option value="">—</option>
          <option value="quick_expense">Quick expense</option>
          <option value="bank_charge">Bank charge</option>
          <option value="client_receipt">Client receipt</option>
          <option value="direct_project_expense">Direct project expense</option>
        </select>
      </div>
      <div class="col-auto"><button type="button" class="btn btn-outline-secondary btn-sm" id="btnBulkApply">Apply to selected</button></div>
      <div class="col-auto ms-auto"><button type="button" class="btn btn-success btn-sm" id="btnBulkReconcile">Save &amp; Reconcile selected</button></div>
    </div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="table-responsive" style="max-height:620px">
    <table class="table table-sm table-hover mb-0 align-middle" id="cashGrid">
      <thead class="table-light sticky-top">
        <tr>
          <th><input type="checkbox" id="chkAll"></th>
          <th>Date</th><th>Description</th><th>Ref</th><th class="text-end">Amount</th>
          <th>Rule</th><th>Type</th><th>Who</th><th>What</th><th>Why</th>
        </tr>
      </thead>
      <tbody id="cashBody"><tr><td colspan="10" class="text-muted text-center py-4">Click Load lines</td></tr></tbody>
    </table>
  </div>
</div>
<div id="bulkResult" class="small mt-2"></div>

<script>
window.CO_BRECO_ACCOUNTS = <?= json_encode($coaAccounts) ?>;
window.CO_BRECO_CONTACTS = <?= json_encode($contacts) ?>;
(function(){
  const CSRF = <?= json_encode($csrf) ?>;
  const contacts = window.CO_BRECO_CONTACTS || {clients:[],suppliers:[],contractors:[]};
  let rows = [];

  function esc(s){ const d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

  function contactOptions(selected){
    let h = '<option value="">—</option>';
    [['clients','client','Clients'],['suppliers','supplier','Suppliers'],['contractors','contractor','Contractors']].forEach(function(g){
      h += '<optgroup label="'+g[2]+'">';
      (contacts[g[0]]||[]).forEach(function(c){
        const v = g[1]+':'+c.id;
        h += '<option value="'+v+'"'+(v===selected?' selected':'')+'>'+esc(c.name)+'</option>';
      });
      h += '</optgroup>';
    });
    return h;
  }

  function accountOptions(selected){
    return '<option value="">—</option>' + (window.CO_BRECO_ACCOUNTS||[]).map(function(a){
      return '<option value="'+a.id+'"'+(String(a.id)===String(selected)?' selected':'')+'>'+esc(a.account_code+' — '+a.account_name)+'</option>';
    }).join('');
  }

  function typeOptions(selected){
    const types = [['quick_expense','Quick expense'],['bank_charge','Bank charge'],['client_receipt','Client receipt'],['direct_project_expense','Direct project expense'],['cash_withdrawal','Cash withdrawal']];
    return types.map(function(t){ return '<option value="'+t[0]+'"'+(t[0]===selected?' selected':'')+'>'+t[1]+'</option>'; }).join('');
  }

  async function loadLines(){
    const q = new URLSearchParams({
      bank_account_id: document.getElementById('bankSel').value,
      date_from: document.getElementById('dFrom').value,
      date_to: document.getElementById('dTo').value
    });
    const r = await fetch('ajax/bank_reco_cash_coding_list.php?'+q);
    const j = await r.json();
    if (!j.success) { alert(j.error||'Load failed'); return; }
    rows = j.lines || [];
    renderGrid();
  }

  function renderGrid(){
    const body = document.getElementById('cashBody');
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">No unreconciled lines in this period.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function(row, i){
      const p = row.prefill || {};
      return '<tr data-idx="'+i+'">' +
        '<td><input type="checkbox" class="row-chk" data-idx="'+i+'"></td>' +
        '<td class="small">'+esc(row.txn_date)+'</td>' +
        '<td class="small">'+esc(row.description)+'</td>' +
        '<td class="small">'+esc(row.reference)+'</td>' +
        '<td class="text-end '+(row.is_spent?'text-danger':'text-success')+'">'+Number(row.abs_amount).toFixed(2)+'</td>' +
        '<td class="small">'+(row.rule_name ? esc(row.rule_name) : '—')+'</td>' +
        '<td><select class="form-select form-select-sm f-type">'+typeOptions(p.transaction_type||'quick_expense')+'</select></td>' +
        '<td><select class="form-select form-select-sm f-contact">'+contactOptions(p.contact||'')+'</select></td>' +
        '<td><select class="form-select form-select-sm f-account">'+accountOptions(p.account_id||'')+'</select></td>' +
        '<td><input type="text" class="form-control form-control-sm f-desc" value="'+esc(p.description||row.description||'')+'"></td>' +
      '</tr>';
    }).join('');
  }

  document.getElementById('btnLoad').addEventListener('click', loadLines);
  document.getElementById('chkAll').addEventListener('change', function(){
    document.querySelectorAll('.row-chk').forEach(function(c){ c.checked = document.getElementById('chkAll').checked; });
  });
  document.getElementById('btnBulkApply').addEventListener('click', function(){
    const acct = document.getElementById('bulkAccount').value;
    const typ = document.getElementById('bulkType').value;
    document.querySelectorAll('.row-chk:checked').forEach(function(chk){
      const tr = chk.closest('tr');
      if (acct) tr.querySelector('.f-account').value = acct;
      if (typ) tr.querySelector('.f-type').value = typ;
    });
  });
  document.getElementById('btnBulkReconcile').addEventListener('click', async function(){
    const payload = [];
    document.querySelectorAll('.row-chk:checked').forEach(function(chk){
      const tr = chk.closest('tr');
      const idx = Number(tr.getAttribute('data-idx'));
      const row = rows[idx];
      const accountId = tr.querySelector('.f-account').value;
      if (!accountId) return;
      payload.push({
        line_id: row.id,
        account_id: Number(accountId),
        transaction_type: tr.querySelector('.f-type').value,
        contact: tr.querySelector('.f-contact').value,
        description: tr.querySelector('.f-desc').value,
        reference: row.reference || ''
      });
    });
    if (!payload.length) { alert('Select rows with an account (What) filled in.'); return; }
    if (!confirm('Create & reconcile '+payload.length+' line(s)?')) return;
    const p = new URLSearchParams({_csrf: CSRF, rows: JSON.stringify(payload)});
    const r = await fetch('ajax/bank_reco_cash_coding_bulk.php', {method:'POST', body:p});
    const j = await r.json();
    document.getElementById('bulkResult').innerHTML = j.success
      ? ('Reconciled <strong>'+j.processed+'</strong> line(s).'+(j.failed&&j.failed.length ? ' Failed: '+j.failed.length : ''))
      : esc(j.error||'Bulk reconcile failed');
    if (j.success) loadLines();
  });

  loadLines();
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
