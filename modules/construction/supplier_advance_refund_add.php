<?php
/**
 * Construction — Refund unused supplier advance to bank
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_advance_helpers.php';
require_once __DIR__ . '/includes/construction_accounting_integration.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();
$paymentId = (int)($_GET['payment_id'] ?? $_POST['supplier_payment_id'] ?? 0);
$err = '';

if ($paymentId <= 0) {
    header('Location: supplier_payments.php');
    exit;
}
$st = $conn->prepare("
    SELECT sp.*, s.supplier_name
    FROM co_supplier_payments sp
    JOIN co_suppliers s ON s.id = sp.supplier_id
    WHERE sp.id = ? AND sp.company_id = ?
");
$st->execute([$paymentId, $cid]);
$pay = $st->fetch(PDO::FETCH_ASSOC);
if (!$pay || co_supplier_money($pay['advance_amount'] ?? 0) <= 0.005) {
    header('Location: supplier_payment_view.php?id=' . $paymentId);
    exit;
}
$remaining = co_supplier_payment_advance_remaining($conn, $cid, $paymentId);
$vatPosted = function_exists('co_supplier_payment_advance_vat_posted')
    ? co_supplier_payment_advance_vat_posted($conn, $cid, $paymentId) : 0.0;

$bankAccounts = [];
$ba = $conn->prepare("
    SELECT id, account_code, account_name FROM re_chart_of_accounts
    WHERE company_id = ? AND is_active = 1 AND is_header = 0
      AND (account_type IN ('Asset','asset') OR account_code IN ('1110','1120','1010','1020'))
    ORDER BY account_code
    LIMIT 100
");
$ba->execute([$cid]);
$bankAccounts = $ba->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $res = co_supplier_post_advance_refund(
        $conn,
        $cid,
        $paymentId,
        (float)($_POST['amount'] ?? 0),
        (string)($_POST['refund_date'] ?? date('Y-m-d')),
        (int)($_POST['pay_account_id'] ?? 0) ?: null,
        trim((string)($_POST['reference'] ?? '')),
        trim((string)($_POST['notes'] ?? '')),
        $userId ? (int)$userId : null
    );
    if (!empty($res['success'])) {
        header('Location: supplier_advance_refund_view.php?id=' . (int)$res['refund_id'] . '&posted=1');
        exit;
    }
    $err = $res['error'] ?? 'Refund failed';
}

$pageTitle = 'Refund Supplier Advance';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4">
    <a href="supplier_payment_view.php?id=<?= $paymentId ?>" class="btn btn-outline-secondary btn-sm mb-2">Back to Payment</a>
    <h1 class="h4 mb-0">Refund Supplier Advance</h1>
    <p class="text-muted mb-0"><?= h($pay['supplier_name']) ?> · Payment #<?= $paymentId ?></p>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if ($vatPosted > 0.005): ?>
<div class="alert alert-warning">Posted Advance VAT remains on this payment (<?= co_format_money($vatPosted) ?>). Reverse VAT documents before refunding.</div>
<?php endif; ?>
<form method="post" class="card card-round"><div class="card-body row g-3">
<?php csrf_field(); ?>
<input type="hidden" name="supplier_payment_id" value="<?= $paymentId ?>">
<div class="col-md-4"><label class="form-label">Remaining Advance</label><input type="text" class="form-control" readonly value="<?= h(number_format($remaining, 2, '.', '')) ?>"></div>
<div class="col-md-4"><label class="form-label">Refund Date *</label><input type="date" name="refund_date" class="form-control" required value="<?= h(date('Y-m-d')) ?>"></div>
<div class="col-md-4"><label class="form-label">Amount *</label><input type="number" step="0.01" min="0.01" max="<?= h(number_format($remaining, 2, '.', '')) ?>" name="amount" class="form-control" required value="<?= h(number_format($remaining, 2, '.', '')) ?>"></div>
<div class="col-md-6">
    <label class="form-label">Bank / Cash Account</label>
    <select name="pay_account_id" class="form-select">
        <option value="0">— Default from payment / COA —</option>
        <?php foreach ($bankAccounts as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= (int)($pay['pay_account_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['account_code'] . ' — ' . $a['account_name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="col-md-6"><label class="form-label">Reference</label><input type="text" name="reference" class="form-control"></div>
<div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
<div class="col-12">
    <button class="btn btn-warning" <?= ($remaining <= 0.005 || $vatPosted > 0.005) ? 'disabled' : '' ?>>Post Refund to GL</button>
    <p class="small text-muted mt-2 mb-0">Posts Dr Bank / Cr Supplier Advances. Blocked while Advance VAT is still posted on this payment.</p>
</div>
</div></form>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
