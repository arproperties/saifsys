<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.match');

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$line_id = (int) ($_POST['line_id'] ?? 0);
$system_type = trim((string) ($_POST['system_type'] ?? ''));
$system_id = (int) ($_POST['system_id'] ?? 0);
$match_id = (int) ($_POST['match_id'] ?? 0);

$uid = current_user_id();

try {
    if ($match_id > 0) {
        $result = co_bank_reco_confirm_matches($conn, $cid, [$match_id], $uid);
        echo json_encode([
            'success' => $result['success'],
            'confirmed' => $result['confirmed'],
            'errors' => $result['errors'],
        ]);
        return;
    }

    if ($line_id <= 0 || !in_array($system_type, ['gl_inflow', 'gl_outflow'], true) || $system_id <= 0) {
        co_bank_reco_json_error('line_id, system_type, system_id required');
    }

    $line = co_bank_get_statement_line($conn, $line_id, $cid);
    if (!$line) {
        co_bank_reco_json_error('Statement line not found');
    }
    if (co_bank_is_period_locked($conn, (int) $line['bank_account_id'], $line['txn_date'])) {
        co_bank_reco_json_error('Period locked');
    }

    $suggestions = co_bank_reco_gl_suggestions($conn, $cid, $line);
    $score = null;
    $confidence = null;
    foreach ($suggestions as $s) {
        if ((int) $s['system_id'] === $system_id && $s['system_type'] === $system_type) {
            $score = $s['score'];
            $confidence = $s['confidence'];
            break;
        }
    }

    $rem = co_bank_line_remaining($conn, $line);
    $amt = $rem;
    $insCols = 'bank_statement_line_id, system_type, system_id, amount_matched, status, period_lock_key, created_by';
    $insVals = [$line_id, $system_type, $system_id, $amt, 'proposed', null, $uid ?: null];
    if (co_db_column_exists($conn, 'co_reconciliation_matches', 'company_id')) {
        $insCols .= ', company_id, bank_account_id, match_method, confidence_score, confidence_label';
        $insVals = array_merge($insVals, [$cid, (int) $line['bank_account_id'], 'match', $score, $confidence]);
    }
    $conn->prepare('INSERT INTO co_reconciliation_matches (' . $insCols . ') VALUES (' . implode(',', array_fill(0, count($insVals), '?')) . ')')
        ->execute($insVals);
    $newMatchId = (int) $conn->lastInsertId();

    $confirm = co_bank_reco_confirm_matches($conn, $cid, [$newMatchId], $uid);
    if (!$confirm['confirmed']) {
        co_bank_reco_json_error($confirm['errors'][0] ?? 'Could not confirm match');
    }

    co_bank_reco_audit($conn, $cid, (int) $line['bank_account_id'], $line_id, $newMatchId, 'reconcile_ok', null, [
        'system_type' => $system_type,
        'system_id' => $system_id,
        'amount' => $amt,
    ], $uid, $system_id);

    echo json_encode([
        'success' => true,
        'match_id' => $newMatchId,
        'confirmed' => 1,
    ]);
} catch (Throwable $e) {
    co_bank_reco_json_error($e->getMessage());
}
