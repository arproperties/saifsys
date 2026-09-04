<?php
/**
 * Services API Endpoint - Enhanced
 * GET /api/mobile/services.php - Get all active services
 * GET /api/mobile/services.php?id=1 - Get specific service with pricing details
 * GET /api/mobile/services.php?category_id=1 - Get services by category
 * POST /api/mobile/services.php/calculate - Calculate price based on options
 */

require_once __DIR__ . '/config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Handle price calculation endpoint
    if ($method === 'POST' && strpos($_SERVER['REQUEST_URI'], '/calculate') !== false) {
        calculatePrice();
        exit;
    }
    
    if ($method !== 'GET') {
        errorResponse('Method not allowed', 405);
    }

    // Get specific service by ID with full details
    if (isset($_GET['id'])) {
        $serviceId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        if (!$serviceId) {
            errorResponse('Invalid service ID');
        }

        $stmt = $conn->prepare("
            SELECT 
                s.*,
                c.name as category_name,
                c.parent_id as parent_category_id,
                pc.name as parent_category_name,
                pr.name as pricing_rule_name,
                pr.calculation_type
            FROM services s
            LEFT JOIN service_categories c ON s.category_id = c.id
            LEFT JOIN service_categories pc ON c.parent_id = pc.id
            LEFT JOIN pricing_rules pr ON s.pricing_rule_id = pr.id
            WHERE s.id = ? AND s.is_active = 1
        ");
        $stmt->execute([$serviceId]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$service) {
            errorResponse('Service not found', 404);
        }

        $service['image_url'] = buildMediaUrl($service['image_url'] ?? null);
        
        // Get service options
        $optionsStmt = $conn->prepare("
            SELECT * FROM service_options 
            WHERE service_id = ? 
            ORDER BY sort_order ASC
        ");
        $optionsStmt->execute([$serviceId]);
        $service['options'] = $optionsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode JSON fields
        foreach ($service['options'] as &$option) {
            $option['option_values'] = json_decode($option['option_values'], true);
        }
        
        // Get frequency discounts if allowed
        if ($service['allows_frequency']) {
            $frequencyStmt = $conn->query("
                SELECT * FROM frequency_discounts 
                WHERE is_active = 1 
                ORDER BY sort_order ASC
            ");
            $service['frequency_options'] = $frequencyStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Load item groups and items for per-item services
        if ($service['calculation_type'] === 'per_item') {
            $groupsStmt = $conn->prepare("
                SELECT 
                    sig.id,
                    sig.service_id,
                    sig.name,
                    sig.description,
                    sig.image_url,
                    sig.sort_order,
                    sig.is_active,
                    sig.created_at,
                    sig.updated_at
                FROM service_item_groups sig
                WHERE sig.service_id = ? AND sig.is_active = 1
                ORDER BY sig.sort_order ASC, sig.name ASC
            ");
            $groupsStmt->execute([$serviceId]);
            $groupRows = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

            $groupsMap = [];
            foreach ($groupRows as $groupRow) {
                $groupRow['image_url'] = buildMediaUrl($groupRow['image_url'] ?? null);
                $groupRow['items'] = [];
                $groupsMap[$groupRow['id']] = $groupRow;
            }

            $itemsStmt = $conn->prepare("
                SELECT 
                    si.id,
                    si.service_id,
                    si.group_id,
                    si.name,
                    si.description,
                    si.price,
                    si.original_price,
                    si.duration_minutes,
                    si.badge_text,
                    si.image_url,
                    si.min_quantity,
                    si.max_quantity,
                    si.default_quantity,
                    si.sort_order,
                    si.metadata
                FROM service_items si
                WHERE si.service_id = ? AND si.is_active = 1
                ORDER BY si.sort_order ASC, si.name ASC
            ");
            $itemsStmt->execute([$serviceId]);
            $itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $ungroupedItems = [];
            foreach ($itemRows as $itemRow) {
                $itemRow['image_url'] = buildMediaUrl($itemRow['image_url'] ?? null);
                if (!empty($itemRow['metadata'])) {
                    $decoded = json_decode($itemRow['metadata'], true);
                    $itemRow['metadata'] = $decoded ?: null;
                }
                $groupId = $itemRow['group_id'] ?? null;
                if ($groupId && isset($groupsMap[$groupId])) {
                    $groupsMap[$groupId]['items'][] = $itemRow;
                } else {
                    $ungroupedItems[] = $itemRow;
                }
            }

            $itemGroups = array_values($groupsMap);

            if (!empty($ungroupedItems)) {
                $itemGroups[] = [
                    'id' => 0,
                    'service_id' => $serviceId,
                    'name' => 'Other',
                    'description' => null,
                    'image_url' => null,
                    'sort_order' => 999,
                    'is_active' => 1,
                    'created_at' => null,
                    'updated_at' => null,
                    'items' => $ungroupedItems,
                ];
            }

            $service['item_groups'] = $itemGroups;
        }

        successResponse($service);
    }

    // Get services by category_id
    $categoryId = isset($_GET['category_id']) ? filter_var($_GET['category_id'], FILTER_VALIDATE_INT) : null;
    
    $sql = "
        SELECT 
            s.id,
            s.name,
            s.description,
            s.price,
            s.base_price,
            s.price_per_unit,
            s.min_price,
            s.max_price,
            s.duration_minutes,
            s.min_hours,
            s.max_hours,
            s.min_professionals,
                    s.max_professionals,
                    s.requires_materials,
                    s.materials_cost,
                    s.allows_frequency,
                    s.image_url,
            s.category_id,
            c.name as category_name,
            c.parent_id as parent_category_id,
            pc.name as parent_category_name,
            pr.calculation_type,
            s.sort_order
        FROM services s
        LEFT JOIN service_categories c ON s.category_id = c.id
        LEFT JOIN service_categories pc ON c.parent_id = pc.id
        LEFT JOIN pricing_rules pr ON s.pricing_rule_id = pr.id
        WHERE s.is_active = 1
    ";
    
    $params = [];
    
    if ($categoryId) {
        $sql .= " AND s.category_id = ?";
        $params[] = $categoryId;
    }
    
    $sql .= " ORDER BY s.sort_order ASC, s.name ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($services as &$serviceRow) {
        $serviceRow['image_url'] = buildMediaUrl($serviceRow['image_url'] ?? null);
    }
    unset($serviceRow);

    // Get categories with hierarchy
    $categoriesStmt = $conn->query("
        SELECT * FROM v_categories_tree
        WHERE is_active = 1
        ORDER BY sort_order ASC, name ASC
    ");
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

    successResponse([
        'services' => $services,
        'categories' => $categories,
        'total' => count($services)
    ]);

} catch (PDOException $e) {
    error_log("Services API Error: " . $e->getMessage());
    errorResponse('Database error occurred: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_log("Services API Error: " . $e->getMessage());
    errorResponse('An error occurred: ' . $e->getMessage(), 500);
}

/**
 * Calculate price based on service configuration and selected options
 * POST /api/mobile/services.php/calculate
 * Body: {
 *   "service_id": 1,
 *   "hours": 2,
 *   "professionals": 1,
 *   "frequency": "weekly",
 *   "materials": true,
 *   "area": 100,  // for per_sqm
 *   "rooms": 3    // for per_room
 * }
 */
function calculatePrice() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['service_id'])) {
        errorResponse('Service ID is required', 400);
    }
    
    $serviceId = filter_var($data['service_id'], FILTER_VALIDATE_INT);
    
    // Get service details
    $stmt = $conn->prepare("
        SELECT s.*, pr.calculation_type
        FROM services s
        LEFT JOIN pricing_rules pr ON s.pricing_rule_id = pr.id
        WHERE s.id = ? AND s.is_active = 1
    ");
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        errorResponse('Service not found', 404);
    }
    $itemsBreakdown = [];
    $itemDiscountTotal = 0.0;

    $hours = $data['hours'] ?? $service['min_hours'] ?? 2;
    $professionals = $data['professionals'] ?? $service['min_professionals'] ?? 1;
    $frequency = $data['frequency'] ?? 'one_time';
    $materials = $data['materials'] ?? false;
    
    // Calculate base price based on calculation type
    $basePrice = 0;
    
    switch ($service['calculation_type']) {
        case 'hourly':
            // Base price + (hours * professionals * rate_per_unit)
            $basePrice = $service['base_price'] + ($hours * $professionals * ($service['price_per_unit'] ?? 40));
            break;
            
        case 'per_sqm':
            $area = $data['area'] ?? 100;
            $basePrice = $service['base_price'] + ($area * ($service['price_per_unit'] ?? 2));
            break;
            
        case 'per_room':
            $rooms = $data['rooms'] ?? 1;
            $basePrice = $service['base_price'] + ($rooms * ($service['price_per_unit'] ?? 50));
            break;

        case 'per_item':
            $itemsInput = $data['items'] ?? [];
            if (empty($itemsInput) || !is_array($itemsInput)) {
                errorResponse('Items are required for this service', 400);
            }

            $itemIds = [];
            foreach ($itemsInput as $row) {
                if (isset($row['item_id'])) {
                    $itemIds[] = (int)$row['item_id'];
                }
            }

            $itemIds = array_values(array_unique(array_filter($itemIds)));
            if (empty($itemIds)) {
                errorResponse('Items are required for this service', 400);
            }

            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $itemsStmt = $conn->prepare("
                SELECT 
                    si.*,
                    sig.name AS group_name
                FROM service_items si
                LEFT JOIN service_item_groups sig ON si.group_id = sig.id
                WHERE si.service_id = ?
                  AND si.is_active = 1
                  AND si.id IN ($placeholders)
            ");
            $itemsStmt->execute(array_merge([$serviceId], $itemIds));
            $dbItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $itemsMap = [];
            foreach ($dbItems as $dbItem) {
                $itemsMap[$dbItem['id']] = $dbItem;
            }

            $subtotal = 0.0;

            foreach ($itemsInput as $row) {
                $itemId = isset($row['item_id']) ? (int)$row['item_id'] : 0;
                $quantity = isset($row['quantity']) ? (int)$row['quantity'] : 0;

                if ($itemId <= 0 || $quantity <= 0) {
                    continue;
                }

                if (!isset($itemsMap[$itemId])) {
                    continue;
                }

                $itemData = $itemsMap[$itemId];
                $unitPrice = (float)$itemData['price'];
                $lineTotal = $unitPrice * $quantity;
                $subtotal += $lineTotal;

                $originalPrice = isset($itemData['original_price'])
                    ? (float)$itemData['original_price']
                    : null;
                $lineDiscount = 0.0;
                if ($originalPrice !== null && $originalPrice > $unitPrice) {
                    $lineDiscount = ($originalPrice - $unitPrice) * $quantity;
                    $itemDiscountTotal += $lineDiscount;
                }

                $itemsBreakdown[] = [
                    'item_id' => $itemId,
                    'name' => $itemData['name'],
                    'group_name' => $itemData['group_name'],
                    'quantity' => $quantity,
                    'unit_price' => round($unitPrice, 2),
                    'original_price' => $originalPrice !== null ? round($originalPrice, 2) : null,
                    'line_total' => round($lineTotal, 2),
                    'line_discount' => round($lineDiscount, 2),
                    'duration_minutes' => $itemData['duration_minutes'] !== null
                        ? (int)$itemData['duration_minutes']
                        : null,
                ];
            }

            if ($subtotal <= 0) {
                errorResponse('Selected items are invalid', 400);
            }

            $basePrice = $subtotal;
            break;
            
        case 'fixed':
        default:
            $basePrice = $service['base_price'] ?: $service['price'];
            break;
    }
    
    // Apply materials cost if required
    if ($materials && $service['requires_materials']) {
        $basePrice += ($service['materials_cost'] ?? 20); // Materials fee from database
    }
    
    // Apply frequency discount
    $discount = 0;
    $discountRow = null;
    if ($frequency !== 'one_time') {
        $discountStmt = $conn->prepare("
            SELECT discount_percentage 
            FROM frequency_discounts 
            WHERE frequency_type = ? AND is_active = 1
        ");
        $discountStmt->execute([$frequency]);
        $discountRow = $discountStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($discountRow) {
            $discount = ($basePrice * $discountRow['discount_percentage']) / 100;
        }
    }
    
    $subtotal = $basePrice;
    $discountAmount = $discount;
    $afterDiscount = $basePrice - $discount;
    
    // Apply min/max price limits
    if ($service['min_price'] && $afterDiscount < $service['min_price']) {
        $afterDiscount = $service['min_price'];
    }
    if ($service['max_price'] && $afterDiscount > $service['max_price']) {
        $afterDiscount = $service['max_price'];
    }
    
    // Get service fee percentage from settings
    $settingsStmt = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'service_fee_percentage'");
    $serviceFeePercent = $settingsStmt ? floatval($settingsStmt->fetchColumn()) : 5.0;
    
    // Get VAT percentage from settings
    $vatStmt = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'vat_percentage'");
    $vatPercent = $vatStmt ? floatval($vatStmt->fetchColumn()) : 5.0;
    
    // Calculate fees
    $serviceFee = ($afterDiscount * $serviceFeePercent) / 100;
    $beforeVat = $afterDiscount + $serviceFee;
    $vat = ($beforeVat * $vatPercent) / 100;
    $finalTotal = $beforeVat + $vat;
    
    successResponse([
        'subtotal' => round($subtotal, 2),
        'discount' => round($discountAmount, 2),
        'items_discount' => round($itemDiscountTotal, 2),
        'discount_percentage' => $discountRow['discount_percentage'] ?? 0,
        'after_discount' => round($afterDiscount, 2),
        'service_fee' => round($serviceFee, 2),
        'service_fee_percentage' => $serviceFeePercent,
        'before_vat' => round($beforeVat, 2),
        'vat' => round($vat, 2),
        'vat_percentage' => $vatPercent,
        'total' => round($finalTotal, 2),
        'currency' => 'AED',
        'breakdown' => [
            'base_price' => $service['base_price'],
            'hours' => $hours,
            'professionals' => $professionals,
            'materials_included' => $materials,
            'frequency' => $frequency,
            'calculation_type' => $service['calculation_type'],
            'items' => $itemsBreakdown ?? [],
        ]
    ]);
}

