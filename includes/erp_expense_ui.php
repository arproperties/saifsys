<?php
/**
 * Shared layout resolution for ERP expense add/edit (Real Estate / Construction / ARS).
 * Optional: define ERP_EXPENSE_PAGE_LAYOUT as 'construction' or 'ars' before including
 * realestate/expense_add.php or expense_edit.php from a module wrapper.
 */
function erp_expense_resolve_layout(string $sourceModule): string {
    if (defined('ERP_EXPENSE_PAGE_LAYOUT') && (string) constant('ERP_EXPENSE_PAGE_LAYOUT') !== '') {
        return (string) constant('ERP_EXPENSE_PAGE_LAYOUT');
    }
    return match ($sourceModule) {
        'construction' => 'construction',
        'ars' => 'ars',
        default => 'realestate',
    };
}

function erp_expense_require_header(string $layout): void {
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

function erp_expense_require_footer(string $layout): void {
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

function erp_expense_chart_of_accounts_href(string $layout): string {
    return match ($layout) {
        'construction' => '../construction/chart_of_accounts.php',
        'ars' => '../ars/chart_of_accounts.php',
        default => 'accounting/chart_of_accounts.php',
    };
}
