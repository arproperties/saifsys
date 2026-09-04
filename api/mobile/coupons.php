<?php
/**
 * Coupons API
 * GET /coupons.php - Get available coupons for a service/category
 * POST /coupons.php?action=validate - Validate and apply a coupon
 */

// Define constant to exclude from rate limiting
define('SKIP_RATE_LIMIT', true);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        handleGet();
    } elseif ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'validate') {
        handleValidate();
    } else {
        errorResponse('Method not allowed', 405);
    }
} catch (PDOException $e) {
    error_log("Coupons API Error: " . $e->getMessage());
    errorResponse('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Coupons API Error: " . $e->getMessage());
    errorResponse('An error occurred', 500);
}

function handleGet() {
    global $conn;
    
    $serviceId = isset($_GET['service_id']) ? (int)$_GET['service_id'] : null;
    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $totalAmount = isset($_GET['total_amount']) ? (float)$_GET['total_amount'] : 0;
    $zoneId = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : null;
    $debug = isset($_GET['debug']) && $_GET['debug'] === '1';
    
    // Log for debugging
    error_log("Coupons API - serviceId: $serviceId, categoryId: $categoryId, totalAmount: $totalAmount, zoneId: $zoneId, debug: $debug");
    
    // Build query to get applicable coupons
    $where = ['c.is_active = 1', 'c.start_date <= NOW()', 'c.end_date >= NOW()'];
    $params = [];
    
    // Debug mode: skip all filters except active and date range
    if (!$debug) {
        // Filter by minimum purchase amount if total amount is provided
    // Only filter if totalAmount > 0, otherwise show all coupons (validation will happen later)
    if ($totalAmount > 0) {
        $where[] = '(c.min_purchase_amount = 0 OR c.min_purchase_amount <= ?)';
        $params[] = $totalAmount;
    } else {
        // If totalAmount is 0 or not provided, still show coupons (they'll be validated when applied)
        // But we can optionally filter out coupons with very high min amounts
        // For now, show all coupons
    }
    
    // Filter by service/category if provided
    // If serviceId or categoryId is provided, show coupons that match
    // If neither is provided, show all active coupons (they'll be filtered client-side)
    if ($serviceId || $categoryId) {
        $typeConditions = [];
        
        // Service-specific coupons: match if discount_type is 'service' and service_id matches
        if ($serviceId) {
            $typeConditions[] = "(c.discount_type = 'service' AND c.service_id = ?)";
            $params[] = $serviceId;
        }
        
        // Category-specific coupons: match if discount_type is 'category' and category_id matches
        if ($categoryId) {
            $typeConditions[] = "(c.discount_type = 'category' AND c.category_id = ?)";
            $params[] = $categoryId;
        }
        
        // Mixed coupons: match if discount_type is 'mixed' AND (service matches OR category matches)
        if ($serviceId && $categoryId) {
            // If both provided, match mixed coupons where either service OR category matches
            $typeConditions[] = "(c.discount_type = 'mixed' AND (c.service_id = ? OR c.category_id = ?))";
            $params[] = $serviceId;
            $params[] = $categoryId;
        } elseif ($serviceId) {
            // If only serviceId provided, match mixed coupons with this service
            $typeConditions[] = "(c.discount_type = 'mixed' AND c.service_id = ?)";
            $params[] = $serviceId;
        } elseif ($categoryId) {
            // If only categoryId provided, match mixed coupons with this category
            $typeConditions[] = "(c.discount_type = 'mixed' AND c.category_id = ?)";
            $params[] = $categoryId;
        }
        
        if (!empty($typeConditions)) {
            $where[] = '(' . implode(' OR ', $typeConditions) . ')';
        }
    }
    
    // Zone filter removed - coupons are not filtered by zone
    // All active coupons will be returned regardless of zone_id value
    } // End of if (!$debug) block
    
    $whereClause = implode(' AND ', $where);
    
    $query = "
        SELECT 
            c.id,
            c.coupon_code,
            c.title,
            c.title_en,
            c.title_ar,
            c.discount_type,
            c.category_id,
            c.service_id,
            c.zone_id,
            c.amount_type,
            c.amount,
            c.min_purchase_amount,
            c.max_discount_amount,
            c.start_date,
            c.end_date,
            c.limit_per_user
        FROM coupons c
        WHERE $whereClause
        ORDER BY c.amount DESC, c.created_at DESC
    ";
    
    error_log("Coupons API Query: $query");
    error_log("Coupons API Params: " . json_encode($params));
    
    // Debug: Check total active coupons in database first
    $debugStmt = $conn->query("
        SELECT COUNT(*) as total_active 
        FROM coupons 
        WHERE is_active = 1 
        AND start_date <= NOW() 
        AND end_date >= NOW()
    ");
    $debugResult = $debugStmt->fetch(PDO::FETCH_ASSOC);
    error_log("Total active coupons in DB (no filters): " . $debugResult['total_active']);
    
    // Debug: Check all active coupons details
    $allCouponsStmt = $conn->query("
        SELECT id, coupon_code, discount_type, service_id, category_id, min_purchase_amount, start_date, end_date, zone_id
        FROM coupons 
        WHERE is_active = 1 
        AND start_date <= NOW() 
        AND end_date >= NOW()
    ");
    $allCoupons = $allCouponsStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("All active coupons: " . json_encode($allCoupons));
    
    // Debug: Check coupons matching service_id
    if ($serviceId) {
        $debugServiceStmt = $conn->prepare("
            SELECT id, coupon_code, discount_type, service_id, category_id, min_purchase_amount, start_date, end_date
            FROM coupons 
            WHERE is_active = 1 
            AND start_date <= NOW() 
            AND end_date >= NOW()
            AND service_id = ?
        ");
        $debugServiceStmt->execute([$serviceId]);
        $debugServiceCoupons = $debugServiceStmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Coupons matching service_id $serviceId: " . count($debugServiceCoupons));
        if (count($debugServiceCoupons) > 0) {
            error_log("Service coupons details: " . json_encode($debugServiceCoupons));
        }
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Coupons API Found: " . count($coupons) . " coupons after filters");
    if (count($coupons) > 0) {
        error_log("Returned coupons: " . json_encode(array_map(function($c) {
            return ['id' => $c['id'], 'code' => $c['coupon_code'], 'type' => $c['discount_type']];
        }, $coupons)));
    }
    
    successResponse([
        'coupons' => $coupons,
        'total' => count($coupons),
        'debug' => [
            'filters' => [
                'service_id' => $serviceId,
                'category_id' => $categoryId,
                'total_amount' => $totalAmount,
                'zone_id' => $zoneId,
            ],
            'total_active_in_db' => (int)$debugResult['total_active']
        ]
    ]);
}

function handleValidate() {
    global $conn;
    
    $payload = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($payload['coupon_code'])) {
        errorResponse('Coupon code is required', 400);
    }
    
    $couponCode = strtoupper(trim($payload['coupon_code']));
    $serviceId = isset($payload['service_id']) ? (int)$payload['service_id'] : null;
    $categoryId = isset($payload['category_id']) ? (int)$payload['category_id'] : null;
    $totalAmount = isset($payload['total_amount']) ? (float)$payload['total_amount'] : 0;
    $zoneId = isset($payload['zone_id']) ? (int)$payload['zone_id'] : null;
    $customerId = isset($payload['customer_id']) ? (int)$payload['customer_id'] : null;
    
    // Get coupon
    $stmt = $conn->prepare("
        SELECT * FROM coupons 
        WHERE coupon_code = ? AND is_active = 1
            AND start_date <= NOW() AND end_date >= NOW()
    ");
    $stmt->execute([$couponCode]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$coupon) {
        errorResponse('Invalid or expired coupon code', 400);
    }
    
    // Check minimum purchase amount
    if ($totalAmount < $coupon['min_purchase_amount']) {
        errorResponse('Minimum purchase amount not met', 400);
    }
    
    // Check discount type matching
    $isApplicable = false;
    if ($coupon['discount_type'] === 'service' && $coupon['service_id'] == $serviceId) {
        $isApplicable = true;
    } elseif ($coupon['discount_type'] === 'category' && $coupon['category_id'] == $categoryId) {
        $isApplicable = true;
    } elseif ($coupon['discount_type'] === 'mixed') {
        if (($coupon['service_id'] == $serviceId) || ($coupon['category_id'] == $categoryId)) {
            $isApplicable = true;
        }
    }
    
    if (!$isApplicable) {
        errorResponse('Coupon is not applicable to this service/category', 400);
    }
    
    // Zone check removed - coupons are not restricted by zone
    
    // Check user limit if customer is provided
    if ($customerId && $coupon['limit_per_user'] !== null) {
        $usageStmt = $conn->prepare("
            SELECT COUNT(*) as usage_count 
            FROM coupon_usages 
            WHERE coupon_id = ? AND customer_id = ?
        ");
        $usageStmt->execute([$coupon['id'], $customerId]);
        $usage = $usageStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usage['usage_count'] >= $coupon['limit_per_user']) {
            errorResponse('You have reached the maximum usage limit for this coupon', 400);
        }
    }
    
    // Calculate discount amount
    $discountAmount = 0;
    
    if ($coupon['amount_type'] === 'percentage') {
        $discountAmount = ($totalAmount * $coupon['amount']) / 100;
        
        // Apply max discount limit if set
        if ($coupon['max_discount_amount'] !== null && $discountAmount > $coupon['max_discount_amount']) {
            $discountAmount = $coupon['max_discount_amount'];
        }
    } else {
        $discountAmount = $coupon['amount'];
        
        // Don't exceed the total amount
        if ($discountAmount > $totalAmount) {
            $discountAmount = $totalAmount;
        }
    }
    
    $finalAmount = $totalAmount - $discountAmount;
    
    successResponse([
        'valid' => true,
        'coupon_id' => $coupon['id'],
        'coupon_code' => $coupon['coupon_code'],
        'title' => $coupon['title'],
        'discount_amount' => round($discountAmount, 2),
        'original_amount' => $totalAmount,
        'final_amount' => round($finalAmount, 2)
    ]);
}
