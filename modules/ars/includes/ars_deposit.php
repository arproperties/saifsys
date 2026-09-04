<?php
/**
 * ARS short-term booking security deposit helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/ars_accounting.php';
require_once __DIR__ . '/ars_guest_notifications.php';

function ars_deposit_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ars_deposit_add_column($conn, 'ars_bookings', 'deposit_payment_method', "VARCHAR(30) DEFAULT NULL");
    ars_deposit_add_column($conn, 'ars_bookings', 'deposit_stripe_payment_id', 'INT(11) DEFAULT NULL');
    ars_deposit_add_column($conn, 'ars_bookings', 'deposit_cash_requested', 'TINYINT(1) NOT NULL DEFAULT 0');
    ars_deposit_add_column($conn, 'ars_bookings', 'deposit_forfeited_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    ars_deposit_add_column($conn, 'ars_bookings', 'deposit_settlement_journal_id', 'INT NULL DEFAULT NULL');

    try {
        $conn->exec("
            ALTER TABLE ars_bookings
            MODIFY deposit_status ENUM('none','pending','received','partially_refunded','refunded','forfeited')
            NOT NULL DEFAULT 'none'
        ");
    } catch (Throwable $e) {
        error_log('ARS deposit_status enum: ' . $e->getMessage());
    }

    try {
        $conn->exec("
            ALTER TABLE ars_booking_payments
            MODIFY payment_type ENUM('manual','full','deposit','balance','partial','security_deposit')
            NOT NULL DEFAULT 'manual'
        ");
    } catch (Throwable $e) {
        error_log('ARS security_deposit payment_type enum: ' . $e->getMessage());
    }
}

function ars_deposit_add_column(PDO $conn, string $table, string $column, string $definition): void {
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($column));
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $e) {
        error_log("ARS deposit schema failed for $table.$column: " . $e->getMessage());
    }
}

/** SQL fragment: exclude security-deposit Stripe rows from room balance totals. */
function ars_payment_room_balance_sql_filter(string $alias = 'p'): string {
    $col = $alias !== '' ? "{$alias}.payment_type" : 'payment_type';
    return " AND COALESCE({$col}, 'manual') != 'security_deposit' ";
}

/**
 * Remaining held liability (not yet refunded or forfeited).
 *
 * @param array<string,mixed> $booking
 */
function ars_deposit_held_remaining(array $booking): float {
    $amount = round((float)($booking['deposit_amount'] ?? 0), 2);
    $refunded = round((float)($booking['deposit_refunded_amount'] ?? 0), 2);
    $forfeited = round((float)($booking['deposit_forfeited_amount'] ?? 0), 2);
    return round(max($amount - $refunded - $forfeited, 0), 2);
}

/**
 * Status after settlement amounts are updated.
 */
function ars_deposit_status_after_settlement(float $depositAmount, float $refunded, float $forfeited): string {
    $remaining = round(max($depositAmount - $refunded - $forfeited, 0), 2);
    if ($remaining > 0.009) {
        return 'partially_refunded';
    }
    if ($forfeited > 0.009 && $refunded <= 0.009) {
        return 'forfeited';
    }
    return 'refunded';
}

/**
 * Allowed deduction types for BR-ARS-OPS-002 (no VAT).
 *
 * @return list<string>
 */
function ars_deposit_deduction_types(): array {
    return ['damage', 'lost_item', 'other'];
}

/**
 * Normalize and validate deduction rows from operator input.
 *
 * @param mixed $raw
 * @return array{ok:bool,deductions:list<array{type:string,amount:float,note:string}>,error:?string,total:float}
 */
function ars_deposit_normalize_deductions($raw): array {
    if ($raw === null || $raw === '' || $raw === []) {
        return ['ok' => true, 'deductions' => [], 'error' => null, 'total' => 0.0];
    }
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'deductions' => [], 'error' => 'Invalid deductions payload.', 'total' => 0.0];
        }
        $raw = $decoded;
    }
    if (!is_array($raw)) {
        return ['ok' => false, 'deductions' => [], 'error' => 'Invalid deductions payload.', 'total' => 0.0];
    }

    $allowed = ars_deposit_deduction_types();
    $out = [];
    $total = 0.0;
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = strtolower(trim((string)($row['type'] ?? '')));
        $amount = round((float)($row['amount'] ?? 0), 2);
        $note = trim((string)($row['note'] ?? ''));
        if ($amount <= 0) {
            continue;
        }
        if (!in_array($type, $allowed, true)) {
            return ['ok' => false, 'deductions' => [], 'error' => 'Invalid deduction type: ' . $type, 'total' => 0.0];
        }
        if ($note === '') {
            return ['ok' => false, 'deductions' => [], 'error' => 'Each deduction requires a note.', 'total' => 0.0];
        }
        $out[] = ['type' => $type, 'amount' => $amount, 'note' => $note];
        $total += $amount;
    }
    return ['ok' => true, 'deductions' => $out, 'error' => null, 'total' => round($total, 2)];
}

function ars_booking_security_deposit_payload(array $booking, array $settings = []): array {
    $amount = round((float)($booking['deposit_amount'] ?? 0), 2);
    $status = (string)($booking['deposit_status'] ?? 'none');
    $refunded = round((float)($booking['deposit_refunded_amount'] ?? 0), 2);
    $forfeited = round((float)($booking['deposit_forfeited_amount'] ?? 0), 2);
    $refundable = ars_deposit_held_remaining($booking);
    $currency = (string)($settings['currency'] ?? 'AED');
    $stripeOn = !empty($settings['stripe_enabled']);

    $canPayOnline = $stripeOn
        && $amount > 0
        && $status === 'pending'
        && empty($booking['deposit_cash_requested']);

    $canChooseCash = $amount > 0 && $status === 'pending' && empty($booking['deposit_cash_requested']);

    return [
        'amount' => number_format($amount, 2, '.', ''),
        'status' => $status,
        'payment_method' => $booking['deposit_payment_method'] !== null
            ? (string)$booking['deposit_payment_method']
            : null,
        'cash_requested' => !empty($booking['deposit_cash_requested']),
        'received_date' => $booking['deposit_received_date'] !== null
            ? (string)$booking['deposit_received_date']
            : null,
        'refunded_amount' => number_format($refunded, 2, '.', ''),
        'forfeited_amount' => number_format($forfeited, 2, '.', ''),
        'refundable_amount' => number_format($refundable, 2, '.', ''),
        'held_remaining' => number_format($refundable, 2, '.', ''),
        'refunded_date' => $booking['deposit_refunded_date'] !== null
            ? (string)$booking['deposit_refunded_date']
            : null,
        'currency' => $currency,
        'can_pay_online' => $canPayOnline,
        'can_choose_cash' => $canChooseCash,
        'is_due' => $amount > 0 && $status === 'pending',
        'is_received' => in_array($status, ['received', 'partially_refunded'], true),
        'is_fully_refunded' => $status === 'refunded',
        'is_forfeited' => $status === 'forfeited',
    ];
}

/**
 * Mark security deposit as received (cash, card at desk, or Stripe).
 *
 * @param array<string,mixed> $booking
 */
function ars_booking_mark_deposit_received(
    PDO $conn,
    array $booking,
    float $amount,
    string $method,
    ?int $userId = null,
    ?int $stripePaymentId = null,
    ?string $receiptAccountCode = null
): void {
    ars_deposit_ensure_schema($conn);
    ars_ensure_receipt_account_columns($conn);

    $bookingId = (int)$booking['id'];
    $expected = round((float)($booking['deposit_amount'] ?? 0), 2);
    if ($expected <= 0) {
        throw new RuntimeException('No security deposit is set on this booking.');
    }
    if ((string)($booking['deposit_status'] ?? 'none') !== 'pending') {
        throw new RuntimeException('Security deposit is not awaiting payment.');
    }

    $amount = round($amount, 2);
    if ($amount <= 0 || $amount > $expected + 0.01) {
        throw new RuntimeException('Invalid deposit amount.');
    }

    $journalResult = ars_post_deposit_received($conn, $booking, $amount, $method, $userId, $receiptAccountCode);
    if (empty($journalResult['success'])) {
        throw new RuntimeException($journalResult['error'] ?? 'Deposit journal failed.');
    }

    $conn->prepare("
        UPDATE ars_bookings SET
            deposit_status = 'received',
            deposit_received_date = CURDATE(),
            deposit_payment_method = ?,
            deposit_stripe_payment_id = ?,
            deposit_cash_requested = 0,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([
        $method,
        $stripePaymentId > 0 ? $stripePaymentId : null,
        $bookingId,
    ]);

    ars_guest_notification_create($conn, [
        'company_id' => (int)$booking['company_id'],
        'guest_id' => (int)$booking['guest_id'],
        'booking_id' => $bookingId,
        'event_type' => 'deposit_received',
        'title' => 'Security deposit received',
        'message' => 'We recorded your security deposit of '
            . number_format($amount, 2) . ' for booking '
            . (string)($booking['booking_number'] ?? ('#' . $bookingId)) . '.',
        'cta_route' => '/guest/bookings/' . $bookingId,
    ]);
}

/**
 * @param array<string,mixed> $booking
 */
function ars_booking_request_deposit_cash(PDO $conn, array $booking): void {
    ars_deposit_ensure_schema($conn);
    $amount = (float)($booking['deposit_amount'] ?? 0);
    if ($amount <= 0) {
        throw new RuntimeException('No security deposit is required for this booking.');
    }
    if ((string)($booking['deposit_status'] ?? 'none') !== 'pending') {
        throw new RuntimeException('Security deposit is not due.');
    }

    $conn->prepare("
        UPDATE ars_bookings SET deposit_cash_requested = 1, deposit_payment_method = 'cash', updated_at = NOW()
        WHERE id = ?
    ")->execute([(int)$booking['id']]);

    try {
        ars_guest_notification_create($conn, [
            'company_id' => (int)$booking['company_id'],
            'guest_id' => (int)$booking['guest_id'],
            'booking_id' => (int)$booking['id'],
            'event_type' => 'deposit_cash_selected',
            'title' => 'Cash deposit noted',
            'message' => 'You chose to pay the security deposit in cash. Our team will confirm when received.',
            'cta_route' => '/guest/bookings/' . (int)$booking['id'],
        ]);
    } catch (Throwable $e) {
        error_log('ARS deposit cash notification failed: ' . $e->getMessage());
    }
}

/**
 * @param array<string,mixed> $booking
 */
function ars_booking_set_security_deposit_amount(PDO $conn, array $booking, float $amount): void {
    ars_deposit_ensure_schema($conn);
    $bookingId = (int)$booking['id'];
    $status = (string)($booking['deposit_status'] ?? 'none');

    if ($amount > 0 && !in_array($status, ['none', 'pending'], true)) {
        throw new RuntimeException('Cannot change deposit amount after it has been received.');
    }

    if ($amount <= 0) {
        if (!in_array($status, ['none', 'pending'], true)) {
            throw new RuntimeException('Cannot remove deposit after it has been received.');
        }
        $conn->prepare("
            UPDATE ars_bookings SET
                deposit_amount = 0,
                deposit_status = 'none',
                deposit_cash_requested = 0,
                deposit_payment_method = NULL,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$bookingId]);
        return;
    }

    $conn->prepare("
        UPDATE ars_bookings SET
            deposit_amount = ?,
            deposit_status = 'pending',
            deposit_cash_requested = 0,
            deposit_payment_method = NULL,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([round($amount, 2), $bookingId]);

    $stmt = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$bookingId]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fresh) {
        ars_guest_notification_create($conn, [
            'company_id' => (int)$fresh['company_id'],
            'guest_id' => (int)$fresh['guest_id'],
            'booking_id' => $bookingId,
            'event_type' => 'deposit_required',
            'title' => 'Security deposit required',
            'message' => 'A security deposit of AED ' . number_format($amount, 2)
                . ' is required for booking ' . (string)($fresh['booking_number'] ?? '') . '.',
            'cta_route' => '/guest/bookings/' . $bookingId,
        ]);
    }
}
