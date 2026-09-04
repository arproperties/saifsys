<?php
/**
 * Real Estate Module - Invoice View
 * View invoice details and generate PDF
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/re_pdf_helpers.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

$invoiceId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$export = $_GET['export'] ?? '';

if (!$invoiceId) {
    header('Location: billing_invoices.php');
    exit;
}

// Get invoice details
$invoice = $conn->prepare("
    SELECT 
        i.*,
        l.lease_number,
        l.accounting_mode,
        l.start_date as lease_start,
        l.end_date as lease_end,
        l.monthly_rent,
        u.unit_number,
        u.unit_type,
        u.area_sqm,
        b.name as building_name,
        b.address as building_address,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        t.id_number,
        u2.username as created_by_name
    FROM re_invoices i
    JOIN re_leases l ON l.id = i.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN user u2 ON u2.id = i.created_by
    WHERE i.id = ? AND i.company_id = ?
");
$invoice->execute([$invoiceId, $currentCompanyId]);
$invoice = $invoice->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header('Location: billing_invoices.php');
    exit;
}

// Get invoice items
$invoiceItems = $conn->prepare("
    SELECT * FROM re_invoice_items
    WHERE invoice_id = ?
    ORDER BY display_order, id
");
$invoiceItems->execute([$invoiceId]);
$invoiceItems = $invoiceItems->fetchAll(PDO::FETCH_ASSOC);

// Get payments for this invoice
$payments = $conn->prepare("
    SELECT * FROM re_payments
    WHERE invoice_id = ?
    ORDER BY payment_date DESC
");
$payments->execute([$invoiceId]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

// Handle PDF export (professional Dompdf / print-friendly HTML)
if ($export === 'pdf') {
    $company = re_pdf_company($conn, $currentCompanyId);
    $docSettings = re_pdf_document_settings($conn, $currentCompanyId);
    $logo = re_pdf_logo_data_uri((string)($company['logo_path'] ?? ''));
    $currency = $company['currency'] ?: 'AED';
    $subtotal = (float)($invoice['subtotal'] ?? 0);
    $taxAmount = (float)($invoice['tax_amount'] ?? 0);
    $discountAmount = (float)($invoice['discount_amount'] ?? 0);
    $total = (float)($invoice['total_amount'] ?? 0);
    $paid = (float)($invoice['paid_amount'] ?? 0);
    $outstanding = (float)($invoice['outstanding_amount'] ?? max(0, $total - $paid));
    $statusRaw = strtolower((string)($invoice['status'] ?? ''));
    $statusLabel = ucfirst((string)($invoice['status'] ?? ''));
    $statusBadgeClass = 'badge-sent';
    if (in_array($statusRaw, ['paid', 'cleared'], true)) {
        $statusBadgeClass = 'badge-paid';
    } elseif (in_array($statusRaw, ['overdue', 'partial', 'unpaid', 'sent', 'issued'], true) && $outstanding > 0.005) {
        $statusBadgeClass = $statusRaw === 'sent' || $statusRaw === 'issued' ? 'badge-sent' : 'badge-due';
    }
    $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$invoice['invoice_number']) . '-tax-invoice.pdf';

    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Tax Invoice <?= htmlspecialchars((string)$invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></title>
    <style><?= re_pdf_document_styles() ?></style>
</head>
<body>
<div class="doc">
    <div class="accent"></div>
    <div class="top">
        <div class="top-left">
            <?= re_pdf_letterhead_html($company, $docSettings, $logo) ?>
        </div>
        <div class="top-right">
            <div class="title">TAX INVOICE</div>
            <table class="doc-meta">
                <tr><td class="k">Invoice #</td><td class="v"><?= htmlspecialchars((string)$invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><td class="k">Invoice date</td><td class="v"><?= htmlspecialchars(re_pdf_fmt_date((string)$invoice['invoice_date']), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><td class="k">Due date</td><td class="v"><?= htmlspecialchars(re_pdf_fmt_date((string)$invoice['due_date']), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php if (!empty($invoice['lease_number'])): ?>
                <tr><td class="k">Lease</td><td class="v"><?= htmlspecialchars((string)$invoice['lease_number'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php endif; ?>
            </table>
            <div style="margin-top:8px;"><span class="badge <?= htmlspecialchars($statusBadgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></div>
        </div>
    </div>

    <div class="grid parties">
        <div class="col">
            <div class="party">
                <div class="label">Bill To</div>
                <div class="value"><?= htmlspecialchars(trim($invoice['first_name'] . ' ' . $invoice['last_name']), ENT_QUOTES, 'UTF-8') ?></div>
                <div><?= htmlspecialchars((string)$invoice['building_name'], ENT_QUOTES, 'UTF-8') ?>, Unit <?= htmlspecialchars((string)$invoice['unit_number'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php if (!empty($invoice['building_address'])): ?>
                    <div class="muted"><?= htmlspecialchars((string)$invoice['building_address'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if (!empty($invoice['phone'])): ?><div class="muted">Phone: <?= htmlspecialchars((string)$invoice['phone'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if (!empty($invoice['email'])): ?><div class="muted">Email: <?= htmlspecialchars((string)$invoice['email'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if (!empty($invoice['id_number'])): ?><div class="muted">ID / TRN: <?= htmlspecialchars((string)$invoice['id_number'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            </div>
        </div>
        <div class="col">
            <div class="party">
                <div class="label">Property / Lease</div>
                <div class="value"><?= htmlspecialchars((string)$invoice['building_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div>Unit <?= htmlspecialchars((string)$invoice['unit_number'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php if (!empty($invoice['lease_start'])): ?>
                    <div class="muted">Period: <?= htmlspecialchars(re_pdf_fmt_date((string)$invoice['lease_start']), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(re_pdf_fmt_date((string)$invoice['lease_end']), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="muted">Currency: <?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>

    <table class="txn">
        <thead>
            <tr>
                <th style="width:6%;">#</th>
                <th>Item &amp; description</th>
                <th class="right" style="width:10%;">Qty</th>
                <th class="right" style="width:16%;">Rate</th>
                <th class="right" style="width:16%;">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($invoiceItems as $idx => $item): ?>
            <tr class="<?= ($idx % 2) === 1 ? 'alt' : '' ?>">
                <td><?= (int)$idx + 1 ?></td>
                <td>
                    <strong><?= htmlspecialchars((string)$item['item_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if (!empty($item['item_description'])): ?>
                        <div class="muted"><?= htmlspecialchars((string)$item['item_description'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </td>
                <td class="right"><?= number_format((float)$item['quantity'], 2) ?></td>
                <td class="right"><?= number_format((float)$item['unit_price'], 2) ?></td>
                <td class="right"><?= number_format((float)$item['line_total'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$invoiceItems): ?>
            <tr><td colspan="5" class="muted" style="text-align:center;">No line items.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="grid" style="margin-top:8px;">
        <div class="col">
            <div class="party" style="margin-bottom:0;">
                <div class="label">Amount in words</div>
                <div><?= htmlspecialchars(re_pdf_amount_words($total, $currency), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
        <div class="col">
            <table class="totals">
                <tr>
                    <td class="label-cell">Subtotal</td>
                    <td class="right"><?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?> <?= number_format($subtotal, 2) ?></td>
                </tr>
                <?php if ($taxAmount > 0.005): ?>
                <tr>
                    <td class="label-cell">VAT<?= isset($invoice['tax_rate']) ? ' (' . number_format((float)$invoice['tax_rate'], 2) . '%)' : '' ?></td>
                    <td class="right"><?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?> <?= number_format($taxAmount, 2) ?></td>
                </tr>
                <?php else: ?>
                <tr>
                    <td class="label-cell">VAT</td>
                    <td class="right"><?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?> 0.00</td>
                </tr>
                <?php endif; ?>
                <?php if ($discountAmount > 0.005): ?>
                <tr>
                    <td class="label-cell">Discount</td>
                    <td class="right">-<?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?> <?= number_format($discountAmount, 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td>Total</td>
                    <td class="right"><?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?> <?= number_format($total, 2) ?></td>
                </tr>
                <tr>
                    <td class="label-cell">Payment made</td>
                    <td class="right"><?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?> <?= number_format($paid, 2) ?></td>
                </tr>
                <tr class="due-row">
                    <td>Balance due</td>
                    <td class="right"><?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?> <?= number_format($outstanding, 2) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <?php if (($docSettings['show_bank_details'] ?? '1') === '1' && ($company['bank_name'] || $company['bank_iban'])): ?>
    <div class="bank">
        <div class="label">Bank details for payment</div>
        <div><strong><?= htmlspecialchars((string)$company['bank_name'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <?php if (!empty($company['bank_account_no'])): ?><div>Account: <?= htmlspecialchars((string)$company['bank_account_no'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if (!empty($company['bank_iban'])): ?><div>IBAN: <?= htmlspecialchars((string)$company['bank_iban'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if (!empty($company['bank_swift'])): ?><div>SWIFT: <?= htmlspecialchars((string)$company['bank_swift'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="footer">This is a computer-generated tax invoice from <?= htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') ?>.</div>
</div>
</body>
</html>
    <?php
    $html = ob_get_clean();
    re_pdf_output($html, $filename);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Invoice #' . h($invoice['invoice_number']);
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<style>
@media print {
    .no-print { display: none; }
}
</style>
<?php
?>

        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div class="page-header-label">Invoice #<?= h($invoice['invoice_number']) ?></div>
            <div>
                <?php if ($invoice['outstanding_amount'] > 0): ?>
                    <?php if (($invoice['accounting_mode'] ?? 'legacy') === 'invoice'): ?>
                        <a href="accounting/receipt_allocation.php?lease_id=<?= (int)$invoice['lease_id'] ?>" class="btn btn-success me-2">
                            <i class="bi bi-cash-coin"></i> Allocate Receipt
                        </a>
                    <?php else: ?>
                        <a href="payment_add.php?invoice_id=<?= $invoiceId ?>&lease_id=<?= $invoice['lease_id'] ?>" class="btn btn-success me-2">
                            <i class="bi bi-cash-coin"></i> Record Payment
                        </a>
                    <?php endif; ?>
                    <a href="accounting/credit_note_add.php?invoice_id=<?= $invoiceId ?>" class="btn btn-outline-warning me-2">
                        <i class="bi bi-arrow-counterclockwise"></i> Credit Note
                    </a>
                <?php endif; ?>
                <a href="?id=<?= $invoiceId ?>&export=pdf" target="_blank" class="btn btn-primary">
                    <i class="bi bi-file-pdf"></i> View PDF
                </a>
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <?php if (($invoice['accounting_mode'] ?? 'legacy') === 'invoice'): ?>
                    <div class="alert alert-info no-print">
                        This invoice belongs to an Invoice Mode lease. Cleared money should be recorded through Receipt Allocation, then allocated against this invoice.
                    </div>
                <?php endif; ?>
                <div class="row">
                    <div class="col-md-6">
                        <h5>Bill To:</h5>
                        <p>
                            <strong><?= h($invoice['first_name'] . ' ' . $invoice['last_name']) ?></strong><br>
                            <?= h($invoice['building_name']) ?><br>
                            Unit <?= h($invoice['unit_number']) ?><br>
                            <?php if ($invoice['phone']): ?>
                                Phone: <?= h($invoice['phone']) ?><br>
                            <?php endif; ?>
                            <?php if ($invoice['email']): ?>
                                Email: <?= h($invoice['email']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6 text-end">
                        <h5>Invoice Details:</h5>
                        <p>
                            <strong>Invoice #:</strong> <?= h($invoice['invoice_number']) ?><br>
                            <strong>Date:</strong> <?= date('M d, Y', strtotime($invoice['invoice_date'])) ?><br>
                            <strong>Due Date:</strong> <?= date('M d, Y', strtotime($invoice['due_date'])) ?><br>
                            <strong>Status:</strong> 
                            <span class="badge bg-<?= 
                                $invoice['status'] === 'paid' ? 'success' : 
                                ($invoice['status'] === 'overdue' ? 'danger' : 
                                ($invoice['status'] === 'partial' ? 'warning' : 'info')) 
                            ?>">
                                <?= ucfirst($invoice['status']) ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="mb-3">Invoice Items</h5>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoiceItems as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($item['item_name']) ?></strong>
                                        <?php if ($item['item_description']): ?>
                                            <br><small class="text-muted"><?= h($item['item_description']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= number_format($item['quantity'], 2) ?></td>
                                    <td class="text-end"><?= number_format($item['unit_price'], 2) ?> AED</td>
                                    <td class="text-end"><?= number_format($item['line_total'], 2) ?> AED</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end"><strong>Subtotal:</strong></td>
                                <td class="text-end"><strong><?= number_format($invoice['subtotal'], 2) ?> AED</strong></td>
                            </tr>
                            <?php if ($invoice['tax_amount'] > 0): ?>
                            <tr>
                                <td colspan="3" class="text-end"><strong>Tax (<?= number_format($invoice['tax_rate'], 2) ?>%):</strong></td>
                                <td class="text-end"><strong><?= number_format($invoice['tax_amount'], 2) ?> AED</strong></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($invoice['discount_amount'] > 0): ?>
                            <tr>
                                <td colspan="3" class="text-end"><strong>Discount:</strong></td>
                                <td class="text-end"><strong>-<?= number_format($invoice['discount_amount'], 2) ?> AED</strong></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="table-primary">
                                <td colspan="3" class="text-end"><strong>Total Amount:</strong></td>
                                <td class="text-end"><strong><?= number_format($invoice['total_amount'], 2) ?> AED</strong></td>
                            </tr>
                            <?php if ($invoice['paid_amount'] > 0): ?>
                            <tr>
                                <td colspan="3" class="text-end"><strong>Paid Amount:</strong></td>
                                <td class="text-end text-success"><strong><?= number_format($invoice['paid_amount'], 2) ?> AED</strong></td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-end"><strong>Outstanding:</strong></td>
                                <td class="text-end text-danger"><strong><?= number_format($invoice['outstanding_amount'], 2) ?> AED</strong></td>
                            </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <?php if (!empty($payments)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Payments</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Payment Date</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= date('M d, Y', strtotime($payment['payment_date'])) ?></td>
                                    <td><?= number_format($payment['amount'], 2) ?> AED</td>
                                    <td><?= h($payment['payment_method'] ?: '-') ?></td>
                                    <td><?= h($payment['reference_number'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($invoice['notes']): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h5>Notes</h5>
                <p><?= nl2br(h($invoice['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

