<?php
/**
 * Stops the same tenant payment being saved as a second receipt.
 *
 * A payment is taken as already recorded when the lease has a receipt for the same amount,
 * dated within 7 days, with the same bank reference, and that reference holds a bank
 * transaction number (8 or more digits). Short references such as a cheque number or "BT"
 * are never matched, so instalments that share them are not blocked.
 *
 * Over all receipts up to 27 Nov 2026 this matched exactly the 3 payments that had been
 * entered twice (Barberz Park Place Shop 01, Ayla 505, Ayla 418) and nothing else.
 */

if (!function_exists('re_receipt_find_duplicate')) {
    function re_receipt_find_duplicate(PDO $conn, int $companyId, int $leaseId, float $amount, string $paymentDate, ?string $reference): ?array
    {
        $reference = trim((string)$reference);
        if ($leaseId <= 0 || $amount <= 0 || $paymentDate === '' || !preg_match('/\d{8}/', $reference)) {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT id, receipt_number, payment_date, amount
              FROM re_payments
             WHERE company_id = ?
               AND lease_id = ?
               AND ABS(amount - ?) < 0.005
               AND ABS(DATEDIFF(payment_date, ?)) <= 7
               AND LOWER(TRIM(reference_number)) = LOWER(?)
             ORDER BY id
             LIMIT 1
        ");
        $stmt->execute([$companyId, $leaseId, $amount, $paymentDate, $reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('re_receipt_assert_not_duplicate')) {
    /** Throws when the payment is already recorded, naming the existing receipt. */
    function re_receipt_assert_not_duplicate(PDO $conn, int $companyId, int $leaseId, float $amount, string $paymentDate, ?string $reference): void
    {
        $dup = re_receipt_find_duplicate($conn, $companyId, $leaseId, $amount, $paymentDate, $reference);
        if ($dup === null) {
            return;
        }
        $label = trim((string)($dup['receipt_number'] ?? '')) !== '' ? (string)$dup['receipt_number'] : ('receipt #' . (int)$dup['id']);
        throw new RuntimeException(sprintf(
            'This payment is already recorded as %s (AED %s on %s, same bank reference). Nothing was saved.',
            $label,
            number_format((float)$dup['amount'], 2),
            date('d M Y', strtotime((string)$dup['payment_date']))
        ));
    }
}
