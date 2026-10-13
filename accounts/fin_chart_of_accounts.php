<?php
/**
 * Finance > Chart of Accounts (New). One chart per company (fin_coa_accounts),
 * shown for the company selected in the session.
 */
require_once __DIR__ . '/../includes/fin_context.php';

$tableOk = fin_coa_table_ready($conn);
$msg = $_GET['msg'] ?? '';
$err = '';

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $code = trim($_POST['code'] ?? '');
            fin_coa_add_account($conn, $companyId, $code, $_POST['name'] ?? '', $_POST['normal_balance'] ?? null, $userId);
            $done = "Account {$code} added.";
        } elseif ($action === 'edit') {
            fin_coa_update_account($conn, $companyId, (int)($_POST['id'] ?? 0), $_POST['name'] ?? '', $_POST['normal_balance'] ?? '', !empty($_POST['is_active']));
            $done = 'Account updated.';
        } elseif ($action === 'load_sample') {
            $n = fin_coa_load_sample($conn, $companyId, $userId);
            $done = "Starting chart loaded: {$n} accounts.";
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        header('Location: fin_chart_of_accounts.php?msg=' . urlencode($done));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_chart_of_accounts: ' . $e->getMessage());
        $err = 'The change could not be saved.';
    }
}

$rows = ($tableOk && $company) ? fin_coa_accounts($conn, $companyId, true) : [];
$codes = array_column($rows, 'code');
$levelKeys = array_keys(FIN_COA_LEVELS);
$postedUnder = $err ? (string)($_POST['under'] ?? '') : '';
$ledgerTotal = 0;
foreach ($rows as $r) {
    if ($r['is_leaf']) $ledgerTotal++;
}
$pageTitle = 'Chart of Accounts';
$finActive = 'coa';
$pageSubtitle = $rows ? $ledgerTotal . ' posting accounts' : '';
$pageStyles = '
  .coa-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  .coa-toggle { width: 1.25rem; display: inline-block; cursor: pointer; color: #6c757d; }
  tr.coa-head td { font-weight: 600; }
  tr.coa-l-class td { background: #d5ebc8; }
  tr.coa-l-group td { background: #ecd3ee; }
  tr.coa-l-sub_group td, tr.coa-l-category td { background: #dbe8f6; }
  #coaTable th.coa-step, #coaTable td.coa-step { width: 84px; white-space: nowrap; }
  tr.coa-off td { color: #adb5bd; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its chart of accounts.</div>
  <?php else: ?>

  <div class="card mb-3">
    <div class="card-header">Add account</div>
    <div class="card-body">
      <form method="post" class="row g-2 align-items-end">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="add">
        <div class="col-md-5">
          <label class="form-label small mb-1" for="addUnder">Add under</label>
          <select class="form-select" id="addUnder" name="under">
            <option value="" data-group="<?= h((string)fin_coa_suggest_code($codes, null, false)) ?>" data-ledger="">Top of the chart</option>
            <?php foreach ($rows as $r): if ($r['level'] === 'ledger' || $r['level'] === 'total') continue; ?>
            <option value="<?= h($r['code']) ?>"
                    data-group="<?= h((string)fin_coa_suggest_code($codes, $r['code'], false)) ?>"
                    data-ledger="<?= h((string)fin_coa_suggest_code($codes, $r['code'], true)) ?>"
                    <?= $postedUnder === $r['code'] ? 'selected' : '' ?>><?= h($r['code'] . ' · ' . $r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small mb-1" for="addKind">Type</label>
          <select class="form-select" id="addKind" name="kind">
            <option value="group">Group (next level down)</option>
            <option value="ledger" <?= $err && ($_POST['kind'] ?? '') === 'ledger' ? 'selected' : '' ?>>Ledger account</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1" for="addCode">Code</label>
          <input class="form-control coa-code" id="addCode" name="code" maxlength="7" pattern="[1-9][0-9]{6}" inputmode="numeric" placeholder="1112002" required value="<?= $err ? h($_POST['code'] ?? '') : '' ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1" for="addNormal">Normal balance</label>
          <select class="form-select" id="addNormal" name="normal_balance">
            <option value="">Automatic</option>
            <option value="debit">Debit</option>
            <option value="credit">Credit</option>
          </select>
        </div>
        <div class="col-md-10">
          <label class="form-label small mb-1" for="addName">Name</label>
          <input class="form-control" id="addName" name="name" maxlength="150" required value="<?= $err ? h($_POST['name'] ?? '') : '' ?>">
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Add</button></div>
        <div class="col-12 form-text" id="addHint">Pick where the account goes and the next free code is filled in; you can type another code. 7 digits: one for each of the four group levels, then a 3-digit ledger number. Entries are posted to the last level of each branch.</div>
      </form>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="card">
      <div class="card-body text-center py-5">
        <p class="mb-3">This company has no accounts yet.</p>
        <form method="post">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="load_sample">
          <button class="btn btn-outline-primary">Load starting chart</button>
        </form>
        <p class="text-muted small mt-3 mb-0">Adds the <?= count(fin_coa_sample_chart()) ?> lines of the Chart Of Accounts sheet, total lines included, with the same codes and names.</p>
      </div>
    </div>
  <?php else: ?>
  <div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
      <input type="search" class="form-control form-control-sm" id="coaSearch" placeholder="Search code or name" style="max-width:260px">
      <div class="ms-auto">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="coaExpand">Expand all</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="coaCollapse">Collapse all</button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0" id="coaTable">
        <thead><tr><?php foreach (FIN_COA_LEVELS as $label): ?><th class="coa-step"><?= h($label) ?></th><?php endforeach; ?><th class="coa-step text-center" colspan="2">Totals</th><th style="width:34%">Name</th><th>Normal balance</th><th class="text-end" title="Accounts under this heading that entries can be posted to">Posting accounts</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $isLedger = $r['is_leaf'];
            $isTotal = $r['level'] === 'total';
            // A total line takes the colour of the heading it closes and sits where the sheet puts it.
            $shade = $isTotal ? fin_coa_total_of($r['code']) : $r['level'];
            $step = $isTotal ? ['class' => 0, 'group' => 1, 'sub_group' => 6, 'category' => 5][$shade] : array_search($r['level'], $levelKeys, true);
          ?>
          <tr class="<?= $isLedger || $isTotal ? '' : 'coa-head' ?> coa-l-<?= h($shade) ?> <?= $r['is_active'] ? '' : 'coa-off' ?>"
              data-id="<?= (int)$r['id'] ?>" data-parent="<?= (int)$r['parent_id'] ?>"
              data-code="<?= h($r['code']) ?>" data-name="<?= h($r['name']) ?>"
              data-normal="<?= h($r['normal_balance']) ?>" data-active="<?= (int)$r['is_active'] ?>">
            <?php for ($i = 0; $i < 7; $i++): ?><td class="coa-code coa-step"><?= $i === $step ? h($r['code']) : '' ?></td><?php endfor; ?>
            <td>
              <span class="coa-toggle"><?php if (!$isLedger && !$isTotal): ?><i class="bi bi-chevron-down"></i><?php endif; ?></span><?= h($r['name']) ?>
            </td>
            <td class="small"><?= $isTotal ? '' : ($r['normal_balance'] === 'debit' ? 'Debit' : 'Credit') ?></td>
            <td class="text-end small"><?= $isLedger || $isTotal ? '' : (int)$r['ledger_count'] ?></td>
            <td><?= $isTotal ? '' : ($r['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>') ?></td>
            <td class="text-end text-nowrap"><?php if ($isLedger): ?><a href="fin_account_ledger.php?account_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-link py-0">Ledger</a><?php endif; ?><?php if ($r['level'] !== 'ledger' && !$isTotal): ?><button type="button" class="btn btn-sm btn-link py-0 coa-add">Add under</button><?php endif; ?><button type="button" class="btn btn-sm btn-link py-0 coa-edit">Edit</button></td>
          </tr>
        <?php endforeach; ?>
          <tr id="coaNoMatch" class="d-none"><td colspan="12" class="text-muted text-center py-3">No accounts match.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="editId">
        <div class="modal-header">
          <h5 class="modal-title">Edit <span class="coa-code" id="editCode"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="editName">Name</label>
            <input class="form-control" id="editName" name="name" maxlength="150" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="editNormal">Normal balance</label>
            <select class="form-select" id="editNormal" name="normal_balance">
              <option value="debit">Debit</option>
              <option value="credit">Credit</option>
            </select>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="editActive" name="is_active" value="1">
            <label class="form-check-label" for="editActive">Active</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  const LEVELS = <?= json_encode(array_values(FIN_COA_LEVELS)) ?>;
  const code = document.getElementById('addCode');
  const hint = document.getElementById('addHint');
  const under = document.getElementById('addUnder');
  const kind = document.getElementById('addKind');
  // Fills the code with the next free one for the chosen place and type.
  function suggest() {
    const place = under.selectedOptions[0];
    const group = kind.querySelector('option[value="group"]');
    const ledger = kind.querySelector('option[value="ledger"]');
    group.disabled = !place.dataset.group;
    ledger.disabled = !place.dataset.ledger;
    let pick = kind.value;
    if (kind.selectedOptions[0].disabled) pick = group.disabled ? 'ledger' : 'group';
    window.finSetSelect(kind, pick);
    code.value = place.dataset[pick] || '';
    code.dispatchEvent(new Event('input'));
  }
  if (under && kind) {
    under.addEventListener('change', suggest);
    kind.addEventListener('change', suggest);
  }
  if (code && hint) {
    const base = hint.textContent;
    code.addEventListener('input', () => {
      const v = code.value.trim();
      if (!/^[1-9][0-9]{6}$/.test(v)) { hint.textContent = base; return; }
      if (v.slice(4) !== '000') {
        hint.textContent = v.slice(4) === '999' ? 'This will be a total line: it takes no entries.' : 'This will be a ledger account.';
        return;
      }
      const depth = v[3] !== '0' ? 3 : v[2] !== '0' ? 2 : v[1] !== '0' ? 1 : 0;
      hint.textContent = 'This will be a heading: ' + LEVELS[depth] + '.';
    });
  }
  // After a refused save the form keeps what was typed.
  if (under && kind && code) {
    const typed = code.value;
    suggest();
    if (typed) { code.value = typed; code.dispatchEvent(new Event('input')); }
  }

  const table = document.getElementById('coaTable');
  if (!table) return;
  const rows = Array.from(table.querySelectorAll('tbody tr[data-id]'));
  const byId = new Map(rows.map(r => [r.dataset.id, r]));
  const collapsed = new Set();
  const search = document.getElementById('coaSearch');
  const noMatch = document.getElementById('coaNoMatch');

  function hiddenByParent(row) {
    let p = row.dataset.parent;
    while (p && p !== '0') {
      if (collapsed.has(p)) return true;
      const pr = byId.get(p);
      p = pr ? pr.dataset.parent : null;
    }
    return false;
  }

  function render() {
    const q = search.value.trim().toLowerCase();
    let shown = 0;
    if (q) {
      // Show matches together with the headings above them.
      const keep = new Set();
      rows.forEach(r => {
        if ((r.dataset.code + ' ' + r.dataset.name).toLowerCase().includes(q)) {
          let cur = r;
          while (cur) { keep.add(cur.dataset.id); cur = byId.get(cur.dataset.parent); }
        }
      });
      rows.forEach(r => { const on = keep.has(r.dataset.id); r.classList.toggle('d-none', !on); if (on) shown++; });
    } else {
      rows.forEach(r => { const off = hiddenByParent(r); r.classList.toggle('d-none', off); if (!off) shown++; });
    }
    rows.forEach(r => {
      const icon = r.querySelector('.coa-toggle i');
      if (icon) icon.className = 'bi ' + (collapsed.has(r.dataset.id) && !q ? 'bi-chevron-right' : 'bi-chevron-down');
    });
    noMatch.classList.toggle('d-none', shown > 0);
  }

  table.addEventListener('click', e => {
    const row = e.target.closest('tr[data-id]');
    if (!row) return;
    if (e.target.closest('.coa-edit')) {
      document.getElementById('editId').value = row.dataset.id;
      document.getElementById('editCode').textContent = row.dataset.code;
      document.getElementById('editName').value = row.dataset.name;
      window.finSetSelect(document.getElementById('editNormal'), row.dataset.normal);
      document.getElementById('editActive').checked = row.dataset.active === '1';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
      return;
    }
    if (e.target.closest('.coa-add')) {
      window.finSetSelect(under, row.dataset.code);
      suggest();
      under.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
      document.getElementById('addName').focus({ preventScroll: true });
      return;
    }
    if (e.target.closest('.coa-toggle') && row.querySelector('.coa-toggle i')) {
      collapsed.has(row.dataset.id) ? collapsed.delete(row.dataset.id) : collapsed.add(row.dataset.id);
      render();
    }
  });
  search.addEventListener('input', render);
  document.getElementById('coaExpand').addEventListener('click', () => { collapsed.clear(); render(); });
  document.getElementById('coaCollapse').addEventListener('click', () => {
    rows.forEach(r => { if (r.querySelector('.coa-toggle i')) collapsed.add(r.dataset.id); });
    render();
  });
})();
</script>
</body>
</html>
