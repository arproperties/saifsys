<?php
/**
 * POS shelf pricing: optional promotional offer (VAT-exclusive offer_price, inclusive date range).
 * Regular list price remains inv_items.sale_price (VAT-excl).
 */

require_once __DIR__ . '/inv_pricing.php';

function inv_item_business_date_ymd(): string {
    return date('Y-m-d');
}

/**
 * Offer window: offer_start <= d <= offer_end (inclusive both ends).
 */
function inv_item_offer_is_active(array $item, ?string $ymd = null): bool {
    $ymd = $ymd ?? inv_item_business_date_ymd();
    if (empty($item['is_offer'])) {
        return false;
    }
    $op = $item['offer_price'] ?? null;
    if ($op === null || $op === '') {
        return false;
    }
    if ((float)$op <= 0) {
        return false;
    }
    $start = $item['offer_start'] ?? null;
    $end = $item['offer_end'] ?? null;
    if ($start === null || $start === '' || $end === null || $end === '') {
        return false;
    }
    $ds = substr((string)$start, 0, 10);
    $de = substr((string)$end, 0, 10);
    return $ds <= $ymd && $ymd <= $de;
}

function inv_item_effective_unit_price_excl(array $item, ?string $ymd = null): float {
    if (inv_item_offer_is_active($item, $ymd)) {
        return round((float)($item['offer_price'] ?? 0), 4);
    }
    return round((float)($item['sale_price'] ?? 0), 4);
}

/**
 * @return string|null One of OFFER, DISCOUNT, EXPIRY_SOON, or null
 */
function inv_item_offer_badge_code(array $item, ?string $ymd = null): ?string {
    if (!inv_item_offer_is_active($item, $ymd)) {
        return null;
    }
    $b = strtoupper(trim((string)($item['offer_badge'] ?? '')));
    if (in_array($b, ['OFFER', 'DISCOUNT', 'EXPIRY_SOON'], true)) {
        return $b;
    }
    return 'OFFER';
}

function inv_item_offer_badge_label(?string $code): string {
    switch ($code) {
        case 'DISCOUNT':
            return 'Discount';
        case 'EXPIRY_SOON':
            return 'Expiry soon';
        case 'OFFER':
        default:
            return 'Offer';
    }
}

/**
 * JSON-friendly POS item pricing + display fields (list vs effective, VAT-inclusive).
 *
 * @return array<string, mixed>
 */
function inv_item_pos_price_payload(array $item, float $vatRate, ?string $ymd = null): array {
    $ymd = $ymd ?? inv_item_business_date_ymd();
    $listExcl = round((float)($item['sale_price'] ?? 0), 4);
    $effExcl = inv_item_effective_unit_price_excl($item, $ymd);
    $listIncl = inv_calc_line_vat(1, $listExcl, $vatRate)['gross_incl'];
    $effIncl = inv_calc_line_vat(1, $effExcl, $vatRate)['gross_incl'];
    $offerActive = inv_item_offer_is_active($item, $ymd);
    $badgeCode = inv_item_offer_badge_code($item, $ymd);
    return [
        'sale_price_excl' => $effExcl,
        'list_sale_price_excl' => $listExcl,
        'unit_price_incl' => $effIncl,
        'list_unit_price_incl' => $listIncl,
        'offer_active' => $offerActive,
        'offer_badge' => $badgeCode,
        'offer_label' => $badgeCode ? inv_item_offer_badge_label($badgeCode) : null,
    ];
}
