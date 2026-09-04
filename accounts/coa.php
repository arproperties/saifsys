<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/cleaning_accounting_context.php';
require_once __DIR__.'/../includes/report_date_helpers.php';
require_once __DIR__.'/../includes/report_gl_helpers.php';

if (!has_permission('accounts.view', MODULE_FINANCE, $conn)) {
    require_role(['Owner','Admin','Account'], $conn);
}

$types = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];
$typeColors = [
    'Asset' => 'primary',
    'Liability' => 'danger',
    'Equity' => 'success',
    'Revenue' => 'info',
    'Expense' => 'warning',
];

$filterType = trim((string)($_GET['type'] ?? ''));
if ($filterType !== '' && !in_array($filterType, $types, true)) {
    $filterType = '';
}

$isStandalone = basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'account.php';

function coa_filter_url(?string $type = null): string
{
    global $isStandalone, $ledgerFrom, $ledgerTo;
    $params = array_filter([
        'type' => $type,
        'from' => $ledgerFrom,
        'to' => $ledgerTo,
    ]);
    $qs = http_build_query($params);
    if ($isStandalone) {
        return 'accounts/coa.php' . ($qs ? ('?' . $qs) : '');
    }
    $params = array_merge(['tab' => 'coa'], $params);
    return 'account?' . http_build_query($params);
}

$ledgerDates = report_date_range();
$ledgerFrom = $ledgerDates['from'];
$ledgerTo = $ledgerDates['to'];
$companyId = cleaning_accounting_company_id($conn);
$coaGlStats = report_gl_coa_account_stats($conn, $companyId, $ledgerFrom, $ledgerTo);

$sql = "
  SELECT c.id, c.account_no, c.name, c.type, c.parent_id, c.normal_balance, c.is_header, c.is_active,
         p.account_no AS parent_no, p.name AS parent_name
  FROM chart_of_accounts c
  LEFT JOIN chart_of_accounts p ON p.id = c.parent_id
";
$params = [];
if ($filterType !== '') {
    $sql .= " WHERE c.type = ?";
    $params[] = $filterType;
}
$sql .= "
  ORDER BY FIELD(c.type,'Asset','Liability','Equity','Revenue','Expense'), c.account_no
";
$st = $conn->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$withTxns = 0;
foreach ($coaGlStats as $stat) {
    if (($stat['period_txn_count'] ?? 0) > 0) {
        $withTxns++;
    }
}

$allRows = $conn->query("SELECT type, is_active FROM chart_of_accounts")->fetchAll(PDO::FETCH_ASSOC);
$typeCounts = array_fill_keys($types, 0);
$activeCount = 0;
foreach ($allRows as $r) {
    $typeCounts[$r['type']] = ($typeCounts[$r['type']] ?? 0) + 1;
    if ((int)$r['is_active'] === 1) {
        $activeCount++;
    }
}

$parents = $conn->query("
  SELECT id, account_no, name, type FROM chart_of_accounts
  WHERE is_header = 1 AND is_active = 1
  ORDER BY account_no
")->fetchAll(PDO::FETCH_ASSOC);

function coa_account_balance(int $accountId, array $coaGlStats): float
{
    return (float)($coaGlStats[$accountId]['balance'] ?? 0);
}

function coa_account_txn_count(int $accountId, array $coaGlStats): int
{
    return (int)($coaGlStats[$accountId]['period_txn_count'] ?? 0);
}

function coa_money(float $n): string
{
    return number_format($n, 2);
}

if ($isStandalone): ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Chart of Accounts</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php endif; ?>
<style>
  body { background: #f6f7f9; }
  .coa-wrap-embedded { margin: -0.5rem 0; }
  .coa-hero {
    background: #fff; border-radius: 18px; box-shadow: 0 10px 24px rgba(0,0,0,.06);
    padding: 18px 22px; margin-bottom: 18px;
  }
  .stat-card {
    border: 0; border-radius: 14px; background: #fff;
    box-shadow: 0 8px 20px rgba(0,0,0,.05); height: 100%;
  }
  .stat-card .stat-value { font-size: 1.35rem; font-weight: 700; }
  .stat-card .stat-label { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; }
  .coa-panel {
    border: 0; border-radius: 18px; background: #fff;
    box-shadow: 0 10px 24px rgba(0,0,0,.06); overflow: hidden;
  }
  .type-pill.active { font-weight: 600; box-shadow: 0 0 0 2px rgba(13,110,253,.25); }
  .account-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: .82rem; font-weight: 600; color: #495057;
    background: #f1f3f5; padding: .2rem .5rem; border-radius: .4rem;
  }
  .coa-table thead th {
    font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
    color: #6c757d; background: #f8f9fa; border-bottom: 1px solid #e9ecef;
  }
  .coa-table tbody tr.coa-row { cursor: pointer; }
  .coa-table tbody tr.coa-row:hover { background: #f8f9fb; }
  .coa-table tbody tr.coa-row.is-header td.name-cell { font-weight: 600; }
  .coa-table .balance-cell { font-variant-numeric: tabular-nums; font-weight: 600; }
  .coa-detail-row td { background: #f8f9fa; padding: 0 !important; }
  .coa-detail-panel { padding: 1rem 1.25rem 1.25rem 3rem; }
  .coa-detail-loading { color: #6c757d; padding: 1rem 0; }
  .badge-header { background: #fff3cd; color: #856404; }
  .badge-inactive { background: #f8d7da; color: #842029; }
  @media (max-width: 768px) {
    .coa-detail-panel { padding-left: 1rem; }
  }
</style>
<?php if ($isStandalone): ?></head>
<body>
<?php endif; ?>

<div class="<?= $isStandalone ? 'container-fluid px-3 px-lg-4 my-4' : 'coa-wrap-embedded' ?>">

  <?php if ($isStandalone): ?>
  <div class="coa-hero d-flex flex-wrap align-items-center gap-2">
    <div class="me-auto">
      <div class="text-uppercase small text-muted">Accounting</div>
      <h3 class="mb-0">Chart of Accounts</h3>
      <small class="text-muted"><?= count($rows) ?> accounts · <?= (int)$withTxns ?> with transactions</small>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#accModal" onclick="openForm()">
      <i class="bi bi-plus-lg"></i> New Account
    </button>
  </div>
  <?php else: ?>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="me-auto text-muted small"><?= count($rows) ?> accounts · <?= (int)$withTxns ?> with GL activity</div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#accModal" onclick="openForm()">
      <i class="bi bi-plus-lg"></i> New Account
    </button>
  </div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
      <div class="card stat-card"><div class="card-body py-3">
        <div class="stat-label">Total Accounts</div>
        <div class="stat-value"><?= count($rows) ?></div>
      </div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card stat-card"><div class="card-body py-3">
        <div class="stat-label">Active</div>
        <div class="stat-value text-success"><?= (int)$activeCount ?></div>
      </div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card stat-card"><div class="card-body py-3">
        <div class="stat-label">With GL Activity</div>
        <div class="stat-value text-primary"><?= (int)$withTxns ?></div>
      </div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card stat-card"><div class="card-body py-3">
        <div class="stat-label">Ledger Period</div>
        <div class="stat-value" style="font-size:1rem;"><?= h(report_date_period_label($ledgerFrom, $ledgerTo)) ?></div>
      </div></div>
    </div>
  </div>

  <form class="coa-panel mb-3 p-3 no-print" method="get" action="">
    <?php if (!$isStandalone): ?><input type="hidden" name="tab" value="coa"><?php endif; ?>
    <?php if ($filterType !== ''): ?><input type="hidden" name="type" value="<?= h($filterType) ?>"><?php endif; ?>
    <div class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small mb-1">Ledger period — From</label>
        <input type="date" class="form-control form-control-sm" name="from" value="<?= h($ledgerFrom) ?>">
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small mb-1">To</label>
        <input type="date" class="form-control form-control-sm" name="to" value="<?= h($ledgerTo) ?>">
      </div>
      <div class="col-sm-4 col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Apply</button>
      </div>
      <div class="col-md-4 small text-muted">
        Balances and transaction counts use the same rules as the General Ledger report.
      </div>
    </div>
  </form>

  <div class="coa-panel mb-3 p-3">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
      <div class="btn-group flex-wrap" role="group">
        <a href="<?= h(coa_filter_url()) ?>" class="btn btn-sm btn-outline-secondary type-pill <?= $filterType === '' ? 'active' : '' ?>">All</a>
        <?php foreach ($types as $t): ?>
          <a href="<?= h(coa_filter_url($t)) ?>" class="btn btn-sm btn-outline-<?= h($typeColors[$t]) ?> type-pill <?= $filterType === $t ? 'active' : '' ?>">
            <?= h($t) ?> <span class="badge text-bg-light text-dark"><?= (int)($typeCounts[$t] ?? 0) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="ms-md-auto" style="min-width:220px;">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="search" id="coaSearch" class="form-control" placeholder="Search account no or name…">
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover coa-table align-middle mb-0" id="coaTable">
        <thead>
          <tr>
            <th style="width:2.5rem"></th>
            <th style="width:6.5rem">No</th>
            <th>Name</th>
            <th style="width:7rem" class="d-none d-md-table-cell">Type</th>
            <th class="d-none d-lg-table-cell">Parent</th>
            <th class="text-end" style="width:7rem" title="Journals in selected period">Period txns</th>
            <th class="text-end" style="width:9rem" title="Closing balance as of <?= h($ledgerTo) ?>">Balance</th>
            <th style="width:8rem" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $accId = (int)$r['id'];
          $net = coa_account_balance($accId, $coaGlStats);
          $periodTxns = coa_account_txn_count($accId, $coaGlStats);
          $hasTxns = $periodTxns > 0;
          $hasLedger = $hasTxns || abs($net) >= 0.005;
          $typeColor = $typeColors[$r['type']] ?? 'secondary';
        ?>
          <tr class="coa-row <?= (int)$r['is_header'] ? 'is-header' : '' ?>"
              data-id="<?= $accId ?>"
              data-search="<?= h(strtolower($r['account_no'].' '.$r['name'])) ?>"
              data-has-txns="<?= $hasTxns ? '1' : '0' ?>">
            <td>
              <?php if ($hasTxns): ?>
                <button type="button" class="btn btn-sm btn-light border-0 btn-toggle-txn" title="Quick view">
                  <i class="bi bi-chevron-right"></i>
                </button>
              <?php endif; ?>
            </td>
            <td><span class="account-code"><?= h($r['account_no']) ?></span></td>
            <td class="name-cell">
              <?= h($r['name']) ?>
              <?php if ((int)$r['is_header']): ?><span class="badge badge-header ms-1">Header</span><?php endif; ?>
              <?php if (!(int)$r['is_active']): ?><span class="badge badge-inactive ms-1">Inactive</span><?php endif; ?>
              <div class="d-md-none small text-muted"><?= h($r['type']) ?></div>
            </td>
            <td class="d-none d-md-table-cell">
              <span class="badge text-bg-<?= h($typeColor) ?>"><?= h($r['type']) ?></span>
            </td>
            <td class="d-none d-lg-table-cell text-muted small">
              <?= $r['parent_no'] ? h($r['parent_no'].' — '.$r['parent_name']) : '—' ?>
            </td>
            <td class="text-end">
              <?php if ($periodTxns > 0): ?>
                <span class="badge text-bg-light text-dark border"><?= $periodTxns ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end balance-cell <?= $net < 0 ? 'text-danger' : ($net > 0 ? 'text-success' : 'text-muted') ?>">
              <?= $hasLedger ? coa_money($net) : '—' ?>
            </td>
            <td class="text-end" onclick="event.stopPropagation()">
              <div class="btn-group btn-group-sm">
                <?php if ($hasLedger): ?>
                  <a class="btn btn-outline-primary"
                     href="accounts/report_general_ledger.php?<?= h(report_date_query($ledgerFrom, $ledgerTo, ['account_id' => (int)$r['id']])) ?>"
                     title="Full ledger">
                    <i class="bi bi-journal-text"></i>
                  </a>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-secondary"
                        onclick='openForm(<?= (int)$r["id"] ?>,<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'
                        title="Edit">
                  <i class="bi bi-pencil"></i>
                </button>
                <button type="button" class="btn btn-outline-danger" onclick="delAcc(<?= (int)$r['id'] ?>)" title="Delete">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <tr class="coa-detail-row d-none" data-for="<?= (int)$r['id'] ?>">
            <td colspan="8">
              <div class="coa-detail-panel" data-loaded="0"></div>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No accounts yet. Click <strong>New Account</strong> to add one.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <p class="text-muted small">
  <i class="bi bi-info-circle"></i>
  <strong>Balance</strong> = closing balance as of <?= h($ledgerTo) ?> (matches General Ledger).
  <strong>Period txns</strong> = journals between <?= h($ledgerFrom) ?> and <?= h($ledgerTo) ?>.
  Click the arrow to preview entries, or the ledger icon for the full report.
  </p>
</div>

<!-- Modal -->
<div class="modal fade" id="accModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <form class="modal-content" id="accForm">
      <div class="modal-header">
        <h5 class="modal-title" id="accTitle">New Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="f_id">
        <div class="mb-2">
          <label class="form-label">Account No *</label>
          <input class="form-control" name="account_no" id="f_no" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Name *</label>
          <input class="form-control" name="name" id="f_name" required>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Type *</label>
            <select class="form-select" name="type" id="f_type" required>
              <?php foreach ($types as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Normal Balance *</label>
            <select class="form-select" name="normal_balance" id="f_norm" required>
              <option value="debit">debit</option>
              <option value="credit">credit</option>
            </select>
            <small class="text-muted" id="norm_hint">Select account type first</small>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label">Parent (optional, choose a header)</label>
          <select class="form-select" name="parent_id" id="f_parent">
            <option value="">— None —</option>
            <?php foreach ($parents as $p): ?>
              <option value="<?= (int)$p['id'] ?>">[<?= h($p['type']) ?>] <?= h($p['account_no'].' — '.$p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row g-2">
          <div class="col-md-4"><div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="is_header" id="f_header">
            <label class="form-check-label" for="f_header">Header</label>
          </div></div>
          <div class="col-md-4"><div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="f_active" checked>
            <label class="form-check-label" for="f_active">Active</label>
          </div></div>
        </div>
        <div id="err" class="text-danger small mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
<?php if ($isStandalone): ?>
document.addEventListener('DOMContentLoaded', function() { coaInit(); });
<?php else: ?>
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', coaInit);
else coaInit();
<?php endif; ?>

const LEDGER_FROM = <?= json_encode($ledgerFrom) ?>;
const LEDGER_TO = <?= json_encode($ledgerTo) ?>;

function fmt(n) {
  return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function coaInit() {
const f_type = document.getElementById('f_type');
const f_norm = document.getElementById('f_norm');
const norm_hint = document.getElementById('norm_hint');
const err = document.getElementById('err');
const f_no = document.getElementById('f_no');
const f_name = document.getElementById('f_name');
const f_parent = document.getElementById('f_parent');
const f_header = document.getElementById('f_header');
const f_active = document.getElementById('f_active');
if (!f_type) return;

function updateNormalBalance() {
  const type = f_type.value;
  let expectedNorm = null;
  let hintText = '';
  switch (type) {
    case 'Asset':
    case 'Expense':
      expectedNorm = 'debit';
      hintText = 'Assets and Expenses have debit normal balance';
      break;
    case 'Liability':
    case 'Equity':
    case 'Revenue':
      expectedNorm = 'credit';
      hintText = 'Liabilities, Equity, and Revenue have credit normal balance';
      break;
  }
  if (expectedNorm) {
    f_norm.value = expectedNorm;
    norm_hint.textContent = hintText;
    norm_hint.className = 'text-info';
  }
}

f_type.addEventListener('change', updateNormalBalance);

document.getElementById('accForm').addEventListener('submit', async e => {
  e.preventDefault();
  err.style.display = 'none';
  const res = await fetch('accounts/ajax/coa_save.php', { method: 'POST', body: new FormData(e.target) });
  const j = await res.json();
  if (j.success) location.reload();
  else { err.textContent = j.error || 'Failed'; err.style.display = ''; }
});

document.getElementById('coaSearch').addEventListener('input', function() {
  const q = this.value.trim().toLowerCase();
  document.querySelectorAll('#coaTable tbody tr.coa-row').forEach(row => {
    const match = !q || (row.dataset.search || '').includes(q);
    row.style.display = match ? '' : 'none';
    const detail = document.querySelector('tr.coa-detail-row[data-for="' + row.dataset.id + '"]');
    if (detail && !match) detail.classList.add('d-none');
  });
});
}

function openForm(id = null, data = null) {
  const f_type = document.getElementById('f_type');
  const f_norm = document.getElementById('f_norm');
  const f_no = document.getElementById('f_no');
  const f_name = document.getElementById('f_name');
  const f_parent = document.getElementById('f_parent');
  const f_header = document.getElementById('f_header');
  const f_active = document.getElementById('f_active');
  document.getElementById('accForm').reset();
  document.getElementById('f_id').value = id || '';
  document.getElementById('accTitle').textContent = id ? 'Edit Account' : 'New Account';
  if (data) {
    f_no.value = data.account_no || '';
    f_name.value = data.name || '';
    f_type.value = data.type || 'Asset';
    f_type.dispatchEvent(new Event('change'));
    if (id && data.normal_balance) f_norm.value = data.normal_balance;
    f_parent.value = data.parent_id || '';
    f_header.checked = !!(+data.is_header);
    f_active.checked = !!(+data.is_active);
  } else {
    f_type.dispatchEvent(new Event('change'));
  }
  new bootstrap.Modal(document.getElementById('accModal')).show();
}

async function delAcc(id) {
  if (!confirm('Delete this account? (It will be deactivated if it has journal entries)')) return;
  const fd = new FormData();
  fd.append('id', id);
  const res = await fetch('accounts/ajax/coa_delete.php', { method: 'POST', body: fd });
  const j = await res.json();
  if (j.success) location.reload();
  else alert(j.error || 'Failed');
}

async function loadAccountLedger(accountId, panel) {
  panel.innerHTML = '<div class="coa-detail-loading"><span class="spinner-border spinner-border-sm me-2"></span>Loading transactions…</div>';
  const url = 'accounts/ajax/coa_ledger.php?' + new URLSearchParams({
    account_id: accountId,
    from: LEDGER_FROM,
    to: LEDGER_TO,
    limit: 25
  });
  try {
    const res = await fetch(url);
    const data = await res.json();
    if (!data.success) {
      panel.innerHTML = '<div class="text-danger">' + (data.error || 'Failed to load') + '</div>';
      return;
    }
    const glUrl = 'accounts/report_general_ledger.php?' + new URLSearchParams({
      account_id: accountId,
      from: data.from,
      to: data.to
    });
    let html = '<div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">';
    html += '<div><strong>Recent transactions</strong> <span class="text-muted small">(' + data.from + ' to ' + data.to + ')</span></div>';
    html += '<div class="small">Debit <strong>' + fmt(data.totals.debit) + '</strong> · Credit <strong>' + fmt(data.totals.credit) + '</strong> · Net <strong>' + fmt(data.totals.net) + '</strong></div>';
    html += '<a href="' + glUrl + '" class="btn btn-sm btn-primary"><i class="bi bi-journal-text"></i> Open full ledger</a>';
    html += '</div>';
    if (!data.lines.length) {
      html += '<p class="text-muted mb-0">No transactions in this period.</p>';
    } else {
      html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr>';
      html += '<th>Date</th><th>Journal</th><th>Source</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Net</th>';
      html += '</tr></thead><tbody>';
      data.lines.forEach(line => {
        html += '<tr>';
        html += '<td>' + line.journal_date + '</td>';
        html += '<td><code>' + line.journal_no + '</code></td>';
        html += '<td><span class="badge text-bg-secondary">' + line.source + '</span></td>';
        html += '<td>' + (line.line_description || line.source_info || line.memo || '—') + '</td>';
        html += '<td class="text-end">' + fmt(line.debit) + '</td>';
        html += '<td class="text-end">' + fmt(line.credit) + '</td>';
        html += '<td class="text-end">' + fmt(line.net) + '</td>';
        html += '</tr>';
      });
      html += '</tbody></table></div>';
    }
    panel.innerHTML = html;
    panel.dataset.loaded = '1';
  } catch (e) {
    panel.innerHTML = '<div class="text-danger">Could not load transactions.</div>';
  }
}

function toggleAccountRow(row) {
  if (row.dataset.hasTxns !== '1') return;
  const id = row.dataset.id;
  const detailRow = document.querySelector('tr.coa-detail-row[data-for="' + id + '"]');
  const btn = row.querySelector('.btn-toggle-txn i');
  if (!detailRow) return;
  const isOpen = !detailRow.classList.contains('d-none');
  document.querySelectorAll('tr.coa-detail-row').forEach(r => r.classList.add('d-none'));
  document.querySelectorAll('.btn-toggle-txn i').forEach(i => {
    i.classList.remove('bi-chevron-down');
    i.classList.add('bi-chevron-right');
  });
  if (isOpen) return;
  detailRow.classList.remove('d-none');
  if (btn) {
    btn.classList.remove('bi-chevron-right');
    btn.classList.add('bi-chevron-down');
  }
  const panel = detailRow.querySelector('.coa-detail-panel');
  if (panel && panel.dataset.loaded !== '1') {
    loadAccountLedger(id, panel);
  }
}

document.querySelectorAll('tr.coa-row').forEach(row => {
  row.addEventListener('click', e => {
    if (e.target.closest('a, button, .btn-group')) return;
    toggleAccountRow(row);
  });
});
document.querySelectorAll('.btn-toggle-txn').forEach(btn => {
  btn.addEventListener('click', e => {
    e.stopPropagation();
    toggleAccountRow(btn.closest('tr.coa-row'));
  });
});
}
</script>
<?php if ($isStandalone): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php endif; ?>
