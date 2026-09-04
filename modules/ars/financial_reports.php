<?php
/**
 * ARS Financial Documents / Receivables / Deposits — reporting surface.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_financial_reports.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$arsCompany = get_company($conn, $arsCompanyId);
$currencyStmt = $conn->prepare("SELECT currency FROM ars_company_settings WHERE company_id = ? LIMIT 1");
$currencyStmt->execute([$arsCompanyId]);
$currency = (string)($currencyStmt->fetchColumn() ?: 'AED');
$tab = $_GET['tab'] ?? 'documents';
if (!in_array($tab, ['documents', 'ar', 'deposits'], true)) {
    $tab = 'documents';
}

$filters = [
    'booking_id' => (int) ($_GET['booking_id'] ?? 0) ?: null,
    'guest_id' => (int) ($_GET['guest_id'] ?? 0) ?: null,
    'unit_id' => (int) ($_GET['unit_id'] ?? 0) ?: null,
    'document_type' => $_GET['document_type'] ?? null,
    'status' => $_GET['status'] ?? null,
    'from' => $_GET['from'] ?? null,
    'to' => $_GET['to'] ?? null,
];

$documents = ars_report_financial_documents($conn, $arsCompanyId, array_filter($filters));
$ar = ars_report_outstanding_receivables($conn, $arsCompanyId);
$dep = ars_report_deposit_liability($conn, $arsCompanyId);
$journalStatuses = [];
$journalIds = array_values(array_unique(array_filter(array_map(
    static fn(array $row): int => (int)($row['journal_id'] ?? 0),
    $documents
))));
if ($journalIds) {
    $placeholders = implode(',', array_fill(0, count($journalIds), '?'));
    $journalStmt = $conn->prepare(
        "SELECT id, is_posted, is_reversed FROM re_journal_headers
         WHERE company_id = ? AND id IN ($placeholders)"
    );
    $journalStmt->execute(array_merge([$arsCompanyId], $journalIds));
    foreach ($journalStmt->fetchAll(PDO::FETCH_ASSOC) as $journalRow) {
        $journalStatuses[(int)$journalRow['id']] = !empty($journalRow['is_reversed'])
            ? 'Reversed'
            : (!empty($journalRow['is_posted']) ? 'Posted' : 'Unposted');
    }
}

$openDocBalance = 0.0;
$openDocCount = 0;
foreach ($documents as $d) {
    $bal = (float)($d['balance_due'] ?? 0);
    if ($bal > 0.009) {
        $openDocBalance += $bal;
        $openDocCount++;
    }
}

$pageTitle = 'Financial Reports';
$tabsHtml = '<div class="inline-flex flex-wrap gap-1 rounded-ars-lg border border-ars-border bg-ars-surface p-1">'
    . '<a class="no-underline rounded-ars-md px-3 py-1.5 text-ars-sm font-semibold ' . ($tab === 'documents' ? 'bg-ars-ink text-white' : 'text-ars-muted hover:text-ars-text') . '" href="?tab=documents">Documents</a>'
    . '<a class="no-underline rounded-ars-md px-3 py-1.5 text-ars-sm font-semibold ' . ($tab === 'ar' ? 'bg-ars-ink text-white' : 'text-ars-muted hover:text-ars-text') . '" href="?tab=ar">Outstanding AR</a>'
    . '<a class="no-underline rounded-ars-md px-3 py-1.5 text-ars-sm font-semibold ' . ($tab === 'deposits' ? 'bg-ars-ink text-white' : 'text-ars-muted hover:text-ars-text') . '" href="?tab=deposits">Deposit Liability</a>'
    . '</div>';

ars_shell_begin([
    'title' => 'Financial Reports',
    'subtitle' => ($arsCompany['name'] ?? ('#' . $arsCompanyId)) . ' · ' . $currency
        . ' · Open via Reports hub',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Reports', 'href' => 'reports.php'],
        ['label' => 'Financial Reports'],
    ],
    'actions_html' => '<a class="no-underline inline-flex items-center gap-1 rounded-ars-md border border-ars-border px-3 py-2 text-ars-sm font-semibold text-ars-text hover:border-ars-ink/40" href="reports.php">'
        . ars_ui_icon('arrow-left', ['class' => 'h-4 w-4']) . ' Reports hub</a>',
    'legacy_bootstrap' => true,
]);
?>

<div class="mb-4"><?= $tabsHtml ?></div>

<?php if ($tab === 'documents'): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Documents', (string)count($documents), ['tone' => 'default', 'icon' => 'file-text', 'hint' => 'Matching filters']) ?></div>
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Open invoices', (string)$openDocCount, ['tone' => $openDocCount > 0 ? 'warn' : 'ok', 'icon' => 'alert-circle', 'hint' => 'With balance due']) ?></div>
    <div class="col-12 col-lg-6"><?= ars_ds_stat_tile('Open balance', $currency . ' ' . number_format($openDocBalance, 2), ['tone' => $openDocBalance > 0 ? 'warn' : 'ok', 'icon' => 'wallet', 'hint' => 'Sum of filtered open docs']) ?></div>
</div>

<div class="alert alert-light border small mb-3">
    <i class="bi bi-info-circle me-1 text-ars-primary"></i>
    <strong>Document status</strong> is the ARS lifecycle.
    <strong>Accounting status</strong> shows whether a shared-ledger journal is linked.
</div>

<div class="ars-card mb-3">
    <div class="card-body py-3">
        <form class="row g-2 align-items-end" method="get">
            <input type="hidden" name="tab" value="documents">
            <div class="col-sm-4 col-lg-3">
                <label class="form-label small fw-semibold mb-1">Booking ID</label>
                <input class="form-control form-control-sm" name="booking_id" placeholder="e.g. 96" value="<?= h((string)($filters['booking_id'] ?? '')) ?>">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input class="form-control form-control-sm" name="from" type="date" value="<?= h((string)($filters['from'] ?? '')) ?>">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input class="form-control form-control-sm" name="to" type="date" value="<?= h((string)($filters['to'] ?? '')) ?>">
            </div>
            <div class="col-sm-2 col-lg-2">
                <button class="btn btn-sm btn-ars w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
            <div class="col-auto">
                <a class="btn btn-sm btn-outline-secondary" href="?tab=documents">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="ars-card">
<div class="table-responsive">
    <table class="table table-sm ars-table ars-mobile-cards mb-0 align-middle">
        <thead>
            <tr>
                <th>Number</th>
                <th>Type</th>
                <th>Booking</th>
                <th>Document</th>
                <th>Accounting</th>
                <th>Date</th>
                <th class="text-end">Total (<?= h($currency) ?>)</th>
                <th class="text-end">Balance</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($documents as $d): ?>
            <?php
            $journalId = (int)($d['journal_id'] ?? 0);
            $accountingStatus = $journalId > 0 ? ($journalStatuses[$journalId] ?? 'Journal unavailable') : 'Unposted';
            $docStatus = strtolower((string)($d['status'] ?? ''));
            $docTone = $docStatus === 'paid' ? 'bg-success'
                : ($docStatus === 'posted' ? 'bg-primary'
                : ($docStatus === 'reversed' ? 'bg-danger' : 'bg-secondary'));
            $accountingTone = $accountingStatus === 'Posted'
                ? 'bg-success'
                : ($accountingStatus === 'Reversed' ? 'bg-danger' : 'bg-warning text-dark');
            $bal = (float)($d['balance_due'] ?? 0);
            ?>
            <tr class="<?= $bal > 0.009 ? 'table-warning' : '' ?>">
                <td data-label="Number" class="fw-semibold font-monospace small"><?= h($d['document_number']) ?></td>
                <td data-label="Type"><?= h(ucwords(str_replace('_', ' ', $d['document_type']))) ?></td>
                <td data-label="Booking"><a class="text-decoration-none fw-semibold" href="booking_view.php?id=<?= (int)$d['booking_id'] ?>"><?= h($d['booking_number']) ?></a></td>
                <td data-label="Document"><span class="badge <?= $docTone ?>"><?= h(ucwords(str_replace('_', ' ', $d['status']))) ?></span></td>
                <td data-label="Accounting"><span class="badge <?= $accountingTone ?>"><?= h($accountingStatus) ?></span></td>
                <td data-label="Date" class="text-nowrap"><?= h($d['document_date']) ?></td>
                <td data-label="Total" class="text-end ars-tabular"><?= number_format((float)$d['total_amount'], 2) ?></td>
                <td data-label="Balance" class="text-end ars-tabular fw-semibold <?= $bal > 0.009 ? 'text-danger' : 'text-success' ?>"><?= number_format($bal, 2) ?></td>
                <td data-label=""><a class="btn btn-sm btn-ars-outline text-decoration-none" href="financial_document_view.php?id=<?= (int)$d['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$documents): ?><tr><td colspan="9" class="text-center text-muted py-4">No financial documents match these filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<?php elseif ($tab === 'ar'): ?>
<div class="row g-3 mb-3">
    <div class="col-md-6"><?= ars_ds_stat_tile('Outstanding AR', $currency . ' ' . number_format((float)$ar['total_balance'], 2), ['tone' => ((float)$ar['total_balance'] > 0 ? 'warn' : 'ok'), 'icon' => 'wallet', 'hint' => count($ar['rows']) . ' open document(s)']) ?></div>
    <div class="col-md-6"><?= ars_ds_stat_tile('Open documents', (string)count($ar['rows']), ['tone' => 'default', 'icon' => 'file-text', 'hint' => 'Balance due &gt; 0']) ?></div>
</div>
<div class="ars-card"><div class="table-responsive">
    <table class="table table-sm ars-table ars-mobile-cards mb-0 align-middle">
        <thead><tr><th>Document</th><th>Booking</th><th>Status</th><th class="text-end">Balance (<?= h($currency) ?>)</th></tr></thead>
        <tbody>
        <?php foreach ($ar['rows'] as $r): ?>
            <tr>
                <td data-label="Document"><a class="text-decoration-none fw-semibold" href="financial_document_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['document_number']) ?></a></td>
                <td data-label="Booking"><a class="text-decoration-none" href="booking_view.php?id=<?= (int)($r['booking_id'] ?? 0) ?>"><?= h($r['booking_number']) ?></a></td>
                <td data-label="Status"><span class="badge bg-secondary"><?= h(ucwords(str_replace('_', ' ', $r['status']))) ?></span></td>
                <td data-label="Balance" class="text-end fw-semibold text-danger ars-tabular"><?= number_format((float)$r['balance_due'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($ar['rows'])): ?><tr><td colspan="4" class="text-center text-muted py-4">No outstanding receivables.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>

<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-md-6"><?= ars_ds_stat_tile('Open deposit liability', $currency . ' ' . number_format((float)$dep['net_liability'], 2), ['tone' => 'info', 'icon' => 'shield', 'hint' => 'Held for guests']) ?></div>
    <div class="col-md-6"><?= ars_ds_stat_tile('Bookings with deposit', (string)count($dep['rows']), ['tone' => 'default', 'icon' => 'home', 'hint' => 'With open liability rows']) ?></div>
</div>
<div class="ars-card"><div class="table-responsive">
    <table class="table table-sm ars-table ars-mobile-cards mb-0 align-middle">
        <thead><tr><th>Booking</th><th class="text-end">Received</th><th class="text-end">Refunded</th><th class="text-end">Forfeited</th><th class="text-end">Open</th></tr></thead>
        <tbody>
        <?php foreach ($dep['rows'] as $r): ?>
            <tr>
                <td data-label="Booking"><a class="text-decoration-none fw-semibold" href="booking_view.php?id=<?= (int)$r['booking_id'] ?>">#<?= (int)$r['booking_id'] ?></a></td>
                <td data-label="Received" class="text-end ars-tabular"><?= number_format((float)$r['received'], 2) ?></td>
                <td data-label="Refunded" class="text-end ars-tabular"><?= number_format((float)$r['refunded'], 2) ?></td>
                <td data-label="Forfeited" class="text-end ars-tabular"><?= number_format((float)$r['forfeited'], 2) ?></td>
                <td data-label="Open" class="text-end fw-semibold ars-tabular"><?= number_format((float)$r['open_liability'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($dep['rows'])): ?><tr><td colspan="5" class="text-center text-muted py-4">No open deposit liability activity.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?php endif; ?>

<?php ars_shell_end(); ?>
