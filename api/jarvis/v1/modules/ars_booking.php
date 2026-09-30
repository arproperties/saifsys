<?php
/**
 * Jarvis API v1 — creating an ARS booking.
 *
 * Loaded only by ../act.php, after the action key has been checked and the acting user
 * found; opened directly it does nothing. $conn, $in (the JSON body), $action, $actorId,
 * $actorName and the jarvis_api_* helpers come from there.
 *
 *   quote   the price saifsys would charge for this stay, with every check the booking
 *           page makes (unit, guest, dates, minimum stay, availability). Saves nothing.
 *   create  the same checks again inside a transaction with the unit locked, the price
 *           compared with the one the person approved (expected_total), then the booking.
 *
 * The create is a copy of modules/ars/booking_add.php's POST handler, calling the same
 * helpers (pricing, availability, booking number, activity, notifications, audit). If
 * that page changes how a booking is made, change this too.
 *
 * The body:
 *   unit_id, check_in, check_out (YYYY-MM-DD, check_out = the day they leave)
 *   guest_id  — an existing guest — or new_guest {first_name, last_name, phone, email, nationality}
 *   num_guests
 *   pricing_mode  nightly (default) | monthly_package | manual_total
 *   rate          nightly only: a nightly rate instead of the unit's
 *   total         manual_total only: the stay's amount
 *   vat_mode      exclusive (default) | inclusive | none
 *   discount      {type: percentage|fixed, value}
 *   deposit, special_requests, internal_notes
 *   historical, historical_status (confirmed|checked_in|checked_out|completed) — a past stay
 *   expected_total  create only: the total on the card the person approved
 */

declare(strict_types=1);

if (!defined('JARVIS_ACT')) {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 4);
require_once $root . '/modules/ars/includes/ars_helpers.php';
require_once $root . '/modules/ars/includes/ars_availability.php';
require_once $root . '/modules/ars/includes/ars_pricing.php';
require_once $root . '/modules/ars/includes/ars_activity.php';
require_once $root . '/modules/ars/includes/ars_guest_notifications.php';
require_once $root . '/includes/AuditService.php';

// ---------- reading the request ----------

function jarvis_bk_fail(string $code, string $message, int $status = 400, array $extra = []): void
{
    jarvis_api_error($code, $message, $status, $extra);
}

function jarvis_bk_date($v, string $name): string
{
    $v = trim((string)$v);
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        jarvis_bk_fail('bad_date', "$name must be YYYY-MM-DD.");
    }
    return $v;
}

function jarvis_bk_text($v, int $max): string
{
    return mb_substr(trim((string)($v ?? '')), 0, $max);
}

/** The ARS company, found the way getArsCompanyId() finds it. */
function jarvis_bk_company(PDO $conn): int
{
    $id = (int)($conn->query("SELECT id FROM companies WHERE code = 'ARS' AND is_active = 1 LIMIT 1")->fetchColumn() ?: 0);
    if ($id === 0) {
        jarvis_bk_fail('no_ars_company', 'No active company with code ARS.', 500);
    }
    return $id;
}

/** The unit, if ARS may use it — ars_assert_unit_usable_for_ars()'s rule. */
function jarvis_bk_unit(PDO $conn, int $cid, int $unitId): array
{
    $s = $conn->prepare("SELECT u.*, bd.name AS building_name FROM re_units u LEFT JOIN re_buildings bd ON bd.id = u.building_id
        WHERE u.id = ? AND u.rental_mode IN ('short_term','both')
          AND (u.company_id = ? OR u.id IN (SELECT DISTINCT unit_id FROM ars_bookings WHERE company_id = ?)
               OR NOT EXISTS (SELECT 1 FROM re_units ux WHERE ux.company_id = ? AND ux.rental_mode IN ('short_term','both') LIMIT 1))
        LIMIT 1");
    $s->execute([$unitId, $cid, $cid, $cid]);
    $u = $s->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        jarvis_bk_fail('bad_unit', 'That unit is not available for ARS short-term use.');
    }
    return $u;
}

/** Everything the booking needs, checked, in booking_add.php's terms. */
function jarvis_bk_read(PDO $conn, int $cid, array $in): array
{
    $unit = jarvis_bk_unit($conn, $cid, (int)($in['unit_id'] ?? 0));
    $checkIn = jarvis_bk_date($in['check_in'] ?? '', 'check_in');
    $checkOut = jarvis_bk_date($in['check_out'] ?? '', 'check_out');
    if ($checkOut <= $checkIn) {
        jarvis_bk_fail('bad_dates', 'Check-out must be after check-in.');
    }
    $nights = (int)(new DateTime($checkOut))->diff(new DateTime($checkIn))->days;

    $guest = null;
    $newGuest = null;
    if (!empty($in['guest_id'])) {
        $s = $conn->prepare('SELECT id, first_name, last_name, phone, email FROM ars_guests WHERE id = ? AND company_id = ?');
        $s->execute([(int)$in['guest_id'], $cid]);
        $guest = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$guest) {
            jarvis_bk_fail('bad_guest', 'That guest is not in ARS.');
        }
    } else {
        $g = is_array($in['new_guest'] ?? null) ? $in['new_guest'] : [];
        $newGuest = [
            'first_name'  => jarvis_bk_text($g['first_name'] ?? '', 100),
            'last_name'   => jarvis_bk_text($g['last_name'] ?? '', 100),
            'phone'       => jarvis_bk_text($g['phone'] ?? '', 50),
            'email'       => jarvis_bk_text($g['email'] ?? '', 200),
            'nationality' => jarvis_bk_text($g['nationality'] ?? '', 100),
        ];
        if ($newGuest['first_name'] === '' || $newGuest['last_name'] === '') {
            jarvis_bk_fail('bad_guest', 'A new guest needs a first and last name.');
        }
    }

    $mode = (string)($in['pricing_mode'] ?? 'nightly');
    if (!in_array($mode, ['nightly', 'monthly_package', 'manual_total'], true)) {
        jarvis_bk_fail('bad_pricing_mode', 'pricing_mode must be nightly, monthly_package or manual_total.');
    }
    $rate = (isset($in['rate']) && $in['rate'] !== '' && $in['rate'] !== null) ? (float)$in['rate'] : null;
    if ($rate !== null && $rate < 0) {
        jarvis_bk_fail('bad_rate', 'The rate cannot be negative.');
    }
    $total = max(0, (float)($in['total'] ?? 0));
    if ($mode === 'manual_total' && $total <= 0) {
        jarvis_bk_fail('bad_total', 'A manual total needs the amount.');
    }
    $discount = is_array($in['discount'] ?? null) ? $in['discount'] : [];
    $discType = in_array($discount['type'] ?? '', ['percentage', 'fixed'], true) ? $discount['type'] : '';
    $discVal = max(0, (float)($discount['value'] ?? 0));
    $status = (string)($in['historical_status'] ?? 'confirmed');

    return [
        'unit'           => $unit,
        'check_in'       => $checkIn,
        'check_out'      => $checkOut,
        'nights'         => $nights,
        'guest'          => $guest,
        'new_guest'      => $newGuest,
        'num_guests'     => max(1, (int)($in['num_guests'] ?? 1)),
        'pricing_mode'   => $mode,
        'rate'           => $mode === 'nightly' ? $rate : null,
        'total'          => $total,
        'vat_mode'       => ars_normalize_vat_mode((string)($in['vat_mode'] ?? 'exclusive')),
        'discount_type'  => $discVal > 0 ? $discType : '',
        'discount_value' => $discVal,
        'deposit'        => max(0, (float)($in['deposit'] ?? 0)),
        'special'        => jarvis_bk_text($in['special_requests'] ?? '', 2000),
        'internal'       => jarvis_bk_text($in['internal_notes'] ?? '', 2000),
        'historical'     => !empty($in['historical']),
        'historical_status' => in_array($status, ['confirmed', 'checked_in', 'checked_out', 'completed'], true) ? $status : 'confirmed',
    ];
}

/** booking_add.php's minimum stay and availability checks; historical stays skip both. */
function jarvis_bk_check(PDO $conn, int $cid, array $b): void
{
    if ($b['historical']) {
        return;
    }
    $minStay = ars_validate_minimum_stay($conn, $cid, (int)$b['unit']['id'], $b['check_in'], $b['check_out'], $b['nights']);
    if ($minStay) {
        jarvis_bk_fail('minimum_stay', $minStay);
    }
    $avail = ars_check_availability($conn, (int)$b['unit']['id'], $b['check_in'], $b['check_out']);
    if (!$avail['available']) {
        jarvis_bk_fail('unavailable', 'Unit not available: ' . implode(', ', array_map(fn($c) => $c['label'], $avail['conflicts'])), 409);
    }
}

/** The price, exactly as booking_add.php asks for it. */
function jarvis_bk_price(PDO $conn, int $cid, array $b, array $settings): array
{
    $s = $conn->prepare('SELECT nightly_rate, COALESCE(monthly_rate, 0) AS monthly_rate FROM re_units WHERE id = ?');
    $s->execute([(int)$b['unit']['id']]);
    $ur = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    $discount = [];
    if ($b['discount_type']) {
        $discount = [
            'type' => 'manual', 'discount_type' => $b['discount_type'], 'discount_value' => $b['discount_value'],
            'label' => 'Manual ' . ($b['discount_type'] === 'percentage' ? $b['discount_value'] . '%' : 'AED ' . number_format($b['discount_value'], 2)),
        ];
    }
    $pricing = ars_calculate_booking_price_v3(
        $conn, $cid, (int)$b['unit']['id'],
        (float)($ur['nightly_rate'] ?? 0), (float)($ur['monthly_rate'] ?? 0),
        $b['nights'], $b['check_in'], $b['check_out'],
        $b['rate'], [], (float)$settings['default_vat_rate'], $discount,
        ['pricing_mode' => $b['pricing_mode'], 'vat_mode' => $b['vat_mode'], 'entered_amount' => $b['total'], 'skip_length_discount' => true]
    );
    if (!empty($pricing['calc_error'])) {
        jarvis_bk_fail('pricing', (string)$pricing['calc_error']);
    }
    return $pricing;
}

/** What the card shows. */
function jarvis_bk_quote(array $b, array $pricing, array $settings, string $actorName): array
{
    $u = $b['unit'];
    $guest = $b['guest']
        ? ['guest_id' => (int)$b['guest']['id'], 'name' => trim($b['guest']['first_name'] . ' ' . $b['guest']['last_name']), 'phone' => $b['guest']['phone'], 'new' => false]
        : ['name' => trim($b['new_guest']['first_name'] . ' ' . $b['new_guest']['last_name']), 'phone' => $b['new_guest']['phone'] ?: null,
           'email' => $b['new_guest']['email'] ?: null, 'nationality' => $b['new_guest']['nationality'] ?: null, 'new' => true];
    $warnings = [];
    if (isset($u['max_guests']) && (int)$u['max_guests'] > 0 && $b['num_guests'] > (int)$u['max_guests']) {
        $warnings[] = "The unit takes up to {$u['max_guests']} guests.";
    }
    return [
        'unit' => [
            'unit_id'      => (int)$u['id'],
            'unit'         => $u['unit_number'],
            'building'     => $u['building_name'],
            'nightly_rate' => (float)($u['nightly_rate'] ?? 0),
            'monthly_rate' => (float)($u['monthly_rate'] ?? 0),
            'max_guests'   => isset($u['max_guests']) ? (int)$u['max_guests'] : null,
        ],
        'guest'      => $guest,
        'check_in'   => $b['check_in'],
        'check_out'  => $b['check_out'],
        'nights'     => $b['nights'],
        'num_guests' => $b['num_guests'],
        'price' => [
            'pricing_mode'    => $b['pricing_mode'],
            'rate'            => round((float)$pricing['effective_rate'], 2),
            'custom_rate'     => $b['rate'] !== null,
            'subtotal'        => round((float)$pricing['subtotal'], 2),
            'discount'        => round((float)($pricing['discount_amount'] ?? 0), 2),
            'discount_label'  => $pricing['discount_label'] ?? null,
            'vat_mode'        => $b['vat_mode'],
            'vat_rate'        => (float)$pricing['vat_rate'],
            'vat_amount'      => round((float)$pricing['vat_amount'], 2),
            'total'           => round((float)$pricing['total_amount'], 2),
            'currency'        => $settings['currency'] ?? 'AED',
        ],
        'deposit'          => $b['deposit'],
        'special_requests' => $b['special'] ?: null,
        'internal_notes'   => $b['internal'] ?: null,
        'historical'       => $b['historical'] ? $b['historical_status'] : null,
        'starts_as'        => $b['historical'] ? $b['historical_status'] : 'pending',
        'pending_expiry_hours' => $b['historical'] ? null : (int)$settings['pending_expiry_hours'],
        'created_by'       => $actorName,
        'warnings'         => $warnings,
    ];
}

$cid = jarvis_bk_company($conn);
$settings = getArsSettings($conn, $cid);

// ---------- quote ----------

if ($action === 'quote') {
    $b = jarvis_bk_read($conn, $cid, $in);
    jarvis_bk_check($conn, $cid, $b);
    $pricing = jarvis_bk_price($conn, $cid, $b, $settings);
    jarvis_api_send(['ok' => true, 'quote' => jarvis_bk_quote($b, $pricing, $settings, $actorName)]);
}

// ---------- create ----------

if ($action === 'create') {
    if (!isset($in['expected_total']) || !is_numeric($in['expected_total'])) {
        jarvis_bk_fail('no_expected_total', 'expected_total — the total the person approved — is required.');
    }
    $b = jarvis_bk_read($conn, $cid, $in);
    $userId = $actorId;

    $conn->beginTransaction();
    try {
        $conn->prepare('SELECT id FROM re_units WHERE id = ? FOR UPDATE')->execute([(int)$b['unit']['id']]);
        jarvis_bk_check($conn, $cid, $b); // again, now that nobody else can book this unit
        $pricing = jarvis_bk_price($conn, $cid, $b, $settings);
        if (abs(round((float)$pricing['total_amount'], 2) - round((float)$in['expected_total'], 2)) > 0.009) {
            $conn->rollBack();
            jarvis_bk_fail('price_changed', 'The price changed since it was approved. Nothing was created.', 409,
                ['quote' => jarvis_bk_quote($b, $pricing, $settings, $actorName)]);
        }

        // A new guest, the way the Guests page adds one (no ID details).
        if ($b['new_guest']) {
            $g = $b['new_guest'];
            $conn->prepare('INSERT INTO ars_guests (company_id, first_name, last_name, email, phone, nationality) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$cid, $g['first_name'], $g['last_name'], $g['email'] ?: null, $g['phone'] ?: null, $g['nationality'] ?: null]);
            $guestId = (int)$conn->lastInsertId();
        } else {
            $guestId = (int)$b['guest']['id'];
        }

        // From here: booking_add.php's POST handler.
        $unitId = (int)$b['unit']['id'];
        $checkIn = $b['check_in'];
        $checkOut = $b['check_out'];
        $nights = $b['nights'];
        $numGuests = $b['num_guests'];
        $pricingMode = $b['pricing_mode'];
        $vatMode = $b['vat_mode'];
        $rateOverride = $b['rate'];
        $depositAmt = $b['deposit'];
        $specialReqs = $b['special'];
        $internalNote = $b['internal'];
        $isHistorical = $b['historical'];
        $historicalStatus = $b['historical_status'];

        $bookingNumber = generateBookingNumber($conn, $cid);
        $newStatus     = $isHistorical ? $historicalStatus : 'pending';
        $expiresAt     = $newStatus === 'pending'
            ? date('Y-m-d H:i:s', strtotime('+' . (int) $settings['pending_expiry_hours'] . ' hours'))
            : null;
        $depStatus = $depositAmt > 0 ? 'pending' : 'none';

        $lengthDiscAmt    = $pricing['length_discount_amount'] ?? 0;
        $lengthDiscLabel  = !empty($pricing['length_discount']) ? ($pricing['length_discount']['rule_name'] ?? null) : null;
        $rulesAppliedJson = !empty($pricing['rules_applied']) ? json_encode($pricing['rules_applied']) : null;

        $bDiscountType    = $pricing['discount_type'] ?? 'none';
        $bDiscountLabel   = $pricing['discount_label'] ?? null;
        $bDiscountPercent = $pricing['discount_percent'] ?? 0;
        $bDiscountAmount  = $pricing['discount_amount'] ?? 0;
        $bPromoCodeId     = $pricing['discount_promo_id'] ?? null;

        $enteredStored = ($pricing['entered_amount'] ?? null) !== null ? $pricing['entered_amount'] : null;

        $conn->prepare("
            INSERT INTO ars_bookings
                (company_id, unit_id, guest_id, booking_number, check_in, check_out, nights, num_guests,
                 status, nightly_rate, rate_override, subtotal, extras_total, vat_rate, vat_amount, net_amount,
                 length_discount_amount, length_discount_label, pricing_rules_applied,
                 pricing_mode, vat_mode, entered_amount,
                 discount_type, discount_label, discount_percent, discount_amount, promo_code_id,
                 total_amount, paid_amount, balance_due, payment_status, expires_at,
                 deposit_amount, deposit_status, is_historical,
                 special_requests, internal_notes, created_by)
            VALUES (
                ?,?,?,?,?,?,?,?,
                ?,?,?,?,0.00,?,?,?,
                ?,?,?,
                ?,?,?,
                ?,?,?,?,?,
                ?,?,?, 'unpaid', ?,
                ?,?,?,?,?,?
            )
        ")->execute([
            $cid, $unitId, $guestId, $bookingNumber, $checkIn, $checkOut, $nights, $numGuests,
            $newStatus,
            $pricing['effective_rate'],
            $pricingMode === 'nightly' ? $rateOverride : null,
            $pricing['subtotal'],
            $pricing['vat_rate'],
            $pricing['vat_amount'],
            $pricing['net_amount'],
            $lengthDiscAmt, $lengthDiscLabel, $rulesAppliedJson,
            $pricingMode, $vatMode, $enteredStored,
            $bDiscountType, $bDiscountLabel, $bDiscountPercent, $bDiscountAmount, $bPromoCodeId ?: null,
            $pricing['total_amount'], 0.00, $pricing['total_amount'], $expiresAt,
            $depositAmt, $depStatus,
            $isHistorical ? 1 : 0,
            $specialReqs ?: null, $internalNote ?: null, $userId,
        ]);
        $newBookingId = (int) $conn->lastInsertId();

        try {
            AuditService::logEvent([
                'action' => 'booking_created',
                'module' => 'ars',
                'company_id' => $cid,
                'object_type' => 'ars_bookings',
                'object_id' => (string)$newBookingId,
                'object_ref' => $bookingNumber ?: ('Booking #' . $newBookingId),
                'summary' => 'Created booking ' . ($bookingNumber ?: ('#' . $newBookingId)) . ' via Jarvis',
                'user_id' => $userId,
                'source' => 'user',
                'success' => true,
            ]);
        } catch (Throwable $ignored) {}

        if ($isHistorical && $newStatus === 'completed') {
            $conn->prepare("
                UPDATE ars_guests SET total_bookings = total_bookings + 1, total_spent = total_spent + ?
                WHERE id = ? AND company_id = ?
            ")->execute([(float) $pricing['total_amount'], $guestId, $cid]);
        }

        if ($isHistorical && in_array($newStatus, ['confirmed', 'checked_in', 'checked_out', 'completed'], true)) {
            require_once $root . '/modules/ars/includes/ars_accounting.php';
            require_once $root . '/modules/ars/includes/ars_financial_lock.php';
            $bRowStmt = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? AND company_id = ?');
            $bRowStmt->execute([$newBookingId, $cid]);
            $bookingRow = $bRowStmt->fetch(PDO::FETCH_ASSOC);
            if ($bookingRow) {
                $jr = ars_post_booking_revenue($conn, $bookingRow, $userId);
                if (empty($jr['success'])) {
                    throw new RuntimeException($jr['error'] ?? 'Could not post revenue journal.');
                }
                $bRowStmt->execute([$newBookingId, $cid]);
                $bookingRow = $bRowStmt->fetch(PDO::FETCH_ASSOC) ?: $bookingRow;
                ars_booking_engage_financial_lock($conn, $bookingRow, 'Historical booking revenue posted', $userId, 'invoice_created');
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        jarvis_bk_fail('create_failed', 'saifsys could not create the booking: ' . $e->getMessage(), 500);
    }

    ars_booking_activity_log($conn, [
        'company_id' => $cid,
        'booking_id' => $newBookingId,
        'booking_number' => $bookingNumber,
        'event_category' => 'operational',
        'event_type' => 'booking_created',
        'title' => 'Booking created via Jarvis',
        'new_value' => $newStatus,
        'created_by' => $userId,
    ]);
    if (!$isHistorical) {
        try {
            $bNotify = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? LIMIT 1');
            $bNotify->execute([$newBookingId]);
            $bookingNotify = $bNotify->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($bookingNotify) {
                ars_guest_notification_create($conn, [
                    'company_id' => $cid,
                    'guest_id' => $guestId,
                    'booking_id' => $newBookingId,
                    'event_type' => 'booking_created',
                    'title' => 'Booking created',
                    'message' => 'Your booking ' . $bookingNumber . ' has been created.',
                    'cta_route' => '/guest/bookings/' . $newBookingId,
                ]);
                ars_guest_notifications_schedule_booking_reminders($conn, $bookingNotify);
            }
        } catch (Throwable $e) {
            error_log('ARS booking created notification failed (Jarvis): ' . $e->getMessage());
        }
    }

    jarvis_api_send(['ok' => true, 'booking' => [
        'booking_id'     => $newBookingId,
        'booking_number' => $bookingNumber,
        'status'         => $newStatus,
        'expires_at'     => $expiresAt,
        'total'          => round((float)$pricing['total_amount'], 2),
        'guest_id'       => $guestId,
        'created_by'     => $actorName,
    ]]);
}

jarvis_bk_fail('not_found', 'Unknown booking action.', 404);
