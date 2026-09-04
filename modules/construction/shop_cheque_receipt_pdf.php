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
$chequeId = (int)($_GET['id'] ?? 0);
if ($chequeId <= 0) {
    http_response_code(404);
    exit('Receipt not found.');
}

$stmt = $conn->prepare("
    SELECT ch.*, c.contract_number, c.id AS contract_id, cl.client_name, cl.address, cl.phone, cl.email, cl.tax_number,
           u.shop_number, u.shop_name, i.invoice_number,
           p.payment_date,
           jh.journal_number,
           coa.account_code, coa.account_name
    FROM co_shop_rent_cheques ch
    JOIN co_shop_rental_contracts c ON c.id = ch.contract_id
    JOIN co_clients cl ON cl.id = c.client_id
    JOIN co_shop_units u ON u.id = c.shop_unit_id
    LEFT JOIN co_client_invoices i ON i.id = ch.invoice_id
    LEFT JOIN co_client_payments p ON p.id = ch.payment_id
    LEFT JOIN re_journal_headers jh ON jh.id = ch.journal_id
    LEFT JOIN re_journal_lines jl ON jl.id = (
        SELECT jl2.id
        FROM re_journal_lines jl2
        WHERE jl2.journal_id = ch.journal_id
          AND jl2.company_id = ch.company_id
          AND jl2.debit_amount > 0
        ORDER BY jl2.line_number, jl2.id
        LIMIT 1
    )
    LEFT JOIN re_chart_of_accounts coa ON coa.id = jl.account_id
    WHERE ch.id = ? AND ch.company_id = ? AND ch.status = 'cleared'
");
$stmt->execute([$chequeId, $cid]);
$cheque = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cheque) {
    http_response_code(404);
    exit('Cleared cheque receipt not found.');
}
$multiShops = co_shop_contract_shops_label($conn, $cid, (int)$cheque['contract_id']);
if ($multiShops !== '') {
    $cheque['shop_number'] = $multiShops;
}

$company = co_pdf_company($conn, $cid);
$docSettings = co_pdf_document_settings($conn, $cid);
$logo = co_pdf_logo_data_uri($company['logo_path']);
$currency = $company['currency'] ?: 'AED';
$receiptNo = 'SHOP-CHQ-' . str_pad((string)$chequeId, 6, '0', STR_PAD_LEFT);
$filename = $receiptNo . '-receipt.pdf';
$receiptDate = $cheque['payment_date'] ?: $cheque['cheque_date'];
$receiptType = $cheque['cheque_type'] === 'security_deposit' ? 'Security Deposit Receipt' : 'Rent Receipt';

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($receiptType) ?> <?= h($receiptNo) ?></title>
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
            <div class="title">RECEIPT VOUCHER</div>
            <div><strong><?= h($receiptNo) ?></strong></div>
            <div class="muted"><?= h($receiptType) ?></div>
            <div class="muted">Date: <?= h($receiptDate) ?></div>
            <?php if ($cheque['journal_number']): ?><div class="badge">GL: <?= h($cheque['journal_number']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="grid">
        <div class="col">
            <div class="panel">
                <div class="label">Received From</div>
                <div class="value"><?= h($cheque['client_name']) ?></div>
                <?php if ($cheque['address']): ?><div><?= nl2br(h($cheque['address'])) ?></div><?php endif; ?>
                <?php if ($cheque['phone']): ?><div>Phone: <?= h($cheque['phone']) ?></div><?php endif; ?>
                <?php if ($cheque['email']): ?><div>Email: <?= h($cheque['email']) ?></div><?php endif; ?>
                <?php if ($cheque['tax_number']): ?><div>TRN: <?= h($cheque['tax_number']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="col">
            <div class="panel">
                <div class="label">Shop / Cheque Details</div>
                <div>Shop: <?= h($cheque['shop_number'] . ($cheque['shop_name'] ? ' - ' . $cheque['shop_name'] : '')) ?></div>
                <div>Contract: <?= h($cheque['contract_number']) ?></div>
                <?php if ($cheque['invoice_number']): ?><div>Invoice: <?= h($cheque['invoice_number']) ?></div><?php endif; ?>
                <div>Cheque No.: <?= h($cheque['cheque_number'] ?: '-') ?></div>
                <div>Bank: <?= h($cheque['bank_name'] ?: '-') ?></div>
                <div>Cheque Date: <?= h($cheque['cheque_date']) ?></div>
                <div>Received To: <?= h(trim(($cheque['account_code'] ? $cheque['account_code'] . ' - ' : '') . ($cheque['account_name'] ?: 'Bank/Cash'))) ?></div>
            </div>
        </div>
    </div>

    <table>
        <thead><tr><th>Description</th><th class="right">Amount</th></tr></thead>
        <tbody>
            <tr>
                <td><?= h($receiptType) ?> - <?= h($cheque['contract_number']) ?></td>
                <td class="right"><?= h($currency) ?> <?= number_format((float)$cheque['amount'], 2) ?></td>
            </tr>
            <tr class="total-row"><td>Total Received</td><td class="right"><?= h($currency) ?> <?= number_format((float)$cheque['amount'], 2) ?></td></tr>
        </tbody>
    </table>

    <div class="panel" style="margin-top: 14px;">
        <div class="label">Amount In Words</div>
        <?= h(co_pdf_amount_words((float)$cheque['amount'], $currency)) ?>
    </div>
    <div class="grid" style="margin-top: 28px;">
        <div class="col"><div class="panel"><div class="label">Prepared By</div><br><br>________________________</div></div>
        <div class="col"><div class="panel"><div class="label">Received By</div><br><br>________________________</div></div>
    </div>
    <div class="footer">This is a computer-generated receipt voucher.</div>
</div>
</body>
</html>
<?php
$html = ob_get_clean();
co_pdf_output($html, $filename);
