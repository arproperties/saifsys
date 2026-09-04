<?php
/**
 * Tenant Portal — Pay for extra service (online or Cash at Office)
 * Cash at Office: creates Cash Payment Request, shows Request ID and expiry; no service activation until PAID_VERIFIED.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: extra_services.php');
    exit;
}

$lease_id = (int)$lease['lease_id'];
$company_id = (int)$lease['company_id'];

$selectCols = "id, service_type, total_amount_aed, payment_status, status";
$hasCashColumn = false;
try {
    $conn->query("SELECT cash_payment_request_id FROM tenant_extra_service_requests LIMIT 1");
    $hasCashColumn = true;
    $selectCols .= ", cash_payment_request_id";
} catch (Throwable $e) {}

$row = $conn->prepare("
    SELECT {$selectCols}
    FROM tenant_extra_service_requests
    WHERE id = ? AND lease_id = ? AND company_id = ?
");
$row->execute([$id, $lease_id, $company_id]);
$row = $row->fetch(PDO::FETCH_ASSOC);

if (!$row || ($row['payment_status'] ?? 'n_a') !== 'pending_payment' || (float)($row['total_amount_aed'] ?? 0) <= 0) {
    header('Location: extra_services.php');
    exit;
}

$amount = (float)$row['total_amount_aed'];
$requestId = (int)$row['id'];
$cashRequestId = $hasCashColumn ? (int)($row['cash_payment_request_id'] ?? 0) : 0;

$cashPaymentTablesExist = false;
$cashRequest = null;
if (file_exists(__DIR__ . '/../modules/realestate/includes/cash_payment_helper.php')) {
    require_once __DIR__ . '/../modules/realestate/includes/cash_payment_helper.php';
    $cashPaymentTablesExist = cash_payment_tables_exist($conn);
    if ($cashPaymentTablesExist && $cashRequestId) {
        $q = $conn->prepare("SELECT id, request_number, status, expires_at FROM re_cash_payment_requests WHERE id = ? AND company_id = ?");
        $q->execute([$cashRequestId, $company_id]);
        $cashRequest = $q->fetch(PDO::FETCH_ASSOC);
    }
}

// POST: choose Cash at Office -> create cash payment request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cashPaymentTablesExist && !$cashRequestId) {
    csrf_verify();
    $create = cash_payment_create_request($conn, $company_id, 'extra_service', $requestId, $amount);
    if ($create) {
        cash_payment_link_service_request($conn, 'extra_service', $requestId, $create['id']);
        header('Location: pay_extra_service.php?id=' . $requestId . '&cash=1');
        exit;
    }
}

// Reload cash request if we just created it via redirect
if ($hasCashColumn && ($_GET['cash'] ?? '') === '1' && file_exists(__DIR__ . '/../modules/realestate/includes/cash_payment_helper.php')) {
    require_once __DIR__ . '/../modules/realestate/includes/cash_payment_helper.php';
    if (cash_payment_tables_exist($conn)) {
        $q = $conn->prepare("SELECT r.id, r.request_number, r.status, r.expires_at FROM re_cash_payment_requests r INNER JOIN tenant_extra_service_requests e ON e.cash_payment_request_id = r.id WHERE e.id = ? AND r.company_id = ?");
        $q->execute([$requestId, $company_id]);
        $cashRequest = $q->fetch(PDO::FETCH_ASSOC);
    }
}

$paymentGatewayUrl = null;
try {
    $s = $conn->prepare("SELECT value FROM settings WHERE `key` = 'extra_service_payment_url' LIMIT 1");
    $s->execute();
    $v = $s->fetchColumn();
    if ($v && filter_var($v, FILTER_VALIDATE_URL)) {
        $paymentGatewayUrl = $v;
    }
} catch (Throwable $e) {}

$pageTitle = 'Pay for service';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Pay for extra service</h4>
<div class="portal-card card">
    <div class="card-body">
        <p class="mb-2">Service request <strong>#<?= $requestId ?></strong> — <?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['service_type']))) ?></p>
        <p class="mb-4"><strong>Amount: <?= number_format($amount, 2) ?> AED</strong></p>

        <?php if ($cashRequest): ?>
            <div class="alert alert-info mb-3">
                <strong>Cash Payment Request: <?= htmlspecialchars($cashRequest['request_number']) ?></strong>
                <p class="mb-1 mt-2">Status: <span class="badge bg-<?= $cashRequest['status'] === 'paid_verified' ? 'success' : ($cashRequest['status'] === 'expired' ? 'secondary' : 'warning') ?>"><?= htmlspecialchars(str_replace('_', ' ', $cashRequest['status'])) ?></span></p>
                <?php if ($cashRequest['status'] === 'pending_cash_payment' && $cashRequest['expires_at']): ?>
                    <p class="mb-0 small">Pay at the reception within <strong><?= date('M j, Y g:i A', strtotime($cashRequest['expires_at'])) ?></strong>. Bring this Request ID to the reception.</p>
                <?php elseif ($cashRequest['status'] === 'paid_verified'): ?>
                    <p class="mb-0 small">Payment verified. Your service will be activated.</p>
                <?php endif; ?>
            </div>
            <a href="extra_services.php" class="btn btn-outline-primary">Back to Extra services</a>
        <?php else: ?>
            <p class="mb-3">Choose how you want to pay:</p>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($paymentGatewayUrl): ?>
                    <a href="<?= htmlspecialchars($paymentGatewayUrl) ?>?amount=<?= urlencode($amount) ?>&ref=extra-<?= $requestId ?>" class="btn btn-primary">Pay online</a>
                <?php endif; ?>
                <?php if ($cashPaymentTablesExist): ?>
                    <form method="POST" class="d-inline">
                        <?php csrf_field(); ?>
                        <button type="submit" class="btn btn-outline-primary">Pay cash at Reception</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (!$paymentGatewayUrl && !$cashPaymentTablesExist): ?>
                <p class="text-muted mt-3 mb-0">Online payment is not configured. Please pay at the management office or contact management.</p>
            <?php endif; ?>
            <p class="mt-3 mb-0 small text-muted">If you choose <strong>Pay cash at Reception</strong>, you will receive a Request ID. Bring it to the reception and pay within the stated time. Your service is activated only after payment is verified.</p>
            <a href="extra_services.php" class="btn btn-link mt-2">Back to Extra services</a>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
