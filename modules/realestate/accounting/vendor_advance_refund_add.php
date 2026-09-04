<?php
/**
 * Create / post Vendor Advance Refund — Dr Bank / Cr 1410
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../includes/vendor_ap_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);
if ($companyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}
$userId = current_user_id();

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2);
}

if (!re_ap_advance_refund_table_ready($conn)) {
    http_response_code(503);
    die('Advance refund schema is not installed. Run migrations/re_vendor_advance_refunds.sql');
}

$vendorId = (int)($_GET['vendor_id'] ?? $_POST['vendor_id'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? $_POST['vendor_payment_id'] ?? 0);
$error = '';

$vendors = [];
$stV = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? ORDER BY vendor_name");
$stV->execute([$companyId]);
$vendors = $stV->fetchAll(PDO::FETCH_ASSOC) ?: [];

$banks = [];
$stB = $conn->prepare("SELECT id, account_name FROM re_bank_accounts WHERE company_id = ? AND is_active = 1 ORDER BY account_name");
$stB->execute([$companyId]);
$banks = $stB->fetchAll(PDO::FETCH_ASSOC) ?: [];

$eligiblePayments = [];
if ($vendorId > 0) {
    $eligiblePayments = re_ap_advance_source_payments($conn, $companyId, $vendorId);
    // Exclude payments blocked by posted Advance VAT
    $filtered = [];
    foreach ($eligiblePayments as $ep) {
        $vat = re_ap_payment_advance_vat_posted($conn, $companyId, (int)$ep['id']);
        $ep['vat_posted'] = $vat;
        $ep['blocked_by_vat'] = $vat > 0.005;
        $filtered[] = $ep;
    }
    $eligiblePayments = $filtered;
}

$selectedPayment = null;
$remaining = 0.0;
$orig = 0.0;
$applied = 0.0;
$vatPosted = 0.0;
$refunded = 0.0;
if ($paymentId > 0) {
    $st = $conn->prepare("
        SELECT vp.*, v.vendor_name
        FROM re_vendor_payments vp
        JOIN re_vendors v ON v.id = vp.vendor_id AND v.company_id = vp.company_id
        WHERE vp.id = ? AND vp.company_id = ?
    ");
    $st->execute([$paymentId, $companyId]);
    $selectedPayment = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selectedPayment) {
        $vendorId = (int)$selectedPayment['vendor_id'];
        $orig = re_ap_money($selectedPayment['advance_amount'] ?? 0);
        $applied = re_ap_payment_advance_applied($conn, $companyId, $paymentId);
        $vatPosted = re_ap_payment_advance_vat_posted($conn, $companyId, $paymentId);
        $refunded = re_ap_payment_advance_refunded($conn, $companyId, $paymentId);
        $remaining = re_ap_payment_advance_remaining($conn, $companyId, $paymentId);
        if ($eligiblePayments === [] && $vendorId > 0) {
            $eligiblePayments = re_ap_advance_source_payments($conn, $companyId, $vendorId);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $paymentId = (int)($_POST['vendor_payment_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $refundDate = (string)($_POST['refund_date'] ?? date('Y-m-d'));
        $bankAccountId = (int)($_POST['bank_account_id'] ?? 0) ?: null;
        $reference = trim((string)($_POST['reference_number'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $res = re_ap_post_vendor_advance_refund(
            $conn,
            $companyId,
            $paymentId,
            $amount,
            $refundDate,
            $bankAccountId,
            $reference,
            $notes,
            $userId
        );
        if (empty($res['success'])) {
            throw new RuntimeException($res['error'] ?? 'Refund failed');
        }
        header('Location: vendor_advance_refund_view.php?id=' . (int)$res['refund_id'] . '&ok=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        // refresh selected payment after failed post
        if ($paymentId > 0) {
            $st = $conn->prepare("SELECT vp.*, v.vendor_name FROM re_vendor_payments vp JOIN re_vendors v ON v.id = vp.vendor_id AND v.company_id = vp.company_id WHERE vp.id = ? AND vp.company_id = ?");
            $st->execute([$paymentId, $companyId]);
            $selectedPayment = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($selectedPayment) {
                $orig = re_ap_money($selectedPayment['advance_amount'] ?? 0);
                $applied = re_ap_payment_advance_applied($conn, $companyId, $paymentId);
                $vatPosted = re_ap_payment_advance_vat_posted($conn, $companyId, $paymentId);
                $refunded = re_ap_payment_advance_refunded($conn, $companyId, $paymentId);
                $remaining = re_ap_payment_advance_remaining($conn, $companyId, $paymentId);
            }
        }
    }
}

$pageTitle = 'Refund Vendor Advance';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i data-lucide="undo-2" class="me-1"></i> Refund Vendor Advance</div>
    <a href="vendor_advance_refunds.php" class="btn btn-outline-secondary">All Refunds</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="alert alert-light border small">
    Posts <strong>Dr Bank/Cash / Cr Vendor Advances (1410)</strong>. Does not affect AP, expenses, or VAT.
    Refunds are blocked while posted Advance VAT documents remain on the source payment.
</div>

<form method="post" class="card card-round">
    <div class="card-body">
        <?php csrf_field(); ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select" required onchange="location.href='vendor_advance_refund_add.php?vendor_id='+this.value">
                    <option value="">Select vendor</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Source Advance Payment</label>
                <select name="vendor_payment_id" class="form-select" required onchange="if(this.value) location.href='vendor_advance_refund_add.php?vendor_id=<?= (int)$vendorId ?>&payment_id='+this.value">
                    <option value="">Select payment with remaining advance</option>
                    <?php foreach ($eligiblePayments as $ep): ?>
                        <?php
                        $label = 'PAY-' . $ep['id'] . ' · ' . $ep['payment_date'] . ' · rem ' . m($ep['remaining']);
                        if (!empty($ep['blocked_by_vat'])) {
                            $label .= ' (VAT posted — reverse VAT first)';
                        }
                        ?>
                        <option value="<?= (int)$ep['id'] ?>"
                            <?= $paymentId === (int)$ep['id'] ? 'selected' : '' ?>
                            <?= !empty($ep['blocked_by_vat']) ? 'disabled' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($vendorId > 0 && !$eligiblePayments): ?>
                    <div class="form-text text-muted">No refundable advance remaining for this vendor.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label">Refund Date</label>
                <input type="date" name="refund_date" class="form-control" value="<?= h($_POST['refund_date'] ?? date('Y-m-d')) ?>" required>
            </div>

            <?php if ($selectedPayment): ?>
                <div class="col-12">
                    <div class="row g-2">
                        <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Original Advance</div><strong><?= m($orig) ?></strong></div></div>
                        <div class="col-md-2"><div class="border rounded p-2"><div class="small text-muted">Applied</div><strong><?= m($applied) ?></strong></div></div>
                        <div class="col-md-2"><div class="border rounded p-2"><div class="small text-muted">Advance VAT</div><strong><?= m($vatPosted) ?></strong></div></div>
                        <div class="col-md-2"><div class="border rounded p-2"><div class="small text-muted">Previously Refunded</div><strong><?= m($refunded) ?></strong></div></div>
                        <div class="col-md-3"><div class="border rounded p-2 bg-light"><div class="small text-muted">Refundable Now</div><strong class="text-success"><?= m($remaining) ?></strong></div></div>
                    </div>
                    <?php if ($vatPosted > 0.005): ?>
                        <div class="alert alert-warning mt-2 mb-0">Posted Advance VAT must be reversed before refunding this payment.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="col-md-3">
                <label class="form-label">Refund Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required
                       value="<?= h($_POST['amount'] ?? ($remaining > 0 ? number_format($remaining, 2, '.', '') : '')) ?>"
                       <?= ($vatPosted > 0.005 || $remaining <= 0.005) ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-4">
                <label class="form-label">Bank / Cash Account</label>
                <select name="bank_account_id" class="form-select">
                    <option value="">Same as payment / default bank</option>
                    <?php foreach ($banks as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (int)($_POST['bank_account_id'] ?? ($selectedPayment['bank_account_id'] ?? 0)) === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= h($b['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Reference</label>
                <input type="text" name="reference_number" class="form-control" value="<?= h($_POST['reference_number'] ?? '') ?>" maxlength="100">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="vendor_advance_refunds.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary" <?= ($vatPosted > 0.005 || $remaining <= 0.005 || $paymentId <= 0) ? 'disabled' : '' ?>>
            Post Refund
        </button>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
