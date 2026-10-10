<?php
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
require_once __DIR__ . '/../includes/re_bank_reco_rules.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}
re_bank_reco_require_permission($conn, 'realestate.bank_reconciliation.report');

$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;

$banks = [];
if (re_bank_reco_tables_ready($conn)) {
    $st = $conn->prepare("
        SELECT b.id, b.account_name AS name, coa.account_code AS account_no
        FROM re_bank_accounts b
        JOIN re_chart_of_accounts coa ON coa.id = b.gl_account_id AND coa.company_id = b.company_id
        WHERE b.company_id = ? AND b.is_active = 1 ORDER BY coa.account_code
    ");
    $st->execute([$cid]);
    $banks = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$bankId = !empty($_GET['bank_account_id']) ? (int) $_GET['bank_account_id'] : ((int) ($banks[0]['id'] ?? 0));
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$tab = $_GET['tab'] ?? 'summary';

$report = null;
if ($bankId && re_bank_reco_tables_ready($conn)) {
    $report = re_bank_reco_report($conn, $cid, $bankId, $dateFrom, $dateTo);
}

$show = $_GET['show'] ?? 'all';
$check = null;
if ($report && empty($report['error']) && $tab === 'check') {
    $check = re_bank_reco_report_check($conn, $cid, $report['statement_lines']);
    if ($show === 'reconciled') {
        $check['lines'] = array_values(array_filter($check['lines'], static fn($l) => $l['check'] === 'reconciled'));
    } elseif ($show === 'open') {
        $check['lines'] = array_values(array_filter($check['lines'], static fn($l) => $l['check'] !== 'reconciled'));
    }
}

$pageTitle = 'Bank Reconciliation Report';
$pageHead = '<link href="assets/bank_reconciliation.css?v=20260703p3" rel="stylesheet">';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h1 class="h4 mb-0">Bank Reconciliation Report</h1>
    <p class="text-muted mb-0"><?= h($dateFrom) ?> to <?= h($dateTo) ?></p>
  </div>
  <a href="bank_reconciliation.php?bank_account_id=<?= (int)$bankId ?>" class="btn btn-outline-secondary btn-sm">Workbench</a>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-auto"><label class="form-label small mb-0">Bank</label>
    <select name="bank_account_id" class="form-select form-select-sm">
      <?php foreach ($banks as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= $bankId === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['account_no'].' — '.$b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto"><label class="form-label small mb-0">From</label><input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0">To</label><input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>"></div>
  <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm">Run report</button></div>
</form>

<?php if (!$report): ?>
  <div class="alert alert-warning">Select a bank account to run the report.</div>
<?php else: ?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'summary' ? 'active' : '' ?>" href="?<?= h(http_build_query(['bank_account_id'=>$bankId,'date_from'=>$dateFrom,'date_to'=>$dateTo,'tab'=>'summary'])) ?>">Summary</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'statement' ? 'active' : '' ?>" href="?<?= h(http_build_query(['bank_account_id'=>$bankId,'date_from'=>$dateFrom,'date_to'=>$dateTo,'tab'=>'statement'])) ?>">Bank statement</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'outstanding' ? 'active' : '' ?>" href="?<?= h(http_build_query(['bank_account_id'=>$bankId,'date_from'=>$dateFrom,'date_to'=>$dateTo,'tab'=>'outstanding'])) ?>">Outstanding ERP</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'check' ? 'active' : '' ?>" href="?<?= h(http_build_query(['bank_account_id'=>$bankId,'date_from'=>$dateFrom,'date_to'=>$dateTo,'tab'=>'check'])) ?>">Reconcile check</a></li>
</ul>

<?php if ($tab === 'summary'): ?>
<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Balance in ERP</div><div class="h5 mb-0"><?= number_format((float)$report['erp_balance'], 2) ?></div></div></div></div>
  <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Statement balance</div><div class="h5 mb-0"><?= $report['statement_balance'] !== null ? number_format((float)$report['statement_balance'], 2) : '—' ?></div></div></div></div>
  <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Difference</div><div class="h5 mb-0"><?= $report['difference'] !== null ? number_format((float)$report['difference'], 2) : '—' ?></div></div></div></div>
  <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Unreconciled lines</div><div class="h5 mb-0"><?= (int)$report['counts']['unreconciled'] ?> / <?= (int)$report['counts']['statement_total'] ?></div></div></div></div>
</div>
<div class="row g-3">
  <div class="col-md-6"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Outstanding ERP bank transactions</div><div class="h5 mb-0"><?= (int)$report['counts']['outstanding_gl'] ?></div></div></div></div>
  <div class="col-md-6"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Adjustments / created from reco</div><div class="h5 mb-0"><?= (int)$report['counts']['adjustments'] ?></div></div></div></div>
</div>

<?php elseif ($tab === 'statement'): ?>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead class="table-light"><tr><th>Date</th><th>Description</th><th>Ref</th><th class="text-end">Amount</th><th>Status</th><th class="text-end">Remaining</th></tr></thead>
  <tbody>
    <?php foreach ($report['statement_lines'] as $l): ?>
      <tr>
        <td><?= h($l['statement_date'] ?? '') ?></td>
        <td><?= h($l['description'] ?? '') ?></td>
        <td><?= h($l['reference'] ?? '') ?></td>
        <td class="text-end"><?= number_format((float)($l['net_amount'] ?? 0), 2) ?></td>
        <td><?= h($l['status'] ?? '') ?></td>
        <td class="text-end"><?= number_format((float)($l['remaining'] ?? 0), 2) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table></div></div>

<?php elseif ($tab === 'outstanding'): ?>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead class="table-light"><tr><th>Date</th><th>Journal</th><th>Description</th><th class="text-end">Amount</th><th class="text-end">Remaining</th></tr></thead>
  <tbody>
    <?php if (!$report['outstanding_gl']): ?>
      <tr><td colspan="5" class="text-muted text-center py-3">No outstanding ERP bank lines in this period.</td></tr>
    <?php else: ?>
      <?php foreach ($report['outstanding_gl'] as $gl): ?>
        <tr>
          <td><?= h($gl['entry_date'] ?? '') ?></td>
          <td><?= h($gl['journal_number'] ?? '') ?></td>
          <td><?= h($gl['description'] ?? '') ?></td>
          <td class="text-end"><?= number_format((float)($gl['amount'] ?? 0), 2) ?></td>
          <td class="text-end"><?= number_format((float)($gl['remaining'] ?? 0), 2) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table></div></div>

<?php elseif ($tab === 'check' && $check): ?>
<?php
  $t = $check['totals'];
  $checkBadges = [
      'reconciled' => ['success', 'Reconciled'],
      'open' => ['warning text-dark', 'Not reconciled'],
      'partial' => ['danger', 'Partly matched'],
      'over' => ['danger', 'Over matched'],
  ];
?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Statement total</div><div class="h5 mb-0"><?= number_format($t['statement'], 2) ?></div></div></div></div>
  <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Matched total</div><div class="h5 mb-0"><?= number_format($t['matched'], 2) ?></div></div></div></div>
  <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Not matched</div><div class="h5 mb-0 <?= abs($t['gap']) > 0.009 ? 'text-danger' : 'text-success' ?>"><?= number_format($t['gap'], 2) ?><?= abs($t['gap']) > 0.009 ? '' : ' — totals agree' ?></div></div></div></div>
</div>
<div class="btn-group btn-group-sm mb-3">
  <?php foreach (['all' => 'All lines', 'reconciled' => 'Reconciled', 'open' => 'Not reconciled'] as $k => $lbl): ?>
    <a class="btn <?= $show === $k ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?<?= h(http_build_query(['bank_account_id'=>$bankId,'date_from'=>$dateFrom,'date_to'=>$dateTo,'tab'=>'check','show'=>$k])) ?>"><?= h($lbl) ?></a>
  <?php endforeach; ?>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead class="table-light">
    <tr><th colspan="3">Statement line</th><th colspan="2" class="border-start">Matched with</th><th class="text-end">Not matched</th><th>Check</th></tr>
    <tr><th>Date</th><th>Description</th><th class="text-end">Amount</th><th class="border-start">Entry</th><th class="text-end">Amount</th><th></th><th></th></tr>
  </thead>
  <tbody>
    <?php if (!$check['lines']): ?>
      <tr><td colspan="7" class="text-muted text-center py-3">No statement lines to show.</td></tr>
    <?php endif; ?>
    <?php foreach ($check['lines'] as $l): [$badgeClass, $badgeText] = $checkBadges[$l['check']]; ?>
      <tr>
        <td class="text-nowrap"><?= h($l['statement_date']) ?></td>
        <td><?= h($l['description']) ?></td>
        <td class="text-end fw-semibold <?= $l['net_amount'] < 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($l['net_amount'], 2) ?></td>
        <td class="border-start">
          <?php if (!$l['matches']): ?><span class="text-muted">—</span><?php endif; ?>
          <?php foreach ($l['matches'] as $m): ?>
            <div><?= h($m['label']) ?> <span class="text-muted small"><?= h($m['date'] ?? '') ?></span></div>
          <?php endforeach; ?>
        </td>
        <td class="text-end">
          <?php foreach ($l['matches'] as $m): ?><div><?= number_format($m['amount'], 2) ?></div><?php endforeach; ?>
          <?php if (count($l['matches']) > 1): ?><div class="fw-semibold border-top"><?= number_format($l['matched'], 2) ?></div><?php endif; ?>
        </td>
        <td class="text-end <?= abs($l['gap']) > 0.009 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= number_format($l['gap'], 2) ?></td>
        <td><span class="badge bg-<?= $badgeClass ?>"><?= h($badgeText) ?></span></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <?php if ($show === 'all'): ?>
  <tfoot class="table-light fw-semibold">
    <tr><td colspan="2">Total</td><td class="text-end"><?= number_format($t['statement'], 2) ?></td><td class="border-start"></td><td class="text-end"><?= number_format($t['matched'], 2) ?></td><td class="text-end <?= abs($t['gap']) > 0.009 ? 'text-danger' : '' ?>"><?= number_format($t['gap'], 2) ?></td><td></td></tr>
  </tfoot>
  <?php endif; ?>
</table></div></div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
