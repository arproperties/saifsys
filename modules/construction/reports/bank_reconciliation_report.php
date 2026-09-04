<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_bank_reconciliation.php';
require_once __DIR__ . '/../includes/construction_bank_reco_rules.php';

$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

if (!co_bank_reco_has_permission($conn, 'construction.bank_reconciliation.report')) {
    http_response_code(403);
    die('Permission denied.');
}

$banks = [];
if (co_bank_reco_tables_ready($conn)) {
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
if ($bankId && co_bank_reco_tables_ready($conn)) {
    $report = co_bank_reco_report($conn, $cid, $bankId, $dateFrom, $dateTo);
}

if ($report && !empty($report['statement_lines'])) {
    co_report_export($report['statement_lines'], [
        'txn_date' => 'Date',
        'description' => 'Description',
        'reference' => 'Reference',
        'amount' => 'Amount',
        'status' => 'Status',
        'remaining' => 'Remaining',
    ], 'construction_bank_reco_' . $bankId . '_' . $dateFrom . '_' . $dateTo, 'Bank Reconciliation Statement');
}

$pageTitle = 'Bank Reconciliation Report';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
  <div>
    <h1 class="h4 mb-0">Bank Reconciliation Report</h1>
    <p class="text-muted mb-0"><?= h($dateFrom) ?> to <?= h($dateTo) ?></p>
  </div>
  <div class="d-flex gap-2"><?= $report ? co_report_export_buttons() : '' ?></div>
</div>

<form method="get" class="row g-2 align-items-end mb-3 no-print">
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
<ul class="nav nav-tabs mb-3 no-print">
  <li class="nav-item"><a class="nav-link <?= $tab === 'summary' ? 'active' : '' ?>" href="?<?= h(http_build_query(['bank_account_id'=>$bankId,'date_from'=>$dateFrom,'date_to'=>$dateTo,'tab'=>'summary'])) ?>">Summary</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'statement' ? 'active' : '' ?>" href="?<?= h(http_build_query(['bank_account_id'=>$bankId,'date_from'=>$dateFrom,'date_to'=>$dateTo,'tab'=>'statement'])) ?>">Bank statement</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'outstanding' ? 'active' : '' ?>" href="?<?= h(http_build_query(['bank_account_id'=>$bankId,'date_from'=>$dateFrom,'date_to'=>$dateTo,'tab'=>'outstanding'])) ?>">Outstanding ERP</a></li>
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
        <td><?= h($l['txn_date']) ?></td>
        <td><?= h($l['description']) ?></td>
        <td><?= h($l['reference']) ?></td>
        <td class="text-end"><?= number_format((float)$l['amount'], 2) ?></td>
        <td><?= h($l['status'] ?? ($l['is_reconciled'] ? 'reconciled' : 'unreconciled')) ?></td>
        <td class="text-end"><?= number_format((float)$l['remaining'], 2) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table></div></div>

<?php else: ?>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead class="table-light"><tr><th>Date</th><th>Journal</th><th>Description</th><th class="text-end">Amount</th><th class="text-end">Unmatched</th></tr></thead>
  <tbody>
    <?php if (!$report['outstanding_gl']): ?>
      <tr><td colspan="5" class="text-muted text-center py-3">No outstanding ERP bank transactions in this period.</td></tr>
    <?php else: foreach ($report['outstanding_gl'] as $g): ?>
      <tr>
        <td><?= h($g['entry_date']) ?></td>
        <td><?= h($g['journal_number'] ?? '') ?></td>
        <td><?= h($g['description'] ?: $g['reference']) ?></td>
        <td class="text-end"><?= number_format((float)$g['amount'], 2) ?></td>
        <td class="text-end"><?= number_format((float)$g['remaining'], 2) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
