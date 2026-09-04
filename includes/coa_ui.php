<?php
/**
 * Layout for Chart of Accounts page when opened from Construction or ARS wrappers.
 * Define COA_PAGE_LAYOUT as 'construction' or 'ars' before including
 * modules/realestate/accounting/chart_of_accounts.php
 */
function coa_resolve_layout(): string {
    if (defined('COA_PAGE_LAYOUT') && (string) constant('COA_PAGE_LAYOUT') !== '') {
        return (string) constant('COA_PAGE_LAYOUT');
    }
    return 'realestate';
}

function coa_require_header(string $layout): void {
    global $conn, $brand;
    $base = dirname(__DIR__);
    if ($layout === 'construction') {
        require_once $base . '/modules/construction/includes/construction_layout_header.php';
    } elseif ($layout === 'ars') {
        require_once $base . '/modules/ars/includes/ars_layout_header.php';
    } else {
        require_once $base . '/modules/realestate/includes/re_layout_header.php';
    }
}

function coa_require_footer(string $layout): void {
    global $conn, $brand;
    $base = dirname(__DIR__);
    if ($layout === 'construction') {
        require_once $base . '/modules/construction/includes/construction_layout_footer.php';
    } elseif ($layout === 'ars') {
        require_once $base . '/modules/ars/includes/ars_layout_footer.php';
    } else {
        require_once $base . '/modules/realestate/includes/re_layout_footer.php';
    }
}
