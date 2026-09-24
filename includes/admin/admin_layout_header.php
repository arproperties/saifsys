<?php
/**
 * Administration Control Center layout header (light gold shell).
 * Expects: $conn, $brand, $tab (active tab), optional $pageTitle, $settingsCompanyId
 */

require_once __DIR__ . '/ui/admin_ui_helpers.php';
require_once __DIR__ . '/admin_nav.php';

if (!isset($brand) && function_exists('getBrandSettings') && isset($conn)) {
    $brand = getBrandSettings($conn);
}
$brand = $brand ?? ['system_name' => 'HeroSysgro', 'primary_color' => '#b8860b'];

if (!isset($adminNavGroups)) {
    $adminNavGroups = admin_nav_groups($conn);
}

$tab = $tab ?? 'dashboard';
$tabMeta = admin_nav_tab_meta((string)$tab);
$pageTitle = $pageTitle ?? $tabMeta[0];
$settingsCompanyId = isset($settingsCompanyId) ? (int)$settingsCompanyId : 0;
$appBase = function_exists('get_base_path') ? get_base_path() : '';
$adminAssetBase = $appBase . '/assets/admin';

$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$U = $hasUserObject ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

$companyQs = $settingsCompanyId > 0 ? '&settings_company_id=' . $settingsCompanyId : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= admin_ui_h($pageTitle) ?> · Administration | <?= admin_ui_h($brand['system_name'] ?? 'HeroSysgro') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= admin_ui_h($adminAssetBase) ?>/admin-ui-v2.css?v=20260716-2" rel="stylesheet">
  <style>
    .feature-toggle-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
    .feature-toggle-grid .form-check{padding:16px;border:1px solid var(--admin-border);border-radius:12px;background:#fafafa}
    .kudos-list{max-height:320px;overflow-y:auto}
    .kudos-item{border-radius:12px;padding:12px 16px;margin-bottom:10px;background:#fafafa;border:1px solid var(--admin-border)}
    .award-history-scroll{display:flex;gap:16px;overflow-x:auto;padding-bottom:8px}
    .award-card{min-width:220px;border-radius:16px;background:#fff;border:1px solid var(--admin-border);padding:16px;box-shadow:var(--admin-shadow)}
    .checklist-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
  </style>
</head>
<body class="admin-ui-v2">
<?php
// Check-out bar for staff who have checked in. Prints nothing when the
// feature is off or the person has no attendance to record.
$asWidget = dirname(__DIR__, 2) . '/includes/attendance_self_widget.php';
if (is_file($asWidget)) { require $asWidget; }
?>
<div class="admin-sidebar-overlay" id="admin-sidebar-overlay"></div>
<div class="admin-shell">
  <aside class="admin-sidebar" id="admin-sidebar" aria-label="Administration navigation">
    <div class="admin-sidebar-brand">
      <div class="brand-mark">A</div>
      <div class="min-w-0">
        <h1>Administration</h1>
        <small>Control Center</small>
      </div>
    </div>
    <div class="admin-nav-search">
      <input type="search" id="admin-nav-search" class="form-control form-control-sm"
             placeholder="Search settings…" aria-label="Search settings navigation" autocomplete="off">
    </div>
    <nav class="pb-4">
      <?php foreach ($adminNavGroups as $group): ?>
        <div class="admin-nav-group">
          <div class="admin-nav-label"><?= admin_ui_h($group['label']) ?></div>
          <?php foreach ($group['items'] as $item):
            $isActive = $tab === $item['tab'];
            $href = $appBase . '/settings.php?tab=' . rawurlencode($item['tab']) . $companyQs;
          ?>
            <a class="admin-nav-link <?= $isActive ? 'active' : '' ?>"
               href="<?= admin_ui_h($href) ?>"
               data-tab="<?= admin_ui_h($item['tab']) ?>"
               data-label="<?= admin_ui_h($item['label'] . ' ' . $group['label']) ?>">
              <span class="nav-ico"><i data-lucide="<?= admin_ui_h($item['icon']) ?>" style="width:16px;height:16px"></i></span>
              <span class="text-truncate"><?= admin_ui_h($item['label']) ?></span>
              <?php if (!empty($item['scope'])): ?>
                <span class="scope-chip"><?= admin_ui_h($item['scope']) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
  </aside>

  <div class="admin-main">
    <header class="admin-topbar">
      <button type="button" class="btn btn-outline-secondary btn-sm d-lg-none" id="admin-mobile-menu-btn" aria-label="Open menu">
        <i data-lucide="menu" style="width:18px;height:18px"></i>
      </button>
      <div class="flex-grow-1 min-w-0">
        <div class="small text-muted text-uppercase fw-semibold" style="letter-spacing:.06em;font-size:.65rem">Administration</div>
        <div class="fw-semibold text-truncate"><?= admin_ui_h($pageTitle) ?></div>
      </div>
      <?php if (!empty($settingsCompanyName) || $settingsCompanyId > 0): ?>
        <span class="admin-pill admin-pill-gold d-none d-md-inline-flex text-truncate" style="max-width:220px" title="Selected company context">
          <?= admin_ui_h($settingsCompanyName !== '' ? $settingsCompanyName : ('Company #' . $settingsCompanyId)) ?>
        </span>
      <?php endif; ?>
      <a href="<?= admin_ui_h($appBase) ?>/index.php" class="btn btn-sm btn-outline-secondary">
        <i data-lucide="arrow-left" style="width:14px;height:14px" class="me-1"></i> ERP Home
      </a>
      <div class="d-flex align-items-center gap-2">
        <div class="rounded-circle fw-bold d-flex align-items-center justify-content-center"
             style="width:36px;height:36px;background:var(--admin-primary-soft);color:var(--admin-primary)">
          <?= admin_ui_h($avatarInitial) ?>
        </div>
        <div class="d-none d-md-block small lh-sm">
          <div class="fw-semibold"><?= admin_ui_h($fullName ?: $userName) ?></div>
          <div class="text-muted">Admin</div>
        </div>
      </div>
    </header>
    <main class="admin-content">
      <div id="admin-toast-host" class="admin-toast-host" aria-live="polite"></div>
