<?php
/**
 * ARS Home Rentals Layout Header
 * Hospitality-themed sidebar and top navigation
 * Include at the start of each ARS page after requiring auth/db
 */

if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}

$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$U = $hasUserObject ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username']  ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$currentPage = basename($_SERVER['PHP_SELF']);
$appBase = (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';

require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
require_once dirname(__DIR__, 3) . '/includes/tasks_nav_helper.php';
$hasArsCore = has_department_access(MODULE_ARS, DEPT_ARS_CORE, $conn);
$hasArsOps  = has_department_access(MODULE_ARS, DEPT_ARS_OPERATIONS, $conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' - ARS Home Rentals | ' : 'ARS Home Rentals | ' ?><?= h($brand['system_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= dirname($_SERVER['SCRIPT_NAME']) ?>/assets/ars_styles.css?v=<?= filemtime(__DIR__ . '/../assets/ars_styles.css') ?>" rel="stylesheet">
    <meta name="ars-csrf" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <script>window.ARS_CSRF = <?= json_encode(csrf_token()) ?>;</script>
    <?php if (isset($pageHead)) echo $pageHead; ?>
</head>
<body<?= !empty($brand['dark_mode_enabled']) ? ' class="dark-mode"' : '' ?>>
<div class="d-flex">
    <!-- Sidebar -->
    <aside id="sb" class="ars-sidebar no-print d-flex flex-column">
        <div class="ars-sidebar-brand">
            <?php if ($logoSrc = brand_logo_src($brand)): ?>
                <img src="<?= h($logoSrc) ?>" alt="<?= h($brand['system_name']) ?>" class="ars-logo">
            <?php else: ?>
                <span class="ars-logo-icon"><i class="bi bi-house-heart-fill"></i></span>
            <?php endif; ?>
            <span class="ars-brand-text">ARS Rentals</span>
        </div>
        <div class="ars-collapse-btn" id="sbToggle" title="Collapse/Expand"><i class="bi bi-chevron-left"></i></div>

        <?php require_once dirname(__DIR__, 3) . '/includes/nav_search.php'; ?>
        <?= nav_search_box() ?>

        <nav class="ars-nav flex-grow-1">
            <?php if ($hasArsCore): ?>
            <div class="ars-nav-section">
                <span class="ars-nav-section-label">Management</span>
            </div>
            <a href="index.php" class="ars-nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-grid-1x2-fill"></i></span>
                <span class="ars-nav-label">Dashboard</span>
            </a>
            <a href="calendar.php" class="ars-nav-link <?= $currentPage === 'calendar.php' ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-calendar3"></i></span>
                <span class="ars-nav-label">Calendar</span>
            </a>
            <a href="bookings.php" class="ars-nav-link <?= in_array($currentPage, ['bookings.php','booking_add.php','booking_view.php']) ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-journal-bookmark-fill"></i></span>
                <span class="ars-nav-label">Bookings</span>
            </a>
            <a href="units.php" class="ars-nav-link <?= in_array($currentPage, ['units.php','unit_edit.php','unit_profile.php','unit_history_search.php']) ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-door-open-fill"></i></span>
                <span class="ars-nav-label">Units</span>
            </a>
            <a href="guests.php" class="ars-nav-link <?= in_array($currentPage, ['guests.php','guest_view.php']) ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-person-vcard-fill"></i></span>
                <span class="ars-nav-label">Guests</span>
            </a>
            <a href="pricing.php" class="ars-nav-link <?= $currentPage === 'pricing.php' ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-tags-fill"></i></span>
                <span class="ars-nav-label">Pricing</span>
            </a>
            <?php endif; ?>

            <?php if ($hasArsOps): ?>
            <div class="ars-nav-section">
                <span class="ars-nav-section-label">Operations</span>
            </div>
            <a href="housekeeping.php" class="ars-nav-link <?= $currentPage === 'housekeeping.php' ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-stars"></i></span>
                <span class="ars-nav-label">Housekeeping</span>
            </a>
            <a href="blocked_dates.php" class="ars-nav-link <?= $currentPage === 'blocked_dates.php' ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-calendar-x"></i></span>
                <span class="ars-nav-label">Blocked Dates</span>
            </a>
            <a href="maintenance.php" class="ars-nav-link <?= $currentPage === 'maintenance.php' ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-wrench-adjustable"></i></span>
                <span class="ars-nav-label">Maintenance</span>
            </a>
            <?php endif; ?>

            <?php if ($hasArsCore): ?>
            <div class="ars-nav-section">
                <span class="ars-nav-section-label">Finance</span>
            </div>
            <a href="revenue.php" class="ars-nav-link <?= $currentPage === 'revenue.php' ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-graph-up-arrow"></i></span>
                <span class="ars-nav-label">Revenue</span>
            </a>
            <a href="expenses.php" class="ars-nav-link <?= in_array($currentPage, ['expenses.php', 'expense_add.php', 'expense_edit.php'], true) ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-receipt-cutoff"></i></span>
                <span class="ars-nav-label">ERP Expenses</span>
            </a>
            <a href="chart_of_accounts.php" class="ars-nav-link <?= $currentPage === 'chart_of_accounts.php' ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-list-columns-reverse"></i></span>
                <span class="ars-nav-label">Chart of Accounts</span>
            </a>
            <a href="accounting/" class="ars-nav-link <?= strpos($_SERVER['PHP_SELF'], '/accounting/') !== false ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-calculator-fill"></i></span>
                <span class="ars-nav-label">Accounting</span>
            </a>
            <?php endif; ?>

            <?php if ($hasArsCore): ?>
            <div class="ars-nav-section">
                <span class="ars-nav-section-label">Settings</span>
            </div>
            <a href="settings.php" class="ars-nav-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                <span class="ars-nav-icon"><i class="bi bi-gear-fill"></i></span>
                <span class="ars-nav-label">Settings</span>
            </a>
            <?php endif; ?>

            <?php if (isset($conn) && $conn instanceof PDO && tasks_nav_user_can_access($conn)): ?>
            <a href="<?= h($appBase) ?>/modules/tasks/tasks.php" class="ars-nav-link">
                <span class="ars-nav-icon"><i class="bi bi-list-check"></i></span>
                <span class="ars-nav-label">Tasks</span>
            </a>
            <?php endif; ?>

            <?php
            if (!function_exists('get_user_companies')) {
                require_once dirname(__DIR__, 3) . '/includes/company_helper.php';
            }
            $userId = function_exists('current_user_id') ? current_user_id() : null;
            $userCompanies = ($userId && function_exists('get_user_companies')) ? get_user_companies($conn, $userId) : [];
            if (count($userCompanies) > 1): ?>
            <div class="ars-nav-section">
                <span class="ars-nav-section-label">Switch</span>
            </div>
            <a href="<?= h($appBase) ?>/select-module" class="ars-nav-link ars-nav-switch">
                <span class="ars-nav-icon"><i class="bi bi-arrow-left-right"></i></span>
                <span class="ars-nav-label">Switch Module</span>
            </a>
            <?php endif; ?>
        </nav>
    </aside>

    <!-- Mobile Sidebar Backdrop -->
    <div id="sbBackdrop" class="ars-sidebar-backdrop no-print"></div>

    <!-- Main Content -->
    <div class="flex-grow-1 ars-main-wrapper">
        <!-- Top Navbar -->
        <nav class="navbar navbar-expand navbar-light ars-topbar no-print">
            <div class="container-fluid">
                <button class="btn btn-link text-dark d-lg-none me-2 p-1 ars-hamburger" id="sbHamburger" type="button" aria-label="Toggle sidebar"><i class="bi bi-list fs-4"></i></button>
                <span class="navbar-brand fw-bold ars-topbar-brand">
                    <i class="bi bi-house-heart-fill me-2"></i>ARS Home Rentals
                </span>
                <div class="dropdown ms-auto">
                    <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle ars-user-dropdown" data-bs-toggle="dropdown">
                        <div class="ars-avatar me-2"><?= h($avatarInitial) ?></div>
                        <span class="me-2 d-none d-sm-inline"><?= h($fullName) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><span class="dropdown-item-text"><strong><?= h($userName) ?></strong></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/profile"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="/settings"><i class="bi bi-sliders me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item text-danger" href="/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Page Content -->
        <div class="container-fluid ars-content my-4 px-3 px-md-4">
