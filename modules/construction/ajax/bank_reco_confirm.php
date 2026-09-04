<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$raw = $_POST['match_ids'] ?? '[]';
if (is_string($raw)) {
    $ids = json_decode($raw, true);
} else {
    $ids = $raw;
}
if (!is_array($ids)) {
    $ids = [];
}
$ids = array_values(array_filter(array_map('intval', $ids), function ($x) {
    return $x > 0;
}));

if (!$ids) {
    co_bank_reco_json_error('match_ids required');
}

$uid = current_user_id();
$confirmed = 0;
$errors = [];

try {
    foreach ($ids as $mid) {
        $st = $conn->prepare("
            SELECT m.id, m.status, m.system_id, l.txn_date, l.bank_account_id
            FROM co_reconciliation_matches m
            INNER JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
            WHERE m.id = ? AND l.company_id = ?
        ");
        $st->execute([$mid, $cid]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m || $m['status'] !== 'proposed') {
            $errors[] = "Match #{$mid}: not proposed or missing";
            continue;
        }
        if (co_bank_is_period_locked($conn, (int) $m['bank_account_id'], $m['txn_date'])) {
            $errors[] = "Match #{$mid}: period locked";
            continue;
        }
        $up = $conn->prepare("
            UPDATE co_reconciliation_matches
            SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW()
            WHERE id = ? AND status = 'proposed'
        ");
        $up->execute([$uid ?: null, $mid]);
        if ($up->rowCount()) {
            $confirmed++;
            co_bank_sync_gl_reconciled_flag($conn, (int) $m['system_id'], $cid, $uid);
        }
    }

    echo json_encode([
        'success' => true,
        'confirmed' => $confirmed,
        'errors' => $errors,
    ]);
} catch (Throwable $e) {
    error_log('co bank_reco_confirm: ' . $e->getMessage());
    co_bank_reco_json_error($e->getMessage());
}
