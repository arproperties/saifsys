<?php
/**
 * ARS booking lifecycle requests from guests.
 */

declare(strict_types=1);

require_once __DIR__ . '/ars_guest_notifications.php';
require_once __DIR__ . '/ars_pricing.php';
require_once __DIR__ . '/ars_availability.php';
require_once __DIR__ . '/ars_financial_lock.php';

function ars_booking_normalize_date(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d', $ts);
}

function ars_booking_requests_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_booking_lifecycle_requests (
            id INT(11) NOT NULL AUTO_INCREMENT,
            company_id INT(11) NOT NULL,
            booking_id INT(11) NOT NULL,
            guest_id INT(11) NOT NULL,
            request_type ENUM('cancellation','extension','early_checkin','late_checkout','support') NOT NULL,
            status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            guest_note TEXT DEFAULT NULL,
            admin_note TEXT DEFAULT NULL,
            requested_check_in DATE DEFAULT NULL,
            requested_check_out DATE DEFAULT NULL,
            requested_time VARCHAR(20) DEFAULT NULL,
            requested_nights INT(11) DEFAULT NULL,
            contact_subject VARCHAR(255) DEFAULT NULL,
            payload_json JSON DEFAULT NULL,
            reviewed_by INT(11) DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ars_req_booking (company_id, booking_id, created_at),
            KEY idx_ars_req_guest (company_id, guest_id, created_at),
            KEY idx_ars_req_status (company_id, status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * @param array<string,mixed> $booking
 * @param array<string,mixed> $body
 */
function ars_booking_request_create(PDO $conn, array $booking, int $guestId, array $body): array {
    ars_booking_requests_ensure_schema($conn);
    $type = strtolower(trim((string)($body['request_type'] ?? '')));
    $allowed = ['cancellation', 'extension', 'early_checkin', 'late_checkout', 'support'];
    if (!in_array($type, $allowed, true)) {
        throw new InvalidArgumentException('Invalid request_type.');
    }

    $guestNote = trim((string)($body['guest_note'] ?? ''));
    $requestedCheckIn = ars_booking_normalize_date($body['requested_check_in'] ?? '');
    $requestedCheckOut = ars_booking_normalize_date($body['requested_check_out'] ?? '');
    $requestedTime = trim((string)($body['requested_time'] ?? ''));
    $contactSubject = trim((string)($body['contact_subject'] ?? ''));

    if ($type === 'extension') {
        if ($requestedCheckOut === '') {
            throw new InvalidArgumentException(
                'Please provide a valid check-out date (YYYY-MM-DD), after your current check-out.'
            );
        }
        if ($requestedCheckOut <= (string)$booking['check_out']) {
            throw new InvalidArgumentException(
                'The new check-out date must be after your current check-out (' . (string)$booking['check_out'] . ').'
            );
        }
    }
    if (($type === 'early_checkin' || $type === 'late_checkout') && $requestedTime === '') {
        throw new InvalidArgumentException('requested_time is required for this request type.');
    }
    if ($type === 'support' && $contactSubject === '') {
        throw new InvalidArgumentException('contact_subject is required for support request.');
    }

    $requestedNights = null;
    if ($requestedCheckOut !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedCheckOut)) {
        $days = (int)((strtotime($requestedCheckOut) - strtotime((string)$booking['check_out'])) / 86400);
        $requestedNights = $days > 0 ? $days : null;
    }

    $payload = [
        'booking_number' => (string)($booking['booking_number'] ?? ''),
        'current_check_in' => (string)($booking['check_in'] ?? ''),
        'current_check_out' => (string)($booking['check_out'] ?? ''),
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare("
        INSERT INTO ars_booking_lifecycle_requests
            (company_id, booking_id, guest_id, request_type, status, guest_note,
             requested_check_in, requested_check_out, requested_time, requested_nights,
             contact_subject, payload_json)
        VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int)$booking['company_id'],
        (int)$booking['id'],
        $guestId,
        $type,
        $guestNote !== '' ? $guestNote : null,
        $requestedCheckIn !== '' ? $requestedCheckIn : null,
        $requestedCheckOut !== '' ? $requestedCheckOut : null,
        $requestedTime !== '' ? $requestedTime : null,
        $requestedNights,
        $contactSubject !== '' ? $contactSubject : null,
        $payloadJson,
    ]);

    $requestId = (int)$conn->lastInsertId();
    return ars_booking_request_by_id($conn, (int)$booking['company_id'], $requestId) ?? [];
}

/**
 * @return list<array<string,mixed>>
 */
function ars_booking_requests_for_booking(PDO $conn, int $companyId, int $bookingId): array {
    ars_booking_requests_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT r.*, COALESCE(u.fullname, u.username) AS reviewed_by_name
        FROM ars_booking_lifecycle_requests r
        LEFT JOIN user u ON u.id = r.reviewed_by
        WHERE r.company_id = ? AND r.booking_id = ?
        ORDER BY r.created_at DESC, r.id DESC
    ");
    $stmt->execute([$companyId, $bookingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string,mixed>|null
 */
function ars_booking_request_by_id(PDO $conn, int $companyId, int $requestId): ?array {
    ars_booking_requests_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT r.*, COALESCE(u.fullname, u.username) AS reviewed_by_name
        FROM ars_booking_lifecycle_requests r
        LEFT JOIN user u ON u.id = r.reviewed_by
        WHERE r.company_id = ? AND r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId, $requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array<string,mixed>
 */
function ars_booking_request_update_status(
    PDO $conn,
    int $companyId,
    int $requestId,
    string $newStatus,
    int $reviewedBy,
    ?string $adminNote = null
): array {
    ars_booking_requests_ensure_schema($conn);
    $allowed = ['approved', 'rejected', 'cancelled'];
    if (!in_array($newStatus, $allowed, true)) {
        throw new InvalidArgumentException('Invalid status transition.');
    }
    $req = ars_booking_request_by_id($conn, $companyId, $requestId);
    if (!$req) {
        throw new RuntimeException('Request not found.');
    }
    if ((string)$req['status'] !== 'pending') {
        throw new RuntimeException('Only pending requests can be updated.');
    }

    $stmt = $conn->prepare("
        UPDATE ars_booking_lifecycle_requests
        SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW(),
            cancelled_at = CASE WHEN ? = 'cancelled' THEN NOW() ELSE cancelled_at END,
            updated_at = NOW()
        WHERE id = ? AND company_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([
        $newStatus,
        $adminNote !== null && trim($adminNote) !== '' ? trim($adminNote) : null,
        $reviewedBy > 0 ? $reviewedBy : null,
        $newStatus,
        $requestId,
        $companyId,
    ]);
    if ($stmt->rowCount() <= 0) {
        throw new RuntimeException('Request could not be updated.');
    }

    return ars_booking_request_by_id($conn, $companyId, $requestId) ?? [];
}

/**
 * Build discount array for repricing from an existing booking row.
 *
 * @param array<string,mixed> $booking
 * @return array<string,mixed>
 */
function ars_booking_discount_from_row(PDO $conn, int $companyId, array $booking): array {
    $dtype = (string)($booking['discount_type'] ?? 'none');
    if ($dtype === '' || $dtype === 'none') {
        return [];
    }

    if ($dtype === 'promo' && !empty($booking['promo_code_id'])) {
        $stmt = $conn->prepare('SELECT * FROM ars_promo_codes WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([(int)$booking['promo_code_id'], $companyId]);
        $pc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pc) {
            return [
                'type' => 'promo',
                'discount_type' => (string)$pc['discount_type'],
                'discount_value' => (float)$pc['discount_value'],
                'max_discount_amount' => $pc['max_discount_amount'] !== null && $pc['max_discount_amount'] !== ''
                    ? (float)$pc['max_discount_amount'] : null,
                'promo_id' => (int)$pc['id'],
                'label' => (string)$pc['code'],
            ];
        }
    }

    if ($dtype === 'manual') {
        $pct = (float)($booking['discount_percent'] ?? 0);
        if ($pct > 0) {
            return [
                'type' => 'manual',
                'discount_type' => 'percentage',
                'discount_value' => $pct,
                'label' => (string)($booking['discount_label'] ?? 'Manual discount'),
            ];
        }
        $amt = (float)($booking['discount_amount'] ?? 0);
        if ($amt > 0) {
            return [
                'type' => 'manual',
                'discount_type' => 'fixed',
                'discount_value' => $amt,
                'label' => (string)($booking['discount_label'] ?? 'Manual discount'),
            ];
        }
    }

    return [];
}

/**
 * Reprice and update booking dates (extension).
 *
 * @param array<string,mixed> $booking
 * @return array<string,mixed>
 */
function ars_booking_apply_date_change(PDO $conn, array $booking, string $newCheckOut): array {
    $bookingId = (int)$booking['id'];
    $companyId = (int)$booking['company_id'];
    $unitId = (int)$booking['unit_id'];
    $checkIn = (string)$booking['check_in'];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newCheckOut) || $newCheckOut <= $checkIn) {
        throw new InvalidArgumentException('Invalid extended check-out date.');
    }

    $nights = max(1, (int)((strtotime($newCheckOut) - strtotime($checkIn)) / 86400));

    $avail = ars_check_availability($conn, $unitId, $checkIn, $newCheckOut, $bookingId);
    if (!$avail['available']) {
        $labels = array_map(static fn(array $c): string => (string)$c['label'], $avail['conflicts']);
        throw new RuntimeException('Unit not available for extended dates: ' . implode(', ', $labels));
    }

    $stmt = $conn->prepare('SELECT nightly_rate, COALESCE(monthly_rate, 0) AS monthly_rate FROM re_units WHERE id = ?');
    $stmt->execute([$unitId]);
    $ur = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $baseRate = (float)($ur['nightly_rate'] ?? 0);
    $monthlyRt = (float)($ur['monthly_rate'] ?? 0);

    $pricingMode = (string)($booking['pricing_mode'] ?? 'nightly');
    $vatMode = (string)($booking['vat_mode'] ?? 'exclusive');
    $rateOverride = isset($booking['rate_override']) && $booking['rate_override'] !== null && $booking['rate_override'] !== ''
        ? (float)$booking['rate_override'] : null;

    $enteredAmount = (float)($booking['entered_amount'] ?? 0);
    if ($pricingMode === 'manual_total' && $enteredAmount > 0) {
        $oldNights = max(1, (int)$booking['nights']);
        $enteredAmount = round($enteredAmount * ($nights / $oldNights), 2);
    }

    $pricing = ars_calculate_booking_price_v3(
        $conn,
        $companyId,
        $unitId,
        $baseRate,
        $monthlyRt,
        $nights,
        $checkIn,
        $newCheckOut,
        $pricingMode === 'nightly' ? $rateOverride : null,
        [],
        (float)($booking['vat_rate'] ?? 5),
        ars_booking_discount_from_row($conn, $companyId, $booking),
        [
            'pricing_mode' => $pricingMode,
            'vat_mode' => $vatMode,
            'entered_amount' => $enteredAmount,
        ]
    );

    if (!empty($pricing['calc_error'])) {
        throw new RuntimeException((string)$pricing['calc_error']);
    }

    $lengthDiscAmt = $pricing['length_discount_amount'] ?? 0;
    $lengthDiscLabel = $pricing['length_discount'] ? ($pricing['length_discount']['rule_name'] ?? null) : null;
    $rulesAppliedJson = !empty($pricing['rules_applied']) ? json_encode($pricing['rules_applied']) : null;

    $conn->prepare("
        UPDATE ars_bookings SET
            check_out = ?, nights = ?,
            nightly_rate = ?, subtotal = ?,
            length_discount_amount = ?, length_discount_label = ?, pricing_rules_applied = ?,
            pricing_mode = ?, vat_mode = ?, entered_amount = ?,
            discount_type = ?, discount_label = ?, discount_percent = ?, discount_amount = ?,
            vat_amount = ?, net_amount = ?, total_amount = ?,
            updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ")->execute([
        $newCheckOut,
        $nights,
        $pricing['effective_rate'],
        $pricing['subtotal'],
        $lengthDiscAmt,
        $lengthDiscLabel,
        $rulesAppliedJson,
        $pricingMode,
        $vatMode,
        $pricing['entered_amount'],
        $pricing['discount_type'] ?? 'none',
        $pricing['discount_label'],
        $pricing['discount_percent'] ?? 0,
        $pricing['discount_amount'] ?? 0,
        $pricing['vat_amount'],
        $pricing['net_amount'],
        $pricing['total_amount'],
        $bookingId,
        $companyId,
    ]);

    ars_recalc_booking_totals($conn, $bookingId);

    $stmt = $conn->prepare('SELECT balance_due, total_amount, paid_amount, payment_status, check_out, nights FROM ars_bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'check_out' => $newCheckOut,
        'nights' => $nights,
        'total_amount' => (float)($fresh['total_amount'] ?? $pricing['total_amount']),
        'balance_due' => (float)($fresh['balance_due'] ?? 0),
        'payment_status' => (string)($fresh['payment_status'] ?? ''),
    ];
}

/**
 * Apply approved lifecycle request to the booking record.
 *
 * @param array<string,mixed> $booking
 * @param array<string,mixed> $request
 * @return array<string,mixed>  Keys: applied (bool), message (string), booking (array|null)
 */
function ars_booking_request_apply_approval(
    PDO $conn,
    array $booking,
    array $request,
    int $userId
): array {
    require_once __DIR__ . '/ars_financial_lock.php';
    $type = (string)($request['request_type'] ?? '');
    $bookingId = (int)$booking['id'];

    if (ars_booking_is_financially_locked($conn, $booking)
        && in_array($type, ['extension', 'early_checkin', 'late_checkout', 'cancellation'], true)
    ) {
        throw new RuntimeException(
            'Financially locked booking: ' . $type . ' requires Booking Amendment (Phase 2 Financial Adapter).'
        );
    }

    switch ($type) {
        case 'extension':
            $newCheckOut = ars_booking_normalize_date($request['requested_check_out'] ?? '');
            if ($newCheckOut === '') {
                throw new RuntimeException('Extension request is missing a check-out date.');
            }
            // Lock unit row for overbooking protection
            $conn->prepare('SELECT id FROM re_units WHERE id = ? FOR UPDATE')->execute([(int)$booking['unit_id']]);
            $result = ars_booking_apply_date_change($conn, $booking, $newCheckOut);
            $balance = (float)$result['balance_due'];
            $msg = 'Your stay extension was approved. New check-out: ' . $newCheckOut . '.';
            if ($balance > 0.009) {
                $msg .= ' Additional amount due: AED ' . number_format($balance, 2)
                    . '. You can pay from your stay details.';
            }
            return [
                'applied' => true,
                'message' => $msg,
                'booking' => $result,
            ];

        case 'cancellation':
            if (!in_array((string)$booking['status'], ['pending', 'confirmed'], true)) {
                throw new RuntimeException('Booking cannot be cancelled in its current status.');
            }
            if (!empty($booking['journal_id'])) {
                require_once __DIR__ . '/ars_accounting.php';
                ars_reverse_booking_journal((int)$booking['journal_id'], $userId);
            }
            $conn->prepare("
                UPDATE ars_bookings
                SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$userId > 0 ? $userId : null, $bookingId]);
            return [
                'applied' => true,
                'message' => 'Your cancellation request was approved. The booking has been cancelled.',
                'booking' => ['status' => 'cancelled'],
            ];

        case 'early_checkin':
            $time = trim((string)($request['requested_time'] ?? ''));
            $line = '[Approved] Early check-in: ' . ($time !== '' ? $time : 'as requested');
            $newCheckIn = ars_booking_normalize_date($request['requested_check_in'] ?? '');
            if ($newCheckIn !== '' && $newCheckIn < (string)$booking['check_out']) {
                $conn->prepare('UPDATE ars_bookings SET check_in = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$newCheckIn, $bookingId]);
            }
            ars_booking_append_special_request($conn, $bookingId, $line);
            return [
                'applied' => true,
                'message' => 'Your early check-in request was approved' . ($time !== '' ? ' for ' . $time : '') . '.',
                'booking' => null,
            ];

        case 'late_checkout':
            $time = trim((string)($request['requested_time'] ?? ''));
            $line = '[Approved] Late check-out: ' . ($time !== '' ? $time : 'as requested');
            ars_booking_append_special_request($conn, $bookingId, $line);
            return [
                'applied' => true,
                'message' => 'Your late check-out request was approved' . ($time !== '' ? ' for ' . $time : '') . '.',
                'booking' => null,
            ];

        case 'support':
            $subject = trim((string)($request['contact_subject'] ?? 'Support'));
            $adminNote = trim((string)($request['admin_note'] ?? ''));
            $line = '[Support resolved] ' . $subject;
            if ($adminNote !== '') {
                $line .= ' — ' . $adminNote;
            }
            ars_booking_append_internal_note($conn, $bookingId, $line);
            return [
                'applied' => true,
                'message' => 'Your support request was resolved. ' . ($adminNote !== '' ? $adminNote : ''),
                'booking' => null,
            ];

        default:
            return [
                'applied' => false,
                'message' => 'Request approved.',
                'booking' => null,
            ];
    }
}

function ars_booking_append_special_request(PDO $conn, int $bookingId, string $line): void {
    $stmt = $conn->prepare('SELECT special_requests FROM ars_bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$bookingId]);
    $existing = trim((string)($stmt->fetchColumn() ?: ''));
    $text = $existing !== '' ? $existing . "\n" . $line : $line;
    $conn->prepare('UPDATE ars_bookings SET special_requests = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$text, $bookingId]);
}

function ars_booking_append_internal_note(PDO $conn, int $bookingId, string $line): void {
    $stmt = $conn->prepare('SELECT internal_notes FROM ars_bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$bookingId]);
    $existing = trim((string)($stmt->fetchColumn() ?: ''));
    $text = $existing !== '' ? $existing . "\n" . $line : $line;
    $conn->prepare('UPDATE ars_bookings SET internal_notes = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$text, $bookingId]);
}

/**
 * Pending unlocked stay-date correction eligibility (BR-ARS-OPS-003 Draft).
 *
 * @param array<string,mixed> $booking
 * @return array{ok:bool,error:?string}
 */
function ars_booking_can_edit_pending_stay_dates(PDO $conn, array $booking): array {
    if (strtolower((string)($booking['status'] ?? '')) !== 'pending') {
        return ['ok' => false, 'error' => 'Only pending bookings can edit stay dates.'];
    }
    if (ars_booking_is_financially_locked($conn, $booking)) {
        return ['ok' => false, 'error' => 'This booking is financially locked. Stay date changes require an amendment.'];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Validate dates, availability, min stay, and compute reprice for pending stay edit.
 *
 * @param array<string,mixed> $booking
 * @return array<string,mixed>
 */
function ars_booking_compute_pending_stay_reprice(
    PDO $conn,
    array $booking,
    string $checkIn,
    string $checkOut
): array {
    $checkIn = ars_booking_normalize_date($checkIn);
    $checkOut = ars_booking_normalize_date($checkOut);
    if ($checkIn === '' || $checkOut === '') {
        throw new InvalidArgumentException('Check-in and check-out dates are required.');
    }
    if ($checkOut <= $checkIn) {
        throw new InvalidArgumentException('Check-out must be after check-in.');
    }

    $nights = max(1, (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    $bookingId = (int)$booking['id'];
    $companyId = (int)$booking['company_id'];
    $unitId = (int)$booking['unit_id'];

    $minStayErr = ars_validate_minimum_stay($conn, $companyId, $unitId, $checkIn, $checkOut, $nights);
    if ($minStayErr) {
        throw new RuntimeException($minStayErr);
    }

    $avail = ars_check_availability($conn, $unitId, $checkIn, $checkOut, $bookingId);
    if (!$avail['available']) {
        $labels = array_map(static fn(array $c): string => (string)$c['label'], $avail['conflicts'] ?? []);
        throw new RuntimeException('Unit not available: ' . implode(', ', $labels));
    }

    $stmt = $conn->prepare('SELECT nightly_rate, COALESCE(monthly_rate, 0) AS monthly_rate FROM re_units WHERE id = ?');
    $stmt->execute([$unitId]);
    $ur = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $baseRate = (float)($ur['nightly_rate'] ?? 0);
    $monthlyRt = (float)($ur['monthly_rate'] ?? 0);

    $pricingMode = (string)($booking['pricing_mode'] ?? 'nightly');
    if (!in_array($pricingMode, ['nightly', 'monthly_package', 'manual_total'], true)) {
        $pricingMode = 'nightly';
    }
    $vatMode = ars_normalize_vat_mode((string)($booking['vat_mode'] ?? 'exclusive'));
    $rateOverride = isset($booking['rate_override']) && $booking['rate_override'] !== null && $booking['rate_override'] !== ''
        ? (float)$booking['rate_override'] : null;

    $enteredAmount = (float)($booking['entered_amount'] ?? 0);
    if ($pricingMode === 'manual_total' && $enteredAmount > 0) {
        $oldNights = max(1, (int)$booking['nights']);
        $enteredAmount = round($enteredAmount * ($nights / $oldNights), 2);
    }

    $pricing = ars_calculate_booking_price_v3(
        $conn,
        $companyId,
        $unitId,
        $baseRate,
        $monthlyRt,
        $nights,
        $checkIn,
        $checkOut,
        $pricingMode === 'nightly' ? $rateOverride : null,
        [],
        (float)($booking['vat_rate'] ?? 5),
        ars_booking_discount_from_row($conn, $companyId, $booking),
        [
            'pricing_mode' => $pricingMode,
            'vat_mode' => $vatMode,
            'entered_amount' => $enteredAmount,
        ]
    );

    if (!empty($pricing['calc_error'])) {
        throw new RuntimeException((string)$pricing['calc_error']);
    }

    return [
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => $nights,
        'pricing_mode' => $pricingMode,
        'vat_mode' => $vatMode,
        'pricing' => $pricing,
        'availability' => $avail,
    ];
}

/**
 * Preview stay date correction for a pending unlocked booking.
 *
 * @param array<string,mixed> $booking
 * @return array<string,mixed>
 */
function ars_booking_preview_pending_stay_dates(
    PDO $conn,
    array $booking,
    string $checkIn,
    string $checkOut
): array {
    $gate = ars_booking_can_edit_pending_stay_dates($conn, $booking);
    if (!$gate['ok']) {
        throw new RuntimeException((string)$gate['error']);
    }

    $computed = ars_booking_compute_pending_stay_reprice($conn, $booking, $checkIn, $checkOut);
    $pricing = $computed['pricing'];

    $oldDeposit = round((float)($booking['deposit_amount'] ?? 0), 2);
    $depositStatus = (string)($booking['deposit_status'] ?? 'none');
    $depositEditable = in_array($depositStatus, ['none', 'pending'], true);

    return [
        'old' => [
            'check_in' => (string)$booking['check_in'],
            'check_out' => (string)$booking['check_out'],
            'nights' => (int)$booking['nights'],
            'subtotal' => round((float)($booking['subtotal'] ?? 0), 2),
            'vat_amount' => round((float)($booking['vat_amount'] ?? 0), 2),
            'total_amount' => round((float)($booking['total_amount'] ?? 0), 2),
            'deposit_amount' => $oldDeposit,
            'deposit_status' => $depositStatus,
        ],
        'new' => [
            'check_in' => $computed['check_in'],
            'check_out' => $computed['check_out'],
            'nights' => $computed['nights'],
            'subtotal' => round((float)($pricing['subtotal'] ?? 0), 2),
            'vat_amount' => round((float)($pricing['vat_amount'] ?? 0), 2),
            'total_amount' => round((float)($pricing['total_amount'] ?? 0), 2),
            'effective_rate' => round((float)($pricing['effective_rate'] ?? 0), 2),
        ],
        'deposit' => [
            'current_amount' => $oldDeposit,
            'status' => $depositStatus,
            'editable' => $depositEditable,
            'keep_default' => true,
        ],
        'available' => true,
    ];
}

/**
 * Apply stay date correction on a pending unlocked booking.
 * Deposit: keep (default) or set new amount when still none/pending.
 *
 * @param array<string,mixed> $booking
 * @return array<string,mixed>
 */
function ars_booking_apply_pending_stay_dates(
    PDO $conn,
    array $booking,
    string $checkIn,
    string $checkOut,
    string $depositMode = 'keep',
    ?float $newDepositAmount = null
): array {
    $gate = ars_booking_can_edit_pending_stay_dates($conn, $booking);
    if (!$gate['ok']) {
        throw new RuntimeException((string)$gate['error']);
    }

    $depositMode = strtolower(trim($depositMode));
    if (!in_array($depositMode, ['keep', 'set'], true)) {
        throw new InvalidArgumentException('Deposit mode must be keep or set.');
    }

    $proposed = [
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => 1,
    ];
    if ($depositMode === 'set') {
        $proposed['deposit_amount'] = max(0, (float)$newDepositAmount);
    }
    ars_assert_financial_edit_allowed($conn, $booking, $proposed);

    $computed = ars_booking_compute_pending_stay_reprice($conn, $booking, $checkIn, $checkOut);
    $pricing = $computed['pricing'];
    $pricingMode = $computed['pricing_mode'];
    $vatMode = $computed['vat_mode'];
    $nights = (int)$computed['nights'];
    $bookingId = (int)$booking['id'];
    $companyId = (int)$booking['company_id'];

    $lengthDiscAmt = $pricing['length_discount_amount'] ?? 0;
    $lengthDiscLabel = $pricing['length_discount'] ? ($pricing['length_discount']['rule_name'] ?? null) : null;
    $rulesAppliedJson = !empty($pricing['rules_applied']) ? json_encode($pricing['rules_applied']) : null;

    $conn->prepare("
        UPDATE ars_bookings SET
            check_in = ?, check_out = ?, nights = ?,
            nightly_rate = ?, subtotal = ?,
            length_discount_amount = ?, length_discount_label = ?, pricing_rules_applied = ?,
            pricing_mode = ?, vat_mode = ?, entered_amount = ?,
            discount_type = ?, discount_label = ?, discount_percent = ?, discount_amount = ?,
            vat_amount = ?, net_amount = ?, total_amount = ?,
            updated_at = NOW()
        WHERE id = ? AND company_id = ? AND status = 'pending'
    ")->execute([
        $computed['check_in'],
        $computed['check_out'],
        $nights,
        $pricing['effective_rate'],
        $pricing['subtotal'],
        $lengthDiscAmt,
        $lengthDiscLabel,
        $rulesAppliedJson,
        $pricingMode,
        $vatMode,
        $pricing['entered_amount'],
        $pricing['discount_type'] ?? 'none',
        $pricing['discount_label'],
        $pricing['discount_percent'] ?? 0,
        $pricing['discount_amount'] ?? 0,
        $pricing['vat_amount'],
        $pricing['net_amount'],
        $pricing['total_amount'],
        $bookingId,
        $companyId,
    ]);

    if ($depositMode === 'set') {
        require_once __DIR__ . '/ars_deposit.php';
        $stmt = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$bookingId, $companyId]);
        $freshForDeposit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$freshForDeposit) {
            throw new RuntimeException('Booking not found after date update.');
        }
        ars_booking_set_security_deposit_amount($conn, $freshForDeposit, max(0, (float)$newDepositAmount));
    }

    ars_recalc_booking_totals($conn, $bookingId);

    $stmt = $conn->prepare('SELECT check_in, check_out, nights, subtotal, vat_amount, total_amount, balance_due, paid_amount, payment_status, deposit_amount, deposit_status FROM ars_bookings WHERE id = ? AND company_id = ?');
    $stmt->execute([$bookingId, $companyId]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'check_in' => (string)($fresh['check_in'] ?? $computed['check_in']),
        'check_out' => (string)($fresh['check_out'] ?? $computed['check_out']),
        'nights' => (int)($fresh['nights'] ?? $nights),
        'total_amount' => round((float)($fresh['total_amount'] ?? $pricing['total_amount']), 2),
        'balance_due' => round((float)($fresh['balance_due'] ?? 0), 2),
        'deposit_amount' => round((float)($fresh['deposit_amount'] ?? 0), 2),
        'deposit_status' => (string)($fresh['deposit_status'] ?? 'none'),
        'deposit_mode' => $depositMode,
        'old' => [
            'check_in' => (string)$booking['check_in'],
            'check_out' => (string)$booking['check_out'],
            'nights' => (int)$booking['nights'],
            'total_amount' => round((float)($booking['total_amount'] ?? 0), 2),
            'deposit_amount' => round((float)($booking['deposit_amount'] ?? 0), 2),
        ],
    ];
}

