<?php
// accounts/reports.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/report_date_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

$dates = report_date_range();
$reportFrom = $dates['from'];
$reportTo = $dates['to'];
$reportQs = report_date_query($reportFrom, $reportTo);
$reportCsv = function(string $page) use ($reportFrom, $reportTo) {
    return $page . '?' . report_date_query($reportFrom, $reportTo, ['export' => 'csv']);
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Reports</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{background:#f6f7f9}
  .hero{background:#fff;border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.06);padding:18px 22px;margin-bottom:18px}
  .report-card{
    border:0;border-radius:18px;background:#fff; box-shadow:0 10px 24px rgba(0,0,0,.06);
    transition: transform .08s ease, box-shadow .08s ease;
  }
  .report-card:hover{ transform: translateY(-2px); box-shadow:0 14px 28px rgba(0,0,0,.09); }
  .r-icon{width:48px;height:48px;border-radius:12px;display:grid;place-items:center;background:#80000010;color:#800000}
  .r-title{font-weight:700;margin-bottom:.2rem}
  .r-desc{color:#6b7280;font-size:.92rem}
  .badge-live{background:#e8fff2;color:#137a36;border:1px solid #bdf1cf}
  .badge-soon{background:#fff3cd;color:#8a6d3b;border:1px solid #ffe8a1}
</style>
</head>
<body>
<div class="container my-4">

  <div class="hero d-flex align-items-center">
    <div>
      <div class="text-uppercase small text-muted">Accounting</div>
      <h3 class="mb-0">Reports</h3>
    </div>
    <div class="ms-auto">
      <a href="reports_enhanced.php" class="btn btn-primary me-2"><i class="bi bi-graph-up"></i> Enhanced View</a>
      <a href="../account" class="btn btn-outline-secondary" data-tab="dashboard"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
  </div>

  <div class="hero mb-3 py-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-2">
        <label class="form-label small mb-0">Report period — From</label>
        <input type="date" class="form-control" name="from" value="<?= h($reportFrom) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-0">To</label>
        <input type="date" class="form-control" name="to" value="<?= h($reportTo) ?>">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary w-100">Apply to all reports</button>
      </div>
      <div class="col-md-6 small text-muted">
        <?= h(report_date_period_label($reportFrom, $reportTo)) ?> — View/Export links below use this range (set the same day for a single-day report).
      </div>
    </form>
  </div>

  <div class="row g-3">
    <!-- Trial Balance -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3"><i class="bi bi-columns-gap fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Trial Balance</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="report_trial_balance.php?<?= h($reportQs) ?>"><i class="bi bi-eye"></i> View</a>
          <a class="btn btn-outline-secondary" href="<?= h($reportCsv('report_trial_balance.php')) ?>"><i class="bi bi-download"></i> Export</a>
        </div>
      </div>
    </div>

    <!-- Profit & Loss -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3"><i class="bi bi-graph-down fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Profit & Loss</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="report_pnl.php?<?= h($reportQs) ?>"><i class="bi bi-eye"></i> View</a>
          <a class="btn btn-outline-secondary" href="<?= h($reportCsv('report_pnl.php')) ?>"><i class="bi bi-download"></i> Export</a>
        </div>
      </div>
    </div>

    <!-- Balance Sheet -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3"><i class="bi bi-diagram-3 fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Balance Sheet</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="report_balance_sheet.php?<?= h($reportQs) ?>"><i class="bi bi-eye"></i> View</a>
          <a class="btn btn-outline-secondary" href="<?= h($reportCsv('report_balance_sheet.php')) ?>"><i class="bi bi-download"></i> Export</a>
        </div>
      </div>
    </div>

    <!-- General Ledger -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3"><i class="bi bi-journal-text fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">General Ledger</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="report_general_ledger.php?<?= h($reportQs) ?>"><i class="bi bi-eye"></i> View</a>
          <a class="btn btn-outline-secondary" href="<?= h($reportCsv('report_general_ledger.php')) ?>"><i class="bi bi-download"></i> Export</a>
        </div>
      </div>
    </div>

    <!-- VAT Report -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3"><i class="bi bi-receipt fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">VAT Report</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="report_vat.php?<?= h($reportQs) ?>"><i class="bi bi-eye"></i> View</a>
          <a class="btn btn-outline-secondary" href="<?= h($reportCsv('report_vat.php')) ?>"><i class="bi bi-download"></i> Export</a>
        </div>
      </div>
    </div>

    <!-- AR / AP Ageing -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3"><i class="bi bi-people fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">AR / AP Ageing</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="report_ar_ap_ageing.php?<?= h($reportQs) ?>"><i class="bi bi-eye"></i> View</a>
          <a class="btn btn-outline-secondary" href="<?= h($reportCsv('report_ar_ap_ageing.php')) ?>"><i class="bi bi-download"></i> Export</a>
        </div>
      </div>
    </div>

    <!-- WO vs Invoice reconciliation -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3"><i class="bi bi-link-45deg fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">WO vs Invoice Reconciliation</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="report_wo_invoice_reconciliation.php?<?= h($reportQs) ?>"><i class="bi bi-eye"></i> View</a>
          <a class="btn btn-outline-secondary" href="system_health_check.php"><i class="bi bi-shield-check"></i> Health</a>
        </div>
      </div>
    </div>

    <!-- GM Dashboard (Phase 8) -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-primary" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#e7f1ff;color:#0d6efd"><i class="bi bi-speedometer2 fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">GM Dashboard</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
            <div class="r-desc mt-1">Revenue, profit, AR, jobs — one page via ServiceAccountingService</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="gm_dashboard.php?<?= h($reportQs) ?>"><i class="bi bi-eye"></i> Open</a>
        </div>
      </div>
    </div>

    <!-- Invoice Register -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3"><i class="bi bi-file-earmark-spreadsheet fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Invoice Register</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
            <div class="r-desc mt-1">Collectible invoices with balances — CSV export</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="report_invoice_register.php?<?= h($reportQs) ?>"><i class="bi bi-eye"></i> View</a>
          <a class="btn btn-outline-secondary" href="<?= h($reportCsv('report_invoice_register.php')) ?>"><i class="bi bi-download"></i> Export</a>
        </div>
      </div>
    </div>

    <!-- Expense Summary -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3"><i class="bi bi-pie-chart fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Expense Summary</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
            <div class="r-desc mt-1">By type and GL account — links to prepaid &amp; journals</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="report_expense_summary.php?<?= h($reportQs) ?>"><i class="bi bi-eye"></i> View</a>
          <a class="btn btn-outline-secondary" href="<?= h($reportCsv('report_expense_summary.php')) ?>"><i class="bi bi-download"></i> Export</a>
        </div>
      </div>
    </div>

    <?php if (has_role('Owner', $conn)): ?>
    <!-- Tools (Owner only — see tools.php) -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-warning" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#fff8e6;color:#856404"><i class="bi bi-tools fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Tools</div>
              <span class="badge badge-soon ms-2">Owner</span>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-warning" href="tools.php"><i class="bi bi-tools"></i> Open tools</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- GL exceptions (receipts / expenses linkage) -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-secondary" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#e9ecef;color:#495057"><i class="bi bi-exclamation-triangle fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">GL exceptions review</div>
              <span class="badge badge-soon ms-2">Admin</span>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-outline-dark" href="gl_exceptions_review.php"><i class="bi bi-list-check"></i> Open</a>
        </div>
      </div>
    </div>

    <!-- Bank reconciliation (cleaning) -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-primary" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#e7f1ff;color:#0d6efd"><i class="bi bi-bank fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Bank reconciliation</div>
              <span class="badge badge-live ms-2">Live</span>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="bank_reconciliation.php"><i class="bi bi-bank"></i> Open</a>
          <a class="btn btn-outline-secondary" href="bank_reconciliation_accounts.php"><i class="bi bi-gear"></i> Accounts</a>
        </div>
      </div>
    </div>

  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Clean URL navigation for data-tab links
  document.querySelectorAll('a[data-tab]').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const tab = this.getAttribute('data-tab');
      const form = document.createElement('form');
      form.method = 'POST';
      // Use absolute path based on current location
      const currentPath = window.location.pathname;
      const basePath = currentPath.substring(0, currentPath.indexOf('/accounts'));
      form.action = basePath + '/account';
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'tab';
      input.value = tab;
      form.appendChild(input);
      document.body.appendChild(form);
      form.submit();
    });
  });
</script>
</body>
</html>
