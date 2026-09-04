<?php
/**
 * ARS Phase 1 — Financial lock + amendment impact detection foundations.
 * Does not post journals or create Option B financial documents.
 */

require_once __DIR__ . '/ars_activity.php';

/** Columns that cannot be silently changed when financially locked. */
function ars_financial_protected_fields(): array {
    return [
        'unit_id', 'guest_id', 'check_in', 'check_out', 'nights', 'num_guests',
        'nightly_rate', 'rate_override', 'subtotal', 'extras_total',
        'vat_rate', 'vat_amount', 'net_amount', 'total_amount',
        'length_discount_amount', 'discount_type', 'discount_percent', 'discount_amount',
        'promo_code_id', 'pricing_mode', 'vat_mode', 'entered_amount',
        'deposit_amount', 'paid_amount', 'balance_due', 'payment_status',
    ];
}

/** Operational fields always editable without amendment. */
function ars_operational_free_fields(): array {
    return ['special_requests', 'internal_notes'];
}

function ars_booking_has_financial_evidence(PDO $conn, array $booking): bool {
    if (!empty($booking['journal_id'])
        || !empty($booking['deposit_journal_id'])
        || !empty($booking['deposit_refund_journal_id'])
    ) {
        return true;
    }
    if (!empty($booking['is_financially_locked'])) {
        return true;
    }
    $bookingId = (int)($booking['id'] ?? 0);
    $companyId = (int)($booking['company_id'] ?? 0);
    if ($bookingId <= 0 || $companyId <= 0) {
        return false;
    }
    $stmt = $conn->prepare("SELECT 1 FROM ars_booking_payments WHERE booking_id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$bookingId, $companyId]);
    return (bool)$stmt->fetchColumn();
}

function ars_booking_is_financially_locked(PDO $conn, array $booking): bool {
    if (!empty($booking['is_financially_locked'])) {
        return true;
    }
    return ars_booking_has_financial_evidence($conn, $booking);
}

/**
 * Engage financial lock (idempotent). Additive columns must exist (Phase 1 migration).
 */
function ars_booking_engage_financial_lock(
    PDO $conn,
    array $booking,
    string $reason,
    ?int $userId = null,
    ?string $financialStatus = null
): void {
    $bookingId = (int)($booking['id'] ?? 0);
    $companyId = (int)($booking['company_id'] ?? 0);
    if ($bookingId <= 0 || $companyId <= 0) {
        return;
    }
    if (!ars_bookings_financial_columns_ready($conn)) {
        return;
    }

    $status = $financialStatus;
    if ($status === null) {
        $pay = (string)($booking['payment_status'] ?? 'unpaid');
        if ($pay === 'refunded') {
            $status = 'refunded';
        } elseif ($pay === 'paid') {
            $status = 'paid';
        } elseif ($pay === 'partial') {
            $status = 'partially_paid';
        } else {
            $status = 'invoice_created';
        }
    }

    $already = !empty($booking['is_financially_locked']);
    $stmt = $conn->prepare("
        UPDATE ars_bookings SET
            is_financially_locked = 1,
            financial_locked_at = COALESCE(financial_locked_at, NOW()),
            financial_locked_by = COALESCE(financial_locked_by, ?),
            financial_lock_reason = COALESCE(financial_lock_reason, ?),
            financial_status = CASE
                WHEN financial_status = 'draft' OR financial_status IS NULL OR financial_status = '' THEN ?
                ELSE financial_status
            END,
            updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$userId, $reason, $status, $bookingId, $companyId]);

    if (!$already) {
        ars_booking_activity_log($conn, [
            'company_id' => $companyId,
            'booking_id' => $bookingId,
            'booking_number' => $booking['booking_number'] ?? null,
            'event_category' => 'financial',
            'event_type' => 'financial_lock_engaged',
            'title' => 'Financial lock engaged',
            'description' => $reason,
            'new_value' => $status,
            'created_by' => $userId,
        ]);
    }
}

function ars_bookings_financial_columns_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM ars_bookings LIKE 'is_financially_locked'");
        $ready = (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Detect whether proposed field changes have financial impact under lock rules.
 *
 * @param array $current Booking row
 * @param array $proposed Associative field => new value
 * @return array{has_financial_impact:bool,requires_amendment:bool,locked:bool,impacted_fields:array,allowed_fields:array,blocked_fields:array,preview_documents:array,message:?string}
 */
function ars_detect_amendment_financial_impact(PDO $conn, array $current, array $proposed): array {
    $locked = ars_booking_is_financially_locked($conn, $current);
    $protected = array_flip(ars_financial_protected_fields());
    $free = array_flip(ars_operational_free_fields());

    $impacted = [];
    $allowed = [];
    $blocked = [];

    foreach ($proposed as $field => $newVal) {
        if (!array_key_exists($field, $current) && !isset($protected[$field]) && !isset($free[$field])) {
            // Unknown field — treat as operational-safe only if not protected list
            if (isset($protected[$field])) {
                $blocked[] = $field;
                $impacted[] = $field;
            } else {
                $allowed[] = $field;
            }
            continue;
        }
        $old = $current[$field] ?? null;
        if ((string)$old === (string)$newVal) {
            continue;
        }
        if (isset($free[$field])) {
            $allowed[] = $field;
            continue;
        }
        if (isset($protected[$field])) {
            $impacted[] = $field;
            if ($locked) {
                $blocked[] = $field;
            } else {
                $allowed[] = $field;
            }
            continue;
        }
        // Non-listed fields (e.g. status): allowed operationally
        $allowed[] = $field;
    }

    $hasImpact = !empty($impacted);
    $requiresAmendment = $locked && !empty($blocked);

    $preview = [];
    if ($requiresAmendment) {
        if (array_intersect($blocked, ['check_in', 'check_out', 'nights', 'unit_id'])) {
            $preview[] = 'Extension Invoice and/or Credit Note (date/unit change) — Phase 2 adapter';
        }
        if (array_intersect($blocked, ['nightly_rate', 'rate_override', 'subtotal', 'total_amount', 'vat_amount', 'discount_amount', 'entered_amount'])) {
            $preview[] = 'Adjustment Invoice or Credit Note (rate/total change) — Phase 2 adapter';
        }
        if (in_array('deposit_amount', $blocked, true)) {
            $preview[] = 'Security Deposit adjustment document — Phase 2 adapter';
        }
        if (empty($preview)) {
            $preview[] = 'Booking Amendment financial documents — Phase 2 adapter';
        }
    }

    $message = null;
    if ($requiresAmendment) {
        $message = 'This booking is financially locked. Changes to '
            . implode(', ', $blocked)
            . ' require a Booking Amendment. Operational notes may still be edited.';
    }

    return [
        'has_financial_impact' => $hasImpact,
        'requires_amendment' => $requiresAmendment,
        'locked' => $locked,
        'impacted_fields' => array_values(array_unique($impacted)),
        'allowed_fields' => array_values(array_unique($allowed)),
        'blocked_fields' => array_values(array_unique($blocked)),
        'preview_documents' => $preview,
        'message' => $message,
    ];
}

/**
 * Assert proposed money-field edits are allowed; throw RuntimeException if not.
 */
function ars_assert_financial_edit_allowed(PDO $conn, array $booking, array $proposed): array {
    $result = ars_detect_amendment_financial_impact($conn, $booking, $proposed);
    if (!empty($result['requires_amendment'])) {
        throw new RuntimeException($result['message'] ?: 'Financially locked booking requires amendment.');
    }
    return $result;
}
