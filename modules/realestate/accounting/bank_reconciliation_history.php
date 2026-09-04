<?php
/**
 * Real Estate — Reconciliation history with Remove & Redo.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../includes/re_bank_reco_core.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}
re_bank_reco_require_permission($conn, 'realestate.bank_reconciliation.view');

$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$tablesReady = re_bank_reco_tables_ready($conn);
$csrf = csrf_token();

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

$selectedBank = !empty($_GET['bank_account_id']) ? (int) $_GET['bank_account_id'] : 0;
$df = $_GET['date_from'] ?? date('Y-m-01');
$dt = $_GET['date_to'] ?? date('Y-m-d');

$history = [];
if ($tablesReady) {
    $history = re_bank_reco_history($conn, $cid, $selectedBank ?: null, $df, $dt, 300);
}

$canUndo = re_bank_reco_has_permission($conn, 'realestate.bank_reconciliation.undo');

$pageTitle = 'Reconciliation History';
$pageHead = '<link href="assets/bank_reconciliation.css?v=20260703p2" rel="stylesheet">';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="co-breco-hero">
  <div class="d-flex flex-wrap align-items-start gap-3">
    <div class="flex-grow-1">
      <div class="text-uppercase small text-muted">Real Estate · Bank Reconciliation</div>
      <h3 class="mb-1">Reconciliation history</h3>
      <div class="text-muted small">Confirmed matches with Remove &amp; Redo for corrections. Created transactions are reversed when undone.</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a href="bank_reconciliation_accounts.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
      <a href="bank_reconciliation.php" class="btn btn-outline-primary btn-sm">Reconcile</a>
    </div>
  </div>
</div>

<?php if (!$tablesReady): ?>
  <div class="alert alert-warning">Bank reconciliation tables are not installed.</div>
<?php else: ?>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-auto">
    <label class="form-label small mb-0">Bank</label>
    <select name="bank_account_id" class="form-select form-select-sm">
      <option value="">All banks</option>
      <?php foreach ($banks as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= $selectedBank === (int) $b['id'] ? 'selected' : '' ?>><?= h($b['account_no'] . ' — ' . $b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small mb-0">From</label>
    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($df) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small mb-0">To</label>
    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dt) ?>">
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
  </div>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Confirmed</th>
          <th>Bank</th>
          <th>Statement line</th>
          <th class="text-end">Matched</th>
          <th>ERP transaction</th>
          <th>Method</th>
          <th>By</th>
          <?php if ($canUndo): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$history): ?>
          <tr><td colspan="<?= $canUndo ? 8 : 7 ?>" class="text-center text-muted py-4">No confirmed matches in this period.</td></tr>
        <?php else: ?>
          <?php foreach ($history as $row): ?>
            <?php
              $createdJournal = (int) ($row['created_transaction_id'] ?? 0);
              $method = (string) ($row['match_method'] ?? 'match');
              $needsReverse = $createdJournal > 0 && in_array($method, ['create', 'adjustment', 'transfer'], true);
            ?>
            <tr data-match-id="<?= (int) $row['id'] ?>">
              <td class="small"><?= h(substr((string) ($row['confirmed_at'] ?? ''), 0, 16)) ?></td>
              <td class="small"><?= h(($row['account_code'] ?? '') . ' ' . ($row['bank_name'] ?? '')) ?></td>
              <td>
                <div class="small fw-semibold"><?= h($row['line_description'] ?: '(No description)') ?></div>
                <div class="text-muted small"><?= h($row['txn_date'] ?? '') ?><?= !empty($row['line_reference']) ? ' · ' . h($row['line_reference']) : '' ?></div>
              </td>
              <td class="text-end"><?= number_format((float) ($row['amount_matched'] ?? 0), 2) ?></td>
              <td class="small">
                <?= h(trim(($row['journal_number'] ?? 'GL') . ' — ' . ($row['gl_description'] ?: $row['gl_reference'] ?: ($row['source_table'] ?? '')))) ?>
                <?php if ($createdJournal): ?>
                  <div class="text-muted">JE #<?= $createdJournal ?></div>
                <?php endif; ?>
              </td>
              <td><span class="badge bg-light text-dark border"><?= h($row['method_label'] ?? $method) ?></span></td>
              <td class="small"><?= h($row['confirmed_by_name'] ?: '—') ?></td>
              <?php if ($canUndo): ?>
              <td class="text-end">
                <button type="button"
                  class="btn btn-outline-danger btn-sm btn-undo-match"
                  data-match-id="<?= (int) $row['id'] ?>"
                  data-needs-reverse="<?= $needsReverse ? '1' : '0' ?>"
                  data-method="<?= h($method) ?>">
                  Remove &amp; Redo
                </button>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canUndo): ?>
<script>
(function(){
  const CSRF = <?= json_encode($csrf) ?>;
  document.querySelectorAll('.btn-undo-match').forEach(function(btn){
    btn.addEventListener('click', async function(){
      const id = btn.getAttribute('data-match-id');
      const needsReverse = btn.getAttribute('data-needs-reverse') === '1';
      const method = btn.getAttribute('data-method') || 'match';
      let msg = 'Remove this reconciliation match?';
      if (needsReverse) {
        msg = 'This will reverse the ' + method + ' journal entry and unlink the match. Continue?';
      }
      if (!confirm(msg)) return;
      btn.disabled = true;
      const p = new URLSearchParams({_csrf: CSRF, match_id: id, reverse_created: needsReverse ? '1' : '0'});
      const r = await fetch('ajax/re_bank_reco_undo.php', {method:'POST', body: p});
      const j = await r.json();
      if (!j.success) { alert(j.error || 'Undo failed'); btn.disabled = false; return; }
      btn.closest('tr')?.remove();
    });
  });
})();
</script>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
