<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/company_helper.php';
require_once __DIR__.'/../includes/report_date_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$dates = report_date_range();
$from = $dates['from'];
$to = $dates['to'];
$export = isset($_GET['export']) && $_GET['export']==='csv';
$currentCompanyId = current_company_id($conn) ?: 1;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function moneyv($n){ return number_format((float)$n, 2); }

// UAE VAT Rate (5%)
$UAE_VAT_RATE = 5.0;

// Get Output VAT (VAT on Sales) - from invoices
$outputVatSql = "
  SELECT 
    'Invoice' as source_type,
    i.invoice_no as reference,
    i.issue_date as transaction_date,
    c.client_name as customer_supplier,
    c.trn as trn,
    ROUND(GREATEST(COALESCE(i.total, 0) - COALESCE(i.vat_amount, 0), 0), 2) as taxable_amount,
    i.vat_rate as vat_rate,
    i.vat_amount as vat_amount,
    i.total as total_amount,
    'Standard Rate' as vat_type
  FROM invoices i
  LEFT JOIN client c ON c.id = i.client_id
  WHERE i.issue_date BETWEEN ? AND ? 
    AND i.company_id = ?
    AND i.status IN ('issued', 'partially_paid', 'paid')
    AND " . ar_collectible_invoice_sql('i') . "
    AND i.vat_amount > 0
  ORDER BY i.issue_date, i.invoice_no
";

$st = $conn->prepare($outputVatSql);
$st->execute([$from, $to, $currentCompanyId]);
$outputVatData = $st->fetchAll(PDO::FETCH_ASSOC);

// Get Input VAT (VAT on Purchases) - from expenses
$inputVatSql = "
  SELECT 
    'Expense' as source_type,
    COALESCE(NULLIF(e.reference_no, ''), CONCAT('EXP-', e.id)) as reference,
    e.expense_date as transaction_date,
    v.name as customer_supplier,
    v.trn as trn,
    el.line_subtotal as taxable_amount,
    el.vat_rate as vat_rate,
    el.line_vat as vat_amount,
    el.line_total as total_amount,
    'Standard Rate' as vat_type
  FROM expenses e
  LEFT JOIN vendors v ON v.id = e.vendor_id
  JOIN expense_lines el ON el.expense_id = e.id
  WHERE e.expense_date BETWEEN ? AND ? 
    AND e.company_id = ?
    AND e.status = 'posted'
    AND el.line_vat > 0
  ORDER BY e.expense_date, e.reference_no, el.line_no
";

$st = $conn->prepare($inputVatSql);
$st->execute([$from, $to, $currentCompanyId]);
$inputVatData = $st->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$outputVatTotal = array_sum(array_column($outputVatData, 'vat_amount'));
$inputVatTotal = array_sum(array_column($inputVatData, 'vat_amount'));
$netVatPayable = $outputVatTotal - $inputVatTotal;

// Calculate taxable amounts
$outputTaxableTotal = array_sum(array_column($outputVatData, 'taxable_amount'));
$inputTaxableTotal = array_sum(array_column($inputVatData, 'taxable_amount'));
$outputGrossTotal = array_sum(array_column($outputVatData, 'total_amount'));
$inputGrossTotal = array_sum(array_column($inputVatData, 'total_amount'));

if ($export) {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="vat_report_'.$from.'_to_'.$to.'.csv"');
  $out = fopen('php://output', 'w');
  
  // Header
  fputcsv($out, ['UAE VAT REPORT - '.$from.' to '.$to]);
  fputcsv($out, []);
  
  // Output VAT Section
  fputcsv($out, ['OUTPUT VAT (VAT ON SALES)']);
  fputcsv($out, ['Date', 'Reference', 'Customer/Supplier', 'TRN', 'Taxable Amount', 'VAT Rate %', 'VAT Amount', 'Total Amount']);
  foreach ($outputVatData as $row) {
    fputcsv($out, [
      $row['transaction_date'],
      $row['reference'],
      $row['customer_supplier'],
      $row['trn'] ?: 'N/A',
      moneyv($row['taxable_amount']),
      moneyv($row['vat_rate']),
      moneyv($row['vat_amount']),
      moneyv($row['total_amount'])
    ]);
  }
  fputcsv($out, ['', '', '', 'TOTAL:', moneyv($outputTaxableTotal), '', moneyv($outputVatTotal), moneyv($outputGrossTotal)]);
  fputcsv($out, []);
  
  // Input VAT Section
  fputcsv($out, ['INPUT VAT (VAT ON PURCHASES)']);
  fputcsv($out, ['Date', 'Reference', 'Customer/Supplier', 'TRN', 'Taxable Amount', 'VAT Rate %', 'VAT Amount', 'Total Amount']);
  foreach ($inputVatData as $row) {
    fputcsv($out, [
      $row['transaction_date'],
      $row['reference'],
      $row['customer_supplier'],
      $row['trn'] ?: 'N/A',
      moneyv($row['taxable_amount']),
      moneyv($row['vat_rate']),
      moneyv($row['vat_amount']),
      moneyv($row['total_amount'])
    ]);
  }
  fputcsv($out, ['', '', '', 'TOTAL:', moneyv($inputTaxableTotal), '', moneyv($inputVatTotal), moneyv($inputGrossTotal)]);
  fputcsv($out, []);
  
  // Summary
  fputcsv($out, ['VAT SUMMARY']);
  fputcsv($out, ['Total Sales Value (Excl. VAT):', moneyv($outputTaxableTotal)]);
  fputcsv($out, ['Total Output VAT:', moneyv($outputVatTotal)]);
  fputcsv($out, ['Total Expenses Value (Excl. VAT):', moneyv($inputTaxableTotal)]);
  fputcsv($out, ['Total Input VAT:', moneyv($inputVatTotal)]);
  fputcsv($out, ['Net VAT Payable:', moneyv($netVatPayable)]);
  
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>VAT Report - UAE Compliant</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .vat-header { background: #f8f9fa; border-left: 4px solid #800000; }
  .vat-summary { background: #e8f5e8; border: 2px solid #28a745; }
  .vat-payable { background: #fff3cd; border: 2px solid #ffc107; }
  .vat-refund { background: #d1ecf1; border: 2px solid #17a2b8; }
  .trn-badge { font-size: 0.8em; }
  .vat-rate { font-weight: bold; color: #800000; }
  .fta-amount-label { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; }
</style>
</head>
<body class="bg-light">
<div class="container my-4">
  
  <div class="row mb-4">
    <div class="col">
      <h3><i class="bi bi-receipt"></i> VAT Report</h3>
    </div>
    <div class="col-auto">
      <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Reports</a>
    </div>
  </div>

  <!-- Filters -->
  <div class="card mb-4">
    <div class="card-body">
      <form method="GET" class="row g-3">
        <div class="col-md-4">
          <label class="form-label">From Date</label>
          <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">To Date</label>
          <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">&nbsp;</label>
          <div class="d-grid">
            <button type="submit" class="btn btn-primary">Run</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Export Button -->
  <div class="mb-3">
    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" 
       class="btn btn-outline-success">
      <i class="bi bi-download"></i> Export CSV
    </a>
  </div>

  <!-- VAT Summary -->
  <div class="row mb-4">
    <div class="col-md-4">
      <div class="card vat-summary">
        <div class="card-body text-center">
          <h5 class="card-title">Output VAT</h5>
          <div class="fta-amount-label">Total Sales Value (Excl. VAT)</div>
          <h3 class="text-success mb-2"><?= moneyv($outputTaxableTotal) ?> AED</h3>
          <div class="border-top pt-2">
            <div class="fta-amount-label">Output VAT Amount</div>
            <h5 class="text-success mb-0"><?= moneyv($outputVatTotal) ?> AED</h5>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card vat-summary">
        <div class="card-body text-center">
          <h5 class="card-title">Input VAT</h5>
          <div class="fta-amount-label">Total Expenses Value (Excl. VAT)</div>
          <h3 class="text-info mb-2"><?= moneyv($inputTaxableTotal) ?> AED</h3>
          <div class="border-top pt-2">
            <div class="fta-amount-label">Input VAT Amount</div>
            <h5 class="text-info mb-0"><?= moneyv($inputVatTotal) ?> AED</h5>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card <?= $netVatPayable >= 0 ? 'vat-payable' : 'vat-refund' ?>">
        <div class="card-body text-center">
          <h5 class="card-title">Net VAT</h5>
          <h3 class="<?= $netVatPayable >= 0 ? 'text-warning' : 'text-info' ?>">
            <?= moneyv($netVatPayable) ?> AED
          </h3>
        </div>
      </div>
    </div>
  </div>

  <!-- Output VAT Section -->
  <div class="card mb-4">
    <div class="card-header vat-header">
      <h5 class="mb-0">
        <i class="bi bi-arrow-up-circle"></i> Output VAT (VAT on Sales)
        <span class="badge bg-success ms-2"><?= count($outputVatData) ?> transactions</span>
      </h5>
    </div>
    <div class="card-body p-0">
      <?php if (empty($outputVatData)): ?>
        <div class="p-3 text-center text-muted">
          <i class="bi bi-info-circle"></i> No output VAT transactions found for the selected period.
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Date</th>
                <th>Invoice No</th>
                <th>Customer</th>
                <th>TRN</th>
                <th class="text-end">Taxable Amount</th>
                <th class="text-center">VAT Rate</th>
                <th class="text-end">VAT Amount</th>
                <th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($outputVatData as $row): ?>
                <tr>
                  <td><?= h($row['transaction_date']) ?></td>
                  <td><code><?= h($row['reference']) ?></code></td>
                  <td><?= h($row['customer_supplier']) ?></td>
                  <td>
                    <?php if ($row['trn']): ?>
                      <span class="badge bg-primary trn-badge"><?= h($row['trn']) ?></span>
                    <?php else: ?>
                      <span class="text-muted">N/A</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end"><?= moneyv($row['taxable_amount']) ?></td>
                  <td class="text-center vat-rate"><?= moneyv($row['vat_rate']) ?>%</td>
                  <td class="text-end text-success fw-bold"><?= moneyv($row['vat_amount']) ?></td>
                  <td class="text-end"><?= moneyv($row['total_amount']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
              <tr>
                <th colspan="4" class="text-end">TOTAL OUTPUT VAT:</th>
                <th class="text-end text-success"><?= moneyv($outputTaxableTotal) ?> AED</th>
                <th></th>
                <th class="text-end text-success"><?= moneyv($outputVatTotal) ?> AED</th>
                <th class="text-end"><?= moneyv($outputGrossTotal) ?> AED</th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Input VAT Section -->
  <div class="card mb-4">
    <div class="card-header vat-header">
      <h5 class="mb-0">
        <i class="bi bi-arrow-down-circle"></i> Input VAT (VAT on Purchases)
        <span class="badge bg-info ms-2"><?= count($inputVatData) ?> transactions</span>
      </h5>
    </div>
    <div class="card-body p-0">
      <?php if (empty($inputVatData)): ?>
        <div class="p-3 text-center text-muted">
          <i class="bi bi-info-circle"></i> No input VAT transactions found for the selected period.
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Supplier</th>
                <th>TRN</th>
                <th class="text-end">Taxable Amount</th>
                <th class="text-center">VAT Rate</th>
                <th class="text-end">VAT Amount</th>
                <th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inputVatData as $row): ?>
                <tr>
                  <td><?= h($row['transaction_date']) ?></td>
                  <td><code><?= h($row['reference']) ?></code></td>
                  <td><?= h($row['customer_supplier']) ?></td>
                  <td>
                    <?php if ($row['trn']): ?>
                      <span class="badge bg-primary trn-badge"><?= h($row['trn']) ?></span>
                    <?php else: ?>
                      <span class="text-muted">N/A</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end"><?= moneyv($row['taxable_amount']) ?></td>
                  <td class="text-center vat-rate"><?= moneyv($row['vat_rate']) ?>%</td>
                  <td class="text-end text-info fw-bold"><?= moneyv($row['vat_amount']) ?></td>
                  <td class="text-end"><?= moneyv($row['total_amount']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
              <tr>
                <th colspan="4" class="text-end">TOTAL INPUT VAT:</th>
                <th class="text-end text-info"><?= moneyv($inputTaxableTotal) ?> AED</th>
                <th></th>
                <th class="text-end text-info"><?= moneyv($inputVatTotal) ?> AED</th>
                <th class="text-end"><?= moneyv($inputGrossTotal) ?> AED</th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>
</body>
</html>
