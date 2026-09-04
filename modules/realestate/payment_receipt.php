<?php
/**
 * Payment receipt - professional print/PDF view (Dompdf when available).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/payment_allocation_helper.php';
require_once __DIR__ . '/includes/re_pdf_helpers.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

$paymentId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$paymentId) {
    header('Location: payments.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT p.*, l.lease_number, l.monthly_rent, l.start_date, l.end_date, l.tenant_id,
           u.unit_number, b.name as building_name, b.address as building_address,
           t.first_name, t.last_name, t.phone, t.email,
           li.installment_date, li.amount as installment_amount, li.status as installment_status,
           u2.username as created_by_name
    FROM re_payments p
    JOIN re_leases l ON l.id = p.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_lease_installments li ON li.id = p.installment_id
    LEFT JOIN user u2 ON u2.id = p.created_by
    WHERE p.id = ? AND p.company_id = ?
");
$stmt->execute([$paymentId, $currentCompanyId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    header('Location: payments.php');
    exit;
}

$outstandingBalance = 0.0;
if ($payment['installment_id']) {
    $totalPaid = get_installment_total_paid($conn, (int)$payment['installment_id']);
    $outstandingBalance = max(0, (float)$payment['installment_amount'] - $totalPaid);
}

$allocations = [];
$appliedCredit = 0.0;
$advanceToCredit = 0.0;
if (payment_allocation_tables_exist($conn)) {
    $stmt = $conn->prepare("
        SELECT pa.installment_id, pa.amount_allocated,
               li.installment_date, li.amount as installment_amount, li.status as inst_status, li.lease_id
        FROM re_payment_allocations pa
        JOIN re_lease_installments li ON li.id = pa.installment_id
        WHERE pa.payment_id = ? ORDER BY li.installment_date
    ");
    $stmt->execute([$paymentId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $instId = (int)$row['installment_id'];
        $leaseId = (int)$row['lease_id'];
        $totalPaidInst = get_installment_total_paid($conn, $instId);
        $outstanding = max(0, (float)$row['installment_amount'] - $totalPaidInst);
        $chequeNumber = '';
        $chq = $conn->prepare("
            SELECT COALESCE(c.cheque_number, lc.cheque_number, '') as chq
            FROM re_post_dated_cheques c
            LEFT JOIN re_lease_cheques lc ON lc.installment_id = c.installment_id AND lc.lease_id = c.lease_id
            WHERE c.installment_id = ? AND c.lease_id = ?
            LIMIT 1
        ");
        $chq->execute([$instId, $leaseId]);
        if ($r = $chq->fetch(PDO::FETCH_ASSOC)) {
            $chequeNumber = trim($r['chq'] ?? '');
        }
        if ($chequeNumber === '' && $leaseId) {
            $chq2 = $conn->prepare("SELECT cheque_number FROM re_lease_cheques WHERE installment_id = ? AND lease_id = ? LIMIT 1");
            $chq2->execute([$instId, $leaseId]);
            if ($r2 = $chq2->fetch(PDO::FETCH_ASSOC)) {
                $chequeNumber = trim($r2['cheque_number'] ?? '');
            }
        }
        $allocations[] = [
            'installment_date' => $row['installment_date'],
            'cheque_number' => $chequeNumber,
            'amount_allocated' => (float)$row['amount_allocated'],
            'installment_amount' => (float)$row['installment_amount'],
            'status' => $row['inst_status'],
            'outstanding' => $outstanding,
        ];
    }
    $stmt = $conn->prepare("SELECT type, COALESCE(SUM(amount_aed), 0) as total FROM re_tenant_credit_transactions WHERE payment_id = ? GROUP BY type");
    $stmt->execute([$paymentId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['type'] === 'debit') {
            $appliedCredit = (float)$row['total'];
        } else {
            $advanceToCredit = (float)$row['total'];
        }
    }
}

$company = re_pdf_company($conn, $currentCompanyId);
$docSettings = re_pdf_document_settings($conn, $currentCompanyId);
$logo = re_pdf_logo_data_uri((string)($company['logo_path'] ?? ''));
$currency = $company['currency'] ?: 'AED';
$receiptNo = $payment['receipt_number'] ?: ('#' . $payment['id']);
$amount = (float)$payment['amount'];
$filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$receiptNo) . '-receipt.pdf';
$receiptStatus = (string)($payment['receipt_status'] ?? $payment['status'] ?? 'recorded');
$statusRaw = strtolower($receiptStatus);
$statusBadgeClass = in_array($statusRaw, ['cleared', 'paid', 'posted'], true) ? 'badge-paid' : 'badge-sent';

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment Receipt <?= re_pdf_h((string)$receiptNo) ?></title>
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
            <div class="title">PAYMENT RECEIPT</div>
            <table class="doc-meta">
                <tr><td class="k">Receipt #</td><td class="v"><?= re_pdf_h((string)$receiptNo) ?></td></tr>
                <tr><td class="k">Payment date</td><td class="v"><?= re_pdf_h(re_pdf_fmt_date((string)$payment['payment_date'])) ?></td></tr>
                <?php if (!empty($payment['cleared_date'])): ?>
                <tr><td class="k">Cleared date</td><td class="v"><?= re_pdf_h(re_pdf_fmt_date((string)$payment['cleared_date'])) ?></td></tr>
                <?php endif; ?>
                <tr><td class="k">Method</td><td class="v"><?= re_pdf_h(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))) ?></td></tr>
                <tr><td class="k">Currency</td><td class="v"><?= re_pdf_h($currency) ?></td></tr>
            </table>
            <div style="margin-top:8px;"><span class="badge <?= re_pdf_h($statusBadgeClass) ?>"><?= re_pdf_h(ucfirst(str_replace('_', ' ', $receiptStatus))) ?></span></div>
        </div>
    </div>

    <div class="grid parties">
        <div class="col">
            <div class="party">
                <div class="label">Received From</div>
                <div class="value"><?= re_pdf_h(trim($payment['first_name'] . ' ' . $payment['last_name'])) ?></div>
                <?php if (!empty($payment['phone'])): ?><div class="muted">Phone: <?= re_pdf_h((string)$payment['phone']) ?></div><?php endif; ?>
                <?php if (!empty($payment['email'])): ?><div class="muted">Email: <?= re_pdf_h((string)$payment['email']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="col">
            <div class="party">
                <div class="label">Lease / Unit</div>
                <div class="value"><?= re_pdf_h((string)($payment['lease_number'] ?: ('L-' . $payment['lease_id']))) ?></div>
                <div><?= re_pdf_h($payment['building_name'] . ' — Unit ' . $payment['unit_number']) ?></div>
                <?php if (!empty($payment['building_address'])): ?>
                <div class="muted"><?= re_pdf_h((string)$payment['building_address']) ?></div>
                <?php endif; ?>
                <?php if (!empty($payment['start_date'])): ?>
                <div class="muted">Lease period: <?= re_pdf_h(re_pdf_fmt_date((string)$payment['start_date'])) ?> – <?= re_pdf_h(re_pdf_fmt_date((string)$payment['end_date'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="amount-box">
        <div class="label">Amount received</div>
        <div class="amount"><?= re_pdf_h($currency) ?> <?= number_format($amount, 2) ?></div>
        <div class="muted"><?= re_pdf_h(re_pdf_amount_words($amount, $currency)) ?></div>
        <div style="margin-top:8px;">
            Reference: <strong><?= re_pdf_h($payment['reference_number'] ?: '—') ?></strong>
            <?php if (!empty($payment['created_at'])): ?>
                <span class="muted"> · Recorded <?= re_pdf_h(date('d M Y H:i', strtotime($payment['created_at']))) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($allocations) || $appliedCredit > 0 || $advanceToCredit > 0): ?>
    <div class="section-title">Applied to</div>
    <?php if (!empty($allocations)): ?>
    <table class="txn">
        <thead>
            <tr>
                <th>Date</th>
                <th>Cheque #</th>
                <th class="right">Allocated</th>
                <th class="right">Installment</th>
                <th>Status</th>
                <th class="right">Outstanding</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($allocations as $i => $a): ?>
            <tr class="<?= ($i % 2) === 1 ? 'alt' : '' ?>">
                <td><?= re_pdf_h(re_pdf_fmt_date((string)$a['installment_date'])) ?></td>
                <td><?= $a['cheque_number'] !== '' ? re_pdf_h($a['cheque_number']) : '—' ?></td>
                <td class="right"><?= number_format($a['amount_allocated'], 2) ?></td>
                <td class="right"><?= number_format($a['installment_amount'], 2) ?></td>
                <td><?= re_pdf_h(ucfirst((string)$a['status'])) ?></td>
                <td class="right"><?= number_format($a['outstanding'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php if ($appliedCredit > 0): ?>
        <div class="note">Tenant credit applied: <?= re_pdf_h($currency) ?> <?= number_format($appliedCredit, 2) ?></div>
    <?php endif; ?>
    <?php if ($advanceToCredit > 0): ?>
        <div class="note">Amount held as tenant credit: <?= re_pdf_h($currency) ?> <?= number_format($advanceToCredit, 2) ?></div>
    <?php endif; ?>
    <?php elseif ($payment['installment_id'] && $outstandingBalance > 0): ?>
    <div class="party">
        <div class="label">Related installment outstanding</div>
        <div class="text-danger value"><?= re_pdf_h($currency) ?> <?= number_format($outstandingBalance, 2) ?></div>
    </div>
    <?php endif; ?>

    <div class="footer">
        This is a computer-generated payment receipt from <?= re_pdf_h($company['name']) ?>. Thank you for your payment.
    </div>
</div>
</body>
</html>
<?php
$html = ob_get_clean();
re_pdf_output($html, $filename);
