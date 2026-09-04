<?php
/**
 * Real Estate — Cashier session: open/close, expected vs actual, variance log.
 * Receptionist (Operations) must open a session before recording any cash receipt.
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
    $pageTitle = 'Cashier session';
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

// Open session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open') {
    csrf_verify();
    $open = $conn->prepare("SELECT id FROM re_cashier_sessions WHERE company_id = ? AND user_id = ? AND status = 'open' LIMIT 1");
    $open->execute([$currentCompanyId, $userId]);
    if ($open->fetch()) {
        $message = 'You already have an open session.';
        $messageType = 'warning';
    } else {
        $ins = $conn->prepare("INSERT INTO re_cashier_sessions (company_id, user_id, status) VALUES (?, ?, 'open')");
        $ins->execute([$currentCompanyId, $userId]);
        $message = 'Session opened. You can now record cash payments.';
        $messageType = 'success';
    }
}

// Close session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    csrf_verify();
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $actualAmount = trim($_POST['actual_amount'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $session = $conn->prepare("SELECT id, user_id, status FROM re_cashier_sessions WHERE id = ? AND company_id = ?");
    $session->execute([$sessionId, $currentCompanyId]);
    $session = $session->fetch(PDO::FETCH_ASSOC);
    if (!$session || $session['status'] !== 'open' || (int)$session['user_id'] !== $userId) {
        $message = 'Invalid or already closed session.';
        $messageType = 'danger';
    } elseif ($actualAmount === '' || !is_numeric($actualAmount)) {
        $message = 'Enter the actual cash count (number).';
        $messageType = 'danger';
    } else {
        $expected = $conn->prepare("SELECT COALESCE(SUM(amount_aed), 0) FROM re_cash_payment_requests WHERE cashier_session_id = ? AND status IN ('cash_received_pending_verification','paid_verified')");
        $expected->execute([$sessionId]);
        $expectedAmount = (float)$expected->fetchColumn();
        $actualAmountFloat = (float)$actualAmount;
        $variance = $actualAmountFloat - $expectedAmount;
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE re_cashier_sessions SET closed_at = NOW(), expected_amount = ?, actual_amount = ?, variance_amount = ?, status = 'closed', notes = ? WHERE id = ?")
                ->execute([$expectedAmount, $actualAmountFloat, $variance, $notes ?: null, $sessionId]);
            $conn->prepare("INSERT INTO re_cash_payment_variance_log (cashier_session_id, expected_amount, actual_amount, variance_amount, recorded_by, notes) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$sessionId, $expectedAmount, $actualAmountFloat, $variance, $userId, $notes ?: null]);
            $conn->commit();
            $message = 'Session closed. Expected: ' . number_format($expectedAmount, 2) . ' AED, Actual: ' . number_format($actualAmountFloat, 2) . ' AED, Variance: ' . number_format($variance, 2) . ' AED.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $conn->rollBack();
            $message = 'Failed to close session.';
            $messageType = 'danger';
        }
    }
}

$currentSession = null;
$openSessions = $conn->prepare("SELECT id, opened_at FROM re_cashier_sessions WHERE company_id = ? AND user_id = ? AND status = 'open' ORDER BY opened_at DESC LIMIT 1");
$openSessions->execute([$currentCompanyId, $userId]);
$currentSession = $openSessions->fetch(PDO::FETCH_ASSOC);

$sessions = $conn->prepare("
    SELECT s.id, s.opened_at, s.closed_at, s.expected_amount, s.actual_amount, s.variance_amount, s.status, u.username
    FROM re_cashier_sessions s
    LEFT JOIN user u ON u.id = s.user_id
    WHERE s.company_id = ?
    ORDER BY s.opened_at DESC
    LIMIT 50
");
$sessions->execute([$currentCompanyId]);
$sessions = $sessions->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Cashier session';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Cashier session</div>
    <a href="cash_payment_requests.php" class="btn btn-outline-primary"><i class="bi bi-cash-coin"></i> Cash payment requests</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($currentSession): ?>
<div class="card card-round mb-4 border-success">
    <div class="card-body">
        <h5 class="text-success"><i class="bi bi-unlock"></i> Session open</h5>
        <p class="mb-0">Started <?= h(date('M j, Y g:i A', strtotime($currentSession['opened_at']))) ?>. Record cash receipts in <a href="cash_payment_requests.php">Cash payment requests</a>, then close this session when done.</p>
        <form method="POST" class="mt-3">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="session_id" value="<?= (int)$currentSession['id'] ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Actual cash count (AED)</label>
                    <input type="number" name="actual_amount" class="form-control" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Notes (optional)</label>
                    <input type="text" name="notes" class="form-control" placeholder="Variance reason">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-warning">Close session</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card card-round mb-4">
    <div class="card-body">
        <h5>No open session</h5>
        <p class="text-muted mb-3">Open a session before recording any cash payment at the reception.</p>
        <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="open">
            <button type="submit" class="btn btn-primary">Open session</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card card-round">
    <div class="card-header">Recent sessions</div>
    <div class="card-body">
        <?php if (empty($sessions)): ?>
            <p class="text-muted mb-0">No sessions yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Opened</th>
                            <th>Closed</th>
                            <th>Expected (AED)</th>
                            <th>Actual (AED)</th>
                            <th>Variance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td><?= h(date('M j, Y H:i', strtotime($s['opened_at']))) ?></td>
                            <td><?= $s['closed_at'] ? h(date('M j, Y H:i', strtotime($s['closed_at']))) : '—' ?></td>
                            <td><?= $s['expected_amount'] !== null ? number_format((float)$s['expected_amount'], 2) : '—' ?></td>
                            <td><?= $s['actual_amount'] !== null ? number_format((float)$s['actual_amount'], 2) : '—' ?></td>
                            <td><?php
                                $v = (float)($s['variance_amount'] ?? 0);
                                echo $v !== 0.0 ? '<span class="' . ($v < 0 ? 'text-danger' : 'text-success') . '">' . number_format($v, 2) . '</span>' : '—';
                            ?></td>
                            <td><span class="badge bg-<?= $s['status'] === 'open' ? 'success' : 'secondary' ?>"><?= h($s['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
