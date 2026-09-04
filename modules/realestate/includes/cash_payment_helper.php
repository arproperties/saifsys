<?php
/**
 * Controlled Cash Payment Workflow — Helper
 * Request numbers, create cash request, activation check, audit log.
 * Use only when re_cash_payment_requests and related tables exist (migration re_cash_payment_workflow.sql).
 */

if (!defined('CASH_PAYMENT_EXPIRY_HOURS')) {
    define('CASH_PAYMENT_EXPIRY_HOURS', 48);
}

/**
 * Generate next request number for company: CPR-YYYY-NNNN
 */
function cash_payment_next_request_number(PDO $conn, int $company_id): string {
    $year = date('Y');
    $stmt = $conn->prepare("
        SELECT request_number FROM re_cash_payment_requests
        WHERE company_id = ? AND request_number LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$company_id, "CPR-{$year}-%"]);
    $last = $stmt->fetchColumn();
    $seq = 1;
    if ($last && preg_match('/CPR-\d{4}-(\d+)/', $last, $m)) {
        $seq = (int)$m[1] + 1;
    }
    return sprintf('CPR-%s-%04d', $year, $seq);
}

/**
 * Create a cash payment request (tenant chose "Cash at Office").
 * Returns ['id' => int, 'request_number' => string] or null on failure.
 */
function cash_payment_create_request(PDO $conn, int $company_id, string $related_type, int $related_id, float $amount_aed, ?int $expiry_hours = null): ?array {
    $expiry_hours = $expiry_hours ?? CASH_PAYMENT_EXPIRY_HOURS;
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expiry_hours} hours"));
    $request_number = cash_payment_next_request_number($conn, $company_id);
    $stmt = $conn->prepare("
        INSERT INTO re_cash_payment_requests (company_id, request_number, related_type, related_id, amount_aed, currency, status, expires_at)
        VALUES (?, ?, ?, ?, ?, 'AED', 'pending_cash_payment', ?)
    ");
    $stmt->execute([$company_id, $request_number, $related_type, $related_id, $amount_aed, $expires_at]);
    $id = (int) $conn->lastInsertId();
    if (!$id) return null;
    cash_payment_audit($conn, $id, 'create', null, null, null);
    return ['id' => $id, 'request_number' => $request_number];
}

/**
 * Link service request table to cash payment request (set cash_payment_request_id).
 */
function cash_payment_link_service_request(PDO $conn, string $related_type, int $related_id, int $cash_payment_request_id): bool {
    $tables = [
        'extra_service' => 'tenant_extra_service_requests',
        'cleaning' => 'tenant_cleaning_requests',
        'pest_control' => 'tenant_pest_control_requests',
    ];
    $table = $tables[$related_type] ?? null;
    if (!$table) return false;
    $stmt = $conn->prepare("UPDATE {$table} SET cash_payment_request_id = ? WHERE id = ?");
    $stmt->execute([$cash_payment_request_id, $related_id]);
    return $stmt->rowCount() > 0;
}

/**
 * Check if the related service can be activated (payment satisfied).
 * Use this as the single gate before activating any paid service (parking, storage, cleaning, pest control).
 * For extra_service: payment_status = 'paid' OR (cash_payment_request_id IS NOT NULL AND cash request status = 'paid_verified').
 * For cleaning/pest_control: no payment_status column; no cash link means OK; cash link requires cash request = 'paid_verified'.
 */
function cash_payment_can_activate_service(PDO $conn, string $related_type, int $related_id): bool {
    if ($related_type === 'extra_service') {
        $stmt = $conn->prepare("
            SELECT payment_status, cash_payment_request_id FROM tenant_extra_service_requests WHERE id = ?
        ");
        $stmt->execute([$related_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        if (($row['payment_status'] ?? '') === 'paid') return true;
        $cpr_id = (int)($row['cash_payment_request_id'] ?? 0);
        if ($cpr_id === 0) return false;
        $s = $conn->prepare("SELECT status FROM re_cash_payment_requests WHERE id = ?");
        $s->execute([$cpr_id]);
        return ($s->fetchColumn() ?: '') === 'paid_verified';
    }
    if ($related_type === 'cleaning' || $related_type === 'pest_control') {
        $table = $related_type === 'cleaning' ? 'tenant_cleaning_requests' : 'tenant_pest_control_requests';
        $stmt = $conn->prepare("SELECT cash_payment_request_id FROM {$table} WHERE id = ?");
        $stmt->execute([$related_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $cpr_id = (int)($row['cash_payment_request_id'] ?? 0);
        if ($cpr_id === 0) return true; // no cash payment required
        $s = $conn->prepare("SELECT status FROM re_cash_payment_requests WHERE id = ?");
        $s->execute([$cpr_id]);
        return ($s->fetchColumn() ?: '') === 'paid_verified';
    }
    return false;
}

/**
 * Log audit entry for cash payment request (no hard deletes).
 */
function cash_payment_audit(PDO $conn, int $cash_payment_request_id, string $action, ?string $field_name, ?string $old_value, ?string $new_value): void {
    $uid = current_user_id();
    try {
        $stmt = $conn->prepare("
            INSERT INTO re_cash_payment_audit_log (cash_payment_request_id, action, field_name, old_value, new_value, changed_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $cash_payment_request_id,
            $action,
            $field_name,
            $old_value,
            $new_value,
            $uid ?: null,
        ]);
    } catch (Throwable $e) {
        // table may not exist yet
    }
}

/**
 * Check if cash payment tables exist.
 */
function cash_payment_tables_exist(PDO $conn): bool {
    try {
        $conn->query("SELECT 1 FROM re_cash_payment_requests LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Post accounting entry when cash payment is verified: Debit Cash, Credit Revenue.
 * Uses account codes 1110 (Cash on Hand) and 4200 (Service Charge Income). No-op if already posted or accounts missing.
 *
 * @param PDO $conn Database connection
 * @param int $cashPaymentRequestId re_cash_payment_requests.id
 * @param int $companyId Company ID
 * @param int|null $userId User who verified (for journal created_by/posted_by)
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function cash_payment_post_accounting_entry(PDO $conn, int $cashPaymentRequestId, int $companyId, ?int $userId = null): array {
    $stmt = $conn->prepare("SELECT id, request_number, amount_aed, accounting_entry_id FROM re_cash_payment_requests WHERE id = ? AND company_id = ?");
    $stmt->execute([$cashPaymentRequestId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Cash payment request not found'];
    }
    if (!empty($row['accounting_entry_id'])) {
        return ['success' => true, 'journal_id' => (int)$row['accounting_entry_id'], 'error' => null];
    }
    $amount = (float)$row['amount_aed'];
    if ($amount <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Invalid amount'];
    }
    $enginePath = __DIR__ . '/../accounting/accounting_engine.php';
    if (!file_exists($enginePath)) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Accounting engine not found'];
    }
    require_once $enginePath;
    if (!function_exists('find_account_by_code') || !function_exists('create_and_post_journal') || !function_exists('journal_duplicate_exists')) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Accounting functions not available'];
    }
    $GLOBALS['conn'] = $conn;
    if (journal_duplicate_exists($companyId, 'cash_payment_request', $cashPaymentRequestId)) {
        return ['success' => true, 'journal_id' => null, 'error' => null];
    }
    $cashAccount = find_account_by_code('1110', $companyId);
    $revenueAccount = find_account_by_code('4200', $companyId);
    if (!$revenueAccount) {
        $revenueAccount = find_account_by_code('4110', $companyId);
    }
    if (!$cashAccount || !$revenueAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Cash (1110) or Revenue (4200/4110) account not found in Chart of Accounts'];
    }
    $requestNumber = $row['request_number'];
    $lines = [
        ['account_id' => $cashAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Cash receipt ' . $requestNumber],
        ['account_id' => $revenueAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Cash receipt ' . $requestNumber],
    ];
    $result = create_and_post_journal(
        $companyId,
        'payment',
        'cash_payment_request',
        $cashPaymentRequestId,
        $lines,
        'Cash payment verified: ' . $requestNumber,
        date('Y-m-d'),
        $userId
    );
    if (!$result['success']) {
        return ['success' => false, 'journal_id' => null, 'error' => $result['error'] ?? 'Post failed'];
    }
    $journalId = (int)$result['journal_id'];
    try {
        $conn->prepare("UPDATE re_cash_payment_requests SET accounting_entry_id = ? WHERE id = ? AND company_id = ?")
            ->execute([$journalId, $cashPaymentRequestId, $companyId]);
    } catch (Throwable $e) {
        error_log('cash_payment_post_accounting_entry: could not set accounting_entry_id: ' . $e->getMessage());
    }
    return ['success' => true, 'journal_id' => $journalId, 'error' => null];
}
