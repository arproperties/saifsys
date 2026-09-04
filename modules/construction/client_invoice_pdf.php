<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_pdf_helpers.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

$cid = current_company_id($conn) ?: 1;
$invoiceId = (int)($_GET['id'] ?? 0);
if ($invoiceId <= 0) {
    http_response_code(404);
    exit('Invoice not found.');
}

$allocSelect = co_client_allocations_ready($conn) ? "COALESCE(a.paid_amount, 0) AS paid_amount" : "0 AS paid_amount";
$allocJoin = co_client_allocations_ready($conn) ? "
    LEFT JOIN (
        SELECT invoice_id, SUM(allocated_amount) AS paid_amount
        FROM co_client_payment_allocations
        GROUP BY invoice_id
    ) a ON a.invoice_id = i.id
" : "";
$stmt = $conn->prepare("
    SELECT i.*, {$allocSelect},
           c.client_name, c.contact_person, c.email, c.phone, c.address, c.tax_number,
           p.project_code, p.project_name,
           s.period_start, s.period_end,
           COALESCE(rc.contract_number, rc_comm.contract_number) AS contract_number,
           COALESCE(rc.accrual_deferred_rent, 0) AS accrual_deferred_rent,
           COALESCE(rc.id, rc_comm.id) AS shop_contract_id,
           COALESCE(u.shop_number, u_comm.shop_number) AS shop_number,
           COALESCE(u.shop_name, u_comm.shop_name) AS shop_name,
           ch.cheque_number, ch.bank_name, ch.cheque_date
    FROM co_client_invoices i
    JOIN co_clients c ON c.id = i.client_id
    LEFT JOIN co_projects p ON p.id = i.project_id
    LEFT JOIN co_shop_rent_schedules s ON s.id = i.source_id AND i.source_type = 'shop_rental'
    LEFT JOIN co_shop_rental_contracts rc ON rc.id = s.contract_id
    LEFT JOIN co_shop_units u ON u.id = rc.shop_unit_id
    LEFT JOIN co_shop_rent_cheques ch ON ch.id = s.cheque_id
    LEFT JOIN co_shop_rental_contracts rc_comm ON rc_comm.id = i.source_id AND i.source_type = 'shop_commission' AND rc_comm.company_id = i.company_id
    LEFT JOIN co_shop_units u_comm ON u_comm.id = rc_comm.shop_unit_id
    {$allocJoin}
    WHERE i.id = ? AND i.company_id = ?
");
$stmt->execute([$invoiceId, $cid]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}
if (!empty($invoice['shop_contract_id'])) {
    $shopsLabel = co_shop_contract_shops_label($conn, $cid, (int)$invoice['shop_contract_id']);
    if ($shopsLabel !== '') {
        $invoice['shop_number'] = $shopsLabel;
        $invoice['shop_name'] = null;
    }
}

$linesStmt = $conn->prepare("SELECT * FROM co_client_invoice_lines WHERE company_id = ? AND invoice_id = ? ORDER BY line_number, id");
$linesStmt->execute([$cid, $invoiceId]);
$lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$lines) {
    $lines = [[
        'description' => $invoice['description'] ?: co_income_source_label($invoice['source_type'] ?? 'manual'),
        'amount' => (float)($invoice['subtotal'] ?? 0),
    ]];
}

$company = co_pdf_company($conn, $cid);
$docSettings = co_pdf_document_settings($conn, $cid);
$logo = co_pdf_logo_data_uri($company['logo_path']);
$subtotal = (float)($invoice['subtotal'] ?? 0);
if ($subtotal <= 0) {
    $subtotal = max(0, (float)$invoice['total_amount'] - (float)$invoice['vat_amount']);
}
$vat = (float)$invoice['vat_amount'];
$total = (float)$invoice['total_amount'];
$paid = (float)$invoice['paid_amount'];
$balance = max(0, $total - $paid);
$currency = $company['currency'] ?: 'AED';
$filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoice['invoice_number']) . '-tax-invoice.pdf';

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Tax Invoice <?= h($invoice['invoice_number']) ?></title>
    <style><?= co_pdf_base_styles() ?></style>
</head>
<body>
<div class="no-print"><button class="btn" onclick="window.print()">Print / Save PDF</button></div>
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
            <div class="title">TAX INVOICE</div>
            <div><strong><?= h($invoice['invoice_number']) ?></strong></div>
            <div class="muted">Invoice Date: <?= h($invoice['invoice_date']) ?></div>
            <div class="muted">Due Date: <?= h($invoice['due_date'] ?: $invoice['invoice_date']) ?></div>
            <div class="badge"><?= h(ucfirst($invoice['status'])) ?></div>
        </div>
    </div>

    <div class="grid">
        <div class="col">
            <div class="panel">
                <div class="label">Bill To</div>
                <div class="value"><?= h($invoice['client_name']) ?></div>
                <?php if ($invoice['address']): ?><div><?= nl2br(h($invoice['address'])) ?></div><?php endif; ?>
                <?php if ($invoice['phone']): ?><div>Phone: <?= h($invoice['phone']) ?></div><?php endif; ?>
                <?php if ($invoice['email']): ?><div>Email: <?= h($invoice['email']) ?></div><?php endif; ?>
                <?php if ($invoice['tax_number']): ?><div>TRN: <?= h($invoice['tax_number']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="col">
            <div class="panel">
                <div class="label">Invoice Details</div>
                <div>Category: <strong><?= h(co_income_source_label($invoice['source_type'] ?? 'manual')) ?></strong></div>
                <?php if ($invoice['shop_number']): ?><div>Shop: <?= h($invoice['shop_number'] . ($invoice['shop_name'] ? ' - ' . $invoice['shop_name'] : '')) ?></div><?php endif; ?>
                <?php if ($invoice['contract_number']): ?><div>Contract: <?= h($invoice['contract_number']) ?></div><?php endif; ?>
                <?php if ($invoice['period_start']): ?><div>Period: <?= h($invoice['period_start']) ?> to <?= h($invoice['period_end']) ?></div><?php endif; ?>
                <?php if ($invoice['cheque_number']): ?><div>Cheque: <?= h($invoice['cheque_number']) ?> <?= $invoice['bank_name'] ? '(' . h($invoice['bank_name']) . ')' : '' ?></div><?php endif; ?>
            </div>
        </div>
    </div>

    <table>
        <thead><tr><th style="width: 8%;">#</th><th>Description</th><th class="right" style="width: 18%;">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $idx => $line): ?>
            <tr>
                <td><?= (int)$idx + 1 ?></td>
                <td><?= h($line['description']) ?></td>
                <td class="right"><?= h($currency) ?> <?= number_format((float)$line['amount'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="grid" style="margin-top: 14px;">
        <div class="col">
            <div class="panel">
                <div class="label">Amount In Words</div>
                <?= h(co_pdf_amount_words($total, $currency)) ?>
            </div>
        </div>
        <div class="col">
            <table>
                <tr><td>Subtotal</td><td class="right"><?= h($currency) ?> <?= number_format($subtotal, 2) ?></td></tr>
                <tr><td>VAT</td><td class="right"><?= h($currency) ?> <?= number_format($vat, 2) ?></td></tr>
                <tr class="total-row"><td>Total</td><td class="right"><?= h($currency) ?> <?= number_format($total, 2) ?></td></tr>
                <tr><td>Paid</td><td class="right"><?= h($currency) ?> <?= number_format($paid, 2) ?></td></tr>
                <tr><td>Balance</td><td class="right"><?= h($currency) ?> <?= number_format($balance, 2) ?></td></tr>
            </table>
        </div>
    </div>

    <?php if (($docSettings['show_bank_details'] ?? '1') === '1' && ($company['bank_name'] || $company['bank_iban'])): ?>
    <div class="panel">
        <div class="label">Bank Details</div>
        <?= h($company['bank_name']) ?><?= $company['bank_account_no'] ? ' | Account: ' . h($company['bank_account_no']) : '' ?><?= $company['bank_iban'] ? ' | IBAN: ' . h($company['bank_iban']) : '' ?>
    </div>
    <?php endif; ?>
    <div class="footer">This is a computer-generated tax invoice.</div>
</div>
</body>
</html>
<?php
$html = ob_get_clean();
co_pdf_output($html, $filename);
