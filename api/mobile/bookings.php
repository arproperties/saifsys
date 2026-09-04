<?php
/**
 * Bookings API Endpoint
 * GET /api/mobile/bookings.php - Get customer bookings (requires auth)
 * POST /api/mobile/bookings.php - Create new booking (guest or authenticated)
 * PUT /api/mobile/bookings.php - Update booking (cancel, etc.) (requires auth)
 */

require_once __DIR__ . '/config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Retrieve customer bookings (requires authentication)
    if ($method === 'GET') {
        $auth = requireAuth();
        $clientId = $auth['client_id'] ?? null;
        $clientPhone = $auth['phone'] ?? null;

        if (!$clientId) {
            errorResponse('Invalid authentication', 401);
        }

        $stmt = $conn->prepare("
            SELECT 
                ob.id,
                ob.service_id,
                s.name as service_name,
                ob.employee_id,
                e.full_name as worker_name,
                ob.work_order_id,
                ob.scheduled_date,
                ob.scheduled_time,
                ob.customer_name,
                ob.customer_phone,
                ob.customer_email,
                ob.address,
                ob.latitude,
                ob.longitude,
                ob.notes,
                ob.subtotal,
                ob.discount_amount as promotional_discount_amount,
                c.coupon_code,
                cu.discount_amount as coupon_discount_amount,
                ob.total_price,
                ob.status,
                ob.created_at,
                ob.confirmed_at,
                ob.payment_method,
                ob.wallet_amount_used,
                ob.hours,
                ob.professionals,
                ob.materials_included,
                ob.frequency,
                ob.vat,
                ob.service_fee,
                ob.instructions,
                ob.weekly_schedule
            FROM online_bookings ob
            LEFT JOIN services s ON ob.service_id = s.id
            LEFT JOIN employees e ON ob.employee_id = e.id
            LEFT JOIN coupon_usages cu ON ob.id = cu.booking_id
            LEFT JOIN coupons c ON cu.coupon_id = c.id
            WHERE ob.client_id = ?
            ORDER BY ob.scheduled_date DESC, ob.scheduled_time DESC
        ");
        $stmt->execute([$clientId]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Include bookings that match the authenticated phone number (legacy compatibility)
        if ($clientPhone) {
            $phoneStmt = $conn->prepare("
                SELECT 
                    ob.id,
                    ob.service_id,
                    s.name as service_name,
                    ob.employee_id,
                    e.full_name as worker_name,
                    ob.work_order_id,
                    ob.scheduled_date,
                    ob.scheduled_time,
                    ob.customer_name,
                    ob.customer_phone,
                    ob.customer_email,
                    ob.address,
                    ob.latitude,
                    ob.longitude,
                    ob.notes,
                    ob.subtotal,
                    ob.discount_amount as promotional_discount_amount,
                    c.coupon_code,
                    cu.discount_amount as coupon_discount_amount,
                    ob.total_price,
                    ob.status,
                    ob.created_at,
                    ob.confirmed_at,
                    ob.payment_method,
                    ob.wallet_amount_used,
                    ob.hours,
                    ob.professionals,
                    ob.materials_included,
                    ob.frequency,
                    ob.vat,
                    ob.service_fee,
                    ob.instructions,
                    ob.weekly_schedule
                FROM online_bookings ob
                LEFT JOIN services s ON ob.service_id = s.id
                LEFT JOIN employees e ON ob.employee_id = e.id
                LEFT JOIN coupon_usages cu ON ob.id = cu.booking_id
                LEFT JOIN coupons c ON cu.coupon_id = c.id
                WHERE ob.customer_phone = ?
                ORDER BY ob.scheduled_date DESC, ob.scheduled_time DESC
            ");
            $phoneStmt->execute([$clientPhone]);
            $phoneBookings = $phoneStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($phoneBookings)) {
                $bookingsById = [];
                foreach ($bookings as $booking) {
                    $bookingsById[$booking['id']] = $booking;
                }
                foreach ($phoneBookings as $phoneBooking) {
                    if (!isset($bookingsById[$phoneBooking['id']])) {
                        $bookingsById[$phoneBooking['id']] = $phoneBooking;
                    }
                }

                // Re-index and sort by scheduled date/time descending
                $bookings = array_values($bookingsById);
                usort($bookings, function ($a, $b) {
                    $aDateTime = $a['scheduled_date'] . ' ' . $a['scheduled_time'];
                    $bDateTime = $b['scheduled_date'] . ' ' . $b['scheduled_time'];
                    return strcmp($bDateTime, $aDateTime);
                });
            }
        }

        // Prepare statement to load booking items
        $itemsStmt = $conn->prepare("
            SELECT 
                service_item_id,
                item_name,
                group_name,
                quantity,
                unit_price,
                original_price,
                line_total,
                duration_minutes
            FROM online_booking_items
            WHERE booking_id = ?
            ORDER BY sort_order ASC, id ASC
        ");

        // Get assigned workers from orders for bookings that have work_order_id
        foreach ($bookings as &$booking) {
            $workOrderId = $booking['work_order_id'] ?? null;
            
            if ($workOrderId) {
                // Get all workers assigned to this order
                $workersStmt = $conn->prepare("
                    SELECT 
                        w.id,
                        w.nickname,
                        w.worker_name,
                        e.full_name
                    FROM order_workers ow
                    INNER JOIN workers w ON ow.worker_id = w.id
                    LEFT JOIN employees e ON w.emp_num = e.employee_code
                    WHERE ow.order_id = ?
                    ORDER BY w.nickname, e.full_name
                ");
                $workersStmt->execute([$workOrderId]);
                $workers = $workersStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Build worker names array and comma-separated string
                $workerNames = [];
                foreach ($workers as $worker) {
                    $name = $worker['full_name'] ?: $worker['nickname'] ?: $worker['worker_name'] ?: 'Worker';
                    $workerNames[] = $name;
                }
                
                // Set worker_name to comma-separated list (for backward compatibility)
                $booking['worker_name'] = !empty($workerNames) ? implode(', ', $workerNames) : ($booking['worker_name'] ?? null);
                
                // Add workers array for mobile app
                $booking['workers'] = array_map(function($w) {
                    return [
                        'id' => $w['id'],
                        'name' => $w['full_name'] ?: $w['nickname'] ?: $w['worker_name'] ?: 'Worker'
                    ];
                }, $workers);
            } else {
                // No order yet, use single worker_name if available
                $booking['workers'] = $booking['worker_name'] ? [[
                    'id' => $booking['employee_id'],
                    'name' => $booking['worker_name']
                ]] : [];
            }

            // Attach itemized selection if present
            if (!empty($itemsStmt)) {
                $itemsStmt->execute([$booking['id']]);
                $booking['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $booking['items'] = [];
            }
        }

        successResponse($bookings);
    }

    // POST - Create new booking
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        error_log("Booking creation attempt - Input received: " . json_encode($input));
        
        if (!$input) {
            error_log("Booking creation failed - Invalid JSON input");
            errorResponse('Invalid JSON input');
        }

        // Validate required fields (customer_email is optional)
        validateRequired($input, [
            'service_id', 'scheduled_date', 'scheduled_time',
            'customer_name', 'customer_phone',
            'address', 'total_price'
        ]);

        // Sanitize input
        $serviceId = filter_var($input['service_id'], FILTER_VALIDATE_INT);
        $workerId = isset($input['worker_id']) ? filter_var($input['worker_id'], FILTER_VALIDATE_INT) : null;
        $scheduledDate = sanitizeInput($input['scheduled_date']);
        
        // Extract start time from time slot (e.g., "09:00-09:30" -> "09:00")
        $scheduledTimeRaw = sanitizeInput($input['scheduled_time']);
        $scheduledTime = explode('-', $scheduledTimeRaw)[0]; // Get start time only
        
        $customerName = sanitizeInput($input['customer_name']);
        $customerPhone = sanitizeInput($input['customer_phone']);
        $customerEmail = isset($input['customer_email']) ? sanitizeInput($input['customer_email']) : null;
        $address = sanitizeInput($input['address']);
        $notes = isset($input['notes']) ? sanitizeInput($input['notes']) : null;
        $instructions = isset($input['instructions']) ? sanitizeInput($input['instructions']) : null;
        $latitude = isset($input['latitude']) ? filter_var($input['latitude'], FILTER_VALIDATE_FLOAT) : null;
        $longitude = isset($input['longitude']) ? filter_var($input['longitude'], FILTER_VALIDATE_FLOAT) : null;
        $latitude = $latitude !== false ? $latitude : null;
        $longitude = $longitude !== false ? $longitude : null;
        
        // New fields from enhanced booking flow
        $hours = isset($input['hours']) ? filter_var($input['hours'], FILTER_VALIDATE_FLOAT) : 2.0;
        $professionals = isset($input['professionals']) ? filter_var($input['professionals'], FILTER_VALIDATE_INT) : 1;
        $materialsIncluded = isset($input['needs_materials']) ? (int)(bool)$input['needs_materials'] : 0;
        $frequency = isset($input['frequency']) ? sanitizeInput($input['frequency']) : 'one_time';
        
        // Weekly schedule for multiple times per week bookings
        $weeklySchedule = null;
        if ($frequency === 'multiple' && isset($input['weekly_schedule']) && is_array($input['weekly_schedule'])) {
            $weeklySchedule = json_encode($input['weekly_schedule']);
        }
        
        // Price breakdown
        $subtotal = isset($input['subtotal']) ? filter_var($input['subtotal'], FILTER_VALIDATE_FLOAT) : 0;
        $discountAmount = isset($input['discount']) ? filter_var($input['discount'], FILTER_VALIDATE_FLOAT) : 0;
        $serviceFee = isset($input['service_fee']) ? filter_var($input['service_fee'], FILTER_VALIDATE_FLOAT) : 0;
        $vat = isset($input['vat']) ? filter_var($input['vat'], FILTER_VALIDATE_FLOAT) : 0;
        $totalPrice = filter_var($input['total_price'], FILTER_VALIDATE_FLOAT) ?: filter_var($input['total'], FILTER_VALIDATE_FLOAT);
        
        // Coupon information (optional)
        $couponCode = isset($input['coupon_code']) ? sanitizeInput($input['coupon_code']) : null;
        $couponDiscountAmount = isset($input['coupon_discount']) ? filter_var($input['coupon_discount'], FILTER_VALIDATE_FLOAT) : 0;

        // Validate service exists and fetch calculation type
        $serviceStmt = $conn->prepare("
            SELECT 
                s.id,
                pr.calculation_type
            FROM services s
            LEFT JOIN pricing_rules pr ON s.pricing_rule_id = pr.id
            WHERE s.id = ? AND s.is_active = 1
        ");
        $serviceStmt->execute([$serviceId]);
        $serviceRow = $serviceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$serviceRow) {
            errorResponse('Invalid service', 400);
        }
        $calculationType = $serviceRow['calculation_type'] ?? 'hourly';

        // Attempt to get authenticated client from JWT (optional)
        $authClientId = null;
        $authMobileUserId = null;
        try {
            $token = getJWTFromHeader();
            if ($token) {
                $payload = verifyJWT($token);
                if ($payload && isset($payload['client_id'])) {
                    $authClientId = (int)$payload['client_id'];
                }
                if ($payload && isset($payload['user_id'])) {
                    $authMobileUserId = (int)$payload['user_id'];
                }
            }
        } catch (Exception $e) {
            // Ignore optional auth errors for guest bookings
        }

        // Check or create client (management system integration)
        $clientId = null;

        if ($authClientId) {
            $clientByIdStmt = $conn->prepare("SELECT id FROM client WHERE id = ?");
            $clientByIdStmt->execute([$authClientId]);
            $clientById = $clientByIdStmt->fetch(PDO::FETCH_ASSOC);
            if ($clientById) {
                $clientId = (int)$clientById['id'];
            }
        }

        if (!$clientId) {
            $clientStmt = $conn->prepare("SELECT id FROM client WHERE mobile_num = ?");
            $clientStmt->execute([$customerPhone]);
            $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
            if ($client) {
                $clientId = (int)$client['id'];
            }
        }

        if ($clientId) {
            // Update client info with latest details
            $updateStmt = $conn->prepare("
                UPDATE client 
                SET client_name = ?, email = ?, address = ?, mobile_num = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$customerName, $customerEmail, $address, $customerPhone, $clientId]);
        } else {
            // Create new client
            $insertClientStmt = $conn->prepare("
                INSERT INTO client (client_name, mobile_num, cell_num, email, address, rate, payment, terms, is_active, currency, client_status)
                VALUES (?, ?, '', ?, ?, 0.00, 'D', 'cash', 1, 'AED', 'active')
            ");
            $insertClientStmt->execute([$customerName, $customerPhone, $customerEmail, $address]);
            $clientId = (int)$conn->lastInsertId();

            // If this user is authenticated, link the mobile user to the new client
            if ($authMobileUserId) {
                $linkStmt = $conn->prepare("UPDATE mobile_users SET client_id = ? WHERE id = ?");
                $linkStmt->execute([$clientId, $authMobileUserId]);
                $authClientId = $clientId;
            }
        }

        // Ensure authenticated client uses the resolved client ID for this booking
        if ($authClientId) {
            $clientId = $authClientId;
        }

        $itemsInput = $input['items'] ?? [];
        $selectedItems = [];
        $calculatedMinutes = 0;
        if ($calculationType === 'per_item') {
            if (empty($itemsInput) || !is_array($itemsInput)) {
                errorResponse('Please select at least one service item', 400);
            }

            $itemIds = [];
            foreach ($itemsInput as $row) {
                if (isset($row['item_id'])) {
                    $itemIds[] = (int)$row['item_id'];
                }
            }

            $itemIds = array_values(array_unique(array_filter($itemIds)));
            if (empty($itemIds)) {
                errorResponse('Please select at least one service item', 400);
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

            $calculatedSubtotal = 0.0;
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
                $originalPrice = $itemData['original_price'] !== null
                    ? (float)$itemData['original_price']
                    : null;
                $durationMinutes = $itemData['duration_minutes'] !== null
                    ? (int)$itemData['duration_minutes']
                    : null;

                $selectedItems[] = [
                    'service_item_id' => $itemId,
                    'item_name' => $itemData['name'],
                    'group_name' => $itemData['group_name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'original_price' => $originalPrice,
                    'line_total' => $lineTotal,
                    'duration_minutes' => $durationMinutes,
                ];

                $calculatedSubtotal += $lineTotal;
                if ($durationMinutes !== null) {
                    $calculatedMinutes += ($durationMinutes * $quantity);
                }
            }

            if (empty($selectedItems)) {
                errorResponse('Selected service items are invalid', 400);
            }

            if ($calculatedSubtotal <= 0) {
                errorResponse('Invalid item pricing. Please try again.', 400);
            }

            $subtotal = $calculatedSubtotal;
            if ($calculatedMinutes > 0) {
                $hours = max(1, (int)ceil($calculatedMinutes / 60));
            }
        }

        // Handle wallet payment if specified
        $paymentMethod = isset($input['payment_method']) ? sanitizeInput($input['payment_method']) : 'cash';
        $walletAmountUsed = isset($input['wallet_amount_used']) ? filter_var($input['wallet_amount_used'], FILTER_VALIDATE_FLOAT) : 0;

        // Create booking with all enhanced fields including payment method
        $bookingStmt = $conn->prepare("
            INSERT INTO online_bookings (
                client_id, service_id, employee_id, scheduled_date, scheduled_time,
                customer_name, customer_phone, customer_email, address, latitude, longitude, notes, instructions,
                hours, professionals, materials_included, frequency, weekly_schedule,
                subtotal, discount_amount, service_fee, vat, total_price, 
                payment_method, wallet_amount_used, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $bookingStmt->execute([
            $clientId,
            $serviceId,
            $workerId,
            $scheduledDate,
            $scheduledTime,
            $customerName,
            $customerPhone,
            $customerEmail,
            $address,
            $latitude,
            $longitude,
            $notes,
            $instructions,
            $hours,
            $professionals,
            $materialsIncluded,
            $frequency,
            $weeklySchedule,
            $subtotal,
            $discountAmount,
            $serviceFee,
            $vat,
            $totalPrice,
            $paymentMethod,
            $walletAmountUsed,
            'pending'
        ]);

        $bookingId = $conn->lastInsertId();

        // Create coupon usage record if coupon was applied
        if (!empty($couponCode) && $couponDiscountAmount > 0 && $clientId) {
            try {
                // Get coupon ID
                $couponStmt = $conn->prepare("SELECT id FROM coupons WHERE coupon_code = ? AND is_active = 1");
                $couponStmt->execute([strtoupper(trim($couponCode))]);
                $coupon = $couponStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($coupon) {
                    // Create coupon usage entry
                    $couponUsageStmt = $conn->prepare("
                        INSERT INTO coupon_usages (coupon_id, customer_id, booking_id, discount_amount)
                        VALUES (?, ?, ?, ?)
                    ");
                    $couponUsageStmt->execute([
                        $coupon['id'],
                        $clientId,
                        $bookingId,
                        $couponDiscountAmount
                    ]);
                    error_log("✅ Coupon usage recorded: {$couponCode} - AED {$couponDiscountAmount} for booking #{$bookingId}");
                } else {
                    error_log("⚠️ Coupon code not found in database: {$couponCode}");
                }
            } catch (Exception $e) {
                error_log("⚠️ Failed to record coupon usage: " . $e->getMessage());
                // Don't fail the booking creation if coupon recording fails
            }
        }

        if (!empty($selectedItems)) {
            $bookingItemInsert = $conn->prepare("
                INSERT INTO online_booking_items (
                    booking_id,
                    service_item_id,
                    item_name,
                    group_name,
                    quantity,
                    unit_price,
                    original_price,
                    line_total,
                    duration_minutes,
                    sort_order,
                    metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $sortOrder = 1;
            foreach ($selectedItems as $item) {
                $metadata = json_encode([
                    'source' => 'mobile_app',
                    'calculation_type' => $calculationType,
                ]);
                $bookingItemInsert->execute([
                    $bookingId,
                    $item['service_item_id'],
                    $item['item_name'],
                    $item['group_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['original_price'],
                    $item['line_total'],
                    $item['duration_minutes'],
                    $sortOrder++,
                    $metadata,
                ]);
            }
        }

        error_log("Booking created successfully - ID: " . $bookingId . " | Payment Method: " . $paymentMethod . " | Wallet Amount: " . $walletAmountUsed);
        
        if ($paymentMethod === 'wallet' || ($paymentMethod === 'wallet_partial' && $walletAmountUsed > 0)) {
            // Get or create wallet
            $walletStmt = $conn->prepare("SELECT * FROM mobile_user_wallet WHERE client_id = ?");
            $walletStmt->execute([$clientId]);
            $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                // Create wallet if it doesn't exist
                $createStmt = $conn->prepare("
                    INSERT INTO mobile_user_wallet (client_id, balance, currency)
                    VALUES (?, 0.00, 'AED')
                ");
                $createStmt->execute([$clientId]);
                $walletId = $conn->lastInsertId();
                
                $walletStmt->execute([$clientId]);
                $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($wallet) {
                $amountToPay = $paymentMethod === 'wallet' ? $totalPrice : min($walletAmountUsed, $totalPrice);
                
                if ($wallet['balance'] >= $amountToPay) {
                    // Process wallet payment
                    $newBalance = $wallet['balance'] - $amountToPay;
                    $updateWalletStmt = $conn->prepare("UPDATE mobile_user_wallet SET balance = ? WHERE id = ?");
                    $updateWalletStmt->execute([$newBalance, $wallet['id']]);

                    // Create transaction
                    $transactionStmt = $conn->prepare("
                        INSERT INTO mobile_wallet_transactions (
                            wallet_id, client_id, transaction_type, amount,
                            balance_before, balance_after, transaction_category,
                            description, reference_id, reference_type
                        ) VALUES (?, ?, 'debit', ?, ?, ?, 'payment', ?, ?, 'booking')
                    ");
                    $transactionStmt->execute([
                        $wallet['id'],
                        $clientId,
                        $amountToPay,
                        $wallet['balance'],
                        $newBalance,
                        "Payment for booking #{$bookingId}",
                        $bookingId,
                    ]);

                    error_log("✅ Wallet payment processed: AED {$amountToPay} for booking #{$bookingId} (New balance: {$newBalance})");
                } else {
                    error_log("⚠️ Insufficient wallet balance: {$wallet['balance']} < {$amountToPay}");
                }
            }
        }

        // Log booking event (optional - don't fail if this errors)
        try {
            if (function_exists('logBookingEvent')) {
                logBookingEvent($conn, $bookingId, 'booking_created', [
                    'source' => 'mobile_app',
                    'service_id' => $serviceId,
                    'scheduled_date' => $scheduledDate,
                    'scheduled_time' => $scheduledTime,
                    'payment_method' => $paymentMethod,
                    'wallet_amount_used' => $walletAmountUsed ?? 0,
                ], null, $clientId);
            }
        } catch (Exception $e) {
            error_log("Warning: Failed to log booking event - " . $e->getMessage());
            // Continue anyway - booking is already created
        }

        // Send confirmation email/SMS (implement separately)
        // sendBookingConfirmation($bookingId);

        error_log("Sending success response for booking ID: " . $bookingId);
        
        successResponse([
            'booking_id' => $bookingId,
            'status' => 'pending',
            'message' => 'Booking created successfully',
            'payment_method' => $paymentMethod,
            'wallet_amount_used' => $walletAmountUsed ?? 0,
        ], 'Booking created successfully');
    }

    // PUT - Update booking (cancel, etc.)
    elseif ($method === 'PUT') {
        $auth = requireAuth();
        $clientId = $auth['client_id'] ?? null;

        if (!$clientId) {
            errorResponse('Invalid authentication', 401);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['id'])) {
            errorResponse('Booking ID is required');
        }

        $bookingId = filter_var($input['id'], FILTER_VALIDATE_INT);
        $action = isset($input['action']) ? sanitizeInput($input['action']) : null;

        // Verify booking belongs to client
        $verifyStmt = $conn->prepare("
            SELECT id, status, scheduled_date, scheduled_time 
            FROM online_bookings 
            WHERE id = ? AND client_id = ?
        ");
        $verifyStmt->execute([$bookingId, $clientId]);
        $booking = $verifyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            errorResponse('Booking not found or unauthorized', 404);
        }

        // Handle cancel action
        if ($action === 'cancel') {
            // Check if booking can be cancelled (at least 24 hours in advance)
            $scheduledDateTime = new DateTime($booking['scheduled_date'] . ' ' . $booking['scheduled_time']);
            $now = new DateTime();
            $hoursDiff = ($scheduledDateTime->getTimestamp() - $now->getTimestamp()) / 3600;

            if ($hoursDiff < 24) {
                errorResponse('Bookings must be cancelled at least 24 hours in advance');
            }

            if (!in_array($booking['status'], ['pending', 'confirmed'])) {
                errorResponse('This booking cannot be cancelled');
            }

            $reason = isset($input['reason']) ? sanitizeInput($input['reason']) : 'Cancelled by customer';

            $cancelStmt = $conn->prepare("
                UPDATE online_bookings 
                SET status = 'cancelled', 
                    cancelled_at = NOW(),
                    cancellation_reason = ?
                WHERE id = ?
            ");
            $cancelStmt->execute([$reason, $bookingId]);

            // Log event
            logBookingEvent($conn, $bookingId, 'booking_cancelled', [
                'reason' => $reason,
                'cancelled_by' => 'customer'
            ], null, $clientId);

            successResponse([
                'booking_id' => $bookingId,
                'status' => 'cancelled'
            ], 'Booking cancelled successfully');
        }

        errorResponse('Invalid action');
    }

    else {
        errorResponse('Method not allowed', 405);
    }

} catch (PDOException $e) {
    error_log("Bookings API Error: " . $e->getMessage());
    errorResponse('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Bookings API Error: " . $e->getMessage());
    errorResponse('An error occurred: ' . $e->getMessage(), 500);
}

