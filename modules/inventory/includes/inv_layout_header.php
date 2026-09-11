<?php
/**
 * Inventory Layout Header
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Expect: $conn (PDO), $brand
if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$currentPage = basename($_SERVER['PHP_SELF']);
$U = !empty($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username']  ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

require_once dirname(__DIR__, 3) . '/includes/url_helper.php';
$appBase = get_application_web_root();
require_once dirname(__DIR__, 3) . '/includes/tasks_nav_helper.php';

$invLayoutUserId = function_exists('current_user_id') ? (int)current_user_id() : 0;
$invLayoutCompanies = [];
if ($invLayoutUserId && isset($conn) && $conn instanceof PDO) {
    if (!function_exists('get_user_companies')) {
        require_once dirname(__DIR__, 3) . '/includes/company_helper.php';
    }
    $invLayoutCompanies = get_user_companies($conn, $invLayoutUserId);
}
$invLayoutCompanyName = '';
$invLayoutCid = 0;
if (isset($conn) && $conn instanceof PDO && function_exists('current_company_id')) {
    if (!function_exists('get_company')) {
        require_once dirname(__DIR__, 3) . '/includes/company_helper.php';
    }
    $invLayoutCid = (int)(current_company_id($conn) ?: 0);
    if ($invLayoutCid) {
        $ico = get_company($conn, $invLayoutCid);
        $invLayoutCompanyName = $ico['name'] ?? '';
    }
}
$invSwitchReturn = $_SERVER['REQUEST_URI'] ?? (($appBase !== '' ? $appBase : '') . '/modules/inventory/');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= isset($pageTitle) ? h($pageTitle) . ' | ' : '' ?><?= h($brand['system_name']) ?></title>
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
      min-height:100vh; width:240px; background:linear-gradient(180deg, var(--primary), var(--primary-light));
      color:#fff; position:sticky; top:0; z-index:1030;
    }
    .slink { display:flex; align-items:center; gap:.75rem; padding:.6rem .9rem; margin:.15rem .5rem; border-radius:10px; color:#fff; text-decoration:none; transition: all 0.2s; }
    .slink:hover { background:rgba(255,255,255,.08); color:#fff; }
    .slink.active { background:rgba(255,255,255,.16); }
    .sicon { width:28px; height:28px; display:grid; place-items:center; background:rgba(255,255,255,.15); border-radius:8px; }
    .avatar { width:36px;height:36px;border-radius:50%; background:#eee; display:grid; place-items:center; font-weight:700; color:var(--primary); border: 2px solid white; }
    .card { border:0; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,0.06); background:#fff; }
    .page-header-label { font-size:1.6rem; font-weight:700; color:var(--primary); }
  </style>
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
          <div class="fw-bold">Inventory</div>
          <?php if ($invLayoutCompanyName !== ''): ?>
            <div class="small opacity-90 mt-1" style="max-width:11rem;line-height:1.2" title="<?= h($invLayoutCompanyName) ?>"><?= h($invLayoutCompanyName) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="avatar"><?= h($avatarInitial) ?></div>
    </div>

    <?php if (count($invLayoutCompanies) > 1): ?>
    <form method="post" action="<?= h($appBase) ?>/switch_company.php" class="mb-3 pb-2 border-bottom border-light border-opacity-25">
      <?php if (function_exists('csrf_field')) {
          csrf_field();
      } ?>
      <input type="hidden" name="return" value="<?= h($invSwitchReturn) ?>">
      <label class="small text-white-50 mb-1 d-block">Switch company</label>
      <select name="company_id" class="form-select form-select-sm" style="max-width:100%" title="Company context for all inventory data" onchange="this.form.submit()">
        <?php foreach ($invLayoutCompanies as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $invLayoutCid ? 'selected' : '' ?>><?= h($c['name']) ?> (<?= h($c['business_type']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>

    <?php require_once dirname(__DIR__, 3) . '/includes/nav_search.php'; ?>
    <?= nav_search_box() ?>

    <div class="small text-uppercase opacity-75 mb-2">Core</div>
    <a class="slink <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/">
      <span class="sicon"><i class="bi bi-speedometer2"></i></span><span>Dashboard</span>
    </a>
    <a class="slink <?= ($currentPage === 'items.php' || $currentPage === 'item_edit.php') ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/items.php">
      <span class="sicon"><i class="bi bi-box-seam"></i></span><span>Items</span>
    </a>
    <a class="slink <?= $currentPage === 'locations.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/locations.php">
      <span class="sicon"><i class="bi bi-building"></i></span><span>Locations</span>
    </a>
    <a class="slink <?= $currentPage === 'categories.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/categories.php">
      <span class="sicon"><i class="bi bi-folder"></i></span><span>Categories</span>
    </a>
    <a class="slink <?= $currentPage === 'uoms.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/uoms.php">
      <span class="sicon"><i class="bi bi-rulers"></i></span><span>UoM</span>
    </a>
    <a class="slink <?= $currentPage === 'opening_import.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/opening_import.php">
      <span class="sicon"><i class="bi bi-file-earmark-arrow-up"></i></span><span>Opening import</span>
    </a>
    <a class="slink <?= $currentPage === 'documents.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/documents.php">
      <span class="sicon"><i class="bi bi-receipt"></i></span><span>Documents</span>
    </a>
    <a class="slink <?= $currentPage === 'reports.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/reports.php">
      <span class="sicon"><i class="bi bi-bar-chart"></i></span><span>Reports</span>
    </a>
    <?php if (isset($conn) && $conn instanceof PDO && tasks_nav_user_can_access($conn, $invLayoutUserId)): ?>
    <a class="slink" href="<?= h($appBase) ?>/modules/tasks/tasks.php">
      <span class="sicon"><i class="bi bi-list-check"></i></span><span>Tasks</span>
    </a>
    <?php endif; ?>

    <div class="small text-uppercase opacity-75 mb-2 mt-3">Requests</div>
    <a class="slink <?= $currentPage === 'requests.php' || $currentPage === 'request_create.php' || $currentPage === 'request_view.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/requests.php">
      <span class="sicon"><i class="bi bi-clipboard-check"></i></span><span>Material requests</span>
    </a>
    <a class="slink <?= $currentPage === 'my_material_requests.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/my_material_requests.php">
      <span class="sicon"><i class="bi bi-list-ul"></i></span><span>My requests</span>
    </a>
    <a class="slink <?= $currentPage === 'reports_requests.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/reports_requests.php">
      <span class="sicon"><i class="bi bi-graph-up-arrow"></i></span><span>Request reports</span>
    </a>

    <div class="small text-uppercase opacity-75 mb-2 mt-3">Purchasing</div>
    <a class="slink <?= $currentPage === 'suppliers.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/suppliers.php">
      <span class="sicon"><i class="bi bi-truck"></i></span><span>Suppliers</span>
    </a>
    <a class="slink <?= $currentPage === 'purchase_orders.php' || $currentPage === 'purchase_order_edit.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/purchase_orders.php">
      <span class="sicon"><i class="bi bi-file-earmark-text"></i></span><span>Purchase orders</span>
    </a>
    <a class="slink <?= $currentPage === 'goods_receipts.php' || $currentPage === 'goods_receipt_edit.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/goods_receipts.php">
      <span class="sicon"><i class="bi bi-box-arrow-in-down"></i></span><span>Goods receipts</span>
    </a>
    <a class="slink <?= $currentPage === 'purchase_history.php' ? 'active' : '' ?>" href="<?= h($appBase) ?>/modules/inventory/purchase_history.php">
      <span class="sicon"><i class="bi bi-clock-history"></i></span><span>Purchase history</span>
    </a>

    <div class="mt-4">
      <a href="<?= h($appBase) ?>/select-module" class="slink" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);">
        <span class="sicon"><i class="bi bi-arrow-left-right"></i></span><span>Switch Module</span>
      </a>
    </div>
  </aside>

  <main class="flex-grow-1 p-4">
