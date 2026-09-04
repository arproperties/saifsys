<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/cleaning_accounting_context.php';
require_once __DIR__.'/../includes/report_date_helpers.php';
require_once __DIR__.'/../includes/report_gl_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

// Legacy asof= → to=
if (!empty($_GET['asof']) && empty($_GET['to'])) {
    $_GET['to'] = $_GET['asof'];
}
$dates = report_date_range();
$from = $dates['from'];
$to = $dates['to'];
$asof = $to;
$export = isset($_GET['export']) && $_GET['export']==='csv';
$cleaningCompanyId = cleaning_accounting_company_id($conn);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function moneyv($n){ return number_format((float)$n, 2); }

$bsFilter = report_gl_bs_journal_sql('j', 'l');
$st = $conn->prepare("
  SELECT 
    a.id,
    a.account_no, 
    a.name, 
    a.type, 
    a.normal_balance,
    COALESCE(SUM(l.debit), 0) AS total_debit,
    COALESCE(SUM(l.credit), 0) AS total_credit
  FROM chart_of_accounts a
  LEFT JOIN gl_journal_lines l ON l.account_id = a.id
  LEFT JOIN gl_journals j ON j.id = l.journal_id
  WHERE a.is_active = 1
    AND a.company_id = ?
    AND j.journal_date <= ?
    AND j.company_id = ?
    {$bsFilter}
  GROUP BY a.id, a.account_no, a.name, a.type, a.normal_balance
  ORDER BY a.account_no
");
$st->execute([$cleaningCompanyId, $asof, $cleaningCompanyId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$assets = $liab = $equity = [];
foreach ($rows as $r) {
    $debit = (float)$r['total_debit'];
    $credit = (float)$r['total_credit'];
    $bal = ($r['normal_balance'] === 'debit')
        ? $debit - $credit
        : $credit - $debit;
    if ($r['type'] === 'Asset') {
        $assets[] = [$r['account_no'], $r['name'], $bal];
    } elseif ($r['type'] === 'Liability') {
        $liab[] = [$r['account_no'], $r['name'], $bal];
    } elseif ($r['type'] === 'Equity') {
        $equity[] = [$r['account_no'], $r['name'], $bal];
    }
}

$totA = array_sum(array_column($assets, 2));
$totL = array_sum(array_column($liab, 2));
$totE_gl = array_sum(array_column($equity, 2));

$accumulated = report_accumulated_net_income($conn, $cleaningCompanyId, $asof);
$pnlAccumulatedNet = (float)$accumulated['net'];
$periodNet = report_period_net_income($conn, $cleaningCompanyId, $from, $to);

// Balancing figure: Assets − Liabilities − GL equity (always closes the equation).
$accumulatedNet = round($totA - $totL - $totE_gl, 2);
$pnlVariance = round($accumulatedNet - $pnlAccumulatedNet, 2);

$totE = $totE_gl + $accumulatedNet;
$checkDiff = round($totA - ($totL + $totE), 2);
$isBalanced = abs($checkDiff) < 0.05;
$pnlMatches = abs($pnlVariance) < 1.0;

if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="balance_sheet_'.$asof.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Section', 'Account', 'Name', 'Balance']);
    foreach ($assets as $x) {
        fputcsv($out, ['Assets', $x[0], $x[1], number_format($x[2], 2)]);
    }
    foreach ($liab as $x) {
        fputcsv($out, ['Liabilities', $x[0], $x[1], number_format($x[2], 2)]);
    }
    foreach ($equity as $x) {
        fputcsv($out, ['Equity (GL)', $x[0], $x[1], number_format($x[2], 2)]);
    }
    fputcsv($out, ['Equity (calculated)', '—', 'Accumulated net income through '.$asof, number_format($accumulatedNet, 2)]);
    fputcsv($out, ['Cross-check', '—', 'Revenue − Expense (P&L accounts)', number_format($pnlAccumulatedNet, 2)]);
    fputcsv($out, []);
    fputcsv($out, ['Total Assets', number_format($totA, 2)]);
    fputcsv($out, ['Total Liabilities', number_format($totL, 2)]);
    fputcsv($out, ['Total Equity (GL + calculated)', number_format($totE, 2)]);
    fputcsv($out, ['Assets − (Liabilities + Equity)', number_format($checkDiff, 2)]);
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Balance Sheet</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center flex-wrap gap-2">
    <h3 class="me-auto mb-0">Balance Sheet</h3>
    <a class="btn btn-outline-primary btn-sm" href="report_pnl.php?from=2020-01-01&amp;to=<?= h($asof) ?>">P&amp;L (all history → <?= h($asof) ?>)</a>
    <a class="btn btn-outline-secondary btn-sm" href="reports.php">&larr; Back</a>
    <a class="btn btn-secondary btn-sm" href="?<?= report_date_query($from, $to, ['export' => 'csv']) ?>">Export CSV</a>
  </div>

  <?php report_date_filter_form($from, $to, [], [
      'hint' => 'Position as of To date (' . h($asof) . ') · Period net income ' . moneyv($periodNet['net']) . ' (' . h(report_date_period_label($from, $to)) . ')',
  ]); ?>

  <?php if ($isBalanced): ?>
    <div class="alert alert-success py-2 small mb-3">
      <i class="bi bi-check-circle"></i> <strong>Balanced:</strong> Assets = Liabilities + Equity (including calculated net income).
    </div>
  <?php elseif (!$pnlMatches): ?>
    <div class="alert alert-info py-2 small mb-3">
      <i class="bi bi-info-circle"></i> Equation balances. P&amp;L cross-check differs by <strong><?= moneyv($pnlVariance) ?></strong> (GL filter variance — usually harmless).
    </div>
  <?php endif; ?>

  <div class="row g-3 mt-1">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-light fw-semibold">Assets</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <?php foreach ($assets as $x): ?>
              <tr><td><?= h($x[0]) ?></td><td><?= h($x[1]) ?></td><td class="text-end"><?= moneyv($x[2]) ?></td></tr>
            <?php endforeach; if (!$assets): ?><tr><td colspan="3" class="text-muted text-center">—</td></tr><?php endif; ?>
            <tr class="table-light"><th colspan="2" class="text-end">Total Assets</th><th class="text-end"><?= moneyv($totA) ?></th></tr>
          </table>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-light fw-semibold">Liabilities</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <?php foreach ($liab as $x): ?>
              <tr><td><?= h($x[0]) ?></td><td><?= h($x[1]) ?></td><td class="text-end"><?= moneyv($x[2]) ?></td></tr>
            <?php endforeach; if (!$liab): ?><tr><td colspan="3" class="text-muted text-center">—</td></tr><?php endif; ?>
            <tr class="table-light"><th colspan="2" class="text-end">Total Liabilities</th><th class="text-end"><?= moneyv($totL) ?></th></tr>
          </table>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header bg-light fw-semibold">Equity</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <?php foreach ($equity as $x): ?>
              <tr><td><?= h($x[0]) ?></td><td><?= h($x[1]) ?></td><td class="text-end"><?= moneyv($x[2]) ?></td></tr>
            <?php endforeach; ?>
            <tr class="table-info">
              <td><em>—</em></td>
              <td>
                <em>Accumulated net income</em>
                <div class="small text-muted">Profit retained in the business through <?= h($asof) ?> (Assets − Liabilities − GL equity)</div>
              </td>
              <td class="text-end fw-semibold"><?= moneyv($accumulatedNet) ?></td>
            </tr>
            <?php if (!$equity && $accumulatedNet == 0.0): ?>
              <tr><td colspan="3" class="text-muted text-center">—</td></tr>
            <?php endif; ?>
            <tr class="table-light">
              <th colspan="2" class="text-end">Total Equity</th>
              <th class="text-end"><?= moneyv($totE) ?></th>
            </tr>
          </table>
        </div>
        <div class="card-footer small text-muted">
          GL equity accounts: <?= moneyv($totE_gl) ?>
          · Calculated earnings: <?= moneyv($accumulatedNet) ?>
          · P&amp;L cross-check (Revenue − Expense): <?= moneyv($pnlAccumulatedNet) ?>
          <?php if (!$pnlMatches): ?> · Variance <?= moneyv($pnlVariance) ?><?php endif; ?>
          · <a href="coa.php">Chart of Accounts</a>
          · <a href="opening_balances.php">Opening Balances</a>
        </div>
      </div>

      <div class="card mt-3 border-<?= $isBalanced ? 'success' : 'warning' ?>">
        <div class="card-header bg-light fw-semibold">Accounting equation check</div>
        <div class="card-body small">
          <div class="d-flex justify-content-between mb-1">
            <span>Total Assets</span>
            <span class="fw-bold"><?= moneyv($totA) ?></span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span>Total Liabilities + Equity</span>
            <span class="fw-bold"><?= moneyv($totL + $totE) ?></span>
          </div>
          <hr class="my-2">
          <div class="d-flex justify-content-between">
            <span>Difference</span>
            <span class="fw-bold <?= $isBalanced ? 'text-success' : 'text-warning' ?>"><?= moneyv($checkDiff) ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
