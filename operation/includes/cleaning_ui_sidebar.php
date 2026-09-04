<?php
/**
 * Cleaning operation sidebar. Expects: $brand, $conn, $activeNav (operation|material_request|home|account)
 */
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
require_once dirname(__DIR__, 2) . '/includes/url_helper.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks_nav_helper.php';
require_once dirname(__DIR__, 2) . '/includes/inventory/inv_request_links.php';
$root = get_application_web_root();
$mrUrl = ($root !== '' ? $root : '') . '/operation/material_request_create.php?source_module=cleaning';
$mrMyUrl = ($root !== '' ? $root : '') . '/operation/my_material_requests.php';
$activeNav = $activeNav ?? 'operation';
?>
  <aside id="sb" class="sidebar d-flex flex-column p-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <?php if (!empty($brand['logo_path']) && file_exists(dirname(__DIR__, 2) . '/' . $brand['logo_path'])): ?>
        <img src="<?= h($brand['logo_path']) ?>" alt="<?= h($brand['system_name']) ?>" style="width: 40px; height: 40px; object-fit: contain; border-radius: 8px;">
      <?php endif; ?>
      <span class="brand ms-1"><?= h($brand['system_name']) ?></span>
    </div>
    <div class="collapse-btn" id="sbToggle" title="Collapse/Expand"><i class="bi bi-chevron-left"></i></div>

    <div class="nav-sect mt-3">Main</div>
    <a href="<?= h($root ?: '') ?>/index" class="slink <?= $activeNav === 'home' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-house"></i></span><span class="slabel">Home</span></a>
    <?php if (has_department_access(MODULE_CLEANING, DEPT_CLEANING_OPERATIONS, $conn)): ?>
    <a href="<?= h($root ?: '') ?>/operation" class="slink <?= $activeNav === 'operation' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-gear-wide-connected"></i></span><span class="slabel">Operation</span></a>
    <?php endif; ?>
    <?php if (has_permission('inventory_requests.create', MODULE_INVENTORY, $conn) && inv_user_can_access_material_request_create($conn, 'cleaning')): ?>
    <a href="<?= h($mrUrl) ?>" class="slink <?= $activeNav === 'material_request' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-clipboard-plus"></i></span><span class="slabel">Material request</span></a>
    <a href="<?= h($mrMyUrl) ?>" class="slink <?= $activeNav === 'material_request' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-list-ul"></i></span><span class="slabel">My material requests</span></a>
    <?php endif; ?>
    <?php if (has_department_access(MODULE_CLEANING, DEPT_CLEANING_ACCOUNTS, $conn)): ?>
    <a href="<?= h($root ?: '') ?>/account" class="slink <?= $activeNav === 'account' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-wallet2"></i></span><span class="slabel">Account</span></a>
    <?php endif; ?>
    <?php if (isset($conn) && $conn instanceof PDO && tasks_nav_user_can_access($conn)): ?>
    <a href="<?= h($root ?: '') ?>/modules/tasks/tasks.php" class="slink"><span class="sicon"><i class="bi bi-list-check"></i></span><span class="slabel">Tasks</span></a>
    <?php endif; ?>

    <div class="nav-sect">Other</div>
    <?php if (has_role('Owner', $conn) || has_role('Admin', $conn)): ?>
    <a href="<?= h($root ?: '') ?>/settings" class="slink"><span class="sicon"><i class="bi bi-sliders"></i></span><span class="slabel">Settings</span></a>
    <?php endif; ?>
    <a href="<?= h($root ?: '') ?>/about" class="slink"><span class="sicon"><i class="bi bi-info-circle"></i></span><span class="slabel">About</span></a>
  </aside>
