<?php
/**
 * Vendor Advance Refunds (M7) — Dr Bank/Cash / Cr 1410.
 * Separate document from payments and bills. Does not touch AP, expense, or VAT.
 */
declare(strict_types=1);

require_once __DIR__ . '/vendor_advance_helper.php';

function re_ap_advance_refund_table_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->query('SELECT 1 FROM re_vendor_advance_refunds LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function re_ap_payment_advance_refunded(PDO $conn, int $companyId, int $paymentId): float
{
    if (!re_ap_advance_refund_table_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM re_vendor_advance_refunds
        WHERE company_id = ? AND vendor_payment_id = ? AND status = 'posted'
    ");
    $st->execute([$companyId, $paymentId]);
    return re_ap_money($st->fetchColumn());
}

function re_ap_load_advance_refund(PDO $conn, int $companyId, int $refundId): ?array
{
    if (!re_ap_advance_refund_table_ready($conn)) {
        return null;
    }
    $st = $conn->prepare("SELECT * FROM re_vendor_advance_refunds WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$refundId, $companyId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Post a vendor advance refund: Dr Bank / Cr 1410.
 * Blocked while posted Advance VAT documents remain on the source payment.
 */
function re_ap_post_vendor_advance_refund(
    PDO $conn,
    int $companyId,
    int $paymentId,
    float $amount,
    string $refundDate,
    ?int $bankAccountId,
    string $reference = '',
    string $notes = '',
    ?int $userId = null
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'refund_id' => null, 'journal_id' => null];
    }
    if (!re_ap_advance_refund_table_ready($conn)) {
        return ['success' => false, 'error' => 'Advance refund schema not installed.', 'refund_id' => null, 'journal_id' => null];
    }
    $amount = re_ap_money($amount);
    if ($amount <= 0.005) {
        return ['success' => false, 'error' => 'Refund amount must be greater than zero.', 'refund_id' => null, 'journal_id' => null];
    }
    if ($refundDate === '') {
        $refundDate = date('Y-m-d');
    }

    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }

        $st = $conn->prepare("
            SELECT vp.*, v.vendor_name
            FROM re_vendor_payments vp
            JOIN re_vendors v ON v.id = vp.vendor_id AND v.company_id = vp.company_id
            WHERE vp.id = ? AND vp.company_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute([$paymentId, $companyId]);
        $pay = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pay || ($pay['status'] ?? '') !== 'posted') {
            throw new RuntimeException('Posted vendor advance payment not found.');
        }
        if (re_ap_money($pay['advance_amount'] ?? 0) <= 0.005) {
            throw new RuntimeException('Selected payment has no advance portion to refund.');
        }

        $vendorId = (int)$pay['vendor_id'];
        re_ap_lock_vendor_advance_balance($conn, $companyId, $vendorId);

        if (function_exists('re_ap_payment_advance_vat_posted')
            && re_ap_payment_advance_vat_posted($conn, $companyId, $paymentId) > 0.005) {
            throw new RuntimeException(
                'Reverse posted Advance VAT documents on this payment before refunding the advance.'
            );
        }

        $remaining = re_ap_payment_advance_remaining($conn, $companyId, $paymentId);
        if ($amount > $remaining + 0.005) {
            throw new RuntimeException(
                'Refund exceeds remaining advance on this payment (' . number_format($remaining, 2) . ' AED).'
            );
        }

        $vendorBal = re_ap_vendor_advance_balance($conn, $companyId, $vendorId);
        if ($amount > $vendorBal + 0.005) {
            throw new RuntimeException(
                'Refund exceeds available vendor advance balance (' . number_format($vendorBal, 2) . ' AED).'
            );
        }

        $bank = null;
        $bankId = $bankAccountId ?: (int)($pay['bank_account_id'] ?? 0);
        if ($bankId > 0) {
            $b = $conn->prepare("
                SELECT coa.* FROM re_bank_accounts ba
                JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
                WHERE ba.id = ? AND ba.company_id = ?
            ");
            $b->execute([$bankId, $companyId]);
            $bank = $b->fetch(PDO::FETCH_ASSOC);
        }
        if (!$bank) {
            $bank = find_account_by_code('1210', $companyId) ?: find_account_by_code('1110', $companyId);
        }
        if (!$bank) {
            throw new RuntimeException('Bank/Cash account not found for refund.');
        }

        $adv = re_ap_advance_account($conn, $companyId);
        if (!$adv) {
            throw new RuntimeException('Vendor Advances account (1410) not found.');
        }

        $conn->prepare("
            INSERT INTO re_vendor_advance_refunds
            (company_id, vendor_id, vendor_payment_id, refund_date, amount, bank_account_id,
             reference_number, notes, status, posted_by, posted_at, created_by)
            VALUES (?,?,?,?,?,?,?,?,'posted',?,NOW(),?)
        ")->execute([
            $companyId,
            $vendorId,
            $paymentId,
            $refundDate,
            $amount,
            $bankId > 0 ? $bankId : null,
            $reference !== '' ? $reference : null,
            $notes !== '' ? $notes : null,
            $userId,
            $userId,
        ]);
        $refundId = (int)$conn->lastInsertId();
        if ($refundId <= 0) {
            throw new RuntimeException('Failed to create advance refund row.');
        }

        $desc = 'Vendor advance refund PAY-' . $paymentId . ' / ' . ($pay['vendor_name'] ?? '');
        $ref = $reference !== '' ? $reference : ('REF-ADV-' . $refundId);
        $lines = [
            ['account_id' => (int)$bank['id'], 'debit' => $amount, 'credit' => 0, 'description' => $desc, 'reference' => $ref],
            ['account_id' => (int)$adv['id'], 'debit' => 0, 'credit' => $amount, 'description' => $desc, 'reference' => $ref],
        ];
        $res = create_and_post_journal(
            $companyId,
            'payment',
            'vendor_advance_refund',
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

        $advLedger = get_or_create_vendor_ledger($vendorId, (int)$adv['id'], $companyId);
        re_ap_post_to_vendor_ledger_required(
            $conn,
            (int)$advLedger['id'],
            $refundDate,
            0,
            $amount,
            $desc,
            $ref,
            $companyId,
            $journalId,
            (int)$adv['id']
        );

        re_ap_adjust_vendor_advance_balance($conn, $companyId, $vendorId, -$amount);

        $conn->prepare("
            UPDATE re_vendor_advance_refunds
            SET journal_id = ?, posted_at = NOW(), updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$journalId, $refundId, $companyId]);

        re_ap_audit(
            $conn,
            $companyId,
            $vendorId,
            null,
            $paymentId,
            'advance_refund_posted',
            null,
            (string)$refundId,
            $amount,
            'Vendor advance refund posted',
            $userId,
            'vendor_advance_refund',
            $journalId
        );

        if ($ownTx) {
            $conn->commit();
        }
        return ['success' => true, 'error' => null, 'refund_id' => $refundId, 'journal_id' => $journalId];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage(), 'refund_id' => null, 'journal_id' => null];
    }
}

function re_ap_reverse_vendor_advance_refund(
    PDO $conn,
    int $companyId,
    int $refundId,
    string $reason = '',
    ?int $userId = null
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'reversal_journal_id' => null];
    }
    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }
        $st = $conn->prepare("SELECT * FROM re_vendor_advance_refunds WHERE id = ? AND company_id = ? FOR UPDATE");
        $st->execute([$refundId, $companyId]);
        $refund = $st->fetch(PDO::FETCH_ASSOC);
        if (!$refund) {
            throw new RuntimeException('Advance refund not found.');
        }
        if (($refund['status'] ?? '') === 'reversed') {
            if ($ownTx) {
                $conn->commit();
            }
            return [
                'success' => true,
                'error' => null,
                'reversal_journal_id' => (int)($refund['reversal_journal_id'] ?? 0) ?: null,
                'already_reversed' => true,
            ];
        }
        if (($refund['status'] ?? '') !== 'posted') {
            throw new RuntimeException('Only posted refunds can be reversed.');
        }

        $vendorId = (int)$refund['vendor_id'];
        $amount = re_ap_money($refund['amount']);
        $journalId = (int)($refund['journal_id'] ?? 0);
        if ($journalId <= 0) {
            throw new RuntimeException('Refund has no journal to reverse.');
        }

        re_ap_lock_vendor_advance_balance($conn, $companyId, $vendorId);

        $rev = reverse_journal($journalId, $reason ?: 'Vendor advance refund reversed', $userId);
        if (empty($rev['success'])) {
            throw new RuntimeException($rev['error'] ?? 'Refund journal reversal failed');
        }
        $reversalJournalId = (int)$rev['reversal_journal_id'];

        $adv = re_ap_advance_account($conn, $companyId);
        if (!$adv) {
            throw new RuntimeException('Vendor Advances account not found.');
        }
        $advLedger = get_or_create_vendor_ledger($vendorId, (int)$adv['id'], $companyId);
        re_ap_post_to_vendor_ledger_required(
            $conn,
            (int)$advLedger['id'],
            date('Y-m-d'),
            $amount,
            0,
            'Reverse vendor advance refund #' . $refundId,
            $refund['reference_number'] ?? null,
            $companyId,
            $reversalJournalId,
            (int)$adv['id']
        );

        re_ap_adjust_vendor_advance_balance($conn, $companyId, $vendorId, $amount);

        $conn->prepare("
            UPDATE re_vendor_advance_refunds
            SET status = 'reversed', reversed_by = ?, reversed_at = NOW(),
                reversal_journal_id = ?, updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$userId, $reversalJournalId, $refundId, $companyId]);

        re_ap_audit(
            $conn,
            $companyId,
            $vendorId,
            null,
            (int)$refund['vendor_payment_id'],
            'advance_refund_reversed',
            (string)$journalId,
            (string)$reversalJournalId,
            $amount,
            $reason ?: 'Vendor advance refund reversed',
            $userId,
            'vendor_advance_refund',
            $reversalJournalId
        );

        if ($ownTx) {
            $conn->commit();
        }
        return ['success' => true, 'error' => null, 'reversal_journal_id' => $reversalJournalId];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage(), 'reversal_journal_id' => null];
    }
}
