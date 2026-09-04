<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$line_id = (int) ($_POST['bank_statement_line_id'] ?? 0);
$system_type = trim((string) ($_POST['system_type'] ?? ''));
$system_id = (int) ($_POST['system_id'] ?? 0);
$amount_in = isset($_POST['amount_matched']) ? trim((string) $_POST['amount_matched']) : '';

if ($line_id <= 0 || !in_array($system_type, ['gl_inflow', 'gl_outflow'], true) || $system_id <= 0) {
    co_bank_reco_json_error('Invalid parameters');
}

try {
    $st = $conn->prepare('
        SELECT l.*, b.id AS bank_account_pk, b.gl_account_id
        FROM co_bank_statement_lines l
        JOIN re_bank_accounts b ON b.id = l.bank_account_id AND b.company_id = l.company_id
        WHERE l.id = ? AND l.company_id = ?
    ');
    $st->execute([$line_id, $cid]);
    $line = $st->fetch(PDO::FETCH_ASSOC);
    if (!$line) {
        co_bank_reco_json_error('Statement line not found');
    }

    $bank_pk = (int) $line['bank_account_pk'];
    $gl_account_id = (int) $line['gl_account_id'];

    if (co_bank_is_period_locked($conn, $bank_pk, $line['txn_date'])) {
        co_bank_reco_json_error('This period is locked; matching is blocked.');
    }

    $bamt = (float) $line['amount'];
    $line_rem = co_bank_line_remaining($conn, $line);
    if ($line_rem <= 0.009) {
        co_bank_reco_json_error('Statement line is already fully matched');
    }

    if ($bamt > 0 && $system_type !== 'gl_inflow') {
        co_bank_reco_json_error('Positive bank lines must match GL inflows (debits)');
    }
    if ($bamt < 0 && $system_type !== 'gl_outflow') {
        co_bank_reco_json_error('Negative bank lines must match GL outflows (credits)');
    }

    $st = $conn->prepare('
        SELECT id, debit_amount, credit_amount, account_id
        FROM re_general_ledger
        WHERE id = ? AND company_id = ?
        LIMIT 1
    ');
    $st->execute([$system_id, $cid]);
    $gl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$gl || (int) $gl['account_id'] !== $gl_account_id) {
        co_bank_reco_json_error('GL entry not found or wrong bank account');
    }

    if ($system_type === 'gl_inflow' && (float) $gl['debit_amount'] <= 0) {
        co_bank_reco_json_error('Selected entry is not a debit (inflow)');
    }
    if ($system_type === 'gl_outflow' && (float) $gl['credit_amount'] <= 0) {
        co_bank_reco_json_error('Selected entry is not a credit (outflow)');
    }

    $entry_amt = co_gl_entry_amount($gl);
    $sys_rem = round($entry_amt - co_gl_entry_matched_sum($conn, $system_id), 2);

    if ($amount_in !== '') {
        $amt = round((float) $amount_in, 2);
    } else {
        $amt = round(min($line_rem, $sys_rem), 2);
    }

    if ($amt <= 0) {
        co_bank_reco_json_error('Nothing to match');
    }
    if ($amt > $line_rem + 0.01 || $amt > $sys_rem + 0.01) {
        co_bank_reco_json_error('Amount exceeds remaining on statement line or GL entry');
    }

    $uid = current_user_id();
    $st = $conn->prepare("
        INSERT INTO co_reconciliation_matches
          (bank_statement_line_id, system_type, system_id, amount_matched, status, period_lock_key, created_by)
        VALUES (?, ?, ?, ?, 'proposed', NULL, ?)
    ");
    $st->execute([$line_id, $system_type, $system_id, $amt, $uid ?: null]);

    $new_id = (int) $conn->lastInsertId();
    $dup = co_match_duplicate_warnings($conn, $system_type, $system_id, $new_id);

    echo json_encode([
        'success' => true,
        'match_id' => $new_id,
        'duplicate_warnings' => $dup,
    ]);
} catch (Throwable $e) {
    error_log('co bank_reco_match: ' . $e->getMessage());
    co_bank_reco_json_error($e->getMessage());
}
