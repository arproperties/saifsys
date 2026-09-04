<?php
/**
 * ARS Customer Portal — Layout Header
 * Include after requiring portal_auth.php and setting $pageTitle
 */

if (!isset($brand)) {
    require_once __DIR__ . '/../../includes/branding.php';
    $brand = getBrandSettings($conn);
}

$_loggedIn = is_portal_guest();
$_displayName = current_portal_display_name();
$_baseUrl = portal_base_url();
$_currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' — ' : '' ?>ARS Home Rentals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= $_baseUrl ?>/assets/portal_styles.css?v=<?= @filemtime(__DIR__ . '/../assets/portal_styles.css') ?: time() ?>" rel="stylesheet">
    <?php if (isset($pageHead)) echo $pageHead; ?>
</head>
<body>
<!-- Navbar -->
<nav class="portal-navbar navbar navbar-expand-md navbar-light bg-white shadow-sm sticky-top">
    <div class="container">
        <a class="navbar-brand portal-brand" href="<?= $_baseUrl ?>/">
            <i class="bi bi-house-heart-fill me-2"></i>ARS Rentals
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#portalNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="portalNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $_currentPage === 'index.php' ? 'active' : '' ?>" href="<?= $_baseUrl ?>/"><i class="bi bi-grid me-1"></i>Browse</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $_currentPage === 'search.php' ? 'active' : '' ?>" href="<?= $_baseUrl ?>/search.php"><i class="bi bi-search me-1"></i>Search</a>
                </li>
            </ul>
            <ul class="navbar-nav ms-auto">
                <?php if ($_loggedIn): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i><?= h($_displayName) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item" href="<?= $_baseUrl ?>/dashboard.php"><i class="bi bi-grid-1x2 me-2"></i>Dashboard</a></li>
                        <li><a class="dropdown-item" href="<?= $_baseUrl ?>/profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= $_baseUrl ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </li>
                <?php else: ?>
                <li class="nav-item"><a class="nav-link" href="<?= $_baseUrl ?>/login.php"><i class="bi bi-box-arrow-in-right me-1"></i>Login</a></li>
                <li class="nav-item"><a class="btn btn-portal btn-sm ms-2 mt-1 mt-md-0" href="<?= $_baseUrl ?>/register.php">Sign Up</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Main Content -->
<main class="portal-main">
