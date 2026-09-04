<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
$d = trim((string) ($_POST['txn_date'] ?? ''));
$amount_raw = trim((string) ($_POST['amount'] ?? ''));
$desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
$ref = trim((string) ($_POST['reference'] ?? ''));
$ref = $ref !== '' ? mb_substr($ref, 0, 120) : null;

if ($bank_id <= 0 || $d === '' || $amount_raw === '') {
    co_bank_reco_json_error('bank_account_id, txn_date, amount required');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
    co_bank_reco_json_error('txn_date must be YYYY-MM-DD');
}

if (!co_bank_verify_account($conn, $bank_id, $cid)) {
    co_bank_reco_json_error('Invalid bank account');
}

if (co_bank_is_period_locked($conn, $bank_id, $d)) {
    co_bank_reco_json_error('This period is locked; imports are blocked.');
}

if (!is_numeric($amount_raw)) {
    co_bank_reco_json_error('Invalid amount');
}
$amt = round((float) $amount_raw, 2);

try {
    $hash = co_source_hash($bank_id, $d, (string) $amt, $desc, (string) ($ref ?? ''));
    $chk = $conn->prepare('SELECT id FROM co_bank_statement_lines WHERE bank_account_id = ? AND source_hash = ? LIMIT 1');
    $chk->execute([$bank_id, $hash]);
    if ($chk->fetch()) {
        co_bank_reco_json_error('Duplicate line (same date, amount, description, reference)');
    }

    $uid = current_user_id();
    $st = $conn->prepare("
        INSERT INTO co_bank_import_batches (company_id, bank_account_id, file_name, imported_by, row_count, notes)
        VALUES (?, ?, 'manual', ?, 1, 'manual line')
    ");
    $st->execute([$cid, $bank_id, $uid ?: null]);
    $batch_id = (int) $conn->lastInsertId();

    $ins = $conn->prepare("
        INSERT INTO co_bank_statement_lines
          (company_id, bank_account_id, txn_date, amount, description, reference, import_batch_id, source_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$cid, $bank_id, $d, $amt, $desc, $ref, $batch_id, $hash]);
    $line_id = (int) $conn->lastInsertId();

    echo json_encode(['success' => true, 'line_id' => $line_id, 'batch_id' => $batch_id]);
} catch (Throwable $e) {
    error_log('co bank_reco_manual_line: ' . $e->getMessage());
    co_bank_reco_json_error($e->getMessage());
}
