<?php
/**
 * Grocery module layout — POS retail & backoffice (supermarket companies).
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
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

$groceryContextCompanyName = '';
$groceryCtxId = 0;
$groceryEligibleCompanies = [];
$grocerySwitchReturn = $_SERVER['REQUEST_URI'] ?? (($appBase !== '' ? $appBase : '') . '/modules/grocery/pos_dashboard.php');

if (isset($conn) && $conn instanceof PDO && function_exists('current_company_id')) {
    if (!function_exists('get_company')) {
        require_once dirname(__DIR__, 3) . '/includes/company_helper.php';
    }
    require_once dirname(__DIR__, 3) . '/includes/module_access.php';
    require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
    $gctx = current_company_id($conn);
    $groceryCtxId = $gctx ? (int)$gctx : 0;
    if ($gctx) {
        $gco = get_company($conn, (int)$gctx);
        $groceryContextCompanyName = $gco['name'] ?? '';
    }
    $gUid = function_exists('current_user_id') ? (int)current_user_id() : 0;
    if ($gUid) {
        foreach (get_user_companies($conn, $gUid) as $c) {
            if (user_has_company_module_access($conn, $gUid, (int)$c['id'], MODULE_GROCERY)) {
                $groceryEligibleCompanies[] = $c;
            }
        }
    }
}

$groceryShowPos = isset($conn) && $conn instanceof PDO && function_exists('has_grocery_pos_department')
    ? has_grocery_pos_department($conn) : true;
$groceryShowBackOffice = isset($conn) && $conn instanceof PDO && function_exists('has_grocery_backoffice_department')
    ? has_grocery_backoffice_department($conn) : true;
$groceryShowInventory = isset($conn) && $conn instanceof PDO && function_exists('has_module_access_v2')
    ? (has_module_access_v2(MODULE_INVENTORY, $conn) || $groceryShowBackOffice) : true;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= isset($pageTitle) ? h($pageTitle) . ' | ' : '' ?><?= h($brand['system_name']) ?> — Grocery</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --primary: <?= $brand['primary_color'] ?>;
      --primary-light: <?= $brand['primary_light'] ?>;
      --primary-dark: <?= $brand['primary_dark'] ?>;
      --accent: <?= $brand['accent_color'] ?>;
    }
    body { background:#f7f9fb; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .sidebar {
      min-height:100vh; width:240px; background:linear-gradient(180deg, #1e5f3f, #2d8f5c);
      color:#fff; position:sticky; top:0; z-index:1030;
    }
    .slink { display:flex; align-items:center; gap:.75rem; padding:.6rem .9rem; margin:.15rem .5rem; border-radius:10px; color:#fff; text-decoration:none; transition: all 0.2s; }
    .slink:hover { background:rgba(255,255,255,.08); color:#fff; }
    .slink.active { background:rgba(255,255,255,.16); }
    .sicon { width:28px; height:28px; display:grid; place-items:center; background:rgba(255,255,255,.15); border-radius:8px; }
    .avatar { width:36px;height:36px;border-radius:50%; background:#eee; display:grid; place-items:center; font-weight:700; color:#1e5f3f; border: 2px solid white; }
    .card { border:0; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,0.06); background:#fff; }
    .page-header-label { font-size:1.6rem; font-weight:700; color:#1e5f3f; }
  </style>
  <?php if (isset($pageHead)) {
      echo $pageHead;
  } ?>
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
          <div class="fw-bold">Grocery</div>
          <?php if ($groceryContextCompanyName !== ''): ?>
            <div class="small opacity-90 text-truncate mt-1" style="max-width:11rem" title="<?= h($groceryContextCompanyName) ?>"><?= h($groceryContextCompanyName) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="avatar"><?= h($avatarInitial) ?></div>
    </div>

    <?php if (count($groceryEligibleCompanies) > 1): ?>
    <form method="post" action="<?= h($appBase) ?>/switch_company.php" class="mb-3 pb-2 border-bottom border-light border-opacity-25">
      <?php if (function_exists('csrf_field')) {
          csrf_field();
      } ?>
      <input type="hidden" name="return" value="<?= h($grocerySwitchReturn) ?>">
      <label class="small text-white-50 mb-1 d-block">Switch company</label>
      <select name="company_id" class="form-select form-select-sm bg-light" style="max-width:100%" title="Supermarket company for POS data" onchange="this.form.submit()">
        <?php foreach ($groceryEligibleCompanies as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $groceryCtxId ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>

    <div class="small text-uppercase opacity-75 mb-2">Store</div>
    <?php if ($groceryShowBackOffice): ?>
    <a class="slink <?= $currentPage === 'pos_dashboard.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/grocery/pos_dashboard.php">
      <span class="sicon"><i class="bi bi-graph-up"></i></span><span>POS dashboard</span>
    </a>
    <?php endif; ?>
    <?php if ($groceryShowPos): ?>
    <a class="slink <?= $currentPage === 'pos_retail.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/grocery/pos_retail.php">
      <span class="sicon"><i class="bi bi-shop"></i></span><span>Retail POS</span>
    </a>
    <?php endif; ?>
    <?php if ($groceryShowBackOffice): ?>
    <a class="slink <?= $currentPage === 'pos_sales_list.php' || $currentPage === 'pos_sale_view.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/grocery/pos_sales_list.php">
      <span class="sicon"><i class="bi bi-receipt-cutoff"></i></span><span>POS sales</span>
    </a>
    <a class="slink <?= $currentPage === 'pos_reports_items.php' || $currentPage === 'pos_report_item.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/grocery/pos_reports_items.php">
      <span class="sicon"><i class="bi bi-box-seam"></i></span><span>Item sales</span>
    </a>
    <a class="slink <?= $currentPage === 'pos_reports_cashiers.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/grocery/pos_reports_cashiers.php">
      <span class="sicon"><i class="bi bi-person-badge"></i></span><span>Cashiers</span>
    </a>
    <a class="slink <?= $currentPage === 'pos_reports_payments.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/grocery/pos_reports_payments.php">
      <span class="sicon"><i class="bi bi-wallet2"></i></span><span>Payments</span>
    </a>
    <?php if (isset($conn) && $conn instanceof PDO && tasks_nav_user_can_access($conn)): ?>
    <a class="slink" href="<?= h($appBase) ?>/modules/tasks/tasks.php">
      <span class="sicon"><i class="bi bi-list-check"></i></span><span>Tasks</span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($groceryShowInventory): ?>
    <div class="small text-uppercase opacity-75 mb-2 mt-3">Inventory</div>
    <a class="slink" href="<?= h($appBase) ?>/modules/inventory/items.php">
      <span class="sicon"><i class="bi bi-box-seam"></i></span><span>Items &amp; catalog</span>
    </a>
    <a class="slink" href="<?= h($appBase) ?>/modules/inventory/locations.php">
      <span class="sicon"><i class="bi bi-building"></i></span><span>Locations</span>
    </a>
    <a class="slink" href="<?= h($appBase) ?>/modules/inventory/">
      <span class="sicon"><i class="bi bi-speedometer2"></i></span><span>Inventory home</span>
    </a>
    <?php endif; ?>
  </aside>
  <main class="flex-grow-1 p-4" style="min-height:100vh;">
