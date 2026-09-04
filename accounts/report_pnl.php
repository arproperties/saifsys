<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/cleaning_accounting_context.php';
require_once __DIR__.'/../includes/report_date_helpers.php';
require_once __DIR__.'/../includes/report_gl_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$dates = report_date_range();
$from = $dates['from'];
$to = $dates['to'];
$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$includeZero = isset($_GET['show_zero']) && $_GET['show_zero'] === '1';
$showDetail = isset($_GET['detail']) && $_GET['detail'] === '1';

$cleaningCompanyId = cleaning_accounting_company_id($conn);
$periodLabel = report_pnl_period_label($from, $to);
$pnl = report_pnl_build($conn, $cleaningCompanyId, $from, $to, $includeZero);
$accountDetails = $showDetail
    ? report_pnl_fetch_journal_details($conn, $cleaningCompanyId, $from, $to)
    : [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pnl_money(float $n): string { return report_pnl_format_money($n); }
function pnl_amount_class(float $n, bool $invert = false): string
{
    $positive = $invert ? $n < 0 : $n >= 0;
    return $positive ? 'text-success' : 'text-danger';
}

$queryBase = report_date_query($from, $to, array_filter([
    'show_zero' => $includeZero ? '1' : null,
    'detail' => $showDetail ? '1' : null,
]));

if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="pnl_'.$from.'_to_'.$to.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Report', 'Profit and Loss']);
    fputcsv($out, ['Period', $periodLabel]);
    fputcsv($out, []);
    fputcsv($out, ['Section', 'Account', 'Name', 'Total']);

    $writeSection = static function ($out, string $title, array $rows, ?float $total = null) {
        fputcsv($out, [$title, '', '', '']);
        foreach ($rows as $r) {
            fputcsv($out, ['', $r['account_no'], $r['name'], number_format((float)$r['net'], 2)]);
        }
        if ($total !== null) {
            fputcsv($out, ['', '', 'Total for ' . $title, number_format($total, 2)]);
        }
        fputcsv($out, []);
    };

    $writeSection($out, 'Income', $pnl['income'], $pnl['total_income']);
    $writeSection($out, 'Cost Of Sales', $pnl['cost_of_sales'], $pnl['total_cos']);
    fputcsv($out, ['', '', 'Gross Profit', number_format($pnl['gross_profit'], 2)]);
    fputcsv($out, []);
    $writeSection($out, 'Expenses', $pnl['expenses'], $pnl['total_expenses']);
    fputcsv($out, ['', '', 'Net Earning', number_format($pnl['net_earning'], 2)]);
    exit;
}

function pnl_render_account_rows(array $rows, array $accountDetails, string $from, string $to): void
{
    if (!$rows) {
        echo '<div class="pnl-empty text-muted py-2 ps-3"><em>No activity in this period</em></div>';
        return;
    }
    foreach ($rows as $row) {
        $accNo = (string)$row['account_no'];
        $net = (float)$row['net'];
        $hasDetails = !empty($accountDetails[$accNo]);
        ?>
        <div class="pnl-line <?= $hasDetails ? 'pnl-line-expandable' : '' ?>" data-account="<?= h($accNo) ?>">
            <div class="pnl-line-main">
                <?php if ($hasDetails): ?>
                    <button type="button" class="btn btn-sm btn-light border-0 toggle-details"
                            data-account="<?= h($accNo) ?>" title="Show journal entries">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                <?php else: ?>
                    <span class="pnl-line-spacer"></span>
                <?php endif; ?>
                <span class="account-code"><?= h($accNo) ?></span>
                <span class="account-name"><?= h($row['name']) ?></span>
                <span class="account-amount <?= pnl_amount_class($net, $row['type'] === 'Expense') ?>">
                    <?= pnl_money($net) ?>
                </span>
            </div>
            <?php if ($hasDetails): ?>
            <div class="pnl-detail d-none" data-account="<?= h($accNo) ?>">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Journal</th>
                                <th>Source</th>
                                <th>Description</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Net</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $type = (string)$row['type'];
                        foreach ($accountDetails[$accNo] as $detail):
                            $detailNet = report_pnl_account_net($type, (float)$detail['debit'], (float)$detail['credit']);
                        ?>
                            <tr>
                                <td><?= h($detail['journal_date']) ?></td>
                                <td>
                                    <code><?= h($detail['journal_no']) ?></code>
                                    <a href="report_general_ledger.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&account_id=<?= (int)($detail['account_id'] ?? 0) ?>"
                                       class="ms-1" title="View in GL"><i class="bi bi-box-arrow-up-right"></i></a>
                                </td>
                                <td><span class="badge text-bg-secondary"><?= h($detail['source']) ?></span></td>
                                <td><?= h($detail['line_description'] ?: $detail['memo'] ?: '-') ?></td>
                                <td class="text-end"><?= number_format((float)$detail['debit'], 2) ?></td>
                                <td class="text-end"><?= number_format((float)$detail['credit'], 2) ?></td>
                                <td class="text-end"><?= number_format($detailNet, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

function pnl_render_section_card(string $title, string $totalLabel, array $rows, float $total, string $accent, array $accountDetails, string $from, string $to): void
{
    ?>
    <div class="card pnl-section-card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-0 pt-3 pb-0">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 section-title section-title-<?= h($accent) ?>"><?= h($title) ?></h5>
                <span class="badge rounded-pill text-bg-<?= h($accent) ?>"><?= count($rows) ?> accounts</span>
            </div>
        </div>
        <div class="card-body pt-2 pb-0">
            <?php pnl_render_account_rows($rows, $accountDetails, $from, $to); ?>
        </div>
        <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-3">
            <strong><?= h($totalLabel) ?></strong>
            <strong class="fs-5 <?= pnl_amount_class($total) ?>"><?= pnl_money($total) ?></strong>
        </div>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Profit &amp; Loss</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root {
    --pnl-income: #198754;
    --pnl-cos: #fd7e14;
    --pnl-expense: #dc3545;
    --pnl-profit: #0d6efd;
  }
  .pnl-toolbar { background: #fff; border-radius: .75rem; box-shadow: 0 .125rem .5rem rgba(0,0,0,.06); }
  .period-badge { font-size: .85rem; font-weight: 500; }
  .kpi-card {
    border: none; border-radius: .75rem; box-shadow: 0 .125rem .5rem rgba(0,0,0,.06);
    height: 100%;
  }
  .kpi-card .kpi-label { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; }
  .kpi-card .kpi-value { font-size: 1.5rem; font-weight: 700; font-variant-numeric: tabular-nums; }
  .kpi-income { border-left: 4px solid var(--pnl-income); }
  .kpi-gross { border-left: 4px solid var(--pnl-cos); }
  .kpi-expense { border-left: 4px solid var(--pnl-expense); }
  .kpi-net { border-left: 4px solid var(--pnl-profit); }
  .pnl-section-card { border-radius: .75rem; overflow: hidden; }
  .section-title { font-size: 1rem; font-weight: 600; padding-left: .75rem; border-left: 4px solid #dee2e6; }
  .section-title-success { border-left-color: var(--pnl-income); }
  .section-title-warning { border-left-color: var(--pnl-cos); }
  .section-title-danger { border-left-color: var(--pnl-expense); }
  .pnl-line-main {
    display: grid;
    grid-template-columns: 2rem 4.5rem 1fr auto;
    gap: .5rem;
    align-items: center;
    padding: .55rem .25rem;
    border-radius: .5rem;
  }
  .pnl-line-expandable .pnl-line-main:hover { background: #f8f9fa; }
  .pnl-line-spacer { width: 2rem; display: inline-block; }
  .account-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: .8rem; color: #6c757d; background: #f1f3f5;
    padding: .15rem .45rem; border-radius: .35rem;
  }
  .account-name { color: #212529; }
  .account-amount { font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .pnl-detail { margin: .25rem 0 .75rem 2.5rem; padding: .75rem; background: #f8f9fa; border-radius: .5rem; }
  .pnl-highlight {
    background: linear-gradient(135deg, #f8f9fa 0%, #fff 100%);
    border-radius: .75rem; border: 1px solid #e9ecef;
    padding: 1rem 1.25rem;
  }
  @media (max-width: 576px) {
    .pnl-line-main { grid-template-columns: 2rem 1fr; }
    .account-code { grid-column: 2; }
    .account-name { grid-column: 2; }
    .account-amount { grid-column: 2; text-align: left; padding-top: .25rem; }
  }
  @media print {
    .no-print { display: none !important; }
    body { background: #fff; }
    .kpi-card, .pnl-section-card, .pnl-toolbar { box-shadow: none !important; }
  }
</style>
</head>
<body class="bg-light">
<div class="container-fluid px-3 px-lg-4 my-4">

  <div class="d-flex flex-wrap align-items-center gap-2 no-print mb-3">
    <div class="me-auto">
      <h3 class="mb-1">Profit &amp; Loss</h3>
      <span class="badge text-bg-secondary period-badge"><i class="bi bi-calendar3"></i> <?= h($periodLabel) ?></span>
    </div>
    <a class="btn btn-outline-primary btn-sm" href="gm_dashboard.php?<?= h($queryBase) ?>">GM Dashboard</a>
    <a class="btn btn-outline-secondary btn-sm" href="reports.php">&larr; Back</a>
    <a class="btn btn-secondary btn-sm" href="?<?= h($queryBase) ?>&export=csv">Export CSV</a>
    <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()"><i class="bi bi-printer"></i></button>
  </div>

  <div class="pnl-toolbar p-3 mb-4 no-print">
    <form class="row g-3 align-items-end" method="get" action="">
      <div class="col-sm-6 col-md-3">
        <label class="form-label mb-1">From</label>
        <input type="date" class="form-control" name="from" value="<?= h($from) ?>" required>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label mb-1">To</label>
        <input type="date" class="form-control" name="to" value="<?= h($to) ?>" required>
      </div>
      <div class="col-sm-6 col-md-2">
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-play-fill"></i> Run</button>
      </div>
      <div class="col-sm-6 col-md-4">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="show_zero" value="1" id="showZero"
                 <?= $includeZero ? 'checked' : '' ?>>
          <label class="form-check-label" for="showZero">Show zero-balance accounts</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="detail" value="1" id="showDetail"
                 <?= $showDetail ? 'checked' : '' ?>>
          <label class="form-check-label" for="showDetail">Journal detail per account</label>
        </div>
      </div>
    </form>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
      <div class="card kpi-card kpi-income">
        <div class="card-body">
          <div class="kpi-label">Total Income</div>
          <div class="kpi-value text-success"><?= pnl_money($pnl['total_income']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card kpi-card kpi-gross">
        <div class="card-body">
          <div class="kpi-label">Gross Profit</div>
          <div class="kpi-value <?= pnl_amount_class($pnl['gross_profit']) ?>"><?= pnl_money($pnl['gross_profit']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card kpi-card kpi-expense">
        <div class="card-body">
          <div class="kpi-label">Total Expenses</div>
          <div class="kpi-value"><?= pnl_money($pnl['total_expenses'] + $pnl['total_cos']) ?></div>
          <small class="text-muted">COS <?= pnl_money($pnl['total_cos']) ?> + OpEx <?= pnl_money($pnl['total_expenses']) ?></small>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card kpi-card kpi-net">
        <div class="card-body">
          <div class="kpi-label">Net Earning</div>
          <div class="kpi-value <?= pnl_amount_class($pnl['net_earning']) ?>"><?= pnl_money($pnl['net_earning']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-lg-6">
      <?php pnl_render_section_card('Income', 'Total for Income', $pnl['income'], $pnl['total_income'], 'success', $accountDetails, $from, $to); ?>
      <?php pnl_render_section_card('Cost of Sales', 'Total for Cost of Sales', $pnl['cost_of_sales'], $pnl['total_cos'], 'warning', $accountDetails, $from, $to); ?>
    </div>
    <div class="col-lg-6">
      <?php pnl_render_section_card('Expenses', 'Total for Expenses', $pnl['expenses'], $pnl['total_expenses'], 'danger', $accountDetails, $from, $to); ?>
      <div class="pnl-highlight d-flex justify-content-between align-items-center mb-3">
        <div>
          <div class="text-muted small text-uppercase">Gross Profit</div>
          <div class="fs-4 fw-bold <?= pnl_amount_class($pnl['gross_profit']) ?>"><?= pnl_money($pnl['gross_profit']) ?></div>
          <small class="text-muted">Income − Cost of Sales</small>
        </div>
        <div class="text-end">
          <div class="text-muted small text-uppercase">Net Earning</div>
          <div class="fs-3 fw-bold <?= pnl_amount_class($pnl['net_earning']) ?>"><?= pnl_money($pnl['net_earning']) ?></div>
          <small class="text-muted">Gross Profit − Expenses</small>
        </div>
      </div>
    </div>
  </div>

</div>

<?php if ($showDetail): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.toggle-details').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var account = this.dataset.account;
      var panel = document.querySelector('.pnl-detail[data-account="' + account + '"]');
      var icon = this.querySelector('i');
      if (panel) {
        panel.classList.toggle('d-none');
        icon.classList.toggle('bi-chevron-down');
        icon.classList.toggle('bi-chevron-up');
      }
    });
  });
});
</script>
<?php endif; ?>
</body>
</html>
