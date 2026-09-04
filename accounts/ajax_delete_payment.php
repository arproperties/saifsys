<?php
// accounts/ajax_delete_payment.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_once __DIR__.'/../includes/accounting_health_service.php';
require_role(['Owner'], $conn);

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
if ($receipt_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid receipt ID']);
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

    $allocStmt = $conn->prepare("SELECT * FROM receipt_allocations WHERE receipt_id = ?");
    $allocStmt->execute([$receipt_id]);
    $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

    $receiptJournalIds = accounting_health_receipt_active_journal_ids($conn, $receipt_id);
    foreach ($receiptJournalIds as $journal_id) {
        $revCheck = $conn->prepare("SELECT id FROM gl_journals WHERE source='reversal' AND source_id=? AND is_posted=1");
        $revCheck->execute([$journal_id]);
        if (!$revCheck->fetch()) {
            gl_reverse_journal($conn, $journal_id);
        } else {
            $conn->prepare("UPDATE gl_journals SET is_reversed=1 WHERE id=? AND is_reversed=0")->execute([$journal_id]);
        }
    }

    $deleteAlloc = $conn->prepare("DELETE FROM receipt_allocations WHERE receipt_id = ?");
    $deleteAlloc->execute([$receipt_id]);

    foreach ($allocations as $alloc) {
        if (!empty($alloc['invoice_id'])) {
            ar_refresh_status_from_allocations($conn, (int)$alloc['invoice_id']);
            ar_post_or_repost_invoice($conn, (int)$alloc['invoice_id']);
            accounting_health_assert_invoice($conn, (int)$alloc['invoice_id']);
        }
    }

    $deleteReceipt = $conn->prepare("DELETE FROM receipts WHERE id = ?");
    $deleteReceipt->execute([$receipt_id]);

    require_once __DIR__.'/../includes/AuditService.php';
    AuditService::logDelete('receipts', $receipt_id, $receiptData,
        "Deleted payment receipt {$receiptData['receipt_no']} (Amount: " . number_format((float)$receiptData['amount'], 2) . " AED)");

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Payment deleted successfully. GL reversed where applicable; invoice balances updated.',
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Delete payment error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
