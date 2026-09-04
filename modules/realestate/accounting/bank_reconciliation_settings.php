<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/re_bank_reco_core.php';
require_once __DIR__ . '/../includes/re_bank_reco_settings.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}
re_bank_reco_require_permission($conn, 'realestate.bank_reconciliation.rules');

require_once __DIR__ . '/../../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$csrf = csrf_token();
$tablesReady = re_bank_reco_tables_ready($conn) && re_bank_reco_settings_table_ready($conn);
$canSave = re_bank_reco_has_permission($conn, 'realestate.bank_reconciliation.admin_override')
    || re_bank_reco_has_permission($conn, 'realestate.bank_reconciliation.rules');

$banks = [];
$settings = ['auto_reconcile_rule_matches' => false];
if ($tablesReady) {
    $st = $conn->prepare("
        SELECT b.id, b.account_name AS name, coa.account_code AS account_no
        FROM re_bank_accounts b
        JOIN re_chart_of_accounts coa ON coa.id = b.gl_account_id AND coa.company_id = b.company_id
        WHERE b.company_id = ? AND b.is_active = 1 ORDER BY coa.account_code
    ");
    $st->execute([$cid]);
    $banks = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $settings = re_bank_reco_get_settings($conn, $cid);
}

$pageTitle = 'Bank Reconciliation Settings';
$pageHead = '<link href="assets/bank_reconciliation.css?v=20260703p3" rel="stylesheet">';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div class="flex-grow-1">
    <div class="text-uppercase small text-muted">Real Estate · Bank Reconciliation</div>
    <h3 class="mb-0">Automation settings</h3>
    <div class="text-muted small">Optional rule-based auto-reconcile (never auto-creates journals).</div>
  </div>
  <div class="d-flex gap-2">
    <a href="bank_reconciliation_accounts.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
    <a href="bank_reconciliation_rules.php" class="btn btn-outline-primary btn-sm">Bank rules</a>
    <a href="bank_reconciliation_feeds.php" class="btn btn-outline-secondary btn-sm">Bank feeds</a>
  </div>
</div>

<?php if (!$tablesReady): ?>
  <div class="alert alert-warning">Run <code>migrations/re_bank_reconciliation_v3.sql</code> to enable automation settings.</div>
<?php else: ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Auto-reconcile with bank rules</h5>
        <p class="small text-muted mb-3">
          When enabled, the system will <strong>automatically confirm</strong> a reconciliation only if an active bank rule matches, auto-suggest is on, and a matching ERP transaction scores <strong>90+</strong> with exact amounts.
        </p>
        <form id="settingsForm" class="mt-3">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="autoReconcile" name="auto_reconcile_rule_matches" value="1"
              <?= !empty($settings['auto_reconcile_rule_matches']) ? 'checked' : '' ?>
              <?= $canSave ? '' : 'disabled' ?>>
            <label class="form-check-label" for="autoReconcile">Enable auto-reconcile for high-confidence rule matches</label>
          </div>
          <?php if ($canSave): ?>
            <button type="submit" class="btn btn-primary btn-sm">Save settings</button>
          <?php else: ?>
            <div class="small text-warning">You need rules or admin permission to change this setting.</div>
          <?php endif; ?>
        </form>
        <div id="saveMsg" class="small mt-2"></div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Run auto-reconcile now</h5>
        <p class="small text-muted">Manually trigger rule-based auto-reconcile for a bank and date range (setting must be enabled).</p>
        <form id="runForm" class="row g-2">
          <div class="col-12">
            <label class="form-label small">Bank</label>
            <select id="runBank" class="form-select form-select-sm" required>
              <?php foreach ($banks as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= h($b['account_no'].' — '.$b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small">From</label>
            <input type="date" id="runFrom" class="form-control form-control-sm" value="<?= h(date('Y-m-01')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label small">To</label>
            <input type="date" id="runTo" class="form-control form-control-sm" value="<?= h(date('Y-m-d')) ?>">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-outline-success btn-sm">Run auto-reconcile</button>
          </div>
        </form>
        <div id="runMsg" class="small mt-2"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const CSRF = <?= json_encode($csrf) ?>;
  const settingsForm = document.getElementById('settingsForm');
  if (settingsForm) {
    settingsForm.addEventListener('submit', async function(ev){
      ev.preventDefault();
      const p = new URLSearchParams({
        _csrf: CSRF,
        auto_reconcile_rule_matches: document.getElementById('autoReconcile').checked ? '1' : '0'
      });
      const r = await fetch('ajax/re_bank_reco_settings_save.php', {method:'POST', body:p});
      const j = await r.json();
      document.getElementById('saveMsg').textContent = j.success ? 'Settings saved.' : (j.error || 'Save failed');
    });
  }
  document.getElementById('runForm').addEventListener('submit', async function(ev){
    ev.preventDefault();
    const p = new URLSearchParams({
      _csrf: CSRF,
      bank_account_id: document.getElementById('runBank').value,
      date_from: document.getElementById('runFrom').value,
      date_to: document.getElementById('runTo').value
    });
    const r = await fetch('ajax/re_bank_reco_auto_reconcile.php', {method:'POST', body:p});
    const j = await r.json();
    const el = document.getElementById('runMsg');
    if (!j.success) { el.textContent = j.error || 'Run failed'; return; }
    if (!j.enabled) { el.textContent = 'Auto-reconcile is disabled in settings.'; return; }
    el.innerHTML = 'Confirmed <strong>' + (j.confirmed_count||0) + '</strong> line(s). Skipped: ' + ((j.skipped||[]).length);
  });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
