<?php
/**
 * Tenant Portal — Shared layout header (nav + branding).
 * Include after require_tenant_login(); expects $lease (from lease query) or $pageTitle.
 */
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$moreNavScripts = ['cheques.php', 'maintenance.php', 'documents.php', 'move_out_notice.php', 'cleaning.php', 'pest_control.php', 'extra_services.php', 'renewals.php', 'renewal_detail.php'];
$isInMoreMenu = in_array($currentScript, $moreNavScripts);
$displayName = $_SESSION['tenant_portal_display_name'] ?? null;
if ($displayName === null && isset($lease) && !empty($lease)) {
    $displayName = trim(($lease['first_name'] ?? '') . ' ' . ($lease['last_name'] ?? ''));
}
$displayName = $displayName ?: 'Tenant';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0f4c75">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' — ' : '' ?>Tenant Portal</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11/font/bootstrap-icons.css" rel="stylesheet">
    <link href="includes/tenant_portal.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark portal-navbar">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php"><i class="bi bi-house-door me-2"></i>Tenant Portal</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#portalNav" aria-controls="portalNav" aria-expanded="false" aria-label="Toggle menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="portalNav">
                <ul class="navbar-nav me-auto portal-nav portal-nav-primary">
                    <li class="nav-item"><a class="nav-link <?= $currentScript === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link <?= $currentScript === 'lease.php' ? 'active' : '' ?>" href="lease.php"><i class="bi bi-file-text me-2"></i>Lease</a></li>
                    <li class="nav-item"><a class="nav-link <?= in_array($currentScript, ['renewals.php', 'renewal_detail.php'], true) ? 'active' : '' ?>" href="renewals.php"><i class="bi bi-arrow-repeat me-2"></i>Renewals</a></li>
                    <li class="nav-item"><a class="nav-link <?= $currentScript === 'payments.php' ? 'active' : '' ?>" href="payments.php"><i class="bi bi-cash-coin me-2"></i>Payments</a></li>
                    <li class="nav-item"><a class="nav-link <?= $currentScript === 'invoices.php' ? 'active' : '' ?>" href="invoices.php"><i class="bi bi-receipt me-2"></i>Invoices</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= $isInMoreMenu ? 'active' : '' ?>" href="#" id="portalMoreDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots me-2"></i>More</a>
                        <ul class="dropdown-menu dropdown-menu-end portal-dropdown" aria-labelledby="portalMoreDropdown">
                            <li><a class="dropdown-item <?= $currentScript === 'cheques.php' ? 'active' : '' ?>" href="cheques.php"><i class="bi bi-bank me-2"></i>Cheques</a></li>
                            <li><a class="dropdown-item <?= $currentScript === 'maintenance.php' ? 'active' : '' ?>" href="maintenance.php"><i class="bi bi-tools me-2"></i>Maintenance</a></li>
                            <li><a class="dropdown-item <?= $currentScript === 'documents.php' ? 'active' : '' ?>" href="documents.php"><i class="bi bi-folder2-open me-2"></i>Documents</a></li>
                            <li><a class="dropdown-item <?= $currentScript === 'move_out_notice.php' ? 'active' : '' ?>" href="move_out_notice.php"><i class="bi bi-box-arrow-right me-2"></i>Move-out notice</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item <?= $currentScript === 'cleaning.php' ? 'active' : '' ?>" href="cleaning.php"><i class="bi bi-droplet me-2"></i>Cleaning</a></li>
                            <li><a class="dropdown-item <?= $currentScript === 'pest_control.php' ? 'active' : '' ?>" href="pest_control.php"><i class="bi bi-bug me-2"></i>Pest control</a></li>
                            <li><a class="dropdown-item <?= $currentScript === 'extra_services.php' ? 'active' : '' ?>" href="extra_services.php"><i class="bi bi-plus-circle me-2"></i>Extra services</a></li>
                        </ul>
                    </li>
                </ul>
                <ul class="navbar-nav portal-nav portal-nav-user">
                    <li class="nav-item"><a class="nav-link <?= $currentScript === 'profile.php' ? 'active' : '' ?>" href="profile.php"><i class="bi bi-person-circle me-2"></i><span class="d-none d-lg-inline"><?= h($displayName) ?></span></a></li>
                    <li class="nav-item"><a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <main class="container portal-main">
