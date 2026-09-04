<?php
/**
 * BMSystem Dashboard — Modern, Data-Driven Homepage
 * Redesigned with enhanced KPIs, charts, quick actions, and responsive layout
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/includes/url_helper.php';

// Get branding settings
$brand = getBrandSettings($conn);

/* ---------- LOGIN GUARD ---------- */
$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$hasLegacy     = !empty($_SESSION['username']) || !empty($_SESSION['fullname']);
if (!$hasUserObject && !$hasLegacy) {
  // Store next URL in session for clean URLs
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $_SESSION['login_next'] = preg_replace('/\.php$/', '', $_SERVER['REQUEST_URI'] ?? '/');
  header('Location: login');
  exit;
}

/* ---------- MODULE SELECTION CHECK ---------- */
require_once __DIR__ . '/includes/company_helper.php';
require_once __DIR__ . '/includes/module_access.php';

// Check if user has already selected a company/module
$currentCompanyId = current_company_id($conn);

if (!empty($_SESSION['needs_module_selection']) && !$currentCompanyId) {
    header('Location: select-module');
    exit;
}

// Main index is the cleaning/BM dashboard — barber-only (etc.) users should not land here
$uidHome = current_user_id();
if ($uidHome) {
    $homeRoute = get_non_cleaning_home_redirect_route($conn, (int)$uidHome);
    if ($homeRoute !== null) {
        header('Location: ' . redirect_url($homeRoute));
        exit;
    }
}

// If user has selected a company, allow access to dashboard
// Only redirect to selector if they haven't selected anything yet
if (!$currentCompanyId) {
    $userId = current_user_id();
    if ($userId) {
        $userCompanies = get_user_companies($conn, $userId);
        $userModules = get_user_modules($conn, $userId);
        $roles = current_user_roles($conn);
        $isOwnerAdmin = in_array('Owner', $roles, true) || in_array('Admin', $roles, true);
        
        // Owner/Admin with multiple companies: show selector to choose company/module
        if ($isOwnerAdmin && (count($userCompanies) > 1 || count($userModules) > 1)) {
            $_SESSION['needs_module_selection'] = true;
            header('Location: select-module');
            exit;
        }
        // Single company, single module: auto-select and allow access
        elseif (count($userCompanies) === 1 && count($userModules) === 1) {
            $company = $userCompanies[0];
            $module = $userModules[0];
            if (empty($_SESSION['current_company_id']) || $_SESSION['current_company_id'] != $company['id']) {
                set_current_company($company['id']);
            }
            // For cleaning module, index.php IS the dashboard, so don't redirect
            // For other modules, redirect to their dashboard
            if ($module['module'] !== 'cleaning') {
                $route = get_module_route($module['module']);
                if ($route !== '/index' && $route !== '/') {
                    header('Location: ' . $route);
                    exit;
                }
            }
        }
        // Multiple companies or modules: redirect to selector
        elseif (count($userCompanies) > 1 || count($userModules) > 1) {
            $_SESSION['needs_module_selection'] = true;
            header('Location: select-module');
            exit;
        }
    }
}

/* ---------- CURRENT USER ---------- */
$U = $hasUserObject ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username']  ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));
$userRole = $U['role'] ?? 'User';

// Fetch user avatar if not in session
$userAvatar = $U['avatar_path'] ?? null;
if (!$userAvatar && !empty($U['id'])) {
  $stmt = $conn->prepare("SELECT avatar_path FROM user WHERE id = ?");
  $stmt->execute([$U['id']]);
  $userAvatar = $stmt->fetchColumn();
}

/* ---------- HELPER FUNCTION ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- DASHBOARD DATA ---------- */
$activeEmployees = (int)$conn->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
$in30 = (int)$conn->query("
  SELECT COUNT(*) FROM employee_documents
  WHERE expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetchColumn();
$in60 = (int)$conn->query("
  SELECT COUNT(*) FROM employee_documents
  WHERE expires_at BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
")->fetchColumn();
$pendingLeave = (int)$conn->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
$pendingOT    = (int)$conn->query("SELECT COUNT(*) FROM overtime_entries WHERE status='pending'")->fetchColumn();
$openPayroll  = (int)$conn->query("SELECT COUNT(*) FROM payroll_runs WHERE status IN ('open','posted')")->fetchColumn();
$todaysWO     = (int)$conn->query("SELECT COUNT(*) FROM make_order WHERE DATE(date) = CURDATE()")->fetchColumn();

$cashIssued = (float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM cash_advances WHERE status<>'void'")->fetchColumn();
$cashApplied = (float)$conn->query("
  SELECT COALESCE(SUM(pi.adv_applied),0)
  FROM payroll_items pi JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
  WHERE pr.status IN ('open','posted','finalized','paid')
")->fetchColumn();
$cashOutstanding = max(0, $cashIssued - $cashApplied);

$dedIssued = (float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM employee_deductions")->fetchColumn();
$dedApplied = (float)$conn->query("
  SELECT COALESCE(SUM(pi.other_applied),0)
  FROM payroll_items pi JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
  WHERE pr.status IN ('open','posted','finalized','paid')
")->fetchColumn();
$dedOutstanding = max(0, $dedIssued - $dedApplied);

// Financial metrics — canonical ServiceAccountingService (allocation-based, excludes BINV)
require_once __DIR__ . '/includes/service_management_settings.php';
require_once __DIR__ . '/includes/service_accounting_service.php';

$currentMonth = date('Y-m-01');
$today = date('Y-m-d');

$smAccounting = new ServiceAccountingService($conn);
$smDash = $smAccounting->getDashboardSummary(30);
$revenueThisMonth = (float)($smDash['revenueThisMonth'] ?? 0);
$expensesThisMonth = $smAccounting->getExpenses($currentMonth, $today);
$activeClients = (int)($smDash['activeClients'] ?? 0);
$openWorkOrders = (int)($smDash['openWorkOrders'] ?? 0);
$pendingInvoices = (int)($smDash['pendingInvoices'] ?? 0);
$overduePayments = (float)($smDash['overduePayments'] ?? 0);
$collectionsThisMonth = (float)($smDash['collectionsThisMonth'] ?? 0);

$canViewGmDashboard = has_role('Owner', $conn) || has_role('Admin', $conn);
$revenueGlThisMonth = $canViewGmDashboard ? $smAccounting->getRevenueFromGL($currentMonth, $today) : null;
$expensesGlThisMonth = $canViewGmDashboard ? $smAccounting->getExpensesFromGL($currentMonth, $today) : null;
$netProfitThisMonth = $canViewGmDashboard ? $smAccounting->getProfit($currentMonth, $today) : null;
$outstandingAr = $canViewGmDashboard ? $smAccounting->getARTotal() : null;

// Expiring documents
$expDocs = $conn->query("
  SELECT ed.id, ed.doc_type, ed.expires_at, e.full_name, e.employee_code
  FROM employee_documents ed
  JOIN employees e ON e.id = ed.employee_id
  WHERE ed.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
  ORDER BY ed.expires_at ASC
  LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Today's work orders
$todayWOs = $conn->query("
  SELECT id, client_name AS customer, total, date
  FROM make_order
  WHERE DATE(date)=CURDATE()
  ORDER BY date DESC
  LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Approvals queue
$approvals = [
  'leave' => $conn->query("
    SELECT lr.id, lt.name AS type_name, lr.date_from, lr.date_to, e.full_name
    FROM leave_requests lr
    JOIN leave_types lt ON lt.id = lr.leave_type_id
    JOIN employees e   ON e.id = lr.employee_id
    WHERE lr.status='pending'
    ORDER BY lr.created_at DESC
    LIMIT 10
  ")->fetchAll(PDO::FETCH_ASSOC),
  'ot' => $conn->query("
    SELECT ot.id, ot.ot_date, ot.pay_hours, e.full_name
    FROM overtime_entries ot
    JOIN employees e ON e.id = ot.employee_id
    WHERE ot.status='pending'
    ORDER BY ot.ot_date DESC, ot.id DESC
    LIMIT 10
  ")->fetchAll(PDO::FETCH_ASSOC),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title><?= h($brand['system_name']) ?> | Dashboard</title>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --primary: <?= $brand['primary_color'] ?>;
      --primary-light: <?= $brand['primary_light'] ?>;
      --primary-dark: <?= $brand['primary_dark'] ?>;
      --accent: <?= $brand['accent_color'] ?>;
      --success: #28a745;
      --warning: #ffc107;
      --danger: #dc3545;
      --info: #17a2b8;
      --light: #f8f9fa;
      --dark: #212529;
      --shadow-sm: 0 2px 4px rgba(0,0,0,0.08);
      --shadow: 0 4px 12px rgba(0,0,0,0.1);
      --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
    }
    
    body { 
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      min-height: 100vh;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    /* Sidebar */
    .sidebar {
      min-height: 100vh;
      width: 240px;
      background: linear-gradient(180deg, var(--primary), var(--primary-light));
      color: #fff;
      position: sticky;
      top: 0;
      z-index: 1030;
      transition: width 0.2s ease;
    }
    .sidebar.collapsed { width: 84px; }
    .brand { 
      font-weight: 800;
      letter-spacing: 0.3px;
      color: var(--accent);
      opacity: 0.95;
    }
    .nav-sect {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #ffefc4;
      opacity: 0.7;
      margin: 0.5rem 0 0.25rem 0.75rem;
    }
    .slink {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.6rem 0.9rem;
      margin: 0.15rem 0.5rem;
      border-radius: 10px;
      color: #fff;
      text-decoration: none;
      transition: all 0.2s;
    }
    .slink:hover {
      background: rgba(255,255,255,0.08);
      transform: translateX(2px);
    }
    .slink.active {
      background: rgba(255,255,255,0.16);
    }
    .sicon {
      width: 28px;
      height: 28px;
      display: grid;
      place-items: center;
      background: rgba(255,255,255,0.15);
      border-radius: 8px;
    }
    .slabel { white-space: nowrap; }
    .sidebar.collapsed .slabel { display: none; }
    .sidebar.collapsed .nav-sect { display: none; }
    .collapse-btn {
      position: absolute;
      right: -12px;
      top: 12px;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background: #fff;
      color: var(--primary);
      box-shadow: var(--shadow);
      display: grid;
      place-items: center;
      cursor: pointer;
      transition: all 0.2s;
    }
    .collapse-btn:hover { transform: scale(1.1); }
    
    /* Top Navigation */
    .top-nav {
      background: rgba(255,255,255,0.95);
      backdrop-filter: blur(10px);
      box-shadow: var(--shadow-sm);
      padding: 0.75rem 0;
    }
    
    /* Hero Header */
    .hero-header {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
      color: white;
      border-radius: 20px;
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: var(--shadow-lg);
      position: relative;
      overflow: hidden;
    }
    .hero-header::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -10%;
      width: 400px;
      height: 400px;
      background: rgba(255,255,255,0.05);
      border-radius: 50%;
    }
    .hero-header::after {
      content: '';
      position: absolute;
      bottom: -30%;
      left: -5%;
      width: 300px;
      height: 300px;
      background: rgba(255,255,255,0.03);
      border-radius: 50%;
    }
    .hero-content {
      position: relative;
      z-index: 1;
    }
    .hero-title {
      font-size: 2rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
    }
    .hero-subtitle {
      opacity: 0.9;
      font-size: 1.1rem;
    }
    .hero-avatar {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: rgba(255,255,255,0.2);
      backdrop-filter: blur(10px);
      display: grid;
      place-items: center;
      font-size: 2rem;
      font-weight: 700;
      border: 4px solid rgba(255,255,255,0.3);
      overflow: hidden;
    }
    .hero-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    
    /* Quick Actions */
    .quick-actions {
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
      margin-bottom: 2rem;
    }
    .action-btn {
      flex: 1;
      min-width: 150px;
      padding: 1rem;
      background: white;
      border: none;
      border-radius: 12px;
      box-shadow: var(--shadow-sm);
      transition: all 0.3s;
      text-decoration: none;
      color: var(--dark);
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    .action-btn:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
      color: var(--primary);
    }
    .action-btn i {
      font-size: 1.5rem;
      color: var(--primary);
    }
    
    /* Modern Cards */
    .card-modern {
      border: 0;
      border-radius: 16px;
      box-shadow: var(--shadow);
      transition: all 0.3s;
      background: white;
    }
    .card-modern:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-lg);
    }
    
    /* KPI Cards */
    .kpi-card {
      padding: 1.5rem;
      border-radius: 16px;
      background: white;
      box-shadow: var(--shadow);
      transition: all 0.3s;
      height: 100%;
    }
    .kpi-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }
    .kpi-icon {
      width: 50px;
      height: 50px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      font-size: 1.5rem;
      margin-bottom: 1rem;
    }
    .kpi-value {
      font-size: 1.8rem;
      font-weight: 700;
      margin-bottom: 0.25rem;
    }
    .kpi-label {
      color: #6c757d;
      font-size: 0.875rem;
      font-weight: 500;
    }
    .kpi-trend {
      font-size: 0.75rem;
      margin-top: 0.5rem;
    }
    .kpi-trend.up {
      color: var(--success);
    }
    .kpi-trend.down {
      color: var(--danger);
    }
    
    /* Chart Container */
    .chart-container {
      position: relative;
      height: 300px;
      padding: 1rem;
    }
    
    /* Filter Toggle */
    .filter-toggle {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 1rem;
    }
    .filter-btn {
      padding: 0.5rem 1rem;
      border: 1px solid #dee2e6;
      background: white;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s;
    }
    .filter-btn.active {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }
    .filter-btn:hover:not(.active) {
      background: var(--light);
    }
    
    /* Table Styles */
    .table-modern {
      margin-bottom: 0;
    }
    .table-modern thead {
      background: var(--light);
      font-weight: 600;
      font-size: 0.875rem;
    }
    .table-modern tbody tr {
      transition: all 0.2s;
    }
    .table-modern tbody tr:hover {
      background: #f8f9fa;
      transform: scale(1.01);
    }
    
    /* Badge Styles */
    .badge-modern {
      padding: 0.35rem 0.75rem;
      border-radius: 8px;
      font-weight: 500;
      font-size: 0.75rem;
    }
    
    /* Avatar */
    .avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--light);
      display: grid;
      place-items: center;
      font-weight: 700;
      color: var(--primary);
      border: 2px solid white;
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }
    .avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .sidebar {
        width: 84px !important;
      }
      .sidebar .slabel,
      .sidebar .nav-sect {
        display: none !important;
      }
      .hero-title {
        font-size: 1.5rem;
      }
      .quick-actions {
        flex-direction: column;
      }
      .action-btn {
        min-width: 100%;
      }
    }
    
    /* Loading Spinner */
    .loading-spinner {
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 3px solid rgba(0,0,0,0.1);
      border-radius: 50%;
      border-top-color: var(--primary);
      animation: spin 0.6s linear infinite;
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
    
    /* Card Header */
    .card-header-modern {
      background: transparent;
      border-bottom: 2px solid var(--light);
      padding: 1.25rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    
    /* Fix dropdown z-index to appear above all elements */
    .dropdown-menu {
      z-index: 1050 !important;
    }
    
    /* Ensure top nav has proper stacking context */
    .top-nav {
      position: relative;
      z-index: 1040;
    }
    
    /* Dark Mode Styles */
    body.dark-mode {
      background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
      color: #e4e4e7;
    }
    
    body.dark-mode .card-modern,
    body.dark-mode .kpi-card,
    body.dark-mode .card,
    body.dark-mode .action-btn {
      background: #1e293b;
      color: #e4e4e7;
      border-color: #334155;
    }
    
    body.dark-mode .hero-header {
      background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
    }
    
    body.dark-mode .top-nav {
      background: rgba(30, 41, 59, 0.95);
      border-bottom: 1px solid #334155;
    }
    
    body.dark-mode .sidebar {
      background: linear-gradient(180deg, var(--primary-dark), var(--primary));
    }
    
    body.dark-mode .table-modern thead {
      background: #334155;
      color: #e4e4e7;
    }
    
    body.dark-mode .table-modern tbody tr {
      background: #1e293b;
      color: #e4e4e7;
    }
    
    body.dark-mode .table-modern tbody tr:hover {
      background: #334155;
    }
    
    body.dark-mode .kpi-label,
    body.dark-mode .text-muted {
      color: #94a3b8 !important;
    }
    
    body.dark-mode .action-btn:hover {
      background: #334155;
      color: var(--accent);
    }
    
    body.dark-mode .navbar-brand {
      color: #e4e4e7 !important;
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
    <div class="collapse-btn" id="sbToggle" title="Collapse/Expand">
      <i class="bi bi-chevron-left"></i>
    </div>

    <div class="nav-sect mt-3">Main</div>
    <a href="/" class="slink active">
      <span class="sicon"><i class="bi bi-house"></i></span>
      <span class="slabel">Home</span>
    </a>
    <a href="/operation" class="slink">
      <span class="sicon"><i class="bi bi-gear-wide-connected"></i></span>
      <span class="slabel">Operation</span>
    </a>
    <a href="/account" class="slink">
      <span class="sicon"><i class="bi bi-wallet2"></i></span>
      <span class="slabel">Accounts</span>
    </a>
    <?php if ($canViewGmDashboard): ?>
    <a href="/accounts/gm_dashboard" class="slink">
      <span class="sicon"><i class="bi bi-speedometer2"></i></span>
      <span class="slabel">GM Dashboard</span>
    </a>
    <?php endif; ?>

    <?php /* Mobile App section hidden - under development
    <div class="nav-sect">Mobile App</div>
    <a href="<?= get_base_path() ? get_base_path() . '/' : '/' ?>mobile_app_admin" class="slink">
      <span class="sicon"><i class="bi bi-phone"></i></span>
      <span class="slabel">App Admin</span>
    </a>
    <a href="<?= get_base_path() ? get_base_path() . '/' : '/' ?>operation/online_bookings" class="slink">
      <span class="sicon"><i class="bi bi-calendar-check"></i></span>
      <span class="slabel">Online Bookings</span>
    </a>
    */ ?>

    <?php 
    // Check if user has access to multiple companies
    $userId = current_user_id();
    $userCompanies = $userId ? get_user_companies($conn, $userId) : [];
    $hasMultipleCompanies = count($userCompanies) > 1;
    
    if ($hasMultipleCompanies): 
    ?>
    <div class="nav-sect">Switch Module</div>
    <a href="/select-module" class="slink" style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2);">
      <span class="sicon"><i class="bi bi-arrow-left-right"></i></span>
      <span class="slabel">Switch Module</span>
    </a>
    <?php endif; ?>

    <div class="nav-sect">Other</div>
    <a href="/settings" class="slink">
      <span class="sicon"><i class="bi bi-sliders"></i></span>
      <span class="slabel">Settings</span>
    </a>
    <?php 
    if (has_role('Owner', $conn) || has_role('Admin', $conn)): 
    ?>
    <a href="/settings?tab=companies" class="slink">
      <span class="sicon"><i class="bi bi-building-add"></i></span>
      <span class="slabel">Companies Setup</span>
    </a>
    <?php endif; ?>
    <a href="/about" class="slink">
      <span class="sicon"><i class="bi bi-info-circle"></i></span>
      <span class="slabel">About</span>
    </a>
  </aside>

  <!-- Main Content -->
  <div class="flex-grow-1">
    <!-- Top Navigation -->
    <nav class="top-nav">
      <div class="container-fluid px-4">
        <div class="d-flex align-items-center justify-content-between">
          <span class="navbar-brand fw-bold mb-0" style="color: var(--primary)">Dashboard</span>
          <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
              <div class="avatar me-2">
                <?php if (!empty($userAvatar) && file_exists($userAvatar)): ?>
                  <img src="<?= h($userAvatar) ?>" alt="<?= h($fullName) ?>">
                <?php else: ?>
                  <?= h($avatarInitial) ?>
                <?php endif; ?>
              </div>
              <span class="me-2"><?= h($fullName) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow">
              <li><span class="dropdown-item-text"><strong><?= h($userName) ?></strong></span></li>
              <li><span class="dropdown-item-text text-muted small"><?= h($userRole) ?></span></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="/profile"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
              <li><a class="dropdown-item text-danger" href="/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
          </div>
        </div>
      </div>
    </nav>

    <!-- Content -->
    <div class="container-fluid px-4 py-4">
      <!-- Hero Header -->
      <div class="hero-header">
        <div class="hero-content">
          <div class="row align-items-center">
            <div class="col-md-8">
              <h1 class="hero-title">Welcome back, <?= h($fullName) ?>! 👋</h1>
              <p class="hero-subtitle mb-0">Here's what's happening with your business today</p>
            </div>
            <div class="col-md-4 text-md-end">
              <div class="hero-avatar d-inline-grid">
                <?php if (!empty($userAvatar) && file_exists($userAvatar)): ?>
                  <img src="<?= h($userAvatar) ?>" alt="<?= h($fullName) ?>">
                <?php else: ?>
                  <?= h($avatarInitial) ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="quick-actions">
        
        <a href="accounts/invoice_create" class="action-btn">
          <i class="bi bi-receipt"></i>
          <div>
            <div class="fw-bold">New Invoice</div>
            <small class="text-muted">Bill client</small>
          </div>
        </a>
        <a href="accounts/expense_add" class="action-btn">
          <i class="bi bi-wallet"></i>
          <div>
            <div class="fw-bold">Record Expense</div>
            <small class="text-muted">Track costs</small>
          </div>
        </a>
        <a href="hr/employee_edit" class="action-btn">
          <i class="bi bi-person-badge"></i>
          <div>
            <div class="fw-bold">Add Employee</div>
            <small class="text-muted">HR management</small>
          </div>
        </a>
        <?php if ($canViewGmDashboard): ?>
        <a href="accounts/gm_dashboard" class="action-btn">
          <i class="bi bi-speedometer2"></i>
          <div>
            <div class="fw-bold">GM Dashboard</div>
            <small class="text-muted">Revenue, profit &amp; AR</small>
          </div>
        </a>
        <?php endif; ?>
      </div>

      <?php if ($canViewGmDashboard): ?>
      <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
        <div class="card-body p-4" style="background: linear-gradient(135deg, #f8f9fc 0%, #eef2ff 100%);">
          <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <div>
              <div class="text-uppercase small text-muted fw-semibold">GM Financial Overview</div>
              <div class="text-muted small"><?= h(date('M j', strtotime($currentMonth))) ?> – <?= h(date('M j', strtotime($today))) ?></div>
            </div>
            <a href="accounts/gm_dashboard" class="btn btn-primary btn-sm ms-auto">
              <i class="bi bi-speedometer2 me-1"></i>Open GM Dashboard
            </a>
          </div>
          <div class="row g-3">
            <div class="col-6 col-md-3">
              <div class="small text-muted">Revenue (GL)</div>
              <div class="fw-bold fs-5 text-primary"><?= number_format((float)$revenueGlThisMonth, 2) ?> <span class="fs-6 fw-normal">AED</span></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="small text-muted">Expenses (GL)</div>
              <div class="fw-bold fs-5 text-danger"><?= number_format((float)$expensesGlThisMonth, 2) ?> <span class="fs-6 fw-normal">AED</span></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="small text-muted">Net Profit</div>
              <div class="fw-bold fs-5 text-success"><?= number_format((float)$netProfitThisMonth, 2) ?> <span class="fs-6 fw-normal">AED</span></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="small text-muted">Outstanding AR</div>
              <div class="fw-bold fs-5" style="color:#fd7e14"><?= number_format((float)$outstandingAr, 2) ?> <span class="fs-6 fw-normal">AED</span></div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Financial Summary KPIs -->
      <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(40, 167, 69, 0.1); color: var(--success);">
              <i class="bi bi-currency-dollar"></i>
            </div>
            <div class="kpi-value text-success"><?= number_format($revenueThisMonth, 2) ?> AED</div>
            <div class="kpi-label">Revenue This Month</div>
            <div class="kpi-trend up"><i class="bi bi-arrow-up"></i> From invoices</div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(220, 53, 69, 0.1); color: var(--danger);">
              <i class="bi bi-wallet2"></i>
            </div>
            <div class="kpi-value text-danger"><?= number_format($expensesThisMonth, 2) ?> AED</div>
            <div class="kpi-label">Expenses This Month</div>
            <div class="kpi-trend"><i class="bi bi-receipt"></i> Operating costs</div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(23, 162, 184, 0.1); color: var(--info);">
              <i class="bi bi-graph-up-arrow"></i>
            </div>
            <div class="kpi-value text-info"><?= number_format($collectionsThisMonth, 2) ?> AED</div>
            <div class="kpi-label">Collections This Month</div>
            <div class="kpi-trend"><i class="bi bi-cash-stack"></i> Received payments</div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(255, 193, 7, 0.1); color: var(--warning);">
              <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div class="kpi-value text-warning"><?= number_format($overduePayments, 2) ?> AED</div>
            <div class="kpi-label">Overdue Payments</div>
            <div class="kpi-trend down"><i class="bi bi-calendar-x"></i> Past due</div>
          </div>
        </div>
      </div>

      <!-- Operations KPIs -->
      <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(122, 0, 0, 0.1); color: var(--primary);">
              <i class="bi bi-people-fill"></i>
            </div>
            <div class="kpi-value"><?= number_format($activeClients) ?></div>
            <div class="kpi-label">Active Clients</div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(255, 193, 7, 0.1); color: var(--warning);">
              <i class="bi bi-list-check"></i>
            </div>
            <div class="kpi-value"><?= number_format($openWorkOrders) ?></div>
            <div class="kpi-label">Open Work Orders</div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(23, 162, 184, 0.1); color: var(--info);">
              <i class="bi bi-file-earmark-text"></i>
            </div>
            <div class="kpi-value"><?= number_format($pendingInvoices) ?></div>
            <div class="kpi-label">Pending Invoices</div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(40, 167, 69, 0.1); color: var(--success);">
              <i class="bi bi-calendar-day"></i>
            </div>
            <div class="kpi-value"><?= number_format($todaysWO) ?></div>
            <div class="kpi-label">Today's Work Orders</div>
          </div>
        </div>
      </div>

      <!-- HR KPIs -->
      <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(122, 0, 0, 0.1); color: var(--primary);">
              <i class="bi bi-person-badge"></i>
            </div>
            <div class="kpi-value"><?= number_format($activeEmployees) ?></div>
            <div class="kpi-label">Active Employees</div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(255, 193, 7, 0.1); color: var(--warning);">
              <i class="bi bi-clipboard-check"></i>
            </div>
            <div class="kpi-value"><?= number_format($pendingLeave) ?> / <?= number_format($pendingOT) ?></div>
            <div class="kpi-label">Pending Leave / OT</div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(23, 162, 184, 0.1); color: var(--info);">
              <i class="bi bi-calendar-event"></i>
            </div>
            <div class="kpi-value"><?= number_format($in30) ?> / <?= number_format($in60) ?></div>
            <div class="kpi-label">Docs Expiring (30d / 60d)</div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div class="kpi-icon" style="background: rgba(40, 167, 69, 0.1); color: var(--success);">
              <i class="bi bi-cash-coin"></i>
            </div>
            <div class="kpi-value"><?= number_format($cashOutstanding, 2) ?></div>
            <div class="kpi-label">Cash Advances Outstanding</div>
          </div>
        </div>
      </div>

      <!-- Charts Row -->
      <div class="row g-3 mb-4">
        <!-- Revenue vs Expenses Chart -->
        <div class="col-xl-8">
          <div class="card-modern">
            <div class="card-header-modern">
              <span><i class="bi bi-graph-up me-2"></i>Revenue vs Expenses</span>
              <div class="filter-toggle" id="revenueFilter">
                <button class="filter-btn" data-days="7">7 Days</button>
                <button class="filter-btn active" data-days="30">30 Days</button>
                <button class="filter-btn" data-days="90">90 Days</button>
              </div>
            </div>
            <div class="card-body">
              <div class="chart-container">
                <canvas id="revenueExpensesChart"></canvas>
              </div>
            </div>
          </div>
        </div>

        <!-- Work Orders Status Chart -->
        <div class="col-xl-4">
          <div class="card-modern">
            <div class="card-header-modern">
              <span><i class="bi bi-pie-chart me-2"></i>Work Orders Status</span>
            </div>
            <div class="card-body">
              <div class="chart-container">
                <canvas id="workOrdersChart"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Top Clients Chart -->
      <div class="row g-3 mb-4">
        <div class="col-xl-12">
          <div class="card-modern">
            <div class="card-header-modern">
              <span><i class="bi bi-bar-chart me-2"></i>Top 5 Clients by Billing (Last 30 Days)</span>
            </div>
            <div class="card-body">
              <div class="chart-container">
                <canvas id="topClientsChart"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tables Row -->
      <div class="row g-3">
        <!-- Expiring Documents -->
        <div class="col-lg-4">
          <div class="card-modern">
            <div class="card-header-modern">
              <strong>Expiring Documents (≤ 60 days)</strong>
              <a class="small text-decoration-none" href="hr/employees.php?tab=documents" style="color: var(--primary);">
                view all <i class="bi bi-arrow-right"></i>
              </a>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-modern table-sm mb-0 align-middle">
                  <thead>
                    <tr>
                      <th>Employee</th>
                      <th>Doc</th>
                      <th>Expires</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if(!$expDocs): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">No upcoming expiries.</td></tr>
                  <?php else: foreach($expDocs as $r): ?>
                    <tr>
                      <td><?= h($r['full_name'] ?: $r['employee_code']) ?></td>
                      <td><?= h($r['doc_type']) ?></td>
                      <td><?= h($r['expires_at']) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Approvals Queue -->
        <div class="col-lg-4">
          <div class="card-modern">
            <div class="card-header-modern">
              <strong>Approvals Queue</strong>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-modern table-sm mb-0">
                  <thead>
                    <tr>
                      <th>Type</th>
                      <th>Details</th>
                      <th>By</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if(!$approvals['leave'] && !$approvals['ot']): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">Nothing pending.</td></tr>
                  <?php else: ?>
                    <?php foreach($approvals['leave'] as $a): ?>
                      <tr>
                        <td><span class="badge-modern text-bg-warning">Leave</span></td>
                        <td><?= h($a['type_name']) ?> — <?= h($a['date_from']) ?> → <?= h($a['date_to']) ?></td>
                        <td><?= h($a['full_name']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php foreach($approvals['ot'] as $a): ?>
                      <tr>
                        <td><span class="badge-modern text-bg-info">OT</span></td>
                        <td><?= h($a['ot_date']) ?> • <?= number_format((float)$a['pay_hours'], 2) ?> hrs</td>
                        <td><?= h($a['full_name']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Today's Work Orders -->
        <div class="col-lg-4">
          <div class="card-modern">
            <div class="card-header-modern">
              <strong>Today's Work Orders</strong>
              <a class="small text-decoration-none" href="operation" style="color: var(--primary);">
                view all <i class="bi bi-arrow-right"></i>
              </a>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-modern table-sm mb-0 align-middle">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Customer</th>
                      <th class="text-end">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if(!$todayWOs): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">No work orders today.</td></tr>
                  <?php else: foreach($todayWOs as $wo): ?>
                    <tr>
                      <td><?= (int)$wo['id'] ?></td>
                      <td><?= h($wo['customer']) ?></td>
                      <td class="text-end"><?= number_format((float)$wo['total'], 2) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /container -->
  </div><!-- /main -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Sidebar collapse toggle
  const sb = document.getElementById('sb');
  const t = document.getElementById('sbToggle');
  t.addEventListener('click', () => {
    sb.classList.toggle('collapsed');
    t.querySelector('i').classList.toggle('bi-chevron-right');
    t.querySelector('i').classList.toggle('bi-chevron-left');
  });

  // Charts Configuration
  Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
  Chart.defaults.color = '#666';

  let revenueExpensesChart;
  let workOrdersChart;
  let topClientsChart;

  // Initialize all charts
  async function initializeCharts(days = 30) {
    await loadRevenueExpensesChart(days);
    await loadWorkOrdersChart(days);
    await loadTopClientsChart(days);
  }

  // Revenue vs Expenses Chart
  async function loadRevenueExpensesChart(days = 30) {
    try {
      const response = await fetch(`api/dashboard_stats.php?action=revenue_vs_expenses&days=${days}`);
      const data = await response.json();

      const ctx = document.getElementById('revenueExpensesChart');
      
      if (revenueExpensesChart) {
        revenueExpensesChart.destroy();
      }

      revenueExpensesChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.labels.map(d => {
            const date = new Date(d);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
          }),
          datasets: [
            {
              label: 'Revenue',
              data: data.revenue,
              borderColor: '#28a745',
              backgroundColor: 'rgba(40, 167, 69, 0.1)',
              fill: true,
              tension: 0.4
            },
            {
              label: 'Expenses',
              data: data.expenses,
              borderColor: '#dc3545',
              backgroundColor: 'rgba(220, 53, 69, 0.1)',
              fill: true,
              tension: 0.4
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            intersect: false,
            mode: 'index'
          },
          plugins: {
            legend: {
              position: 'top',
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' AED';
                }
              }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                callback: function(value) {
                  return value.toFixed(0) + ' AED';
                }
              }
            }
          }
        }
      });
    } catch (error) {
      console.error('Error loading revenue/expenses chart:', error);
    }
  }

  // Work Orders Chart
  async function loadWorkOrdersChart(days = 30) {
    try {
      const response = await fetch(`api/dashboard_stats.php?action=work_orders&days=${days}`);
      const data = await response.json();

      const ctx = document.getElementById('workOrdersChart');
      
      if (workOrdersChart) {
        workOrdersChart.destroy();
      }

      const colors = {
        completed: '#28a745',
        confirmed: '#17a2b8',
        pending: '#ffc107',
        invoiced: '#6c757d',
        other: '#dc3545'
      };

      workOrdersChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: data.labels.map(l => l.charAt(0).toUpperCase() + l.slice(1)),
          datasets: [{
            data: data.counts,
            backgroundColor: data.labels.map(l => colors[l] || '#6c757d'),
            borderWidth: 2,
            borderColor: '#fff'
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom',
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const label = context.label || '';
                  const value = context.parsed || 0;
                  const total = context.dataset.data.reduce((a, b) => a + b, 0);
                  const percentage = ((value / total) * 100).toFixed(1);
                  return label + ': ' + value + ' (' + percentage + '%)';
                }
              }
            }
          }
        }
      });
    } catch (error) {
      console.error('Error loading work orders chart:', error);
    }
  }

  // Top Clients Chart
  async function loadTopClientsChart(days = 30) {
    try {
      const response = await fetch(`api/dashboard_stats.php?action=top_clients&days=${days}`);
      const data = await response.json();

      const ctx = document.getElementById('topClientsChart');
      
      if (topClientsChart) {
        topClientsChart.destroy();
      }

      topClientsChart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: data.clients,
          datasets: [{
            label: 'Total Billed (AED)',
            data: data.amounts,
            backgroundColor: 'rgba(122, 0, 0, 0.7)',
            borderColor: 'rgba(122, 0, 0, 1)',
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          indexAxis: 'y',
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  return 'Billed: ' + context.parsed.x.toFixed(2) + ' AED';
                }
              }
            }
          },
          scales: {
            x: {
              beginAtZero: true,
              ticks: {
                callback: function(value) {
                  return value.toFixed(0) + ' AED';
                }
              }
            }
          }
        }
      });
    } catch (error) {
      console.error('Error loading top clients chart:', error);
    }
  }

  // Filter toggle for revenue chart
  document.querySelectorAll('#revenueFilter .filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      document.querySelectorAll('#revenueFilter .filter-btn').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      const days = parseInt(this.dataset.days);
      loadRevenueExpensesChart(days);
    });
  });

  // Initialize charts on page load
  document.addEventListener('DOMContentLoaded', () => {
    initializeCharts();
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
</script>
</body>
</html>
