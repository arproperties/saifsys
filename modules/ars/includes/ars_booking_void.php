<?php
/**
 * Void a booking that was entered by mistake.
 *
 * Posted accounting is never erased: each payment is un-posted and removed
 * (ars_payment_unpost), every invoice journal is reversed and its document marked
 * 'voided', then the booking becomes 'cancelled' with the reason. No guest message.
 */

require_once __DIR__ . '/ars_payment_edit.php';

/** Reasons the booking cannot be voided safely; empty when it can. */
function ars_booking_void_blockers(PDO $conn, array $booking): array {
    $bookingId = (int)$booking['id'];
    $blockers = [];

    if (in_array($booking['status'], ['cancelled', 'expired'], true)) {
        $blockers[] = 'Booking is already ' . $booking['status'] . '.';
    }
    if (!in_array((string)($booking['deposit_status'] ?? 'none'), ['none', 'pending'], true)) {
        $blockers[] = 'Security deposit has been received — settle it on the Deposit tab first.';
    }

    $st = $conn->prepare("SELECT * FROM ars_booking_payments WHERE booking_id = ?");
    $st->execute([$bookingId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        if ($b = ars_payment_edit_blocker($conn, $payment)) {
            $blockers[] = 'Payment #' . (int)$payment['id'] . ': ' . $b;
        }
    }

    $st = $conn->prepare("
        SELECT COUNT(*) FROM ars_guest_credit_applications a
        JOIN ars_financial_documents d ON d.id = a.document_id
        WHERE d.booking_id = ?
    ");
    $st->execute([$bookingId]);
    if ((int)$st->fetchColumn() > 0) {
        $blockers[] = 'Guest credit has been applied to this booking\'s invoices.';
    }

    $st = $conn->prepare("SELECT COUNT(*) FROM ops_jobs WHERE source_type = 'ars_checkout' AND source_id = ?");
    try {
        $st->execute([$bookingId]);
        if ((int)$st->fetchColumn() > 0) {
            $blockers[] = 'A cleaning job was already created for this checkout — cancel it in Operations first.';
        }
    } catch (PDOException $e) {
        // ops module not installed on this deployment
    }

    return $blockers;
}

/** Caller owns the transaction. Throws on failure. */
function ars_booking_void(PDO $conn, array $booking, ?int $userId, string $reason): void {
    $bookingId = (int)$booking['id'];

    $st = $conn->prepare("SELECT * FROM ars_booking_payments WHERE booking_id = ?");
    $st->execute([$bookingId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        ars_payment_unpost($conn, $payment, $userId, 'Booking voided: ' . $reason);
        $conn->prepare("DELETE FROM ars_guest_notifications WHERE payment_id = ?")->execute([(int)$payment['id']]);
        $conn->prepare("DELETE FROM ars_booking_payments WHERE id = ?")->execute([(int)$payment['id']]);
    }

    $reversed = [];
    $reverse = static function (?int $journalId) use ($conn, $userId, $reason, &$reversed): ?int {
        if (!$journalId || isset($reversed[$journalId])) {
            return $reversed[$journalId] ?? null;
        }
        $st = $conn->prepare("SELECT is_reversed, reversal_journal_id FROM re_journal_headers WHERE id = ?");
        $st->execute([$journalId]);
        $jr = $st->fetch(PDO::FETCH_ASSOC);
        if (!$jr) {
            return $reversed[$journalId] = null;
        }
        if ((int)$jr['is_reversed'] === 1) {
            return $reversed[$journalId] = ($jr['reversal_journal_id'] ? (int)$jr['reversal_journal_id'] : null);
        }
        $rev = reverse_journal($journalId, 'Booking voided: ' . $reason, $userId);
        if (empty($rev['success'])) {
            throw new RuntimeException('Journal reversal failed: ' . ($rev['error'] ?? 'unknown'));
        }
        return $reversed[$journalId] = (int)$rev['reversal_journal_id'];
    };

    $st = $conn->prepare("SELECT * FROM ars_financial_documents WHERE booking_id = ? AND status NOT IN ('voided','reversed','cancelled') FOR UPDATE");
    $st->execute([$bookingId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $revId = $reverse(!empty($doc['journal_id']) ? (int)$doc['journal_id'] : null);
        $conn->prepare("UPDATE ars_financial_documents SET status = 'voided', balance_due = 0, reversal_journal_id = COALESCE(reversal_journal_id, ?), updated_at = NOW() WHERE id = ?")
            ->execute([$revId, (int)$doc['id']]);
        $conn->prepare("
            INSERT INTO ars_financial_document_transitions (company_id, document_id, from_status, to_status, changed_by, note)
            VALUES (?, ?, ?, 'voided', ?, ?)
        ")->execute([(int)$doc['company_id'], (int)$doc['id'], $doc['status'], $userId, substr('booking voided: ' . $reason, 0, 255)]);
    }
    $reverse(!empty($booking['journal_id']) ? (int)$booking['journal_id'] : null);

    $conn->prepare("
        UPDATE ars_bookings
        SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancellation_reason = ?,
            paid_amount = 0, balance_due = 0, updated_at = NOW()
        WHERE id = ?
    ")->execute([$userId, substr('Wrong entry: ' . $reason, 0, 255), $bookingId]);
}
