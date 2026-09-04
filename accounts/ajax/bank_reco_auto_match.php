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
$from = trim($_POST['date_from'] ?? '');
$to = trim($_POST['date_to'] ?? '');

if ($bank_id <= 0 || $from === '' || $to === '') {
    echo json_encode(['success' => false, 'error' => 'bank_account_id, date_from, date_to required']);
    exit;
}

$acct = cleaning_bank_account_no($conn, $bank_id);
if (!$acct) {
    echo json_encode(['success' => false, 'error' => 'Invalid bank account']);
    exit;
}

$proposed = 0;
$skipped = [];

try {
    $st = $conn->prepare("
        SELECT l.* FROM cleaning_bank_statement_lines l
        WHERE l.bank_account_id = ? AND l.txn_date BETWEEN ? AND ?
        ORDER BY l.txn_date ASC, l.id ASC
    ");
    $st->execute([$bank_id, $from, $to]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($lines as $line) {
        $line_id = (int) $line['id'];
        if (cleaning_bank_is_period_locked($conn, $bank_id, $line['txn_date'])) {
            $skipped[] = ['line_id' => $line_id, 'reason' => 'period_locked'];
            continue;
        }
        $rem = cleaning_bank_line_remaining($conn, $line);
        if ($rem <= 0.009) {
            continue;
        }

        $bamt = (float) $line['amount'];
        $d = $line['txn_date'];

        if ($bamt > 0) {
            $st2 = $conn->prepare("
                SELECT r.id, r.amount FROM receipts r
                WHERE r.deposit_account_no = ? AND r.receipt_date = ?
                ORDER BY r.id ASC
            ");
            $st2->execute([$acct, $d]);
            $hit = null;
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rid = (int) $r['id'];
                $sys_rem = round((float) $r['amount'] - cleaning_receipt_matched_sum($conn, $rid), 2);
                if (abs($sys_rem - $rem) < 0.02) {
                    $hit = $rid;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $uid = current_user_id();
            $ins = $conn->prepare("
                INSERT INTO cleaning_reconciliation_matches
                  (bank_statement_line_id, system_type, system_id, amount_matched, status, period_lock_key, created_by)
                VALUES (?, 'receipt', ?, ?, 'proposed', NULL, ?)
            ");
            $ins->execute([$line_id, $hit, $rem, $uid ?: null]);
            $proposed++;
        } else {
            $st2 = $conn->prepare("
                SELECT e.id, e.total FROM expenses e
                WHERE e.paid_via IN ('cash','bank') AND e.pay_account_no = ? AND e.expense_date = ?
                ORDER BY e.id ASC
            ");
            $st2->execute([$acct, $d]);
            $hit = null;
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $eid = (int) $r['id'];
                $sys_rem = round((float) $r['total'] - cleaning_expense_matched_sum($conn, $eid), 2);
                if (abs($sys_rem - $rem) < 0.02) {
                    $hit = $eid;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $uid = current_user_id();
            $ins = $conn->prepare("
                INSERT INTO cleaning_reconciliation_matches
                  (bank_statement_line_id, system_type, system_id, amount_matched, status, period_lock_key, created_by)
                VALUES (?, 'expense', ?, ?, 'proposed', NULL, ?)
            ");
            $ins->execute([$line_id, $hit, $rem, $uid ?: null]);
            $proposed++;
        }
    }

    echo json_encode([
        'success' => true,
        'proposed_count' => $proposed,
        'skipped' => $skipped,
    ]);
} catch (Throwable $e) {
    error_log('bank_reco_auto_match: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
