<?php
/**
 * Phase B stay routes — expects PDO $conn, ARS helpers, ars_availability, ars_pricing loaded.
 */

declare(strict_types=1);

/**
 * @return array<string,mixed>
 */
function customer_api_stay_settings(PDO $conn): array {
    require_once dirname(__DIR__, 3) . '/modules/ars/includes/ars_helpers.php';
    $companyId = getArsCompanyId($conn);
    $settings = getArsSettings($conn, $companyId);
    $stmt = $conn->prepare(
        "SELECT id, name FROM companies WHERE code = 'ARS' AND is_active = 1 LIMIT 1"
    );
    $stmt->execute();
    $co = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $companyId, 'name' => 'ARS'];

    return [
        'company_id' => (int)($co['id'] ?? $companyId),
        'currency' => (string)($settings['currency'] ?? 'AED'),
        'vat_rate' => (float)($settings['default_vat_rate'] ?? 5),
        'display_name' => (string)($co['name'] ?? 'ARS'),
    ];
}

/**
 * @return array{buildings: list<string>}
 */
function customer_api_stay_buildings(PDO $conn): array {
    $bStmt = $conn->prepare("
        SELECT DISTINCT b.name FROM re_units u
        JOIN re_buildings b ON b.id = u.building_id
        WHERE u.is_listed = 1 AND u.rental_mode IN ('short_term','both')
        ORDER BY b.name
    ");
    $bStmt->execute();
    $names = $bStmt->fetchAll(PDO::FETCH_COLUMN);
    $buildings = array_values(array_filter(array_map('strval', $names)));

    return ['buildings' => $buildings];
}

function customer_api_stay_date_ok(?string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
}

/**
 * Quote inner object for list/detail (aligned with B.5 + legacy ajax keys).
 *
 * @return array<string,mixed>
 */
function customer_api_stay_compute_quote(
    PDO $conn,
    int $arsCompanyId,
    float $vatRate,
    int $unitId,
    string $checkIn,
    string $checkOut,
    string $promo = ''
): array {
    require_once dirname(__DIR__, 3) . '/modules/ars/includes/ars_availability.php';
    require_once dirname(__DIR__, 3) . '/modules/ars/includes/ars_pricing.php';

    $availResult = ars_check_availability($conn, $unitId, $checkIn, $checkOut);
    if (!$availResult['available']) {
        return [
            'available' => false,
            'reason' => 'Unit is not available for these dates.',
            'conflicts' => $availResult['conflicts'] ?? [],
        ];
    }

    $nights = max(1, (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    $minStay = ars_validate_minimum_stay($conn, $arsCompanyId, $unitId, $checkIn, $checkOut, $nights);
    if ($minStay !== null) {
        return [
            'available' => false,
            'reason' => $minStay,
            'min_stay_error' => true,
            'conflicts' => [],
        ];
    }

    $unit = $conn->prepare(
        'SELECT nightly_rate, COALESCE(monthly_rate, 0) AS monthly_rate FROM re_units WHERE id = ? LIMIT 1'
    );
    $unit->execute([$unitId]);
    $urow = $unit->fetch(PDO::FETCH_ASSOC);
    $rate = (float)($urow['nightly_rate'] ?? 0);
    $mRate = (float)($urow['monthly_rate'] ?? 0);

    $baseSubtotal = 0.0;
    $nb = ars_get_nightly_breakdown($conn, $arsCompanyId, $unitId, $checkIn, $checkOut, $rate);
    foreach ($nb as $n) {
        $baseSubtotal += (float)($n['rate'] ?? 0);
    }
    $baseSubtotal = round($baseSubtotal, 2);

    $discount = [];
    $promoError = null;
    if ($promo !== '') {
        $promoResult = ars_validate_promo_code($conn, $arsCompanyId, $promo, $nights, $baseSubtotal);
        if ($promoResult['valid']) {
            $p = $promoResult['promo'];
            $discount = [
                'type' => 'promo',
                'discount_type' => $p['discount_type'],
                'discount_value' => (float)$p['discount_value'],
                'max_discount_amount' => $p['max_discount_amount'] !== null && $p['max_discount_amount'] !== ''
                    ? (float)$p['max_discount_amount'] : null,
                'promo_id' => (int)$p['id'],
                'label' => $p['code'],
            ];
        } else {
            $promoError = $promoResult['error'] ?? 'Invalid promo code.';
        }
    }

    $pricing = ars_calculate_booking_price_v2(
        $conn,
        $arsCompanyId,
        $unitId,
        $rate,
        $nights,
        $checkIn,
        $checkOut,
        null,
        [],
        $vatRate,
        $discount
    );

    $totalNum = (float)($pricing['total_amount'] ?? 0);
    $avgIncl = $nights > 0 ? ($totalNum / $nights) : 0.0;
    $monthlyIncl = $mRate > 0 ? round($mRate * (1 + $vatRate / 100), 2) : 0.0;
    $settingsRow = getArsSettings($conn, $arsCompanyId);
    $currency = (string)($settingsRow['currency'] ?? 'AED');

    $rules = $pricing['rules_applied'] ?? [];
    $eff = (float)($pricing['effective_rate'] ?? 0);
    $sub = (float)$pricing['subtotal'];
    $vatAmt = (float)$pricing['vat_amount'];
    $tot = (float)$pricing['total_amount'];

    return [
        'available' => true,
        'nights' => $nights,
        'subtotal' => round($sub, 2),
        'vat' => round($vatAmt, 2),
        'total' => round($tot, 2),
        'effective_nightly' => round($eff, 2),
        'rules' => $rules,
        'currency' => $currency,
        'vat_rate' => (float)$vatRate,
        'avg_nightly_incl' => round($avgIncl, 2),
        'discount_amount' => round((float)($pricing['discount_amount'] ?? 0), 2),
        'monthly_rate_incl' => $monthlyIncl > 0 ? $monthlyIncl : null,
        'rules_applied' => $rules,
        'promo_valid' => !empty($discount),
        'promo_error' => $promoError,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function customer_api_stay_units_rows(
    PDO $conn,
    string $buildingFilter,
    ?string $checkIn,
    ?string $checkOut,
    int $guests
): array {
    require_once dirname(__DIR__, 3) . '/modules/ars/includes/ars_helpers.php';
    require_once dirname(__DIR__, 3) . '/modules/ars/includes/ars_availability.php';
    require_once dirname(__DIR__, 3) . '/modules/ars/includes/ars_pricing.php';

    $arsCompanyId = getArsCompanyId($conn);
    $settings = getArsSettings($conn, $arsCompanyId);
    $vatRate = (float)($settings['default_vat_rate'] ?? 5);
    $currency = $settings['currency'] ?? 'AED';

    $hasSearch = $checkIn && $checkOut && $checkIn < $checkOut
        && customer_api_stay_date_ok($checkIn) && customer_api_stay_date_ok($checkOut);

    $sql = "
        SELECT u.id, u.unit_number, u.unit_type, u.area_sqm,
               u.nightly_rate, COALESCE(u.monthly_rate, 0) AS monthly_rate,
               u.listing_title, u.short_description, u.amenities_json,
               u.max_guests, u.rental_mode,
               b.name AS building_name, b.address AS building_address,
               (SELECT file_path FROM ars_unit_photos WHERE unit_id = u.id AND is_primary = 1 LIMIT 1) AS primary_photo
        FROM re_units u
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE u.is_listed = 1
          AND u.rental_mode IN ('short_term','both')
    ";
    $params = [];

    if ($buildingFilter !== '') {
        $sql .= ' AND b.name = ?';
        $params[] = $buildingFilter;
    }

    if ($hasSearch && $guests > 0) {
        $sql .= ' AND (u.max_guests >= ? OR u.max_guests IS NULL OR u.max_guests = 0)';
        $params[] = $guests;
    }

    $sql .= $hasSearch ? ' ORDER BY u.nightly_rate ASC' : ' ORDER BY b.name, u.unit_number';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$hasSearch) {
        return array_map(
            function (array $u) use ($currency, $vatRate) {
                return customer_api_stay_map_unit_summary($u, $currency, $vatRate, null);
            },
            $rows
        );
    }

    $nights = max(1, (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    $out = [];
    foreach ($rows as $u) {
        $uid = (int)$u['id'];
        $avail = ars_check_availability($conn, $uid, $checkIn, $checkOut);
        if (!$avail['available']) {
            continue;
        }
        if (ars_validate_minimum_stay($conn, $arsCompanyId, $uid, $checkIn, $checkOut, $nights) !== null) {
            continue;
        }
        $quote = customer_api_stay_compute_quote(
            $conn,
            $arsCompanyId,
            $vatRate,
            $uid,
            $checkIn,
            $checkOut,
            ''
        );
        if (empty($quote['available'])) {
            continue;
        }
        $out[] = customer_api_stay_map_unit_summary($u, $currency, $vatRate, $quote);
    }

    return $out;
}

/**
 * @param array<string,mixed>|null $quote
 * @return array<string,mixed>
 */
function customer_api_stay_map_unit_summary(array $u, string $currency, float $vatRate, ?array $quote): array {
    $amenities = json_decode($u['amenities_json'] ?: '[]', true);
    if (!is_array($amenities)) {
        $amenities = [];
    }
    $photoPath = trim((string)($u['primary_photo'] ?? ''));
    $primaryUrl = $photoPath !== ''
        ? customer_api_absolute_url(customer_api_public_path_url($photoPath))
        : null;

    return [
        'id' => (int)$u['id'],
        'unit_number' => (string)($u['unit_number'] ?? ''),
        'unit_type' => (string)($u['unit_type'] ?? ''),
        'area_sqm' => $u['area_sqm'] !== null && $u['area_sqm'] !== '' ? (float)$u['area_sqm'] : null,
        'listing_title' => (string)($u['listing_title'] ?? ''),
        'short_description' => (string)($u['short_description'] ?? ''),
        'building_name' => (string)($u['building_name'] ?? ''),
        'building_address' => (string)($u['building_address'] ?? ''),
        'nightly_rate' => (float)($u['nightly_rate'] ?? 0),
        'monthly_rate' => (float)($u['monthly_rate'] ?? 0),
        'max_guests' => (int)($u['max_guests'] ?? 0),
        'rental_mode' => (string)($u['rental_mode'] ?? 'short_term'),
        'primary_photo_url' => $primaryUrl,
        'amenities' => array_values(array_map('strval', $amenities)),
        'currency' => $currency,
        'vat_rate' => $vatRate,
        'quote' => $quote,
    ];
}

/**
 * @return array<string,mixed>|null
 */
function customer_api_stay_unit_detail(PDO $conn, int $unitId, int $calYear, int $calMonth): ?array {
    require_once dirname(__DIR__, 3) . '/modules/ars/includes/ars_availability.php';

    $stmt = $conn->prepare("
        SELECT u.*, b.name AS building_name, b.address AS building_address
        FROM re_units u
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE u.id = ? AND u.is_listed = 1 AND u.rental_mode IN ('short_term','both')
        LIMIT 1
    ");
    $stmt->execute([$unitId]);
    $unit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$unit) {
        return null;
    }

    $photosStmt = $conn->prepare(
        'SELECT * FROM ars_unit_photos WHERE unit_id = ? ORDER BY is_primary DESC, sort_order, id'
    );
    $photosStmt->execute([$unitId]);
    $photoRows = $photosStmt->fetchAll(PDO::FETCH_ASSOC);

    $amenities = json_decode($unit['amenities_json'] ?: '[]', true);
    if (!is_array($amenities)) {
        $amenities = [];
    }

    $unitOut = [
        'id' => (int)$unit['id'],
        'unit_number' => (string)($unit['unit_number'] ?? ''),
        'unit_type' => (string)($unit['unit_type'] ?? ''),
        'area_sqm' => $unit['area_sqm'] !== null && $unit['area_sqm'] !== '' ? (float)$unit['area_sqm'] : null,
        'listing_title' => (string)($unit['listing_title'] ?? ''),
        'short_description' => (string)($unit['short_description'] ?? ''),
        'building_name' => (string)($unit['building_name'] ?? ''),
        'building_address' => (string)($unit['building_address'] ?? ''),
        'nightly_rate' => (float)($unit['nightly_rate'] ?? 0),
        'monthly_rate' => (float)($unit['monthly_rate'] ?? 0),
        'max_guests' => (int)($unit['max_guests'] ?? 0),
        'rental_mode' => (string)($unit['rental_mode'] ?? ''),
    ];

    $photos = [];
    foreach ($photoRows as $ph) {
        $fp = (string)($ph['file_path'] ?? '');
        $photos[] = [
            'id' => (int)($ph['id'] ?? 0),
            'file_path' => $fp,
            'url' => $fp !== '' ? customer_api_absolute_url(customer_api_public_path_url($fp)) : '',
            'is_primary' => (bool)($ph['is_primary'] ?? false),
            'sort_order' => (int)($ph['sort_order'] ?? 0),
        ];
    }

    $calMap = ars_unit_month_availability($conn, $unitId, $calYear, $calMonth);
    $days = [];
    foreach ($calMap as $dayNum => $status) {
        $days[(string)(int)$dayNum] = (string)$status;
    }

    return [
        'unit' => $unitOut,
        'photos' => $photos,
        'amenities' => array_values(array_map('strval', $amenities)),
        'calendar' => [
            'year' => $calYear,
            'month' => $calMonth,
            'days' => $days,
        ],
    ];
}
