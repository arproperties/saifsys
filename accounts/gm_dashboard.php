<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sm_reporting_service.php';
require_once __DIR__ . '/../includes/cleaning_accounting_context.php';
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
$companyName = cleaning_accounting_company_name($conn);

$reports = new SmReportingService($conn);
$gm = $reports->getGMReport($from, $to);
$trend = $gm['monthly_trend'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>GM Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  body { background: #f6f7f9; }
  .hero { background: #fff; border-radius: 18px; box-shadow: 0 10px 24px rgba(0,0,0,.06); padding: 18px 22px; margin-bottom: 18px; }
  .kpi-card { border: 0; border-radius: 16px; background: #fff; box-shadow: 0 8px 20px rgba(0,0,0,.05); height: 100%; }
  .kpi-card .kpi-value { font-size: 1.5rem; font-weight: 700; }
  .kpi-card.revenue { border-left: 4px solid #0d6efd; }
  .kpi-card.expense { border-left: 4px solid #dc3545; }
  .kpi-card.profit { border-left: 4px solid #198754; }
  .kpi-card.ar { border-left: 4px solid #fd7e14; }
  .kpi-card.ops { border-left: 4px solid #6f42c1; }
  .chart-wrap { height: 280px; position: relative; }
  .source-badge { font-size: .75rem; }
</style>
</head>
<body>
<div class="container-fluid my-4">
  <div class="hero d-flex flex-wrap align-items-center gap-2">
    <div>
      <div class="text-uppercase small text-muted">Service Management</div>
      <h3 class="mb-0">GM Dashboard</h3>
      <div class="text-muted small"><?= h($companyName) ?> · <?= h($from) ?> to <?= h($to) ?></div>
    </div>
    <div class="ms-auto d-flex flex-wrap gap-2">
      <a href="report_invoice_register.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-outline-primary btn-sm">Invoice Register</a>
      <a href="report_expense_summary.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-outline-primary btn-sm">Expense Summary</a>
      <a href="report_pnl.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-outline-secondary btn-sm">P&amp;L Detail</a>
      <a href="../account.php" class="btn btn-outline-secondary btn-sm">Accounts</a>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body py-2">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-auto">
          <label class="form-label small mb-0">From</label>
          <input type="date" class="form-control form-control-sm" name="from" value="<?= h($from) ?>">
        </div>
        <div class="col-auto">
          <label class="form-label small mb-0">To</label>
          <input type="date" class="form-control form-control-sm" name="to" value="<?= h($to) ?>">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary btn-sm">Run</button>
        </div>
        <div class="col-auto ms-md-auto">
          <span class="badge bg-light text-dark source-badge"><i class="bi bi-database-check me-1"></i><?= h($gm['source']) ?></span>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3">
      <div class="kpi-card revenue p-3">
        <div class="text-muted small">Revenue (GL)</div>
        <div class="kpi-value text-primary"><?= money($gm['revenue_gl']) ?></div>
        <div class="small text-muted">Invoices: <?= money($gm['revenue_invoices']) ?></div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="kpi-card expense p-3">
        <div class="text-muted small">Expenses (GL)</div>
        <div class="kpi-value text-danger"><?= money($gm['expenses_gl']) ?></div>
        <div class="small text-muted">Expense table: <?= money($gm['expenses_table']) ?></div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="kpi-card profit p-3">
        <div class="text-muted small">Net Profit</div>
        <div class="kpi-value text-success"><?= money($gm['net_profit']) ?></div>
        <div class="small text-muted">Collections: <?= money($gm['collections']) ?></div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="kpi-card ar p-3">
        <div class="text-muted small">Outstanding AR</div>
        <div class="kpi-value" style="color:#fd7e14"><?= money($gm['outstanding_ar']) ?></div>
        <div class="small text-muted">Overdue: <?= money($gm['overdue_ar']) ?> · <?= (int)$gm['open_invoices'] ?> open</div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="kpi-card ops p-3">
        <div class="text-muted small">Jobs completed (service date)</div>
        <div class="kpi-value"><?= (int)$gm['jobs_completed'] ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="kpi-card ops p-3">
        <div class="text-muted small">Jobs finalized</div>
        <div class="kpi-value"><?= (int)$gm['jobs_finalized'] ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="kpi-card ops p-3">
        <div class="text-muted small">Open work orders</div>
        <div class="kpi-value"><?= (int)$gm['open_work_orders'] ?></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="card">
        <div class="card-header bg-white"><strong>6-Month Trend</strong> <span class="text-muted small">(GL revenue, expenses, profit)</span></div>
        <div class="card-body">
          <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white">
          <strong>Revenue by Service Category</strong>
          <span class="text-muted small"> — split by catalog line, not work order header</span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Category</th><th class="text-end">Invoices</th><th class="text-end">Revenue</th></tr></thead>
            <tbody>
            <?php foreach ($gm['revenue_by_category'] as $cat): ?>
              <tr>
                <td><?= h($cat['category_name']) ?> <small class="text-muted">(<?= h($cat['category_code']) ?>)</small></td>
                <td class="text-end"><?= (int)$cat['invoice_count'] ?></td>
                <td class="text-end"><?= money($cat['revenue']) ?></td>
              </tr>
            <?php endforeach; if (!$gm['revenue_by_category']): ?>
              <tr><td colspan="3" class="text-muted text-center py-3">No categorized revenue in this period.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white"><strong>Top Overdue Invoices</strong></div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Invoice</th><th>Client</th><th class="text-end">Due</th></tr></thead>
            <tbody>
            <?php foreach ($gm['top_overdue'] as $od): ?>
              <tr>
                <td><a href="invoice_view.php?id=<?= (int)$od['id'] ?>"><?= h($od['invoice_no']) ?></a></td>
                <td><?= h($od['client_name'] ?? '—') ?></td>
                <td class="text-end"><?= money($od['balance_due']) ?></td>
              </tr>
            <?php endforeach; if (!$gm['top_overdue']): ?>
              <tr><td colspan="3" class="text-muted text-center py-3">No overdue invoices.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
const trendData = <?= json_encode($trend) ?>;
new Chart(document.getElementById('trendChart'), {
  type: 'bar',
  data: {
    labels: trendData.map(r => r.month),
    datasets: [
      { label: 'Revenue', data: trendData.map(r => r.revenue), backgroundColor: 'rgba(13,110,253,.7)' },
      { label: 'Expenses', data: trendData.map(r => r.expenses), backgroundColor: 'rgba(220,53,69,.7)' },
      { label: 'Profit', data: trendData.map(r => r.profit), type: 'line', borderColor: '#198754', backgroundColor: 'transparent', tension: 0.3 }
    ]
  },
  options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
});
</script>
</body>
</html>
