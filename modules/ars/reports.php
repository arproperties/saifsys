<?php
/**
 * Reports hub — single entry for ARS reporting surfaces.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_shell.php';

$arsCompanyId = arsPageAuth($conn);
unset($arsCompanyId);
$brand = getBrandSettings($conn);
$pageTitle = 'Reports';

ars_shell_begin([
    'title' => 'Reports',
    'subtitle' => 'Documents, receivables, revenue, and ledger tools',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Reports'],
    ],
    'legacy_bootstrap' => false,
]);

$cards = [
    [
        'Financial documents',
        'Invoices, service docs, accounting status, and document drill-down',
        'financial_reports.php?tab=documents',
        'file-bar-chart',
        'Primary',
    ],
    [
        'Outstanding AR',
        'Open receivable balances by financial document',
        'financial_reports.php?tab=ar',
        'wallet',
        null,
    ],
    [
        'Deposit liability',
        'Security deposits held, refunded, and forfeited',
        'financial_reports.php?tab=deposits',
        'shield',
        null,
    ],
    [
        'Revenue',
        'Stay revenue report for the ARS company',
        'revenue.php',
        'trending-up',
        null,
    ],
    [
        'Chart of Accounts',
        'Shared COA management used by ARS postings',
        'chart_of_accounts.php',
        'list',
        null,
    ],
    [
        'Activity Center',
        'Company-wide booking event feed',
        'activity_center.php',
        'activity',
        null,
    ],
];

echo '<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">';
foreach ($cards as $c) {
    echo '<a class="group block rounded-ars-lg border border-ars-border bg-ars-surface p-5 shadow-ars-sm transition-all hover:border-ars-ink/30 hover:shadow-ars-md no-underline text-inherit" href="' . ars_ui_h($c[2]) . '">';
    echo '<div class="flex items-start justify-between gap-2 mb-3">';
    echo '<div class="flex h-10 w-10 items-center justify-center rounded-ars-md bg-teal-50 text-ars-ink">' . ars_ui_icon($c[3], ['class' => 'h-5 w-5']) . '</div>';
    if (!empty($c[4])) {
        echo '<span class="rounded-full bg-ars-ink text-white text-[10px] font-bold uppercase tracking-wide px-2 py-0.5">' . ars_ui_h($c[4]) . '</span>';
    }
    echo '</div>';
    echo '<div class="font-semibold text-ars-text group-hover:text-ars-ink">' . ars_ui_h($c[0]) . '</div>';
    echo '<p class="mt-1.5 m-0 text-ars-sm text-ars-muted">' . ars_ui_h($c[1]) . '</p>';
    echo '</a>';
}
echo '</div>';

echo '<p class="mt-6 mb-0 text-ars-sm text-ars-muted">Financial document calculations are unchanged — this hub only organizes where operators start.</p>';

ars_shell_end();
