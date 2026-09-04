<?php
/**
 * System Health Check — read-only diagnostics for Service Management / Cleaning accounting.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../includes/cleaning_accounting_context.php';
require_once __DIR__ . '/../includes/service_health_service.php';
require_once __DIR__ . '/../includes/service_accounting_service.php';
require_once __DIR__ . '/../includes/service_management_settings.php';

require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($n) { return number_format((float)$n, 2); }
}

$companyId = cleaning_accounting_company_id($conn);
$health = service_health_collect($conn, $companyId);
$labels = service_health_section_labels();
$summary = $health['summary'] ?? [];
$critical = (int)($summary['critical_count'] ?? 0);
$warning = (int)($summary['warning_count'] ?? 0);

$svc = new ServiceAccountingService($conn, $companyId);
$liveAr = $svc->getARSummary();
$svcEnabled = sm_use_accounting_service($conn);
$isOwner = has_role('Owner');

if (isset($_GET['refresh_cache']) && $_GET['refresh_cache'] === '1') {
    $svc->invalidateFinancialCache();
    header('Location: system_health_check.php?cache_cleared=1');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>System Health Check</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{background:#f6f7f9}
  .hero{background:#fff;border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.06);padding:18px 22px;margin-bottom:18px}
  .health-card{border:0;border-radius:16px;background:#fff;box-shadow:0 10px 24px rgba(0,0,0,.06)}
  .table-sm td,.table-sm th{font-size:.86rem}
  .workflow-strip{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
  .wf-step{background:#f8f9fa;border:1px solid #dee2e6;border-radius:999px;padding:.35rem .85rem;font-size:.85rem}
  .wf-arrow{color:#6c757d}
</style>
</head>
<body>
<div class="container-fluid my-4 px-4">
  <div class="hero d-flex align-items-center flex-wrap gap-3">
    <div>
      <div class="text-uppercase small text-muted">Service Management</div>
      <h3 class="mb-0">System Health Check</h3>
      <div class="text-muted">Read-only scan — use Live Data Repair (Owner) to fix legacy issues before go-live.</div>
    </div>
    <div class="ms-auto d-flex flex-wrap gap-2">
      <?php if ($isOwner): ?>
        <a href="sm_live_data_repair.php" class="btn btn-danger"><i class="bi bi-lightning-charge"></i> Live Repair</a>
      <?php endif; ?>
      <a href="report_wo_invoice_reconciliation.php" class="btn btn-outline-primary"><i class="bi bi-table"></i> WO vs Invoice</a>
      <a href="accounting_health.php" class="btn btn-outline-secondary"><i class="bi bi-heart-pulse"></i> GL Health</a>
      <a href="tools.php" class="btn btn-outline-secondary"><i class="bi bi-tools"></i> Tools</a>
      <a href="?refresh_cache=1" class="btn btn-warning"><i class="bi bi-arrow-clockwise"></i> Clear cache</a>
      <a href="system_health_check.php" class="btn btn-primary"><i class="bi bi-search"></i> Refresh scan</a>
    </div>
  </div>

  <?php if (!empty($_GET['cache_cleared'])): ?>
    <div class="alert alert-success">Financial dashboard cache cleared.</div>
  <?php endif; ?>

  <div class="card health-card mb-4 p-3">
    <div class="small text-muted mb-2">Target workflow (Phase 2+)</div>
    <div class="workflow-strip">
      <span class="wf-step"><strong>OPEN</strong> — edit freely</span>
      <span class="wf-arrow">→</span>
      <span class="wf-step"><strong>COMPLETED</strong> — job done</span>
      <span class="wf-arrow">→</span>
      <span class="wf-step bg-primary text-white"><strong>FINALIZED</strong> — Admin/Accountant only</span>
      <span class="wf-arrow">→</span>
      <span class="wf-step"><strong>PAID</strong> / Adjustment / Cancellation</span>
    </div>
    <div class="small text-muted mt-2">
      Receptionist does <em>not</em> finalize. See
      <a href="../docs/service_management/WORKFLOW.md" target="_blank">workflow guide</a>.
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="health-card p-3 border-start border-4 border-<?= $critical ? 'danger' : 'success' ?>">
        <div class="text-muted small">Critical issues</div>
        <div class="fs-3 fw-bold"><?= number_format($critical) ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="health-card p-3 border-start border-4 border-<?= $warning ? 'warning' : 'success' ?>">
        <div class="text-muted small">Warnings</div>
        <div class="fs-3 fw-bold"><?= number_format($warning) ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="health-card p-3">
        <div class="text-muted small">Live AR total (service)</div>
        <div class="fs-4 fw-bold">AED <?= money($liveAr['ar_total'] ?? 0) ?></div>
        <div class="small text-muted">Overdue: AED <?= money($liveAr['overdue_total'] ?? 0) ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="health-card p-3">
        <div class="text-muted small">Accounting service flag</div>
        <div class="fw-bold"><?= $svcEnabled ? 'ENABLED' : 'Compare mode only' ?></div>
        <div class="small text-muted">Set <code>sm_use_accounting_service=1</code> in settings to switch dashboards.</div>
      </div>
    </div>
  </div>

  <?php if ($critical === 0 && $warning === 0): ?>
    <div class="alert alert-success">No critical or warning issues detected in this scan.</div>
  <?php else: ?>
    <div class="alert alert-warning">
      <strong><?= number_format($critical + $warning) ?> issue row(s)</strong> need review.
      Suggested actions are shown per section — nothing is auto-fixed.
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <?php foreach ($labels as $key => [$title, $color]): ?>
      <?php $count = (int)($health[$key]['count'] ?? 0); ?>
      <div class="col-xl-3 col-md-4 col-sm-6">
        <a href="#<?= h($key) ?>" class="text-decoration-none text-dark">
          <div class="health-card p-3 h-100 border-start border-4 border-<?= h($count ? $color : 'success') ?>">
            <div class="text-muted small"><?= h($title) ?></div>
            <div class="fs-3 fw-bold"><?= number_format($count) ?></div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <?php foreach ($labels as $key => [$title, $color]): ?>
    <?php
      $rows = $health[$key]['rows'] ?? [];
      $desc = $health[$key]['description'] ?? '';
    ?>
    <div class="card health-card mb-4" id="<?= h($key) ?>">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <div>
          <strong><?= h($title) ?></strong>
          <?php if ($desc): ?><div class="small text-muted"><?= h($desc) ?></div><?php endif; ?>
        </div>
        <span class="badge text-bg-<?= h(count($rows) ? $color : 'success') ?>"><?= number_format(count($rows)) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
          <thead class="table-light">
            <tr>
              <?php
              $columns = $rows ? array_keys($rows[0]) : [];
              if ($key === 'dashboard_metric_mismatches' || $key === 'pnl_invoice_mismatch_mtd') {
                  $columns[] = 'suggested_action';
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
                    <?php if ($col === 'suggested_action'): ?>
                      <td class="small"><?= h(service_health_suggested_action((string)($row['metric'] ?? $key))) ?></td>
                    <?php else: ?>
                      <td><?= h((string)($row[$col] ?? '')) ?></td>
                    <?php endif; ?>
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
