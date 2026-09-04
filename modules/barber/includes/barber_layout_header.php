<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}
if (!defined('MODULE_BARBER')) {
    require_once dirname(__DIR__, 3) . '/includes/module_access.php';
}
if (!function_exists('has_permission')) {
    require_once dirname(__DIR__, 3) . '/includes/permissions.php';
}
if (!function_exists('has_barber_pos_department')) {
    require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
}
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
$currentPage = basename($_SERVER['PHP_SELF']);
$U = !empty($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

require_once dirname(__DIR__, 3) . '/includes/url_helper.php';
$appBase = get_application_web_root();
require_once dirname(__DIR__, 3) . '/includes/tasks_nav_helper.php';

$barberCompanyName = '';
$barberCtxId = 0;
$barberEligibleCompanies = [];
$barberSwitchReturn = $_SERVER['REQUEST_URI'] ?? (($appBase !== '' ? $appBase : '') . '/modules/barber/dashboard.php');

if (isset($conn) && $conn instanceof PDO && function_exists('current_company_id')) {
    if (!function_exists('get_company')) {
        require_once dirname(__DIR__, 3) . '/includes/company_helper.php';
    }
    require_once dirname(__DIR__, 3) . '/includes/module_access.php';
    $bctx = current_company_id($conn);
    $barberCtxId = $bctx ? (int)$bctx : 0;
    if ($bctx) {
        $bco = get_company($conn, (int)$bctx);
        $barberCompanyName = $bco['name'] ?? '';
    }
    $bUid = function_exists('current_user_id') ? (int)current_user_id() : 0;
    if ($bUid) {
        foreach (get_user_companies($conn, $bUid) as $c) {
            if (user_has_company_module_access($conn, $bUid, (int)$c['id'], MODULE_BARBER)) {
                $barberEligibleCompanies[] = $c;
            }
        }
    }
}

$barberShowPos = isset($conn) && $conn instanceof PDO ? has_barber_pos_department($conn) : true;
$barberShowBackOffice = isset($conn) && $conn instanceof PDO ? has_barber_backoffice_department($conn) : true;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= isset($pageTitle) ? h($pageTitle) . ' | ' : '' ?><?= h($brand['system_name']) ?> — Barber</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --primary: <?= $brand['primary_color'] ?>;
      --bb-bg: #1e2430;
      --bb-accent: #c9a227;
    }
    body { background:#f0f2f5; font-family: 'Segoe UI', system-ui, sans-serif; }
    .sidebar {
      min-height:100vh; width:250px; background: linear-gradient(180deg, var(--bb-bg), #2d3545);
      color:#fff; position:sticky; top:0; z-index:1030;
    }
    .slink { display:flex; align-items:center; gap:.75rem; padding:.55rem .85rem; margin:.12rem .45rem; border-radius:10px; color:#fff; text-decoration:none; }
    .slink:hover { background:rgba(255,255,255,.08); color:#fff; }
    .slink.active { background:rgba(201,162,39,.2); border:1px solid rgba(201,162,39,.45); }
    .sicon { width:28px; height:28px; display:grid; place-items:center; background:rgba(255,255,255,.1); border-radius:8px; }
    .avatar { width:36px;height:36px;border-radius:50%; background:#eee; display:grid; place-items:center; font-weight:700; color:var(--bb-bg); border: 2px solid #fff; }
    .card { border:0; border-radius:16px; box-shadow:0 8px 24px rgba(0,0,0,.06); }
    .page-header-label { font-size:1.5rem; font-weight:700; color:var(--bb-bg); }
  </style>
  <?php if (isset($pageHead)) { echo $pageHead; } ?>
</head>
<body>
<div class="d-flex">
  <aside class="sidebar p-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div class="d-flex align-items-center gap-2">
        <?php if ($logoSrc = brand_logo_src($brand)): ?>
          <img src="<?= h($logoSrc) ?>" alt="<?= h($brand['system_name']) ?>" style="width:38px;height:38px;object-fit:contain;border-radius:8px;flex:0 0 38px;">
        <?php endif; ?>
        <div>
          <div class="small opacity-75"><?= h($brand['system_name']) ?></div>
          <div class="fw-bold"><i class="bi bi-scissors"></i> Barber</div>
          <?php if ($barberCompanyName !== ''): ?>
            <div class="small opacity-90 text-truncate mt-1" style="max-width:12rem" title="<?= h($barberCompanyName) ?>"><?= h($barberCompanyName) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="avatar"><?= h($avatarInitial) ?></div>
    </div>

    <?php if (count($barberEligibleCompanies) > 1): ?>
    <form method="post" action="<?= h($appBase) ?>/switch_company.php" class="mb-3 pb-2 border-bottom border-light border-opacity-25">
      <?php csrf_field(); ?>
      <input type="hidden" name="return" value="<?= h($barberSwitchReturn) ?>">
      <label class="small text-white-50 mb-1 d-block">Switch company</label>
      <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($barberEligibleCompanies as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $barberCtxId ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>

    <?php if ($barberShowPos && has_permission('barber_pos.view', MODULE_BARBER, $conn)): ?>
    <div class="small text-uppercase text-white-50 mb-2">POS</div>
    <a class="slink <?= $currentPage === 'pos.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/barber/pos.php">
      <span class="sicon"><i class="bi bi-tablet"></i></span><span>Tablet POS</span>
    </a>
    <?php endif; ?>

    <?php if ($barberShowBackOffice && has_permission('barber_backoffice.view', MODULE_BARBER, $conn)): ?>
    <div class="small text-uppercase text-white-50 mb-2 mt-3">Back office</div>
    <a class="slink <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/barber/dashboard.php">
      <span class="sicon"><i class="bi bi-speedometer2"></i></span><span>Dashboard</span>
    </a>
    <a class="slink <?= $currentPage === 'sales_list.php' || $currentPage === 'sale_view.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/barber/sales_list.php">
      <span class="sicon"><i class="bi bi-receipt"></i></span><span>Sales</span>
    </a>
    <a class="slink <?= $currentPage === 'reports.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/barber/reports.php">
      <span class="sicon"><i class="bi bi-graph-up"></i></span><span>Reports</span>
    </a>
    <?php endif; ?>

    <?php if ($barberShowBackOffice && has_permission('barber_backoffice.manage_services', MODULE_BARBER, $conn)): ?>
    <a class="slink <?= $currentPage === 'services.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/barber/services.php">
      <span class="sicon"><i class="bi bi-list-stars"></i></span><span>Services</span>
    </a>
    <?php endif; ?>
    <?php if ($barberShowBackOffice && has_permission('barber_backoffice.manage_team', MODULE_BARBER, $conn)): ?>
    <a class="slink <?= $currentPage === 'barbers.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/barber/barbers.php">
      <span class="sicon"><i class="bi bi-people"></i></span><span>Team</span>
    </a>
    <?php endif; ?>
    <?php if (isset($conn) && $conn instanceof PDO && tasks_nav_user_can_access($conn)): ?>
    <a class="slink" href="<?= h($appBase) ?>/modules/tasks/tasks.php">
      <span class="sicon"><i class="bi bi-list-check"></i></span><span>Tasks</span>
    </a>
    <?php endif; ?>

    <div class="mt-4 pt-3 border-top border-secondary">
      <a class="slink" href="<?= h($appBase) ?>/select-module.php"><span class="sicon"><i class="bi bi-grid"></i></span><span>Modules</span></a>
    </div>
  </aside>
  <main class="flex-grow-1 p-4" style="min-height:100vh;">
