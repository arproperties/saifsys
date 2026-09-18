<?php
/**
 * Edit / delete a manually recorded ARS stay payment.
 *
 * Posted journals are never rewritten: the payment's journal is reversed and its
 * invoice allocations are taken back ("unpost"), then an edit re-posts a fresh
 * journal + allocations through the normal payment path.
 */

require_once __DIR__ . '/ars_accounting.php';
require_once __DIR__ . '/ars_financial_adapter.php';

/** Returns an error message when the payment cannot be edited or deleted, else null. */
function ars_payment_edit_blocker(PDO $conn, array $payment): ?string {
    if (($payment['payment_gateway'] ?? '') === 'stripe') {
        return 'Stripe payments cannot be changed here. Refund them instead.';
    }
    $st = $conn->prepare("SELECT COUNT(*) FROM ars_refunds WHERE payment_id = ?");
    $st->execute([(int)$payment['id']]);
    if ((int)$st->fetchColumn() > 0) {
        return 'This payment has a linked refund, so it cannot be changed.';
    }
    // Overpayment credit that was never used can be undone with the payment; anything else cannot.
    $st = $conn->prepare("
        SELECT COUNT(*) FROM ars_guest_credits
        WHERE payment_id = ?
          AND (source_type <> 'overpayment' OR amount_applied > 0 OR amount_refunded > 0)
    ");
    $st->execute([(int)$payment['id']]);
    if ((int)$st->fetchColumn() > 0) {
        return 'Guest credit from this payment has already been used or refunded, so it cannot be changed.';
    }
    return null;
}

/**
 * Reverse the payment's journal and take its allocations back off the invoices.
 * Caller owns the transaction. Throws on failure. Returns the reversal journal id (or null).
 */
function ars_payment_unpost(PDO $conn, array $payment, ?int $userId, string $reason): ?int {
    $paymentId = (int)$payment['id'];
    $reversalJournalId = null;

    if (!empty($payment['journal_id'])) {
        $st = $conn->prepare("SELECT is_reversed, journal_date FROM re_journal_headers WHERE id = ?");
        $st->execute([(int)$payment['journal_id']]);
        $jr = $st->fetch(PDO::FETCH_ASSOC);
        if ($jr && (int)$jr['is_reversed'] === 0) {
            $rev = reverse_journal((int)$payment['journal_id'], $reason, $userId, (string)$jr['journal_date']);
            if (empty($rev['success'])) {
                throw new RuntimeException('Journal reversal failed: ' . ($rev['error'] ?? 'unknown'));
            }
            $reversalJournalId = (int)$rev['reversal_journal_id'];
        }
    }

    // Unused overpayment credit: reverse its reclass journal and drop it (frees its idempotency key).
    $st = $conn->prepare("SELECT * FROM ars_guest_credits WHERE payment_id = ? FOR UPDATE");
    $st->execute([$paymentId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $credit) {
        if (!empty($credit['journal_id'])) {
            $cj = $conn->prepare("SELECT is_reversed, journal_date FROM re_journal_headers WHERE id = ?");
            $cj->execute([(int)$credit['journal_id']]);
            $cjr = $cj->fetch(PDO::FETCH_ASSOC);
            if ($cjr && (int)$cjr['is_reversed'] === 0) {
                $rev = reverse_journal((int)$credit['journal_id'], $reason, $userId, (string)$cjr['journal_date']);
                if (empty($rev['success'])) {
                    throw new RuntimeException('Guest credit reversal failed: ' . ($rev['error'] ?? 'unknown'));
                }
            }
        }
        $conn->prepare("DELETE FROM ars_guest_credits WHERE id = ?")->execute([(int)$credit['id']]);
    }

    $st = $conn->prepare("SELECT * FROM ars_payment_allocations WHERE payment_id = ? AND status = 'active' FOR UPDATE");
    $st->execute([$paymentId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $ds = $conn->prepare("SELECT * FROM ars_financial_documents WHERE id = ? FOR UPDATE");
        $ds->execute([(int)$a['document_id']]);
        $doc = $ds->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException('Allocated invoice not found.');
        }
        $newAllocated = round((float)$doc['amount_allocated'] - (float)$a['amount'], 2);
        if ($newAllocated < -0.001) {
            throw new RuntimeException('Invoice ' . $doc['document_number'] . ' would go below zero paid.');
        }
        $newAllocated = max(0, $newAllocated);
        $newStatus = ars_fin_doc_status_from_balances((float)$doc['total_amount'], $newAllocated);
        $conn->prepare("UPDATE ars_financial_documents SET amount_allocated = ?, balance_due = ?, status = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$newAllocated, round((float)$doc['total_amount'] - $newAllocated, 2), $newStatus, (int)$doc['id']]);
        if ($newStatus !== $doc['status']) {
            $conn->prepare("
                INSERT INTO ars_financial_document_transitions (company_id, document_id, from_status, to_status, changed_by, note)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([(int)$doc['company_id'], (int)$doc['id'], $doc['status'], $newStatus, $userId, substr('payment unposted: ' . $reason, 0, 255)]);
        }
        // Free the idempotency key so a re-post of this payment can allocate again.
        $conn->prepare("UPDATE ars_payment_allocations SET status = 'reversed', idempotency_key = CONCAT(COALESCE(idempotency_key, ''), ':rev', id) WHERE id = ?")
            ->execute([(int)$a['id']]);
    }

    return $reversalJournalId;
}
