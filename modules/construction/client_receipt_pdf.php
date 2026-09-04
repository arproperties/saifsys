<?php
/**
 * Construction client payment receipt — Print / PDF.
 * Uses co_receipt_load_view() so content matches client_payment_receipt.php.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_pdf_helpers.php';
require_once __DIR__ . '/includes/construction_receipt_view_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

$cid = current_company_id($conn);
if (!$cid || (int)$cid <= 0) {
    http_response_code(400);
    exit('Company context is required.');
}
$cid = (int)$cid;
$paymentId = (int)($_GET['id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(404);
    exit('Receipt not found.');
}

try {
    $view = co_receipt_load_view($conn, $cid, $paymentId);
} catch (Throwable $e) {
    http_response_code(404);
    exit('Receipt not found.');
}

$company = co_pdf_company($conn, $cid);
$docSettings = co_pdf_document_settings($conn, $cid);
$logo = co_pdf_logo_data_uri($company['logo_path']);
$currency = $company['currency'] ?: 'AED';
$receiptNo = $view['receipt_number'];
$filename = $receiptNo . '-receipt.pdf';
$p = $view['payment'];
$statusLabel = (string)($view['status_label'] ?? co_receipt_status_display_label((string)($view['status'] ?? ''), $p));
$isPrepaidVat = !empty($view['is_prepaid_vat']);

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt <?= h($receiptNo) ?></title>
    <style><?= co_pdf_base_styles() ?>
        .section-title { font-size:12px; font-weight:700; margin:16px 0 6px; border-bottom:1px solid #ccc; padding-bottom:3px; }
        .meta td { padding:2px 6px 2px 0; vertical-align:top; }
        .meta th { text-align:left; font-weight:600; width:38%; padding:2px 6px 2px 0; color:#444; }
        .note { margin:10px 0; padding:8px 10px; border:1px solid #bcd; background:#f4f8fc; font-size:11px; line-height:1.45; }
    </style>
</head>
<body>
<div class="doc">
    <div class="top">
        <div class="top-left">
            <?php if ($logo && ($docSettings['show_logo'] ?? '1') === '1'): ?><img class="logo" src="<?= h($logo) ?>" alt="Logo"><?php endif; ?>
            <div class="value"><?= h($company['name']) ?></div>
            <?php if ($company['address'] && ($docSettings['show_address'] ?? '1') === '1'): ?><div class="muted"><?= h($company['address']) ?></div><?php endif; ?>
            <?php if (($docSettings['show_phone'] ?? '1') === '1' && $company['phone']): ?><div class="muted">Phone: <?= h($company['phone']) ?></div><?php endif; ?>
            <?php if (($docSettings['show_email'] ?? '1') === '1' && $company['email']): ?><div class="muted">Email: <?= h($company['email']) ?></div><?php endif; ?>
            <?php if ($company['trn'] && ($docSettings['show_trn'] ?? '1') === '1'): ?><div class="muted">TRN: <?= h($company['trn']) ?></div><?php endif; ?>
        </div>
        <div class="top-right">
            <div class="title">PAYMENT RECEIPT</div>
            <div><strong><?= h($receiptNo) ?></strong></div>
            <div class="muted">Payment #<?= (int)$view['payment_id'] ?></div>
            <div class="muted">Date: <?= h((string)$p['payment_date']) ?></div>
            <div class="muted">Status: <?= h($statusLabel) ?></div>
        </div>
    </div>

    <?php if ($isPrepaidVat): ?>
    <div class="note">
        This receipt records VAT collected in advance. The prepaid VAT will be automatically consumed by the monthly Tax Invoices as they are generated.
    </div>
    <?php endif; ?>

    <div class="grid">
        <div class="col">
            <div class="panel">
                <div class="label">Received From</div>
                <div class="value"><?= h($view['client']['name'] ?: 'Customer') ?></div>
                <?php if ($view['client']['address']): ?><div><?= nl2br(h($view['client']['address'])) ?></div><?php endif; ?>
                <?php if ($view['client']['phone']): ?><div>Phone: <?= h($view['client']['phone']) ?></div><?php endif; ?>
                <?php if ($view['client']['email']): ?><div>Email: <?= h($view['client']['email']) ?></div><?php endif; ?>
                <?php if ($view['client']['trn']): ?><div>TRN: <?= h($view['client']['trn']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="col">
            <div class="panel">
                <div class="label">Payment Details</div>
                <table class="meta">
                    <tr><th>Status</th><td><?= h($statusLabel) ?></td></tr>
                    <tr><th>Contract</th><td><?= h($view['contract']['contract_number'] ?? '—') ?></td></tr>
                    <tr><th>Funding</th><td><?= h(implode(', ', $view['funding_methods_summary']) ?: '—') ?></td></tr>
                    <tr><th>Receiving GL</th><td><?= h($view['receiving_gl']['label']) ?></td></tr>
                    <tr><th>Bank / transfer ref</th><td><?= h(implode(', ', $view['bank_transfer_refs']) ?: '—') ?></td></tr>
                    <tr><th>Cheque(s)</th><td><?= h(implode(', ', $view['cheque_numbers']) ?: '—') ?></td></tr>
                    <?php if ($view['contract_outstanding'] !== null): ?>
                        <tr><th>Contract outstanding</th><td><?= h($currency) ?> <?= number_format((float)$view['contract_outstanding'], 2) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="label">Amount In Words</div>
        <?= h(co_pdf_amount_words((float)$view['amount'], $currency)) ?>
    </div>

    <div class="section-title">Funding sources</div>
    <table>
        <thead>
            <tr>
                <th>Method</th>
                <th>Date</th>
                <th>Reference</th>
                <th>Details</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($view['funding'] as $f):
            $m = (string)($f['method'] ?? '');
            $lab = $view['method_labels'][$m] ?? ucwords(str_replace('_', ' ', $m));
            $detail = '';
            if ($m === 'cheque') {
                $detail = trim((string)($f['cheque_number'] ?? ''));
                if ($detail === '' && !empty($f['cheque_id'])) {
                    $detail = '#' . (int)$f['cheque_id'];
                }
                if (!empty($f['cheque_bank'])) {
                    $detail .= ($detail !== '' ? ' · ' : '') . $f['cheque_bank'];
                }
            }
        ?>
            <tr>
                <td><?= h($lab) ?></td>
                <td><?= h((string)($f['funding_date'] ?? '—')) ?></td>
                <td><?= h((string)($f['reference'] ?? '—')) ?></td>
                <td><?= h($detail !== '' ? $detail : '—') ?></td>
                <td class="right"><?= h($currency) ?> <?= number_format((float)($f['amount'] ?? 0), 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="section-title"><?= $isPrepaidVat ? 'Allocation' : 'Allocation — covered invoices' ?></div>
    <table>
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Type</th>
                <th>Due</th>
                <th class="right">Invoice total</th>
                <th class="right">Allocated</th>
                <th class="right">Remaining</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($isPrepaidVat): ?>
            <tr><td colspan="6" class="muted">No invoice allocations — prepaid Output VAT (consumed automatically by monthly Tax Invoices).</td></tr>
        <?php elseif ($view['allocations']): ?>
            <?php foreach ($view['allocations'] as $row): ?>
                <tr>
                    <td><?= h($row['invoice_number']) ?></td>
                    <td><?= h($row['kind_label']) ?></td>
                    <td><?= h((string)($row['due_date'] ?? '—')) ?></td>
                    <td class="right"><?= h($currency) ?> <?= number_format((float)$row['invoice_total'], 2) ?></td>
                    <td class="right"><?= h($currency) ?> <?= number_format((float)$row['allocated_amount'], 2) ?></td>
                    <td class="right"><?= h($currency) ?> <?= number_format((float)$row['invoice_remaining'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="6" class="muted">No invoice allocations<?= $view['credit_created'] > 0.005 ? ' (credit created).' : '.' ?></td></tr>
        <?php endif; ?>
        <tr class="total-row">
            <td colspan="4">Total received</td>
            <td class="right" colspan="2"><?= h($currency) ?> <?= number_format((float)$view['amount'], 2) ?></td>
        </tr>
        </tbody>
    </table>

    <div class="grid" style="margin-top: 28px;">
        <div class="col"><div class="panel"><div class="label">Prepared By</div><br><br>________________________</div></div>
        <div class="col"><div class="panel"><div class="label">Received By</div><br><br>________________________</div></div>
    </div>
    <div class="footer">Computer-generated payment receipt <?= h($receiptNo) ?>.</div>
</div>
</body>
</html>
<?php
$html = ob_get_clean();
co_pdf_output($html, $filename);
