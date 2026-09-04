<?php
/**
 * Lease VAT helpers — rent + extra charges, consistent with renewal workflow logic.
 */

if (!function_exists('lease_vat_unit_is_commercial')) {
    /**
     * Commercial / shop-style units may charge VAT on rent (UAE practice varies by supply).
     */
    function lease_vat_unit_is_commercial(string $unitType): bool {
        $t = strtolower(trim($unitType));
        if ($t === '') {
            return false;
        }
        foreach (['commercial', 'shop', 'retail', 'office', 'warehouse', 'showroom', 'business'] as $kw) {
            if (strpos($t, $kw) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('lease_vat_compute_amounts')) {
    /**
     * @param array{
     *   annual_rent?:float|numeric-string,
     *   vat_applicable_on_rent?:int|bool,
     *   vat_applicable_on_extra_charges?:int|bool,
     *   lease_vat_rate?:float|numeric-string,
     *   extra_charges_base?:float
     * } $in
     * @return array{rent_vat:float, extra_vat:float, total_vat:float, rate:float}
     */
    function lease_vat_compute_amounts(array $in): array {
        $rate = (float)($in['lease_vat_rate'] ?? 5.0);
        if ($rate < 0) {
            $rate = 0.0;
        }
        if ($rate > 100) {
            $rate = 100.0;
        }
        $rentAnnual = (float)($in['annual_rent'] ?? 0);
        $onRent = !empty($in['vat_applicable_on_rent']);
        $onExtras = array_key_exists('vat_applicable_on_extra_charges', $in)
            ? !empty($in['vat_applicable_on_extra_charges'])
            : true;
        $extraBase = max(0.0, (float)($in['extra_charges_base'] ?? 0));

        $rentVat = ($onRent && $rate > 0) ? round($rentAnnual * ($rate / 100.0), 2) : 0.0;
        $extraVat = ($onExtras && $rate > 0) ? round($extraBase * ($rate / 100.0), 2) : 0.0;

        return [
            'rent_vat' => $rentVat,
            'extra_vat' => $extraVat,
            'total_vat' => round($rentVat + $extraVat, 2),
            'rate' => $rate,
        ];
    }
}

if (!function_exists('lease_vat_normalize_distribution')) {
    function lease_vat_normalize_distribution(?string $t): string {
        $t = strtolower(trim((string)$t));
        if (in_array($t, ['split_installments', 'split', 'even'], true)) {
            return 'split_installments';
        }
        if (in_array($t, ['separate_payment', 'separate', 'sep'], true)) {
            return 'separate_payment';
        }
        return 'first_installment';
    }
}
