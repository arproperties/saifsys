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

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
$d = trim((string) ($_POST['txn_date'] ?? ''));
$amount_raw = trim((string) ($_POST['amount'] ?? ''));
$desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
$ref = trim((string) ($_POST['reference'] ?? ''));
$ref = $ref !== '' ? mb_substr($ref, 0, 120) : null;

if ($bank_id <= 0 || $d === '' || $amount_raw === '') {
    echo json_encode(['success' => false, 'error' => 'bank_account_id, txn_date, amount required']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
    echo json_encode(['success' => false, 'error' => 'txn_date must be YYYY-MM-DD']);
    exit;
}

$st = $conn->prepare('SELECT id FROM cleaning_bank_accounts WHERE id = ? LIMIT 1');
$st->execute([$bank_id]);
if (!$st->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Invalid bank account']);
    exit;
}

if (cleaning_bank_is_period_locked($conn, $bank_id, $d)) {
    echo json_encode(['success' => false, 'error' => 'This period is locked; imports are blocked.']);
    exit;
}

if (!is_numeric($amount_raw)) {
    echo json_encode(['success' => false, 'error' => 'Invalid amount']);
    exit;
}
$amt = round((float) $amount_raw, 2);

try {
    $hash = cleaning_source_hash($bank_id, $d, (string) $amt, $desc, (string) ($ref ?? ''));
    $chk = $conn->prepare('SELECT id FROM cleaning_bank_statement_lines WHERE bank_account_id = ? AND source_hash = ? LIMIT 1');
    $chk->execute([$bank_id, $hash]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Duplicate line (same date, amount, description, reference)']);
        exit;
    }

    $uid = current_user_id();
    $st = $conn->prepare("
        INSERT INTO cleaning_bank_import_batches (bank_account_id, file_name, imported_by, row_count, notes)
        VALUES (?, 'manual', ?, 1, 'manual line')
    ");
    $st->execute([$bank_id, $uid ?: null]);
    $batch_id = (int) $conn->lastInsertId();

    $ins = $conn->prepare("
        INSERT INTO cleaning_bank_statement_lines
          (bank_account_id, txn_date, amount, description, reference, import_batch_id, source_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$bank_id, $d, $amt, $desc, $ref, $batch_id, $hash]);
    $line_id = (int) $conn->lastInsertId();

    echo json_encode(['success' => true, 'line_id' => $line_id, 'batch_id' => $batch_id]);
} catch (Throwable $e) {
    error_log('bank_reco_manual_line: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
