<?php
/**
 * Discounts API
 * GET /discounts.php - Get active discounts
 * POST /discounts.php?action=calculate - Calculate discount for a booking
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        handleGet();
    } elseif ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'calculate') {
        handleCalculate();
    } else {
        errorResponse('Method not allowed', 405);
    }
} catch (PDOException $e) {
    error_log("Discounts API Error: " . $e->getMessage());
    errorResponse('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Discounts API Error: " . $e->getMessage());
    errorResponse('An error occurred', 500);
}

function handleGet() {
    global $conn;
    
    // Get active discounts within date range
    $query = "
        SELECT 
            d.id,
            d.title,
            d.discount_type,
            d.category_id,
            d.service_id,
            d.zone_id,
            d.amount_type,
            d.amount,
            d.min_purchase_amount,
            d.max_discount_amount,
            d.start_date,
            d.end_date
        FROM discounts d
        WHERE d.is_active = 1
            AND d.start_date <= NOW()
            AND d.end_date >= NOW()
        ORDER BY d.created_at DESC
    ";
    
    $stmt = $conn->query($query);
    $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    successResponse([
        'discounts' => $discounts,
        'total' => count($discounts)
    ]);
}

function handleCalculate() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['service_id']) || !isset($data['total_amount'])) {
        errorResponse('Missing required fields: service_id and total_amount', 400);
    }
    
    $serviceId = (int)$data['service_id'];
    $categoryId = isset($data['category_id']) ? (int)$data['category_id'] : null;
    $totalAmount = (float)$data['total_amount'];
    $zoneId = isset($data['zone_id']) ? (int)$data['zone_id'] : null;
    
    // Get applicable discounts
    $query = "
        SELECT 
            d.id,
            d.title,
            d.discount_type,
            d.category_id,
            d.service_id,
            d.zone_id,
            d.amount_type,
            d.amount,
            d.min_purchase_amount,
            d.max_discount_amount
        FROM discounts d
        WHERE d.is_active = 1
            AND d.start_date <= NOW()
            AND d.end_date >= NOW()
            AND d.min_purchase_amount <= ?
            AND (
                (d.discount_type = 'service' AND d.service_id = ?)
                OR (d.discount_type = 'category' AND d.category_id = ?)
                OR (d.discount_type = 'mixed' AND (d.service_id = ? OR d.category_id = ?))
            )
            AND (d.zone_id IS NULL OR d.zone_id = ?)
        ORDER BY d.amount DESC
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        $totalAmount,
        $serviceId,
        $categoryId,
        $serviceId,
        $categoryId,
        $zoneId
    ]);
    
    $discount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$discount) {
        successResponse([
            'discount_applied' => false,
            'discount_amount' => 0,
            'final_amount' => $totalAmount
        ]);
        return;
    }
    
    // Calculate discount amount
    $discountAmount = 0;
    
    if ($discount['amount_type'] === 'percentage') {
        $discountAmount = ($totalAmount * $discount['amount']) / 100;
        
        // Apply max discount limit if set
        if ($discount['max_discount_amount'] !== null && $discountAmount > $discount['max_discount_amount']) {
            $discountAmount = $discount['max_discount_amount'];
        }
    } else {
        $discountAmount = $discount['amount'];
        
        // Don't exceed the total amount
        if ($discountAmount > $totalAmount) {
            $discountAmount = $totalAmount;
        }
    }
    
    $finalAmount = $totalAmount - $discountAmount;
    
    successResponse([
        'discount_applied' => true,
        'discount_id' => $discount['id'],
        'discount_title' => $discount['title'],
        'discount_amount' => round($discountAmount, 2),
        'original_amount' => $totalAmount,
        'final_amount' => round($finalAmount, 2)
    ]);
}
