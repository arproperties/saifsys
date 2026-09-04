<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
if ($bank_id <= 0 || empty($_FILES['file']['tmp_name'])) {
    co_bank_reco_json_error('bank_account_id and file required');
}

if (!co_bank_verify_account($conn, $bank_id, $cid)) {
    co_bank_reco_json_error('Invalid bank account');
}

$path = $_FILES['file']['tmp_name'];
$name = (string) ($_FILES['file']['name'] ?? 'import.csv');
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$allowed = ['csv', 'txt', 'xlsx', 'xls'];
if (!in_array($ext, $allowed, true)) {
    co_bank_reco_json_error('Supported formats: CSV, XLSX, XLS');
}

if (!is_uploaded_file($path)) {
    co_bank_reco_json_error('Upload failed');
}

$parsed = co_bank_parse_statement_upload($path, $name);
if ($parsed['error']) {
    co_bank_reco_json_error($parsed['error']);
}

$rows = $parsed['rows'];
$inserted = 0;
$skipped_locked = 0;
$skipped_dup = 0;
$skipped_bad = 0;

try {
    $conn->beginTransaction();

    $uid = current_user_id();
    $stBatch = $conn->prepare("
        INSERT INTO co_bank_import_batches (company_id, bank_account_id, file_name, imported_by, row_count, notes)
        VALUES (?, ?, ?, ?, 0, NULL)
    ");
    $stBatch->execute([$cid, $bank_id, $name, $uid ?: null]);
    $batch_id = (int) $conn->lastInsertId();

    $ins = $conn->prepare("
        INSERT INTO co_bank_statement_lines
          (company_id, bank_account_id, txn_date, amount, description, reference, import_batch_id, source_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($rows as $r) {
        $d = co_bank_normalize_import_date($r['date']);
        if ($d === null) {
            $skipped_bad++;
            continue;
        }
        if (co_bank_is_period_locked($conn, $bank_id, $d)) {
            $skipped_locked++;
            continue;
        }

        $amt = co_bank_normalize_import_amount($r['amount']);
        if ($amt === null) {
            $skipped_bad++;
            continue;
        }

        $desc = mb_substr($r['description'], 0, 500);
        $ref = $r['reference'] !== '' ? mb_substr($r['reference'], 0, 120) : null;
        $hash = co_source_hash($bank_id, $d, (string) $amt, $desc, (string) ($ref ?? ''));

        $chk = $conn->prepare('SELECT id FROM co_bank_statement_lines WHERE bank_account_id = ? AND source_hash = ? LIMIT 1');
        $chk->execute([$bank_id, $hash]);
        if ($chk->fetch()) {
            $skipped_dup++;
            continue;
        }

        $ins->execute([$cid, $bank_id, $d, $amt, $desc, $ref, $batch_id, $hash]);
        $inserted++;
    }

    $up = $conn->prepare('UPDATE co_bank_import_batches SET row_count = ? WHERE id = ?');
    $up->execute([$inserted, $batch_id]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'batch_id' => $batch_id,
        'inserted' => $inserted,
        'skipped_locked' => $skipped_locked,
        'skipped_dup' => $skipped_dup,
        'skipped_bad' => $skipped_bad,
        'format' => $ext,
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('co bank_reco_import: ' . $e->getMessage());
    co_bank_reco_json_error($e->getMessage());
}
