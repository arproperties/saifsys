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
re_bank_reco_require_permission($conn, 'realestate.bank_reconciliation.view');

require_once __DIR__ . '/../../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$feedReady = re_bank_feed_table_ready($conn);
$connections = $feedReady ? re_bank_feed_connections_list($conn, $cid) : [];

$pageTitle = 'Bank Feeds';
$pageHead = '<link href="assets/bank_reconciliation.css?v=20260703p4" rel="stylesheet">';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div class="flex-grow-1">
    <div class="text-uppercase small text-muted">Real Estate · Bank Reconciliation</div>
    <h3 class="mb-0">Bank feeds</h3>
    <div class="text-muted small">Placeholder for future direct bank API connections. Use CSV/Excel import until feeds are available.</div>
  </div>
  <div class="d-flex gap-2">
    <a href="bank_reconciliation_import.php" class="btn btn-primary btn-sm">Import statement</a>
    <a href="bank_reconciliation_settings.php" class="btn btn-outline-secondary btn-sm">Settings</a>
  </div>
</div>

<?php if (!$feedReady): ?>
  <div class="alert alert-warning">Run <code>migrations/re_bank_reconciliation_v4.sql</code> to enable the bank feeds registry.</div>
<?php else: ?>

<div class="alert alert-info d-flex flex-wrap align-items-center gap-2">
  <span><i class="bi bi-info-circle"></i> Direct bank feeds are not connected yet. This page will manage live bank API links in a future release.</span>
  <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" disabled title="Coming soon">Connect bank account</button>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr><th>Name</th><th>Provider</th><th>Bank account</th><th>Status</th><th>Last sync</th></tr>
      </thead>
      <tbody>
        <?php if (!$connections): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No feed connections configured. Continue using manual import.</td></tr>
        <?php else: ?>
          <?php foreach ($connections as $c): ?>
            <tr>
              <td><?= h($c['connection_name']) ?></td>
              <td><?= h($c['provider']) ?></td>
              <td><?= h(trim(($c['account_code'] ?? '') . ' ' . ($c['bank_name'] ?? '')) ?: '—') ?></td>
              <td><span class="badge bg-secondary"><?= h($c['status']) ?></span></td>
              <td><?= h($c['last_sync_at'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
