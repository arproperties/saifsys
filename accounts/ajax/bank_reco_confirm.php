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

$raw = $_POST['match_ids'] ?? '[]';
if (is_string($raw)) {
    $ids = json_decode($raw, true);
} else {
    $ids = $raw;
}
if (!is_array($ids)) {
    $ids = [];
}
$ids = array_values(array_filter(array_map('intval', $ids), function ($x) { return $x > 0; }));

if (!$ids) {
    echo json_encode(['success' => false, 'error' => 'match_ids required']);
    exit;
}

$uid = current_user_id();
$confirmed = 0;
$errors = [];

try {
    foreach ($ids as $mid) {
        $st = $conn->prepare("
            SELECT m.id, m.status, l.txn_date, l.bank_account_id
            FROM cleaning_reconciliation_matches m
            INNER JOIN cleaning_bank_statement_lines l ON l.id = m.bank_statement_line_id
            WHERE m.id = ?
        ");
        $st->execute([$mid]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m || $m['status'] !== 'proposed') {
            $errors[] = "Match #{$mid}: not proposed or missing";
            continue;
        }
        if (cleaning_bank_is_period_locked($conn, (int) $m['bank_account_id'], $m['txn_date'])) {
            $errors[] = "Match #{$mid}: period locked";
            continue;
        }
        $up = $conn->prepare("
            UPDATE cleaning_reconciliation_matches
            SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW()
            WHERE id = ? AND status = 'proposed'
        ");
        $up->execute([$uid ?: null, $mid]);
        $confirmed += $up->rowCount();
    }

    echo json_encode([
        'success' => true,
        'confirmed' => $confirmed,
        'errors' => $errors,
    ]);
} catch (Throwable $e) {
    error_log('bank_reco_confirm: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
