<?php
/**
 * Construction Module — Reverse / Delete Supplier Payment
 * Uses guarded reverse: reverse GL, remove allocations, refresh invoice statuses.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_accounting_integration.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();
$id = (int)($_REQUEST['id'] ?? 0);
if (!$id) { header('Location: supplier_payments.php'); exit; }

$stmt = $conn->prepare("SELECT sp.*, s.supplier_name FROM co_supplier_payments sp JOIN co_suppliers s ON s.id = sp.supplier_id WHERE sp.id = ? AND sp.company_id = ?");
$stmt->execute([$id, $cid]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$payment) { header('Location: supplier_payments.php'); exit; }

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    csrf_verify();
    $reason = trim((string)($_POST['reason'] ?? ''));
    $result = co_supplier_reverse_payment(
        $conn,
        $cid,
        $id,
        $reason !== '' ? $reason : 'Supplier payment reversed',
        $userId ? (int)$userId : null
    );
    if (!empty($result['success'])) {
        header('Location: supplier_view.php?id=' . (int)$payment['supplier_id']);
        exit;
    }
    $err = $result['error'] ?? 'Could not reverse payment.';
}

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$pageTitle = 'Reverse Supplier Payment';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="supplier_payment_view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Reverse Supplier Payment</h1>
</div>
<?php if ($err): ?>
<div class="alert alert-danger"><?= h($err) ?></div>
<p><a href="supplier_payment_view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Return to Payment</a></p>
<?php else: ?>
<div class="card card-round">
    <div class="card-body">
        <p class="mb-1">Reverse payment of <strong><?= co_format_money($payment['amount']) ?></strong> to <strong><?= h($payment['supplier_name']) ?></strong> on <strong><?= h($payment['payment_date']) ?></strong>?</p>
        <p class="text-muted">Invoice allocations will be removed, affected invoice statuses refreshed, and the GL journal reversed. This cannot be undone.</p>
        <form method="post" class="d-flex flex-column gap-3">
            <?php csrf_field(); ?>
            <div>
                <label class="form-label">Reason (optional)</label>
                <input type="text" name="reason" class="form-control" maxlength="255" placeholder="e.g. Incorrect allocation">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">Reverse Payment</button>
                <a href="supplier_payment_view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
