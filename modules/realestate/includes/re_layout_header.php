<?php
/**
 * Real Estate Layout Header - Shared sidebar and top navigation for all Real Estate pages
 * Include this at the start of each Real Estate page after requiring auth/db
 */

// Align session company to a Real Estate business before any UI/company-scoped chrome
if (isset($conn) && $conn instanceof PDO) {
    require_once dirname(__DIR__, 3) . '/includes/module_access.php';
    if (function_exists('ensure_current_company_supports_module')) {
        ensure_current_company_supports_module($conn, MODULE_REALESTATE);
    }
}

// Load branding if not already loaded
if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}
require_once dirname(__DIR__, 3) . '/includes/tasks_nav_helper.php';

// Get current user info
$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$hasLegacy     = !empty($_SESSION['username']) || !empty($_SESSION['fullname']);
$U = $hasUserObject ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username']  ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Determine active page
$currentPage = basename($_SERVER['PHP_SELF']);
$appBase = (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';

// Determine base path for navigation links (handle accounting subdirectory)
$isInAccounting = strpos($_SERVER['PHP_SELF'], '/accounting/') !== false;
$navBasePath = $isInAccounting ? '../' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' - Real Estate | ' : 'Real Estate | ' ?><?= h($brand['system_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: <?= $brand['primary_color'] ?>;
            --primary-light: <?= $brand['primary_light'] ?>;
            --primary-dark: <?= $brand['primary_dark'] ?>;
            --accent: <?= $brand['accent_color'] ?>;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.08);
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Sidebar */
        .sidebar {
            min-height: 100vh;
            max-height: 100vh;
            width: 240px;
            background: linear-gradient(180deg, var(--primary), var(--primary-light));
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 1030;
            transition: width 0.2s ease;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .sidebar.collapsed { width: 84px; }
        .brand {
            font-weight: 800;
            letter-spacing: 0.3px;
            color: var(--accent);
            opacity: 0.95;
        }
        .nav-sect {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #ffefc4;
            opacity: 0.7;
            margin: 0.5rem 0 0.25rem 0.75rem;
        }
        /* Collapsible nav groups */
        .nav-group { margin-bottom: 0.25rem; }
        .nav-sect-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 0.4rem 0.75rem;
            margin: 0.15rem 0.25rem 0 0.5rem;
            border: none;
            border-radius: 10px;
            background: transparent;
            color: #ffefc4;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            cursor: pointer;
            transition: background 0.2s;
        }
        .nav-sect-toggle:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }
        .nav-sect-toggle .nav-chevron {
            font-size: 0.9rem;
            transition: transform 0.2s ease;
        }
        .nav-sect-toggle[aria-expanded="false"] .nav-chevron {
            transform: rotate(-90deg);
        }
        .nav-group .collapse .slink { margin-left: 0; }
        .sidebar.collapsed .nav-sect-toggle .nav-sect-text,
        .sidebar.collapsed .nav-chevron { display: none !important; }
        .sidebar.collapsed .nav-sect-toggle { padding: 0.5rem; justify-content: center; }
        .slink {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 0.9rem;
            margin: 0.15rem 0.5rem;
            border-radius: 10px;
            color: #fff;
            text-decoration: none;
            transition: all 0.2s;
        }
        .slink:hover {
            background: rgba(255,255,255,0.08);
            transform: translateX(2px);
        }
        .slink.active {
            background: rgba(255,255,255,0.16);
        }
        .sicon {
            width: 28px;
            height: 28px;
            display: grid;
            place-items: center;
            background: rgba(255,255,255,0.15);
            border-radius: 8px;
        }
        .slabel { white-space: nowrap; }
        .sidebar.collapsed .slabel { display: none; }
        .sidebar.collapsed .nav-sect { display: none; }
        .collapse-btn {
            position: absolute;
            right: -12px;
            top: 12px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #fff;
            color: var(--primary);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
            display: grid;
            place-items: center;
            cursor: pointer;
        }
        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #eee;
            display: grid;
            place-items: center;
            font-weight: 700;
            color: var(--primary);
        }
        
        /* Cards */
        .card-round {
            border: 0;
            border-radius: 16px;
            box-shadow: var(--shadow);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card-round:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .page-header-label {
            font-size: 1.85rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        /* Dark Mode */
        body.dark-mode {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #e4e4e7;
        }
        body.dark-mode .sidebar {
            background: linear-gradient(180deg, var(--primary-dark), var(--primary));
        }
        body.dark-mode .navbar {
            background: rgba(30, 41, 59, 0.95) !important;
            border-bottom: 1px solid #334155;
        }
        body.dark-mode .navbar-brand {
            color: #e4e4e7 !important;
        }
        body.dark-mode .card-round {
            background: #1e293b;
            color: #e4e4e7;
            border-color: #334155;
        }
        body.dark-mode .page-header-label {
            color: var(--accent);
        }
        body.dark-mode .text-dark {
            color: #e4e4e7 !important;
        }
        
        /* Print: hide nav/sidebar and no-print elements; clean report layout */
        @media print {
            .sidebar,
            .navbar,
            .no-print {
                display: none !important;
            }
            body {
                background: #fff !important;
                min-height: auto !important;
            }
            .flex-grow-1 {
                flex: none !important;
            }
            .container {
                max-width: 100% !important;
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
            }
            .card-round {
                box-shadow: none !important;
                border: 1px solid #dee2e6 !important;
                break-inside: avoid;
            }
            .card-round:hover {
                transform: none !important;
            }
            table {
                break-inside: auto;
            }
            tr {
                break-inside: avoid;
                break-after: auto;
            }
            thead {
                display: table-header-group;
            }
            a[href]:not(.no-print)::after {
                content: none !important;
            }
        }
        
        <?php if (isset($pageStyles)) echo $pageStyles; ?>
        <?php
        if (!empty($reApUiEnhanced)) {
            require_once __DIR__ . '/re_ap_ui_assets.php';
            re_ap_ui_assets_head();
        }
        ?>
    </style>
    <?php if (isset($pageHead)) echo $pageHead; ?>
</head>
<body<?= $brand['dark_mode_enabled'] ? ' class="dark-mode"' : '' ?>>
<div class="d-flex">
    <!-- Sidebar -->
    <aside id="sb" class="sidebar no-print d-flex flex-column p-3">
        <div class="d-flex align-items-center gap-2 mb-2">
            <?php if ($logoSrc = brand_logo_src($brand)): ?>
                <img src="<?= h($logoSrc) ?>" alt="<?= h($brand['system_name']) ?>" style="width: 40px; height: 40px; object-fit: contain; border-radius: 8px;">
            <?php endif; ?>
            <span class="brand ms-1"><?= h($brand['system_name']) ?></span>
        </div>
        <div class="collapse-btn" id="sbToggle" title="Collapse/Expand"><i class="bi bi-chevron-left"></i></div>

        <?php
        require_once __DIR__ . '/../../../includes/rbac_department.php';
        $hasCore = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn);
        $hasFinancial = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn);
        $hasMaintenance = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_MAINTENANCE, $conn);
        $hasOperations = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_OPERATIONS, $conn);
        $hasCompliance = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_COMPLIANCE, $conn);
        $isOwnerUser = function_exists('has_role') ? has_role('Owner', $conn) : false;
        ?>
        
        <?php
        $corePages = ['index.php','buildings.php','units.php','tenants.php','leases.php','deleted_leases.php','lease_expiry_reminders.php','lease_renewal_workflow.php','lease_renewal_workflow_view.php','lease_templates.php','tenant_portal_approvals.php','unit_viewing_requests.php','unit_lease_applications.php','customer_push_notifications.php','tenant_communication_center.php'];
        $coreActive = in_array($currentPage, $corePages);
        if ($hasCore): ?>
        <div class="nav-group mt-3" data-nav-id="core">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-core" aria-expanded="<?= $coreActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Core Management</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $coreActive ? 'show' : '' ?>" id="nav-core">
                <a href="<?= $navBasePath ?>index.php" class="slink <?= $currentPage === 'index.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-speedometer2"></i></span><span class="slabel">Dashboard</span></a>
                <a href="<?= $navBasePath ?>buildings.php" class="slink <?= $currentPage === 'buildings.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-building"></i></span><span class="slabel">Buildings</span></a>
                <a href="<?= $navBasePath ?>units.php" class="slink <?= $currentPage === 'units.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-door-open"></i></span><span class="slabel">Units</span></a>
                <a href="<?= $navBasePath ?>tenants.php" class="slink <?= $currentPage === 'tenants.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-people"></i></span><span class="slabel">Tenants</span></a>
                <a href="<?= $navBasePath ?>leases.php" class="slink <?= in_array($currentPage, ['leases.php', 'deleted_leases.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-text"></i></span><span class="slabel">Leases</span></a>
                <a href="<?= $navBasePath ?>lease_expiry_reminders.php" class="slink <?= in_array($currentPage, ['lease_expiry_reminders.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-bell"></i></span><span class="slabel">Expiry Reminders</span></a>
                <a href="<?= $navBasePath ?>lease_renewal_workflow.php" class="slink <?= in_array($currentPage, ['lease_renewal_workflow.php', 'lease_renewal_workflow_view.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-arrow-repeat"></i></span><span class="slabel">Renewal Workflow</span></a>
                <a href="<?= $navBasePath ?>tenant_communication_center.php" class="slink <?= in_array($currentPage, ['tenant_communication_center.php', 'customer_push_notifications.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-megaphone"></i></span><span class="slabel">Communication Center</span></a>
                <a href="<?= $navBasePath ?>unit_viewing_requests.php" class="slink <?= $currentPage === 'unit_viewing_requests.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-calendar-check"></i></span><span class="slabel">Viewing Requests</span></a>
                <a href="<?= $navBasePath ?>unit_lease_applications.php" class="slink <?= $currentPage === 'unit_lease_applications.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-person"></i></span><span class="slabel">Lease Applications</span></a>
                <a href="<?= $navBasePath ?>lease_templates.php" class="slink <?= $currentPage === 'lease_templates.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-text"></i></span><span class="slabel">Contract Templates</span></a>
                <a href="<?= $navBasePath ?>tenant_portal_approvals.php" class="slink <?= $currentPage === 'tenant_portal_approvals.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-person-badge"></i></span><span class="slabel">Tenant Portal</span></a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($conn) && $conn instanceof PDO && tasks_nav_user_can_access($conn)): ?>
        <a href="<?= h($appBase) ?>/modules/tasks/tasks.php" class="slink <?= strpos($_SERVER['PHP_SELF'], '/modules/tasks/') !== false ? 'active' : '' ?>">
            <span class="sicon"><i class="bi bi-list-check"></i></span>
            <span class="slabel">Shared Tasks</span>
        </a>
        <?php endif; ?>

        <?php
        $financialPages = ['payments.php','billing.php','collections.php','cash_verification.php','expenses.php','expense_add.php','expense_edit.php'];
        $financialActive = in_array($currentPage, $financialPages);
        $accountingActive = $isInAccounting;
        if ($hasFinancial): ?>
        <div class="nav-group" data-nav-id="financial">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-financial" aria-expanded="<?= $financialActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Financial</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $financialActive ? 'show' : '' ?>" id="nav-financial">
                <a href="<?= $navBasePath ?>payments.php" class="slink <?= $currentPage === 'payments.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-cash-coin"></i></span><span class="slabel">Payments</span></a>
                <a href="<?= $navBasePath ?>cash_verification.php" class="slink <?= $currentPage === 'cash_verification.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-patch-check"></i></span><span class="slabel">Cash verification</span></a>
                <a href="<?= $navBasePath ?>billing.php" class="slink <?= $currentPage === 'billing.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-receipt"></i></span><span class="slabel">Billing</span></a>
                <a href="<?= $navBasePath ?>collections.php" class="slink <?= $currentPage === 'collections.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-exclamation-triangle"></i></span><span class="slabel">Collections</span></a>
            </div>
        </div>
        <?php
        $incomePages = ['obligation_preview.php','invoice_preview.php','receipt_allocation.php','receipt_diagnostics.php'];
        $ownerIncomeRepairPages = ['income_mispost_report.php','income_reclass_sessions.php','income_reclass_session_view.php'];
        $purchasePages = ['vendors.php','vendors_add.php','vendors_view.php','vendor_agreements.php','vendor_performance.php','bill_entry_add.php','vendor_bills.php','vendor_payment_add.php','vendor_payments.php','vendor_advance_vat_documents.php','vendor_advance_vat_document_edit.php','vendor_advance_vat_document_view.php','vendor_advance_refunds.php','vendor_advance_refund_add.php','vendor_advance_refund_view.php','ap_aging.php','vendor_ledger.php','vendor_statement.php','building_expense_report.php','vendor_ap_diagnostics.php','qpe_ap_duplicate_finder.php','vendor_recurring_bills.php','expenses.php','expense_add.php','expense_edit.php'];
        $bankRecoPages = ['bank_reconciliation.php','bank_reconciliation_accounts.php','bank_reconciliation_import.php','bank_reconciliation_history.php','bank_reconciliation_match.php','bank_reconciliation_rules.php','bank_reconciliation_cash_coding.php','bank_reconciliation_settings.php','bank_reconciliation_feeds.php','bank_reconciliation_diagnostics.php','bank_reconciliation_report.php'];
        $accountingSetupPages = ['chart_of_accounts.php','journal_entry_list.php','journal_entry_add.php','journal_entry_view.php','bank_accounts.php','periods.php','period_close.php','vat_config.php','accounting_settings.php','document_settings.php','credit_note_add.php','migrate_existing_data.php'];
        // Owner income-repair pages live under /accounting/ but belong in Owner Tools, not Accounting expand
        $accountingActive = ($isInAccounting && !in_array($currentPage, $ownerIncomeRepairPages, true)) || in_array($currentPage, $purchasePages, true) || $currentPage === 'document_settings.php';
        $bankRecoActive = in_array($currentPage, $bankRecoPages, true);
        $incomeActive = in_array($currentPage, $incomePages, true);
        $purchaseActive = in_array($currentPage, $purchasePages, true);
        ?>
        <div class="nav-group" data-nav-id="accounting">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-accounting" aria-expanded="<?= $accountingActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Accounting</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $accountingActive ? 'show' : '' ?>" id="nav-accounting">
                <div class="nav-sect mt-2">Purchases &amp; Expenses</div>
                <a href="<?= $navBasePath ?>vendors.php" class="slink <?= in_array($currentPage, ['vendors.php','vendors_add.php','vendors_view.php','vendor_agreements.php','vendor_performance.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-truck"></i></span><span class="slabel">Vendors</span></a>
                <a href="<?= $navBasePath ?>accounting/vendor_bills.php" class="slink <?= in_array($currentPage, ['vendor_bills.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-text"></i></span><span class="slabel">Vendor Bills</span></a>
                <a href="<?= $navBasePath ?>accounting/bill_entry_add.php" class="slink <?= in_array($currentPage, ['bill_entry_add.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-plus"></i></span><span class="slabel">New Bill</span></a>
                <a href="<?= $navBasePath ?>accounting/vendor_payments.php" class="slink <?= in_array($currentPage, ['vendor_payments.php','vendor_payment_add.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-cash-stack"></i></span><span class="slabel">Payments Made</span></a>
                <a href="<?= $navBasePath ?>accounting/vendor_advance_vat_documents.php" class="slink <?= in_array($currentPage, ['vendor_advance_vat_documents.php','vendor_advance_vat_document_edit.php','vendor_advance_vat_document_view.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-receipt"></i></span><span class="slabel">Advance VAT Docs</span></a>
                <a href="<?= $navBasePath ?>accounting/vendor_advance_refunds.php" class="slink <?= in_array($currentPage, ['vendor_advance_refunds.php','vendor_advance_refund_add.php','vendor_advance_refund_view.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-arrow-counterclockwise"></i></span><span class="slabel">Advance Refunds</span></a>
                <a href="<?= $navBasePath ?>accounting/ap_aging.php" class="slink <?= in_array($currentPage, ['ap_aging.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-clock-history"></i></span><span class="slabel">AP Aging</span></a>
                <a href="<?= $navBasePath ?>accounting/vendor_ledger.php" class="slink <?= in_array($currentPage, ['vendor_ledger.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-journal-bookmark"></i></span><span class="slabel">Vendor Ledger</span></a>
                <a href="<?= $navBasePath ?>accounting/vendor_statement.php" class="slink <?= in_array($currentPage, ['vendor_statement.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-ruled"></i></span><span class="slabel">Vendor SOA</span></a>
                <a href="<?= $navBasePath ?>accounting/building_expense_report.php" class="slink <?= in_array($currentPage, ['building_expense_report.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-building"></i></span><span class="slabel">Building Expenses</span></a>
                <a href="<?= $navBasePath ?>accounting/vendor_ap_diagnostics.php" class="slink <?= in_array($currentPage, ['vendor_ap_diagnostics.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-clipboard-data"></i></span><span class="slabel">Vendor/AP Diagnostics</span></a>
                <a href="<?= $navBasePath ?>accounting/qpe_ap_duplicate_finder.php" class="slink <?= in_array($currentPage, ['qpe_ap_duplicate_finder.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-intersect"></i></span><span class="slabel">QPE/AP Duplicates</span></a>
                <a href="<?= $navBasePath ?>accounting/vendor_recurring_bills.php" class="slink <?= in_array($currentPage, ['vendor_recurring_bills.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-arrow-repeat"></i></span><span class="slabel">Recurring Bills</span></a>
                <a href="<?= $navBasePath ?>expenses.php" class="slink <?= in_array($currentPage, ['expenses.php','expense_add.php','expense_edit.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-receipt-cutoff"></i></span><span class="slabel">Quick Paid Expenses</span></a>

                <div class="nav-sect mt-3">Income</div>
                <a href="<?= $navBasePath ?>accounting/obligation_preview.php" class="slink <?= in_array($currentPage, ['obligation_preview.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-diagram-3"></i></span><span class="slabel">Obligation Preview</span></a>
                <a href="<?= $navBasePath ?>accounting/invoice_preview.php" class="slink <?= in_array($currentPage, ['invoice_preview.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-text"></i></span><span class="slabel">Invoice Preview</span></a>
                <a href="<?= $navBasePath ?>accounting/receipt_allocation.php" class="slink <?= in_array($currentPage, ['receipt_allocation.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-cash-coin"></i></span><span class="slabel">Receipt Allocation</span></a>
                <a href="<?= $navBasePath ?>accounting/receipt_diagnostics.php" class="slink <?= in_array($currentPage, ['receipt_diagnostics.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-clipboard-data"></i></span><span class="slabel">Receipt Diagnostics</span></a>

                <div class="nav-sect mt-3">Setup &amp; Controls</div>
                <a href="<?= $navBasePath ?>accounting/chart_of_accounts.php" class="slink <?= in_array($currentPage, ['chart_of_accounts.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-list-columns-reverse"></i></span><span class="slabel">Chart of Accounts</span></a>
                <a href="<?= $navBasePath ?>accounting/journal_entry_list.php" class="slink <?= in_array($currentPage, ['journal_entry_list.php', 'journal_entry_add.php', 'journal_entry_view.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-journal-text"></i></span><span class="slabel">Journal Entries</span></a>
                <a href="<?= $navBasePath ?>accounting/bank_accounts.php" class="slink <?= in_array($currentPage, ['bank_accounts.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-bank2"></i></span><span class="slabel">Bank Accounts</span></a>

                <div class="nav-group mt-2 ms-1" data-nav-id="bank-reco">
                    <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-bank-reco" aria-expanded="<?= $bankRecoActive ? 'true' : 'false' ?>">
                        <span class="nav-sect-text">Bank Reconciliations</span>
                        <i class="bi bi-chevron-down nav-chevron"></i>
                    </button>
                    <div class="collapse <?= $bankRecoActive ? 'show' : '' ?>" id="nav-bank-reco">
                        <a href="<?= $navBasePath ?>accounting/bank_reconciliation_accounts.php" class="slink <?= in_array($currentPage, ['bank_reconciliation_accounts.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-grid-1x2"></i></span><span class="slabel">Dashboard</span></a>
                        <a href="<?= $navBasePath ?>accounting/bank_reconciliation.php" class="slink <?= in_array($currentPage, ['bank_reconciliation.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-bank"></i></span><span class="slabel">Reconcile</span></a>
                        <a href="<?= $navBasePath ?>accounting/bank_reconciliation_import.php" class="slink <?= in_array($currentPage, ['bank_reconciliation_import.php', 'bank_reconciliation_match.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-arrow-up"></i></span><span class="slabel">Statement Import</span></a>
                        <a href="<?= $navBasePath ?>accounting/bank_reconciliation_cash_coding.php" class="slink <?= $currentPage === 'bank_reconciliation_cash_coding.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-table"></i></span><span class="slabel">Cash Coding</span></a>
                        <a href="<?= $navBasePath ?>accounting/bank_reconciliation_rules.php" class="slink <?= $currentPage === 'bank_reconciliation_rules.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-sliders"></i></span><span class="slabel">Bank Rules</span></a>
                        <a href="<?= $navBasePath ?>accounting/bank_reconciliation_report.php" class="slink <?= in_array($currentPage, ['bank_reconciliation_report.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-bar-graph"></i></span><span class="slabel">Report</span></a>
                        <a href="<?= $navBasePath ?>accounting/bank_reconciliation_history.php" class="slink <?= in_array($currentPage, ['bank_reconciliation_history.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-clock-history"></i></span><span class="slabel">History</span></a>
                        <a href="<?= $navBasePath ?>accounting/bank_reconciliation_settings.php" class="slink <?= $currentPage === 'bank_reconciliation_settings.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-gear"></i></span><span class="slabel">Settings</span></a>
                        <a href="<?= $navBasePath ?>accounting/bank_reconciliation_feeds.php" class="slink <?= $currentPage === 'bank_reconciliation_feeds.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-cloud-download"></i></span><span class="slabel">Bank Feeds</span></a>
                        <a href="<?= $navBasePath ?>accounting/bank_reconciliation_diagnostics.php" class="slink <?= in_array($currentPage, ['bank_reconciliation_diagnostics.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-clipboard-data"></i></span><span class="slabel">Diagnostics</span></a>
                    </div>
                </div>

                <a href="<?= $navBasePath ?>accounting/periods.php" class="slink <?= in_array($currentPage, ['periods.php', 'period_close.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-calendar-range"></i></span><span class="slabel">Period Management</span></a>
                <a href="<?= $navBasePath ?>accounting/vat_config.php" class="slink <?= in_array($currentPage, ['vat_config.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-receipt-cutoff"></i></span><span class="slabel">VAT Configuration</span></a>
                <a href="<?= $navBasePath ?>accounting/accounting_settings.php" class="slink <?= in_array($currentPage, ['accounting_settings.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-sliders"></i></span><span class="slabel">Accounting Settings</span></a>
                <a href="<?= $navBasePath ?>document_settings.php" class="slink <?= $currentPage === 'document_settings.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-richtext"></i></span><span class="slabel">Document Settings</span></a>
                <a href="<?= $navBasePath ?>accounting/credit_note_add.php" class="slink <?= in_array($currentPage, ['credit_note_add.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-arrow-counterclockwise"></i></span><span class="slabel">Credit Note</span></a>
                <a href="<?= $navBasePath ?>accounting/migrate_existing_data.php" class="slink <?= in_array($currentPage, ['migrate_existing_data.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-database"></i></span><span class="slabel">Migrate Data</span></a>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $maintPages = ['maintenance.php','maintenance_schedule.php','maintenance_schedule_pdf.php','maintenance_schedule_ical.php','preventive_maintenance.php','amc.php','amc_add.php','amc_view.php','amc_visits.php','amc_certificates.php','amc_alerts.php','amc_payments.php','amc_set_alert_email.php','extra_service_rates.php','extra_service_requests.php','cleaning_rates.php','cleaning_requests.php','pest_control_rates.php','pest_control_requests.php'];
        $maintActive = in_array($currentPage, $maintPages);
        if ($hasMaintenance): ?>
        <div class="nav-group" data-nav-id="maintenance">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-maintenance" aria-expanded="<?= $maintActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Maintenance</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $maintActive ? 'show' : '' ?>" id="nav-maintenance">
                <a href="<?= $navBasePath ?>maintenance.php" class="slink <?= $currentPage === 'maintenance.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-tools"></i></span><span class="slabel">Maintenance</span></a>
                <a href="<?= $navBasePath ?>maintenance_schedule.php" class="slink <?= in_array($currentPage, ['maintenance_schedule.php', 'maintenance_schedule_pdf.php', 'maintenance_schedule_ical.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-calendar3-week"></i></span><span class="slabel">Maintenance Schedule</span></a>
                <a href="<?= $navBasePath ?>extra_service_requests.php" class="slink <?= in_array($currentPage, ['extra_service_requests.php', 'extra_service_rates.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-plus-circle"></i></span><span class="slabel">Tenant extra services</span></a>
                <a href="<?= $navBasePath ?>preventive_maintenance.php" class="slink <?= $currentPage === 'preventive_maintenance.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-calendar-check"></i></span><span class="slabel">Preventive Maint.</span></a>
                <a href="<?= $navBasePath ?>amc.php" class="slink <?= in_array($currentPage, ['amc.php', 'amc_add.php', 'amc_view.php', 'amc_visits.php', 'amc_certificates.php', 'amc_alerts.php', 'amc_payments.php', 'amc_set_alert_email.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-check"></i></span><span class="slabel">AMC Contracts</span></a>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $opsPages = ['move_in.php','move_out.php','tasks.php','reminders.php','cash_payment_requests.php','cashier_session.php','bulk_tenant_email.php','bulk_tenant_email_preview.php','bulk_tenant_email_history.php','bulk_tenant_email_view.php'];
        $opsActive = in_array($currentPage, $opsPages);
        if ($hasOperations): ?>
        <div class="nav-group" data-nav-id="operations">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-operations" aria-expanded="<?= $opsActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Operations</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $opsActive ? 'show' : '' ?>" id="nav-operations">
                <a href="<?= $navBasePath ?>cash_payment_requests.php" class="slink <?= in_array($currentPage, ['cash_payment_requests.php']) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-cash-coin"></i></span><span class="slabel">Cash payment requests</span></a>
                <a href="<?= $navBasePath ?>cashier_session.php" class="slink <?= $currentPage === 'cashier_session.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-box"></i></span><span class="slabel">Cashier session</span></a>
                <a href="<?= $navBasePath ?>move_in.php" class="slink <?= $currentPage === 'move_in.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-box-arrow-in-right"></i></span><span class="slabel">Move-Ins</span></a>
                <a href="<?= $navBasePath ?>move_out.php" class="slink <?= $currentPage === 'move_out.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-box-arrow-right"></i></span><span class="slabel">Move-Outs</span></a>
                <a href="<?= $navBasePath ?>tasks.php" class="slink <?= $currentPage === 'tasks.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-list-check"></i></span><span class="slabel">Tasks</span></a>
                <a href="<?= $navBasePath ?>reminders.php" class="slink <?= $currentPage === 'reminders.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-bell"></i></span><span class="slabel">Reminders</span></a>
                <a href="<?= $navBasePath ?>bulk_tenant_email.php" class="slink <?= in_array($currentPage, ['bulk_tenant_email.php','bulk_tenant_email_preview.php','bulk_tenant_email_history.php','bulk_tenant_email_view.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-envelope-paper"></i></span><span class="slabel">Bulk Tenant Email</span></a>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $complPages = ['compliance.php','documents.php','reports.php'];
        $complActive = in_array($currentPage, $complPages);
        if ($hasCompliance): ?>
        <div class="nav-group" data-nav-id="compliance">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-compliance" aria-expanded="<?= $complActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Compliance & Reports</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $complActive ? 'show' : '' ?>" id="nav-compliance">
                <a href="<?= $navBasePath ?>compliance.php" class="slink <?= $currentPage === 'compliance.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-shield-check"></i></span><span class="slabel">Compliance</span></a>
                <a href="<?= $navBasePath ?>documents.php" class="slink <?= $currentPage === 'documents.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-folder"></i></span><span class="slabel">Documents</span></a>
                <a href="<?= $navBasePath ?>reports.php" class="slink <?= $currentPage === 'reports.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-graph-up"></i></span><span class="slabel">Reports</span></a>
            </div>
        </div>
        <?php endif; ?>

        <?php
        // Switch Module — show when user has access to more than one company/module
        if (!function_exists('get_user_companies')) {
            require_once dirname(__DIR__, 2) . '/includes/company_helper.php';
        }
        $userId = function_exists('current_user_id') ? current_user_id() : null;
        $userCompanies = ($userId && function_exists('get_user_companies')) ? get_user_companies($conn, $userId) : [];
        $hasMultipleCompanies = count($userCompanies) > 1;
        if ($hasMultipleCompanies): ?>
        <div class="nav-sect mt-3">Switch Module</div>
        <a href="<?= h($appBase) ?>/select-module" class="slink" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);">
            <span class="sicon"><i class="bi bi-arrow-left-right"></i></span>
            <span class="slabel">Switch Module</span>
        </a>
        <?php endif; ?>

        <div class="nav-group" data-nav-id="settings">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-settings" aria-expanded="false">
                <span class="nav-sect-text">Settings</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse" id="nav-settings">
                <a href="<?= h($appBase) ?>/settings" class="slink"><span class="sicon"><i class="bi bi-sliders"></i></span><span class="slabel">Settings</span></a>
                <a href="<?= h($appBase) ?>/profile" class="slink"><span class="sicon"><i class="bi bi-person-circle"></i></span><span class="slabel">Profile</span></a>
            </div>
        </div>

        <?php if ($isOwnerUser): ?>
        <?php
        $ownerToolPages = [
            'installments_cleanup_admin.php',
            'orphan_accounting_cleanup.php',
            'lease_financial_reset_convert.php',
            'income_mispost_report.php',
            'income_reclass_sessions.php',
            'income_reclass_session_view.php',
        ];
        $ownerToolsActive = in_array($currentPage, $ownerToolPages, true);
        ?>
        <div class="nav-group" data-nav-id="owner-tools">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-owner-tools" aria-expanded="<?= $ownerToolsActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Owner Tools</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $ownerToolsActive ? 'show' : '' ?>" id="nav-owner-tools">
                <a href="<?= $navBasePath ?>installments_cleanup_admin.php" class="slink <?= $currentPage === 'installments_cleanup_admin.php' ? 'active' : '' ?>">
                    <span class="sicon"><i class="bi bi-wrench-adjustable-circle"></i></span>
                    <span class="slabel">Installments Cleanup</span>
                </a>
                <a href="<?= $navBasePath ?>admin/orphan_accounting_cleanup.php" class="slink <?= $currentPage === 'orphan_accounting_cleanup.php' ? 'active' : '' ?>">
                    <span class="sicon"><i class="bi bi-bandaid"></i></span>
                    <span class="slabel">Orphan GL Cleanup</span>
                </a>
                <a href="<?= $navBasePath ?>admin/lease_financial_reset_convert.php" class="slink <?= $currentPage === 'lease_financial_reset_convert.php' ? 'active' : '' ?>">
                    <span class="sicon"><i class="bi bi-arrow-repeat"></i></span>
                    <span class="slabel">Lease Financial Reset</span>
                </a>
                <a href="<?= $navBasePath ?>accounting/income_mispost_report.php" class="slink <?= $currentPage === 'income_mispost_report.php' ? 'active' : '' ?>">
                    <span class="sicon"><i class="bi bi-exclamation-diamond"></i></span>
                    <span class="slabel">Income Mispost Report</span>
                </a>
                <a href="<?= $navBasePath ?>accounting/income_reclass_sessions.php" class="slink <?= in_array($currentPage, ['income_reclass_sessions.php', 'income_reclass_session_view.php'], true) ? 'active' : '' ?>">
                    <span class="sicon"><i class="bi bi-journal-check"></i></span>
                    <span class="slabel">Income Repair Sessions</span>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content -->
    <div class="flex-grow-1">
        <!-- Top Navbar -->
        <nav class="navbar navbar-expand navbar-light bg-white shadow-sm no-print">
            <div class="container-fluid">
                <span class="navbar-brand fw-bold" style="color:var(--primary)"><?= h($brand['system_name']) ?> - Real Estate</span>
                <div class="dropdown ms-auto">
                    <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                        <div class="avatar me-2"><?= h($avatarInitial) ?></div>
                        <span class="me-2"><?= h($fullName) ?></span>
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

        <!-- Page Content (set $reLayoutFluid = true before including this header for full-width pages) -->
        <div class="<?= !empty($reLayoutFluid) ? 'container-fluid px-3 px-lg-4 px-xl-5' : 'container' ?> my-4">

<?php
// Global accounting warning flash — shown once after any failed accounting post
if (!empty($_SESSION['accounting_warning'])): ?>
<div class="alert alert-warning alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
    <div>
        <strong>Accounting Notice:</strong> <?= htmlspecialchars($_SESSION['accounting_warning'], ENT_QUOTES, 'UTF-8') ?>
        <br><small class="text-muted">The operational record is saved. Only the accounting journal needs attention.</small>
    </div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['accounting_warning']); endif; ?>

