<?php
/**
 * Real Estate — Cash Payment Requests (Receptionist).
 * Search by Request ID, confirm cash received (amount must match), tie to cashier session, generate receipt.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_OPERATIONS, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

require_once __DIR__ . '/includes/cash_payment_helper.php';
if (!cash_payment_tables_exist($conn)) {
    $pageTitle = 'Cash payment requests';
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

// Mark expired requests (pending_cash_payment past expires_at)
try {
    $conn->prepare("UPDATE re_cash_payment_requests SET status = 'expired' WHERE company_id = ? AND status = 'pending_cash_payment' AND expires_at < NOW()")->execute([$currentCompanyId]);
} catch (Throwable $e) {}

// Open session check
$openSession = $conn->prepare("SELECT id FROM re_cashier_sessions WHERE company_id = ? AND user_id = ? AND status = 'open' LIMIT 1");
$openSession->execute([$currentCompanyId, $userId]);
$openSession = $openSession->fetch(PDO::FETCH_ASSOC);

// Confirm cash received
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_cash') {
    csrf_verify();
    $requestId = (int)($_POST['request_id'] ?? 0);
    $amountReceived = trim($_POST['amount_received'] ?? '');
    if (!$openSession) {
        $message = 'Open a cashier session first from <a href="cashier_session.php">Cashier session</a>.';
        $messageType = 'danger';
    } elseif (!$requestId || $amountReceived === '' || !is_numeric($amountReceived)) {
        $message = 'Invalid request or amount.';
        $messageType = 'danger';
    } else {
        $req = $conn->prepare("SELECT id, amount_aed, status, expires_at FROM re_cash_payment_requests WHERE id = ? AND company_id = ?");
        $req->execute([$requestId, $currentCompanyId]);
        $req = $req->fetch(PDO::FETCH_ASSOC);
        if (!$req || $req['status'] !== 'pending_cash_payment') {
            $message = 'Request not found or not pending cash payment.';
            $messageType = 'danger';
        } elseif ($req['expires_at'] && strtotime($req['expires_at']) < time()) {
            $conn->prepare("UPDATE re_cash_payment_requests SET status = 'expired' WHERE id = ? AND company_id = ?")->execute([$requestId, $currentCompanyId]);
            $message = 'This request has expired. Tenant must create a new one.';
            $messageType = 'danger';
        } elseif (abs((float)$amountReceived - (float)$req['amount_aed']) > 0.01) {
            $message = 'Amount received must match request amount (' . number_format((float)$req['amount_aed'], 2) . ' AED).';
            $messageType = 'danger';
        } else {
            $sessionId = (int)$openSession['id'];
            $conn->beginTransaction();
            try {
                $seq = $conn->prepare("SELECT id, last_number FROM re_cash_receipt_sequences WHERE company_id = ? AND cashier_session_id = ? FOR UPDATE");
                $seq->execute([$currentCompanyId, $sessionId]);
                $seqRow = $seq->fetch(PDO::FETCH_ASSOC);
                if ($seqRow) {
                    $next = (int)$seqRow['last_number'] + 1;
                    $conn->prepare("UPDATE re_cash_receipt_sequences SET last_number = ?, updated_at = NOW() WHERE id = ?")->execute([$next, $seqRow['id']]);
                } else {
                    $conn->prepare("INSERT INTO re_cash_receipt_sequences (company_id, cashier_session_id, last_number) VALUES (?, ?, 1)")->execute([$currentCompanyId, $sessionId]);
                    $next = 1;
                }
                $receiptNumber = 'REC-' . date('Ymd') . '-' . $sessionId . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
                $oldStatus = $req['status'];
                $conn->prepare("
                    UPDATE re_cash_payment_requests
                    SET status = 'cash_received_pending_verification', received_at = NOW(), received_by = ?, cashier_session_id = ?, receipt_number = ?
                    WHERE id = ? AND company_id = ?
                ")->execute([$userId, $sessionId, $receiptNumber, $requestId, $currentCompanyId]);
                cash_payment_audit($conn, $requestId, 'status_change', 'status', $oldStatus, 'cash_received_pending_verification');
                $conn->commit();
                if (file_exists(__DIR__ . '/includes/re_email_helper.php')) {
                    require_once __DIR__ . '/includes/re_email_helper.php';
                    send_cash_payment_pending_verification_notification($conn, $requestId, $currentCompanyId);
                }
                $message = 'Cash received recorded. Receipt: ' . $receiptNumber . '. Pending accounting verification.';
                $messageType = 'success';
            } catch (Throwable $e) {
                $conn->rollBack();
                $message = 'Failed to record: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$list = [];
if ($search !== '') {
    $stmt = $conn->prepare("
        SELECT r.id, r.request_number, r.related_type, r.related_id, r.amount_aed, r.status, r.expires_at, r.created_at
        FROM re_cash_payment_requests r
        WHERE r.company_id = ? AND (r.request_number = ? OR r.request_number LIKE ?)
        ORDER BY r.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$currentCompanyId, $search, $search . '%']);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $where = "r.company_id = ?";
    $params = [$currentCompanyId];
    if (in_array($statusFilter, ['pending_cash_payment', 'cash_received_pending_verification', 'paid_verified', 'expired'], true)) {
        $where .= " AND r.status = ?";
        $params[] = $statusFilter;
    }
    $stmt = $conn->prepare("
        SELECT r.id, r.request_number, r.related_type, r.related_id, r.amount_aed, r.status, r.expires_at, r.receipt_number, r.received_at, r.created_at
        FROM re_cash_payment_requests r
        WHERE {$where}
        ORDER BY r.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Cash payment requests';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="page-header-label">Cash payment requests</div>
    <div class="d-flex gap-2">
        <a href="cash_verification.php" class="btn btn-outline-success"><i class="bi bi-patch-check"></i> Cash verification</a>
        <a href="cashier_session.php" class="btn btn-outline-primary"><i class="bi bi-box"></i> Cashier session</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
<?php endif; ?>

<div class="alert alert-info mb-3">
    <strong>Workflow:</strong> <em>Pending cash</em> = tenant must pay at reception. After you <strong>Confirm cash</strong>, the request moves to <em>Pending verification</em>. The <strong>accountant</strong> (or owner/manager) approves it in <strong>Financial → Cash verification</strong>, which sets it to <em>Paid verified</em>. Use the Status filter below to see all stages.
</div>

<?php if (!$openSession): ?>
    <div class="alert alert-warning">You must <a href="cashier_session.php">open a cashier session</a> before recording any cash receipt.</div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Search by Request ID</label>
                <input type="text" name="q" class="form-control" value="<?= h($search) ?>" placeholder="e.g. CPR-2026-0001">
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="pending_cash_payment" <?= $statusFilter === 'pending_cash_payment' ? 'selected' : '' ?>>Pending cash</option>
                    <option value="cash_received_pending_verification" <?= $statusFilter === 'cash_received_pending_verification' ? 'selected' : '' ?>>Pending verification</option>
                    <option value="paid_verified" <?= $statusFilter === 'paid_verified' ? 'selected' : '' ?>>Paid verified</option>
                    <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">Search</button>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-body">
        <?php if (empty($list)): ?>
            <p class="text-muted mb-0">
                <?php if ($search !== ''): ?>
                    No requests match your search.
                <?php elseif ($statusFilter === 'pending_cash_payment'): ?>
                    No requests awaiting cash. After you confirm a payment, it moves to <strong>Pending verification</strong> (change Status filter above). Accountants approve in <strong>Financial → Cash verification</strong>.
                <?php else: ?>
                    No cash payment requests in this status.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Type</th>
                            <th>Related ID</th>
                            <th>Amount (AED)</th>
                            <th>Status</th>
                            <th>Expires</th>
                            <th>Receipt</th>
                            <th>Received</th>
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
                            <td><span class="badge bg-<?= $row['status'] === 'paid_verified' ? 'success' : ($row['status'] === 'expired' ? 'secondary' : 'warning') ?>"><?= h(str_replace('_', ' ', $row['status'])) ?></span></td>
                            <td><?= $row['expires_at'] ? h(date('M j, Y H:i', strtotime($row['expires_at']))) : '—' ?></td>
                            <td><?= $row['receipt_number'] ? h($row['receipt_number']) : '—' ?></td>
                            <td><?= $row['received_at'] ? h(date('M j, H:i', strtotime($row['received_at']))) : '—' ?></td>
                            <td>
                                <?php if ($row['status'] === 'pending_cash_payment' && $openSession): ?>
                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#confirmModal" data-request-id="<?= (int)$row['id'] ?>" data-amount="<?= h($row['amount_aed']) ?>" data-request-number="<?= h($row['request_number']) ?>">Confirm cash</button>
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

<!-- Confirm cash modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="confirm_cash">
                <input type="hidden" name="request_id" id="modal_request_id">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm cash received</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Request ID: <strong id="modal_request_number"></strong></p>
                    <p>Expected amount: <strong id="modal_amount_display"></strong> AED</p>
                    <div class="mb-3">
                        <label class="form-label">Amount received (AED)</label>
                        <input type="number" name="amount_received" id="modal_amount_received" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm receipt</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('confirmModal')?.addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    if (!btn) return;
    var id = btn.getAttribute('data-request-id');
    var amount = btn.getAttribute('data-amount');
    var num = btn.getAttribute('data-request-number');
    document.getElementById('modal_request_id').value = id;
    document.getElementById('modal_request_number').textContent = num;
    document.getElementById('modal_amount_display').textContent = amount;
    document.getElementById('modal_amount_received').value = amount;
});
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
