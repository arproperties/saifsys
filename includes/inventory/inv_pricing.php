<?php
/**
 * VAT / pricing helpers. Convention: unit sale_price on inv_items is VAT-exclusive.
 */

/**
 * @return array{net_excl: float, tax: float, gross_incl: float}
 */
function inv_calc_line_vat(float $qty, float $unitPriceExcl, float $vatRatePercent): array {
    $net = round($qty * $unitPriceExcl, 4);
    $tax = round($net * ($vatRatePercent / 100.0), 4);
    return [
        'net_excl' => $net,
        'tax' => $tax,
        'gross_incl' => round($net + $tax, 4),
    ];
}

/**
 * Sum cart lines (each with net_excl, tax from inv_calc_line_vat).
 *
 * @param list<array{net_excl: float, tax: float}> $lines
 * @return array{subtotal_excl: float, tax_total: float, grand_incl: float}
 */
function inv_sum_pos_lines(array $lines): array {
    $sub = 0.0;
    $tax = 0.0;
    foreach ($lines as $ln) {
        $sub += (float)($ln['net_excl'] ?? 0);
        $tax += (float)($ln['tax'] ?? 0);
    }
    return [
        'subtotal_excl' => round($sub, 4),
        'tax_total' => round($tax, 4),
        'grand_incl' => round($sub + $tax, 4),
    ];
}
