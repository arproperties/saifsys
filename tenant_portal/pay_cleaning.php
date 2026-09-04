<?php
/**
 * Tenant Portal — Pay for cleaning (Cash at Office)
 * Creates Cash Payment Request; shows Request ID and expiry. Service activation when PAID_VERIFIED.
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
    header('Location: cleaning.php');
    exit;
}

$lease_id = (int)$lease['lease_id'];
$company_id = (int)$lease['company_id'];

$selectCols = "id, num_cleaners, num_hours, total_amount_aed, status";
$hasCashColumn = false;
try {
    $conn->query("SELECT cash_payment_request_id FROM tenant_cleaning_requests LIMIT 1");
    $hasCashColumn = true;
    $selectCols .= ", cash_payment_request_id";
} catch (Throwable $e) {}

$row = $conn->prepare("
    SELECT {$selectCols}
    FROM tenant_cleaning_requests
    WHERE id = ? AND lease_id = ? AND company_id = ?
");
$row->execute([$id, $lease_id, $company_id]);
$row = $row->fetch(PDO::FETCH_ASSOC);

if (!$row || $row['status'] !== 'approved' || (float)($row['total_amount_aed'] ?? 0) <= 0) {
    header('Location: cleaning.php');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cashPaymentTablesExist && !$cashRequestId) {
    csrf_verify();
    $create = cash_payment_create_request($conn, $company_id, 'cleaning', $requestId, $amount);
    if ($create) {
        cash_payment_link_service_request($conn, 'cleaning', $requestId, $create['id']);
        header('Location: pay_cleaning.php?id=' . $requestId . '&cash=1');
        exit;
    }
}

if ($hasCashColumn && ($_GET['cash'] ?? '') === '1' && file_exists(__DIR__ . '/../modules/realestate/includes/cash_payment_helper.php')) {
    require_once __DIR__ . '/../modules/realestate/includes/cash_payment_helper.php';
    if (cash_payment_tables_exist($conn)) {
        $q = $conn->prepare("SELECT r.id, r.request_number, r.status, r.expires_at FROM re_cash_payment_requests r INNER JOIN tenant_cleaning_requests c ON c.cash_payment_request_id = r.id WHERE c.id = ? AND r.company_id = ?");
        $q->execute([$requestId, $company_id]);
        $cashRequest = $q->fetch(PDO::FETCH_ASSOC);
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Pay for cleaning';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Pay for cleaning</h4>
<div class="portal-card card">
    <div class="card-body">
        <p class="mb-2">Cleaning request <strong>#<?= $requestId ?></strong> — <?= (int)$row['num_cleaners'] ?> cleaner(s), <?= h($row['num_hours']) ?> hour(s)</p>
        <p class="mb-4"><strong>Amount: <?= number_format($amount, 2) ?> AED</strong></p>

        <?php if ($cashRequest): ?>
            <div class="alert alert-info mb-3">
                <strong>Cash Payment Request: <?= h($cashRequest['request_number']) ?></strong>
                <p class="mb-1 mt-2">Status: <span class="badge bg-<?= $cashRequest['status'] === 'paid_verified' ? 'success' : ($cashRequest['status'] === 'expired' ? 'secondary' : 'warning') ?>"><?= h(str_replace('_', ' ', $cashRequest['status'])) ?></span></p>
                <?php if ($cashRequest['status'] === 'pending_cash_payment' && $cashRequest['expires_at']): ?>
                    <p class="mb-0 small">Pay at the reception within <strong><?= date('M j, Y g:i A', strtotime($cashRequest['expires_at'])) ?></strong>. Bring this Request ID to the reception.</p>
                <?php elseif ($cashRequest['status'] === 'paid_verified'): ?>
                    <p class="mb-0 small">Payment verified. Your cleaning service can be scheduled.</p>
                <?php endif; ?>
            </div>
            <a href="cleaning.php" class="btn btn-outline-primary">Back to Cleaning</a>
        <?php else: ?>
            <p class="mb-3">Choose how you want to pay:</p>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($cashPaymentTablesExist): ?>
                    <form method="POST" class="d-inline">
                        <?php csrf_field(); ?>
                        <button type="submit" class="btn btn-primary">Pay cash at Reception</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (!$cashPaymentTablesExist): ?>
                <p class="text-muted mt-3 mb-0">Cash payment is not configured. Please pay at the management office or contact management.</p>
            <?php endif; ?>
            <p class="mt-3 mb-0 small text-muted">If you choose <strong>Pay cash at Reception</strong>, you will receive a Request ID. Bring it to the reception and pay within the stated time. Your service is confirmed only after payment is verified.</p>
            <a href="cleaning.php" class="btn btn-link mt-2">Back to Cleaning</a>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
