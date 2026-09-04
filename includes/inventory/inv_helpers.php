<?php
/**
 * Inventory Engine - Helpers
 */

if (!function_exists('inv_h')) {
    function inv_h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

function inv_now(): string {
    return date('Y-m-d H:i:s');
}

/**
 * Owner/Admin may create emergency manual issue documents (policy A).
 */
function inv_is_inventory_owner_or_admin(PDO $conn): bool {
    if (!function_exists('current_user_roles')) {
        require_once __DIR__ . '/../auth.php';
    }
    $roles = current_user_roles($conn);
    return in_array('Owner', $roles, true) || in_array('Admin', $roles, true);
}

/**
 * Normalize doc type.
 * @return string canonical doc type key
 */
function inv_normalize_doc_type(string $docType): string {
    $t = strtolower(trim($docType));
    $map = [
        'grn' => 'receipt',
        'goods_receipt' => 'receipt',
        'purchase_receipt' => 'receipt',
        'consumption' => 'issue',
        'internal_consumption' => 'issue',
        'stock_issue' => 'issue',
        'stock_transfer' => 'transfer',
        'stock_adjustment' => 'adjustment',
        'scrap' => 'wastage',
        'return_to_vendor' => 'return',
        'pos_sale' => 'sale',
        'opening' => 'opening_balance',
        'initial_stock' => 'opening_balance',
        'stocktake' => 'stock_take',
        'stock_take_count' => 'stock_take',
    ];
    if (isset($map[$t])) {
        return $map[$t];
    }
    $allowed = [
        'receipt', 'opening_balance', 'issue', 'transfer', 'adjustment',
        'wastage', 'return', 'sale', 'stock_take',
    ];
    return in_array($t, $allowed, true) ? $t : 'adjustment';
}

/**
 * 3-letter code for document numbers: INV-{TYP}-{YYYY}-{SEQ5}
 */
function inv_doc_type_code(string $docType): string {
    $t = inv_normalize_doc_type($docType);
    $map = [
        'receipt' => 'REC',
        'opening_balance' => 'OPN',
        'issue' => 'ISS',
        'transfer' => 'TRA',
        'adjustment' => 'ADJ',
        'wastage' => 'WAS',
        'return' => 'RET',
        'sale' => 'SAL',
        'stock_take' => 'STK',
    ];
    return $map[$t] ?? 'ADJ';
}

/**
 * Normalize status.
 */
function inv_normalize_status(string $status): string {
    $s = strtolower(trim($status));
    return in_array($s, ['draft','posted','void'], true) ? $s : 'draft';
}

