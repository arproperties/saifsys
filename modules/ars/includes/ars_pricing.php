<?php
/**
 * ARS Home Rentals — Pricing Engine (Phase 2.1: rule-based)
 *
 * Calculation order:
 *   1. Per-night rate (seasonal rules → weekend rules → base rate)
 *   2. Manual rate override replaces ALL rules for ALL nights
 *   3. Subtotal = sum of per-night rates
 *   4. Length-of-stay discount (auto-applied)
 *   5. Extras
 *   6. VAT on (discounted subtotal + extras)
 *   7. Total
 */

/**
 * Fetch active pricing rules applicable to a unit and date range.
 */
function ars_get_applicable_rules(PDO $conn, int $companyId, ?int $unitId, string $checkIn, string $checkOut): array {
    $stmt = $conn->prepare("
        SELECT * FROM ars_pricing_rules
        WHERE company_id = ? AND is_active = 1
          AND (unit_id IS NULL OR unit_id = ?)
        ORDER BY priority DESC, id ASC
    ");
    $stmt->execute([$companyId, $unitId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Validate minimum-stay rules. Returns error string or null if OK.
 */
function ars_validate_minimum_stay(PDO $conn, int $companyId, ?int $unitId, string $checkIn, string $checkOut, int $nights): ?string {
    $rules = ars_get_applicable_rules($conn, $companyId, $unitId, $checkIn, $checkOut);
    foreach ($rules as $r) {
        if ($r['rule_type'] !== 'minimum_stay') continue;
        $minN = (int)$r['min_nights'];
        if ($minN <= 0) continue;

        $applies = true;
        if ($r['start_date'] && $r['end_date']) {
            $ruleStart = $r['start_date'];
            $ruleEnd   = $r['end_date'];
            $applies = ($checkIn <= $ruleEnd && $checkOut > $ruleStart);
        }
        if ($applies && $nights < $minN) {
            $label = $r['name'] ?: 'Minimum stay rule';
            $period = ($r['start_date'] && $r['end_date'])
                ? ' (' . $r['start_date'] . ' to ' . $r['end_date'] . ')'
                : '';
            return "$label: minimum $minN nights required$period.";
        }
    }
    return null;
}

/**
 * Build per-night rate breakdown considering pricing rules.
 * Returns array of ['date' => 'YYYY-MM-DD', 'rate' => float, 'rule_name' => string|null]
 */
function ars_get_nightly_breakdown(PDO $conn, int $companyId, ?int $unitId, string $checkIn, string $checkOut, float $baseRate): array {
    $rules = ars_get_applicable_rules($conn, $companyId, $unitId, $checkIn, $checkOut);

    $seasonalRules = [];
    $weekendRules  = [];
    foreach ($rules as $r) {
        if ($r['rule_type'] === 'seasonal') $seasonalRules[] = $r;
        if ($r['rule_type'] === 'weekend')  $weekendRules[]  = $r;
    }

    $breakdown = [];
    $current = new DateTime($checkIn);
    $end     = new DateTime($checkOut);

    while ($current < $end) {
        $dateStr  = $current->format('Y-m-d');
        $dayOfWeek = (int)$current->format('N'); // 5=Fri, 6=Sat
        $nightRate = $baseRate;
        $ruleName  = null;

        // 1. Check seasonal rules (already sorted by priority DESC)
        foreach ($seasonalRules as $sr) {
            if ($sr['start_date'] && $sr['end_date'] && $dateStr >= $sr['start_date'] && $dateStr <= $sr['end_date']) {
                if ($sr['rate_amount'] !== null && (float)$sr['rate_amount'] > 0) {
                    $nightRate = (float)$sr['rate_amount'];
                } elseif ($sr['rate_modifier'] !== null) {
                    $nightRate = round($baseRate * (1 + (float)$sr['rate_modifier'] / 100), 2);
                }
                $ruleName = $sr['name'];
                break; // highest-priority seasonal wins
            }
        }

        // 2. Weekend rules (only if no seasonal rule matched)
        if ($ruleName === null && in_array($dayOfWeek, [5, 6]) && !empty($weekendRules)) {
            $wr = $weekendRules[0]; // highest priority
            if ($wr['rate_amount'] !== null && (float)$wr['rate_amount'] > 0) {
                $nightRate = (float)$wr['rate_amount'];
            } elseif ($wr['rate_modifier'] !== null) {
                $nightRate = round($baseRate * (1 + (float)$wr['rate_modifier'] / 100), 2);
            }
            $ruleName = $wr['name'];
        }

        $breakdown[] = [
            'date'      => $dateStr,
            'rate'      => $nightRate,
            'rule_name' => $ruleName,
            'day_name'  => $current->format('D'),
        ];
        $current->modify('+1 day');
    }

    return $breakdown;
}

/**
 * Find the best length-of-stay discount for a given number of nights.
 * Returns ['discount_percent' => float, 'rule_name' => string] or null.
 */
function ars_get_length_discount(PDO $conn, int $companyId, ?int $unitId, int $nights): ?array {
    $stmt = $conn->prepare("
        SELECT * FROM ars_pricing_rules
        WHERE company_id = ? AND is_active = 1 AND rule_type = 'length_discount'
          AND (unit_id IS NULL OR unit_id = ?)
          AND min_nights IS NOT NULL AND min_nights <= ?
        ORDER BY min_nights DESC, priority DESC
        LIMIT 1
    ");
    $stmt->execute([$companyId, $unitId, $nights]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r || (float)$r['discount_percent'] <= 0) return null;
    return [
        'discount_percent' => (float)$r['discount_percent'],
        'rule_name'        => $r['name'] ?: ($r['min_nights'] . '+ nights discount'),
        'min_nights'       => (int)$r['min_nights'],
    ];
}

/**
 * Validate and fetch a promo code. Returns promo row or null.
 */
function ars_validate_promo_code(PDO $conn, int $companyId, string $code, int $nights, float $subtotal): array {
    $stmt = $conn->prepare("SELECT * FROM ars_promo_codes WHERE company_id = ? AND code = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$companyId, strtoupper(trim($code))]);
    $promo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$promo) return ['valid' => false, 'error' => 'Promo code not found or inactive.'];
    if ($promo['max_uses'] !== null && (int)$promo['times_used'] >= (int)$promo['max_uses']) {
        return ['valid' => false, 'error' => 'Promo code has reached its usage limit.'];
    }
    $today = date('Y-m-d');
    if ($promo['valid_from'] && $today < $promo['valid_from']) {
        return ['valid' => false, 'error' => 'Promo code is not yet valid (starts ' . $promo['valid_from'] . ').'];
    }
    if ($promo['valid_to'] && $today > $promo['valid_to']) {
        return ['valid' => false, 'error' => 'Promo code has expired.'];
    }
    if ($promo['min_nights'] && $nights < (int)$promo['min_nights']) {
        return ['valid' => false, 'error' => 'Minimum ' . $promo['min_nights'] . ' nights required for this code.'];
    }
    if ($promo['min_amount'] && $subtotal < (float)$promo['min_amount']) {
        return ['valid' => false, 'error' => 'Minimum subtotal of AED ' . number_format((float)$promo['min_amount'], 2) . ' required.'];
    }

    return ['valid' => true, 'promo' => $promo];
}

/**
 * Increment promo code usage counter.
 */
function ars_increment_promo_usage(PDO $conn, int $promoId): void {
    $conn->prepare("UPDATE ars_promo_codes SET times_used = times_used + 1 WHERE id = ?")->execute([$promoId]);
}

/**
 * Calculate the discount amount for a promo code or manual discount.
 * @param float|null $maxCap  Maximum discount cap (NULL = no cap)
 */
function ars_calc_discount_amount(string $type, float $value, float $subtotal, ?float $maxCap = null): float {
    if ($type === 'percentage') {
        $raw = round($subtotal * $value / 100, 2);
    } else {
        $raw = round(min($value, $subtotal), 2);
    }
    if ($maxCap !== null && $maxCap > 0 && $raw > $maxCap) {
        $raw = round($maxCap, 2);
    }
    return $raw;
}

/**
 * Extract VAT from a VAT-inclusive gross amount (total unchanged).
 * vat = round(gross * rate / (100 + rate), 2); net = gross - vat.
 *
 * @return array{gross:float,vat:float,net:float}
 */
function ars_extract_vat_from_inclusive_gross(float $gross, float $vatRate): array {
    $gross = round($gross, 2);
    $vat   = round($gross * $vatRate / (100 + $vatRate), 2);
    $net   = round($gross - $vat, 2);
    return ['gross' => $gross, 'vat' => $vat, 'net' => $net];
}

/**
 * @return 'exclusive'|'inclusive'|'none'
 */
function ars_normalize_vat_mode(string $vatMode): string {
    $m = strtolower(trim($vatMode));
    if ($m === 'inclusive') {
        return 'inclusive';
    }
    if ($m === 'none' || $m === 'no_vat') {
        return 'none';
    }
    return 'exclusive';
}

/**
 * Apply VAT mode to room + extras.
 *
 * @param float  $roomAmt   Exclusive/none: net room after discounts. Inclusive: gross room (VAT in price); extras always net with VAT on top.
 * @param float  $extrasNet Sum of extra charge nets (taxable)
 * @param string $vatMode   exclusive|inclusive|none
 */
function ars_finalize_booking_totals(float $roomAmt, float $extrasNet, string $vatMode, float $vatRate): array {
    $vatMode   = ars_normalize_vat_mode($vatMode);
    $extrasNet = round($extrasNet, 2);
    $r         = $vatRate;

    if ($vatMode === 'none') {
        $roomNet     = round($roomAmt, 2);
        $taxableNet  = round($roomNet + $extrasNet, 2);

        return [
            'room_net_component' => $roomNet,
            'taxable_net'        => $taxableNet,
            'net_amount'         => $taxableNet,
            'vat_amount'         => 0.0,
            'total_amount'       => $taxableNet,
        ];
    }

    if ($vatMode === 'exclusive') {
        $roomNet     = round($roomAmt, 2);
        $taxableNet  = round($roomNet + $extrasNet, 2);
        $vatAmount   = round($taxableNet * ($r / 100), 2);
        $totalAmount = round($taxableNet + $vatAmount, 2);

        return [
            'room_net_component' => $roomNet,
            'taxable_net'        => $taxableNet,
            'net_amount'         => $taxableNet,
            'vat_amount'         => $vatAmount,
            'total_amount'       => $totalAmount,
        ];
    }

    $roomGross = round($roomAmt, 2);
    $roomVat   = round($roomGross * $r / (100 + $r), 2);
    $roomNet   = round($roomGross - $roomVat, 2);
    $extrasVat = round($extrasNet * ($r / 100), 2);
    $vatAmount = round($roomVat + $extrasVat, 2);
    $totalAmount = round($roomGross + $extrasNet + $extrasVat, 2);
    $taxableNet  = round($roomNet + $extrasNet, 2);

    return [
        'room_net_component' => $roomNet,
        'taxable_net'        => $taxableNet,
        'net_amount'         => $taxableNet,
        'vat_amount'         => $vatAmount,
        'total_amount'       => $totalAmount,
    ];
}

/**
 * Calculate booking price with full rule engine + discount support.
 *
 * @param PDO        $conn           DB connection
 * @param int        $companyId      ARS company ID
 * @param int|null   $unitId         Unit ID
 * @param float      $baseNightlyRate Unit's base nightly rate
 * @param int        $nights         Number of nights
 * @param string     $checkIn        YYYY-MM-DD
 * @param string     $checkOut       YYYY-MM-DD
 * @param float|null $rateOverride   Manual override per night
 * @param array      $extras         Extra charges
 * @param float      $vatRate        VAT %
 * @param array      $discount       Optional: ['type'=>'promo'|'manual','discount_type'=>'percentage'|'fixed','discount_value'=>float,'promo_code'=>string,'promo_id'=>int,'label'=>string]
 * @return array     Pricing breakdown
 */
function ars_calculate_booking_price_v2(
    PDO $conn,
    int $companyId,
    ?int $unitId,
    float $baseNightlyRate,
    int $nights,
    string $checkIn,
    string $checkOut,
    ?float $rateOverride,
    array $extras,
    float $vatRate,
    array $discount = []
): array {
    $hasManualOverride = ($rateOverride !== null && $rateOverride > 0);
    $rulesApplied = [];

    if ($hasManualOverride) {
        $subtotal = round($rateOverride * $nights, 2);
        $effectiveRate = $rateOverride;
        $nightlyBreakdown = [];
    } else {
        $nightlyBreakdown = ars_get_nightly_breakdown($conn, $companyId, $unitId, $checkIn, $checkOut, $baseNightlyRate);
        $subtotal = 0.0;
        $ruleNames = [];
        foreach ($nightlyBreakdown as $nb) {
            $subtotal += $nb['rate'];
            if ($nb['rule_name'] && !in_array($nb['rule_name'], $ruleNames)) {
                $ruleNames[] = $nb['rule_name'];
            }
        }
        $subtotal = round($subtotal, 2);
        $effectiveRate = $nights > 0 ? round($subtotal / $nights, 2) : $baseNightlyRate;
        $rulesApplied = $ruleNames;
    }

    // Length-of-stay discount (auto)
    $lengthDiscount = null;
    $lengthDiscountAmount = 0.0;
    if (!$hasManualOverride) {
        $lengthDiscount = ars_get_length_discount($conn, $companyId, $unitId, $nights);
        if ($lengthDiscount) {
            $lengthDiscountAmount = round($subtotal * $lengthDiscount['discount_percent'] / 100, 2);
            $rulesApplied[] = $lengthDiscount['rule_name'];
        }
    }

    $afterLengthDiscount = round($subtotal - $lengthDiscountAmount, 2);

    // Manual / promo discount (applied on top of length discount)
    $discountType   = $discount['type'] ?? 'none';
    $discountDType  = $discount['discount_type'] ?? '';
    $discountValue  = (float)($discount['discount_value'] ?? 0);
    $discountLabel  = $discount['label'] ?? null;
    $discountPromoId = (int)($discount['promo_id'] ?? 0);
    $maxDiscountCap = isset($discount['max_discount_amount']) && $discount['max_discount_amount'] !== null
        ? (float)$discount['max_discount_amount'] : null;
    $discountAmount = 0.0;
    $discountPercent = 0.0;

    if ($discountType !== 'none' && $discountValue > 0) {
        $discountAmount = ars_calc_discount_amount($discountDType, $discountValue, $afterLengthDiscount, $maxDiscountCap);
        if ($discountDType === 'percentage') {
            $discountPercent = $discountValue;
        }
    }

    $discountedSubtotal = round($afterLengthDiscount - $discountAmount, 2);

    $extrasTotal = 0.0;
    foreach ($extras as $e) {
        $extrasTotal += round((float)($e['amount'] ?? $e['total'] ?? 0), 2);
    }

    $fin = ars_finalize_booking_totals($discountedSubtotal, $extrasTotal, 'exclusive', $vatRate);

    return [
        'effective_rate'         => $effectiveRate,
        'base_rate'              => $baseNightlyRate,
        'nights'                 => $nights,
        'subtotal'               => $subtotal,
        'length_discount'        => $lengthDiscount,
        'length_discount_amount' => $lengthDiscountAmount,
        'discount_type'          => $discountType,
        'discount_discount_type' => $discountDType,
        'discount_value'         => $discountValue,
        'discount_percent'       => $discountPercent,
        'discount_amount'        => $discountAmount,
        'discount_label'         => $discountLabel,
        'discount_promo_id'      => $discountPromoId,
        'discounted_subtotal'    => $discountedSubtotal,
        'extras_total'           => $extrasTotal,
        'vat_rate'               => $vatRate,
        'vat_amount'             => $fin['vat_amount'],
        'total_amount'           => $fin['total_amount'],
        'taxable_net'            => $fin['taxable_net'],
        'net_amount'             => $fin['net_amount'],
        'pricing_mode'           => 'nightly',
        'vat_mode'               => 'exclusive',
        'entered_amount'         => null,
        'has_manual_override'    => $hasManualOverride,
        'nightly_breakdown'      => $nightlyBreakdown,
        'rules_applied'          => $rulesApplied,
        'calc_error'             => null,
    ];
}

/**
 * Full pricing: nightly (same as v2), monthly package, or manual total; optional inclusive VAT on room portion.
 *
 * $opts keys: pricing_mode (nightly|monthly_package|manual_total), vat_mode (exclusive|inclusive|none),
 *             entered_amount (required for manual_total).
 */
function ars_calculate_booking_price_v3(
    PDO $conn,
    int $companyId,
    ?int $unitId,
    float $baseNightlyRate,
    float $monthlyRate,
    int $nights,
    string $checkIn,
    string $checkOut,
    ?float $rateOverride,
    array $extras,
    float $vatRate,
    array $discount = [],
    array $opts = []
): array {
    $pricingMode   = $opts['pricing_mode'] ?? 'nightly';
    $vatMode       = ars_normalize_vat_mode((string)($opts['vat_mode'] ?? 'exclusive'));
    $enteredAmount = isset($opts['entered_amount']) ? (float)$opts['entered_amount'] : 0.0;

    if ($pricingMode === 'manual_total' && $enteredAmount <= 0) {
        return [
            'calc_error' => 'Enter a valid manual total amount.',
            'effective_rate' => 0, 'base_rate' => $baseNightlyRate, 'nights' => $nights,
            'subtotal' => 0, 'length_discount' => null, 'length_discount_amount' => 0,
            'discount_type' => 'none', 'discount_discount_type' => '', 'discount_value' => 0,
            'discount_percent' => 0, 'discount_amount' => 0, 'discount_label' => null,
            'discount_promo_id' => 0, 'discounted_subtotal' => 0, 'extras_total' => 0,
            'vat_rate' => $vatRate, 'vat_amount' => 0, 'total_amount' => 0,
            'taxable_net' => 0, 'net_amount' => 0, 'pricing_mode' => 'manual_total',
            'vat_mode' => $vatMode, 'entered_amount' => $enteredAmount,
            'has_manual_override' => false, 'nightly_breakdown' => [], 'rules_applied' => [],
        ];
    }

    if ($pricingMode === 'monthly_package' && $monthlyRate <= 0) {
        return [
            'calc_error' => 'Set a monthly rate on the unit or choose nightly pricing.',
            'effective_rate' => 0, 'base_rate' => $baseNightlyRate, 'nights' => $nights,
            'subtotal' => 0, 'length_discount' => null, 'length_discount_amount' => 0,
            'discount_type' => 'none', 'discount_discount_type' => '', 'discount_value' => 0,
            'discount_percent' => 0, 'discount_amount' => 0, 'discount_label' => null,
            'discount_promo_id' => 0, 'discounted_subtotal' => 0, 'extras_total' => 0,
            'vat_rate' => $vatRate, 'vat_amount' => 0, 'total_amount' => 0,
            'taxable_net' => 0, 'net_amount' => 0, 'pricing_mode' => 'monthly_package',
            'vat_mode' => $vatMode, 'entered_amount' => null,
            'has_manual_override' => false, 'nightly_breakdown' => [], 'rules_applied' => [],
        ];
    }

    $hasManualOverride = ($pricingMode === 'nightly' && $rateOverride !== null && $rateOverride > 0);
    $rulesApplied      = [];
    $nightlyBreakdown  = [];
    $lengthDiscount    = null;
    $lengthDiscountAmount = 0.0;
    $subtotal          = 0.0;
    $effectiveRate     = $baseNightlyRate;

    if ($pricingMode === 'manual_total') {
        $subtotal      = round($enteredAmount, 2);
        $effectiveRate = $nights > 0 ? round($subtotal / $nights, 2) : 0.0;
    } elseif ($pricingMode === 'monthly_package') {
        $subtotal      = round($monthlyRate * ($nights / 30.0), 2);
        $effectiveRate = $nights > 0 ? round($subtotal / $nights, 2) : 0.0;
        $rulesApplied[] = 'Monthly package';
        $lengthDiscount = ars_get_length_discount($conn, $companyId, $unitId, $nights);
        if ($lengthDiscount) {
            $lengthDiscountAmount = round($subtotal * $lengthDiscount['discount_percent'] / 100, 2);
            $rulesApplied[]       = $lengthDiscount['rule_name'];
        }
    } else {
        if ($hasManualOverride) {
            $subtotal      = round($rateOverride * $nights, 2);
            $effectiveRate = $rateOverride;
        } else {
            $nightlyBreakdown = ars_get_nightly_breakdown($conn, $companyId, $unitId, $checkIn, $checkOut, $baseNightlyRate);
            $ruleNames        = [];
            foreach ($nightlyBreakdown as $nb) {
                $subtotal += $nb['rate'];
                if ($nb['rule_name'] && !in_array($nb['rule_name'], $ruleNames, true)) {
                    $ruleNames[] = $nb['rule_name'];
                }
            }
            $subtotal      = round($subtotal, 2);
            $effectiveRate = $nights > 0 ? round($subtotal / $nights, 2) : $baseNightlyRate;
            $rulesApplied  = $ruleNames;

            $lengthDiscount = ars_get_length_discount($conn, $companyId, $unitId, $nights);
            if ($lengthDiscount) {
                $lengthDiscountAmount = round($subtotal * $lengthDiscount['discount_percent'] / 100, 2);
                $rulesApplied[]       = $lengthDiscount['rule_name'];
            }
        }
    }

    $afterLengthDiscount = round($subtotal - $lengthDiscountAmount, 2);

    $discountType      = $discount['type'] ?? 'none';
    $discountDType     = $discount['discount_type'] ?? '';
    $discountValue     = (float)($discount['discount_value'] ?? 0);
    $discountLabel     = $discount['label'] ?? null;
    $discountPromoId   = (int)($discount['promo_id'] ?? 0);
    $maxDiscountCap    = isset($discount['max_discount_amount']) && $discount['max_discount_amount'] !== null
        ? (float)$discount['max_discount_amount'] : null;
    $discountAmount    = 0.0;
    $discountPercent   = 0.0;

    if ($discountType !== 'none' && $discountValue > 0) {
        $discountAmount = ars_calc_discount_amount($discountDType, $discountValue, $afterLengthDiscount, $maxDiscountCap);
        if ($discountDType === 'percentage') {
            $discountPercent = $discountValue;
        }
    }

    $discountedSubtotal = round($afterLengthDiscount - $discountAmount, 2);

    $extrasTotal = 0.0;
    foreach ($extras as $e) {
        $extrasTotal += round((float)($e['amount'] ?? $e['total'] ?? 0), 2);
    }

    $fin = ars_finalize_booking_totals($discountedSubtotal, $extrasTotal, $vatMode, $vatRate);

    return [
        'effective_rate'         => $effectiveRate,
        'base_rate'              => $baseNightlyRate,
        'nights'                 => $nights,
        'subtotal'               => $subtotal,
        'length_discount'        => $lengthDiscount,
        'length_discount_amount' => $lengthDiscountAmount,
        'discount_type'          => $discountType,
        'discount_discount_type' => $discountDType,
        'discount_value'         => $discountValue,
        'discount_percent'       => $discountPercent,
        'discount_amount'        => $discountAmount,
        'discount_label'         => $discountLabel,
        'discount_promo_id'      => $discountPromoId,
        'discounted_subtotal'    => $discountedSubtotal,
        'extras_total'           => $extrasTotal,
        'vat_rate'               => $vatRate,
        'vat_amount'             => $fin['vat_amount'],
        'total_amount'           => $fin['total_amount'],
        'taxable_net'            => $fin['taxable_net'],
        'net_amount'             => $fin['net_amount'],
        'pricing_mode'           => $pricingMode,
        'vat_mode'               => $vatMode,
        'entered_amount'         => $pricingMode === 'manual_total' ? round($enteredAmount, 2) : null,
        'has_manual_override'    => $hasManualOverride,
        'nightly_breakdown'      => $nightlyBreakdown,
        'rules_applied'          => $rulesApplied,
        'calc_error'             => null,
    ];
}

/**
 * Legacy wrapper — keeps backward compatibility with Phase 1 callers.
 */
function ars_calculate_booking_price(
    float $baseNightlyRate,
    int $nights,
    ?float $rateOverride,
    array $extras,
    float $vatRate
): array {
    $effectiveRate = ($rateOverride !== null && $rateOverride > 0) ? $rateOverride : $baseNightlyRate;
    $subtotal = round($effectiveRate * $nights, 2);

    $extrasTotal = 0.0;
    foreach ($extras as $e) {
        $extrasTotal += round((float)($e['amount'] ?? $e['total'] ?? 0), 2);
    }

    $taxableAmount = $subtotal + $extrasTotal;
    $vatAmount = round($taxableAmount * ($vatRate / 100), 2);
    $totalAmount = round($taxableAmount + $vatAmount, 2);

    return [
        'effective_rate' => $effectiveRate,
        'nights'         => $nights,
        'subtotal'       => $subtotal,
        'extras_total'   => $extrasTotal,
        'vat_rate'       => $vatRate,
        'vat_amount'     => $vatAmount,
        'total_amount'   => $totalAmount,
    ];
}

/**
 * Recalculate and update booking totals from its charges.
 */
function ars_recalc_booking_totals(PDO $conn, int $bookingId): void {
    $stmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ? LIMIT 1");
    $stmt->execute([$bookingId]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
        return;
    }

    $stmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) FROM ars_booking_charges WHERE booking_id = ? AND charge_type != 'room_night'");
    $stmt->execute([$bookingId]);
    $extrasTotal = (float) $stmt->fetchColumn();

    $roomAmt = round(
        (float) $b['subtotal']
        - (float) ($b['length_discount_amount'] ?? 0)
        - (float) ($b['discount_amount'] ?? 0),
        2
    );
    $vatMode = ars_normalize_vat_mode((string)($b['vat_mode'] ?? 'exclusive'));
    $fin     = ars_finalize_booking_totals($roomAmt, $extrasTotal, $vatMode, (float) $b['vat_rate']);

    $hasStripeColumns = false;
    try {
        $col = $conn->query("SHOW COLUMNS FROM ars_booking_payments LIKE 'gateway_status'");
        $hasStripeColumns = (bool)($col && $col->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $hasStripeColumns = false;
    }

    $roomFilter = '';
    if (function_exists('ars_payment_room_balance_sql_filter')) {
        require_once __DIR__ . '/ars_deposit.php';
        // Query has no table alias — use unaliased column filter.
        $roomFilter = ars_payment_room_balance_sql_filter('');
    }

    if ($hasStripeColumns) {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(CASE
                WHEN COALESCE(gateway_status, '') IN ('requires_payment_method','canceled','failed') THEN 0
                ELSE GREATEST(amount - COALESCE(amount_refunded, 0), 0)
            END), 0)
            FROM ars_booking_payments
            WHERE booking_id = ?
              AND (payment_gateway IS NULL OR payment_gateway != 'stripe' OR gateway_status IN ('succeeded','partially_refunded','refunded'))
              $roomFilter
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM ars_booking_payments
            WHERE booking_id = ? $roomFilter
        ");
    }
    $stmt->execute([$bookingId]);
    $paidAmount = (float) $stmt->fetchColumn();

    $totalAmount = $fin['total_amount'];
    $balanceDue  = round($totalAmount - $paidAmount, 2);
    $paymentStatus = 'unpaid';
    if ($paidAmount >= $totalAmount && $totalAmount > 0) {
        $paymentStatus = 'paid';
    } elseif ($paidAmount > 0) {
        $paymentStatus = 'partial';
    }

    $conn->prepare("
        UPDATE ars_bookings SET
            extras_total = ?, vat_amount = ?, net_amount = ?, total_amount = ?,
            paid_amount = ?, balance_due = ?, payment_status = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([
        $extrasTotal,
        $fin['vat_amount'],
        $fin['net_amount'],
        $totalAmount,
        $paidAmount,
        $balanceDue,
        $paymentStatus,
        $bookingId,
    ]);
}
