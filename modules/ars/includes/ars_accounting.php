<?php
/**
 * ARS Home Rentals — Accounting Integration
 * Legacy bridge to shared accounting_engine.php.
 * When financial_adapter_enabled=1, delegates to ars_financial_adapter.php.
 */

require_once dirname(__DIR__, 2) . '/realestate/accounting/accounting_engine.php';
require_once __DIR__ . '/ars_financial_adapter.php';

/**
 * Post revenue journal when booking is confirmed.
 * DR AR-Guests (1310) = total_amount
 * CR Room Revenue (4100) = subtotal + extras_total
 * CR Output VAT (2310) = vat_amount
 */
function ars_post_booking_revenue(PDO $conn, array $booking, ?int $userId = null): array {
    if (ars_financial_adapter_enabled($conn, (int) ($booking['company_id'] ?? 0))) {
        $r = ars_adapter_create_original_invoice($conn, $booking, [
            'user_id' => $userId,
            'idempotency_key' => 'invoice:original:' . (int) $booking['id'],
        ]);
        return [
            'success' => !empty($r['success']),
            'journal_id' => $r['journal_id'] ?? null,
            'error' => $r['error'] ?? null,
            'document_id' => $r['document_id'] ?? null,
            'adapter' => true,
            'code' => $r['code'] ?? null,
        ];
    }

    $opsCompanyId = (int) $booking['company_id'];
    $glCompanyId = ars_financial_gl_company_id($conn, $opsCompanyId);
    if ($opsCompanyId <= 0 || $glCompanyId <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Missing company_id'];
    }

    $roles = ars_require_account_roles($conn, $glCompanyId, ['AR_GUEST', 'ROOM_REVENUE', 'VAT_OUTPUT']);
    if (!$roles['success']) {
        return ['success' => false, 'journal_id' => null, 'error' => $roles['error']];
    }
    $arAccount = $roles['accounts']['AR_GUEST'];
    $revAccount = $roles['accounts']['ROOM_REVENUE'];
    $vatAccount = $roles['accounts']['VAT_OUTPUT'];

    if (isset($booking['net_amount']) && $booking['net_amount'] !== null && $booking['net_amount'] !== '') {
        $netRevenue = round((float) $booking['net_amount'], 2);
    } else {
        $netRevenue = round(
            (float) $booking['subtotal']
            - (float) ($booking['length_discount_amount'] ?? 0)
            - (float) ($booking['discount_amount'] ?? 0)
            + (float) $booking['extras_total'],
            2
        );
    }
    $vatAmount = (float) $booking['vat_amount'];
    $total     = round($netRevenue + $vatAmount, 2);

    $lines = [];
    $lines[] = [
        'account_id'  => $arAccount['id'],
        'debit'       => $total,
        'credit'      => 0,
        'description' => 'AR - Booking ' . $booking['booking_number'],
        'reference'   => $booking['booking_number'],
    ];
    $lines[] = [
        'account_id'  => $revAccount['id'],
        'debit'       => 0,
        'credit'      => $netRevenue,
        'description' => 'Room Revenue - Booking ' . $booking['booking_number'],
        'reference'   => $booking['booking_number'],
    ];
    if ($vatAmount > 0) {
        $lines[] = [
            'account_id'  => $vatAccount['id'],
            'debit'       => 0,
            'credit'      => $vatAmount,
            'description' => 'Output VAT - Booking ' . $booking['booking_number'],
            'reference'   => $booking['booking_number'],
        ];
    }

    $journalDate = !empty($booking['is_historical']) && !empty($booking['check_in'])
        ? $booking['check_in']
        : date('Y-m-d');

    $result = create_and_post_journal(
        $glCompanyId,
        'invoice',
        'ars_booking',
        (int) $booking['id'],
        $lines,
        'ARS Booking Revenue: ' . $booking['booking_number'],
        $journalDate,
        $userId
    );

    if ($result['success'] && !empty($result['journal_id'])) {
        $conn->prepare("UPDATE ars_bookings SET journal_id = ? WHERE id = ? AND company_id = ?")
             ->execute([$result['journal_id'], $booking['id'], $opsCompanyId]);
    }

    return $result;
}

/**
 * Post payment journal.
 * DR Cash/Bank (1110 or 1210) = amount
 * CR AR-Guests (1310) = amount
 */
function ars_post_payment_journal(PDO $conn, array $payment, array $booking, ?int $userId = null): array {
    if (ars_financial_adapter_enabled($conn, (int) ($payment['company_id'] ?? $booking['company_id'] ?? 0))) {
        $r = ars_adapter_record_payment($conn, $payment, $booking, [
            'user_id' => $userId,
            'receipt_account_code' => $payment['receipt_account_code'] ?? null,
        ]);
        return [
            'success' => !empty($r['success']),
            'journal_id' => $r['journal_id'] ?? null,
            'error' => $r['error'] ?? null,
            'document_id' => $r['document_id'] ?? null,
            'adapter' => true,
            'code' => $r['code'] ?? null,
        ];
    }

    $opsCompanyId = (int) $payment['company_id'];
    $glCompanyId = ars_financial_gl_company_id($conn, $opsCompanyId);
    if ($opsCompanyId <= 0 || $glCompanyId <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Missing company_id'];
    }

    $method = (string) ($payment['payment_method'] ?? 'cash');
    $receipt = ars_resolve_receipt_account($conn, $glCompanyId, $method, $payment['receipt_account_code'] ?? null);
    if (!$receipt['success']) {
        return ['success' => false, 'journal_id' => null, 'error' => $receipt['error']];
    }
    $arAccount = find_account_by_code(
        ars_resolve_account_code($conn, $glCompanyId, 'AR_GUEST') ?? '1340',
        $glCompanyId
    );

    if (!$arAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Missing AR account on financial company.'];
    }

    $amount = (float) $payment['amount'];
    $lines = [
        [
            'account_id'  => $receipt['account']['id'],
            'debit'       => $amount,
            'credit'      => 0,
            'description' => 'Payment received - ' . $booking['booking_number'],
            'reference'   => $payment['reference_number'] ?: $booking['booking_number'],
        ],
        [
            'account_id'  => $arAccount['id'],
            'debit'       => 0,
            'credit'      => $amount,
            'description' => 'AR settlement - ' . $booking['booking_number'],
            'reference'   => $payment['reference_number'] ?: $booking['booking_number'],
        ],
    ];

    $result = create_and_post_journal(
        $glCompanyId,
        'payment',
        'ars_payment',
        (int) $payment['id'],
        $lines,
        'ARS Payment: ' . $booking['booking_number'],
        $payment['payment_date'],
        $userId
    );

    if ($result['success'] && !empty($result['journal_id'])) {
        $conn->prepare("UPDATE ars_booking_payments SET journal_id = ?, receipt_account_code = ? WHERE id = ? AND company_id = ?")
             ->execute([$result['journal_id'], $receipt['account_code'], $payment['id'], $opsCompanyId]);
    }

    return $result;
}

/**
 * Reverse the revenue journal for a cancelled booking.
 */
function ars_reverse_booking_journal(int $journalId, ?int $userId = null): array {
    return reverse_journal($journalId, 'Booking cancelled', $userId);
}

/**
 * Post deposit received journal.
 * DR Cash/Bank (1110 or 1210) = deposit amount
 * CR Guest Deposits Held (2200) = deposit amount
 */
function ars_post_deposit_received(
    PDO $conn,
    array $booking,
    float $amount,
    string $method,
    ?int $userId = null,
    ?string $receiptAccountCode = null
): array {
    if (ars_financial_adapter_enabled($conn, (int) ($booking['company_id'] ?? 0))) {
        $r = ars_adapter_receive_deposit($conn, $booking, $amount, $method, [
            'user_id' => $userId,
            'idempotency_key' => 'deposit:received:' . (int) $booking['id'],
            'receipt_account_code' => $receiptAccountCode,
        ]);
        return [
            'success' => !empty($r['success']),
            'journal_id' => $r['journal_id'] ?? null,
            'error' => $r['error'] ?? null,
            'deposit_id' => $r['deposit_id'] ?? null,
            'adapter' => true,
            'code' => $r['code'] ?? null,
        ];
    }

    $opsCompanyId = (int) $booking['company_id'];
    $glCompanyId = ars_financial_gl_company_id($conn, $opsCompanyId);
    if ($opsCompanyId <= 0 || $glCompanyId <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Missing company_id'];
    }

    $receipt = ars_resolve_receipt_account($conn, $glCompanyId, $method, $receiptAccountCode);
    if (!$receipt['success']) {
        return ['success' => false, 'journal_id' => null, 'error' => $receipt['error']];
    }
    $depResolved = ars_resolve_account_by_role($conn, $glCompanyId, 'SECURITY_DEPOSIT');
    if (!$depResolved['success']) {
        return ['success' => false, 'journal_id' => null, 'error' => $depResolved['error']];
    }

    $lines = [
        [
            'account_id'  => $receipt['account']['id'],
            'debit'       => $amount,
            'credit'      => 0,
            'description' => 'Deposit received - ' . $booking['booking_number'],
            'reference'   => $booking['booking_number'],
        ],
        [
            'account_id'  => $depResolved['account']['id'],
            'debit'       => 0,
            'credit'      => $amount,
            'description' => 'Guest deposit liability - ' . $booking['booking_number'],
            'reference'   => $booking['booking_number'],
        ],
    ];

    $result = create_and_post_journal(
        $glCompanyId,
        'deposit',
        'ars_deposit',
        (int) $booking['id'],
        $lines,
        'ARS Security Deposit: ' . $booking['booking_number'],
        date('Y-m-d'),
        $userId
    );

    if ($result['success'] && !empty($result['journal_id'])) {
        $conn->prepare("UPDATE ars_bookings SET deposit_journal_id = ? WHERE id = ? AND company_id = ?")
             ->execute([$result['journal_id'], $booking['id'], $opsCompanyId]);
    }

    return $result;
}

/**
 * Post deposit refund journal.
 * DR Guest Deposits Held (2200) = refund amount
 * CR Cash/Bank (1110 or 1210) = refund amount
 */
function ars_post_deposit_refund(
    PDO $conn,
    array $booking,
    float $refundAmount,
    string $method,
    ?int $userId = null,
    ?string $receiptAccountCode = null
): array {
    if (ars_financial_adapter_enabled($conn, (int) ($booking['company_id'] ?? 0))) {
        $r = ars_adapter_refund_deposit($conn, $booking, $refundAmount, $method, [
            'user_id' => $userId,
            'idempotency_key' => 'deposit:refund:' . (int) $booking['id'] . ':' . round($refundAmount, 2),
            'receipt_account_code' => $receiptAccountCode,
        ]);
        return [
            'success' => !empty($r['success']),
            'journal_id' => $r['journal_id'] ?? null,
            'error' => $r['error'] ?? null,
            'deposit_id' => $r['deposit_id'] ?? null,
            'adapter' => true,
            'code' => $r['code'] ?? null,
        ];
    }

    $opsCompanyId = (int) $booking['company_id'];
    $glCompanyId = ars_financial_gl_company_id($conn, $opsCompanyId);
    if ($opsCompanyId <= 0 || $glCompanyId <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Missing company_id'];
    }

    $receipt = ars_resolve_receipt_account($conn, $glCompanyId, $method, $receiptAccountCode);
    if (!$receipt['success']) {
        return ['success' => false, 'journal_id' => null, 'error' => $receipt['error']];
    }
    $depResolved = ars_resolve_account_by_role($conn, $glCompanyId, 'SECURITY_DEPOSIT');
    if (!$depResolved['success']) {
        return ['success' => false, 'journal_id' => null, 'error' => $depResolved['error']];
    }

    $lines = [
        [
            'account_id'  => $depResolved['account']['id'],
            'debit'       => $refundAmount,
            'credit'      => 0,
            'description' => 'Deposit refund - ' . $booking['booking_number'],
            'reference'   => $booking['booking_number'],
        ],
        [
            'account_id'  => $receipt['account']['id'],
            'debit'       => 0,
            'credit'      => $refundAmount,
            'description' => 'Deposit refund paid - ' . $booking['booking_number'],
            'reference'   => $booking['booking_number'],
        ],
    ];

    $result = create_and_post_journal(
        $glCompanyId,
        'refund',
        'ars_deposit_refund',
        (int) $booking['id'],
        $lines,
        'ARS Deposit Refund: ' . $booking['booking_number'],
        date('Y-m-d'),
        $userId
    );

    if ($result['success'] && !empty($result['journal_id'])) {
        $conn->prepare("UPDATE ars_bookings SET deposit_refund_journal_id = ? WHERE id = ? AND company_id = ?")
             ->execute([$result['journal_id'], $booking['id'], $opsCompanyId]);
    }

    return $result;
}

/**
 * Post deposit settlement journal (BR-ARS-OPS-002, no VAT).
 * DR 2200 = refund + deductions
 * CR cash/bank = refund (if > 0)
 * CR 4200 = damage total
 * CR 4900 = lost_item + other total
 *
 * @param list<array{type:string,amount:float,note:string}> $deductions
 * @return array{success:bool,journal_id:?int,error:?string}
 */
function ars_post_deposit_settlement(
    PDO $conn,
    array $booking,
    float $refundAmount,
    string $method,
    array $deductions,
    ?int $userId = null,
    ?string $receiptAccountCode = null
): array {
    $opsCompanyId = (int)($booking['company_id'] ?? 0);
    $glCompanyId = ars_financial_gl_company_id($conn, $opsCompanyId);
    if ($opsCompanyId <= 0 || $glCompanyId <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Missing company_id'];
    }
    $companyId = $glCompanyId; // journal COA company

    $refundAmount = round($refundAmount, 2);
    $damageTotal = 0.0;
    $otherTotal = 0.0;
    $damageNotes = [];
    $otherNotes = [];
    foreach ($deductions as $d) {
        $amt = round((float)($d['amount'] ?? 0), 2);
        $type = (string)($d['type'] ?? '');
        $note = trim((string)($d['note'] ?? ''));
        if ($amt <= 0) {
            continue;
        }
        if ($type === 'damage') {
            $damageTotal += $amt;
            $damageNotes[] = $note !== '' ? $note : 'Damage';
        } else {
            $otherTotal += $amt;
            $label = $type === 'lost_item' ? 'Lost item' : 'Other';
            $otherNotes[] = ($note !== '' ? $note : $label);
        }
    }
    $damageTotal = round($damageTotal, 2);
    $otherTotal = round($otherTotal, 2);
    $releaseTotal = round($refundAmount + $damageTotal + $otherTotal, 2);

    if ($releaseTotal <= 0.009) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Nothing to settle.'];
    }

    // Pure refund → keep classic deposit refund journal shape.
    if ($refundAmount > 0.009 && $damageTotal <= 0.009 && $otherTotal <= 0.009) {
        return ars_post_deposit_refund($conn, $booking, $refundAmount, $method, $userId, $receiptAccountCode);
    }

    $cashAccount = null;
    if ($refundAmount > 0.009) {
        $receipt = ars_resolve_receipt_account($conn, $glCompanyId, $method, $receiptAccountCode);
        if (!$receipt['success']) {
            return ['success' => false, 'journal_id' => null, 'error' => $receipt['error']];
        }
        $cashAccount = $receipt['account'];
    }
    $depositResolved = ars_resolve_account_by_role($conn, $glCompanyId, 'SECURITY_DEPOSIT');
    $damageResolved = ars_resolve_account_by_role($conn, $glCompanyId, 'DAMAGE_REVENUE');
    $forfeitResolved = ars_resolve_account_by_role($conn, $glCompanyId, 'FORFEIT_REVENUE');
    $depositAccount = $depositResolved['success'] ? $depositResolved['account'] : null;
    $damageAccount = $damageResolved['success'] ? $damageResolved['account'] : null;
    $forfeitAccount = $forfeitResolved['success'] ? $forfeitResolved['account'] : null;

    if (!$depositAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => $depositResolved['error'] ?? 'Missing SECURITY_DEPOSIT role account.'];
    }
    if ($refundAmount > 0.009 && !$cashAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Missing cash/bank receipt account.'];
    }
    if ($damageTotal > 0.009 && !$damageAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => $damageResolved['error'] ?? 'Missing DAMAGE_REVENUE account.'];
    }
    if ($otherTotal > 0.009 && !$forfeitAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => $forfeitResolved['error'] ?? 'Missing FORFEIT_REVENUE account.'];
    }

    $bn = (string)($booking['booking_number'] ?? ('#' . (int)($booking['id'] ?? 0)));
    $lines = [
        [
            'account_id' => $depositAccount['id'],
            'debit' => $releaseTotal,
            'credit' => 0,
            'description' => 'Deposit settlement release - ' . $bn,
            'reference' => $bn,
        ],
    ];
    if ($refundAmount > 0.009) {
        $lines[] = [
            'account_id' => $cashAccount['id'],
            'debit' => 0,
            'credit' => $refundAmount,
            'description' => 'Deposit refund paid - ' . $bn,
            'reference' => $bn,
        ];
    }
    if ($damageTotal > 0.009) {
        $lines[] = [
            'account_id' => $damageAccount['id'],
            'debit' => 0,
            'credit' => $damageTotal,
            'description' => 'Deposit deduction (damage, no VAT): ' . implode('; ', $damageNotes),
            'reference' => $bn,
        ];
    }
    if ($otherTotal > 0.009) {
        $lines[] = [
            'account_id' => $forfeitAccount['id'],
            'debit' => 0,
            'credit' => $otherTotal,
            'description' => 'Deposit deduction (forfeit/other, no VAT): ' . implode('; ', $otherNotes),
            'reference' => $bn,
        ];
    }

    $result = create_and_post_journal(
        $companyId,
        'deposit',
        'ars_deposit_settlement',
        (int)$booking['id'],
        $lines,
        'ARS Deposit Settlement: ' . $bn,
        date('Y-m-d'),
        $userId
    );

    if ($result['success'] && !empty($result['journal_id'])) {
        $jid = (int)$result['journal_id'];
        $conn->prepare("UPDATE ars_bookings SET deposit_settlement_journal_id = ? WHERE id = ? AND company_id = ?")
             ->execute([$jid, $booking['id'], $opsCompanyId]);
        if ($refundAmount > 0.009) {
            $conn->prepare("UPDATE ars_bookings SET deposit_refund_journal_id = ? WHERE id = ? AND company_id = ?")
                 ->execute([$jid, $booking['id'], $opsCompanyId]);
        }
    }

    return $result;
}
