<?php
/**
 * Real Estate Layout Header - Shared sidebar and top navigation for all Real Estate pages
 * Include this at the start of each Real Estate page after requiring auth/db
 *
 * Menu:
 *  - Desktop (992px and wider): sidebar beside the page; the menu button folds it
 *    to an icon rail and back (remembered per browser).
 *  - Tablet and phone: the sidebar is a drawer that slides in over the page from
 *    the menu button, and closes on a link, the backdrop, the close button or Esc.
 *  - The menu is defined once in $reNav below; links are absolute, so they work
 *    from every folder (accounting/, admin/, ...). The group of the current page
 *    is open on every page load (no other section), and its link highlighted, also on
 *    the page's detail pages (e.g. lease_view.php highlights Leases). Sections work as
 *    an accordion: opening one closes the others.
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
// Web path of modules/realestate/ so menu links work from any sub folder
$reSelf = (string)($_SERVER['PHP_SELF'] ?? '');
$reRootPos = strpos($reSelf, '/modules/realestate/');
$reWebBase = $reRootPos !== false ? substr($reSelf, 0, $reRootPos + strlen('/modules/realestate/')) : $navBasePath;

require_once __DIR__ . '/../../../includes/rbac_department.php';
$hasCore = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn);
$hasFinancial = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn);
$hasMaintenance = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_MAINTENANCE, $conn);
$hasOperations = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_OPERATIONS, $conn);
$hasCompliance = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_COMPLIANCE, $conn);
$isOwnerUser = function_exists('has_role') ? has_role('Owner', $conn) : false;
$hasTasks = isset($conn) && $conn instanceof PDO && tasks_nav_user_can_access($conn);

// Switch Module: only for users with more than one company/module
if (!function_exists('get_user_companies')) {
    require_once dirname(__DIR__, 2) . '/includes/company_helper.php';
}
$userId = function_exists('current_user_id') ? current_user_id() : null;
$userCompanies = ($userId && function_exists('get_user_companies')) ? get_user_companies($conn, $userId) : [];
$hasMultipleCompanies = count($userCompanies) > 1;

/*
 * The menu. Item forms:
 *   ['Label', 'bi-icon', 'path.php', ['page.php', 'prefix_*', ...]]   link (pages that light it up)
 *   ['head' => 'Section title']                                        small heading
 *   ['group' => 'id', 'label' => ..., 'icon' => ..., 'items' => [...]] nested group
 * Paths are relative to modules/realestate/; a path starting with "/" is from the app root.
 */
$reNav = [];
if ($hasCore) {
    $reNav[] = ['id' => 'core', 'label' => 'Core Management', 'icon' => 'bi-grid-1x2', 'items' => [
        ['Dashboard', 'bi-speedometer2', 'index.php', ['index.php']],
        ['Buildings', 'bi-building', 'buildings.php', ['buildings.php', 'building_*', 'affection_site_plan_*', 'title_deed_*']],
        ['Units', 'bi-door-open', 'units.php', ['units.php', 'units_add.php', 'unit_view.php', 'unit_public_media_*']],
        ['Tenants', 'bi-people', 'tenants.php', ['tenants.php', 'tenant_add.php', 'tenant_view.php', 'tenant_issue_add.php']],
        ['Leases', 'bi-file-text', 'leases.php', ['leases.php', 'deleted_leases.php', 'lease_add.php', 'lease_view.php', 'lease_terminate.php', 'lease_contract_generate.php', 'lease_payments_manage.php', 'lease_payment_schedule_audit.php', 'installment_payment_history.php']],
        ['Expiry Reminders', 'bi-bell', 'lease_expiry_reminders.php', ['lease_expiry_reminders.php']],
        ['Renewal Workflow', 'bi-arrow-repeat', 'lease_renewal_workflow.php', ['lease_renewal_workflow*']],
        ['Communication Center', 'bi-megaphone', 'tenant_communication_center.php', ['tenant_communication_center.php', 'customer_push_notifications.php']],
        ['Viewing Requests', 'bi-calendar-check', 'unit_viewing_requests.php', ['unit_viewing_requests.php']],
        ['Lease Applications', 'bi-file-earmark-person', 'unit_lease_applications.php', ['unit_lease_applications.php']],
        ['Contract Templates', 'bi-file-earmark-text', 'lease_templates.php', ['lease_templates.php', 'create_tanacey_template.php']],
        ['Tenant Portal', 'bi-person-badge', 'tenant_portal_approvals.php', ['tenant_portal_approvals.php']],
    ]];
}
if ($hasFinancial) {
    $reNav[] = ['id' => 'financial', 'label' => 'Financial', 'icon' => 'bi-wallet2', 'items' => [
        ['Payments', 'bi-cash-coin', 'payments.php', ['payments.php', 'payment_*']],
        ['Cash verification', 'bi-patch-check', 'cash_verification.php', ['cash_verification.php']],
        ['Billing', 'bi-receipt', 'billing.php', ['billing*']],
        ['Collections', 'bi-exclamation-triangle', 'collections.php', ['collections*']],
    ]];
    $reNav[] = ['id' => 'accounting', 'label' => 'Accounting', 'icon' => 'bi-calculator', 'items' => [
        ['head' => 'Purchases & Expenses'],
        ['Vendors', 'bi-truck', 'vendors.php', ['vendors.php', 'vendors_add.php', 'vendors_view.php', 'vendor_agreements.php', 'vendor_performance.php']],
        ['Vendor Bills', 'bi-file-text', 'accounting/vendor_bills.php', ['vendor_bills.php', 'vendor_bill_view.php']],
        ['New Bill', 'bi-file-earmark-plus', 'accounting/bill_entry_add.php', ['bill_entry_add.php']],
        ['Payments Made', 'bi-cash-stack', 'accounting/vendor_payments.php', ['vendor_payments.php', 'vendor_payment_add.php']],
        ['Advance VAT Docs', 'bi-receipt', 'accounting/vendor_advance_vat_documents.php', ['vendor_advance_vat_document*']],
        ['Advance Refunds', 'bi-arrow-counterclockwise', 'accounting/vendor_advance_refunds.php', ['vendor_advance_refund*']],
        ['AP Aging', 'bi-clock-history', 'accounting/ap_aging.php', ['ap_aging.php']],
        ['Vendor Ledger', 'bi-journal-bookmark', 'accounting/vendor_ledger.php', ['vendor_ledger.php']],
        ['Vendor SOA', 'bi-file-earmark-ruled', 'accounting/vendor_statement.php', ['vendor_statement.php']],
        ['Building Expenses', 'bi-building', 'accounting/building_expense_report.php', ['building_expense_report.php']],
        ['Vendor/AP Diagnostics', 'bi-clipboard-data', 'accounting/vendor_ap_diagnostics.php', ['vendor_ap_diagnostics.php']],
        ['QPE/AP Duplicates', 'bi-intersect', 'accounting/qpe_ap_duplicate_finder.php', ['qpe_ap_duplicate_finder.php']],
        ['Recurring Bills', 'bi-arrow-repeat', 'accounting/vendor_recurring_bills.php', ['vendor_recurring_bills.php']],
        ['Quick Paid Expenses', 'bi-receipt-cutoff', 'expenses.php', ['expenses.php', 'expense_*']],
        ['head' => 'Income'],
        ['Obligation Preview', 'bi-diagram-3', 'accounting/obligation_preview.php', ['obligation_preview.php']],
        ['Invoice Preview', 'bi-file-earmark-text', 'accounting/invoice_preview.php', ['invoice_preview.php']],
        ['Receipt Allocation', 'bi-cash-coin', 'accounting/receipt_allocation.php', ['receipt_allocation.php']],
        ['Receipt Diagnostics', 'bi-clipboard-data', 'accounting/receipt_diagnostics.php', ['receipt_diagnostics.php']],
        ['head' => 'Setup & Controls'],
        ['Chart of Accounts', 'bi-list-columns-reverse', 'accounting/chart_of_accounts.php', ['chart_of_accounts.php']],
        ['Journal Entries', 'bi-journal-text', 'accounting/journal_entry_list.php', ['journal_entry_*']],
        ['Bank Accounts', 'bi-bank2', 'accounting/bank_accounts.php', ['bank_accounts.php']],
        ['group' => 'bank-reco', 'label' => 'Bank Reconciliations', 'icon' => 'bi-bank', 'items' => [
            ['Dashboard', 'bi-grid-1x2', 'accounting/bank_reconciliation_accounts.php', ['bank_reconciliation_accounts.php']],
            ['Reconcile', 'bi-bank', 'accounting/bank_reconciliation.php', ['bank_reconciliation.php']],
            ['Statement Import', 'bi-file-earmark-arrow-up', 'accounting/bank_reconciliation_import.php', ['bank_reconciliation_import.php', 'bank_reconciliation_match.php']],
            ['Cash Coding', 'bi-table', 'accounting/bank_reconciliation_cash_coding.php', ['bank_reconciliation_cash_coding.php']],
            ['Bank Rules', 'bi-sliders', 'accounting/bank_reconciliation_rules.php', ['bank_reconciliation_rules.php']],
            ['Report', 'bi-file-earmark-bar-graph', 'accounting/bank_reconciliation_report.php', ['bank_reconciliation_report.php']],
            ['History', 'bi-clock-history', 'accounting/bank_reconciliation_history.php', ['bank_reconciliation_history.php']],
            ['Settings', 'bi-gear', 'accounting/bank_reconciliation_settings.php', ['bank_reconciliation_settings.php']],
            ['Bank Feeds', 'bi-cloud-download', 'accounting/bank_reconciliation_feeds.php', ['bank_reconciliation_feeds.php']],
            ['Diagnostics', 'bi-clipboard-data', 'accounting/bank_reconciliation_diagnostics.php', ['bank_reconciliation_diagnostics.php']],
        ]],
        ['Period Management', 'bi-calendar-range', 'accounting/periods.php', ['periods.php', 'period_close.php']],
        ['VAT Configuration', 'bi-receipt-cutoff', 'accounting/vat_config.php', ['vat_config.php']],
        ['Accounting Settings', 'bi-sliders', 'accounting/accounting_settings.php', ['accounting_settings.php']],
        ['Document Settings', 'bi-file-earmark-richtext', 'document_settings.php', ['document_settings.php']],
        ['Credit Note', 'bi-arrow-counterclockwise', 'accounting/credit_note_add.php', ['credit_note_add.php']],
        ['Migrate Data', 'bi-database', 'accounting/migrate_existing_data.php', ['migrate_existing_data.php']],
    ]];
}
if ($hasMaintenance) {
    $reNav[] = ['id' => 'maintenance', 'label' => 'Maintenance', 'icon' => 'bi-tools', 'items' => [
        ['Maintenance', 'bi-tools', 'maintenance.php', ['maintenance.php', 'maintenance_add.php', 'maintenance_view.php', 'maintenance_queue.php', 'material_request_*', 'my_material_requests.php']],
        ['Maintenance Schedule', 'bi-calendar3-week', 'maintenance_schedule.php', ['maintenance_schedule*']],
        ['Tenant extra services', 'bi-plus-circle', 'extra_service_requests.php', ['extra_service_*', 'cleaning_*', 'pest_control_*']],
        ['Preventive Maint.', 'bi-calendar-check', 'preventive_maintenance.php', ['preventive_maintenance*']],
        ['AMC Contracts', 'bi-file-earmark-check', 'amc.php', ['amc*']],
    ]];
}
if ($hasOperations) {
    $reNav[] = ['id' => 'operations', 'label' => 'Operations', 'icon' => 'bi-kanban', 'items' => [
        ['Cash payment requests', 'bi-cash-coin', 'cash_payment_requests.php', ['cash_payment_requests.php']],
        ['Cashier session', 'bi-box', 'cashier_session.php', ['cashier_session.php']],
        ['Move-Ins', 'bi-box-arrow-in-right', 'move_in.php', ['move_in*', 'form_letterhead.php']],
        ['Move-Outs', 'bi-box-arrow-right', 'move_out.php', ['move_out*']],
        ['Tasks', 'bi-list-check', 'tasks.php', ['tasks.php', 'tasks_add.php', 'tasks_view.php', 'tasks_categories.php']],
        ['Reminders', 'bi-bell', 'reminders.php', ['reminders.php']],
        ['Bulk Tenant Email', 'bi-envelope-paper', 'bulk_tenant_email.php', ['bulk_tenant_email*']],
    ]];
}
if ($hasCompliance) {
    $reNav[] = ['id' => 'compliance', 'label' => 'Compliance & Reports', 'icon' => 'bi-shield-check', 'items' => [
        ['Compliance', 'bi-shield-check', 'compliance.php', ['compliance*']],
        ['Documents', 'bi-folder', 'documents.php', ['documents*']],
        ['Reports', 'bi-graph-up', 'reports.php', ['reports*']],
    ]];
}
$reNav[] = ['id' => 'settings', 'label' => 'Settings', 'icon' => 'bi-gear', 'items' => array_merge(
    $hasTasks ? [['Shared Tasks', 'bi-list-check', '/modules/tasks/tasks.php', []]] : [],
    [
        ['Settings', 'bi-sliders', '/settings', []],
        ['Profile', 'bi-person-circle', '/profile', []],
    ]
)];
if ($isOwnerUser) {
    $reNav[] = ['id' => 'owner-tools', 'label' => 'Owner Tools', 'icon' => 'bi-shield-lock', 'items' => [
        ['Installments Cleanup', 'bi-wrench-adjustable-circle', 'installments_cleanup_admin.php', ['installments_cleanup_admin.php']],
        ['Orphan GL Cleanup', 'bi-bandaid', 'admin/orphan_accounting_cleanup.php', ['orphan_accounting_cleanup.php']],
        ['Lease Financial Reset', 'bi-arrow-repeat', 'admin/lease_financial_reset_convert.php', ['lease_financial_reset_convert.php']],
        ['Income Mispost Report', 'bi-exclamation-diamond', 'accounting/income_mispost_report.php', ['income_mispost_report.php']],
        ['Income Repair Sessions', 'bi-journal-check', 'accounting/income_reclass_sessions.php', ['income_reclass_session*']],
    ]];
}

/** The one link that matches the current page: an exact page name wins, else the longest prefix. */
if (!function_exists('re_nav_find_active')) {
    function re_nav_find_active(array $items, string $page, array &$best, array $groupPath): void
    {
        foreach ($items as $it) {
            if (isset($it['group'])) {
                re_nav_find_active($it['items'], $page, $best, array_merge($groupPath, [$it['group']]));
                continue;
            }
            if (isset($it['head'])) {
                continue;
            }
            foreach ($it[3] as $pat) {
                $score = 0;
                if ($pat === $page) {
                    $score = 1000;
                } elseif (substr($pat, -1) === '*' && strncmp($page, substr($pat, 0, -1), strlen($pat) - 1) === 0) {
                    $score = strlen($pat);
                }
                if ($score > $best['score']) {
                    $best = ['score' => $score, 'href' => $it[2], 'groups' => $groupPath];
                }
            }
        }
    }
}
$reNavActive = ['score' => 0, 'href' => null, 'groups' => []];
foreach ($reNav as $g) {
    re_nav_find_active($g['items'], $currentPage, $reNavActive, [$g['id']]);
}
$reNavHref = static fn(string $p): string => $p !== '' && $p[0] === '/' ? $appBase . $p : $reWebBase . $p;

/** Links, headings and nested groups of one menu group. */
if (!function_exists('re_nav_items_html')) {
    function re_nav_items_html(array $items, array $active, callable $href): string
    {
        $out = '';
        foreach ($items as $it) {
            if (isset($it['head'])) {
                $out .= '<div class="nav-sect">' . h($it['head']) . '</div>';
                continue;
            }
            if (isset($it['group'])) {
                $open = in_array($it['group'], $active['groups'], true);
                $id = 'nav-' . $it['group'];
                $out .= '<div class="nav-group nav-sub" data-nav-id="' . h($it['group']) . '">'
                    . '<button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#' . $id . '" aria-controls="' . $id . '" aria-expanded="' . ($open ? 'true' : 'false') . '" data-tip="' . h($it['label']) . '">'
                    . '<span class="sicon"><i class="bi ' . h($it['icon']) . '"></i></span><span class="nav-sect-text">' . h($it['label']) . '</span><i class="bi bi-chevron-down nav-chevron"></i></button>'
                    . '<div class="collapse' . ($open ? ' show' : '') . ($open ? ' is-current' : '') . '" id="' . $id . '">' . re_nav_items_html($it['items'], $active, $href) . '</div></div>';
                continue;
            }
            $isActive = $active['href'] === $it[2];
            $out .= '<a href="' . h($href($it[2])) . '" class="slink' . ($isActive ? ' active' : '') . '"' . ($isActive ? ' aria-current="page"' : '') . ' data-tip="' . h($it[0]) . '">'
                . '<span class="sicon"><i class="bi ' . h($it[1]) . '"></i></span><span class="slabel">' . h($it[0]) . '</span></a>';
        }
        return $out;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' - Real Estate | ' : 'Real Estate | ' ?><?= h($brand['system_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script>
    // Folded menu (desktop) before the first paint, so the page does not jump
    try { if (localStorage.getItem('re_nav_rail') === '1') document.documentElement.classList.add('re-rail'); } catch (e) {}
    </script>
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
            --sb-w: 264px;
            --sb-rail: 76px;
            --topbar-h: 60px;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* ---------- Sidebar ---------- */
        .re-shell { min-height: 100vh; }
        .sidebar {
            width: var(--sb-w);
            flex-shrink: 0;
            height: 100vh;
            height: 100dvh;
            position: sticky;
            top: 0;
            z-index: 1040;
            display: flex;
            flex-direction: column;
            background: linear-gradient(180deg, var(--primary), var(--primary-light));
            color: #fff;
            transition: width .2s ease, transform .25s ease;
        }
        .sb-head {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .9rem .9rem .4rem;
            min-height: 64px;
        }
        .sb-logo { width: 38px; height: 38px; object-fit: contain; border-radius: 9px; background: rgba(255,255,255,.95); padding: 3px; flex-shrink: 0; }
        .brand {
            font-weight: 800;
            letter-spacing: .3px;
            color: var(--accent);
            line-height: 1.15;
            font-size: 1.02rem;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .sb-close {
            margin-left: auto;
            display: none;
            width: 38px; height: 38px;
            border: 0; border-radius: 10px;
            background: rgba(255,255,255,.12);
            color: #fff; font-size: 1.15rem;
        }
        .sb-close:hover { background: rgba(255,255,255,.22); }
        .sb-scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior: contain;
            padding: 0 .35rem 1rem;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,.3) transparent;
        }
        .sb-scroll::-webkit-scrollbar { width: 6px; }
        .sb-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,.28); border-radius: 6px; }
        .sb-foot { padding: .5rem .35rem calc(.75rem + env(safe-area-inset-bottom)); border-top: 1px solid rgba(255,255,255,.12); }

        .nav-sect {
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .09em;
            color: #ffefc4;
            opacity: .65;
            margin: .75rem 0 .2rem .9rem;
        }
        .nav-group { margin-top: .15rem; }
        .nav-sect-toggle {
            display: flex;
            align-items: center;
            gap: .65rem;
            width: 100%;
            min-height: 42px;
            padding: .35rem .65rem;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #ffefc4;
            font-size: .74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            text-align: left;
            cursor: pointer;
            transition: background .15s;
        }
        .nav-sect-toggle .sicon { background: rgba(255,255,255,.1); color: #fff; }
        .nav-sect-toggle .nav-sect-text { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .nav-sect-toggle:hover { background: rgba(255,255,255,.08); color: #fff; }
        .nav-sect-toggle .nav-chevron { font-size: .85rem; transition: transform .2s ease; }
        .nav-sect-toggle[aria-expanded="false"] .nav-chevron { transform: rotate(-90deg); }
        .nav-group.has-current > .nav-sect-toggle { color: #fff; }
        .nav-group.has-current > .nav-sect-toggle .sicon { background: var(--accent); color: var(--primary-dark); }
        .nav-group .collapse, .nav-group .collapsing { padding-left: .55rem; margin-left: 1.15rem; border-left: 1px solid rgba(255,255,255,.14); }
        .nav-sub { margin-top: .25rem; }
        .nav-sub > .nav-sect-toggle { text-transform: none; letter-spacing: 0; font-size: .9rem; font-weight: 500; color: #fff; }

        .slink {
            display: flex;
            align-items: center;
            gap: .65rem;
            min-height: 42px;
            padding: .35rem .6rem;
            margin: .1rem 0;
            border-radius: 10px;
            color: rgba(255,255,255,.92);
            text-decoration: none;
            font-size: .92rem;
            transition: background .15s, color .15s;
        }
        .slink:hover { background: rgba(255,255,255,.1); color: #fff; }
        .slink.active { background: rgba(255,255,255,.2); color: #fff; font-weight: 600; box-shadow: inset 3px 0 0 var(--accent); }
        .slink.active .sicon { background: var(--accent); color: var(--primary-dark); }
        .sicon {
            width: 30px;
            height: 30px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            background: rgba(255,255,255,.14);
            border-radius: 8px;
            font-size: .95rem;
        }
        .slabel { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
        .slink-switch { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.22); }
        .sidebar :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .sidebar .ns-box { margin: .35rem .55rem .6rem; }

        /* ---------- Top bar ---------- */
        .re-topbar {
            position: sticky;
            top: 0;
            z-index: 1020;
            min-height: var(--topbar-h);
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .5rem 1rem;
            background: rgba(255,255,255,.96);
            -webkit-backdrop-filter: saturate(160%) blur(8px);
            backdrop-filter: saturate(160%) blur(8px);
            box-shadow: 0 1px 0 rgba(0,0,0,.06), 0 2px 8px rgba(0,0,0,.04);
        }
        .re-menu-btn {
            width: 42px; height: 42px;
            flex-shrink: 0;
            display: grid; place-items: center;
            border: 1px solid rgba(0,0,0,.08);
            border-radius: 11px;
            background: #fff;
            color: var(--primary);
            font-size: 1.35rem;
        }
        .re-menu-btn:hover { background: #f3f5f9; }
        .re-topbar-title { min-width: 0; font-weight: 700; color: var(--primary); font-size: 1.05rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .re-topbar-title small { display: block; font-size: .72rem; font-weight: 600; color: #6c757d; text-transform: uppercase; letter-spacing: .06em; }
        .re-user { margin-left: auto; flex-shrink: 0; }
        .re-user > a { display: flex; align-items: center; gap: .5rem; padding: .25rem .4rem; border-radius: 12px; color: #212529; text-decoration: none; }
        .re-user > a:hover { background: #f3f5f9; }
        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #eef1f7;
            display: grid;
            place-items: center;
            font-weight: 700;
            color: var(--primary);
            flex-shrink: 0;
        }
        .re-user-name { max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .re-backdrop { display: none; }

        /* ---------- Desktop: folded icon rail ---------- */
        @media (min-width: 992px) {
            html.re-rail .sidebar { width: var(--sb-rail); }
            html.re-rail .sidebar .brand,
            html.re-rail .sidebar .slabel,
            html.re-rail .sidebar .nav-sect,
            html.re-rail .sidebar .nav-sect-text,
            html.re-rail .sidebar .nav-chevron,
            html.re-rail .sidebar .ns-box,
            html.re-rail .sidebar .nav-group > .collapse,
            html.re-rail .sidebar .nav-group > .collapsing { display: none !important; }
            html.re-rail .sidebar .sb-head { justify-content: center; padding-left: .5rem; padding-right: .5rem; }
            html.re-rail .sidebar .nav-sect-toggle,
            html.re-rail .sidebar .slink { justify-content: center; padding-left: 0; padding-right: 0; }
            html.re-rail .sidebar .sicon { width: 36px; height: 36px; font-size: 1.05rem; }
        }

        /* ---------- Tablet and phone: drawer ---------- */
        @media (max-width: 991.98px) {
            .sidebar {
                position: fixed;
                left: 0; top: 0; bottom: 0;
                width: min(var(--sb-w), 86vw);
                transform: translateX(-102%);
                box-shadow: 0 0 40px rgba(0,0,0,.25);
                visibility: hidden;
                transition: transform .25s ease, visibility 0s linear .25s;
            }
            body.re-nav-open .sidebar { transform: none; visibility: visible; transition: transform .25s ease, visibility 0s; }
            .sb-close { display: grid; place-items: center; }
            .re-backdrop {
                display: block;
                position: fixed; inset: 0;
                z-index: 1035;
                background: rgba(15,23,42,.45);
                opacity: 0; pointer-events: none;
                transition: opacity .25s ease;
            }
            body.re-nav-open .re-backdrop { opacity: 1; pointer-events: auto; }
            body.re-nav-open { overflow: hidden; }
            body.re-nav-open .as-bar { display: none !important; } /* check-out bar would cover the menu */
            .slink, .nav-sect-toggle { min-height: 46px; }
        }
        @media (max-width: 575.98px) {
            .re-topbar { padding: .45rem .75rem; gap: .5rem; }
            .re-topbar-title { font-size: .98rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            .sidebar, .re-backdrop, .nav-chevron { transition: none !important; }
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
        body.dark-mode .re-topbar {
            background: rgba(30, 41, 59, 0.95);
            box-shadow: 0 1px 0 #334155;
        }
        body.dark-mode .re-topbar-title, body.dark-mode .re-user > a { color: #e4e4e7; }
        body.dark-mode .re-menu-btn { background: #1e293b; border-color: #334155; color: #e4e4e7; }
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
            .re-topbar,
            .re-backdrop,
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
<?php
// Check-out bar for staff who have checked in. Prints nothing when the
// feature is off or the person has no attendance to record.
$asWidget = dirname(__DIR__, 3) . '/includes/attendance_self_widget.php';
if (is_file($asWidget)) { require $asWidget; }
?>
<div class="d-flex re-shell">
    <div class="re-backdrop no-print" id="reNavBackdrop" aria-hidden="true"></div>
    <!-- Sidebar -->
    <aside id="sb" class="sidebar no-print" aria-label="Real Estate menu">
        <div class="sb-head">
            <?php if ($logoSrc = brand_logo_src($brand)): ?>
                <img src="<?= h($logoSrc) ?>" alt="" class="sb-logo">
            <?php endif; ?>
            <span class="brand"><?= h($brand['system_name']) ?></span>
            <button type="button" class="sb-close" id="sbClose" aria-label="Close menu"><i class="bi bi-x-lg"></i></button>
        </div>
        <?php require_once __DIR__ . '/../../../includes/nav_search.php'; ?>
        <?= nav_search_box() ?>
        <nav class="sb-scroll" id="sbNav">
            <?php foreach ($reNav as $g):
                $open = in_array($g['id'], $reNavActive['groups'], true);
                $gid = 'nav-' . $g['id']; ?>
            <div class="nav-group<?= $open ? ' has-current' : '' ?>" data-nav-id="<?= h($g['id']) ?>">
                <button type="button" class="nav-sect-toggle" data-bs-toggle="collapse" data-bs-target="#<?= h($gid) ?>" aria-controls="<?= h($gid) ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>" data-tip="<?= h($g['label']) ?>">
                    <span class="sicon"><i class="bi <?= h($g['icon']) ?>"></i></span>
                    <span class="nav-sect-text"><?= h($g['label']) ?></span>
                    <i class="bi bi-chevron-down nav-chevron"></i>
                </button>
                <div class="collapse<?= $open ? ' show is-current' : '' ?>" id="<?= h($gid) ?>" data-bs-parent="#sbNav">
                    <?= re_nav_items_html($g['items'], $reNavActive, $reNavHref) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </nav>
        <?php if ($hasMultipleCompanies): ?>
        <div class="sb-foot">
            <a href="<?= h($appBase) ?>/select-module" class="slink slink-switch" data-tip="Switch Module">
                <span class="sicon"><i class="bi bi-arrow-left-right"></i></span>
                <span class="slabel">Switch Module</span>
            </a>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content -->
    <div class="flex-grow-1" style="min-width:0">
        <!-- Top bar -->
        <header class="re-topbar no-print">
            <button type="button" class="re-menu-btn" id="sbToggle" aria-controls="sb" aria-expanded="false" aria-label="Menu" title="Menu"><i class="bi bi-list"></i></button>
            <div class="re-topbar-title">
                <small>Real Estate</small>
                <?= h(isset($pageTitle) && $pageTitle !== '' ? $pageTitle : $brand['system_name']) ?>
            </div>
            <div class="dropdown re-user">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                    <span class="avatar"><?= h($avatarInitial) ?></span>
                    <span class="re-user-name d-none d-md-inline"><?= h($fullName) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><span class="dropdown-item-text"><strong><?= h($fullName) ?></strong><?php if ($userName !== ''): ?><br><small class="text-muted"><?= h($userName) ?></small><?php endif; ?></span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= h($appBase) ?>/profile"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="<?= h($appBase) ?>/settings"><i class="bi bi-sliders me-2"></i>Settings</a></li>
                    <?php if ($hasMultipleCompanies): ?>
                    <li><a class="dropdown-item" href="<?= h($appBase) ?>/select-module"><i class="bi bi-arrow-left-right me-2"></i>Switch Module</a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?= h($appBase) ?>/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </header>

        <!-- Page Content (full width by default; set $reLayoutFluid = false before including this header for a narrow centred page) -->
        <div class="<?= (!isset($reLayoutFluid) || $reLayoutFluid) ? 'container-fluid px-3 px-lg-4' : 'container' ?> my-4">

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

