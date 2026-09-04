<?php
/**
 * Real Estate — Cash verification (Accounting).
 * List requests in CASH_RECEIVED_PENDING_VERIFICATION; approve -> PAID_VERIFIED.
 * Optionally post accounting entry when status becomes PAID_VERIFIED (hook for existing GL).
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

require_once __DIR__ . '/includes/cash_payment_helper.php';
if (!cash_payment_tables_exist($conn)) {
    $pageTitle = 'Cash verification';
    require_once __DIR__ . '/includes/re_layout_header.php';
    echo '<div class="alert alert-warning">Cash payment workflow is not installed. Run migration <code>re_cash_payment_workflow.sql</code>.</div>';
    require_once __DIR__ . '/includes/re_layout_footer.php';
    exit;
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    csrf_verify();
    $requestId = (int)($_POST['request_id'] ?? 0);
    $req = $conn->prepare("SELECT id, status FROM re_cash_payment_requests WHERE id = ? AND company_id = ?");
    $req->execute([$requestId, $currentCompanyId]);
    $req = $req->fetch(PDO::FETCH_ASSOC);
    if (!$req || $req['status'] !== 'cash_received_pending_verification') {
        $message = 'Request not found or not pending verification.';
        $messageType = 'danger';
    } else {
        $oldStatus = $req['status'];
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE re_cash_payment_requests SET status = 'paid_verified', verified_at = NOW(), verified_by = ? WHERE id = ? AND company_id = ?")
                ->execute([$userId, $requestId, $currentCompanyId]);
            cash_payment_audit($conn, $requestId, 'status_change', 'status', $oldStatus, 'paid_verified');
            $conn->commit();
            $accountingResult = cash_payment_post_accounting_entry($conn, $requestId, $currentCompanyId, $userId);
            if (!$accountingResult['success'] && $accountingResult['error']) {
                $message = 'Payment verified. Service can now be activated. Accounting entry could not be posted: ' . $accountingResult['error'];
                $messageType = 'warning';
            } else {
                $message = 'Payment verified. Service can now be activated.' . ($accountingResult['journal_id'] ? ' Journal entry posted.' : '');
                $messageType = 'success';
            }
            if (file_exists(__DIR__ . '/includes/re_email_helper.php')) {
                require_once __DIR__ . '/includes/re_email_helper.php';
                send_cash_payment_verified_to_tenant_notification($conn, $requestId, $currentCompanyId);
            }
        } catch (Throwable $e) {
            $conn->rollBack();
            $message = 'Failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

$statusFilter = $_GET['status'] ?? 'cash_received_pending_verification';
$where = "r.company_id = ?";
$params = [$currentCompanyId];
if (in_array($statusFilter, ['cash_received_pending_verification', 'paid_verified'], true)) {
    $where .= " AND r.status = ?";
    $params[] = $statusFilter;
}
$list = $conn->prepare("
    SELECT r.id, r.request_number, r.related_type, r.related_id, r.amount_aed, r.status, r.received_at, r.receipt_number, r.verified_at
    FROM re_cash_payment_requests r
    WHERE {$where}
    ORDER BY r.received_at DESC
    LIMIT 100
");
$list->execute($params);
$list = $list->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Cash verification';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Cash verification</div>
</div>

<p class="text-muted">Verify cash received at reception. Only requests in <strong>Cash received – Pending verification</strong> can be approved. After approval, status becomes <strong>Paid verified</strong> and the related service can be activated.</p>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= h($message) ?></div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="cash_received_pending_verification" <?= $statusFilter === 'cash_received_pending_verification' ? 'selected' : '' ?>>Pending verification</option>
                    <option value="paid_verified" <?= $statusFilter === 'paid_verified' ? 'selected' : '' ?>>Paid verified</option>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-body">
        <?php if (empty($list)): ?>
            <p class="text-muted mb-0">No requests in this status.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Type</th>
                            <th>Related ID</th>
                            <th>Amount (AED)</th>
                            <th>Receipt</th>
                            <th>Received at</th>
                            <th>Status</th>
                            <th>Verified at</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $row): ?>
                        <tr>
                            <td><strong><?= h($row['request_number']) ?></strong></td>
                            <td><?= h(str_replace('_', ' ', $row['related_type'])) ?></td>
                            <td><?= (int)$row['related_id'] ?></td>
                            <td><?= number_format((float)$row['amount_aed'], 2) ?></td>
                            <td><?= $row['receipt_number'] ? h($row['receipt_number']) : '—' ?></td>
                            <td><?= $row['received_at'] ? h(date('M j, Y H:i', strtotime($row['received_at']))) : '—' ?></td>
                            <td><span class="badge bg-<?= $row['status'] === 'paid_verified' ? 'success' : 'warning' ?>"><?= h(str_replace('_', ' ', $row['status'])) ?></span></td>
                            <td><?= $row['verified_at'] ? h(date('M j, Y H:i', strtotime($row['verified_at']))) : '—' ?></td>
                            <td>
                                <?php if ($row['status'] === 'cash_received_pending_verification'): ?>
                                <form method="POST" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="verify">
                                    <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success">Approve (set Paid verified)</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
