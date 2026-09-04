<?php
/**
 * Record cash settlement against a loan/advance or fine (shared HR, all companies).
 * Stays under hr/ — redirects back to employee profile or loans list.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_loans.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required');
}
csrf_verify();

$type = $_POST['type'] ?? 'loan'; // loan | deduction
$id = (int)($_POST['id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$settleDate = $_POST['settle_date'] ?? date('Y-m-d');
$notes = trim($_POST['notes'] ?? '');
$employeeId = (int)($_POST['employee_id'] ?? 0);
$return = trim($_POST['return'] ?? '');

$uid = $_SESSION['user']['id'] ?? null;

$msg = '';
$err = '';
try {
    $conn->beginTransaction();
    if ($type === 'deduction') {
        hr_deduction_record_settlement($conn, $id, $amount, 'cash', $settleDate, $uid ? (int)$uid : null, null, null, $notes !== '' ? $notes : 'Cash settlement');
        $msg = 'Fine/deduction cash settlement recorded.';
    } else {
        hr_loan_record_settlement($conn, $id, $amount, 'cash', $settleDate, $uid ? (int)$uid : null, null, null, $notes !== '' ? $notes : 'Cash settlement');
        $msg = 'Cash repayment recorded. Outstanding balance reduced.';
    }
    $conn->commit();
    $_SESSION['cash_advance_msg'] = $msg;
    $_SESSION['deduction_msg'] = $msg;
    $_SESSION['ok'] = $msg;
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $err = $e->getMessage();
    $_SESSION['cash_advance_err'] = $err;
    $_SESSION['deduction_msg'] = $err;
    $_SESSION['err'] = $err;
}

// Allow return to cash_advances list (with optional query) or employee profile.
if ($return !== '') {
    $safeReturn = $return;
    if (str_starts_with($return, 'employee_view') || str_starts_with($return, 'cash_advances')) {
        header('Location: ' . $safeReturn);
        exit;
    }
}
if ($employeeId > 0 && $err === '') {
    $hash = ($type === 'deduction') ? '#tab-deduct' : '#tab-cashadv';
    header('Location: employee_view.php?id=' . $employeeId . $hash);
    exit;
}
header('Location: cash_advances.php');
exit;
