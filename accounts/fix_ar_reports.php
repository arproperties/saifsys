<?php
/**
 * Comprehensive AR Reports Fix Tool
 * This tool diagnoses and fixes issues with AR Ageing Report, Trial Balance, and General Ledger
 */

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$action = $_GET['action'] ?? 'diagnose';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function moneyv($n){ return number_format((float)$n, 2); }

// Get all outstanding invoices (correct logic - matches AR Dashboard)
$outstandingInvoices = [];
$st = $conn->prepare("
  SELECT 
    i.id,
    i.invoice_no,
    i.issue_date,
    i.status,
    i.total,
    COALESCE(SUM(ra.amount_applied), 0) as amount_paid,
    i.total - COALESCE(SUM(ra.amount_applied), 0) as balance_due
  FROM invoices i
  LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
  WHERE i.status IN ('issued', 'partially_paid')
    AND i.status != 'void'
    AND " . ar_collectible_invoice_sql('i') . "
  GROUP BY i.id, i.invoice_no, i.issue_date, i.status, i.total
  HAVING balance_due > 0
  ORDER BY i.issue_date
");
$st->execute();
$outstandingInvoices = $st->fetchAll(PDO::FETCH_ASSOC);

$totalOutstanding = array_sum(array_column($outstandingInvoices, 'balance_due'));

// Get GL Trade Receivables balance
$glSt = $conn->prepare("
  SELECT 
    COALESCE(SUM(l.debit), 0) AS total_debit,
    COALESCE(SUM(l.credit), 0) AS total_credit,
    COALESCE(SUM(l.debit - l.credit), 0) AS net_balance
  FROM gl_journal_lines l
  JOIN gl_journals j ON j.id = l.journal_id
  JOIN chart_of_accounts a ON a.id = l.account_id
  WHERE a.account_no = '1110'
    AND a.is_active = 1
    AND j.journal_date <= CURDATE()
    AND j.is_posted = 1 
    AND j.is_reversed = 0
    AND NOT (j.source = 'reversal' AND EXISTS (SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = j.id))
    AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Compensating entry: Voiding duplicate reversal%')
    AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Void incorrect bank transfer%')
    AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Bank Transfer%' AND j.is_reversed = 1)
");
$glSt->execute();
$glBalance = $glSt->fetch(PDO::FETCH_ASSOC);

// Get invoices with GL journals
$invoicesWithGL = [];
$glInvSt = $conn->prepare("
  SELECT DISTINCT j.source_id as invoice_id
  FROM gl_journals j
  JOIN gl_journal_lines l ON l.journal_id = j.id
  JOIN chart_of_accounts a ON a.id = l.account_id
  WHERE a.account_no = '1110'
    AND j.source = 'invoice'
    AND j.is_posted = 1
    AND j.is_reversed = 0
    AND NOT EXISTS (
      SELECT 1 FROM gl_journals rev_j 
      WHERE rev_j.source = 'reversal' 
        AND rev_j.source_id = j.id
        AND rev_j.is_posted = 1
        AND rev_j.is_reversed = 0
        AND NOT EXISTS (
          SELECT 1 FROM gl_journals adj_j 
          WHERE adj_j.source = 'adjustment' 
            AND adj_j.source_id = rev_j.id
        )
    )
");
$glInvSt->execute();
$invoicesWithGL = $glInvSt->fetchAll(PDO::FETCH_COLUMN);

// Get receipts posted to GL
$receiptsInGL = [];
$glRecSt = $conn->prepare("
  SELECT 
    j.source_id as receipt_id,
    SUM(l.credit) as credit_amount
  FROM gl_journals j
  JOIN gl_journal_lines l ON l.journal_id = j.id
  JOIN chart_of_accounts a ON a.id = l.account_id
  WHERE a.account_no = '1110'
    AND j.source = 'receipt'
    AND j.is_posted = 1
    AND j.is_reversed = 0
    AND NOT EXISTS (
      SELECT 1 FROM gl_journals rev_j 
      WHERE rev_j.source = 'reversal' 
        AND rev_j.source_id = j.id
        AND rev_j.is_posted = 1
        AND rev_j.is_reversed = 0
        AND NOT EXISTS (
          SELECT 1 FROM gl_journals adj_j 
          WHERE adj_j.source = 'adjustment' 
            AND adj_j.source_id = rev_j.id
        )
    )
  GROUP BY j.source_id
");
$glRecSt->execute();
$receiptsInGL = $glRecSt->fetchAll(PDO::FETCH_ASSOC);

$totalReceiptCredits = array_sum(array_column($receiptsInGL, 'credit_amount'));

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>AR Reports Diagnostic & Fix</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .summary-box { background: #f8f9fa; border-left: 4px solid #0d6efd; padding: 15px; margin-bottom: 20px; }
  .error-box { background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin-bottom: 20px; }
  .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; }
</style>
</head>
<body class="bg-light">
<div class="container my-4">
  <h3><i class="bi bi-tools"></i> AR Reports Diagnostic & Fix Tool</h3>
  <p class="text-muted">Comprehensive diagnosis and fix for AR Ageing Report, Trial Balance, and General Ledger</p>

  <!-- Summary -->
  <div class="summary-box">
    <h5>Current Status</h5>
    <div class="row">
      <div class="col-md-4">
        <strong>Outstanding Invoices (Correct):</strong><br>
        <span class="h4 text-primary"><?= moneyv($totalOutstanding) ?> AED</span><br>
        <small class="text-muted"><?= count($outstandingInvoices) ?> invoices with status 'issued' or 'partially_paid'</small>
      </div>
      <div class="col-md-4">
        <strong>GL Trade Receivables:</strong><br>
        <span class="h4 <?= ($glBalance['net_balance'] ?? 0) < 0 ? 'text-danger' : 'text-success' ?>">
          <?= moneyv($glBalance['net_balance'] ?? 0) ?> AED
        </span><br>
        <small class="text-muted">
          Debits: <?= moneyv($glBalance['total_debit'] ?? 0) ?> | 
          Credits: <?= moneyv($glBalance['total_credit'] ?? 0) ?>
        </small>
      </div>
      <div class="col-md-4">
        <strong>Difference:</strong><br>
        <span class="h3 <?= abs($totalOutstanding - ($glBalance['net_balance'] ?? 0)) > 0.01 ? 'text-danger' : 'text-success' ?>">
          <?= moneyv(abs($totalOutstanding - ($glBalance['net_balance'] ?? 0))) ?> AED
        </span>
      </div>
    </div>
  </div>

  <!-- Issues Found -->
  <?php if (($glBalance['net_balance'] ?? 0) < 0): ?>
    <div class="error-box">
      <h5><i class="bi bi-exclamation-triangle"></i> Critical Issue: Negative Trade Receivables Balance</h5>
      <p>The GL shows Trade Receivables as <strong><?= moneyv($glBalance['net_balance']) ?> AED</strong> (negative).</p>
      <p>This means receipt credits (<?= moneyv($glBalance['total_credit']) ?>) exceed invoice debits (<?= moneyv($glBalance['total_debit']) ?>).</p>
      <p><strong>Possible causes:</strong></p>
      <ul>
        <li>Receipts posted without corresponding invoices</li>
        <li>Duplicate receipt postings</li>
        <li>Invoices reversed but receipts not reversed</li>
        <li>Incorrect receipt amounts in GL</li>
      </ul>
    </div>
  <?php endif; ?>

  <!-- Outstanding Invoices Not in GL -->
  <?php
  $invoicesNotInGL = array_filter($outstandingInvoices, function($inv) use ($invoicesWithGL) {
    return !in_array($inv['id'], $invoicesWithGL);
  });
  if (!empty($invoicesNotInGL)):
    $missingTotal = array_sum(array_column($invoicesNotInGL, 'balance_due'));
  ?>
    <div class="warning-box">
      <h5><i class="bi bi-exclamation-triangle"></i> Found <?= count($invoicesNotInGL) ?> Outstanding Invoices NOT Posted to GL</h5>
      <p>Total outstanding amount: <strong><?= moneyv($missingTotal) ?> AED</strong></p>
      <p>These invoices need to be posted to GL to match the AR Ageing Report.</p>
      <div class="table-responsive">
        <table class="table table-sm table-bordered">
          <thead>
            <tr>
              <th>Invoice No</th>
              <th>Issue Date</th>
              <th>Status</th>
              <th class="text-end">Total</th>
              <th class="text-end">Paid</th>
              <th class="text-end">Balance</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($invoicesNotInGL, 0, 20) as $inv): ?>
              <tr>
                <td><?= h($inv['invoice_no']) ?></td>
                <td><?= h($inv['issue_date']) ?></td>
                <td><?= h($inv['status']) ?></td>
                <td class="text-end"><?= moneyv($inv['total']) ?></td>
                <td class="text-end"><?= moneyv($inv['amount_paid']) ?></td>
                <td class="text-end"><strong><?= moneyv($inv['balance_due']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <?php if (count($invoicesNotInGL) > 20): ?>
            <tfoot>
              <tr>
                <th colspan="5" class="text-end">Showing first 20 of <?= count($invoicesNotInGL) ?> invoices</th>
                <th class="text-end"><?= moneyv($missingTotal) ?></th>
              </tr>
            </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- Recommendations -->
  <div class="card mt-4">
    <div class="card-header">
      <h5 class="mb-0">Recommendations</h5>
    </div>
    <div class="card-body">
      <ol>
        <li><strong>AR Ageing Report:</strong> Should show <strong><?= moneyv($totalOutstanding) ?> AED</strong> from <strong><?= count($outstandingInvoices) ?> invoices</strong> with status 'issued' or 'partially_paid'.</li>
        <li><strong>Trial Balance:</strong> Trade Receivables should show <strong><?= moneyv($totalOutstanding) ?> AED</strong> (positive balance), not negative.</li>
        <li><strong>General Ledger:</strong> Should match Trial Balance calculation exactly.</li>
        <?php if (!empty($invoicesNotInGL)): ?>
          <li><strong>Action Required:</strong> Post <?= count($invoicesNotInGL) ?> missing invoices to GL. This will increase GL balance by approximately <?= moneyv($missingTotal) ?> AED.</li>
        <?php endif; ?>
        <?php if (($glBalance['net_balance'] ?? 0) < 0): ?>
          <li><strong>Action Required:</strong> Investigate why receipt credits exceed invoice debits. Check for duplicate receipts or receipts without invoices.</li>
        <?php endif; ?>
      </ol>
    </div>
  </div>

  <div class="mt-3">
    <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Reports</a>
    <a href="report_ar_ap_ageing.php" class="btn btn-primary"><i class="bi bi-eye"></i> View AR Ageing Report</a>
  </div>
</div>
</body>
</html>

