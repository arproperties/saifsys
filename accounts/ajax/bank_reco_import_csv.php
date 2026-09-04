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
if ($bank_id <= 0 || empty($_FILES['file']['tmp_name'])) {
    echo json_encode(['success' => false, 'error' => 'bank_account_id and CSV file required']);
    exit;
}

$st = $conn->prepare('SELECT id FROM cleaning_bank_accounts WHERE id = ? LIMIT 1');
$st->execute([$bank_id]);
if (!$st->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Invalid bank account']);
    exit;
}

$path = $_FILES['file']['tmp_name'];
$name = (string) ($_FILES['file']['name'] ?? 'import.csv');

if (!is_uploaded_file($path)) {
    echo json_encode(['success' => false, 'error' => 'Upload failed']);
    exit;
}

$fh = fopen($path, 'rb');
if (!$fh) {
    echo json_encode(['success' => false, 'error' => 'Could not read file']);
    exit;
}

$header = fgetcsv($fh);
if (!$header) {
    fclose($fh);
    echo json_encode(['success' => false, 'error' => 'Empty CSV']);
    exit;
}
$header = array_map(function ($c) { return strtolower(trim((string) $c)); }, $header);
$expected = ['date', 'amount', 'description', 'reference'];
if ($header !== $expected) {
    fclose($fh);
    echo json_encode(['success' => false, 'error' => 'CSV v1 header must be: date,amount,description,reference']);
    exit;
}

$rows = [];
$lineNum = 1;
while (($row = fgetcsv($fh)) !== false) {
    $lineNum++;
    if (count($row) < 4) {
        continue;
    }
    $rows[] = ['line' => $lineNum, 'date' => trim($row[0]), 'amount' => trim($row[1]), 'description' => trim($row[2]), 'reference' => trim($row[3])];
}
fclose($fh);

$inserted = 0;
$skipped_locked = 0;
$skipped_dup = 0;
$skipped_bad = 0;

try {
    $conn->beginTransaction();

    $uid = current_user_id();
    $stBatch = $conn->prepare("
        INSERT INTO cleaning_bank_import_batches (bank_account_id, file_name, imported_by, row_count, notes)
        VALUES (?, ?, ?, 0, NULL)
    ");
    $stBatch->execute([$bank_id, $name, $uid ?: null]);
    $batch_id = (int) $conn->lastInsertId();

    $ins = $conn->prepare("
        INSERT INTO cleaning_bank_statement_lines
          (bank_account_id, txn_date, amount, description, reference, import_batch_id, source_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($rows as $r) {
        $d = $r['date'];
        if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $skipped_bad++;
            continue;
        }
        if (cleaning_bank_is_period_locked($conn, $bank_id, $d)) {
            $skipped_locked++;
            continue;
        }
        if (!is_numeric($r['amount'])) {
            $skipped_bad++;
            continue;
        }
        $amt = round((float) $r['amount'], 2);
        $desc = mb_substr($r['description'], 0, 500);
        $ref = $r['reference'] !== '' ? mb_substr($r['reference'], 0, 120) : null;
        $hash = cleaning_source_hash($bank_id, $d, (string) $amt, $desc, (string) ($ref ?? ''));

        $chk = $conn->prepare('SELECT id FROM cleaning_bank_statement_lines WHERE bank_account_id = ? AND source_hash = ? LIMIT 1');
        $chk->execute([$bank_id, $hash]);
        if ($chk->fetch()) {
            $skipped_dup++;
            continue;
        }

        $ins->execute([$bank_id, $d, $amt, $desc, $ref, $batch_id, $hash]);
        $inserted++;
    }

    $up = $conn->prepare('UPDATE cleaning_bank_import_batches SET row_count = ? WHERE id = ?');
    $up->execute([$inserted, $batch_id]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'batch_id' => $batch_id,
        'inserted' => $inserted,
        'skipped_locked' => $skipped_locked,
        'skipped_dup' => $skipped_dup,
        'skipped_bad' => $skipped_bad,
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('bank_reco_import_csv: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
