<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/cleaning_bank_reconciliation.php';

require_role(['Owner', 'Admin', 'Account'], $conn);

header('Content-Type: application/json; charset=utf-8');

$csrfOk = isset($_POST['_csrf']) && hash_equals($_SESSION['_csrf'] ?? '', (string) ($_POST['_csrf'] ?? ''));
if (!$csrfOk) {
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid or missing.']);
    exit;
}

$match_id = (int) ($_POST['match_id'] ?? 0);
if ($match_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'match_id required']);
    exit;
}

try {
    $st = $conn->prepare("
        SELECT m.*, l.txn_date, l.bank_account_id
        FROM cleaning_reconciliation_matches m
        INNER JOIN cleaning_bank_statement_lines l ON l.id = m.bank_statement_line_id
        WHERE m.id = ? AND m.status IN ('proposed','confirmed')
    ");
    $st->execute([$match_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Match not found or already void']);
        exit;
    }

    if (cleaning_bank_is_period_locked($conn, (int) $row['bank_account_id'], $row['txn_date'])) {
        echo json_encode(['success' => false, 'error' => 'This period is locked; unmatch is blocked.']);
        exit;
    }

    $uid = current_user_id();
    $st = $conn->prepare("
        UPDATE cleaning_reconciliation_matches
        SET status = 'void', voided_by = ?, voided_at = NOW()
        WHERE id = ? AND status IN ('proposed','confirmed')
    ");
    $st->execute([$uid ?: null, $match_id]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('bank_reco_unmatch: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
