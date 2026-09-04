<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';

require_role(['Owner', 'Admin', 'Account'], $conn);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $bank_id = (int) ($_GET['bank_account_id'] ?? 0);
    if ($bank_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'bank_account_id required']);
        exit;
    }
    $st = $conn->prepare('SELECT period, locked, locked_at, notes FROM cleaning_reconciliation_period_locks WHERE bank_account_id = ? ORDER BY period DESC');
    $st->execute([$bank_id]);
    echo json_encode(['success' => true, 'locks' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    exit;
}

$csrfOk = isset($_POST['_csrf']) && hash_equals($_SESSION['_csrf'] ?? '', (string) ($_POST['_csrf'] ?? ''));
if (!$csrfOk) {
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid or missing.']);
    exit;
}

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
$period = trim((string) ($_POST['period'] ?? ''));
$locked = isset($_POST['locked']) ? (int) $_POST['locked'] : 1;

if ($bank_id <= 0 || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    echo json_encode(['success' => false, 'error' => 'bank_account_id and period (YYYY-MM) required']);
    exit;
}

$st = $conn->prepare('SELECT id FROM cleaning_bank_accounts WHERE id = ? LIMIT 1');
$st->execute([$bank_id]);
if (!$st->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Invalid bank account']);
    exit;
}

try {
    $uid = current_user_id();
    if ($locked) {
        $st = $conn->prepare("
            INSERT INTO cleaning_reconciliation_period_locks (bank_account_id, period, locked, locked_by, locked_at, notes)
            VALUES (?, ?, 1, ?, NOW(), NULL)
            ON DUPLICATE KEY UPDATE locked = 1, locked_by = VALUES(locked_by), locked_at = NOW()
        ");
        $st->execute([$bank_id, $period, $uid ?: null]);
    } else {
        $st = $conn->prepare('DELETE FROM cleaning_reconciliation_period_locks WHERE bank_account_id = ? AND period = ?');
        $st->execute([$bank_id, $period]);
    }
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('bank_reco_lock: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
