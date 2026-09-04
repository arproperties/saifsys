<?php
/**
 * ARS Financial Document view — deep link target for Activity Center.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_financial_adapter.php';
require_once __DIR__ . '/includes/ars_shell.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$arsCompany = get_company($conn, $arsCompanyId);
$currencyStmt = $conn->prepare("SELECT currency FROM ars_company_settings WHERE company_id = ? LIMIT 1");
$currencyStmt->execute([$arsCompanyId]);
$companyCurrency = (string)($currencyStmt->fetchColumn() ?: 'AED');

$docId = (int) ($_GET['id'] ?? 0);
$depositId = (int) ($_GET['deposit_id'] ?? 0);
$refundId = (int) ($_GET['refund_id'] ?? 0);

$document = null;
$lines = [];
$transitions = [];
$allocations = [];
$deposit = null;
$refund = null;

if ($docId > 0 && ars_financial_adapter_tables_ready($conn)) {
    $stmt = $conn->prepare("SELECT * FROM ars_financial_documents WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$docId, $arsCompanyId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($document) {
        $ls = $conn->prepare("SELECT * FROM ars_financial_document_lines WHERE document_id = ? AND company_id = ? ORDER BY line_no");
        $ls->execute([$docId, $arsCompanyId]);
        $lines = $ls->fetchAll(PDO::FETCH_ASSOC);

        $ts = $conn->prepare("SELECT * FROM ars_financial_document_transitions WHERE document_id = ? AND company_id = ? ORDER BY id");
        $ts->execute([$docId, $arsCompanyId]);
        $transitions = $ts->fetchAll(PDO::FETCH_ASSOC);

        $as = $conn->prepare("SELECT * FROM ars_payment_allocations WHERE document_id = ? AND company_id = ? ORDER BY id");
        $as->execute([$docId, $arsCompanyId]);
        $allocations = $as->fetchAll(PDO::FETCH_ASSOC);
    }
}

if ($depositId > 0 && ars_financial_adapter_tables_ready($conn)) {
    $stmt = $conn->prepare("SELECT * FROM ars_security_deposits WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$depositId, $arsCompanyId]);
    $deposit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($refundId > 0 && ars_financial_adapter_tables_ready($conn)) {
    $stmt = $conn->prepare("SELECT * FROM ars_refunds WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$refundId, $arsCompanyId]);
    $refund = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$document && !$deposit && !$refund) {
    header('Location: bookings.php');
    exit;
}

$linkedJournalId = (int)($document['journal_id'] ?? $deposit['journal_id'] ?? $refund['journal_id'] ?? 0);
$accountingStatus = 'Unposted';
if ($linkedJournalId > 0) {
    $journalStatusStmt = $conn->prepare(
        "SELECT is_posted, is_reversed FROM re_journal_headers
         WHERE id = ? AND company_id = ? LIMIT 1"
    );
    $journalStatusStmt->execute([$linkedJournalId, $arsCompanyId]);
    $journalStatus = $journalStatusStmt->fetch(PDO::FETCH_ASSOC);
    $accountingStatus = !$journalStatus
        ? 'Journal unavailable'
        : (!empty($journalStatus['is_reversed'])
            ? 'Reversed'
            : (!empty($journalStatus['is_posted']) ? 'Posted' : 'Unposted'));
}
$accountingBadgeClass = $accountingStatus === 'Posted'
    ? 'bg-success'
    : ($accountingStatus === 'Reversed' ? 'bg-danger' : 'bg-warning text-dark');

$pageTitle = $document
    ? ('Document ' . $document['document_number'])
    : ($deposit ? ('Deposit ' . $deposit['deposit_number']) : ('Refund ' . $refund['refund_number']));
$bookingId = (int) ($document['booking_id'] ?? $deposit['booking_id'] ?? $refund['booking_id'] ?? 0);
$fdActions = $bookingId > 0
    ? '<a class="btn btn-ars-outline btn-sm" href="booking_view.php?id=' . $bookingId . '">Back to booking</a>'
    : '';
ars_shell_begin([
    'title' => $pageTitle,
    'subtitle' => 'Company: ' . ($arsCompany['name'] ?? ('#' . $arsCompanyId))
        . ' · Currency: ' . ($document['currency'] ?? $companyCurrency)
        . ' · Document and accounting states are shown separately',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Finance', 'href' => 'financial_reports.php'],
        ['label' => $pageTitle],
    ],
    'actions_html' => $fdActions,
    'legacy_bootstrap' => true,
]);
?>


<?php if ($document): ?>
<div class="ars-card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><strong>Type</strong><br><?= h($document['document_type']) ?></div>
            <div class="col-md-3"><strong>Document status</strong><br><span class="badge bg-secondary"><?= h(ucwords(str_replace('_', ' ', $document['status']))) ?></span></div>
            <div class="col-md-3"><strong>Accounting status</strong><br><span class="badge <?= $accountingBadgeClass ?>"><?= h($accountingStatus) ?></span></div>
            <div class="col-md-3"><strong>Company</strong><br><?= h($arsCompany['name'] ?? ('#' . $arsCompanyId)) ?></div>
            <div class="col-md-3"><strong>Date</strong><br><?= h($document['document_date']) ?></div>
            <div class="col-md-3"><strong>Total</strong><br><?= number_format((float)$document['total_amount'], 2) ?> <?= h($document['currency']) ?></div>
            <div class="col-md-3"><strong>Allocated</strong><br><?= number_format((float)$document['amount_allocated'], 2) ?></div>
            <div class="col-md-3"><strong>Balance</strong><br><?= number_format((float)$document['balance_due'], 2) ?></div>
            <div class="col-md-3">
                <strong>Journal</strong><br>
                <?php if (!empty($document['journal_id'])): ?>
                    <a class="text-decoration-none" href="../realestate/accounting/journal_entry_view.php?id=<?= (int)$document['journal_id'] ?>">#<?= (int)$document['journal_id'] ?></a>
                <?php else: ?>—<?php endif; ?>
            </div>
            <div class="col-md-3"><strong>Idempotency</strong><br><small class="text-muted"><?= h((string)$document['idempotency_key']) ?></small></div>
        </div>
    </div>
</div>

<div class="ars-card mb-3">
    <div class="card-header">Lines</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>#</th><th>Type</th><th>Description</th><th>Role</th><th class="text-end">Amount</th><th class="text-end">VAT</th></tr></thead>
            <tbody>
            <?php foreach ($lines as $ln): ?>
                <tr>
                    <td><?= (int)$ln['line_no'] ?></td>
                    <td><?= h($ln['line_type']) ?></td>
                    <td><?= h($ln['description']) ?></td>
                    <td><code><?= h((string)$ln['account_role']) ?></code></td>
                    <td class="text-end"><?= number_format((float)$ln['line_total'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$ln['vat_amount'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="ars-card h-100">
            <div class="card-header">Status transitions</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($transitions as $tr): ?>
                    <li class="list-group-item small">
                        <?= h((string)$tr['from_status']) ?> → <strong><?= h($tr['to_status']) ?></strong>
                        <span class="text-muted"><?= h($tr['changed_at']) ?></span>
                        <?php if (!empty($tr['note'])): ?><br><?= h($tr['note']) ?><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$transitions): ?><li class="list-group-item text-muted">None</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-6">
        <div class="ars-card h-100">
            <div class="card-header">Allocations</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($allocations as $al): ?>
                    <li class="list-group-item small">
                        Payment #<?= (int)$al['payment_id'] ?> —
                        <?= number_format((float)$al['amount'], 2) ?>
                        (<?= h($al['status']) ?>)
                    </li>
                <?php endforeach; ?>
                <?php if (!$allocations): ?><li class="list-group-item text-muted">None</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($deposit): ?>
<div class="ars-card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><strong>Event</strong><br><?= h($deposit['event_type']) ?></div>
            <div class="col-md-3"><strong>Document status</strong><br><span class="badge bg-secondary"><?= h(ucwords(str_replace('_', ' ', $deposit['status']))) ?></span></div>
            <div class="col-md-3"><strong>Accounting status</strong><br><span class="badge <?= $accountingBadgeClass ?>"><?= h($accountingStatus) ?></span></div>
            <div class="col-md-3"><strong>Amount</strong><br><?= h($companyCurrency) ?> <?= number_format((float)$deposit['amount'], 2) ?></div>
            <div class="col-md-3">
                <strong>Journal</strong><br>
                <?php if (!empty($deposit['journal_id'])): ?>
                    <a class="text-decoration-none" href="../realestate/accounting/journal_entry_view.php?id=<?= (int)$deposit['journal_id'] ?>">#<?= (int)$deposit['journal_id'] ?></a>
                <?php else: ?>—<?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($refund): ?>
<div class="ars-card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><strong>Document status</strong><br><span class="badge bg-secondary"><?= h(ucwords(str_replace('_', ' ', $refund['status']))) ?></span></div>
            <div class="col-md-3"><strong>Accounting status</strong><br><span class="badge <?= $accountingBadgeClass ?>"><?= h($accountingStatus) ?></span></div>
            <div class="col-md-3"><strong>Amount</strong><br><?= h($companyCurrency) ?> <?= number_format((float)$refund['amount'], 2) ?></div>
            <div class="col-md-3"><strong>Method</strong><br><?= h((string)$refund['method']) ?></div>
            <div class="col-md-3">
                <strong>Journal</strong><br>
                <?php if (!empty($refund['journal_id'])): ?>
                    <a class="text-decoration-none" href="../realestate/accounting/journal_entry_view.php?id=<?= (int)$refund['journal_id'] ?>">#<?= (int)$refund['journal_id'] ?></a>
                <?php else: ?>—<?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php ars_shell_end(); ?>
