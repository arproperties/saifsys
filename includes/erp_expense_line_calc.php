<?php
/**
 * ERP expense line amounts (shared re_* GL expenses): inclusive vs exclusive VAT.
 *
 * @return array{line_subtotal:float,line_vat:float,line_total:float}
 */
function erp_expense_compute_line_amounts(float $qty, float $unitCost, float $vatRate, string $vatMode): array {
    $vatMode = ($vatMode === 'inclusive') ? 'inclusive' : 'exclusive';
    $gross   = round($qty * $unitCost, 2);

    if ($vatMode === 'inclusive') {
        if ($vatRate <= 0) {
            return ['line_subtotal' => $gross, 'line_vat' => 0.0, 'line_total' => $gross];
        }
        $lineVat = round($gross * $vatRate / (100 + $vatRate), 2);
        $net     = round($gross - $lineVat, 2);

        return ['line_subtotal' => $net, 'line_vat' => $lineVat, 'line_total' => $gross];
    }

    $lineSub = $gross;
    $lineVat = round($lineSub * ($vatRate / 100), 2);

    return ['line_subtotal' => $lineSub, 'line_vat' => $lineVat, 'line_total' => round($lineSub + $lineVat, 2)];
}
