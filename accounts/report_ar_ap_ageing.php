<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/cleaning_accounting_context.php';
require_once __DIR__.'/../includes/report_date_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

// Legacy params
if (!empty($_GET['asof']) && empty($_GET['to'])) {
    $_GET['to'] = $_GET['asof'];
}
if (!empty($_GET['from_date']) && empty($_GET['from'])) {
    $_GET['from'] = $_GET['from_date'];
}
if (!empty($_GET['to_date']) && empty($_GET['to'])) {
    $_GET['to'] = $_GET['to_date'];
}
$dates = report_date_range();
$from = $dates['from'];
$to = $dates['to'];
$asof = $to;
$export = isset($_GET['export']) && $_GET['export']==='csv';
$cleaningCompanyId = cleaning_accounting_company_id($conn);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function moneyv($n){ return number_format((float)$n, 2); }

// Function to calculate ageing bucket
function getAgeingBucket($days) {
  if ($days <= 0) return 'Current';
  if ($days <= 30) return '1-30 Days';
  if ($days <= 60) return '31-60 Days';
  if ($days <= 90) return '61-90 Days';
  return '90+ Days';
}

// Get AR Ageing (Accounts Receivable)
// Simple query matching AR Dashboard logic exactly - show all outstanding invoices
$arSql = "
  SELECT 
    i.id,
    i.invoice_no,
    i.issue_date,
    i.due_date,
    c.client_name,
    c.trn,
    i.total as invoice_total,
    COALESCE(pa.amount_paid, 0) as amount_paid,
    GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) as balance_due,
    DATEDIFF(?, i.issue_date) as days_old,
    i.status
  FROM invoices i
  LEFT JOIN client c ON c.id = i.client_id
  LEFT JOIN (
    SELECT invoice_id, SUM(amount_applied) as amount_paid
    FROM receipt_allocations
    GROUP BY invoice_id
  ) pa ON pa.invoice_id = i.id
  WHERE i.status IN ('issued', 'partially_paid')
    AND i.status != 'void'
    AND " . ar_collectible_invoice_sql('i') . "
    AND (i.total - COALESCE(pa.amount_paid, 0)) > 0
    AND i.issue_date BETWEEN ? AND ?
  ORDER BY c.client_name, i.issue_date
";

$arParams = [$asof, $from, $to];
$st = $conn->prepare($arSql);
$st->execute($arParams);
$arData = $st->fetchAll(PDO::FETCH_ASSOC);

// Group AR by ageing buckets
$arAgeing = [
  'Current' => [],
  '1-30 Days' => [],
  '31-60 Days' => [],
  '61-90 Days' => [],
  '90+ Days' => []
];

$arTotals = [
  'Current' => 0,
  '1-30 Days' => 0,
  '31-60 Days' => 0,
  '61-90 Days' => 0,
  '90+ Days' => 0
];

foreach ($arData as $row) {
  $bucket = getAgeingBucket($row['days_old']);
  $arAgeing[$bucket][] = $row;
  $arTotals[$bucket] += (float)$row['balance_due'];
}

$arGrandTotal = array_sum($arTotals);

// Get GL Trade Receivables Balance for comparison
$periodEnd = $asof;
$glBalanceSt = $conn->prepare("
  SELECT 
    COALESCE(SUM(
      CASE WHEN a.normal_balance = 'debit' 
        THEN l.debit - l.credit 
        ELSE l.credit - l.debit 
      END
    ), 0) AS net_balance
  FROM chart_of_accounts a
  LEFT JOIN gl_journal_lines l ON l.account_id = a.id
  LEFT JOIN gl_journals j ON j.id = l.journal_id
  WHERE a.account_no = '1110'
    AND a.is_active = 1
    AND a.company_id = ?
    AND j.journal_date <= ?
    AND j.company_id = ?
    AND j.is_posted = 1 
    AND j.is_reversed = 0
    AND NOT (j.source = 'reversal' AND EXISTS (SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = j.id))
    AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Compensating entry: Voiding duplicate reversal%')
    AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Void incorrect bank transfer%')
    AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Bank Transfer%' AND j.is_reversed = 1)
  GROUP BY a.id
");
$glBalanceSt->execute([$cleaningCompanyId, $periodEnd, $cleaningCompanyId]);
$glTradeReceivablesBalance = (float)($glBalanceSt->fetchColumn() ?: 0);

// Calculate difference
$arGlDifference = abs($arGrandTotal - $glTradeReceivablesBalance);

// Get unallocated receipt amounts (potential cause of discrepancy)
$unallocatedSt = $conn->prepare("
  SELECT 
    COALESCE(SUM(r.amount), 0) - COALESCE(SUM(ra.amount_applied), 0) as unallocated
  FROM receipts r
  LEFT JOIN receipt_allocations ra ON ra.receipt_id = r.id
  WHERE r.receipt_date <= ?
");
$unallocatedSt->execute([$periodEnd]);
$unallocatedReceipts = (float)($unallocatedSt->fetchColumn() ?: 0);

// Get AP Ageing (Accounts Payable) - from expenses
// Only include expenses paid via Accounts Payable (paid_via='ap')
// Expenses paid via cash/bank are already paid and shouldn't appear in AP
$apSql = "
  SELECT 
    e.id,
    e.reference_no,
    e.expense_date,
    v.name as vendor_name,
    v.trn,
    e.total as expense_total,
    e.total as balance_due,
    DATEDIFF(?, e.expense_date) as days_old,
    e.status,
    e.paid_via
  FROM expenses e
  LEFT JOIN vendors v ON v.id = e.vendor_id
  WHERE e.status = 'posted'
    AND e.paid_via = 'ap'
    AND e.total > 0
    AND e.expense_date BETWEEN ? AND ?
  ORDER BY v.name, e.expense_date
";

$apParams = [$asof, $from, $to];
$st = $conn->prepare($apSql);
$st->execute($apParams);
$apData = $st->fetchAll(PDO::FETCH_ASSOC);

// Group AP by ageing buckets
$apAgeing = [
  'Current' => [],
  '1-30 Days' => [],
  '31-60 Days' => [],
  '61-90 Days' => [],
  '90+ Days' => []
];

$apTotals = [
  'Current' => 0,
  '1-30 Days' => 0,
  '31-60 Days' => 0,
  '61-90 Days' => 0,
  '90+ Days' => 0
];

foreach ($apData as $row) {
  $bucket = getAgeingBucket($row['days_old']);
  $apAgeing[$bucket][] = $row;
  $apTotals[$bucket] += (float)$row['balance_due'];
}

$apGrandTotal = array_sum($apTotals);

if ($export) {
  header('Content-Type: text/csv');
  $filename = 'ar_ap_ageing_' . $asof;
  if ($from_date && $to_date) {
    $filename .= '_' . str_replace('-', '', $from_date) . '_to_' . str_replace('-', '', $to_date);
  }
  header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
  $out = fopen('php://output', 'w');
  
  // Header
  fputcsv($out, ['AR / AP AGEING REPORT - As of '.$asof]);
  if ($from_date && $to_date) {
    fputcsv($out, ['Filtered by Issue Date: '.$from_date.' to '.$to_date]);
  }
  fputcsv($out, []);
  
  // AR Section
  fputcsv($out, ['ACCOUNTS RECEIVABLE AGEING']);
  fputcsv($out, ['Client', 'Invoice No', 'Issue Date', 'Due Date', 'Invoice Total', 'Amount Paid', 'Balance Due', 'Days Old', 'Ageing Bucket']);
  foreach ($arAgeing as $bucket => $rows) {
    foreach ($rows as $row) {
      fputcsv($out, [
        $row['client_name'],
        $row['invoice_no'],
        $row['issue_date'],
        $row['due_date'],
        moneyv($row['invoice_total']),
        moneyv($row['amount_paid']),
        moneyv($row['balance_due']),
        $row['days_old'],
        $bucket
      ]);
    }
  }
  fputcsv($out, []);
  fputcsv($out, ['AR TOTALS BY BUCKET']);
  fputcsv($out, ['Ageing Bucket', 'Total Amount']);
  foreach ($arTotals as $bucket => $total) {
    fputcsv($out, [$bucket, moneyv($total)]);
  }
  fputcsv($out, ['GRAND TOTAL', moneyv($arGrandTotal)]);
  fputcsv($out, []);
  
  // AP Section
  fputcsv($out, ['ACCOUNTS PAYABLE AGEING']);
  fputcsv($out, ['Note: Only expenses paid via Accounts Payable (AP) are shown. Expenses paid via cash/bank are excluded.']);
  fputcsv($out, ['Vendor', 'Reference No', 'Expense Date', 'Total Amount', 'Balance Due', 'Days Old', 'Ageing Bucket']);
  foreach ($apAgeing as $bucket => $rows) {
    foreach ($rows as $row) {
      fputcsv($out, [
        $row['vendor_name'],
        $row['reference_no'],
        $row['expense_date'],
        moneyv($row['expense_total']),
        moneyv($row['balance_due']),
        $row['days_old'],
        $bucket
      ]);
    }
  }
  fputcsv($out, []);
  fputcsv($out, ['AP TOTALS BY BUCKET']);
  fputcsv($out, ['Ageing Bucket', 'Total Amount']);
  foreach ($apTotals as $bucket => $total) {
    fputcsv($out, [$bucket, moneyv($total)]);
  }
  fputcsv($out, ['GRAND TOTAL', moneyv($apGrandTotal)]);
  
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>AR / AP Ageing Report</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .ageing-header { background: #f8f9fa; border-left: 4px solid #800000; }
  .ageing-summary { background: #e8f5e8; border: 2px solid #28a745; }
  .ageing-current { background: #d1ecf1; }
  .ageing-30 { background: #fff3cd; }
  .ageing-60 { background: #f8d7da; }
  .ageing-90 { background: #f5c6cb; }
  .ageing-90plus { background: #f1b0b7; }
  .bucket-total { font-weight: bold; background: #e9ecef; }
  .days-old { font-size: 0.9em; color: #6c757d; }
  .trn-badge { font-size: 0.8em; }
</style>
</head>
<body class="bg-light">
<div class="container my-4">
  
  <div class="row mb-4">
    <div class="col">
      <h3><i class="bi bi-people"></i> AR / AP Ageing Report</h3>
    </div>
    <div class="col-auto">
      <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Reports</a>
    </div>
  </div>

  <!-- Filters -->
  <div class="card mb-4">
    <div class="card-body">
      <?php report_date_filter_form($from, $to, [], [
          'form_class' => 'row g-3',
          'button_label' => 'Run',
          'hint' => 'Ageing as of To date · invoice/expense dates within From–To · use an early From date for full outstanding AR/AP',
      ]); ?>
    </div>
  </div>

  <!-- Export Button -->
  <div class="mb-3">
    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" 
       class="btn btn-outline-success">
      <i class="bi bi-download"></i> Export CSV
    </a>
  </div>

  <!-- Summary Cards -->
  <div class="row mb-4">
    <div class="col-md-6">
      <div class="card ageing-summary">
        <div class="card-body text-center">
          <h5 class="card-title">Total AR Outstanding</h5>
          <h3 class="text-success"><?= moneyv($arGrandTotal) ?> AED</h3>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card ageing-summary">
        <div class="card-body text-center">
          <h5 class="card-title">Total AP Outstanding</h5>
          <h3 class="text-info"><?= moneyv($apGrandTotal) ?> AED</h3>
        </div>
      </div>
    </div>
  </div>

  <!-- AR Ageing Section -->
  <div class="card mb-4">
    <div class="card-header ageing-header">
      <h5 class="mb-0">
        <i class="bi bi-arrow-up-circle"></i> Accounts Receivable Ageing
        <span class="badge bg-success ms-2"><?= count($arData) ?> invoices</span>
      </h5>
    </div>
    <div class="card-body p-0">
      <?php if (empty($arData)): ?>
        <div class="p-3 text-center text-muted">
          <i class="bi bi-info-circle"></i> No outstanding receivables found.
        </div>
      <?php else: ?>
        <?php foreach ($arAgeing as $bucket => $rows): ?>
          <?php if (!empty($rows)): ?>
            <div class="p-3 border-bottom">
              <h6 class="mb-3">
                <span class="badge <?= $bucket === 'Current' ? 'bg-primary' : ($bucket === '1-30 Days' ? 'bg-warning' : ($bucket === '31-60 Days' ? 'bg-warning' : ($bucket === '61-90 Days' ? 'bg-danger' : 'bg-danger'))) ?>">
                  <?= h($bucket) ?>
                </span>
                <span class="ms-2">Total: <?= moneyv($arTotals[$bucket]) ?> AED</span>
              </h6>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Client</th>
                      <th>Invoice No</th>
                      <th>Issue Date</th>
                      <th>Due Date</th>
                      <th class="text-end">Invoice Total</th>
                      <th class="text-end">Amount Paid</th>
                      <th class="text-end">Balance Due</th>
                      <th class="text-center">Days Old</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td>
                          <div><?= h($row['client_name']) ?></div>
                          <?php if ($row['trn']): ?>
                            <small class="text-muted">TRN: <?= h($row['trn']) ?></small>
                          <?php endif; ?>
                        </td>
                        <td><code><?= h($row['invoice_no']) ?></code></td>
                        <td><?= h($row['issue_date']) ?></td>
                        <td><?= h($row['due_date']) ?></td>
                        <td class="text-end"><?= moneyv($row['invoice_total']) ?></td>
                        <td class="text-end text-success"><?= moneyv($row['amount_paid']) ?></td>
                        <td class="text-end fw-bold"><?= moneyv($row['balance_due']) ?></td>
                        <td class="text-center days-old"><?= $row['days_old'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- AP Ageing Section -->
  <div class="card mb-4">
    <div class="card-header ageing-header">
      <h5 class="mb-0">
        <i class="bi bi-arrow-down-circle"></i> Accounts Payable Ageing
        <span class="badge bg-info ms-2"><?= count($apData) ?> expenses</span>
      </h5>
    </div>
    <div class="card-body p-0">
      <?php if (empty($apData)): ?>
        <div class="p-3 text-center text-muted">
          <i class="bi bi-info-circle"></i> No outstanding payables found.
        </div>
      <?php else: ?>
        <?php foreach ($apAgeing as $bucket => $rows): ?>
          <?php if (!empty($rows)): ?>
            <div class="p-3 border-bottom">
              <h6 class="mb-3">
                <span class="badge <?= $bucket === 'Current' ? 'bg-primary' : ($bucket === '1-30 Days' ? 'bg-warning' : ($bucket === '31-60 Days' ? 'bg-warning' : ($bucket === '61-90 Days' ? 'bg-danger' : 'bg-danger'))) ?>">
                  <?= h($bucket) ?>
                </span>
                <span class="ms-2">Total: <?= moneyv($apTotals[$bucket]) ?> AED</span>
              </h6>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Vendor</th>
                      <th>Reference No</th>
                      <th>Expense Date</th>
                      <th class="text-end">Total Amount</th>
                      <th class="text-end">Balance Due</th>
                      <th class="text-center">Days Old</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td>
                          <div><?= h($row['vendor_name']) ?></div>
                          <?php if ($row['trn']): ?>
                            <small class="text-muted">TRN: <?= h($row['trn']) ?></small>
                          <?php endif; ?>
                        </td>
                        <td><code><?= h($row['reference_no']) ?></code></td>
                        <td><?= h($row['expense_date']) ?></td>
                        <td class="text-end"><?= moneyv($row['expense_total']) ?></td>
                        <td class="text-end fw-bold"><?= moneyv($row['balance_due']) ?></td>
                        <td class="text-center days-old"><?= $row['days_old'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>
</body>
</html>
