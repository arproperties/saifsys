<?php
require __DIR__.'/includes/auth.php';
require __DIR__.'/includes/db_connect.php';
require __DIR__.'/includes/ar_helpers.php';
require_once __DIR__.'/includes/gl_posting.php';
require_once __DIR__.'/includes/caching_service.php';
require_once __DIR__.'/includes/optimized_dashboard_service.php';
require_once __DIR__.'/includes/branding.php';
require_once __DIR__.'/includes/url_helper.php';
require_once __DIR__.'/includes/cleaning_payment_accounts.php';

// Get branding settings
$brand = getBrandSettings($conn);

require_once __DIR__.'/includes/rbac_department.php';

require_login();
// Check department access (backward compatible: fallback to role check)
if (!has_department_access(MODULE_CLEANING, DEPT_CLEANING_ACCOUNTS, $conn)) {
    require_role(['Owner','Admin','HR'], $conn);
}

/* ---- LOGIN GUARD ---- */
$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$hasLegacy     = !empty($_SESSION['username']) || !empty($_SESSION['fullname']);
if (!$hasUserObject && !$hasLegacy) {
  // Store next URL in session for clean URLs
  $_SESSION['login_next'] = preg_replace('/\.php$/', '', $_SERVER['REQUEST_URI'] ?? '/');
  header('Location: login');
  exit;
}

// statements PDF passthrough
if (($_GET['tab'] ?? '') === 'statements' && ($_GET['export'] ?? '') === 'pdf') {
  require __DIR__ . '/accounts/statements_export.php';
  exit;
}

/* ---- CURRENT USER ---- */
$U = $hasUserObject ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username']  ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_fmt($v){ return number_format((float)$v, 2); }

// Handle tab selection. Use GET for navigation so browser refresh never repeats a POST.
$tab = $_GET['tab'] ?? $_SESSION['account_tab'] ?? 'dashboard';
if (isset($_GET['tab'])) {
    $_SESSION['account_tab'] = $tab;
}
$tabs  = [
  'dashboard'       => 'AR Dashboard',
  'invoices'        => 'Invoices',
  'uninvoiced_orders' => 'Uninvoiced Orders',
  'payments'        => 'Payments',
  'statements'      => 'Statements',
  'batch_invoices'  => 'Batch Invoices',
  'billing_queue'   => 'Billing Queue',
  'coa'             => 'Chart of Accounts',
];
$header = $tabs[$tab] ?? 'AR Dashboard';

$cleaningPaymentAccountOptions = cleaning_payment_account_options($conn);

/* =========================
   DATA LOADERS (use views)
   ========================= */
function fetch_one_assoc(PDO $conn, string $sql){
  try { $r = $conn->query($sql)->fetch(PDO::FETCH_ASSOC); return $r ?: []; }
  catch(Throwable $e){ return []; }
}
function fetch_all_assoc(PDO $conn, string $sql){
  try { return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
  catch(Throwable $e){ return []; }
}

// Use optimized dashboard service with caching
$dashboardService = new OptimizedDashboardService($conn);

// Get cached dashboard data
$dashboardData = $dashboardService->getDashboardData();
$summary = $dashboardData['summary'] ?? [];
$ageing = $dashboardData['ageing'] ?? [];
$topOverdue = $dashboardData['top_overdue'] ?? [];
$topClientsBalance = $dashboardData['top_clients_balance'] ?? [];
$topUnbilled = $dashboardData['top_unbilled'] ?? [];
$credit = $dashboardData['client_credit'] ?? [];

// Initialize warning variables (data validation flags)
$warn_total_mismatch = false;
$warn_overdue_mismatch = false;
$warn_age_mismatch = false;
$computed_ar_total = 0;
$computed_overdue = 0;

// Get at-risk clients (those with AR > 0)
$atRisk = array_values(array_filter($credit, function($c){
  return (float)($c['ar_total'] ?? 0) > 0;
}));
$atRisk = array_slice($atRisk, 0, 10);

// Initialize chart data variables (needed for JavaScript)
$b0   = (float)($ageing['bucket_0']   ?? 0);
$b30  = (float)($ageing['bucket_30']  ?? 0);
$b60  = (float)($ageing['bucket_60']  ?? 0);
$b90  = (float)($ageing['bucket_90']  ?? 0);
$b120 = (float)($ageing['bucket_120'] ?? 0);

// Debug information (can be removed in production)
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
  echo "<!-- Debug Info:\n";
  echo "Summary: " . json_encode($summary) . "\n";
  echo "Ageing: " . json_encode($ageing) . "\n";
  echo "Top Overdue: " . json_encode($topOverdue) . "\n";
  echo "Top Unbilled: " . json_encode($topUnbilled) . "\n";
  echo "Credit: " . json_encode($credit) . "\n";
  echo "-->\n";
}

$todayIso = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Accounts | <?= h($brand['system_name']) ?></title>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
  <script>
    // Fallback Chart.js loading
    if (typeof Chart === 'undefined') {
      console.log('Loading fallback Chart.js...');
      const script = document.createElement('script');
      script.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js';
      script.onload = function() {
        console.log('Fallback Chart.js loaded successfully');
      };
      script.onerror = function() {
        console.error('Failed to load fallback Chart.js');
      };
      document.head.appendChild(script);
    }
  </script>
  <style>
    :root {
      --primary: <?= $brand['primary_color'] ?>;
      --primary-light: <?= $brand['primary_light'] ?>;
      --primary-dark: <?= $brand['primary_dark'] ?>;
      --accent: <?= $brand['accent_color'] ?>;
    }
    
    body{ background:#f6f7f9; }
    .sidebar{ min-height:100vh; width:240px; background:linear-gradient(180deg, var(--primary), var(--primary-light));
      color:#fff; position:sticky; top:0; z-index:1030; transition:width .2s ease; }
    .sidebar.collapsed{ width:84px; }
    .nav-sect{ font-size:.75rem; text-transform:uppercase; letter-spacing:.08em; color:#ffefc4; opacity:.7; margin:.5rem 0 .25rem .75rem; }
    .slink{ display:flex; align-items:center; gap:.75rem; padding:.6rem .9rem; margin:.15rem .5rem; border-radius:10px; color:#fff; text-decoration:none; }
    .slink:hover{ background:rgba(255,255,255,.08); }
    .slink.active{ background:rgba(255,255,255,.16); }
    .sicon{ width:28px; height:28px; display:grid; place-items:center; background:rgba(255,255,255,.15); border-radius:8px; }
    .slabel{ white-space:nowrap; }
    .sidebar.collapsed .slabel, .sidebar.collapsed .nav-sect{ display:none; }
    .collapse-btn{ position:absolute; right:-12px; top:12px; width:24px; height:24px; border-radius:50%; background:#fff; color:var(--primary);
      box-shadow:0 4px 10px rgba(0,0,0,.15); display:grid; place-items:center; cursor:pointer; }
    .avatar{ width:36px;height:36px;border-radius:50%; background:#eee; display:grid; place-items:center; font-weight:700; color:var(--primary); }
    .page-header-label{ font-size:1.85rem;font-weight:700;color:var(--primary); }
    .nav-pills .nav-link.active{ background-color:var(--primary)!important; }
    .kpi{ border:1px solid #eee; border-radius:14px; padding:16px; background:#fff; box-shadow:0 4px 12px rgba(0,0,0,.04); height:100%; }
    .kpi .label{ color:#6c757d; font-weight:600; }
    .kpi .main{ font-size:1.6rem; font-weight:800; }
    .hint{ font-size:.8rem; color:#6c757d; }
    
    /* Dark Mode */
    body.dark-mode { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: #e4e4e7; }
    body.dark-mode .sidebar { background: linear-gradient(180deg, var(--primary-dark), var(--primary)); }
    body.dark-mode .navbar { background: rgba(30, 41, 59, 0.95) !important; border-bottom: 1px solid #334155; }
    body.dark-mode .navbar-brand { color: #e4e4e7 !important; }
    body.dark-mode .kpi { background: #1e293b; color: #e4e4e7; border-color: #334155; }
    body.dark-mode .kpi .label { color: #94a3b8; }
    body.dark-mode .page-header-label { color: var(--accent); }
    body.dark-mode .text-dark { color: #e4e4e7 !important; }
    body.dark-mode .card { background: #1e293b; color: #e4e4e7; border-color: #334155; }
    body.dark-mode .table { color: #e4e4e7; }
    body.dark-mode .modal-content { background: #1e293b; color: #e4e4e7; }
    
    /* Mobile Responsiveness */
    @media (max-width: 768px) {
      .sidebar{ width:84px; }
      .sidebar .slabel, .sidebar .nav-sect{ display:none; }
      .page-header-label{ font-size:1.5rem; }
      .kpi .main{ font-size:1.3rem; }
      .kpi .label{ font-size:0.85rem; }
      .kpi .hint{ font-size:0.75rem; }
      .table-responsive{ font-size:0.875rem; }
      .btn-sm{ padding:0.25rem 0.5rem; font-size:0.75rem; }
      .modal-dialog{ margin:0.5rem; }
      .modal-content{ border-radius:0.5rem; }
      .card{ margin-bottom:1rem; }
      .container-fluid{ padding-left:0.75rem; padding-right:0.75rem; }
    }
    
    @media (max-width: 576px) {
      .page-header-label{ font-size:1.25rem; }
      .kpi{ padding:12px; }
      .kpi .main{ font-size:1.1rem; }
      .kpi .label{ font-size:0.8rem; }
      .kpi .hint{ font-size:0.7rem; }
      .table{ font-size:0.8rem; }
      .btn{ padding:0.375rem 0.75rem; font-size:0.875rem; }
      .modal-dialog{ margin:0.25rem; }
      .card-body{ padding:1rem; }
    }
    
    /* Touch-friendly improvements */
    @media (hover: none) and (pointer: coarse) {
      .btn{ min-height:44px; }
      .form-control, .form-select{ min-height:44px; }
      .nav-link{ padding:0.75rem 1rem; }
      .dropdown-item{ padding:0.75rem 1rem; }
    }
  </style>
</head>
<body<?= $brand['dark_mode_enabled'] ? ' class="dark-mode"' : '' ?>>
<div class="d-flex">
  <!-- Sidebar -->
  <aside id="sb" class="sidebar d-flex flex-column p-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <?php if (!empty($brand['logo_path']) && file_exists(__DIR__ . '/' . $brand['logo_path'])): ?>
        <img src="<?= h($brand['logo_path']) ?>" alt="<?= h($brand['system_name']) ?>" style="width: 40px; height: 40px; object-fit: contain; border-radius: 8px;">
      <?php endif; ?>
      <span class="brand ms-1"><?= h($brand['system_name']) ?></span>
    </div>
    <div class="collapse-btn" id="sbToggle" title="Collapse/Expand"><i class="bi bi-chevron-left"></i></div>
    <?php require_once __DIR__ . '/includes/nav_search.php'; ?>
    <?= nav_search_box() ?>
    <div class="nav-sect mt-3">Main</div>
    <a href="/index"       class="slink"><span class="sicon"><i class="bi bi-house"></i></span><span class="slabel">Home</span></a>
    <?php if (has_department_access(MODULE_CLEANING, DEPT_CLEANING_OPERATIONS, $conn)): ?>
    <a href="/operation"   class="slink"><span class="sicon"><i class="bi bi-gear-wide-connected"></i></span><span class="slabel">Operation</span></a>
    <?php endif; ?>
    <?php if (has_department_access(MODULE_CLEANING, DEPT_CLEANING_ACCOUNTS, $conn)): ?>
    <a href="/account"     class="slink active"><span class="sicon"><i class="bi bi-wallet2"></i></span><span class="slabel">Accounts</span></a>
    <?php endif; ?>

    <?php /* Mobile App section hidden - under development
    <?php 
    // Only show Mobile App section if user has Owner/Admin role
    require_once __DIR__ . '/includes/auth.php';
    if (has_role('Owner', $conn) || has_role('Admin', $conn)): 
    ?>
    <div class="nav-sect">Mobile App</div>
    <a href="/mobile_app_admin" class="slink"><span class="sicon"><i class="bi bi-phone"></i></span><span class="slabel">App Admin</span></a>
    <a href="/operation/online_bookings" class="slink"><span class="sicon"><i class="bi bi-calendar-check"></i></span><span class="slabel">Online Bookings</span></a>
    <?php endif; ?>
    */ ?>

    <div class="nav-sect">Other</div>
    <?php if (has_role('Owner', $conn) || has_role('Admin', $conn)): ?>
    <a href="/settings" class="slink"><span class="sicon"><i class="bi bi-sliders"></i></span><span class="slabel">Settings</span></a>
    <a href="/settings?tab=companies" class="slink"><span class="sicon"><i class="bi bi-building-add"></i></span><span class="slabel">Companies Setup</span></a>
    <?php endif; ?>
    <a href="/about"    class="slink"><span class="sicon"><i class="bi bi-info-circle"></i></span><span class="slabel">About</span></a>
  </aside>

  <!-- Main -->
  <div class="flex-grow-1">
    <!-- Top bar -->
    <nav class="navbar navbar-expand navbar-light bg-white shadow-sm">
      <div class="container-fluid">
        <span class="navbar-brand fw-bold" style="color:var(--primary)"><?= h($brand['system_name']) ?></span>
        <div class="dropdown ms-auto">
          <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <div class="avatar me-2"><?= h($avatarInitial) ?></div>
            <span class="me-2"><?= h($fullName) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><span class="dropdown-item-text"><strong><?= h($userName) ?></strong></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="/profile"><i class="bi bi-person-circle me-2"></i>Edit/Update</a></li>
            <li><a class="dropdown-item text-danger" href="/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </nav>

    <div class="container-fluid my-4">
      <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center mb-3">
        <div class="page-header-label flex-grow-1 mb-2 mb-md-0"><?= h($header) ?></div>
        
        <!-- Mobile dropdown navigation -->
        <div class="dropdown d-md-none w-100">
          <button class="btn btn-outline-primary w-100" type="button" data-bs-toggle="dropdown">
            <i class="bi bi-list"></i> Navigation
          </button>
          <ul class="dropdown-menu w-100">
            <li><a class="dropdown-item <?= $tab==='dashboard'?'active':'' ?>" href="account" data-tab="dashboard">AR Dashboard</a></li>
            <li><a class="dropdown-item" href="accounts/expenses">Expenses</a></li>
            <li><a class="dropdown-item <?= $tab==='invoices'?'active':'' ?>" href="account" data-tab="invoices">Invoices</a></li>
            <li><a class="dropdown-item <?= $tab==='uninvoiced_orders'?'active':'' ?>" href="account" data-tab="uninvoiced_orders">Uninvoiced Orders</a></li>
            <li><a class="dropdown-item <?= $tab==='batch_invoices'?'active':'' ?>" href="account" data-tab="batch_invoices">Batch Invoices</a></li>
            <li><a class="dropdown-item <?= $tab==='billing_queue'?'active':'' ?>" href="account" data-tab="billing_queue">Billing Queue</a></li>
            <li><a class="dropdown-item" href="accounts/adjustment_requests.php">Adjustment Requests</a></li>
            <li><a class="dropdown-item" href="accounts/journal_entries.php">Journal Entries</a></li>
            <li><a class="dropdown-item" href="accounts/prepaid_schedules.php">Prepaid Schedules</a></li>
            <li><a class="dropdown-item" href="accounts/recurring_journals.php">Recurring Journals</a></li>
            <li><a class="dropdown-item <?= $tab==='payments'?'active':'' ?>" href="account" data-tab="payments">Payments</a></li>
            <li><a class="dropdown-item <?= $tab==='statements'?'active':'' ?>" href="account" data-tab="statements">Statements</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="accounts/gm_dashboard.php">GM Dashboard</a></li>
            <li><a class="dropdown-item" href="accounts/reports">Reports</a></li>
            <li><a class="dropdown-item" href="accounts/scheduled_reports">Scheduled Reports</a></li>
            <li><a class="dropdown-item" href="accounts/email_templates">Email Templates</a></li>
            <li><a class="dropdown-item" href="accounts/email_queue">Email Queue</a></li>
            <li><a class="dropdown-item <?= $tab==='coa'?'active':'' ?>" href="account" data-tab="coa">Chart of Accounts</a></li>
          </ul>
        </div>
        
        <!-- Desktop navigation pills -->
        <ul class="nav nav-pills d-none d-md-flex flex-wrap">
          <li class="nav-item"><a class="nav-link <?= $tab==='dashboard'?'active':'' ?>" href="account" data-tab="dashboard">AR Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="accounts/expenses">Expenses</a></li>
          <li class="nav-item"><a class="nav-link <?= $tab==='invoices'?'active':'' ?>" href="account" data-tab="invoices">Invoices</a></li>
          <li class="nav-item"><a class="nav-link <?= $tab==='uninvoiced_orders'?'active':'' ?>" href="account" data-tab="uninvoiced_orders">Uninvoiced Orders</a></li>
          <li class="nav-item"><a class="nav-link <?= $tab==='batch_invoices'?'active':'' ?>" href="account" data-tab="batch_invoices">Batch Invoices</a></li>
          <li class="nav-item"><a class="nav-link <?= $tab==='billing_queue'?'active':'' ?>" href="account" data-tab="billing_queue">Billing Queue</a></li>
          <li class="nav-item"><a class="nav-link" href="accounts/adjustment_requests.php">Adjustments</a></li>
          <li class="nav-item"><a class="nav-link" href="accounts/journal_entries.php">Journals</a></li>
          <li class="nav-item"><a class="nav-link" href="accounts/prepaid_schedules.php">Prepaid</a></li>
          <li class="nav-item"><a class="nav-link <?= $tab==='payments'?'active':'' ?>" href="account" data-tab="payments">Payments</a></li>
          <li class="nav-item"><a class="nav-link <?= $tab==='statements'?'active':'' ?>" href="account" data-tab="statements">Statements</a></li>
          <li class="nav-item"><a class="nav-link" href="accounts/gm_dashboard.php">GM Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="accounts/reports">Reports</a></li>
          <li class="nav-item"><a class="nav-link" href="accounts/scheduled_reports">Scheduled Reports</a></li>
          <li class="nav-item"><a class="nav-link" href="accounts/email_templates">Email Templates</a></li>
          <li class="nav-item"><a class="nav-link" href="accounts/email_queue">Email Queue</a></li>
          <li class="nav-item"><a class="nav-link <?= $tab==='coa'?'active':'' ?>" href="account" data-tab="coa">Chart of Accounts</a></li>
        </ul>
      </div>

      <div class="card p-3 shadow-sm rounded-4">
        <?php
        switch ($tab){
          case 'invoices':        include __DIR__.'/accounts/invoices.php'; break;
          case 'uninvoiced_orders': include __DIR__.'/accounts/uninvoiced_orders.php'; break;
          case 'batch_invoices':  include __DIR__.'/accounts/batch_invoices.php'; break;
          case 'billing_queue':   include __DIR__.'/accounts/billing_queue.php'; break;
          case 'payments':        include __DIR__.'/accounts/payments.php'; break;
          case 'statements':      include __DIR__.'/accounts/statements.php'; break;
          case 'coa':             include __DIR__.'/accounts/coa.php'; break;

          default:
            // ======= DASHBOARD =======
            $open_count      = (int)($summary['open_count']      ?? 0);
            $ar_total        = (float)($summary['ar_total']      ?? 0);
            $overdue_total   = (float)($summary['overdue_total'] ?? 0);
            $paid_this_month = (float)($summary['paid_this_month'] ?? 0);
        ?>
        <!-- Quick Actions Panel -->
        <div class="row g-3 mb-4">
          <div class="col-12">
            <div class="card shadow-sm">
              <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h6>
              </div>
              <div class="card-body">
                <div class="row g-2">
                  <div class="col-md-3">
                    <a href="accounts/invoice_create" class="btn btn-primary w-100 d-flex align-items-center justify-content-center">
                      <i class="bi bi-plus-circle me-2"></i>Create Invoice
                    </a>
                  </div>
                  <div class="col-md-3">
                    <button class="btn btn-success w-100 d-flex align-items-center justify-content-center" data-bs-toggle="modal" data-bs-target="#quickPaymentModal">
                      <i class="bi bi-credit-card me-2"></i>Record Payment
                    </button>
                  </div>
                  <div class="col-md-3">
                    <a href="account" class="btn btn-warning w-100 d-flex align-items-center justify-content-center" data-tab="invoices" data-filter='{"status":"issued","overdue":1}'>
                      <i class="bi bi-exclamation-triangle me-2"></i>View Overdue
                    </a>
                  </div>
                  <div class="col-md-3">
                    <a href="account" class="btn btn-info w-100 d-flex align-items-center justify-content-center" data-tab="statements">
                      <i class="bi bi-file-text me-2"></i>Generate Statement
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-6 col-sm-4 col-md-3 col-lg-2">
            <div class="kpi">
              <div class="d-flex justify-content-between align-items-center">
                <div class="label">Open Invoices</div>
                <i class="bi bi-receipt text-muted"></i>
              </div>
              <div class="main"><?= money_fmt($open_count) ?></div>
              <div class="hint">Amount: AED <?= money_fmt($ar_total) ?></div>
            </div>
          </div>
          <div class="col-6 col-sm-4 col-md-3 col-lg-2">
            <div class="kpi">
              <div class="d-flex justify-content-between align-items-center">
                <div class="label">AR Total</div>
                <i class="bi bi-cash-stack text-muted"></i>
              </div>
              <div class="main">AED <?= money_fmt($ar_total) ?></div>
              <div class="hint">as of <?= h($todayIso) ?></div>
            </div>
          </div>
          <div class="col-6 col-sm-4 col-md-3 col-lg-2">
            <div class="kpi">
              <div class="d-flex justify-content-between align-items-center">
                <div class="label">Overdue</div>
                <i class="bi bi-exclamation-triangle text-danger"></i>
              </div>
              <div class="main text-danger">AED <?= money_fmt($overdue_total) ?></div>
              <div class="hint"><a href="#overdue-list">View overdue list</a></div>
            </div>
          </div>
          <div class="col-6 col-sm-4 col-md-3 col-lg-2">
            <div class="kpi">
              <div class="d-flex justify-content-between align-items-center">
                <div class="label">Paid This Month</div>
                <i class="bi bi-check2-circle text-muted"></i>
              </div>
              <div class="main">AED <?= money_fmt($paid_this_month) ?></div>
              <div class="hint"><?= date('M Y') ?></div>
            </div>
          </div>
          <div class="col-6 col-sm-4 col-md-3 col-lg-2">
            <div class="kpi">
              <div class="d-flex justify-content-between align-items-center">
                <div class="label">Pending Emails</div>
                <i class="bi bi-envelope text-warning"></i>
              </div>
              <div class="main" id="pendingEmailsCount">-</div>
              <div class="hint"><a href="accounts/email_queue.php">View Queue</a></div>
            </div>
          </div>
        </div>

        <?php if ($warn_total_mismatch || $warn_overdue_mismatch || $warn_age_mismatch): ?>
          <div class="alert alert-warning mt-3">
            <strong>Data check:</strong>
            <?php if ($warn_total_mismatch): ?>
              AR total from <code>v_ar_summary</code> doesn’t match recomputed sum from <code>v_ar_invoices_open</code>
              (Summary: AED <?= money_fmt($ar_total) ?> vs Computed: AED <?= money_fmt($computed_ar_total) ?>).
            <?php endif; ?>
            <?php if ($warn_overdue_mismatch): ?>
              Overdue total mismatch
              (Summary: AED <?= money_fmt($overdue_total) ?> vs Computed: AED <?= money_fmt($computed_overdue) ?>).
            <?php endif; ?>
            <?php if ($warn_age_mismatch): ?>
              Ageing buckets differ between <code>v_ar_ageing</code> and bucketing of <code>v_ar_invoices_open</code>.
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="mt-4">
          <div class="fw-semibold mb-2">Ageing (days)</div>
          <table class="table table-sm align-middle">
            <thead><tr><th>Current</th><th>1–30</th><th>31–60</th><th>61–90</th><th>90+</th><th class="text-end">Total</th></tr></thead>
            <tbody><tr>
              <td>AED <?= money_fmt($b0) ?></td>
              <td>AED <?= money_fmt($b30) ?></td>
              <td>AED <?= money_fmt($b60) ?></td>
              <td>AED <?= money_fmt($b90) ?></td>
              <td>AED <?= money_fmt($b120) ?></td>
              <td class="text-end fw-semibold">AED <?= money_fmt($b0+$b30+$b60+$b90+$b120) ?></td>
            </tr></tbody>
          </table>
          <div class="hint">Currency: AED • as of <?= h($todayIso) ?></div>
        </div>

        <div class="row g-3 mt-4">
          <div class="col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header d-flex justify-content-between align-items-center">
                <div class="fw-semibold" id="overdue-list">Top Overdue</div>
                <a class="btn btn-sm btn-outline-secondary" href="account" data-tab="invoices">View all</a>
              </div>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead class="table-light"><tr>
                    <th>Inv #</th><th>Due</th><th>Days</th><th class="text-end">Balance</th>
                  </tr></thead>
                  <tbody>
                  <?php if (!$topOverdue): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No overdue invoices</td></tr>
                  <?php else: foreach ($topOverdue as $r): ?>
                    <tr>
                      <td><?= h($r['invoice_no'] ?? '') ?></td>
                      <td><?= h($r['due_date'] ?? '') ?></td>
                      <td><?= (int)($r['days_overdue'] ?? 0) ?></td>
                      <td class="text-end">AED <?= money_fmt($r['balance_due'] ?? 0) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header d-flex justify-content-between align-items-center">
                <div class="fw-semibold">Unbilled (WIP) by Client</div>
                <a class="btn btn-sm btn-outline-secondary" href="account" data-tab="billing_queue">Go to Billing Queue</a>
              </div>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead class="table-light"><tr>
                    <th>Client ID</th><th>Orders</th><th>Hours</th><th class="text-end">Unbilled Total</th>
                  </tr></thead>
                  <tbody>
                  <?php if (!$topUnbilled): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No unbilled items</td></tr>
                  <?php else: foreach ($topUnbilled as $u): ?>
                    <tr>
                      <td><?= h($u['client_name'] ?? ('#'.($u['client_id'] ?? ''))) ?></td>
                      <td><?= (int)($u['orders_cnt'] ?? 0) ?></td>
                      <td><?= number_format((float)($u['hours'] ?? 0), 2) ?></td>
                      <td class="text-end">AED <?= money_fmt($u['grand_total'] ?? 0) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts Section -->
        <div class="row g-3 mt-4">
          <div class="col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>AR Aging Distribution</h6>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-secondary" onclick="exportData('csv', 'dashboard')" title="Export CSV">
                    <i class="bi bi-file-csv"></i>
                  </button>
                  <button class="btn btn-outline-secondary" onclick="exportData('excel', 'dashboard')" title="Export Excel">
                    <i class="bi bi-file-excel"></i>
                  </button>
                  <button class="btn btn-outline-secondary" onclick="exportData('pdf', 'dashboard')" title="Export PDF">
                    <i class="bi bi-file-pdf"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <canvas id="agingChart" height="300"></canvas>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Top Clients by Outstanding Balance</h6>
              </div>
              <div class="card-body">
                <canvas id="topClientsChart" height="300"></canvas>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mt-3">
          <div class="col-12">
            <div class="card shadow-sm">
              <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Payment Trends (Last 6 Months)</h6>
              </div>
              <div class="card-body">
                <canvas id="paymentTrendsChart" height="200"></canvas>
              </div>
            </div>
          </div>
        </div>

        <div class="card shadow-sm mt-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div class="fw-semibold">Credit at Risk</div>
            <a class="btn btn-sm btn-outline-secondary" href="account" data-tab="statements">Client Statements</a>
          </div>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead class="table-light"><tr>
                <th>Client</th><th>Terms</th><th class="text-end">Credit Limit</th><th class="text-end">AR Total</th><th class="text-end">Available</th>
              </tr></thead>
              <tbody>
              <?php if (!$atRisk): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No clients at risk</td></tr>
              <?php else: foreach ($atRisk as $c): ?>
                <tr>
                  <td><?= h($c['client_name'] ?? ('#'.($c['client_id'] ?? ''))) ?></td>
                  <td><?= h($c['terms'] ?? '') ?></td>
                  <td class="text-end">AED <?= money_fmt($c['credit_limit'] ?? 0) ?></td>
                  <td class="text-end">AED <?= money_fmt($c['ar_total'] ?? 0) ?></td>
                  <td class="text-end <?= (float)($c['available_credit'] ?? 0) <= 0 ? 'text-danger fw-semibold' : '' ?>">
                    AED <?= money_fmt($c['available_credit'] ?? 0) ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Quick Payment Modal -->
        <div class="modal fade" id="quickPaymentModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
            <form class="modal-content" id="quickPaymentForm">
              <div class="modal-header">
                <h5 class="modal-title">Quick Payment Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3">
                  <label class="form-label">Client</label>
                  <select class="form-select" name="client_id" required>
                    <option value="">Select Client</option>
                    <?php
                    $clients = $conn->query("SELECT id, client_name FROM client ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($clients as $c): ?>
                      <option value="<?= $c['id'] ?>"><?= h($c['client_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Amount (AED)</label>
                  <input type="number" step="0.01" class="form-control" name="amount" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Payment Method</label>
                  <select class="form-select" name="payment_method" id="quickPayMethod" required>
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="bank">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Deposit account <span class="text-danger">*</span></label>
                  <select class="form-select" name="deposit_account_no" id="quickDepositAccount" required>
                    <option value="">— Select —</option>
                    <?php foreach ($cleaningPaymentAccountOptions as $co): ?>
                      <option value="<?= h($co['account_no']) ?>"><?= h($co['account_no'].' – '.$co['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Date</label>
                  <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Notes</label>
                  <textarea class="form-control" name="notes" rows="2"></textarea>
                </div>
                <div class="text-danger small" id="quickPaymentErr" style="display:none;"></div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Record Payment</button>
              </div>
            </form>
          </div>
        </div>

        <?php
        } // end dashboard
        ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="includes/keyboard_shortcuts.js"></script>
<script>
  // Sidebar collapse
  const sb = document.getElementById('sb');
  const t  = document.getElementById('sbToggle');
  t.addEventListener('click', () => {
    sb.classList.toggle('collapsed');
    t.querySelector('i').classList.toggle('bi-chevron-right');
    t.querySelector('i').classList.toggle('bi-chevron-left');
  });

  // Clean URL navigation for tabs
  document.querySelectorAll('a[data-tab]').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const tab = this.getAttribute('data-tab');
      const filterData = this.getAttribute('data-filter');
      
      // Use current pathname and replace the last segment with 'account'
      let actionPath = window.location.pathname.replace(/\.php$/, '');
      // If we're already on account page, keep it; otherwise navigate to account
      if (!actionPath.endsWith('/account') && !actionPath.endsWith('account')) {
        const parts = actionPath.split('/').filter(p => p);
        if (parts.length > 0) {
          parts[parts.length - 1] = 'account';
          actionPath = '/' + parts.join('/');
        } else {
          actionPath = '/account';
        }
      }
      const targetUrl = new URL(actionPath, window.location.origin);
      targetUrl.searchParams.set('tab', tab);
      
      // Store filter data in session if provided
      if (filterData) {
        try {
          const filters = JSON.parse(filterData);
          fetch('accounts/ajax/store_filters.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({page: 'account', tab: tab, filters: filters})
          });
        } catch(e) {
          console.error('Error storing filters:', e);
        }
      }
      
      window.location.href = targetUrl.toString();
    });
  });

  // Update URL to clean format without parameters and .php extension (run immediately)
  (function() {
    if (window.history && window.history.replaceState) {
      let cleanUrl = window.location.pathname.replace(/\.php$/, '');
      // Remove trailing slash if present (except for root)
      if (cleanUrl.endsWith('/') && cleanUrl !== '/') {
        cleanUrl = cleanUrl.slice(0, -1);
      }
      // Always update if URL contains .php or has query parameters
      if (window.location.search || window.location.pathname.endsWith('.php') || window.location.pathname !== cleanUrl) {
        window.history.replaceState({}, document.title, cleanUrl);
      }
    }
  })();

  window.cleaningPaymentAccountOptionsQuick = <?= json_encode($cleaningPaymentAccountOptions, JSON_UNESCAPED_UNICODE) ?>;
  function quickDefaultDeposit(m) {
    return String(m || '').toLowerCase() === 'cash' ? '1010' : '1020';
  }
  document.getElementById('quickPayMethod')?.addEventListener('change', function() {
    const sel = document.getElementById('quickDepositAccount');
    if (!sel) return;
    const d = quickDefaultDeposit(this.value);
    if ([...sel.options].some(o => o.value === d)) sel.value = d;
  });
  document.getElementById('quickPaymentModal')?.addEventListener('shown.bs.modal', function() {
    const pm = document.getElementById('quickPayMethod');
    const ds = document.getElementById('quickDepositAccount');
    if (pm && ds && !ds.value) {
      const d = quickDefaultDeposit(pm.value);
      if ([...ds.options].some(o => o.value === d)) ds.value = d;
    }
  });

  // Quick Payment Modal
  document.getElementById('quickPaymentForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    document.getElementById('quickPaymentErr').style.display = 'none';
    
    // Debug: Log form data (can be removed in production)
    // console.log('Form data being sent:');
    // for (let [key, value] of fd.entries()) {
    //   console.log(key + ': ' + value);
    // }

    fetch('accounts/ajax_quick_payment.php?v=' + Date.now(), { method: 'POST', body: fd })
      .then(r => r.json())
      .then(j => {
        if (j.success) {
          // Show success message
          if (j.applied_invoices && j.applied_invoices.length > 0) {
            alert('Payment recorded successfully!\n\nApplied to invoices:\n' + 
                  j.applied_invoices.map(inv => `${inv.invoice_no}: AED ${inv.amount_applied}`).join('\n'));
          } else {
            alert('Payment recorded successfully!\n\n' + (j.message || 'Payment recorded as credit.'));
          }
          
          // Close modal and refresh page
          bootstrap.Modal.getInstance(document.getElementById('quickPaymentModal')).hide();
          location.reload();
        } else {
          document.getElementById('quickPaymentErr').textContent = j.error || 'Error recording payment';
          document.getElementById('quickPaymentErr').style.display = '';
        }
      })
      .catch(() => {
        document.getElementById('quickPaymentErr').textContent = 'Server error';
        document.getElementById('quickPaymentErr').style.display = '';
      });
  });

  // Initialize Charts
  document.addEventListener('DOMContentLoaded', function() {
    // Check if Chart.js is loaded
    if (typeof Chart === 'undefined') {
      console.error('Chart.js is not loaded');
      return;
    }
    
    // AR Aging Chart
    const agingCtx = document.getElementById('agingChart');
    if (agingCtx) {
      const agingData = [<?= $b0 ?>, <?= $b30 ?>, <?= $b60 ?>, <?= $b90 ?>, <?= $b120 ?>];
      const hasData = agingData.some(value => value > 0);
      
      if (hasData) {
        try {
          new Chart(agingCtx, {
            type: 'doughnut',
            data: {
              labels: ['Current', '1-30 days', '31-60 days', '61-90 days', '90+ days'],
              datasets: [{
                data: agingData,
                backgroundColor: [
                  '#28a745',
                  '#ffc107', 
                  '#fd7e14',
                  '#dc3545',
                  '#6f42c1'
                ],
                borderWidth: 2,
                borderColor: '#fff'
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: {
                  position: 'bottom'
                },
                tooltip: {
                  callbacks: {
                    label: function(context) {
                      return context.label + ': AED ' + context.parsed.toLocaleString();
                    }
                  }
                }
              }
            }
          });
        } catch (error) {
          console.error('Error creating aging chart:', error);
          agingCtx.parentElement.innerHTML = '<p class="text-muted text-center">Error loading aging chart</p>';
        }
      } else {
        agingCtx.parentElement.innerHTML = '<p class="text-muted text-center">No aging data available</p>';
      }
    }

    // Top Clients Chart
    const topClientsCtx = document.getElementById('topClientsChart');
    if (topClientsCtx) {
      const topClients = <?= json_encode(array_slice($topClientsBalance, 0, 5)) ?>;
      
      if (topClients && topClients.length > 0) {
        try {
          new Chart(topClientsCtx, {
            type: 'bar',
            data: {
              labels: topClients.map(c => c.client_name),
              datasets: [{
                label: 'Outstanding Balance (AED)',
                data: topClients.map(c => parseFloat(c.total_balance)),
                backgroundColor: '<?= $brand['primary_color'] ?>',
                borderColor: '<?= $brand['primary_dark'] ?>',
                borderWidth: 1
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              scales: {
                y: {
                  beginAtZero: true,
                  ticks: {
                    callback: function(value) {
                      return 'AED ' + value.toLocaleString();
                    }
                  }
                }
              },
              plugins: {
                tooltip: {
                  callbacks: {
                    label: function(context) {
                      return 'Balance: AED ' + context.parsed.y.toLocaleString();
                    }
                  }
                }
              }
            }
          });
        } catch (error) {
          console.error('Error creating top clients chart:', error);
          topClientsCtx.parentElement.innerHTML = '<p class="text-muted text-center">Error loading top clients chart</p>';
        }
      } else {
        topClientsCtx.parentElement.innerHTML = '<p class="text-muted text-center">No data available for top clients chart</p>';
      }
    }

    // Payment Trends Chart
    const paymentTrendsCtx = document.getElementById('paymentTrendsChart');
    if (paymentTrendsCtx) {
      // Get payment data for last 6 months
      fetch('accounts/ajax_payment_trends.php')
        .then(response => {
          if (!response.ok) {
            throw new Error('Network response was not ok');
          }
          return response.json();
        })
        .then(data => {
          if (data.success && data.months && data.amounts) {
            try {
              new Chart(paymentTrendsCtx, {
                type: 'line',
                data: {
                  labels: data.months,
                  datasets: [{
                    label: 'Payments Received (AED)',
                    data: data.amounts,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                  }]
                },
                options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  scales: {
                    y: {
                      beginAtZero: true,
                      ticks: {
                        callback: function(value) {
                          return 'AED ' + value.toLocaleString();
                        }
                      }
                    }
                  },
                  plugins: {
                    tooltip: {
                      callbacks: {
                        label: function(context) {
                          return 'Payments: AED ' + context.parsed.y.toLocaleString();
                        }
                      }
                    }
                  }
                }
              });
            } catch (error) {
              console.error('Error creating payment trends chart:', error);
              paymentTrendsCtx.parentElement.innerHTML = '<p class="text-muted text-center">Error loading payment trends chart</p>';
            }
          } else {
            paymentTrendsCtx.parentElement.innerHTML = '<p class="text-muted text-center">No payment trends data available</p>';
          }
        })
        .catch(error => {
          console.error('Error loading payment trends:', error);
          paymentTrendsCtx.parentElement.innerHTML = '<p class="text-muted text-center">Unable to load payment trends data</p>';
        });
    }
  });

  // Export functionality
  function exportData(format, dataType) {
    const url = `accounts/ajax/export_data.php?type=${dataType}&format=${format}`;
    window.open(url, '_blank');
  }

  // Load pending emails count
  function loadPendingEmailsCount() {
    const countElement = document.getElementById('pendingEmailsCount');
    if (!countElement) return; // Element not found (not on dashboard)
    
    fetch('accounts/ajax/get_email_queue_stats.php')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          const pendingCount = data.stats.pending_queues + data.stats.processing_queues;
          countElement.textContent = pendingCount;
          
          // Update the icon color based on status
          const icon = document.querySelector('#pendingEmailsCount')?.parentElement?.querySelector('i');
          if (icon) {
            if (pendingCount > 0) {
              icon.className = 'bi bi-envelope text-warning';
            } else {
              icon.className = 'bi bi-envelope text-muted';
            }
          }
        }
      })
      .catch(error => {
        console.error('Error loading email queue stats:', error);
        if (countElement) {
          countElement.textContent = '?';
        }
      });
  }

  // Load pending emails count on page load
  loadPendingEmailsCount();

  // Invoice email functions (for use in invoices.php and invoice_view.php)
  // Version: 2.0 - Fixed URL path
  function sendInvoiceEmail(invoiceId) {
    if (confirm('Send email for this invoice?')) {
      const formData = new FormData();
      formData.append('action', 'email');
      formData.append('invoice_ids', JSON.stringify([invoiceId]));
      
      fetch('accounts/ajax/bulk_invoice_actions.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        return response.json();
      })
      .then(data => {
        if (data.success) {
          alert(data.message || `Email queue created and processed. ${data.processed} emails sent, ${data.failed} failed.`);
        } else {
          alert('Error: ' + (data.error || 'Failed to send emails'));
        }
      })
      .catch(error => {
        console.error('Error details:', error);
        alert('Error: ' + error.message);
      });
    }
  }
</script>
</body>
</html>
