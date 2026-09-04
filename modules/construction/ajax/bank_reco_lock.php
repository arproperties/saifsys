<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $bank_id = (int) ($_GET['bank_account_id'] ?? 0);
    if ($bank_id <= 0) {
        co_bank_reco_json_error('bank_account_id required');
    }
    if (!co_bank_verify_account($conn, $bank_id, $cid)) {
        co_bank_reco_json_error('Invalid bank account');
    }
    if (!co_db_table_exists($conn, 'co_reconciliation_period_locks')) {
        echo json_encode(['success' => true, 'locks' => []]);
        exit;
    }
    $st = $conn->prepare('SELECT period, locked, locked_at, notes FROM co_reconciliation_period_locks WHERE bank_account_id = ? ORDER BY period DESC');
    $st->execute([$bank_id]);
    echo json_encode(['success' => true, 'locks' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    exit;
}

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
$period = trim((string) ($_POST['period'] ?? ''));
$locked = isset($_POST['locked']) ? (int) $_POST['locked'] : 1;

if ($bank_id <= 0 || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    co_bank_reco_json_error('bank_account_id and period (YYYY-MM) required');
}

if (!co_bank_verify_account($conn, $bank_id, $cid)) {
    co_bank_reco_json_error('Invalid bank account');
}

if (!co_db_table_exists($conn, 'co_reconciliation_period_locks')) {
    co_bank_reco_json_error('Period lock table not installed');
}

try {
    $uid = current_user_id();
    if ($locked) {
        $st = $conn->prepare("
            INSERT INTO co_reconciliation_period_locks (bank_account_id, period, locked, locked_by, locked_at, notes)
            VALUES (?, ?, 1, ?, NOW(), NULL)
            ON DUPLICATE KEY UPDATE locked = 1, locked_by = VALUES(locked_by), locked_at = NOW()
        ");
        $st->execute([$bank_id, $period, $uid ?: null]);
    } else {
        $st = $conn->prepare('DELETE FROM co_reconciliation_period_locks WHERE bank_account_id = ? AND period = ?');
        $st->execute([$bank_id, $period]);
    }
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('co bank_reco_lock: ' . $e->getMessage());
    co_bank_reco_json_error($e->getMessage());
}
