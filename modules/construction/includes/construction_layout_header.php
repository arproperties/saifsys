<?php
/**
 * Construction Module Layout Header - Shared sidebar and top nav
 * Mobile-friendly, follows Tenant Portal patterns
 */

// Align session company to a Construction business before theme/company-scoped chrome
if (isset($conn) && $conn instanceof PDO) {
    require_once dirname(__DIR__, 3) . '/includes/module_access.php';
    if (function_exists('ensure_current_company_supports_module')) {
        ensure_current_company_supports_module($conn, MODULE_CONSTRUCTION);
    }
}

if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}

$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$U = $hasUserObject ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$currentPage = basename($_SERVER['PHP_SELF']);
$appBase = (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';
$constructionBase = $appBase . '/modules/construction';

require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
require_once dirname(__DIR__, 3) . '/includes/tasks_nav_helper.php';
$hasCore = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_CORE, $conn);
$hasProjects = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_PROJECTS, $conn);
$hasFinancial = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn);
$hasReports = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_REPORTS, $conn);
// Design system is module default; pages may set $coUiV2 = false to opt out
if (!isset($coUiV2)) {
    $coUiV2 = true;
}
$coUiV2 = !empty($coUiV2);
$coThemeCss = '';
$coThemeMode = 'light';
$coThemeResolved = [];
$coIsOwner = false;
if (function_exists('has_role') && isset($conn) && $conn instanceof PDO) {
    $coIsOwner = has_role('Owner', $conn);
}
if ($coUiV2) {
    require_once __DIR__ . '/ui/co_ui_helpers.php';
    require_once __DIR__ . '/construction_theme_helpers.php';
    if (isset($conn) && $conn instanceof PDO && function_exists('current_company_id')) {
        $coThemeCid = (int)(current_company_id($conn) ?: 0);
        $coThemeResolved = $coThemeCid > 0 ? co_theme_get($conn, $coThemeCid) : co_theme_defaults();
    } else {
        require_once dirname(__DIR__, 3) . '/includes/erp_module_theme.php';
        $coThemeResolved = erp_theme_defaults();
    }
    $coThemeCss = erp_theme_css_variables($coThemeResolved);
    $coThemeMode = erp_theme_is_light_surface($coThemeResolved['page_bg'] ?? '#f1f5f9') ? 'light' : 'dark';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' - Construction | ' : 'Construction | ' ?><?= h($brand['system_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= $constructionBase ?>/assets/construction.css?v=20260510-1548" rel="stylesheet">
    <?php if ($coUiV2): ?>
    <link href="<?= $constructionBase ?>/assets/construction-ui-v2.css?v=20260729-badges1" rel="stylesheet">
    <?php if ($coThemeCss !== ''): ?>
    <style id="co-theme-vars">body.co-ui-v2 { <?= $coThemeCss ?> }</style>
    <?php endif; ?>
    <?php endif; ?>
    <style>
        :root {
            --primary: <?= $coUiV2 ? h($coThemeResolved['primary'] ?? '#2563eb') : $brand['primary_color'] ?>;
            --primary-light: <?= $coUiV2 ? h($coThemeResolved['primary_hover'] ?? '#1d4ed8') : $brand['primary_light'] ?>;
            --primary-dark: <?= $coUiV2 ? h($coThemeResolved['primary_hover'] ?? '#1d4ed8') : $brand['primary_dark'] ?>;
            --accent: <?= $coUiV2 ? h($coThemeResolved['accent'] ?? '#f59e0b') : $brand['accent_color'] ?>;
            --success: <?= $coUiV2 ? h($coThemeResolved['success'] ?? '#16a34a') : '#28a745' ?>;
            --warning: <?= $coUiV2 ? h($coThemeResolved['warning'] ?? '#d97706') : '#ffc107' ?>;
            --danger: <?= $coUiV2 ? h($coThemeResolved['danger'] ?? '#dc2626') : '#dc3545' ?>;
            --info: <?= $coUiV2 ? h($coThemeResolved['info'] ?? '#0284c7') : '#17a2b8' ?>;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
        }
        body { background: <?= $coUiV2 ? 'var(--construction-page-bg, #f1f5f9)' : 'linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%)' ?>; min-height: 100vh; font-family: 'Segoe UI', sans-serif; }
        .nav-sublabel { font-size: 0.65rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(255,239,196,0.55); padding: 0.55rem 0.9rem 0.2rem 1rem; margin-top: 0.25rem; }
        .slink-child { margin-left: 0.25rem; }
        .co-switch-module { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); }
        .sidebar { min-height: 100vh; width: 260px; flex: 0 0 260px; background: linear-gradient(180deg, var(--primary), var(--primary-light)); color: #fff; position: sticky; top: 0; z-index: 1030; overflow-y: auto; overflow-x: hidden; }
        .co-main-wrap { min-width: 0; }
        .co-sidebar-logo { min-width: 0; overflow: visible; }
        .co-sidebar-logo-img { width: 40px; height: 40px; min-width: 40px; min-height: 40px; flex-shrink: 0; object-fit: contain; border-radius: 8px; display: block; }
        .nav-sect { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: #ffefc4; opacity: 0.7; margin: 0.5rem 0 0.25rem 0.75rem; }
        .nav-group { margin-bottom: 0.25rem; }
        .nav-sect-toggle { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 0.4rem 0.75rem; margin: 0.15rem 0.25rem 0 0.5rem; border: none; border-radius: 10px; background: transparent; color: #ffefc4; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; cursor: pointer; transition: background 0.2s; min-width: 0; text-align: left; }
        .nav-sect-toggle .nav-sect-text { white-space: normal; word-break: break-word; flex: 1; min-width: 0; }
        .nav-sect-toggle .nav-chevron { flex-shrink: 0; margin-left: 0.25rem; }
        .nav-sect-toggle:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-sect-toggle.is-active-section { background: rgba(255,255,255,0.12); color: #fff; font-weight: 700; }
        .nav-sect-toggle .nav-chevron { font-size: 0.9rem; transition: transform 0.2s ease; }
        .nav-sect-toggle[aria-expanded="false"] .nav-chevron { transform: rotate(-90deg); }
        .nav-group .collapse .slink { margin-left: 0; }
        .slink { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.9rem; margin: 0.15rem 0.5rem; border-radius: 10px; color: #fff; text-decoration: none; transition: all 0.2s; min-height: 44px; min-width: 0; }
        .slink:hover { background: rgba(255,255,255,0.08); transform: translateX(2px); }
        .slink.active { background: rgba(255,255,255,0.16); }
        .sicon { flex-shrink: 0; width: 28px; height: 28px; display: grid; place-items: center; background: rgba(255,255,255,0.15); border-radius: 8px; }
        .slabel { white-space: normal; word-break: break-word; min-width: 0; line-height: 1.3; }
        .table-responsive > table.table,
        .card-body.table-responsive > table.table {
            display: table !important;
            width: 100% !important;
            min-width: 100% !important;
        }
        .card-round { border: 0; border-radius: 16px; box-shadow: var(--shadow); }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: #eee; display: grid; place-items: center; font-weight: 700; color: var(--primary); }
        .sidebar { transition: transform 0.25s ease, box-shadow 0.25s ease; }
        @media (max-width: 767px) {
            .sidebar { position: fixed; left: 0; top: 0; height: 100vh; width: 280px; flex-basis: 280px; max-width: 88vw; z-index: 1040; transform: translateX(-100%); box-shadow: none; }
            .sidebar.mobile-open { transform: translateX(0); box-shadow: var(--shadow-lg); }
            .co-sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.35); z-index: 1035; }
            .co-sidebar-overlay.show { display: block; }
            .co-main-wrap { min-width: 0; }
        }
        @media (min-width: 768px) { .co-mobile-menu-btn { display: none !important; } }
        @media print {
            .no-print, .sidebar, .navbar, .co-sidebar-overlay { display: none !important; }
            body { background: #fff !important; }
            .co-main-wrap, .container { width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .card-round { box-shadow: none !important; border: 1px solid #ddd !important; }
        }
        <?php if (isset($pageStyles)) echo $pageStyles; ?>
    </style>
    <?php if (isset($pageHead)) echo $pageHead; ?>
</head>
<?php
$bodyClasses = [];
if (!empty($brand['dark_mode_enabled'])) $bodyClasses[] = 'dark-mode';
if ($coUiV2) $bodyClasses[] = 'co-ui-v2';
$coBodyAttrs = '';
if ($coUiV2) {
    $coBodyAttrs .= ' data-co-theme-mode="' . h($coThemeMode) . '"';
}
?>
<body<?= $bodyClasses ? ' class="' . h(implode(' ', $bodyClasses)) . '"' : '' ?><?= $coBodyAttrs ?>>
<?php
// Check-out bar for staff who have checked in. Prints nothing when the
// feature is off or the person has no attendance to record.
$asWidget = dirname(__DIR__, 3) . '/includes/attendance_self_widget.php';
if (is_file($asWidget)) { require $asWidget; }
?>
<div class="co-sidebar-overlay no-print" id="co-sidebar-overlay" aria-hidden="true"></div>
<div class="d-flex flex-column flex-md-row">
    <aside class="sidebar no-print d-flex flex-column p-3" id="co-sidebar" role="navigation">
        <div class="d-flex align-items-center gap-2 mb-2 co-sidebar-logo">
            <?php if ($logoSrc = brand_logo_src($brand)): ?>
                <img src="<?= h($logoSrc) ?>" alt="<?= h($brand['system_name']) ?>" class="co-sidebar-logo-img">
            <?php endif; ?>
            <span class="fw-bold" style="color: var(--accent)"><?= h($brand['system_name']) ?></span>
        </div>
        <small class="<?= $coUiV2 ? 'text-muted' : 'text-white-50' ?> mb-2">Construction<?= $coUiV2 ? ' · Design System' : '' ?></small>
        <?php require_once dirname(__DIR__, 3) . '/includes/nav_search.php'; ?>
        <?= nav_search_box() ?>

        <?php
        $corePages = [
            'index.php', 'projects.php', 'project_add.php', 'project_view.php', 'project_edit.php',
            'project_phases.php', 'project_phase_add.php', 'project_phase_edit.php',
            'variation_orders.php', 'variation_order_add.php', 'variation_order_edit.php',
            'project_submittals.php', 'project_submittal_add.php', 'project_submittal_edit.php',
            'project_rfis.php', 'project_rfi_add.php', 'project_rfi_edit.php',
            'project_documents.php', 'project_document_add.php', 'project_document_edit.php',
            'work_orders.php', 'work_order_add.php', 'work_order_edit.php',
            'retention_release.php', 'retention_release_add.php',
        ];
        $leasingPages = [
            'shop_units.php', 'shop_unit_add.php', 'shop_unit_edit.php',
            'shop_rental_control_center.php', 'shop_rental_contracts.php', 'shop_rental_contract_add.php',
            'shop_rental_contract_view.php', 'shop_rental_renew.php', 'shop_rental_terminate.php',
            'shop_rental_deposit_settle.php', 'shop_rental_move_out_inspection.php', 'shop_cheque_receipt_pdf.php',
        ];
        $opsPages = [
            'labor_camps.php',
            'camp_management_contracts.php', 'camp_management_contract_add.php', 'camp_management_contract_view.php',
            'maintenance_contracts.php', 'maintenance_contract_add.php', 'maintenance_contract_view.php',
        ];
        $partnersPages = [
            'contractors.php', 'contractor_add.php', 'contractor_edit.php', 'contractor_view.php',
            'clients.php', 'client_add.php', 'client_edit.php',
            'project_contractors.php', 'project_contractor_edit.php',
        ];
        $financialPages = [
            'contractor_payment_add.php',
            'contractor_payment_retirement.php',
            'project_costs.php', 'project_cost_add.php', 'project_labor.php', 'project_labor_add.php',
            'material_issues.php', 'material_issue_add.php', 'my_material_requests.php', 'material_request_create.php', 'material_request_view.php',
            'client_invoices.php', 'client_invoice_create.php', 'client_invoice_documents.php',
            'client_payments.php', 'client_payment_add.php', 'shop_rental_payment_workspace.php',
            'setup_construction_coa.php', 'document_settings.php', 'chart_of_accounts.php',
            'journal_entry_list.php', 'journal_entry_add.php', 'journal_entry_view.php',
            'suppliers.php', 'supplier_add.php', 'supplier_edit.php', 'supplier_view.php',
            'supplier_invoices.php', 'supplier_invoice_add.php', 'supplier_recurring_invoices.php',
            'supplier_invoice_view.php', 'supplier_invoice_edit.php', 'supplier_invoice_delete.php',
            'supplier_payments.php', 'supplier_payment_add.php', 'supplier_payment_view.php',
            'supplier_payment_edit.php', 'supplier_payment_delete.php',
            'supplier_advance_vat_documents.php', 'supplier_advance_vat_edit.php', 'supplier_advance_vat_view.php',
            'supplier_advance_refund_add.php', 'supplier_advance_refund_view.php',
            'expenses.php', 'expense_add.php', 'expense_edit.php', 'qpe_supplier_duplicate_finder.php',
            'supplier_documents.php', 'supplier_document_add.php', 'supplier_document_edit.php', 'supplier_document_delete.php',
            'supplier_invoice_documents.php', 'supplier_invoice_document_add.php', 'supplier_invoice_document_delete.php',
        ];
        $bankRecoPages = [
            'bank_reconciliation.php', 'bank_reconciliation_accounts.php', 'bank_reconciliation_import.php',
            'bank_reconciliation_history.php', 'bank_reconciliation_rules.php', 'bank_reconciliation_cash_coding.php',
            'bank_reconciliation_settings.php', 'bank_reconciliation_feeds.php',
        ];
        $reportsPages = [
            'profit_loss.php', 'balance_sheet.php', 'trial_balance.php', 'general_ledger.php',
            'supplier_aging.php', 'supplier_statement.php', 'supplier_ledger.php', 'supplier_project_spend.php', 'supplier_ap_diagnostics.php', 'income_by_category.php', 'income_by_customer.php',
            'receivables_aging.php', 'contract_expiry.php', 'camp_income.php',
            'shop_rental_income.php', 'shop_tenant_commission.php', 'shop_key_money.php', 'shop_lease_maturity.php', 'shop_occupancy.php',
            'shop_profitability.php', 'shop_tenant_profitability.php', 'shop_deposit_liability.php',
            'shop_deferred_analysis.php', 'shop_forecast.php', 'shop_termination_penalty.php',
            'deferred_rent_recognition.php', 'maintenance_income.php',
            'project_cost_summary.php', 'budget_vs_actual.php', 'contractor_summary.php',
            'project_profitability.php', 'project_cost_detail.php', 'construction_vat_report.php',
            'bank_reconciliation_report.php',
        ];
        $settingsPages = ['theme_settings.php'];

        $leasingActive = in_array($currentPage, $leasingPages, true);
        $opsActive = in_array($currentPage, $opsPages, true);
        $coreActive = in_array($currentPage, $corePages, true) || $leasingActive;
        $partnersActive = in_array($currentPage, $partnersPages, true);
        $financialActive = in_array($currentPage, $financialPages, true);
        $bankRecoActive = in_array($currentPage, $bankRecoPages, true);
        $reportsActive = in_array($currentPage, $reportsPages, true);
        $settingsActive = in_array($currentPage, $settingsPages, true);
        $showCoreNav = $hasCore || $hasProjects || $hasFinancial;
        $showProjectsNav = $hasCore || $hasProjects;
        $showLeasingNav = $hasFinancial || $hasCore;
        ?>

        <?php if ($showCoreNav): ?>
        <div class="nav-group mt-3" data-nav-id="core">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-core" aria-expanded="<?= $coreActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Core Management</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $coreActive ? 'show' : '' ?>" id="nav-core">
                <?php if ($showProjectsNav): ?>
                <a href="<?= $constructionBase ?>/index.php" class="slink <?= $currentPage === 'index.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-speedometer2"></i></span><span class="slabel">Dashboard</span></a>
                <a href="<?= $constructionBase ?>/projects.php" class="slink <?= in_array($currentPage, ['projects.php','project_add.php','project_view.php','project_edit.php','project_phases.php','project_phase_add.php','project_phase_edit.php','variation_orders.php','variation_order_add.php','variation_order_edit.php','project_submittals.php','project_submittal_add.php','project_submittal_edit.php','project_rfis.php','project_rfi_add.php','project_rfi_edit.php','project_documents.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-briefcase"></i></span><span class="slabel">Projects</span></a>
                <?php endif; ?>
                <?php if ($showProjectsNav || $hasFinancial): ?>
                <a href="<?= $constructionBase ?>/work_orders.php" class="slink <?= in_array($currentPage, ['work_orders.php','work_order_add.php','work_order_edit.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-clipboard-check"></i></span><span class="slabel">Work Orders</span></a>
                <a href="<?= $constructionBase ?>/retention_release.php" class="slink <?= in_array($currentPage, ['retention_release.php','retention_release_add.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-wallet2"></i></span><span class="slabel">Retention Release</span></a>
                <?php endif; ?>

                <?php if ($showLeasingNav): ?>
                <div class="nav-sublabel">Commercial Leasing</div>
                <a href="<?= $constructionBase ?>/shop_rental_control_center.php" class="slink slink-child <?= $currentPage === 'shop_rental_control_center.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-building-gear"></i></span><span class="slabel">Leasing Control Center</span></a>
                <a href="<?= $constructionBase ?>/shop_units.php" class="slink slink-child <?= in_array($currentPage, ['shop_units.php','shop_unit_add.php','shop_unit_edit.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-shop"></i></span><span class="slabel">Shop Units</span></a>
                <a href="<?= $constructionBase ?>/shop_rental_contracts.php" class="slink slink-child <?= in_array($currentPage, $leasingPages, true) && $currentPage !== 'shop_rental_control_center.php' && !in_array($currentPage, ['shop_units.php','shop_unit_add.php','shop_unit_edit.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-richtext"></i></span><span class="slabel">Shop Rental Contracts</span></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($hasFinancial || $hasCore): ?>
        <div class="nav-group" data-nav-id="operations">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-operations" aria-expanded="<?= $opsActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Operations</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $opsActive ? 'show' : '' ?>" id="nav-operations">
                <a href="<?= $constructionBase ?>/labor_camps.php" class="slink <?= $currentPage === 'labor_camps.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-building"></i></span><span class="slabel">Labor Camps</span></a>
                <a href="<?= $constructionBase ?>/camp_management_contracts.php" class="slink <?= in_array($currentPage, ['camp_management_contracts.php','camp_management_contract_add.php','camp_management_contract_view.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-house-gear"></i></span><span class="slabel">Camp Agent Agreements</span></a>
                <a href="<?= $constructionBase ?>/maintenance_contracts.php" class="slink <?= in_array($currentPage, ['maintenance_contracts.php','maintenance_contract_add.php','maintenance_contract_view.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-tools"></i></span><span class="slabel">Maintenance Contracts</span></a>
            </div>
        </div>
        <?php endif; ?>

        <div class="nav-group" data-nav-id="partners">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-partners" aria-expanded="<?= $partnersActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Partners</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $partnersActive ? 'show' : '' ?>" id="nav-partners">
                <a href="<?= $constructionBase ?>/contractors.php" class="slink <?= in_array($currentPage, ['contractors.php','contractor_add.php','contractor_edit.php','contractor_view.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-person-gear"></i></span><span class="slabel">Contractors</span></a>
                <a href="<?= $constructionBase ?>/clients.php" class="slink <?= in_array($currentPage, ['clients.php','client_add.php','client_edit.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-people"></i></span><span class="slabel">Clients</span></a>
                <a href="<?= $constructionBase ?>/project_contractors.php" class="slink <?= in_array($currentPage, ['project_contractors.php','project_contractor_edit.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-link-45deg"></i></span><span class="slabel">Project–Contractor</span></a>
            </div>
        </div>

        <?php if ($hasFinancial): ?>
        <div class="nav-group" data-nav-id="financial">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-financial" aria-expanded="<?= $financialActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Financial</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $financialActive ? 'show' : '' ?>" id="nav-financial">
                <div class="nav-sublabel">Payables</div>
                <a href="<?= $constructionBase ?>/suppliers.php" class="slink slink-child <?= in_array($currentPage, ['suppliers.php','supplier_add.php','supplier_edit.php','supplier_view.php','supplier_documents.php','supplier_document_add.php','supplier_document_edit.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-truck"></i></span><span class="slabel">Suppliers</span></a>
                <a href="<?= $constructionBase ?>/supplier_invoices.php" class="slink slink-child <?= in_array($currentPage, ['supplier_invoices.php','supplier_invoice_add.php','supplier_recurring_invoices.php','supplier_invoice_view.php','supplier_invoice_edit.php','supplier_invoice_documents.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-receipt-cutoff"></i></span><span class="slabel">Supplier Invoices</span></a>
                <a href="<?= $constructionBase ?>/supplier_payments.php" class="slink slink-child <?= in_array($currentPage, ['supplier_payments.php','supplier_payment_add.php','supplier_payment_view.php','supplier_payment_edit.php','supplier_advance_refund_add.php','supplier_advance_refund_view.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-cash-stack"></i></span><span class="slabel">Supplier Payments</span></a>
                <a href="<?= $constructionBase ?>/supplier_advance_vat_documents.php" class="slink slink-child <?= in_array($currentPage, ['supplier_advance_vat_documents.php','supplier_advance_vat_edit.php','supplier_advance_vat_view.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-receipt"></i></span><span class="slabel">Advance VAT</span></a>
                <a href="<?= $constructionBase ?>/qpe_supplier_duplicate_finder.php" class="slink slink-child <?= $currentPage === 'qpe_supplier_duplicate_finder.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-intersect"></i></span><span class="slabel">QPE/Supplier Duplicates</span></a>
                <a href="<?= $constructionBase ?>/tools/contractor_payment_retirement.php" class="slink slink-child <?= $currentPage === 'contractor_payment_retirement.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-shield-exclamation"></i></span><span class="slabel">Retire Old Contractor Payments</span></a>

                <div class="nav-sublabel">Project costs</div>
                <a href="<?= $constructionBase ?>/project_costs.php" class="slink slink-child <?= in_array($currentPage, ['project_costs.php','project_cost_add.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-currency-dollar"></i></span><span class="slabel">Project Costs</span></a>
                <a href="<?= $constructionBase ?>/project_labor.php" class="slink slink-child <?= in_array($currentPage, ['project_labor.php','project_labor_add.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-person-workspace"></i></span><span class="slabel">Labor Assignments</span></a>
                <a href="<?= $constructionBase ?>/material_issues.php" class="slink slink-child <?= in_array($currentPage, ['material_issues.php','material_issue_add.php','my_material_requests.php','material_request_create.php','material_request_view.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-box-seam"></i></span><span class="slabel">Material Issues</span></a>

                <div class="nav-sublabel">Receivables</div>
                <a href="<?= $constructionBase ?>/client_invoices.php" class="slink slink-child <?= in_array($currentPage, ['client_invoices.php','client_invoice_create.php','client_invoice_documents.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-receipt"></i></span><span class="slabel">Income Invoices</span></a>
                <a href="<?= $constructionBase ?>/client_payments.php" class="slink slink-child <?= in_array($currentPage, ['client_payments.php','client_payment_add.php','shop_rental_payment_workspace.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-cash"></i></span><span class="slabel">Client Receipts</span></a>

                <div class="nav-sublabel">Accounting</div>
                <a href="<?= $constructionBase ?>/chart_of_accounts.php" class="slink slink-child <?= $currentPage === 'chart_of_accounts.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-list-columns-reverse"></i></span><span class="slabel">Chart of Accounts</span></a>
                <a href="<?= $constructionBase ?>/journal_entry_list.php" class="slink slink-child <?= in_array($currentPage, ['journal_entry_list.php', 'journal_entry_view.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-journal-text"></i></span><span class="slabel">Journal Entries</span></a>
                <a href="<?= $constructionBase ?>/journal_entry_add.php" class="slink slink-child <?= $currentPage === 'journal_entry_add.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-journal-plus"></i></span><span class="slabel">New Journal Entry</span></a>
                <a href="<?= $constructionBase ?>/setup_construction_coa.php" class="slink slink-child <?= $currentPage === 'setup_construction_coa.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-journal-bookmark-fill"></i></span><span class="slabel">Setup Accounts</span></a>
                <a href="<?= $constructionBase ?>/document_settings.php" class="slink slink-child <?= $currentPage === 'document_settings.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-earmark-ruled"></i></span><span class="slabel">Document Settings</span></a>
                <a href="<?= $constructionBase ?>/expenses.php" class="slink slink-child <?= in_array($currentPage, ['expenses.php','expense_add.php','expense_edit.php'], true) ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-cash-coin"></i></span><span class="slabel">Quick Paid Expenses</span></a>
            </div>
        </div>

        <div class="nav-group" data-nav-id="bank">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-bank" aria-expanded="<?= $bankRecoActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Bank Reconciliation</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $bankRecoActive ? 'show' : '' ?>" id="nav-bank">
                <a href="<?= $constructionBase ?>/bank_reconciliation_accounts.php" class="slink <?= $currentPage === 'bank_reconciliation_accounts.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-grid-1x2"></i></span><span class="slabel">Bank Dashboard</span></a>
                <a href="<?= $constructionBase ?>/bank_reconciliation.php" class="slink <?= $currentPage === 'bank_reconciliation.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-check2-square"></i></span><span class="slabel">Reconcile</span></a>
                <a href="<?= $constructionBase ?>/bank_reconciliation_import.php" class="slink <?= $currentPage === 'bank_reconciliation_import.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-upload"></i></span><span class="slabel">Import Statement</span></a>
                <a href="<?= $constructionBase ?>/bank_reconciliation_history.php" class="slink <?= $currentPage === 'bank_reconciliation_history.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-clock-history"></i></span><span class="slabel">History</span></a>
                <a href="<?= $constructionBase ?>/bank_reconciliation_rules.php" class="slink <?= $currentPage === 'bank_reconciliation_rules.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-sliders"></i></span><span class="slabel">Bank Rules</span></a>
                <a href="<?= $constructionBase ?>/bank_reconciliation_cash_coding.php" class="slink <?= $currentPage === 'bank_reconciliation_cash_coding.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-table"></i></span><span class="slabel">Cash Coding</span></a>
                <a href="<?= $constructionBase ?>/bank_reconciliation_feeds.php" class="slink <?= $currentPage === 'bank_reconciliation_feeds.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-cloud-download"></i></span><span class="slabel">Bank Feeds</span></a>
                <a href="<?= $constructionBase ?>/bank_reconciliation_settings.php" class="slink <?= $currentPage === 'bank_reconciliation_settings.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-gear"></i></span><span class="slabel">Reco Settings</span></a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($hasReports): ?>
        <div class="nav-group" data-nav-id="reports">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-reports" aria-expanded="<?= $reportsActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Reports</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $reportsActive ? 'show' : '' ?>" id="nav-reports">
                <div class="nav-sublabel">Financial statements</div>
                <a href="<?= $constructionBase ?>/reports/profit_loss.php" class="slink slink-child <?= $currentPage === 'profit_loss.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-graph-up-arrow"></i></span><span class="slabel">P&amp;L</span></a>
                <a href="<?= $constructionBase ?>/reports/balance_sheet.php" class="slink slink-child <?= $currentPage === 'balance_sheet.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-bank"></i></span><span class="slabel">Balance Sheet</span></a>
                <a href="<?= $constructionBase ?>/reports/trial_balance.php" class="slink slink-child <?= $currentPage === 'trial_balance.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-columns-gap"></i></span><span class="slabel">Trial Balance</span></a>
                <a href="<?= $constructionBase ?>/reports/general_ledger.php" class="slink slink-child <?= $currentPage === 'general_ledger.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-journal-text"></i></span><span class="slabel">General Ledger</span></a>
                <a href="<?= $constructionBase ?>/construction_vat_report.php" class="slink slink-child <?= $currentPage === 'construction_vat_report.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-percent"></i></span><span class="slabel">VAT Report</span></a>

                <div class="nav-sublabel">Payables &amp; receivables</div>
                <a href="<?= $constructionBase ?>/reports/supplier_aging.php" class="slink slink-child <?= $currentPage === 'supplier_aging.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-hourglass-split"></i></span><span class="slabel">Supplier Aging</span></a>
                <a href="<?= $constructionBase ?>/reports/supplier_statement.php" class="slink slink-child <?= $currentPage === 'supplier_statement.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-file-text"></i></span><span class="slabel">Supplier Statement</span></a>
                <a href="<?= $constructionBase ?>/reports/supplier_ledger.php" class="slink slink-child <?= $currentPage === 'supplier_ledger.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-journal-text"></i></span><span class="slabel">Supplier Ledger</span></a>
                <a href="<?= $constructionBase ?>/reports/supplier_project_spend.php" class="slink slink-child <?= $currentPage === 'supplier_project_spend.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-diagram-3"></i></span><span class="slabel">Supplier Project Spend</span></a>
                <a href="<?= $constructionBase ?>/reports/supplier_ap_diagnostics.php" class="slink slink-child <?= $currentPage === 'supplier_ap_diagnostics.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-clipboard-data"></i></span><span class="slabel">Supplier AP Diagnostics</span></a>
                <a href="<?= $constructionBase ?>/reports/receivables_aging.php" class="slink slink-child <?= $currentPage === 'receivables_aging.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-hourglass"></i></span><span class="slabel">Receivables Aging</span></a>
                <a href="<?= $constructionBase ?>/reports/income_by_category.php" class="slink slink-child <?= $currentPage === 'income_by_category.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-pie-chart"></i></span><span class="slabel">Income by Category</span></a>
                <a href="<?= $constructionBase ?>/reports/income_by_customer.php" class="slink slink-child <?= $currentPage === 'income_by_customer.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-person-lines-fill"></i></span><span class="slabel">Income by Customer</span></a>

                <div class="nav-sublabel">Commercial leasing</div>
                <a href="<?= $constructionBase ?>/reports/shop_rental_income.php" class="slink slink-child <?= $currentPage === 'shop_rental_income.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-shop-window"></i></span><span class="slabel">Shop Rental Income</span></a>
                <a href="<?= $constructionBase ?>/reports/shop_tenant_commission.php" class="slink slink-child <?= $currentPage === 'shop_tenant_commission.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-percent"></i></span><span class="slabel">Tenant Commission</span></a>
                <a href="<?= $constructionBase ?>/reports/shop_key_money.php" class="slink slink-child <?= $currentPage === 'shop_key_money.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-key"></i></span><span class="slabel">Key Money Income</span></a>
                <a href="<?= $constructionBase ?>/reports/shop_lease_maturity.php" class="slink slink-child <?= $currentPage === 'shop_lease_maturity.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-calendar2-week"></i></span><span class="slabel">Lease Maturity</span></a>
                <a href="<?= $constructionBase ?>/reports/shop_occupancy.php" class="slink slink-child <?= $currentPage === 'shop_occupancy.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-grid-1x2"></i></span><span class="slabel">Occupancy / Vacancy</span></a>
                <a href="<?= $constructionBase ?>/reports/shop_profitability.php" class="slink slink-child <?= $currentPage === 'shop_profitability.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-shop"></i></span><span class="slabel">Shop Profitability</span></a>
                <a href="<?= $constructionBase ?>/reports/shop_tenant_profitability.php" class="slink slink-child <?= $currentPage === 'shop_tenant_profitability.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-person-badge"></i></span><span class="slabel">Tenant Profitability</span></a>
                <a href="<?= $constructionBase ?>/reports/shop_deposit_liability.php" class="slink slink-child <?= $currentPage === 'shop_deposit_liability.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-safe"></i></span><span class="slabel">Deposit Liability</span></a>
                <a href="<?= $constructionBase ?>/reports/shop_deferred_analysis.php" class="slink slink-child <?= $currentPage === 'shop_deferred_analysis.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-layers"></i></span><span class="slabel">Deferred Analysis</span></a>
                <a href="<?= $constructionBase ?>/reports/shop_forecast.php" class="slink slink-child <?= $currentPage === 'shop_forecast.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-graph-up"></i></span><span class="slabel">Leasing Forecast</span></a>
                <a href="<?= $constructionBase ?>/reports/shop_termination_penalty.php" class="slink slink-child <?= $currentPage === 'shop_termination_penalty.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-exclamation-diamond"></i></span><span class="slabel">Termination Penalty</span></a>
                <a href="<?= $constructionBase ?>/reports/deferred_rent_recognition.php" class="slink slink-child <?= $currentPage === 'deferred_rent_recognition.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-arrow-left-right"></i></span><span class="slabel">Deferred Rent Recognition</span></a>
                <a href="<?= $constructionBase ?>/reports/contract_expiry.php" class="slink slink-child <?= $currentPage === 'contract_expiry.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-calendar-x"></i></span><span class="slabel">Contract Expiry</span></a>

                <div class="nav-sublabel">Projects &amp; camps</div>
                <a href="<?= $constructionBase ?>/reports/project_cost_summary.php" class="slink slink-child <?= $currentPage === 'project_cost_summary.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-graph-up"></i></span><span class="slabel">Project Cost</span></a>
                <a href="<?= $constructionBase ?>/reports/budget_vs_actual.php" class="slink slink-child <?= $currentPage === 'budget_vs_actual.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-bar-chart"></i></span><span class="slabel">Budget vs Actual</span></a>
                <a href="<?= $constructionBase ?>/reports/contractor_summary.php" class="slink slink-child <?= $currentPage === 'contractor_summary.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-journal-bookmark"></i></span><span class="slabel">Contractor Summary</span></a>
                <a href="<?= $constructionBase ?>/reports/project_profitability.php" class="slink slink-child <?= $currentPage === 'project_profitability.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-cash-stack"></i></span><span class="slabel">Profitability</span></a>
                <a href="<?= $constructionBase ?>/reports/project_cost_detail.php" class="slink slink-child <?= $currentPage === 'project_cost_detail.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-search"></i></span><span class="slabel">Project Cost Detail</span></a>
                <a href="<?= $constructionBase ?>/reports/camp_income.php" class="slink slink-child <?= $currentPage === 'camp_income.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-building-check"></i></span><span class="slabel">Camp Settlements</span></a>
                <a href="<?= $constructionBase ?>/reports/maintenance_income.php" class="slink slink-child <?= $currentPage === 'maintenance_income.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-wrench-adjustable"></i></span><span class="slabel">Maintenance Income</span></a>
                <a href="<?= $constructionBase ?>/reports/bank_reconciliation_report.php" class="slink slink-child <?= $currentPage === 'bank_reconciliation_report.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-bank2"></i></span><span class="slabel">Bank Reconciliation</span></a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($conn) && $conn instanceof PDO && tasks_nav_user_can_access($conn)): ?>
        <a href="<?= $appBase ?>/modules/tasks/tasks.php" class="slink mt-1">
            <span class="sicon"><i class="bi bi-list-check"></i></span><span class="slabel">Tasks</span>
        </a>
        <?php endif; ?>

        <div class="nav-sect mt-3">Switch Module</div>
        <a href="<?= $appBase ?>/select-module" class="slink co-switch-module">
            <span class="sicon"><i class="bi bi-arrow-left-right"></i></span>
            <span class="slabel">Switch Module</span>
        </a>

        <div class="nav-group mt-2" data-nav-id="settings">
            <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#nav-settings" aria-expanded="<?= $settingsActive ? 'true' : 'false' ?>">
                <span class="nav-sect-text">Settings</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse <?= $settingsActive ? 'show' : '' ?>" id="nav-settings">
                <a href="<?= $appBase ?>/settings" class="slink"><span class="sicon"><i class="bi bi-sliders"></i></span><span class="slabel">ERP Settings</span></a>
                <a href="<?= $appBase ?>/profile" class="slink"><span class="sicon"><i class="bi bi-person-circle"></i></span><span class="slabel">Profile</span></a>
                <?php if (!empty($coIsOwner)): ?>
                <a href="<?= $constructionBase ?>/theme_settings.php" class="slink <?= $currentPage === 'theme_settings.php' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-palette"></i></span><span class="slabel">Theme Settings</span></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($coUiV2):
            $coCompanyLabel = 'Construction';
            if (function_exists('current_company_id') && isset($conn)) {
                $coCid = (int)(current_company_id($conn) ?: 0);
                if ($coCid > 0) {
                    try {
                        $cn = $conn->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
                        $cn->execute([$coCid]);
                        $nm = $cn->fetchColumn();
                        if ($nm) {
                            $coCompanyLabel = (string)$nm;
                        }
                    } catch (Throwable $e) {
                        $coCompanyLabel = 'Company ' . $coCid;
                    }
                }
            }
        ?>
        <div class="co-company-chip mt-auto">
            <strong><?= h($coCompanyLabel) ?></strong>
            Construction Design System
        </div>
        <?php endif; ?>
    </aside>

    <div class="flex-grow-1 co-main-wrap">
        <nav class="navbar navbar-expand navbar-light bg-white shadow-sm no-print">
            <div class="container-fluid">
                <button type="button" class="btn btn-link text-dark co-mobile-menu-btn p-2 me-2" id="co-mobile-menu-btn" aria-label="Open menu"><i class="bi bi-list" style="font-size: 1.5rem"></i></button>
                <span class="navbar-brand fw-bold" style="color:var(--primary)"><?= h($brand['system_name']) ?> — Construction</span>
                <div class="dropdown ms-auto">
                    <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                        <div class="avatar me-2"><?= h($avatarInitial) ?></div>
                        <span class="me-2 d-none d-md-inline"><?= h($fullName) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><span class="dropdown-item-text"><strong><?= h($userName) ?></strong></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= $appBase ?>/profile"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="<?= $appBase ?>/settings"><i class="bi bi-sliders me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item text-danger" href="<?= $appBase ?>/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>
        <div class="container-fluid co-content my-4 px-3 px-lg-4">
