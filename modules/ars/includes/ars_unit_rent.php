<?php
/**
 * ARS units with size and current rent — one place for the rules, used by
 * modules/ars/unit_rent_report.php (screen + PDF) and the Jarvis API
 * (api/jarvis/v1/modules/ars.php, action=unit_rents).
 *
 * Read-only. Standalone on purpose (no session, no auth, no other ARS includes)
 * so the Jarvis API can load it.
 *
 * Rules:
 *  - The units are the ARS units: rental_mode short_term or both, same company
 *    scope as ars_short_term_units_where().
 *  - "Rented" = a checked-in or confirmed booking whose stay covers today
 *    (check-in on or before today, leaving after today; an early departure counts).
 *  - Rent per month = that booking's nightly rate x 30, to the whole dirham.
 *  - "Today" is Dubai's date, not the server's (live PHP and MySQL run on UTC).
 */

if (!function_exists('ars_unit_rent_today')) {

function ars_unit_rent_today(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
}

function ars_unit_rent_type_label(?string $type): string
{
    $map = [
        'studio' => 'Studio', '1br' => '1 Bedroom', '2br' => '2 Bedroom', '3br' => '3 Bedroom',
        '4br' => '4 Bedroom', 'penthouse' => 'Penthouse',
    ];
    $type = (string)$type;
    return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
}

/** The buildings that hold ARS units, for the filter: [id => name]. */
function ars_unit_rent_buildings(PDO $conn, int $cid): array
{
    $st = $conn->prepare("
        SELECT DISTINCT bl.id, bl.name
        FROM re_units u
        JOIN re_buildings bl ON bl.id = u.building_id
        WHERE u.rental_mode IN ('short_term','both')
          AND (u.company_id = ?
               OR u.id IN (SELECT DISTINCT unit_id FROM ars_bookings WHERE company_id = ?)
               OR NOT EXISTS (SELECT 1 FROM re_units ux WHERE ux.company_id = ? AND ux.rental_mode IN ('short_term','both') LIMIT 1))
        ORDER BY bl.name
    ");
    $st->execute([$cid, $cid, $cid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = (string)$r['name'];
    }
    return $out;
}

/**
 * The rows. $buildingId 0 = every building. $status '' (all), 'rented' or 'vacant'.
 * Each row: unit_id, unit, premise_no, building_id, building, type, type_label,
 * size_sqft, status (rented|vacant), rent_month, booking_id, booking_number, check_out.
 */
function ars_unit_rent_rows(PDO $conn, int $cid, int $buildingId = 0, string $status = '', ?string $today = null): array
{
    $today = $today ?: ars_unit_rent_today();
    $sql = "
        SELECT u.id AS unit_id, u.unit_number, u.premises_number, u.unit_type, u.unit_size_sqft,
               u.building_id, bl.name AS building_name,
               cur.id AS booking_id, cur.booking_number, cur.nightly_rate,
               COALESCE(cur.actual_check_out, cur.check_out) AS stay_end
        FROM re_units u
        LEFT JOIN re_buildings bl ON bl.id = u.building_id
        LEFT JOIN ars_bookings cur ON cur.id = (
            SELECT b.id FROM ars_bookings b
            WHERE b.unit_id = u.id
              AND b.status IN ('checked_in','confirmed')
              AND b.check_in <= ?
              AND COALESCE(b.actual_check_out, b.check_out) > ?
            ORDER BY b.check_in DESC, b.id DESC
            LIMIT 1
        )
        WHERE u.rental_mode IN ('short_term','both')
          AND (u.company_id = ?
               OR u.id IN (SELECT DISTINCT unit_id FROM ars_bookings WHERE company_id = ?)
               OR NOT EXISTS (SELECT 1 FROM re_units ux WHERE ux.company_id = ? AND ux.rental_mode IN ('short_term','both') LIMIT 1))
    ";
    $params = [$today, $today, $cid, $cid, $cid];
    if ($buildingId > 0) {
        $sql .= " AND u.building_id = ?";
        $params[] = $buildingId;
    }
    $st = $conn->prepare($sql);
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rented = !empty($r['booking_id']);
        if (($status === 'rented' && !$rented) || ($status === 'vacant' && $rented)) {
            continue;
        }
        $rows[] = [
            'unit_id'        => (int)$r['unit_id'],
            'unit'           => (string)$r['unit_number'],
            'premise_no'     => (string)($r['premises_number'] ?? ''),
            'building_id'    => (int)$r['building_id'],
            'building'       => (string)($r['building_name'] ?? ''),
            'type'           => (string)$r['unit_type'],
            'type_label'     => ars_unit_rent_type_label($r['unit_type']),
            'size_sqft'      => $r['unit_size_sqft'] !== null ? round((float)$r['unit_size_sqft'], 2) : null,
            'status'         => $rented ? 'rented' : 'vacant',
            'rent_month'     => $rented ? round((float)$r['nightly_rate'] * 30) : null,
            'booking_id'     => $rented ? (int)$r['booking_id'] : null,
            'booking_number' => $rented ? (string)$r['booking_number'] : null,
            'check_out'      => $rented ? (string)$r['stay_end'] : null,
        ];
    }
    // Building, then unit number the way people read it (109 before 1001, "P 04" first).
    usort($rows, static function (array $a, array $b): int {
        return [$a['building'], (int)$a['unit'] === 0 ? 0 : 1, (int)$a['unit'], $a['unit']]
            <=> [$b['building'], (int)$b['unit'] === 0 ? 0 : 1, (int)$b['unit'], $b['unit']];
    });
    return $rows;
}

/** Totals for a set of rows. */
function ars_unit_rent_totals(array $rows): array
{
    $t = ['units' => count($rows), 'rented' => 0, 'vacant' => 0, 'size_sqft' => 0.0, 'rent_month' => 0.0];
    foreach ($rows as $r) {
        $t[$r['status']]++;
        $t['size_sqft'] += (float)$r['size_sqft'];
        $t['rent_month'] += (float)$r['rent_month'];
    }
    $t['size_sqft'] = round($t['size_sqft'], 2);
    return $t;
}

}
