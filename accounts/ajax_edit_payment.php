<?php
// accounts/ajax_edit_payment.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/cleaning_payment_accounts.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_once __DIR__.'/../includes/accounting_health_service.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/module_access.php';

if (!has_permission('payments.edit', MODULE_FINANCE, $conn)) {
    require_role(['Owner','Admin','Account'], $conn);
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF verification with JSON response
$csrfOk = isset($_POST['_csrf']) && hash_equals($_SESSION['_csrf'] ?? '', (string)($_POST['_csrf'] ?? ''));
if (!$csrfOk) {
    http_response_code(419);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid or missing.']);
    exit;
}

$receipt_id = (int)($_POST['receipt_id'] ?? 0);
$new_amount = (float)($_POST['amount'] ?? 0);
$new_method = trim($_POST['payment_method'] ?? '');
$new_date = trim($_POST['payment_date'] ?? '');
$new_notes = trim($_POST['notes'] ?? '');
$new_deposit = trim($_POST['deposit_account_no'] ?? '');

if ($receipt_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid receipt ID']);
    exit;
}

if ($new_amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']);
    exit;
}

try {
    $conn->beginTransaction();

    $receiptStmt = $conn->prepare("SELECT * FROM receipts WHERE id = ? FOR UPDATE");
    $receiptStmt->execute([$receipt_id]);
    $receiptData = $receiptStmt->fetch(PDO::FETCH_ASSOC);

    if (!$receiptData) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => 'Receipt not found']);
        exit;
    }

    if ($new_deposit === '') {
        $new_deposit = trim((string)($receiptData['deposit_account_no'] ?? ''));
    }
    if ($new_deposit === '') {
        $new_deposit = strtolower((string)($receiptData['method'] ?? '')) === 'cash' ? '1010' : '1020';
    }

    try {
        cleaning_validate_payment_account_no($conn, $new_deposit);
    } catch (Throwable $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $old_amount = (float)$receiptData['amount'];
    $old_method = $receiptData['method'] ?? 'cash';
    $old_deposit = trim((string)($receiptData['deposit_account_no'] ?? ''));

    $allocStmt = $conn->prepare("SELECT COALESCE(SUM(amount_applied),0) FROM receipt_allocations WHERE receipt_id = ?");
    $allocStmt->execute([$receipt_id]);
    $allocated = (float)$allocStmt->fetchColumn();

    if ($new_amount < $allocated) {
        $conn->rollBack();
        echo json_encode([
            'success' => false,
            'error' => "Cannot reduce amount below allocated amount (AED " . number_format($allocated, 2) . ")"
        ]);
        exit;
    }

    $updateFields = [];
    $updateParams = [];

    if ($new_amount != $old_amount) {
        $updateFields[] = "amount = ?";
        $updateParams[] = $new_amount;
    }

    if (!empty($new_method) && $new_method !== $old_method) {
        $updateFields[] = "method = ?";
        $updateParams[] = $new_method;
    }

    if (!empty($new_date)) {
        $updateFields[] = "receipt_date = ?";
        $updateParams[] = $new_date;
    }

    if ($new_notes !== null) {
        $updateFields[] = "notes = ?";
        $updateParams[] = $new_notes;
    }

    if ($new_deposit !== $old_deposit) {
        $updateFields[] = "deposit_account_no = ?";
        $updateParams[] = $new_deposit;
    }

    if (!empty($updateFields)) {
        $updateParams[] = $receipt_id;
        $updateSql = "UPDATE receipts SET " . implode(", ", $updateFields) . ", updated_at = NOW() WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute($updateParams);
    }

    $needs_gl_repost = ($new_amount != $old_amount
        || (!empty($new_method) && $new_method !== $old_method)
        || ($new_deposit !== $old_deposit)
        || (!empty($new_date) && $new_date !== ($receiptData['receipt_date'] ?? '')));

    if ($needs_gl_repost) {
        ar_post_or_repost_receipt($conn, $receipt_id);
    }

    $allocStmt = $conn->prepare("SELECT DISTINCT invoice_id FROM receipt_allocations WHERE receipt_id = ? AND invoice_id IS NOT NULL");
    $allocStmt->execute([$receipt_id]);
    $affected_invoices = $allocStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($affected_invoices as $invoice_id) {
        ar_refresh_status_from_allocations($conn, (int)$invoice_id);
        ar_post_or_repost_invoice($conn, (int)$invoice_id);
        accounting_health_assert_invoice($conn, (int)$invoice_id);
    }

    accounting_health_assert_receipt($conn, $receipt_id);

    require_once __DIR__.'/../includes/AuditService.php';
    AuditService::logUpdate('receipts', $receipt_id, $receiptData, array_merge($receiptData, [
        'amount' => $new_amount,
        'method' => !empty($new_method) ? $new_method : $old_method,
        'receipt_date' => !empty($new_date) ? $new_date : $receiptData['receipt_date'],
        'notes' => $new_notes,
        'deposit_account_no' => $new_deposit,
    ]), "Updated payment receipt {$receiptData['receipt_no']}");

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Payment updated successfully'
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Edit payment error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to update payment: ' . $e->getMessage()]);
}
