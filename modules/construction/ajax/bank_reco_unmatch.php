<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$match_id = (int) ($_POST['match_id'] ?? 0);
if ($match_id <= 0) {
    co_bank_reco_json_error('match_id required');
}

try {
    $st = $conn->prepare("
        SELECT m.*, l.txn_date, l.bank_account_id
        FROM co_reconciliation_matches m
        INNER JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
        WHERE m.id = ? AND l.company_id = ? AND m.status IN ('proposed','confirmed')
    ");
    $st->execute([$match_id, $cid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        co_bank_reco_json_error('Match not found or already void');
    }

    if (co_bank_is_period_locked($conn, (int) $row['bank_account_id'], $row['txn_date'])) {
        co_bank_reco_json_error('This period is locked; unmatch is blocked.');
    }

    $uid = current_user_id();
    $gl_id = (int) $row['system_id'];
    $st = $conn->prepare("
        UPDATE co_reconciliation_matches
        SET status = 'void', voided_by = ?, voided_at = NOW()
        WHERE id = ? AND status IN ('proposed','confirmed')
    ");
    $st->execute([$uid ?: null, $match_id]);

    co_bank_sync_gl_reconciled_flag($conn, $gl_id, $cid, $uid);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('co bank_reco_unmatch: ' . $e->getMessage());
    co_bank_reco_json_error($e->getMessage());
}
