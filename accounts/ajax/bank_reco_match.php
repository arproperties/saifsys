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

$line_id = (int) ($_POST['bank_statement_line_id'] ?? 0);
$system_type = trim((string) ($_POST['system_type'] ?? ''));
$system_id = (int) ($_POST['system_id'] ?? 0);
$amount_in = isset($_POST['amount_matched']) ? trim((string) $_POST['amount_matched']) : '';

if ($line_id <= 0 || !in_array($system_type, ['receipt', 'expense'], true) || $system_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    $st = $conn->prepare('SELECT l.*, b.id AS bank_account_pk FROM cleaning_bank_statement_lines l JOIN cleaning_bank_accounts b ON b.id = l.bank_account_id WHERE l.id = ?');
    $st->execute([$line_id]);
    $line = $st->fetch(PDO::FETCH_ASSOC);
    if (!$line) {
        echo json_encode(['success' => false, 'error' => 'Statement line not found']);
        exit;
    }

    $bank_pk = (int) $line['bank_account_pk'];
    $acct = cleaning_bank_account_no($conn, $bank_pk);
    if (!$acct) {
        echo json_encode(['success' => false, 'error' => 'Bank account misconfigured']);
        exit;
    }

    if (cleaning_bank_is_period_locked($conn, $bank_pk, $line['txn_date'])) {
        echo json_encode(['success' => false, 'error' => 'This period is locked; matching is blocked.']);
        exit;
    }

    $bamt = (float) $line['amount'];
    $line_rem = cleaning_bank_line_remaining($conn, $line);
    if ($line_rem <= 0.009) {
        echo json_encode(['success' => false, 'error' => 'Statement line is already fully matched']);
        exit;
    }

    if ($bamt > 0 && $system_type !== 'receipt') {
        echo json_encode(['success' => false, 'error' => 'Positive bank lines must match receipts']);
        exit;
    }
    if ($bamt < 0 && $system_type !== 'expense') {
        echo json_encode(['success' => false, 'error' => 'Negative bank lines must match expenses']);
        exit;
    }

    $sys_rem = 0.0;
    if ($system_type === 'receipt') {
        $st = $conn->prepare('SELECT id, amount, deposit_account_no FROM receipts WHERE id = ? LIMIT 1');
        $st->execute([$system_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || trim((string) $r['deposit_account_no']) !== $acct) {
            echo json_encode(['success' => false, 'error' => 'Receipt not found or wrong deposit account']);
            exit;
        }
        $sys_rem = round((float) $r['amount'] - cleaning_receipt_matched_sum($conn, $system_id), 2);
    } else {
        $st = $conn->prepare("SELECT id, total, pay_account_no, paid_via FROM expenses WHERE id = ? LIMIT 1");
        $st->execute([$system_id]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e || !in_array($e['paid_via'] ?? '', ['cash', 'bank'], true)) {
            echo json_encode(['success' => false, 'error' => 'Expense not found or not paid via cash/bank']);
            exit;
        }
        if (trim((string) ($e['pay_account_no'] ?? '')) !== $acct) {
            echo json_encode(['success' => false, 'error' => 'Expense pay account does not match this bank account']);
            exit;
        }
        $sys_rem = round((float) $e['total'] - cleaning_expense_matched_sum($conn, $system_id), 2);
    }

    if ($amount_in !== '') {
        $amt = round((float) $amount_in, 2);
    } else {
        $amt = round(min($line_rem, $sys_rem), 2);
    }

    if ($amt <= 0) {
        echo json_encode(['success' => false, 'error' => 'Nothing to match']);
        exit;
    }
    if ($amt > $line_rem + 0.01 || $amt > $sys_rem + 0.01) {
        echo json_encode(['success' => false, 'error' => 'Amount exceeds remaining on statement line or system record']);
        exit;
    }

    $uid = current_user_id();
    $st = $conn->prepare("
        INSERT INTO cleaning_reconciliation_matches
          (bank_statement_line_id, system_type, system_id, amount_matched, status, period_lock_key, created_by)
        VALUES (?, ?, ?, ?, 'proposed', NULL, ?)
    ");
    $st->execute([$line_id, $system_type, $system_id, $amt, $uid ?: null]);

    $new_id = (int) $conn->lastInsertId();
    $dup = cleaning_match_duplicate_warnings($conn, $system_type, $system_id, $new_id);

    echo json_encode([
        'success' => true,
        'match_id' => $new_id,
        'duplicate_warnings' => $dup,
    ]);
} catch (Throwable $e) {
    error_log('bank_reco_match: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
