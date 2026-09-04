<?php
/**
 * Inventory Engine - Read queries
 */

require_once __DIR__ . '/inv_helpers.php';

function inv_get_item(PDO $conn, int $companyId, int $itemId): ?array {
    $stmt = $conn->prepare("SELECT * FROM inv_items WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$itemId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function inv_get_location(PDO $conn, int $companyId, int $locationId): ?array {
    $stmt = $conn->prepare("SELECT * FROM inv_locations WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$locationId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function inv_get_onhand_qty(PDO $conn, int $companyId, int $itemId, int $locationId, ?int $lotId = null): float {
    $lid = (int)($lotId ?? 0);
    $stmt = $conn->prepare("
        SELECT qty_on_hand
        FROM inv_onhand
        WHERE company_id = ? AND item_id = ? AND location_id = ?
          AND lot_id = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId, $itemId, $locationId, $lid]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (float)$v : 0.0;
}

function inv_get_wac(PDO $conn, int $companyId, int $itemId): array {
    $stmt = $conn->prepare("SELECT avg_cost, qty_valued FROM inv_item_cost_state WHERE company_id = ? AND item_id = ? LIMIT 1");
    $stmt->execute([$companyId, $itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['avg_cost' => 0.0, 'qty_valued' => 0.0];
    return ['avg_cost' => (float)$row['avg_cost'], 'qty_valued' => (float)$row['qty_valued']];
}

function inv_get_item_by_code(PDO $conn, int $companyId, string $itemCode): ?array {
    $stmt = $conn->prepare("SELECT * FROM inv_items WHERE company_id = ? AND item_code = ? LIMIT 1");
    $stmt->execute([$companyId, $itemCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Resolve item by primary barcode or alternate barcode (inv_item_barcodes).
 */
function inv_get_item_by_barcode(PDO $conn, int $companyId, string $barcode): ?array {
    $barcode = trim($barcode);
    if ($barcode === '') return null;
    $stmt = $conn->prepare("SELECT * FROM inv_items WHERE company_id = ? AND barcode = ? LIMIT 1");
    $stmt->execute([$companyId, $barcode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    try {
        $stmt = $conn->prepare("
            SELECT i.* FROM inv_item_barcodes b
            JOIN inv_items i ON i.id = b.item_id AND i.company_id = b.company_id
            WHERE b.company_id = ? AND b.barcode = ? AND b.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$companyId, $barcode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Adjust valued quantity for company+item WAC pool (MVP: not per location).
 */
function inv_adjust_qty_valued(PDO $conn, int $companyId, int $itemId, float $deltaQty, string $moveDate): void {
    $conn->prepare("SELECT qty_valued FROM inv_item_cost_state WHERE company_id = ? AND item_id = ? FOR UPDATE")
         ->execute([$companyId, $itemId]);
    $stmt = $conn->prepare("SELECT avg_cost, qty_valued FROM inv_item_cost_state WHERE company_id = ? AND item_id = ? LIMIT 1");
    $stmt->execute([$companyId, $itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $qv  = $row ? (float)$row['qty_valued'] : 0.0;
    $ac  = $row ? (float)$row['avg_cost'] : 0.0;
    $newQ = max(0.0, round($qv + $deltaQty, 4));
    $conn->prepare("
        INSERT INTO inv_item_cost_state (company_id, item_id, avg_cost, qty_valued, updated_at)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE qty_valued = VALUES(qty_valued), updated_at = VALUES(updated_at)
    ")->execute([$companyId, $itemId, $ac, $newQ, $moveDate]);
}

