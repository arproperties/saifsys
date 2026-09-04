<?php
/**
 * AJAX endpoint: check availability + get pricing for a unit + dates.
 * Returns JSON: { available, nights, subtotal, vat, total, effective_nightly, rules[], min_stay_error }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/includes/portal_auth.php';
require_once __DIR__ . '/../modules/ars/includes/ars_availability.php';
require_once __DIR__ . '/../modules/ars/includes/ars_pricing.php';

$unitId   = (int)($_GET['unit_id'] ?? 0);
$checkIn  = trim($_GET['check_in'] ?? '');
$checkOut = trim($_GET['check_out'] ?? '');
$promo    = trim($_GET['promo_code'] ?? '');

if (!$unitId || !$checkIn || !$checkOut || $checkIn >= $checkOut) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$arsCompanyId = getArsCompanyId($conn);
$settings     = getArsSettings($conn, $arsCompanyId);
$vatRate      = (float)($settings['default_vat_rate'] ?? 5);

$availResult = ars_check_availability($conn, $unitId, $checkIn, $checkOut);
if (!$availResult['available']) {
    echo json_encode([
        'available' => false,
        'reason'    => 'Unit is not available for these dates.',
        'conflicts' => $availResult['conflicts'],
    ]);
    exit;
}

$nights = max(1, (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400));
$minStay = ars_validate_minimum_stay($conn, $arsCompanyId, $unitId, $checkIn, $checkOut, $nights);
if ($minStay !== null) {
    echo json_encode(['available' => false, 'reason' => $minStay, 'min_stay_error' => true]);
    exit;
}

$unit = $conn->prepare("SELECT nightly_rate, COALESCE(monthly_rate, 0) AS monthly_rate FROM re_units WHERE id = ? LIMIT 1");
$unit->execute([$unitId]);
$urow  = $unit->fetch(PDO::FETCH_ASSOC);
$rate  = (float)($urow['nightly_rate'] ?? 0);
$mRate = (float)($urow['monthly_rate'] ?? 0);

// Base subtotal (nightly sum before length/promo discounts) — same rule as admin promo validation
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
            'type'                 => 'promo',
            'discount_type'        => $p['discount_type'],
            'discount_value'       => (float)$p['discount_value'],
            'max_discount_amount'  => $p['max_discount_amount'] !== null && $p['max_discount_amount'] !== ''
                ? (float)$p['max_discount_amount'] : null,
            'promo_id'             => (int)$p['id'],
            'label'                 => $p['code'],
        ];
    } else {
        $promoError = $promoResult['error'] ?? 'Invalid promo code.';
    }
}

$pricing = ars_calculate_booking_price_v2(
    $conn, $arsCompanyId, $unitId, $rate, $nights, $checkIn, $checkOut,
    null, [], $vatRate, $discount
);

$totalNum      = (float) ($pricing['total_amount'] ?? 0);
$avgIncl       = $nights > 0 ? ($totalNum / $nights) : 0.0;
$monthlyIncl   = $mRate > 0 ? round($mRate * (1 + $vatRate / 100), 2) : 0.0;

echo json_encode([
    'available'          => true,
    'nights'             => $nights,
    'effective_nightly'  => number_format((float)($pricing['effective_rate'] ?? 0), 2, '.', ''),
    'avg_nightly_incl'   => number_format($avgIncl, 2, '.', ''),
    'subtotal'           => number_format($pricing['subtotal'], 2, '.', ''),
    'discount_amount'    => number_format($pricing['discount_amount'] ?? 0, 2, '.', ''),
    'vat'                => number_format($pricing['vat_amount'], 2, '.', ''),
    'total'              => number_format($pricing['total_amount'], 2, '.', ''),
    'monthly_rate_incl'  => $monthlyIncl > 0 ? number_format($monthlyIncl, 2, '.', '') : null,
    'rules_applied'      => $pricing['rules_applied'] ?? [],
    'promo_valid'        => !empty($discount),
    'promo_error'        => $promoError,
]);
