<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../includes/accounting_health_service.php';

require_role(['Owner','Admin','Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($n) { return number_format((float)$n, 2); }
}

$currentCompanyId = current_company_id($conn) ?: 1;
$health = accounting_health_collect($conn, $currentCompanyId);

$labels = [
    'duplicate_invoice_journals' => ['Duplicate Active Invoice Journals', 'danger', 'Invoice has more than one active GL invoice journal.'],
    'missing_invoice_journals' => ['Missing Invoice Journals', 'danger', 'Active invoice has no active GL invoice journal.'],
    'void_invoice_active_journals' => ['Void/Draft With Active Journals', 'warning', 'Draft or void invoice still has active GL invoice journal.'],
    'duplicate_receipt_journals' => ['Duplicate Active Receipt Journals', 'danger', 'Receipt has more than one active GL receipt journal.'],
    'missing_receipt_journals' => ['Missing Receipt Journals', 'danger', 'Receipt has no active GL receipt journal.'],
    'allocation_status_mismatches' => ['Invoice Allocation Mismatches', 'warning', 'Invoice paid/balance/status does not match receipt allocations.'],
    'overallocated_receipts' => ['Overallocated Receipts', 'warning', 'Receipt allocations exceed the received amount.'],
    'broken_allocations' => ['Broken Allocations', 'danger', 'Receipt allocation points to a missing receipt/invoice or wrong company.'],
    'invalid_trade_receivable_lines' => ['Invalid Trade Receivable Lines', 'danger', 'Trade Receivable contains unexpected source/debit/credit patterns.'],
];

$critical = (int)($health['critical_count'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Accounting Health</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{background:#f6f7f9}
  .hero{background:#fff;border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.06);padding:18px 22px;margin-bottom:18px}
  .health-card{border:0;border-radius:16px;background:#fff;box-shadow:0 10px 24px rgba(0,0,0,.06)}
  .table-sm td,.table-sm th{font-size:.86rem}
</style>
</head>
<body>
<div class="container-fluid my-4 px-4">
  <div class="hero d-flex align-items-center">
    <div>
      <div class="text-uppercase small text-muted">Cleaning Accounts</div>
      <h3 class="mb-0">Accounting Health</h3>
      <div class="text-muted">Read-only invariant checks for invoices, receipts, allocations and GL.</div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="tools.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Tools</a>
      <a href="accounting_health.php" class="btn btn-primary"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
    </div>
  </div>

  <div class="alert <?= $critical ? 'alert-warning' : 'alert-success' ?>">
    <strong><?= $critical ? number_format($critical) . ' issue row(s) found.' : 'No accounting health issues found.' ?></strong>
    <?php if ($critical): ?>
      Review the affected sections below. Repair tools remain available, but normal workflows should keep these counts at zero.
    <?php else: ?>
      Invoices, receipts, allocations and Trade Receivable passed the current checks.
    <?php endif; ?>
  </div>

  <div class="row g-3 mb-4">
    <?php foreach ($labels as $key => [$title, $color, $desc]): ?>
      <?php $count = (int)($health[$key]['count'] ?? 0); ?>
      <div class="col-xl-3 col-md-4 col-sm-6">
        <a href="#<?= h($key) ?>" class="text-decoration-none text-dark">
          <div class="health-card p-3 h-100 border-start border-4 border-<?= h($count ? $color : 'success') ?>">
            <div class="text-muted small"><?= h($title) ?></div>
            <div class="fs-3 fw-bold"><?= number_format($count) ?></div>
            <div class="small text-muted"><?= h($desc) ?></div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <?php foreach ($labels as $key => [$title, $color, $desc]): ?>
    <?php $rows = $health[$key]['rows'] ?? []; ?>
    <div class="card health-card mb-4" id="<?= h($key) ?>">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <div>
          <strong><?= h($title) ?></strong>
          <div class="small text-muted"><?= h($desc) ?></div>
        </div>
        <span class="badge text-bg-<?= h(count($rows) ? $color : 'success') ?>"><?= number_format(count($rows)) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
          <thead class="table-light">
            <tr>
              <?php
              $columns = [];
              if ($rows) {
                  $columns = array_keys($rows[0]);
              }
              ?>
              <?php if ($columns): ?>
                <?php foreach ($columns as $col): ?><th><?= h($col) ?></th><?php endforeach; ?>
              <?php else: ?>
                <th>Result</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows): ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <?php foreach ($columns as $col): ?>
                    <td><?= h((string)($row[$col] ?? '')) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td class="text-muted">No rows found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
