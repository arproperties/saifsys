<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sm_reporting_service.php';
require_once __DIR__ . '/../includes/report_date_helpers.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($n) { return number_format((float)$n, 2); }
}

$dates = report_date_range();
$from = $dates['from'];
$to = $dates['to'];
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$reports = new SmReportingService($conn);
$summary = $reports->getExpenseSummary($from, $to);

if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="expense_summary_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Section', 'Key', 'Count', 'Amount']);
    foreach ($summary['by_type'] as $r) {
        fputcsv($out, ['By Type', $r['expense_type'], $r['cnt'], $r['total']]);
    }
    foreach ($summary['by_account'] as $r) {
        fputcsv($out, ['By GL Account', $r['account_no'] . ' ' . $r['name'], '', $r['net_expense']]);
    }
    fputcsv($out, ['Totals', 'GL Expenses', '', $summary['total_gl']]);
    fputcsv($out, ['Totals', 'Expense Table', '', $summary['total_table']]);
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Expense Summary</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid my-4">
  <div class="d-flex align-items-center mb-3">
    <div>
      <h3 class="mb-0">Expense Summary</h3>
      <div class="text-muted small">By expense type and GL account · ServiceAccountingService</div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="prepaid_schedules.php" class="btn btn-outline-primary btn-sm">Prepaid Schedules</a>
      <a href="journal_entries.php" class="btn btn-outline-primary btn-sm">Journal Entries</a>
      <a href="?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&export=csv" class="btn btn-success btn-sm"><i class="bi bi-download"></i> CSV</a>
      <a href="reports.php" class="btn btn-outline-secondary btn-sm">Reports</a>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-2"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= h($from) ?>"></div>
        <div class="col-md-2"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= h($to) ?>"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary">Run</button></div>
        <div class="col-md-6 text-md-end">
          <span class="badge bg-danger me-1">GL: <?= money($summary['total_gl']) ?></span>
          <span class="badge bg-secondary">Expense table: <?= money($summary['total_table']) ?></span>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header bg-white"><strong>By Expense Type</strong></div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Type</th><th class="text-end">Count</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            <?php foreach ($summary['by_type'] as $r): ?>
              <tr>
                <td><?= h($r['expense_type']) ?></td>
                <td class="text-end"><?= (int)$r['cnt'] ?></td>
                <td class="text-end"><?= money($r['total']) ?></td>
              </tr>
            <?php endforeach; if (!$summary['by_type']): ?>
              <tr><td colspan="3" class="text-muted text-center py-3">No posted expenses in period.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header bg-white"><strong>By GL Expense Account</strong> <span class="text-muted small">(includes prepaid amortization &amp; manual JVs)</span></div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Account</th><th>Name</th><th class="text-end">Net Expense</th></tr></thead>
            <tbody>
            <?php foreach ($summary['by_account'] as $r): ?>
              <tr>
                <td><code><?= h($r['account_no']) ?></code></td>
                <td><?= h($r['name']) ?></td>
                <td class="text-end"><?= money($r['net_expense']) ?></td>
              </tr>
            <?php endforeach; if (!$summary['by_account']): ?>
              <tr><td colspan="3" class="text-muted text-center py-3">No GL expense activity in period.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
