<?php
/**
 * Construction Module — Supplier Payment Detail (+ Advance History)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_advance_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: supplier_payments.php'); exit; }

$hasPayAccountColumn = co_payment_account_column_ready($conn);
$accountJoin = $hasPayAccountColumn ? "LEFT JOIN re_chart_of_accounts coa ON coa.id = sp.pay_account_id AND coa.company_id = sp.company_id" : "";
$accountSelect = $hasPayAccountColumn ? "coa.account_code, coa.account_name," : "NULL AS account_code, NULL AS account_name,";

$stmt = $conn->prepare("
    SELECT sp.*, s.supplier_name, {$accountSelect}
           jh.journal_number, jh.is_reversed AS journal_reversed
    FROM co_supplier_payments sp
    JOIN co_suppliers s ON s.id = sp.supplier_id
    {$accountJoin}
    LEFT JOIN re_journal_headers jh ON jh.id = sp.journal_id
    WHERE sp.id = ? AND sp.company_id = ?
");
$stmt->execute([$id, $cid]);
$pay = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pay) { header('Location: supplier_payments.php'); exit; }

$allocations = [];
if (co_supplier_allocations_ready($conn)) {
    $allocStmt = $conn->prepare("
        SELECT a.*, si.invoice_number, si.invoice_date, si.total AS invoice_total
        FROM co_supplier_payment_allocations a
        JOIN co_supplier_invoices si ON si.id = a.invoice_id AND si.company_id = a.company_id
        WHERE a.company_id = ? AND a.payment_id = ?
        ORDER BY si.invoice_date DESC, a.id DESC
    ");
    $allocStmt->execute([$cid, $id]);
    $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
}
$allocatedTotal = array_sum(array_map(fn($row) => (float)$row['allocated_amount'], $allocations));
$advanceAmount = co_supplier_advance_schema_ready($conn) ? (float)($pay['advance_amount'] ?? 0) : 0.0;
$advanceRemaining = $advanceAmount > 0.005 ? co_supplier_payment_advance_remaining($conn, $cid, $id) : 0.0;
$advanceLifecycle = $advanceAmount > 0.005 ? co_supplier_payment_advance_lifecycle($conn, $cid, $id, $pay) : 'none';
$advanceHistory = $advanceAmount > 0.005 ? co_supplier_advance_history($conn, $cid, $id) : [];
$advanceVatPosted = ($advanceAmount > 0.005 && function_exists('co_supplier_payment_advance_vat_posted'))
    ? co_supplier_payment_advance_vat_posted($conn, $cid, $id) : 0.0;
$canRefundAdvance = $advanceRemaining > 0.005
    && !empty($pay['journal_id'])
    && empty($pay['journal_reversed'])
    && $advanceVatPosted <= 0.005
    && function_exists('co_supplier_advance_refund_table_ready')
    && co_supplier_advance_refund_table_ready($conn);
$canCreateAdvanceVat = $advanceRemaining > 0.005
    && !empty($pay['journal_id'])
    && empty($pay['journal_reversed'])
    && function_exists('co_supplier_advance_vat_table_ready')
    && co_supplier_advance_vat_table_ready($conn);

$appBase = (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';
$journalUrl = $appBase . '/modules/construction/journal_entry_view.php?id=';

$pageTitle = 'Supplier Payment';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="supplier_view.php?id=<?= (int)$pay['supplier_id'] ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Supplier</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0">Payment — <?= co_format_money($pay['amount']) ?></h1>
            <p class="text-muted mb-0"><?= h($pay['supplier_name']) ?> · <?= h($pay['payment_date']) ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($canCreateAdvanceVat): ?>
            <a href="supplier_advance_vat_edit.php?payment_id=<?= $id ?>" class="btn btn-outline-primary">Advance VAT</a>
            <?php endif; ?>
            <?php if ($canRefundAdvance): ?>
            <a href="supplier_advance_refund_add.php?payment_id=<?= $id ?>" class="btn btn-outline-warning">Refund Advance</a>
            <?php elseif ($advanceRemaining > 0.005 && $advanceVatPosted > 0.005): ?>
            <span class="btn btn-outline-secondary disabled" title="Reverse posted Advance VAT before refunding">Refund blocked (VAT)</span>
            <?php endif; ?>
            <a href="supplier_payment_edit.php?id=<?= $id ?>" class="btn btn-outline-primary">Edit</a>
            <a href="supplier_payment_delete.php?id=<?= $id ?>" class="btn btn-outline-danger">Reverse</a>
        </div>
    </div>
</div>

<div class="card card-round mb-3">
    <div class="card-header bg-white"><h6 class="mb-0">Payment Details</h6></div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <tr><td class="text-muted" style="width:30%">Supplier</td><td><a href="supplier_view.php?id=<?= (int)$pay['supplier_id'] ?>"><?= h($pay['supplier_name']) ?></a></td></tr>
            <tr><td class="text-muted">Payment Date</td><td><?= h($pay['payment_date']) ?></td></tr>
            <tr><td class="text-muted">Amount</td><td><strong><?= co_format_money($pay['amount']) ?></strong></td></tr>
            <tr><td class="text-muted">Paid From</td><td><?= !empty($pay['account_code']) ? h($pay['account_code'] . ' — ' . $pay['account_name']) : '—' ?></td></tr>
            <tr><td class="text-muted">Reference</td><td><?= h($pay['reference'] ?: '—') ?></td></tr>
            <tr><td class="text-muted">Allocated to Invoices</td><td><?= co_format_money($allocatedTotal) ?></td></tr>
            <?php if ($advanceAmount > 0.005): ?>
            <tr><td class="text-muted">Advance Created</td><td><?= co_format_money($advanceAmount) ?></td></tr>
            <tr><td class="text-muted">Advance Remaining</td><td><?= co_format_money($advanceRemaining) ?></td></tr>
            <tr><td class="text-muted">Advance Lifecycle</td><td><span class="badge bg-info text-dark"><?= h(co_supplier_payment_advance_lifecycle_label($advanceLifecycle)) ?></span></td></tr>
            <?php endif; ?>
            <tr><td class="text-muted">GL Journal</td><td>
                <?php if (!empty($pay['journal_id'])): ?>
                    <?php if (!empty($pay['journal_reversed'])): ?>
                    <span class="badge bg-secondary">Reversed</span>
                    <?php else: ?>
                    <a href="<?= h($journalUrl . (int)$pay['journal_id']) ?>" target="_blank" class="badge bg-success text-decoration-none"><?= h($pay['journal_number'] ?: 'Posted') ?></a>
                    <?php endif; ?>
                <?php else: ?>
                <span class="badge bg-secondary">Not posted</span>
                <?php endif; ?>
            </td></tr>
        </table>
        </div>
    </div>
</div>

<?php if ($allocations): ?>
<div class="card card-round mb-3">
    <div class="card-header bg-white"><h6 class="mb-0">Invoice Allocations</h6></div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Invoice #</th><th>Date</th><th class="text-end">Invoice Total</th><th class="text-end">Allocated</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($allocations as $alloc): ?>
            <tr>
                <td><?= h($alloc['invoice_number']) ?></td>
                <td><?= h($alloc['invoice_date']) ?></td>
                <td class="text-end"><?= co_format_money($alloc['invoice_total']) ?></td>
                <td class="text-end"><?= co_format_money($alloc['allocated_amount']) ?></td>
                <td class="text-end"><a href="supplier_invoice_view.php?id=<?= (int)$alloc['invoice_id'] ?>" class="btn btn-sm btn-outline-primary">View Invoice</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($advanceHistory): ?>
<div class="card card-round mb-3">
    <div class="card-header bg-white"><h6 class="mb-0">Advance History</h6></div>
    <div class="card-body p-0">
        <div class="list-group list-group-flush">
            <?php foreach ($advanceHistory as $ev): ?>
            <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-semibold">
                        <?php if (!empty($ev['link'])): ?>
                        <a href="<?= h($ev['link']) ?>"><?= h($ev['label']) ?></a>
                        <?php else: ?>
                        <?= h($ev['label']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted">
                        <?= h($ev['date'] ?: '—') ?>
                        <?php if (!empty($ev['ref']) && $ev['event'] !== 'remaining'): ?> · <?= h($ev['ref']) ?><?php endif; ?>
                        <?php if ($ev['event'] === 'remaining'): ?> · <?= h($ev['ref']) ?><?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <div class="fw-semibold"><?= co_format_money($ev['amount']) ?></div>
                    <span class="badge bg-<?= in_array($ev['status'], ['posted','open'], true) ? 'success' : ($ev['status'] === 'closed' ? 'secondary' : 'light text-dark') ?>"><?= h(ucfirst((string)$ev['status'])) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
