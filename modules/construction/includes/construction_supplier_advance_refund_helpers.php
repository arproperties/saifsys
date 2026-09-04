<?php
/**
 * Construction Supplier Advance Refunds (Phase 3).
 * Dr Bank / Cr Advances. Blocked while Advance VAT remains posted on the payment.
 */

if (!function_exists('co_supplier_advance_schema_ready')) {
    require_once __DIR__ . '/construction_supplier_advance_helpers.php';
}

function co_supplier_advance_refund_table_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = function_exists('co_db_table_exists') && co_db_table_exists($conn, 'co_supplier_advance_refunds');
    return $ready;
}

function co_supplier_payment_advance_refunded(PDO $conn, int $companyId, int $paymentId): float {
    if (!co_supplier_advance_refund_table_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM co_supplier_advance_refunds
        WHERE company_id = ? AND supplier_payment_id = ? AND status = 'posted'
    ");
    $st->execute([$companyId, $paymentId]);
    return co_supplier_money($st->fetchColumn());
}

function co_supplier_load_advance_refund(PDO $conn, int $companyId, int $refundId): ?array {
    if (!co_supplier_advance_refund_table_ready($conn)) {
        return null;
    }
    $st = $conn->prepare("SELECT * FROM co_supplier_advance_refunds WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$refundId, $companyId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * @return array{success:bool,error:?string,refund_id:?int,journal_id:?int}
 */
function co_supplier_post_advance_refund(
    PDO $conn,
    int $companyId,
    int $paymentId,
    float $amount,
    string $refundDate,
    ?int $payAccountId,
    string $reference = '',
    string $notes = '',
    ?int $userId = null
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'refund_id' => null, 'journal_id' => null];
    }
    if (!co_supplier_advance_refund_table_ready($conn)) {
        return ['success' => false, 'error' => 'Run migrations/construction_supplier_ap_phase3_advance_vat_refunds.sql', 'refund_id' => null, 'journal_id' => null];
    }
    if (!function_exists('create_and_post_journal')) {
        require_once __DIR__ . '/construction_accounting_integration.php';
    }
    $amount = co_supplier_money($amount);
    if ($amount <= 0.005) {
        return ['success' => false, 'error' => 'Refund amount must be greater than zero.', 'refund_id' => null, 'journal_id' => null];
    }
    if ($refundDate === '') {
        $refundDate = date('Y-m-d');
    }
    try {
        $st = $conn->prepare("
            SELECT sp.*, s.supplier_name
            FROM co_supplier_payments sp
            JOIN co_suppliers s ON s.id = sp.supplier_id AND s.company_id = sp.company_id
            WHERE sp.id = ? AND sp.company_id = ?
        ");
        $st->execute([$paymentId, $companyId]);
        $pay = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pay || empty($pay['journal_id'])) {
            throw new RuntimeException('Posted supplier advance payment not found.');
        }
        if (co_supplier_money($pay['advance_amount'] ?? 0) <= 0.005) {
            throw new RuntimeException('Selected payment has no advance portion to refund.');
        }
        $supplierId = (int)$pay['supplier_id'];
        if (function_exists('co_supplier_payment_advance_vat_posted')
            && co_supplier_payment_advance_vat_posted($conn, $companyId, $paymentId) > 0.005) {
            throw new RuntimeException('Reverse posted Advance VAT documents on this payment before refunding.');
        }
        $remaining = co_supplier_payment_advance_remaining($conn, $companyId, $paymentId);
        if ($amount > $remaining + 0.005) {
            throw new RuntimeException('Refund exceeds remaining advance on this payment (' . number_format($remaining, 2) . ' AED).');
        }
        $bal = co_supplier_advance_balance($conn, $companyId, $supplierId);
        if ($amount > $bal + 0.005) {
            throw new RuntimeException('Refund exceeds available supplier advance balance (' . number_format($bal, 2) . ' AED).');
        }

        $bank = null;
        $acctId = $payAccountId ?: (int)($pay['pay_account_id'] ?? 0);
        if ($acctId > 0) {
            $b = $conn->prepare("
                SELECT * FROM re_chart_of_accounts
                WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0 LIMIT 1
            ");
            $b->execute([$acctId, $companyId]);
            $bank = $b->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$bank) {
            $bank = find_account_by_code(CO_ACCOUNT_BANK, $companyId) ?: find_account_by_code(CO_ACCOUNT_CASH, $companyId);
        }
        if (!$bank) {
            throw new RuntimeException('Bank/Cash account not found for refund.');
        }
        $adv = co_supplier_advance_account($conn, $companyId);
        if (!$adv) {
            throw new RuntimeException('Supplier Advances account not found.');
        }

        $conn->prepare("
            INSERT INTO co_supplier_advance_refunds
                (company_id, supplier_id, supplier_payment_id, refund_date, amount, pay_account_id,
                 reference, notes, status, posted_by, posted_at, created_by)
            VALUES (?,?,?,?,?,?,?,?,'posted',?,NOW(),?)
        ")->execute([
            $companyId, $supplierId, $paymentId, $refundDate, $amount,
            $acctId > 0 ? $acctId : null,
            $reference !== '' ? $reference : null,
            $notes !== '' ? $notes : null,
            $userId, $userId,
        ]);
        $refundId = (int)$conn->lastInsertId();
        if ($refundId <= 0) {
            throw new RuntimeException('Failed to create advance refund row.');
        }

        $desc = 'Supplier advance refund PAY-' . $paymentId . ' / ' . ($pay['supplier_name'] ?? '');
        $ref = $reference !== '' ? $reference : ('CO-ADV-REF-' . $refundId);
        $lines = [
            ['account_id' => (int)$bank['id'], 'debit' => $amount, 'credit' => 0, 'description' => $desc, 'reference' => $ref],
            ['account_id' => (int)$adv['id'], 'debit' => 0, 'credit' => $amount, 'description' => $desc, 'reference' => $ref],
        ];
        $res = create_and_post_journal(
            $companyId,
            'payment',
            'co_supplier_advance_refund',
            $refundId,
            $lines,
            $desc,
            $refundDate,
            $userId
        );
        if (empty($res['success'])) {
            throw new RuntimeException($res['error'] ?? 'Advance refund journal failed');
        }
        $journalId = (int)$res['journal_id'];
        $conn->prepare("UPDATE co_supplier_advance_refunds SET journal_id = ? WHERE id = ? AND company_id = ?")
            ->execute([$journalId, $refundId, $companyId]);
        co_supplier_adjust_advance_balance($conn, $companyId, $supplierId, -$amount);
        if (function_exists('co_supplier_ap_audit')) {
            co_supplier_ap_audit(
                $conn, $companyId, $supplierId, null, $paymentId,
                'advance_refunded', null, $ref, $amount,
                'Supplier advance refunded to bank', $userId, 'supplier_advance_refund', $journalId
            );
        }
        return ['success' => true, 'error' => null, 'refund_id' => $refundId, 'journal_id' => $journalId];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'refund_id' => null, 'journal_id' => null];
    }
}

/**
 * @return array{success:bool,error:?string,reversal_journal_id:?int}
 */
function co_supplier_reverse_advance_refund(
    PDO $conn,
    int $companyId,
    int $refundId,
    string $reason = '',
    ?int $userId = null
): array {
    if ($companyId <= 0 || !co_supplier_advance_refund_table_ready($conn)) {
        return ['success' => false, 'error' => 'Advance refunds not available.', 'reversal_journal_id' => null];
    }
    if (!function_exists('reverse_journal')) {
        require_once __DIR__ . '/construction_accounting_integration.php';
    }
    try {
        $rf = co_supplier_load_advance_refund($conn, $companyId, $refundId);
        if (!$rf) {
            throw new RuntimeException('Refund not found.');
        }
        if (($rf['status'] ?? '') === 'reversed') {
            return ['success' => true, 'error' => null, 'reversal_journal_id' => null, 'already_reversed' => true];
        }
        $journalId = (int)($rf['journal_id'] ?? 0);
        $reversalId = null;
        if ($journalId > 0) {
            $rev = reverse_journal($journalId, $reason !== '' ? $reason : 'Advance refund reversed', $userId);
            if (empty($rev['success'])) {
                throw new RuntimeException($rev['error'] ?? 'Refund journal reversal failed');
            }
            $reversalId = isset($rev['reversal_journal_id']) ? (int)$rev['reversal_journal_id'] : null;
        }
        $amount = co_supplier_money($rf['amount']);
        co_supplier_adjust_advance_balance($conn, $companyId, (int)$rf['supplier_id'], $amount);
        $conn->prepare("
            UPDATE co_supplier_advance_refunds
            SET status = 'reversed', reversed_by = ?, reversed_at = NOW(), reversal_journal_id = ?, updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$userId, $reversalId, $refundId, $companyId]);
        if (function_exists('co_supplier_ap_audit')) {
            co_supplier_ap_audit(
                $conn, $companyId, (int)$rf['supplier_id'], null, (int)$rf['supplier_payment_id'],
                'advance_refund_reversed', (string)$journalId, (string)($reversalId ?? ''), $amount,
                $reason !== '' ? $reason : 'Advance refund reversed', $userId, 'supplier_advance_refund', $reversalId
            );
        }
        return ['success' => true, 'error' => null, 'reversal_journal_id' => $reversalId];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'reversal_journal_id' => null];
    }
}
