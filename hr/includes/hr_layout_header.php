<?php
/**
 * HR Layout Header — light gold Control Center shell.
 * Include after auth/db. Optional: $pageTitle, $hrScopeLabel, $pageHead, $pageStyles
 */

require_once __DIR__ . '/ui/hr_ui_helpers.php';
require_once __DIR__ . '/hr_nav.php';

if (!isset($brand)) {
    require_once dirname(__DIR__, 2) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}

$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$U = $hasUserObject ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
require_once dirname(__DIR__, 2) . '/includes/tasks_nav_helper.php';
require_once dirname(__DIR__, 2) . '/includes/url_helper.php';

$appBase = get_application_web_root();
$hrBase = ($appBase !== '' ? $appBase : '') . '/hr';
$hrAssetBase = ($appBase !== '' ? $appBase : '') . '/assets/hr';

$canSwitchModule = true;
try {
    require_once dirname(__DIR__, 2) . '/includes/module_access.php';
    $hrUid = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
    $hrRoleNames = $_SESSION['role_names'] ?? [];
    $isOwnerAdmin = in_array('Owner', $hrRoleNames, true) || in_array('Admin', $hrRoleNames, true);
    if (!$isOwnerAdmin && $hrUid > 0) {
        $hrUserModules = get_user_modules($conn, $hrUid);
        $hrModuleKeys = array_filter(array_unique(array_map(
            static function ($m) {
                return is_array($m) ? ($m['module'] ?? '') : (string)$m;
            },
            $hrUserModules
        )));
        $canSwitchModule = count($hrModuleKeys) > 1;
    }
} catch (Throwable $e) {
    $canSwitchModule = true;
}

$currentPage = basename($_SERVER['PHP_SELF']);
$currentRoles = function_exists('current_user_roles') ? current_user_roles($conn) : [];
$isWorkerNavigation = class_exists('Guard') ? Guard::isWorker($currentRoles) : false;
$selfEmployeeId = $isWorkerNavigation ? Guard::currentEmployeeId($conn) : null;
if ($selfEmployeeId) {
    $_SESSION['employee_view_id'] = (string)$selfEmployeeId;
}
$selfProfileUrl = 'employee_view';

$pageTitle = $pageTitle ?? hr_nav_page_title($currentPage);
$hrScopeLabel = $hrScopeLabel ?? '';
$hrNavGroups = hr_nav_groups($hrBase, $isWorkerNavigation, $selfProfileUrl);
$logoSrc = function_exists('brand_logo_src') ? brand_logo_src($brand) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= hr_ui_h($pageTitle) ?> · HR | <?= hr_ui_h($brand['system_name'] ?? 'HeroSysgro') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= hr_ui_h($hrAssetBase) ?>/hr-ui-v2.css?v=20260904-1" rel="stylesheet">
  <?php if (!empty($pageStyles)): ?><style><?= $pageStyles ?></style><?php endif; ?>
  <?php if (!empty($pageHead)) {
      echo $pageHead;
  } ?>
</head>
<body class="hr-ui-v2">
<div class="hr-sidebar-overlay" id="hr-sidebar-overlay"></div>
<div class="hr-shell">
  <aside class="hr-sidebar" id="hr-sidebar" aria-label="HR navigation">
    <div class="hr-sidebar-brand">
      <?php if ($logoSrc): ?>
        <img src="<?= hr_ui_h($logoSrc) ?>" alt="" width="40" height="40"
             style="width:40px;height:40px;object-fit:contain;border-radius:10px;flex-shrink:0">
      <?php else: ?>
        <div class="brand-mark">HR</div>
      <?php endif; ?>
      <div class="min-w-0">
        <h1>Human Resources</h1>
        <small>Control Center</small>
      </div>
    </div>
    <div class="hr-nav-search">
      <input type="search" id="hr-nav-search" class="form-control form-control-sm"
             placeholder="Search HR…" aria-label="Search HR navigation" autocomplete="off">
    </div>
    <nav class="pb-2">
      <?php foreach ($hrNavGroups as $group): ?>
        <div class="hr-nav-group">
          <div class="hr-nav-label"><?= hr_ui_h($group['label']) ?></div>
          <?php foreach ($group['items'] as $item):
            $isActive = !empty($item['pages']) && in_array($currentPage, $item['pages'], true);
            ?>
            <a class="hr-nav-link <?= $isActive ? 'active' : '' ?>"
               href="<?= hr_ui_h($item['href']) ?>"
               data-label="<?= hr_ui_h($item['label'] . ' ' . $group['label']) ?>">
              <span class="nav-ico"><i data-lucide="<?= hr_ui_h($item['icon']) ?>" style="width:16px;height:16px"></i></span>
              <span class="text-truncate"><?= hr_ui_h($item['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="mt-auto px-3 pb-3">
      <?php if ($canSwitchModule): ?>
        <a href="<?= hr_ui_h($appBase) ?>/select-module" class="hr-nav-link" data-label="Switch Module">
          <span class="nav-ico"><i data-lucide="layout-grid" style="width:16px;height:16px"></i></span>
          <span>Switch Module</span>
        </a>
      <?php endif; ?>
      <?php if (!$isWorkerNavigation && function_exists('has_role') && (has_role('Owner', $conn) || has_role('Admin', $conn))): ?>
        <a href="<?= hr_ui_h($appBase) ?>/settings" class="hr-nav-link" data-label="Settings">
          <span class="nav-ico"><i data-lucide="sliders-horizontal" style="width:16px;height:16px"></i></span>
          <span>Settings</span>
        </a>
      <?php endif; ?>
    </div>
  </aside>

  <div class="hr-main">
    <header class="hr-topbar">
      <button type="button" class="btn btn-outline-secondary btn-sm d-lg-none" id="hr-mobile-menu-btn" aria-label="Open menu">
        <i data-lucide="menu" style="width:18px;height:18px"></i>
      </button>
      <div class="flex-grow-1 min-w-0">
        <div class="small text-muted text-uppercase fw-semibold" style="letter-spacing:.06em;font-size:.65rem">Human Resources</div>
        <div class="fw-semibold text-truncate"><?= hr_ui_h($pageTitle) ?></div>
      </div>
      <?php if ($hrScopeLabel !== ''): ?>
        <span class="hr-pill hr-pill-gold d-none d-md-inline-flex text-truncate" style="max-width:240px" title="Company scope">
          <?= hr_ui_h($hrScopeLabel) ?>
        </span>
      <?php endif; ?>
      <a href="<?= hr_ui_h($appBase) ?>/index.php" class="btn btn-sm btn-outline-secondary">
        <i data-lucide="arrow-left" style="width:14px;height:14px" class="me-1"></i> ERP Home
      </a>
      <div class="dropdown">
        <a href="#" class="d-flex align-items-center gap-2 text-decoration-none text-dark" data-bs-toggle="dropdown">
          <div class="rounded-circle fw-bold d-flex align-items-center justify-content-center"
               style="width:36px;height:36px;background:var(--hr-primary-soft);color:var(--hr-primary)">
            <?= hr_ui_h($avatarInitial) ?>
          </div>
          <div class="d-none d-md-block small lh-sm">
            <div class="fw-semibold"><?= hr_ui_h($fullName ?: $userName) ?></div>
            <div class="text-muted">HR</div>
          </div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li><span class="dropdown-item-text"><strong><?= hr_ui_h($userName) ?></strong></span></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= hr_ui_h($appBase) ?>/profile"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
          <li><a class="dropdown-item text-danger" href="<?= hr_ui_h($appBase) ?>/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
        </ul>
      </div>
    </header>
    <main class="hr-content">
