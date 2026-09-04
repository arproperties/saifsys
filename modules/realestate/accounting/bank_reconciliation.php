<?php
/**
 * Real Estate — Bank Reconciliation Workbench (AJAX, Xero-style).
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

$selectedBank = !empty($_GET['bank_account_id']) ? (int) $_GET['bank_account_id'] : ((int) ($banks[0]['id'] ?? 0));
$df = $_GET['date_from'] ?? date('Y-m-01');
$dt = $_GET['date_to'] ?? date('Y-m-d');
$csrf = csrf_token();

$coaAccounts = [];
$contacts = ['tenants' => [], 'vendors' => []];
if ($tablesReady) {
    $st = $conn->prepare('SELECT id, account_code, account_name FROM re_chart_of_accounts WHERE company_id = ? AND is_active = 1 ORDER BY account_code');
    $st->execute([$cid]);
    $coaAccounts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $contacts = re_bank_reco_contacts($conn, $cid);
    $vatCfg = re_bank_reco_vat_config($conn, $cid);
}

$pageTitle = 'Bank Reconciliation';
$pageHead = '<link href="assets/bank_reconciliation.css?v=20260730" rel="stylesheet">';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="co-breco-hero">
  <div class="d-flex flex-wrap align-items-start gap-3">
    <div class="flex-grow-1">
      <div class="text-uppercase small text-muted">Real Estate · Bank Reconciliation</div>
      <h3 class="mb-1">Reconcile bank account</h3>
      <div class="text-muted small">Review each statement line on the left. Match, create, or discuss on the right — then click OK to reconcile.</div>
      <?php if ($tablesReady && $banks): ?>
      <div class="co-breco-balance-bar">
        <div class="co-breco-balance-item"><div class="lbl">Statement balance</div><div class="val" id="hdrStmtBal">—</div></div>
        <div class="co-breco-balance-item"><div class="lbl">Balance in ERP</div><div class="val" id="hdrErpBal">—</div></div>
        <div class="co-breco-balance-item"><div class="lbl">Difference</div><div class="val" id="hdrDiff">—</div></div>
        <div class="co-breco-balance-item"><div class="lbl">Unreconciled lines</div><div class="val" id="hdrUnrecCount">—</div></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a href="bank_reconciliation_accounts.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
      <a href="bank_reconciliation_import.php?bank_account_id=<?= (int) $selectedBank ?>" class="btn btn-outline-primary btn-sm">Import</a>
      <a href="bank_reconciliation_cash_coding.php?bank_account_id=<?= (int) $selectedBank ?>" class="btn btn-outline-secondary btn-sm">Cash coding</a>
      <a href="bank_reconciliation_rules.php" class="btn btn-outline-secondary btn-sm">Rules</a>
      <a href="bank_reconciliation_feeds.php" class="btn btn-outline-secondary btn-sm">Bank feeds</a>
      <a href="bank_reconciliation_history.php?bank_account_id=<?= (int) $selectedBank ?>" class="btn btn-outline-secondary btn-sm">History</a>
      <a href="bank_reconciliation_report.php" class="btn btn-outline-secondary btn-sm">Report</a>
      <a href="general_ledger.php" class="btn btn-outline-secondary btn-sm">GL</a>
    </div>
  </div>
</div>

<?php if (!$tablesReady): ?>
  <div class="alert alert-warning">Bank reconciliation tables are not installed. Run <code>migrations/re_accounting_phase8_bank_reconciliation.sql</code> and <code>migrations/re_bank_reconciliation_v2.sql</code>.</div>
<?php elseif (!$banks): ?>
  <div class="alert alert-warning">No active bank accounts. <a href="bank_accounts.php">Configure accounts</a> or <a href="bank_reconciliation_accounts.php">open dashboard</a>.</div>
<?php else: ?>

<div class="row g-2 align-items-end mb-3">
  <div class="col-auto">
    <label class="form-label small mb-0">Bank</label>
    <select id="bankSel" class="form-select form-select-sm">
      <?php foreach ($banks as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= $selectedBank === (int) $b['id'] ? 'selected' : '' ?>><?= h($b['account_no'] . ' — ' . $b['name']) ?></option>
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
    <button type="button" class="btn btn-primary btn-sm" id="btnReload">Refresh</button>
  </div>
</div>

<div id="reBankRecoApp" data-csrf="<?= h($csrf) ?>" data-ajax-base="ajax/" data-bank-id="<?= (int) $selectedBank ?>">
  <div class="co-breco-layout">
    <div class="co-breco-lines">
      <div class="co-breco-lines-head d-flex justify-content-between align-items-center">
        <strong>Statement lines</strong>
        <span class="small text-muted" id="linesCount">—</span>
      </div>
      <div class="co-breco-lines-body" id="linesList"></div>
    </div>
    <div class="co-breco-panel">
      <div class="co-breco-panel-head">
        <ul class="nav nav-tabs card-header-tabs" id="actionTabs">
          <li class="nav-item"><button type="button" class="nav-link active" data-tab="match">Match</button></li>
          <li class="nav-item"><button type="button" class="nav-link" data-tab="create">Create</button></li>
          <li class="nav-item"><button type="button" class="nav-link" data-tab="transfer" title="Phase 2">Transfer</button></li>
          <li class="nav-item"><button type="button" class="nav-link" data-tab="discuss">Discuss</button></li>
          <li class="nav-item"><button type="button" class="nav-link" data-tab="find" title="Phase 2">Find &amp; Match</button></li>
        </ul>
      </div>
      <div class="co-breco-panel-body" id="panelBody">
        <div class="co-breco-empty">Select a statement line to begin.</div>
      </div>
    </div>
  </div>
</div>

<script>
window.RE_BRECO_ACCOUNTS = <?= json_encode($coaAccounts) ?>;
window.RE_BRECO_CONTACTS = <?= json_encode($contacts) ?>;
window.RE_BRECO_BANKS = <?= json_encode(array_map(static fn($b) => [
    'id' => (int) $b['id'],
    'label' => ($b['account_no'] ?? '') . ' — ' . ($b['name'] ?? ''),
], $banks)) ?>;
window.RE_BRECO_VAT = <?= json_encode([
    'default_rate' => (float) ($vatCfg['default_rate'] ?? 5),
    'options' => [
        ['value' => 'none', 'label' => 'No VAT'],
        ['value' => 'standard', 'label' => number_format((float) ($vatCfg['default_rate'] ?? 5), 2) . '% Standard VAT'],
        ['value' => 'zero_rated', 'label' => 'Zero rated (0%)'],
        ['value' => 'exempt', 'label' => 'Exempt'],
        ['value' => 'out_of_scope', 'label' => 'Out of scope'],
    ],
]) ?>;
</script>
<script src="assets/bank_reconciliation.js?v=20260730b"></script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
