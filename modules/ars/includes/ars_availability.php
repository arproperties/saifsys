<?php
/**
 * ARS Home Rentals — Availability & Overlap Validation
 */

require_once __DIR__ . '/ars_early_checkout.php';

/**
 * Occupancy end date for availability (exclusive, same as check_out convention).
 * Early checkout frees the unit from actual_check_out onward (same-day turnaround OK).
 */
function ars_booking_occupancy_end_sql(): string {
    return 'COALESCE(actual_check_out, check_out)';
}

/**
 * Check if a unit is available for the given date range.
 * Must be called INSIDE a transaction with FOR UPDATE lock on the unit row
 * for race-condition safety.
 *
 * @param PDO    $conn
 * @param int    $unitId
 * @param string $checkIn   YYYY-MM-DD
 * @param string $checkOut  YYYY-MM-DD
 * @param int|null $excludeBookingId  Booking to exclude (for editing)
 * @return array ['available' => bool, 'conflicts' => [...]]
 */
function ars_check_availability(PDO $conn, int $unitId, string $checkIn, string $checkOut, ?int $excludeBookingId = null): array {
    ars_early_checkout_ensure_schema($conn);
    $conflicts = [];
    $occEnd = ars_booking_occupancy_end_sql();

    // 1. Check overlapping bookings (use actual departure when early checkout was recorded)
    $sql = "
        SELECT id, booking_number, check_in, check_out, actual_check_out, is_early_checkout, status,
               {$occEnd} AS occupancy_end
        FROM ars_bookings
        WHERE unit_id = ?
          AND status NOT IN ('cancelled','expired')
          AND check_in < ?
          AND {$occEnd} > ?
    ";
    $params = [$unitId, $checkOut, $checkIn];
    if ($excludeBookingId) {
        $sql .= " AND id != ?";
        $params[] = $excludeBookingId;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $end = (string)($r['occupancy_end'] ?? $r['check_out']);
        $label = $r['booking_number'] . ' (' . $r['status'] . ')';
        if (!empty($r['is_early_checkout']) || (!empty($r['actual_check_out']) && (string)$r['actual_check_out'] !== (string)$r['check_out'])) {
            $label .= ' · left ' . $end;
        }
        $conflicts[] = [
            'type' => 'booking',
            'id' => $r['id'],
            'label' => $label,
            'start' => $r['check_in'],
            'end' => $end,
        ];
    }

    // 2. Check blocked dates
    $stmt = $conn->prepare("
        SELECT id, start_date, end_date, reason
        FROM ars_blocked_dates
        WHERE unit_id = ?
          AND start_date < ?
          AND end_date > ?
    ");
    $stmt->execute([$unitId, $checkOut, $checkIn]);
    $blocked = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($blocked as $b) {
        $conflicts[] = [
            'type' => 'blocked',
            'id' => $b['id'],
            'label' => 'Blocked: ' . ($b['reason'] ?: 'N/A'),
            'start' => $b['start_date'],
            'end' => $b['end_date'],
        ];
    }

    return [
        'available' => empty($conflicts),
        'conflicts' => $conflicts,
    ];
}

/**
 * Get availability map for a unit in a given month.
 * Returns an associative array: date => status string.
 */
function ars_unit_month_availability(PDO $conn, int $unitId, int $year, int $month): array {
    ars_early_checkout_ensure_schema($conn);
    $firstDay = sprintf('%04d-%02d-01', $year, $month);
    $lastDay  = date('Y-m-t', strtotime($firstDay));
    $map = [];
    $occEndSql = ars_booking_occupancy_end_sql();

    // Fill default = available
    $d = new DateTime($firstDay);
    $end = new DateTime($lastDay);
    $end->modify('+1 day');
    while ($d < $end) {
        $map[$d->format('Y-m-d')] = 'available';
        $d->modify('+1 day');
    }

    // Overlay bookings (occupancy ends at actual_check_out when set)
    $stmt = $conn->prepare("
        SELECT id, booking_number, check_in, check_out, actual_check_out, is_early_checkout, status, guest_id,
               {$occEndSql} AS occupancy_end
        FROM ars_bookings
        WHERE unit_id = ?
          AND status NOT IN ('cancelled','expired')
          AND check_in <= ?
          AND {$occEndSql} >= ?
        ORDER BY check_in
    ");
    $stmt->execute([$unitId, $lastDay, $firstDay]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bookings as $b) {
        $occEnd = (string)($b['occupancy_end'] ?? $b['check_out']);
        $bs = max($firstDay, $b['check_in']);
        $be = min($lastDay, date('Y-m-d', strtotime($occEnd . ' -1 day')));
        if ($bs > $be) {
            continue;
        }
        $d = new DateTime($bs);
        $endD = new DateTime($be);
        $endD->modify('+1 day');
        $isPending = ($b['status'] === 'pending');
        $lastNight = date('Y-m-d', strtotime($occEnd . ' -1 day'));
        while ($d < $endD) {
            $ds = $d->format('Y-m-d');
            if ($isPending) {
                $map[$ds] = 'pending';
            } elseif ($ds === $b['check_in']) {
                $map[$ds] = 'check-in';
            } elseif ($ds === $lastNight) {
                $map[$ds] = 'check-out';
            } else {
                $map[$ds] = 'booked';
            }
            $d->modify('+1 day');
        }
        if (!isset($map['_bookings'])) $map['_bookings'] = [];
        $map['_bookings'][] = $b;
    }

    // Overlay blocked dates
    $stmt = $conn->prepare("
        SELECT start_date, end_date, reason
        FROM ars_blocked_dates
        WHERE unit_id = ?
          AND start_date <= ?
          AND end_date >= ?
    ");
    $stmt->execute([$unitId, $lastDay, $firstDay]);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($blocks as $bl) {
        $bs = max($firstDay, $bl['start_date']);
        $be = min($lastDay, $bl['end_date']);
        $d = new DateTime($bs);
        $endD = new DateTime($be);
        while ($d <= $endD) {
            $map[$d->format('Y-m-d')] = 'maintenance';
            $d->modify('+1 day');
        }
    }

    return $map;
}
