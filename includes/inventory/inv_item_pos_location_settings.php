<?php
/**
 * Per-location POS settings for items: when a row exists it fully replaces item-level favorites/offers for that store.
 */

require_once __DIR__ . '/inv_helpers.php';

/**
 * @return array<string,mixed>|null
 */
function inv_item_pos_location_settings_get(PDO $conn, int $companyId, int $itemId, int $locationId): ?array {
    if ($locationId <= 0 || $itemId <= 0) {
        return null;
    }
    $st = $conn->prepare("
        SELECT * FROM inv_item_pos_location_settings
        WHERE company_id = ? AND item_id = ? AND location_id = ?
        LIMIT 1
    ");
    $st->execute([$companyId, $itemId, $locationId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Apply location row on top of inv_items row (favorite/offer fields only). No row => unchanged item master.
 *
 * @param array<string,mixed> $item inv_items row
 * @param array<string,mixed>|null $locRow inv_item_pos_location_settings row or null
 * @return array<string,mixed>
 */
function inv_item_merge_pos_location_into_item(array $item, ?array $locRow): array {
    if ($locRow === null) {
        return $item;
    }
    $out = $item;
    $out['is_favorite'] = (int)($locRow['is_favorite'] ?? 0);
    $out['favorite_sort_order'] = (int)($locRow['favorite_sort_order'] ?? 0);
    $out['is_offer'] = (int)($locRow['is_offer'] ?? 0);
    $out['offer_price'] = $locRow['offer_price'] ?? null;
    $out['offer_start'] = $locRow['offer_start'] ?? null;
    $out['offer_end'] = $locRow['offer_end'] ?? null;
    $out['offer_note'] = $locRow['offer_note'] ?? null;
    $out['offer_badge'] = $locRow['offer_badge'] ?? null;
    return $out;
}

/**
 * Strip pls_* SELECT aliases and merge when pls_id is set (search SQL with LEFT JOIN).
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed> Effective item row for pricing / payload
 */
function inv_item_search_row_to_effective_item(array $row): array {
    if (!array_key_exists('pls_id', $row)) {
        return $row;
    }
    $rawId = $row['pls_id'];
    $plsId = $rawId !== null && $rawId !== '' ? (int)$rawId : 0;
    $locRow = null;
    if ($plsId > 0) {
        $locRow = [
            'is_favorite' => $row['pls_is_favorite'] ?? 0,
            'favorite_sort_order' => $row['pls_favorite_sort_order'] ?? 0,
            'is_offer' => $row['pls_is_offer'] ?? 0,
            'offer_price' => $row['pls_offer_price'] ?? null,
            'offer_start' => $row['pls_offer_start'] ?? null,
            'offer_end' => $row['pls_offer_end'] ?? null,
            'offer_note' => $row['pls_offer_note'] ?? null,
            'offer_badge' => $row['pls_offer_badge'] ?? null,
        ];
    }
    $item = $row;
    foreach (array_keys($row) as $k) {
        if (strncmp((string)$k, 'pls_', 4) === 0) {
            unset($item[$k]);
        }
    }
    return inv_item_merge_pos_location_into_item($item, $locRow);
}

function inv_item_pos_location_settings_delete(PDO $conn, int $companyId, int $itemId, int $locationId): void {
    if ($locationId <= 0) {
        return;
    }
    $st = $conn->prepare("
        DELETE FROM inv_item_pos_location_settings
        WHERE company_id = ? AND item_id = ? AND location_id = ?
    ");
    $st->execute([$companyId, $itemId, $locationId]);
}

/**
 * @param array{
 *   is_favorite:int,
 *   favorite_sort_order:int,
 *   is_offer:int,
 *   offer_price:float|null,
 *   offer_start:?string,
 *   offer_end:?string,
 *   offer_note:?string,
 *   offer_badge:?string
 * } $fields
 */
function inv_item_pos_location_settings_upsert(PDO $conn, int $companyId, int $itemId, int $locationId, array $fields): void {
    $st = $conn->prepare("
        INSERT INTO inv_item_pos_location_settings (
            company_id, item_id, location_id,
            is_favorite, favorite_sort_order,
            is_offer, offer_price, offer_start, offer_end, offer_note, offer_badge,
            updated_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            is_favorite = VALUES(is_favorite),
            favorite_sort_order = VALUES(favorite_sort_order),
            is_offer = VALUES(is_offer),
            offer_price = VALUES(offer_price),
            offer_start = VALUES(offer_start),
            offer_end = VALUES(offer_end),
            offer_note = VALUES(offer_note),
            offer_badge = VALUES(offer_badge),
            updated_at = NOW()
    ");
    $st->execute([
        $companyId,
        $itemId,
        $locationId,
        $fields['is_favorite'],
        $fields['favorite_sort_order'],
        $fields['is_offer'],
        $fields['offer_price'],
        $fields['offer_start'],
        $fields['offer_end'],
        $fields['offer_note'],
        $fields['offer_badge'],
    ]);
}
