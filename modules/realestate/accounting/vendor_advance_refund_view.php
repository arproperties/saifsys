<?php
/**
 * Vendor Advance Refund — view / reverse / audit
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
$refundId = (int)($_GET['id'] ?? 0);
$error = '';

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2);
}

if (!re_ap_advance_refund_table_ready($conn) || $refundId <= 0) {
    header('Location: vendor_advance_refunds.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reverse') {
    try {
        csrf_verify();
        $reason = trim((string)($_POST['reason'] ?? 'Advance refund reversed'));
        $res = re_ap_reverse_vendor_advance_refund($conn, $companyId, $refundId, $reason, $userId);
        if (empty($res['success'])) {
            throw new RuntimeException($res['error'] ?? 'Reverse failed');
        }
        header('Location: vendor_advance_refund_view.php?id=' . $refundId . '&reversed=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$st = $conn->prepare("
    SELECT r.*, v.vendor_name, vp.reference_number AS payment_ref, vp.payment_date,
           COALESCE(vp.advance_amount, 0) AS original_advance,
           ba.account_name AS bank_name
    FROM re_vendor_advance_refunds r
    JOIN re_vendors v ON v.id = r.vendor_id AND v.company_id = r.company_id
    JOIN re_vendor_payments vp ON vp.id = r.vendor_payment_id AND vp.company_id = r.company_id
    LEFT JOIN re_bank_accounts ba ON ba.id = r.bank_account_id AND ba.company_id = r.company_id
    WHERE r.id = ? AND r.company_id = ?
");
$st->execute([$refundId, $companyId]);
$refund = $st->fetch(PDO::FETCH_ASSOC);
if (!$refund) {
    header('Location: vendor_advance_refunds.php');
    exit;
}

$paymentId = (int)$refund['vendor_payment_id'];
$orig = re_ap_money($refund['original_advance']);
$applied = re_ap_payment_advance_applied($conn, $companyId, $paymentId);
$vatPosted = re_ap_payment_advance_vat_posted($conn, $companyId, $paymentId);
$refunded = re_ap_payment_advance_refunded($conn, $companyId, $paymentId);
$remaining = re_ap_payment_advance_remaining($conn, $companyId, $paymentId);

$history = [];
$hst = $conn->prepare("
    SELECT * FROM re_vendor_advance_refunds
    WHERE company_id = ? AND vendor_payment_id = ?
    ORDER BY id ASC
");
$hst->execute([$companyId, $paymentId]);
$history = $hst->fetchAll(PDO::FETCH_ASSOC) ?: [];

$audit = [];
try {
    $ast = $conn->prepare("
        SELECT * FROM re_vendor_ap_audit
        WHERE company_id = ? AND vendor_payment_id = ?
          AND action_type IN ('advance_refund_posted','advance_refund_reversed')
        ORDER BY id DESC LIMIT 50
    ");
    $ast->execute([$companyId, $paymentId]);
    $audit = $ast->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $audit = [];
}

$pageTitle = 'Advance Refund REF-' . $refundId;
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i data-lucide="undo-2" class="me-1"></i> Advance Refund REF-<?= (int)$refundId ?></div>
    <div class="d-flex gap-2">
        <a href="vendor_advance_refunds.php" class="btn btn-outline-secondary">All Refunds</a>
        <a href="vendor_payments.php?q=PAY-<?= $paymentId ?>" class="btn btn-outline-primary">Payments Made</a>
    </div>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Refund posted successfully.</div><?php endif; ?>
<?php if (!empty($_GET['reversed'])): ?><div class="alert alert-success">Refund reversed successfully.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-8">
        <div class="card card-round h-100">
            <div class="card-header">Refund Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Vendor</dt><dd class="col-sm-8"><?= h($refund['vendor_name']) ?></dd>
                    <dt class="col-sm-4">Source Payment</dt>
                    <dd class="col-sm-8"><?= h($refund['payment_ref'] ?: ('PAY-' . $paymentId)) ?> · <?= h($refund['payment_date']) ?></dd>
                    <dt class="col-sm-4">Refund Date</dt><dd class="col-sm-8"><?= h($refund['refund_date']) ?></dd>
                    <dt class="col-sm-4">Amount</dt><dd class="col-sm-8"><strong><?= m($refund['amount']) ?></strong> AED</dd>
                    <dt class="col-sm-4">Bank</dt><dd class="col-sm-8"><?= h($refund['bank_name'] ?: 'Default / payment bank') ?></dd>
                    <dt class="col-sm-4">Reference</dt><dd class="col-sm-8"><?= h($refund['reference_number'] ?: '—') ?></dd>
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-<?= ($refund['status'] ?? '') === 'posted' ? 'success' : 'secondary' ?>"><?= h($refund['status']) ?></span>
                    </dd>
                    <dt class="col-sm-4">Journal</dt>
                    <dd class="col-sm-8">
                        <?php if (!empty($refund['journal_id'])): ?>
                            <a href="journal_entry_view.php?id=<?= (int)$refund['journal_id'] ?>">Journal #<?= (int)$refund['journal_id'] ?></a>
                        <?php else: ?>—<?php endif; ?>
                        <?php if (!empty($refund['reversal_journal_id'])): ?>
                            · Reversal <a href="journal_entry_view.php?id=<?= (int)$refund['reversal_journal_id'] ?>">#<?= (int)$refund['reversal_journal_id'] ?></a>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-sm-4">Notes</dt><dd class="col-sm-8"><?= nl2br(h($refund['notes'] ?: '—')) ?></dd>
                </dl>
            </div>
            <?php if (($refund['status'] ?? '') === 'posted'): ?>
                <div class="card-footer">
                    <form method="post" onsubmit="return confirm('Reverse this advance refund? Journal will be reversed and advance balance restored.');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="reverse">
                        <div class="input-group">
                            <input type="text" name="reason" class="form-control" placeholder="Reversal reason" value="Advance refund reversed">
                            <button class="btn btn-outline-danger">Reverse Refund</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round h-100">
            <div class="card-header">Source Payment Balance</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2"><span>Original Advance</span><strong><?= m($orig) ?></strong></div>
                <div class="d-flex justify-content-between mb-2"><span>Applied</span><strong><?= m($applied) ?></strong></div>
                <div class="d-flex justify-content-between mb-2"><span>Advance VAT</span><strong><?= m($vatPosted) ?></strong></div>
                <div class="d-flex justify-content-between mb-2"><span>Refunded</span><strong><?= m($refunded) ?></strong></div>
                <hr>
                <div class="d-flex justify-content-between"><span>Remaining</span><strong class="text-success"><?= m($remaining) ?></strong></div>
            </div>
        </div>
    </div>
</div>

<div class="card card-round mb-3">
    <div class="card-header">Refund History on PAY-<?= $paymentId ?></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>REF</th>
                    <th>Date</th>
                    <th class="text-end">Amount</th>
                    <th>Status</th>
                    <th>Journal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $hrow): ?>
                    <tr class="<?= (int)$hrow['id'] === $refundId ? 'table-primary' : '' ?>">
                        <td><a href="vendor_advance_refund_view.php?id=<?= (int)$hrow['id'] ?>">REF-<?= (int)$hrow['id'] ?></a></td>
                        <td><?= h($hrow['refund_date']) ?></td>
                        <td class="text-end"><?= m($hrow['amount']) ?></td>
                        <td><span class="badge bg-<?= ($hrow['status'] ?? '') === 'posted' ? 'success' : 'secondary' ?>"><?= h($hrow['status']) ?></span></td>
                        <td>
                            <?php if (!empty($hrow['journal_id'])): ?>
                                <a href="journal_entry_view.php?id=<?= (int)$hrow['journal_id'] ?>">#<?= (int)$hrow['journal_id'] ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($audit): ?>
<div class="card card-round mb-3">
    <div class="card-header">Audit Trail</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>When</th>
                    <th>Event</th>
                    <th class="text-end">Amount</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($audit as $a): ?>
                    <tr>
                        <td><?= h($a['created_at'] ?? '') ?></td>
                        <td><?= h($a['action_type'] ?? '') ?></td>
                        <td class="text-end"><?= m($a['amount'] ?? 0) ?></td>
                        <td><?= h($a['reason'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
