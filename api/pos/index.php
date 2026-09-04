<?php
/**
 * POS API (JSON) — session auth; no POS UI. Actions via GET/POST `action`.
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_queries.php';
require_once __DIR__ . '/../../includes/inventory/inv_pricing.php';
require_once __DIR__ . '/../../includes/inventory/inv_item_sale_pricing.php';
require_once __DIR__ . '/../../includes/inventory/inv_item_images.php';
require_once __DIR__ . '/../../includes/inventory/inv_item_pos_location_settings.php';
require_once __DIR__ . '/../../includes/inventory/inv_pos_sale.php';

require_login();

/**
 * POS API may be used from Inventory (integration) or Grocery (retail) module users.
 */
function pos_api_user_can_view_items(PDO $conn, int $companyId): bool {
    $uid = (int)current_user_id();
    if ($uid <= 0) {
        return false;
    }
    if (user_has_company_module_access($conn, $uid, $companyId, MODULE_GROCERY)
        && has_grocery_pos_department($conn)
        && has_permission('grocery_pos.view', MODULE_GROCERY, $conn)) {
        return true;
    }
    return user_has_company_module_access($conn, $uid, $companyId, MODULE_INVENTORY)
        && has_permission('inventory_items.view', MODULE_INVENTORY, $conn);
}

function pos_api_require_view_items(PDO $conn, int $companyId): void {
    if (!pos_api_user_can_view_items($conn, $companyId)) {
        pos_json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

function pos_api_user_can_post_sale(PDO $conn, int $companyId): bool {
    $uid = (int)current_user_id();
    if ($uid <= 0) {
        return false;
    }
    if (user_has_company_module_access($conn, $uid, $companyId, MODULE_GROCERY)
        && has_grocery_pos_department($conn)
        && has_permission('grocery_pos.post', MODULE_GROCERY, $conn)) {
        return true;
    }
    return user_has_company_module_access($conn, $uid, $companyId, MODULE_INVENTORY)
        && has_permission('inventory_docs.post', MODULE_INVENTORY, $conn);
}

function pos_api_require_post_sale(PDO $conn, int $companyId): void {
    if (!pos_api_user_can_post_sale($conn, $companyId)) {
        pos_json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

function pos_json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$companyId = current_company_id($conn) ?: 0;
if ($companyId <= 0) {
    pos_json_out(['ok' => false, 'error' => 'No company selected'], 400);
}

$uid = (int)current_user_id();
if (!user_has_company_access($conn, $uid, $companyId)) {
    pos_json_out(['ok' => false, 'error' => 'Access denied'], 403);
}
$hasInv = user_has_company_module_access($conn, $uid, $companyId, MODULE_INVENTORY);
$hasGrocery = user_has_company_module_access($conn, $uid, $companyId, MODULE_GROCERY);
if (!$hasInv && !$hasGrocery) {
    pos_json_out(['ok' => false, 'error' => 'No POS module access for this company'], 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** @return 'api'|'retail' */
function pos_api_client(): string {
    $c = strtolower(trim((string)($_GET['client'] ?? $_POST['client'] ?? 'api')));
    return $c === 'retail' ? 'retail' : 'api';
}

/**
 * Retail POS profile slug from ?pos= (multi-register carts & defaults).
 * Alphanumeric + hyphen/underscore, max 32 chars.
 */
function pos_api_retail_profile_slug(): string {
    $raw = trim((string)($_GET['pos'] ?? $_POST['pos'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $s = strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $raw));
    if (strlen($s) > 32) {
        $s = substr($s, 0, 32);
    }
    return $s;
}

function pos_cart_key(int $companyId, ?string $client = null): string {
    $client = $client ?? pos_api_client();
    if ($client !== 'retail') {
        return 'pos_api_cart_' . $companyId;
    }
    $slug = pos_api_retail_profile_slug();
    if ($slug === '') {
        return 'pos_retail_cart_' . $companyId;
    }
    return 'pos_retail_cart_' . $companyId . '_' . $slug;
}

function pos_offer_window_sql(string $alias): string {
    return "{$alias}.is_offer = 1 AND {$alias}.offer_price IS NOT NULL AND {$alias}.offer_start IS NOT NULL AND {$alias}.offer_end IS NOT NULL AND {$alias}.offer_start <= CURDATE() AND {$alias}.offer_end >= CURDATE()";
}

/**
 * @return array{join: string, select: string, leadingParams: list<int>}
 */
function pos_search_pls_parts(int $locId): array {
    if ($locId <= 0) {
        return ['join' => '', 'select' => '', 'leadingParams' => []];
    }
    return [
        'join' => ' LEFT JOIN inv_item_pos_location_settings pls ON pls.company_id = i.company_id AND pls.item_id = i.id AND pls.location_id = ? ',
        'select' => ', pls.id AS pls_id, pls.is_favorite AS pls_is_favorite, pls.favorite_sort_order AS pls_favorite_sort_order, pls.is_offer AS pls_is_offer, pls.offer_price AS pls_offer_price, pls.offer_start AS pls_offer_start, pls.offer_end AS pls_offer_end, pls.offer_note AS pls_offer_note, pls.offer_badge AS pls_offer_badge',
        'leadingParams' => [$locId],
    ];
}

function pos_item_payload(PDO $conn, int $companyId, array $item, int $locationId = 0): array {
    if ($locationId > 0) {
        $loc = inv_item_pos_location_settings_get($conn, $companyId, (int)($item['id'] ?? 0), $locationId);
        $item = inv_item_merge_pos_location_into_item($item, $loc);
    }
    $thumb = null;
    $orig = null;
    if (!empty($item['primary_image_id'])) {
        $st = $conn->prepare("SELECT path_thumb, path_original FROM inv_item_images WHERE id = ? AND company_id = ? LIMIT 1");
        $st->execute([(int)$item['primary_image_id'], $companyId]);
        $img = $st->fetch(PDO::FETCH_ASSOC);
        if ($img) {
            $thumb = inv_item_image_public_url($img['path_thumb']);
            $orig = inv_item_image_public_url($img['path_original']);
        }
    }
    $vatRate = (float)($item['vat_rate'] ?? 0);
    $pricing = inv_item_pos_price_payload($item, $vatRate);
    $catId = isset($item['category_id']) ? (int)$item['category_id'] : 0;
    $catName = isset($item['category_name']) ? trim((string)$item['category_name']) : '';
    if ($catName === '' && $catId > 0) {
        $cst = $conn->prepare("SELECT name FROM inv_item_categories WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1");
        $cst->execute([$catId, $companyId]);
        $catName = (string)($cst->fetchColumn() ?: '');
    }
    return [
        'id' => (int)$item['id'],
        'item_code' => $item['item_code'],
        'name' => $item['name'],
        'barcode' => $item['barcode'],
        'category_id' => $catId > 0 ? $catId : null,
        'category_name' => $catName !== '' ? $catName : null,
        'sale_price_excl' => $pricing['sale_price_excl'],
        'list_sale_price_excl' => $pricing['list_sale_price_excl'],
        'unit_price_incl' => $pricing['unit_price_incl'],
        'list_unit_price_incl' => $pricing['list_unit_price_incl'],
        'offer_active' => $pricing['offer_active'],
        'offer_badge' => $pricing['offer_badge'],
        'offer_label' => $pricing['offer_label'],
        'vat_rate' => $vatRate,
        'is_service' => !empty($item['is_service']) || strtolower((string)($item['item_type'] ?? '')) === 'service',
        'is_sellable' => !empty($item['is_sellable']),
        'base_uom_id' => (int)($item['base_uom_id'] ?? 0),
        'thumb_url' => $thumb,
        'image_url' => $orig,
    ];
}

try {
    switch ($action) {
        case 'search':
            pos_api_require_view_items($conn, $companyId);
            $q = trim((string)($_GET['q'] ?? ''));
            $limit = min(60, max(1, (int)($_GET['limit'] ?? 24)));
            $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
            $mode = strtolower(trim((string)($_GET['mode'] ?? '')));

            if ($mode === 'top_selling') {
                $locId = (int)($_GET['location_id'] ?? 0);
                if ($locId <= 0) {
                    pos_json_out(['ok' => true, 'items' => []]);
                }
                $fromTs = date('Y-m-d H:i:s', strtotime('-30 days'));
                $toTs = date('Y-m-d H:i:s');
                $agg = $conn->prepare("
                    SELECT l.item_id, SUM(l.qty) AS qty_sold
                    FROM pos_sale_lines l
                    INNER JOIN pos_sales ps ON ps.id = l.header_id AND ps.company_id = l.company_id
                    WHERE l.company_id = ?
                      AND ps.status = 'posted'
                      AND ps.location_id = ?
                      AND COALESCE(ps.posted_at, ps.created_at) >= ?
                      AND COALESCE(ps.posted_at, ps.created_at) <= ?
                    GROUP BY l.item_id
                    ORDER BY qty_sold DESC
                    LIMIT 20
                ");
                $agg->execute([$companyId, $locId, $fromTs, $toTs]);
                $ranked = $agg->fetchAll(PDO::FETCH_ASSOC);
                $ids = [];
                foreach ($ranked as $row) {
                    $ids[] = (int)$row['item_id'];
                }
                if ($ids === []) {
                    pos_json_out(['ok' => true, 'items' => []]);
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $fieldList = implode(',', array_map('intval', $ids));
                $whereTs = ['i.company_id = ?', 'i.is_active = 1', 'i.is_sellable = 1', "i.id IN ({$placeholders})"];
                $pp = pos_search_pls_parts($locId);
                $paramsTs = array_merge($pp['leadingParams'], [$companyId], $ids);
                if ($q !== '') {
                    $like = '%' . $q . '%';
                    $whereTs[] = '(i.item_code LIKE ? OR i.name LIKE ? OR i.barcode LIKE ?)';
                    array_push($paramsTs, $like, $like, $like);
                }
                $sqlTs = "
                    SELECT i.*, c.name AS category_name{$pp['select']}
                    FROM inv_items i
                    LEFT JOIN inv_item_categories c ON c.id = i.category_id AND c.company_id = i.company_id
                    {$pp['join']}
                    WHERE " . implode(' AND ', $whereTs) . "
                    ORDER BY FIELD(i.id, {$fieldList})
                ";
                $stmt = $conn->prepare($sqlTs);
                $stmt->execute($paramsTs);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $out = [];
                foreach ($rows as $r) {
                    $eff = $locId > 0 ? inv_item_search_row_to_effective_item($r) : $r;
                    $out[] = pos_item_payload($conn, $companyId, $eff);
                }
                pos_json_out(['ok' => true, 'items' => $out]);
            }

            $locId = (int)($_GET['location_id'] ?? 0);
            $pp = pos_search_pls_parts($locId);

            $where = ['i.company_id = ?', 'i.is_active = 1', 'i.is_sellable = 1'];
            $params = array_merge($pp['leadingParams'], [$companyId]);

            if ($mode === 'favorites') {
                if ($locId > 0) {
                    $where[] = '((pls.id IS NOT NULL AND pls.is_favorite = 1) OR (pls.id IS NULL AND i.is_favorite = 1))';
                } else {
                    $where[] = 'i.is_favorite = 1';
                }
            } elseif ($mode === 'offers') {
                $oi = pos_offer_window_sql('i');
                if ($locId > 0) {
                    $op = pos_offer_window_sql('pls');
                    $where[] = "((pls.id IS NOT NULL AND ({$op})) OR (pls.id IS NULL AND ({$oi})))";
                } else {
                    $where[] = '(' . $oi . ')';
                }
            } elseif ($categoryId > 0) {
                $where[] = 'i.category_id = ?';
                $params[] = $categoryId;
            }

            if ($q !== '') {
                $like = '%' . $q . '%';
                $where[] = '(i.item_code LIKE ? OR i.name LIKE ? OR i.barcode LIKE ?)';
                array_push($params, $like, $like, $like);
            }

            if ($mode === 'favorites') {
                if ($locId > 0) {
                    $order = 'CASE WHEN pls.id IS NOT NULL THEN pls.favorite_sort_order ELSE i.favorite_sort_order END ASC, i.name ASC, i.id ASC';
                } else {
                    $order = 'i.favorite_sort_order ASC, i.name ASC, i.id ASC';
                }
            } elseif ($q !== '') {
                $order = 'i.name ASC, i.id ASC';
            } elseif ($mode === 'offers' || $categoryId > 0) {
                $order = 'i.name ASC, i.id ASC';
            } else {
                $order = 'i.id DESC';
            }

            $sql = "
                SELECT i.*, c.name AS category_name{$pp['select']}
                FROM inv_items i
                LEFT JOIN inv_item_categories c ON c.id = i.category_id AND c.company_id = i.company_id
                {$pp['join']}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$order}
                LIMIT {$limit}
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $eff = $locId > 0 ? inv_item_search_row_to_effective_item($r) : $r;
                $out[] = pos_item_payload($conn, $companyId, $eff);
            }
            pos_json_out(['ok' => true, 'items' => $out]);

        case 'categories':
            pos_api_require_view_items($conn, $companyId);
            $cstmt = $conn->prepare("
                SELECT id, name FROM inv_item_categories
                WHERE company_id = ? AND is_active = 1
                ORDER BY name ASC
            ");
            $cstmt->execute([$companyId]);
            $cats = $cstmt->fetchAll(PDO::FETCH_ASSOC);
            $outCats = [];
            foreach ($cats as $c) {
                $outCats[] = ['id' => (int)$c['id'], 'name' => (string)$c['name']];
            }
            pos_json_out(['ok' => true, 'categories' => $outCats]);

        case 'barcode':
            pos_api_require_view_items($conn, $companyId);
            $code = trim((string)($_GET['code'] ?? ''));
            if ($code === '') {
                pos_json_out(['ok' => false, 'error' => 'code required'], 400);
            }
            $item = inv_get_item_by_barcode($conn, $companyId, $code);
            if (!$item || empty($item['is_active']) || empty($item['is_sellable'])) {
                pos_json_out(['ok' => false, 'error' => 'Not found'], 404);
            }
            $barLoc = (int)($_GET['location_id'] ?? 0);
            pos_json_out(['ok' => true, 'item' => pos_item_payload($conn, $companyId, $item, $barLoc)]);

        case 'cart_get':
            pos_api_require_view_items($conn, $companyId);
            $cart = $_SESSION[pos_cart_key($companyId)] ?? ['lines' => [], 'location_id' => null];
            pos_json_out(['ok' => true, 'cart' => $cart]);

        case 'cart_clear':
            pos_api_require_view_items($conn, $companyId);
            $_SESSION[pos_cart_key($companyId)] = ['lines' => [], 'location_id' => null];
            pos_json_out(['ok' => true]);

        case 'cart_set_location':
            pos_api_require_view_items($conn, $companyId);
            $loc = (int)($_POST['location_id'] ?? 0);
            $cart = $_SESSION[pos_cart_key($companyId)] ?? ['lines' => [], 'location_id' => null];
            $cart['location_id'] = $loc > 0 ? $loc : null;
            $_SESSION[pos_cart_key($companyId)] = $cart;
            pos_json_out(['ok' => true, 'cart' => $cart]);

        case 'cart_add':
            pos_api_require_view_items($conn, $companyId);
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                pos_json_out(['ok' => false, 'error' => 'POST required'], 405);
            }
            $itemId = (int)($_POST['item_id'] ?? 0);
            $qty = (float)($_POST['qty'] ?? 1);
            $uomId = isset($_POST['uom_id']) ? (int)$_POST['uom_id'] : 0;
            if ($itemId <= 0 || $qty <= 0) {
                pos_json_out(['ok' => false, 'error' => 'item_id and qty required'], 400);
            }
            $item = inv_get_item($conn, $companyId, $itemId);
            if (!$item) {
                pos_json_out(['ok' => false, 'error' => 'Item not found'], 400);
            }
            $baseUom = (int)($item['base_uom_id'] ?? 0);
            $effUom = $uomId > 0 ? $uomId : $baseUom;
            $cart = $_SESSION[pos_cart_key($companyId)] ?? ['lines' => [], 'location_id' => null];
            $lines = $cart['lines'] ?? [];
            $merged = false;
            foreach ($lines as &$ln) {
                if ((int)($ln['item_id'] ?? 0) === $itemId) {
                    $ln['qty'] = (float)($ln['qty'] ?? 0) + $qty;
                    $merged = true;
                    break;
                }
            }
            unset($ln);
            if (!$merged) {
                $lines[] = ['item_id' => $itemId, 'qty' => $qty, 'uom_id' => $effUom];
            }
            $cart['lines'] = $lines;
            $_SESSION[pos_cart_key($companyId)] = $cart;
            pos_json_out(['ok' => true, 'cart' => $cart]);

        case 'cart_set_line_qty':
            pos_api_require_view_items($conn, $companyId);
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                pos_json_out(['ok' => false, 'error' => 'POST required'], 405);
            }
            $idx = (int)($_POST['line_index'] ?? -1);
            $qty = (float)($_POST['qty'] ?? 0);
            $cart = $_SESSION[pos_cart_key($companyId)] ?? ['lines' => [], 'location_id' => null];
            $lines = $cart['lines'] ?? [];
            if ($idx < 0 || $idx >= count($lines)) {
                pos_json_out(['ok' => false, 'error' => 'Invalid line'], 400);
            }
            if ($qty <= 0) {
                array_splice($lines, $idx, 1);
            } else {
                $lines[$idx]['qty'] = $qty;
            }
            $cart['lines'] = $lines;
            $_SESSION[pos_cart_key($companyId)] = $cart;
            pos_json_out(['ok' => true, 'cart' => $cart]);

        case 'cart_remove_line':
            pos_api_require_view_items($conn, $companyId);
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                pos_json_out(['ok' => false, 'error' => 'POST required'], 405);
            }
            $idx = (int)($_POST['line_index'] ?? -1);
            $cart = $_SESSION[pos_cart_key($companyId)] ?? ['lines' => [], 'location_id' => null];
            $lines = $cart['lines'] ?? [];
            if ($idx < 0 || $idx >= count($lines)) {
                pos_json_out(['ok' => false, 'error' => 'Invalid line'], 400);
            }
            array_splice($lines, $idx, 1);
            $cart['lines'] = $lines;
            $_SESSION[pos_cart_key($companyId)] = $cart;
            pos_json_out(['ok' => true, 'cart' => $cart]);

        case 'cart_totals':
            pos_api_require_view_items($conn, $companyId);
            $cart = $_SESSION[pos_cart_key($companyId)] ?? ['lines' => [], 'location_id' => null];
            $cartLoc = (int)($cart['location_id'] ?? 0);
            $exp = pos_cart_expand_lines($conn, $companyId, $cart['lines'] ?? [], $cartLoc > 0 ? $cartLoc : null);
            if (!empty($exp['error'])) {
                pos_json_out(['ok' => false, 'error' => $exp['error']], 400);
            }
            $lines = $exp['lines'];
            $sum = pos_sum_expanded_lines($lines);
            $detail = [];
            foreach ($lines as $i => $e) {
                $unitIncl = inv_calc_line_vat(1, $e['unit_price_excl'], $e['vat_rate'])['gross_incl'];
                $it = $e['item'] ?? [];
                $payload = $it ? pos_item_payload($conn, $companyId, $it) : [];
                $detail[] = [
                    'line_index' => $i,
                    'item_id' => $e['item_id'],
                    'name' => (string)($it['name'] ?? ''),
                    'item_code' => (string)($it['item_code'] ?? ''),
                    'thumb_url' => $payload['thumb_url'] ?? null,
                    'qty' => $e['qty'],
                    'unit_price_excl' => $e['unit_price_excl'],
                    'unit_price_incl' => $unitIncl,
                    'list_unit_price_incl' => $payload['list_unit_price_incl'] ?? null,
                    'vat_rate' => $e['vat_rate'],
                    'is_service' => $e['is_service'],
                    'net_excl' => $e['net_excl'],
                    'tax' => $e['tax'],
                    'gross_incl' => $e['gross_incl'],
                    'offer_active' => !empty($payload['offer_active']),
                    'offer_label' => $payload['offer_label'] ?? null,
                ];
            }
            pos_json_out([
                'ok' => true,
                'lines' => $detail,
                'subtotal_excl' => $sum['subtotal_excl'],
                'tax_total' => $sum['tax_total'],
                'grand_incl' => $sum['grand_incl'],
                'location_id' => $cart['location_id'] ?? null,
            ]);

        case 'sale_post':
            pos_api_require_post_sale($conn, $companyId);
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                pos_json_out(['ok' => false, 'error' => 'POST required'], 405);
            }
            $client = pos_api_client();
            $cart = $_SESSION[pos_cart_key($companyId)] ?? ['lines' => [], 'location_id' => null];
            $loc = (int)($_POST['location_id'] ?? ($cart['location_id'] ?? 0));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $pmRaw = trim((string)($_POST['payment_method'] ?? ''));
            if ($client === 'retail') {
                if ($pmRaw === '' || !in_array(strtolower($pmRaw), ['cash', 'card'], true)) {
                    pos_json_out(['ok' => false, 'error' => 'payment_method required (cash or card)'], 400);
                }
            }
            $exp = pos_cart_expand_lines($conn, $companyId, $cart['lines'] ?? [], $loc > 0 ? $loc : null);
            if (!empty($exp['error'])) {
                pos_json_out(['ok' => false, 'error' => $exp['error']], 400);
            }
            $uid = (int)current_user_id();
            $pm = $pmRaw !== '' ? strtolower($pmRaw) : null;
            $sourceModule = $client === 'retail' ? 'pos_retail' : 'pos_api';
            $res = pos_post_sale($conn, $companyId, $uid, $loc > 0 ? $loc : null, $exp['lines'], $notes !== '' ? $notes : null, $pm, $sourceModule);
            if (empty($res['success'])) {
                pos_json_out(['ok' => false, 'error' => $res['error'] ?? 'Post failed'], 400);
            }
            $_SESSION[pos_cart_key($companyId)] = ['lines' => [], 'location_id' => null];
            pos_json_out([
                'ok' => true,
                'pos_sale_id' => $res['pos_sale_id'] ?? null,
                'inv_doc_id' => $res['inv_doc_id'] ?? null,
            ]);

        default:
            pos_json_out(['ok' => false, 'error' => 'Unknown action', 'actions' => [
                'search', 'categories', 'barcode', 'cart_get', 'cart_clear', 'cart_set_location', 'cart_add', 'cart_set_line_qty', 'cart_remove_line', 'cart_totals', 'sale_post',
            ]], 400);
    }
} catch (Throwable $e) {
    pos_json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
