<?php
/**
 * AJAX endpoint for bank reconciliation
 * Marks GL transactions as reconciled
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

header('Content-Type: application/json');

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$csrfToken = $data['_csrf'] ?? '';

// Verify CSRF token
if (empty($csrfToken) || !hash_equals($_SESSION['_csrf'] ?? '', $csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$transactionIds = $data['transaction_ids'] ?? [];
$bankAccountId = !empty($data['bank_account_id']) ? (int)$data['bank_account_id'] : null;

if (empty($transactionIds) || !is_array($transactionIds)) {
    echo json_encode(['success' => false, 'error' => 'No transactions selected']);
    exit;
}

if (!$bankAccountId) {
    echo json_encode(['success' => false, 'error' => 'Bank account is required']);
    exit;
}

// Verify bank account belongs to company
$stmt = $conn->prepare("
    SELECT gl_account_id FROM re_bank_accounts
    WHERE id = ? AND company_id = ?
");
$stmt->execute([$bankAccountId, $currentCompanyId]);
$bankAccount = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bankAccount) {
    echo json_encode(['success' => false, 'error' => 'Bank account not found']);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Verify all transactions belong to the bank account and company
    $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
    $stmt = $conn->prepare("
        SELECT id FROM re_general_ledger
        WHERE id IN ($placeholders)
        AND account_id = ? AND company_id = ?
        AND is_reconciled = 0
    ");
    $params = array_merge($transactionIds, [$bankAccount['gl_account_id'], $currentCompanyId]);
    $stmt->execute($params);
    $validTransactions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($validTransactions) !== count($transactionIds)) {
        throw new Exception('Some transactions are invalid or already reconciled');
    }
    
    // Mark transactions as reconciled
    $stmt = $conn->prepare("
        UPDATE re_general_ledger
        SET is_reconciled = 1,
            reconciled_at = NOW(),
            reconciled_by = ?
        WHERE id IN ($placeholders)
        AND account_id = ? AND company_id = ?
    ");
    $params = array_merge([$userId], $transactionIds, [$bankAccount['gl_account_id'], $currentCompanyId]);
    $stmt->execute($params);
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => count($transactionIds) . ' transaction(s) reconciled successfully',
        'reconciled_count' => count($transactionIds)
    ]);
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
