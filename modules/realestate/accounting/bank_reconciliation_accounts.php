<?php
/**
 * Real Estate — Bank Reconciliation Dashboard.
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
require_once __DIR__ . '/../includes/re_bank_reco_core.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}
re_bank_reco_require_permission($conn, 'realestate.bank_reconciliation.view');

$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$tablesReady = re_bank_reco_tables_ready($conn);

$banks = $conn->prepare("
    SELECT ba.*, coa.account_code, coa.account_name AS coa_name
    FROM re_bank_accounts ba
    JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id AND coa.company_id = ba.company_id
    WHERE ba.company_id = ?
    ORDER BY coa.account_code
");
$banks->execute([$cid]);
$banks = $banks->fetchAll(PDO::FETCH_ASSOC) ?: [];

$dashboardRows = [];
$asOf = date('Y-m-d');
if ($tablesReady) {
    foreach ($banks as $b) {
        if (!(int) $b['is_active']) {
            continue;
        }
        $bid = (int) $b['id'];
        $glId = (int) $b['gl_account_id'];
        $erpBal = re_bank_erp_balance($conn, $glId, $cid, $asOf);
        $stmtBal = re_bank_statement_balance($conn, $bid, $cid, $asOf);
        $diff = $stmtBal !== null ? re_bank_rec_money($erpBal - $stmtBal) : null;

        $unrec = 0;
        $st = $conn->prepare('
            SELECT l.net_amount,
              (SELECT COALESCE(SUM(m2.matched_amount),0) FROM re_bank_reconciliation_matches m2
               WHERE m2.statement_line_id = l.id AND m2.status = \'confirmed\') AS matched_sum
            FROM re_bank_statement_lines l
            WHERE l.bank_account_id = ? AND l.company_id = ?
        ');
        $st->execute([$bid, $cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (max(0, round(abs((float) $row['net_amount']) - (float) $row['matched_sum'], 2)) > 0.009) {
                $unrec++;
            }
        }

        $st = $conn->prepare('SELECT MAX(imported_at) FROM re_bank_import_batches WHERE bank_account_id = ? AND company_id = ?');
        $st->execute([$bid, $cid]);
        $lastImport = $st->fetchColumn() ?: null;

        $st = $conn->prepare("
            SELECT MAX(m.matched_at) FROM re_bank_reconciliation_matches m
            WHERE m.bank_account_id = ? AND m.company_id = ? AND m.status = 'confirmed'
        ");
        $st->execute([$bid, $cid]);
        $lastReco = $st->fetchColumn() ?: null;

        if ($stmtBal === null) {
            $status = 'No Statement Imported';
            $badge = 'secondary';
        } elseif ($diff !== null && abs($diff) >= 0.02) {
            $status = 'Difference Found';
            $badge = 'warning text-dark';
        } elseif ($unrec > 0) {
            $status = 'Needs Review';
            $badge = 'warning text-dark';
        } else {
            $status = 'Reconciled';
            $badge = 'success';
        }

        $dashboardRows[] = array_merge($b, [
            'erp_balance' => $erpBal,
            'statement_balance' => $stmtBal,
            'difference' => $diff,
            'unreconciled_count' => $unrec,
            'last_import_at' => $lastImport,
            'last_reconciled_at' => $lastReco,
            'status_label' => $status,
            'status_badge' => $badge,
        ]);
    }
}

$pageTitle = 'Bank Reconciliation Dashboard';
$pageHead = '<link href="assets/bank_reconciliation.css?v=20260703" rel="stylesheet">';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div class="flex-grow-1">
    <div class="text-uppercase small text-muted">Real Estate</div>
    <h3 class="mb-0">Bank accounts dashboard</h3>
    <div class="text-muted">Overview of bank accounts, balances, and reconciliation status.</div>
  </div>
  <div class="d-flex gap-2">
    <a href="bank_reconciliation_import.php" class="btn btn-outline-primary btn-sm">Import statement</a>
    <a href="bank_reconciliation_cash_coding.php" class="btn btn-outline-secondary btn-sm">Cash coding</a>
    <a href="bank_reconciliation_rules.php" class="btn btn-outline-secondary btn-sm">Bank rules</a>
    <a href="bank_reconciliation_history.php" class="btn btn-outline-secondary btn-sm">History</a>
    <a href="bank_reconciliation_report.php" class="btn btn-outline-secondary btn-sm">Report</a>
    <a href="bank_reconciliation_settings.php" class="btn btn-outline-secondary btn-sm">Settings</a>
    <a href="bank_reconciliation_feeds.php" class="btn btn-outline-secondary btn-sm">Bank feeds</a>
    <a href="bank_reconciliation_diagnostics.php" class="btn btn-outline-secondary btn-sm">Diagnostics</a>
    <a href="bank_accounts.php" class="btn btn-outline-secondary btn-sm">Manage accounts</a>
  </div>
</div>

<?php if (!$tablesReady): ?>
  <div class="alert alert-warning">Run <code>migrations/re_accounting_phase8_bank_reconciliation.sql</code> and <code>migrations/re_bank_reconciliation_v2.sql</code>.</div>
<?php elseif ($dashboardRows): ?>
<div class="row g-3 mb-4">
  <?php foreach ($dashboardRows as $d): ?>
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
          <div>
            <div class="fw-bold"><?= h($d['account_code'] . ' — ' . $d['account_name']) ?></div>
            <div class="small text-muted"><?= h($d['coa_name']) ?> · <?= h($d['currency'] ?? 'AED') ?></div>
          </div>
          <span class="badge bg-<?= h($d['status_badge']) ?>"><?= h($d['status_label']) ?></span>
        </div>
        <div class="row g-2 small mb-3">
          <div class="col-6"><span class="text-muted">ERP balance</span><div class="fw-semibold"><?= number_format((float) $d['erp_balance'], 2) ?></div></div>
          <div class="col-6"><span class="text-muted">Statement balance</span><div class="fw-semibold"><?= $d['statement_balance'] !== null ? number_format((float) $d['statement_balance'], 2) : '—' ?></div></div>
          <div class="col-6"><span class="text-muted">Difference</span><div class="fw-semibold"><?= $d['difference'] !== null ? number_format((float) $d['difference'], 2) : '—' ?></div></div>
          <div class="col-6"><span class="text-muted">Unreconciled</span><div class="fw-semibold"><?= (int) $d['unreconciled_count'] ?></div></div>
          <div class="col-6"><span class="text-muted">Last import</span><div><?= $d['last_import_at'] ? h(substr((string) $d['last_import_at'], 0, 10)) : '—' ?></div></div>
          <div class="col-6"><span class="text-muted">Last reconciliation</span><div><?= $d['last_reconciled_at'] ? h(substr((string) $d['last_reconciled_at'], 0, 10)) : '—' ?></div></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a href="bank_reconciliation.php?bank_account_id=<?= (int) $d['id'] ?>" class="btn btn-primary btn-sm">Reconcile<?= $d['unreconciled_count'] ? ' (' . (int) $d['unreconciled_count'] . ')' : '' ?></a>
          <a href="bank_reconciliation_import.php?bank_account_id=<?= (int) $d['id'] ?>" class="btn btn-outline-primary btn-sm">Import</a>
          <a href="general_ledger.php?account_id=<?= (int) $d['gl_account_id'] ?>&date_from=<?= h(date('Y-01-01')) ?>&date_to=<?= h(date('Y-m-d')) ?>" class="btn btn-outline-secondary btn-sm">Transactions</a>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <div class="alert alert-info mb-4">No active bank accounts yet. <a href="bank_accounts.php">Add a bank account</a>.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
