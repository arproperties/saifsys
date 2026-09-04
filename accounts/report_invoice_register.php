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
$rows = $reports->getInvoiceRegister($from, $to);

$total = 0.0;
$totalPaid = 0.0;
$totalBal = 0.0;
foreach ($rows as $r) {
    $total += (float)$r['total'];
    $totalPaid += (float)$r['amount_paid'];
    $totalBal += (float)$r['balance_due'];
}

if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="invoice_register_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Invoice No', 'Issue Date', 'Due Date', 'Client', 'Categories', 'Category Split', 'WO#', 'Status', 'Subtotal', 'VAT', 'Total', 'Paid', 'Balance']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['invoice_no'], $r['issue_date'], $r['due_date'], $r['client_name'],
            $r['service_category'], $r['category_breakdown'] ?? '', $r['order_id'], $r['status'],
            $r['subtotal'], $r['vat_amount'], $r['total'], $r['amount_paid'], $r['balance_due'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Invoice Register</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid my-4">
  <div class="d-flex align-items-center mb-3">
    <div>
      <h3 class="mb-0">Invoice Register</h3>
      <div class="text-muted small">Collectible invoices (excludes BINV) · ServiceAccountingService</div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="gm_dashboard.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-outline-primary btn-sm">GM Dashboard</a>
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
        <div class="col-md-6 text-md-end text-muted small align-self-end">
          <?= count($rows) ?> invoices · Total <?= money($total) ?> · Paid <?= money($totalPaid) ?> · Balance <?= money($totalBal) ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Invoice</th><th>Issue</th><th>Due</th><th>Client</th><th>Categories</th><th>Split (AED)</th>
            <th>WO</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="invoice_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['invoice_no']) ?></a></td>
            <td><?= h($r['issue_date']) ?></td>
            <td><?= h($r['due_date'] ?: '—') ?></td>
            <td><?= h($r['client_name'] ?: '—') ?></td>
            <td><?= h($r['service_category']) ?></td>
            <td><small class="text-muted"><?= h($r['category_breakdown'] ?? '') ?></small></td>
            <td><?= $r['order_id'] ? (int)$r['order_id'] : '—' ?></td>
            <td><span class="badge bg-secondary"><?= h($r['status']) ?></span></td>
            <td class="text-end"><?= money($r['total']) ?></td>
            <td class="text-end"><?= money($r['amount_paid']) ?></td>
            <td class="text-end"><?= money($r['balance_due']) ?></td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="11" class="text-center text-muted py-4">No invoices in this period.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot class="table-light">
          <tr>
            <th colspan="7" class="text-end">Totals</th>
            <th class="text-end"><?= money($total) ?></th>
            <th class="text-end"><?= money($totalPaid) ?></th>
            <th class="text-end"><?= money($totalBal) ?></th>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>
</body>
</html>
