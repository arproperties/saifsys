<?php
/**
 * Phase 9 Real Estate Reports Center.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/reporting_mode_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_COMPLIANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$modeCounts = re_report_mode_counts($conn, $currentCompanyId);
$outstandingTotals = re_report_combined_outstanding($conn, $currentCompanyId, date('Y-m-d'));
$collectionTotals = re_report_combined_collections($conn, $currentCompanyId, date('Y-m-01'), date('Y-m-d'));
$rentRollTotals = re_report_combined_rent_roll($conn, $currentCompanyId);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return number_format((float)$n, 2); }
function report_file_exists(string $path): bool { $clean = strtok($path, '?') ?: $path; return is_file(__DIR__ . '/' . ltrim($clean, '/')); }
function report_badge_class(string $badge): string {
    return match ($badge) {
        'New Engine', 'New Engine Only' => 'primary',
        'Combined' => 'success',
        'Legacy-Compatible' => 'secondary',
        'Operational Only' => 'info text-dark',
        'Diagnostic' => 'warning text-dark',
        'Needs Review' => 'danger',
        default => 'secondary',
    };
}

$reports = [
    'Executive / Management Reports' => [
        ['Portfolio Summary', 'Combined management overview of units, leases, collections, AR, bank reconciliation, and deposit liability.', 'New report to add in Phase 9B', 'Combined', 'reports_portfolio_summary.php', 'Planned'],
        ['Income Summary', 'Separates contracted rent, invoiced rent, GL revenue, cash collected, services, penalties, and deposits excluded from income.', 'GL + invoices + receipts', 'Combined', 'reports_income_summary.php', 'Planned'],
    ],
    'Leasing & Occupancy Reports' => [
        ['Rent Roll Report', 'Operational contract rent by building/unit/tenant with accounting mode and payment schedule summary.', 'Leases + units + lease units', 'Combined', 'reports_rent_roll.php', 'CSV'],
        ['Occupancy Report', 'Occupancy by building and unit type.', 'Units + active leases', 'Operational Only', 'reports_occupancy.php', 'Print'],
        ['Lease Expiry Report', 'Expiring leases with renewal status and accounting mode.', 'Leases + renewal workflow', 'Combined', 'reports_lease_expiry.php', 'Print'],
        ['Tenant List Report', 'Tenant contact and active lease information.', 'Tenants + leases', 'Legacy-Compatible', 'reports_tenant_list.php', 'Print'],
    ],
    'Collections Reports' => [
        ['AR Aging / Outstanding Report', 'Invoice Mode AR from invoices/allocations plus Legacy outstanding from installments/payments.', 'Invoices + allocations + legacy installments', 'Combined', 'accounting/outstandings_report.php', 'Needs aging upgrade'],
        ['Collection Report', 'Cleared receipts/payments by date range, method, allocation and reconciliation state.', 'Receipts/payments + allocations', 'Combined', 'reports_rent_collection.php', 'Needs refactor'],
        ['Unallocated Receipts Report', 'Receipts not fully allocated.', 'Phase 4 receipts', 'New Engine', 'accounting/receipt_diagnostics.php', 'Diagnostic'],
    ],
    'Accounting & Financial Reports' => [
        ['Trial Balance', 'GL debit/credit balances. Must balance.', 'General Ledger', 'New Engine', 'accounting/trial_balance.php', 'CSV/Excel'],
        ['Day Book', 'Daily chronological accounting transactions.', 'General Ledger', 'New Engine', 'accounting/day_book.php', 'Report'],
        ['General Ledger', 'Detailed GL by account/date/source.', 'General Ledger', 'New Engine', 'accounting/general_ledger.php', 'Print/Export'],
        ['Account Ledger', 'Tenant/vendor/bank sub-ledger activity with legacy-compatible tenant details.', 'Sub-ledgers + legacy activity', 'Combined', 'accounting/account_ledger.php', 'CSV/Print'],
        ['Tenant Statement', 'Tenant invoices, receipts, allocations and legacy payments with mode labels.', 'Invoices + receipts + allocations + legacy payments', 'Combined', 'accounting/tenant_statement.php', 'Needs enhancement'],
        ['Unearned Revenue (Tenant Credit Balance)', 'Tenants with advance/credit balance posted to GL 2410 Deferred Revenue, with receipt drill-down and allocation links.', 'Tenant credit balances + GL 2410 + receipts', 'New Engine', 'accounting/unearned_revenue_report.php', 'CSV/PDF/Print'],
        ['Profit & Loss', 'GL income and expenses. Security deposits excluded from income.', 'General Ledger', 'New Engine', 'accounting/profit_loss.php', 'CSV/Excel'],
        ['Balance Sheet', 'Assets, liabilities, equity. Deposit liability appears under liabilities.', 'General Ledger', 'New Engine', 'accounting/balance_sheet.php', 'CSV/Excel'],
        ['Cash Flow', 'Cash and bank movement report.', 'GL bank/cash postings', 'New Engine', 'accounting/cash_flow.php', 'Report'],
    ],
    'VAT & Tax Reports' => [
        ['VAT Report', 'VAT from GL VAT accounts with invoice tax validation required.', 'GL VAT + invoice tax lines', 'Combined', 'accounting/vat_report.php', 'Needs validation'],
    ],
    'Bank Reconciliation Reports' => [
        ['Bank Reconciliation Workbench', 'Persisted bank lines and confirmed match records.', 'Statement lines + matches + GL', 'New Engine', 'accounting/bank_reconciliation.php', 'Workbench'],
        ['Statement Import', 'CSV/Excel persisted import with duplicate protection.', 'Import batches + statement lines', 'New Engine', 'accounting/bank_reconciliation_match.php', 'CSV/Excel'],
        ['Bank Diagnostics', 'Unmatched bank lines, unreconciled ERP entries, deposit/cheque exceptions.', 'Bank statement + matches', 'Diagnostic', 'accounting/bank_reconciliation_diagnostics.php', 'Diagnostic'],
        ['Bank Reconciliation Summary Report', 'Opening/closing/matched/unmatched/difference report.', 'Statement lines + matches + GL', 'New Engine', 'accounting/bank_reconciliation_report.php', 'To add'],
    ],
    'Security Deposit Reports' => [
        ['Security Deposit Liability Report', 'Expected, received, liability posted, refunded, deducted, refundable balance.', 'Deposit obligations + receipts + GL', 'Combined', 'accounting/security_deposit_diagnostics.php', 'Diagnostic'],
        ['Deposit Exceptions Report', 'Missing deposit obligations, missing liability journals, refund/deduction exceptions.', 'Phase 7 deposit controls', 'Diagnostic', 'accounting/security_deposit_diagnostics.php', 'Diagnostic'],
    ],
    'Cheque & Payment Instrument Reports' => [
        ['Cheque Register', 'Operational cheque/payment instruments only.', 'Cheque schedule tables', 'Operational Only', 'billing_cheques.php', 'Operational'],
        ['Bounced Cheque Report', 'Bounced cheques, settlement/replacement/legal status.', 'Cheque + legal + penalty records', 'Operational Only', 'billing_cheques.php?status=bounced', 'Operational'],
    ],
    'Service Billing Reports' => [
        ['Service Billing Report', 'Extra service charges, invoice/payment/allocation status and VAT treatment.', 'Service charges + billing items + invoices', 'Combined', 'billing_service_charges.php', 'Needs report'],
    ],
    'Operational Reports' => [
        ['Maintenance Summary', 'Maintenance requests and cost summary.', 'Maintenance operational tables', 'Operational Only', 'reports_maintenance_summary.php', 'Print'],
        ['Maintenance by Building', 'Maintenance grouped by building.', 'Maintenance operational tables', 'Operational Only', 'reports_maintenance_by_building.php', 'Print'],
        ['Maintenance by Category', 'Maintenance grouped by category.', 'Maintenance operational tables', 'Operational Only', 'reports_maintenance_by_category.php', 'Print'],
    ],
    'Diagnostics & Exceptions' => [
        ['Accounting Exception Dashboard', 'Invoices without journals, missing allocations, AR/VAT/deposit/bank variances.', 'Cross-engine diagnostics', 'Diagnostic', 'accounting/accounting_exception_dashboard.php', 'To add'],
        ['Legacy vs Invoice Mode Diagnostic', 'Lease counts by mode, missing obligations/candidates, new/renewal Legacy warnings.', 'Leases + engines', 'Diagnostic', 'reports_legacy_invoice_mode_diagnostic.php', 'To add'],
        ['Receipt Diagnostics', 'Invoice Mode receipt register and allocation diagnostics.', 'Phase 4 receipts', 'Diagnostic', 'accounting/receipt_diagnostics.php', 'Diagnostic'],
        ['Recognition Diagnostics', 'Legacy recognition plus Invoice Mode recognition diagnostics.', 'GL + obligations + invoices', 'Diagnostic', 'accounting/revenue_recognition.php', 'Diagnostic'],
    ],
];

$flatReports = array_merge(...array_values($reports));
$counts = ['total' => count($flatReports), 'new' => 0, 'legacy' => 0, 'diagnostic' => 0, 'needs' => 0, 'combined' => 0, 'operational' => 0];
foreach ($flatReports as $report) {
    $badge = $report[3];
    if (str_contains($badge, 'New Engine')) $counts['new']++;
    if ($badge === 'Legacy-Compatible') $counts['legacy']++;
    if ($badge === 'Diagnostic') $counts['diagnostic']++;
    if ($badge === 'Needs Review' || !report_file_exists((string)$report[4])) $counts['needs']++;
    if ($badge === 'Combined') $counts['combined']++;
    if ($badge === 'Operational Only') $counts['operational']++;
}

$pageTitle = 'Reports Center';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<style>
.report-hero{background:#fff;border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.06);padding:20px;margin-bottom:18px}.report-metric{background:#fff;border-radius:14px;padding:14px;box-shadow:0 4px 14px rgba(0,0,0,.05)}.report-metric .label{font-size:.72rem;color:#6b7280;text-transform:uppercase}.report-metric .value{font-size:1.35rem;font-weight:800}.report-card{height:100%;border:1px solid #edf0f5;border-radius:16px;background:#fff;box-shadow:0 4px 14px rgba(0,0,0,.05);transition:.15s}.report-card:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(0,0,0,.08)}.source-text{font-size:.8rem;color:#6b7280}.section-title{margin-top:28px;margin-bottom:12px;font-weight:800;color:#1f2937}
</style>

<div class="report-hero">
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div>
            <div class="text-uppercase small text-muted">Real Estate</div>
            <h2 class="mb-1"><i class="bi bi-graph-up"></i> Reports Center</h2>
            <p class="text-muted mb-0">Combined reporting for Legacy Mode and Invoice Mode. Reports are read-only and do not change accounting balances.</p>
        </div>
        <a href="accounting/accounting_settings.php" class="btn btn-outline-secondary btn-sm">Accounting Settings</a>
    </div>
</div>

<div class="alert alert-warning">
    Reports marked <strong>Legacy-Compatible</strong> may use old installment/payment logic for historical leases. Reports marked <strong>Combined</strong> include both Legacy Mode and Invoice Mode. Invoice Mode values use obligations, invoices, receipts, allocations, and GL.
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-2"><div class="report-metric"><div class="label">Total Reports</div><div class="value"><?= $counts['total'] ?></div></div></div>
    <div class="col-6 col-md-2"><div class="report-metric"><div class="label">Combined</div><div class="value text-success"><?= $counts['combined'] ?></div></div></div>
    <div class="col-6 col-md-2"><div class="report-metric"><div class="label">New Engine</div><div class="value text-primary"><?= $counts['new'] ?></div></div></div>
    <div class="col-6 col-md-2"><div class="report-metric"><div class="label">Legacy</div><div class="value text-secondary"><?= $counts['legacy'] ?></div></div></div>
    <div class="col-6 col-md-2"><div class="report-metric"><div class="label">Diagnostic</div><div class="value text-warning"><?= $counts['diagnostic'] ?></div></div></div>
    <div class="col-6 col-md-2"><div class="report-metric"><div class="label">Needs Review</div><div class="value text-danger"><?= $counts['needs'] ?></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="report-metric"><div class="label">Outstanding Today</div><div>Legacy: <strong><?= money($outstandingTotals['legacy']) ?></strong> AED</div><div>Invoice Mode: <strong><?= money($outstandingTotals['invoice']) ?></strong> AED</div><div>Combined: <strong><?= money($outstandingTotals['combined']) ?></strong> AED</div></div></div>
    <div class="col-md-4"><div class="report-metric"><div class="label">Collections This Month</div><div>Legacy: <strong><?= money($collectionTotals['legacy']) ?></strong> AED</div><div>Invoice Mode: <strong><?= money($collectionTotals['invoice']) ?></strong> AED</div><div>Combined: <strong><?= money($collectionTotals['combined']) ?></strong> AED</div></div></div>
    <div class="col-md-4"><div class="report-metric"><div class="label">Contracted Annual Rent</div><div>Legacy: <strong><?= money($rentRollTotals['legacy']) ?></strong> AED</div><div>Invoice Mode: <strong><?= money($rentRollTotals['invoice']) ?></strong> AED</div><div>Combined: <strong><?= money($rentRollTotals['combined']) ?></strong> AED</div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="report-metric"><div class="label">Legacy Leases</div><div class="value"><?= (int)$modeCounts['legacy_total'] ?></div><small>Active: <?= (int)$modeCounts['legacy_active'] ?></small></div></div>
    <div class="col-md-3"><div class="report-metric"><div class="label">Invoice Mode Leases</div><div class="value"><?= (int)$modeCounts['invoice_total'] ?></div><small>Active: <?= (int)$modeCounts['invoice_active'] ?></small></div></div>
    <div class="col-md-3"><div class="report-metric"><div class="label">Missing Obligations</div><div class="value text-danger"><?= (int)$modeCounts['invoice_missing_obligations'] ?></div><small>Invoice Mode only</small></div></div>
    <div class="col-md-3"><div class="report-metric"><div class="label">Legacy Renewals</div><div class="value text-warning"><?= (int)$modeCounts['renewal_legacy'] ?></div><small>Needs review</small></div></div>
</div>

<?php foreach ($reports as $section => $items): ?>
    <h4 class="section-title"><?= h($section) ?></h4>
    <div class="row g-3">
        <?php foreach ($items as $report): ?>
            <?php [$title, $description, $source, $badge, $path, $export] = $report; $exists = report_file_exists((string)$path); $displayBadge = $exists ? $badge : 'Needs Review'; ?>
            <div class="col-md-6 col-xl-4">
                <div class="report-card p-3">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <h5 class="mb-0"><?= h($title) ?></h5>
                        <span class="badge bg-<?= report_badge_class($displayBadge) ?>"><?= h($displayBadge) ?></span>
                    </div>
                    <p class="mb-2 text-muted"><?= h($description) ?></p>
                    <div class="source-text mb-2"><strong>Source:</strong> <?= h($source) ?></div>
                    <div class="source-text mb-3"><strong>Export:</strong> <?= h($export) ?></div>
                    <?php if ($exists): ?>
                        <a href="<?= h($path) ?>" class="btn btn-sm btn-primary">Open Report</a>
                    <?php else: ?>
                        <button class="btn btn-sm btn-outline-danger" disabled>Needs Review / Not Built</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
