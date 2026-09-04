<?php
/**
 * Construction — Payment Receipt (primary financial reference document).
 * Phase 5B. Read-only view; PDF uses the same co_receipt_load_view() data.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
require_once __DIR__ . '/includes/construction_receipt_view_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_shop_require_company_id($conn);

$paymentId = (int)($_GET['id'] ?? 0);
if ($paymentId <= 0) {
    header('Location: client_payments.php');
    exit;
}

try {
    $view = co_receipt_load_view($conn, $cid, $paymentId);
} catch (Throwable $e) {
    error_log('client_payment_receipt load failed: ' . $e->getMessage());
    http_response_code(404);
    $pageTitle = 'Receipt not found';
    require_once __DIR__ . '/includes/construction_layout_header.php';
    echo '<div class="alert alert-danger">Receipt could not be loaded for this company.</div>';
    if (defined('APP_ENV') && APP_ENV === 'dev') {
        echo '<div class="alert alert-warning small mb-3"><strong>Dev detail:</strong> '
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
    echo '<a href="client_payments.php" class="btn btn-outline-secondary">Client Receipts</a>';
    require_once __DIR__ . '/includes/construction_layout_footer.php';
    exit;
}

$p = $view['payment'];
$contract = $view['contract'];
$status = (string)$view['status'];
$statusLabel = (string)($view['status_label'] ?? co_receipt_status_display_label($status, $p));
$badge = co_receipt_display_badge_class($status, $p);
$isPrepaidVat = !empty($view['is_prepaid_vat']);

$pageTitle = 'Payment Receipt ' . $view['receipt_number'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <?php if ($view['contract_id']): ?>
                <a href="shop_rental_contract_view.php?id=<?= (int)$view['contract_id'] ?>&amp;tab=finance" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Contract</a>
            <?php else: ?>
                <a href="client_payments.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Client Receipts</a>
            <?php endif; ?>
        </div>
        <h1 class="h4 mb-1">Payment Receipt <?= h($view['receipt_number']) ?></h1>
        <p class="text-muted mb-0 small">
            Primary reference for this payment · Payment #<?= (int)$view['payment_id'] ?>
            · <span class="badge bg-<?= h($badge) ?>"><?= h($statusLabel) ?></span>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= h(co_receipt_pdf_url($paymentId)) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-printer"></i> Print / PDF
        </a>
        <?php if ($view['contract_id']): ?>
            <a href="shop_rental_payment_workspace.php?contract_id=<?= (int)$view['contract_id'] ?>" class="btn btn-outline-secondary btn-sm">Payment Workspace</a>
        <?php endif; ?>
        <a href="client_payments.php<?= $view['client']['id'] ? ('?client_id=' . (int)$view['client']['id']) : '' ?>" class="btn btn-outline-secondary btn-sm">All Receipts</a>
    </div>
</div>

<?php if ($isPrepaidVat): ?>
<div class="alert alert-info mb-3">
    This receipt records VAT collected in advance. The prepaid VAT will be automatically consumed by the monthly Tax Invoices as they are generated.
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card card-round h-100">
            <div class="card-body">
                <div class="small text-muted">Total payment</div>
                <div class="fs-4 fw-semibold"><?= co_format_money($view['amount']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round h-100">
            <div class="card-body">
                <?php if ($isPrepaidVat): ?>
                    <div class="small text-muted">Applied as prepaid VAT</div>
                    <div class="fs-4 fw-semibold text-success"><?= co_format_money($view['amount']) ?></div>
                <?php else: ?>
                    <div class="small text-muted">Allocated to invoices</div>
                    <div class="fs-4 fw-semibold text-success"><?= co_format_money($view['allocated_total']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round h-100">
            <div class="card-body">
                <?php if ($view['is_credit_apply']): ?>
                    <div class="small text-muted">Applied customer credit</div>
                    <div class="fs-4 fw-semibold text-info"><?= co_format_money($view['applied_credit']) ?></div>
                <?php else: ?>
                    <div class="small text-muted">Credit created (overpayment)</div>
                    <div class="fs-4 fw-semibold"><?= co_format_money($view['credit_created']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card card-round mb-3">
            <div class="card-header"><strong>Payment information</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr><th class="w-40 ps-3">Receipt number</th><td><?= h($view['receipt_number']) ?></td></tr>
                    <tr><th class="ps-3">Payment number</th><td>#<?= (int)$view['payment_id'] ?></td></tr>
                    <tr><th class="ps-3">Payment date</th><td><?= h((string)$p['payment_date']) ?></td></tr>
                    <tr><th class="ps-3">Payment status</th><td><span class="badge bg-<?= h($badge) ?>"><?= h($statusLabel) ?></span></td></tr>
                    <tr><th class="ps-3">Funding method(s)</th><td><?= h(implode(', ', $view['funding_methods_summary']) ?: '—') ?></td></tr>
                    <tr><th class="ps-3">Receiving GL / bank</th><td><?= h($view['receiving_gl']['label']) ?></td></tr>
                    <tr><th class="ps-3">Bank transfer reference</th><td><?= h(implode(', ', $view['bank_transfer_refs']) ?: ($p['reference'] ?: '—')) ?></td></tr>
                    <tr><th class="ps-3">Cheque number(s)</th><td><?= h(implode(', ', $view['cheque_numbers']) ?: '—') ?></td></tr>
                    <tr><th class="ps-3">Journal</th><td>
                        <?php if (!empty($view['journal']['id'])): ?>
                            <?= h($view['journal']['number'] ?: ('J#' . $view['journal']['id'])) ?>
                            <?php if (!empty($view['journal']['status'])): ?>
                                <span class="badge bg-light text-dark border"><?= h((string)$view['journal']['status']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td></tr>
                    <?php if ($view['is_credit_apply']): ?>
                        <tr><th class="ps-3">Applied customer credit</th><td><?= co_format_money($view['applied_credit']) ?></td></tr>
                    <?php elseif ($view['credit_created'] > 0.005): ?>
                        <tr><th class="ps-3">Credit created</th><td><?= co_format_money($view['credit_created']) ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-round mb-3">
            <div class="card-header"><strong>Customer &amp; contract</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr><th class="w-40 ps-3">Customer</th><td><?= h($view['client']['name'] ?: '—') ?></td></tr>
                    <?php if ($view['client']['phone']): ?><tr><th class="ps-3">Phone</th><td><?= h($view['client']['phone']) ?></td></tr><?php endif; ?>
                    <?php if ($view['client']['email']): ?><tr><th class="ps-3">Email</th><td><?= h($view['client']['email']) ?></td></tr><?php endif; ?>
                    <tr><th class="ps-3">Contract</th><td>
                        <?php if ($contract): ?>
                            <a href="shop_rental_contract_view.php?id=<?= (int)$contract['id'] ?>"><?= h($contract['contract_number']) ?></a>
                            <span class="badge bg-light text-dark border"><?= h((string)$contract['status']) ?></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td></tr>
                    <?php if ($view['contract_outstanding'] !== null): ?>
                        <tr><th class="ps-3">Contract outstanding (current)</th><td><?= co_format_money($view['contract_outstanding']) ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card card-round mb-3">
    <div class="card-header"><strong>Funding source details</strong></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Reference / cheque</th>
                        <th>Details</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($view['funding'] as $f):
                    $m = (string)($f['method'] ?? '');
                    $lab = $view['method_labels'][$m] ?? ucwords(str_replace('_', ' ', $m));
                    $detail = '';
                    if ($m === 'cheque') {
                        $detail = trim(
                            ($f['cheque_number'] ?? '') . ' '
                            . (!empty($f['cheque_bank']) ? '(' . $f['cheque_bank'] . ')' : '')
                            . (!empty($f['cheque_status']) ? ' · ' . $f['cheque_status'] : '')
                        );
                        if ($detail === '' && !empty($f['cheque_id'])) {
                            $detail = 'Cheque #' . (int)$f['cheque_id'];
                        }
                    } else {
                        $detail = (string)($f['remarks'] ?? '');
                    }
                ?>
                    <tr>
                        <td><?= h($lab) ?></td>
                        <td><?= h((string)($f['funding_date'] ?? '—')) ?></td>
                        <td><?= h((string)($f['reference'] ?? '—')) ?></td>
                        <td class="small text-muted"><?= h($detail !== '' ? $detail : '—') ?></td>
                        <td class="text-end"><?= co_format_money($f['amount'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <td colspan="4" class="text-end">Funding total</td>
                        <td class="text-end"><?= co_format_money(array_sum(array_map(static fn($r) => (float)($r['amount'] ?? 0), $view['funding']))) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div class="card card-round mb-3">
    <div class="card-header"><strong><?= $isPrepaidVat ? 'Allocation details' : 'Allocation details — covered invoices' ?></strong></div>
    <div class="card-body p-0">
        <?php if ($isPrepaidVat): ?>
            <p class="p-3 text-muted mb-0">
                No invoice allocations — this amount was recorded as prepaid Output VAT and will be applied automatically when monthly Tax Invoices are generated.
            </p>
        <?php elseif (!$view['allocations']): ?>
            <p class="p-3 text-muted mb-0">No invoice allocations on this receipt<?= $view['credit_created'] > 0.005 ? ' (full amount to customer credit).' : '.' ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice</th>
                            <th>Type</th>
                            <th>Due</th>
                            <th class="text-end">Invoice total</th>
                            <th class="text-end">Allocated here</th>
                            <th class="text-end">Remaining now</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($view['allocations'] as $al): ?>
                        <tr>
                            <td>
                                <?php if (!empty($al['invoice_id'])): ?>
                                    <a href="client_invoice_pdf.php?id=<?= (int)$al['invoice_id'] ?>" target="_blank"><?= h($al['invoice_number']) ?></a>
                                <?php else: ?>
                                    <?= h($al['invoice_number']) ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark"><?= h($al['kind_label']) ?></span></td>
                            <td><?= h((string)($al['due_date'] ?? '—')) ?></td>
                            <td class="text-end"><?= co_format_money($al['invoice_total']) ?></td>
                            <td class="text-end"><?= co_format_money($al['allocated_amount']) ?></td>
                            <td class="text-end"><?= co_format_money($al['invoice_remaining']) ?></td>
                            <td><?= h((string)($al['invoice_status'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold">
                            <td colspan="4" class="text-end">Total allocated</td>
                            <td class="text-end"><?= co_format_money($view['allocated_total']) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($view['journal']['id'])): ?>
<div class="card card-round mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Journal details</strong>
        <span class="small text-muted">
            <?= h($view['journal']['number'] ?: ('J#' . $view['journal']['id'])) ?>
            <?php if (!empty($view['journal']['date'])): ?> · <?= h((string)$view['journal']['date']) ?><?php endif; ?>
            <?php if (!empty($view['journal']['status'])): ?>
                · <span class="badge bg-light text-dark border"><?= h((string)$view['journal']['status']) ?></span>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($view['journal']['description'])): ?>
            <div class="px-3 py-2 small border-bottom text-muted"><?= h($view['journal']['description']) ?></div>
        <?php endif; ?>
        <?php if (empty($view['journal']['lines'])): ?>
            <p class="p-3 text-muted mb-0">No journal lines found for this receipt.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:3rem">#</th>
                            <th>Account</th>
                            <th>Description</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($view['journal']['lines'] as $line): ?>
                        <tr>
                            <td class="text-muted"><?= (int)$line['line_number'] ?></td>
                            <td><?= h($line['account_label']) ?></td>
                            <td class="small"><?= h($line['description'] !== '' ? $line['description'] : '—') ?></td>
                            <td class="text-end"><?= $line['debit'] > 0.005 ? co_format_money($line['debit']) : '—' ?></td>
                            <td class="text-end"><?= $line['credit'] > 0.005 ? co_format_money($line['credit']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold">
                            <td colspan="3" class="text-end">Totals</td>
                            <td class="text-end"><?= co_format_money($view['journal']['total_debit'] ?? 0) ?></td>
                            <td class="text-end"><?= co_format_money($view['journal']['total_credit'] ?? 0) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card card-round mb-3">
    <div class="card-header"><strong>Audit information</strong></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <tbody>
            <tr><th class="w-40 ps-3">Recorded by</th><td><?= h($view['created_by_name'] ?: 'System') ?></td></tr>
            <tr><th class="ps-3">Recorded at</th><td><?= h((string)($view['created_at'] ?? '—')) ?></td></tr>
            <?php if (!empty($view['voided_at'])): ?>
                <tr><th class="ps-3">Voided at</th><td><?= h((string)$view['voided_at']) ?></td></tr>
                <tr><th class="ps-3">Void reason</th><td><?= h((string)($view['void_reason'] ?? '—')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ($view['events']): ?>
            <div class="border-top px-3 py-2">
                <div class="small text-muted mb-2">Payment events</div>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($view['events'] as $ev): ?>
                        <li class="mb-1">
                            <span class="badge bg-light text-dark border"><?= h((string)$ev['event_type']) ?></span>
                            <?= h((string)$ev['created_at']) ?>
                            · <?= h((string)($ev['created_by_name'] ?? '—')) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.w-40 { width: 40%; }
</style>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
