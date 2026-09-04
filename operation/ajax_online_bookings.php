<?php
/**
 * AJAX Handler for Online Bookings Admin Page
 */

require __DIR__.'/../includes/auth.php';
require __DIR__.'/../includes/db_connect.php';

require_role(['Owner','Admin','HR'], $conn);

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Get statistics
    if ($action === 'stats') {
        $stats = [];

        // Pending bookings
        $stmt = $conn->query("SELECT COUNT(*) FROM online_bookings WHERE status = 'pending'");
        $stats['pending'] = $stmt->fetchColumn();

        // Confirmed bookings
        $stmt = $conn->query("SELECT COUNT(*) FROM online_bookings WHERE status = 'confirmed'");
        $stats['confirmed'] = $stmt->fetchColumn();

        // Today's bookings
        $stmt = $conn->query("SELECT COUNT(*) FROM online_bookings WHERE scheduled_date = CURDATE()");
        $stats['today'] = $stmt->fetchColumn();

        // This week's bookings
        $stmt = $conn->query("
            SELECT COUNT(*) 
            FROM online_bookings 
            WHERE YEARWEEK(scheduled_date, 1) = YEARWEEK(CURDATE(), 1)
        ");
        $stats['week'] = $stmt->fetchColumn();

        echo json_encode(['success' => true, 'stats' => $stats]);
        exit;
    }

    // List bookings with filters
    if ($action === 'list') {
        $status = $_GET['status'] ?? '';
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo = $_GET['date_to'] ?? '';

        $sql = "
            SELECT 
                ob.id,
                ob.customer_name,
                ob.customer_phone,
                ob.customer_email,
                ob.scheduled_date,
                ob.scheduled_time,
                ob.address,
                ob.latitude,
                ob.longitude,
                ob.latitude,
                ob.longitude,
                ob.notes,
                ob.instructions,
                ob.hours,
                ob.professionals,
                ob.materials_included,
                ob.frequency,
                ob.subtotal,
                ob.discount_amount,
                ob.service_fee,
                ob.total_price,
                ob.weekly_schedule,
                ob.status,
                ob.created_at,
                s.name as service_name,
                s.category as service_category,
                e.full_name as employee_name
            FROM online_bookings ob
            LEFT JOIN services s ON ob.service_id = s.id
            LEFT JOIN employees e ON ob.employee_id = e.id
            WHERE 1=1
        ";

        $params = [];

        if ($status) {
            $sql .= " AND ob.status = ?";
            $params[] = $status;
        }

        if ($dateFrom) {
            $sql .= " AND ob.scheduled_date >= ?";
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $sql .= " AND ob.scheduled_date <= ?";
            $params[] = $dateTo;
        }

        $sql .= " ORDER BY ob.scheduled_date DESC, ob.scheduled_time DESC LIMIT 100";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'bookings' => $bookings]);
        exit;
    }

    // View booking details
    if ($action === 'view') {
        $id = $_GET['id'] ?? 0;

        $stmt = $conn->prepare("
            SELECT 
                ob.*,
                s.name as service_name,
                s.category as service_category,
                s.price as service_price,
                s.duration_minutes,
                e.full_name as employee_name,
                e.email as employee_email,
                e.phone as employee_phone,
                c.client_name as customer_full_name,
                c.email as customer_full_email
            FROM online_bookings ob
            LEFT JOIN services s ON ob.service_id = s.id
            LEFT JOIN employees e ON ob.employee_id = e.id
            LEFT JOIN client c ON ob.client_id = c.id
            WHERE ob.id = ?
        ");
        $stmt->execute([$id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            echo json_encode(['success' => false, 'error' => 'Booking not found']);
            exit;
        }

        // Load assigned workers (supports multiple workers per order)
        $assignedWorkers = [];

        if (!empty($booking['work_order_id'])) {
            $workersStmt = $conn->prepare("
                SELECT 
                    w.id,
                    COALESCE(e.full_name, w.nickname, w.worker_name, 'Worker') AS name
                FROM order_workers ow
                INNER JOIN workers w ON ow.worker_id = w.id
                LEFT JOIN employees e ON w.emp_num = e.employee_code
                WHERE ow.order_id = ?
                ORDER BY name
            ");
            $workersStmt->execute([$booking['work_order_id']]);
            $assignedWorkers = $workersStmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($booking['employee_id'])) {
            $singleWorkerStmt = $conn->prepare("
                SELECT e.id, COALESCE(e.full_name, 'Worker') AS name
                FROM employees e
                WHERE e.id = ?
            ");
            $singleWorkerStmt->execute([$booking['employee_id']]);
            $singleWorker = $singleWorkerStmt->fetch(PDO::FETCH_ASSOC);

            if ($singleWorker) {
                $assignedWorkers[] = $singleWorker;
            } elseif (!empty($booking['employee_name'])) {
                $assignedWorkers[] = [
                    'id' => $booking['employee_id'],
                    'name' => $booking['employee_name']
                ];
            }
        } elseif (!empty($booking['employee_name'])) {
            $assignedWorkers[] = [
                'id' => $booking['employee_id'],
                'name' => $booking['employee_name']
            ];
        }

        $booking['assigned_workers'] = $assignedWorkers;

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
        $itemsStmt->execute([$id]);
        $booking['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get booking events (audit log)
        $eventsStmt = $conn->prepare("
            SELECT 
                be.*,
                u.username as user_name
            FROM booking_events be
            LEFT JOIN user u ON be.user_id = u.id
            WHERE be.booking_id = ?
            ORDER BY be.created_at DESC
        ");
        $eventsStmt->execute([$id]);
        $booking['events'] = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get coupon information if available
        $couponStmt = $conn->prepare("
            SELECT 
                cu.discount_amount as coupon_discount_amount,
                c.coupon_code
            FROM coupon_usages cu
            INNER JOIN coupons c ON cu.coupon_id = c.id
            WHERE cu.booking_id = ?
            LIMIT 1
        ");
        $couponStmt->execute([$id]);
        $coupon = $couponStmt->fetch(PDO::FETCH_ASSOC);
        
        // discount_amount in online_bookings is the promotional/frequency discount
        // coupon discount is stored separately in coupon_usages
        $booking['promotional_discount_amount'] = (float)($booking['discount_amount'] ?? 0);
        
        if ($coupon) {
            $booking['coupon_code'] = $coupon['coupon_code'];
            $booking['coupon_discount_amount'] = (float)($coupon['coupon_discount_amount'] ?? 0);
        } else {
            $booking['coupon_code'] = null;
            $booking['coupon_discount_amount'] = 0;
        }

        echo json_encode(['success' => true, 'booking' => $booking]);
        exit;
    }

    // Confirm booking
    if ($action === 'confirm') {
        $id = $_POST['id'] ?? 0;
        $userId = $_SESSION['user']['id'] ?? null;

        $stmt = $conn->prepare("
            UPDATE online_bookings 
            SET status = 'confirmed', confirmed_at = NOW(), confirmed_by = ?
            WHERE id = ? AND status = 'pending'
        ");
        $result = $stmt->execute([$userId, $id]);

        if ($result && $stmt->rowCount() > 0) {
            // Log event
            $eventStmt = $conn->prepare("
                INSERT INTO booking_events (booking_id, event_type, event_data, user_id)
                VALUES (?, 'booking_confirmed', ?, ?)
            ");
            $eventStmt->execute([$id, json_encode(['confirmed_by_user_id' => $userId]), $userId]);

            echo json_encode(['success' => true, 'message' => 'Booking confirmed']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to confirm booking']);
        }
        exit;
    }

    // Update booking status
    if ($action === 'update_status') {
        $id = $_POST['id'] ?? 0;
        $newStatus = $_POST['status'] ?? '';
        $userId = $_SESSION['user']['id'] ?? null;

        // Valid booking statuses
        $validStatuses = ['pending', 'confirmed', 'assigned', 'in_progress', 'completed', 'cancelled', 'no_show'];
        if (!in_array($newStatus, $validStatuses)) {
            echo json_encode(['success' => false, 'error' => 'Invalid status']);
            exit;
        }

        // Get current booking
        $bookingStmt = $conn->prepare("SELECT id, status FROM online_bookings WHERE id = ?");
        $bookingStmt->execute([$id]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            echo json_encode(['success' => false, 'error' => 'Booking not found']);
            exit;
        }

        $oldStatus = $booking['status'];

        // Update status
        $updateStmt = $conn->prepare("
            UPDATE online_bookings 
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $result = $updateStmt->execute([$newStatus, $id]);

        if ($result) {
            // Log event
            $eventStmt = $conn->prepare("
                INSERT INTO booking_events (booking_id, event_type, event_data, user_id)
                VALUES (?, 'status_changed', ?, ?)
            ");
            $eventStmt->execute([
                $id,
                json_encode([
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'changed_by_user_id' => $userId
                ]),
                $userId
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Status updated successfully',
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update status']);
        }
        exit;
    }

    // Get workers list
    if ($action === 'get_workers') {
        $date = $_GET['date'] ?? '';
        $hours = (float)($_GET['hours'] ?? 2);
        
        $stmt = $conn->query("
            SELECT w.id, w.nickname as name, w.daily_cap_hours, e.full_name, e.id as employee_id
            FROM workers w
            LEFT JOIN employees e ON w.emp_num = e.employee_code
            WHERE e.status IS NULL OR e.status != 'Inactive'
            ORDER BY COALESCE(e.full_name, w.nickname, w.worker_name)
        ");
        $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If date provided, get availability for each worker
        if ($date) {
            foreach ($workers as &$worker) {
                $workerId = $worker['id'];
                
                // Get worker's shifts for this day
                $dayOfWeek = date('w', strtotime($date));
                $shiftStmt = $conn->prepare("
                    SELECT es.start_time, es.end_time
                    FROM employee_shifts es
                    INNER JOIN employees e ON es.employee_id = e.id
                    INNER JOIN workers w ON w.emp_num = e.employee_code
                    WHERE w.id = ? 
                      AND es.day_of_week = ?
                      AND es.is_active = 1
                ");
                $shiftStmt->execute([$workerId, $dayOfWeek]);
                $shifts = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($shifts)) {
                    $shifts = [['start_time' => '07:00:00', 'end_time' => '19:00:00']];
                }
                
                // Get existing appointments
                $ordersStmt = $conn->prepare("
                    SELECT mo.start_time, mo.end_time
                    FROM make_order mo
                    INNER JOIN order_workers ow ON ow.order_id = mo.id
                    WHERE ow.worker_id = ?
                      AND mo.service_date = ?
                      AND COALESCE(mo.status, '') NOT IN ('cancelled')
                ");
                $ordersStmt->execute([$workerId, $date]);
                $existingOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $bookingsStmt = $conn->prepare("
                    SELECT ob.scheduled_time as start_time,
                           ADDTIME(ob.scheduled_time, SEC_TO_TIME(ob.hours * 3600)) as end_time
                    FROM online_bookings ob
                    WHERE ob.employee_id = (
                        SELECT e.id FROM employees e
                        INNER JOIN workers w ON w.emp_num = e.employee_code
                        WHERE w.id = ?
                        LIMIT 1
                    )
                      AND ob.scheduled_date = ?
                      AND ob.status NOT IN ('cancelled', 'no_show')
                ");
                $bookingsStmt->execute([$workerId, $date]);
                $existingBookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $appointments = array_merge($existingOrders, $existingBookings);
                
                // Format appointments
                $formattedAppointments = [];
                foreach ($appointments as $apt) {
                    $start = substr($apt['start_time'], 0, 5);
                    $end = substr($apt['end_time'], 0, 5);
                    $formattedAppointments[] = "$start - $end";
                }
                
                $worker['appointments'] = $formattedAppointments;
                $worker['shift_start'] = substr($shifts[0]['start_time'], 0, 5);
                $worker['shift_end'] = substr($shifts[0]['end_time'], 0, 5);
            }
        }
        
        echo json_encode(['success' => true, 'workers' => $workers]);
        exit;
    }

    // Get drivers list
    if ($action === 'get_drivers') {
        $stmt = $conn->query("SELECT id, nickname as name FROM driver ORDER BY nickname");
        $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'drivers' => $drivers]);
        exit;
    }

    // Get client rate
    if ($action === 'get_client_rate') {
        $clientId = $_GET['client_id'] ?? 0;
        $stmt = $conn->prepare("SELECT rate FROM client WHERE id = ?");
        $stmt->execute([$clientId]);
        $rate = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'rate' => $rate ? (float)$rate : null]);
        exit;
    }

    // Get service rate (price_per_unit - hourly rate)
    if ($action === 'get_service_rate') {
        $serviceId = $_GET['service_id'] ?? 0;
        $stmt = $conn->prepare("SELECT price_per_unit, price FROM services WHERE id = ?");
        $stmt->execute([$serviceId]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'price_per_unit' => $service ? ((float)$service['price_per_unit'] ?: null) : null,
            'price' => $service ? ((float)$service['price'] ?: null) : null
        ]);
        exit;
    }

    // Get worker availability for a specific date
    if ($action === 'get_worker_availability') {
        $workerId = $_GET['worker_id'] ?? 0;
        $date = $_GET['date'] ?? '';
        $hours = (float)($_GET['hours'] ?? 2);
        
        if (!$workerId || !$date) {
            echo json_encode(['success' => false, 'error' => 'worker_id and date are required']);
            exit;
        }

        // Get worker's shifts for this day
        $dayOfWeek = date('w', strtotime($date)); // 0 = Sunday, 6 = Saturday
        $shiftStmt = $conn->prepare("
            SELECT es.start_time, es.end_time
            FROM employee_shifts es
            INNER JOIN employees e ON es.employee_id = e.id
            INNER JOIN workers w ON w.emp_num = e.employee_code
            WHERE w.id = ? 
              AND es.day_of_week = ?
              AND es.is_active = 1
        ");
        $shiftStmt->execute([$workerId, $dayOfWeek]);
        $shifts = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);

        // If no shifts, use default 7 AM - 7 PM
        if (empty($shifts)) {
            $shifts = [['start_time' => '07:00:00', 'end_time' => '19:00:00']];
        }

        // Get existing orders for this worker on this date
        $ordersStmt = $conn->prepare("
            SELECT mo.start_time, mo.end_time, mo.client_name, mo.status
            FROM make_order mo
            INNER JOIN order_workers ow ON ow.order_id = mo.id
            WHERE ow.worker_id = ?
              AND mo.service_date = ?
              AND COALESCE(mo.status, '') NOT IN ('cancelled')
        ");
        $ordersStmt->execute([$workerId, $date]);
        $existingOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get existing bookings for this worker on this date
        $bookingsStmt = $conn->prepare("
            SELECT ob.scheduled_time as start_time,
                   ADDTIME(ob.scheduled_time, SEC_TO_TIME(ob.hours * 3600)) as end_time,
                   ob.customer_name, ob.status
            FROM online_bookings ob
            WHERE ob.employee_id = (
                SELECT e.id FROM employees e
                INNER JOIN workers w ON w.emp_num = e.employee_code
                WHERE w.id = ?
                LIMIT 1
            )
              AND ob.scheduled_date = ?
              AND ob.status NOT IN ('cancelled', 'no_show')
        ");
        $bookingsStmt->execute([$workerId, $date]);
        $existingBookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Combine all existing appointments
        $appointments = array_merge($existingOrders, $existingBookings);

        // Format appointments for display
        $formattedAppointments = [];
        foreach ($appointments as $apt) {
            $start = substr($apt['start_time'], 0, 5);
            $end = substr($apt['end_time'], 0, 5);
            $formattedAppointments[] = [
                'time' => "$start - $end",
                'client' => $apt['client_name'] ?? $apt['customer_name'] ?? 'N/A',
                'status' => $apt['status'] ?? 'confirmed'
            ];
        }

        // Get shift times
        $shiftTimes = [];
        foreach ($shifts as $shift) {
            $shiftTimes[] = [
                'start' => substr($shift['start_time'], 0, 5),
                'end' => substr($shift['end_time'], 0, 5)
            ];
        }

        echo json_encode([
            'success' => true,
            'shifts' => $shiftTimes,
            'appointments' => $formattedAppointments,
            'available' => empty($formattedAppointments)
        ]);
        exit;
    }

    // Assign worker and create order
    if ($action === 'assign') {
        require_once __DIR__ . '/../includes/ar_helpers.php';
        require_once __DIR__ . '/../includes/overlap.php';
        
        $id = $_POST['id'] ?? 0;
        $workerIdsJson = $_POST['worker_ids'] ?? '[]';
        $driverId = isset($_POST['driver_id']) && $_POST['driver_id'] ? (int)$_POST['driver_id'] : null;
        $hourlyRate = isset($_POST['hourly_rate']) && $_POST['hourly_rate'] ? (float)$_POST['hourly_rate'] : null;
        $userId = current_user_id();

        // Get booking details
        $bookingStmt = $conn->prepare("
            SELECT ob.*, s.name as service_name, s.price as service_price, s.price_per_unit, s.duration_minutes
            FROM online_bookings ob
            LEFT JOIN services s ON ob.service_id = s.id
            WHERE ob.id = ?
        ");
        $bookingStmt->execute([$id]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            echo json_encode(['success' => false, 'error' => 'Booking not found']);
            exit;
        }

        // Check if already has work order
        if ($booking['work_order_id']) {
            echo json_encode(['success' => false, 'error' => 'Order already created for this booking']);
            exit;
        }

        // Parse worker IDs
        $workerIds = json_decode($workerIdsJson, true);
        if (!is_array($workerIds) || empty($workerIds)) {
            echo json_encode(['success' => false, 'error' => 'Please select at least one worker']);
            exit;
        }

        // Validate workers exist and get worker table IDs
        // Frontend sends worker table IDs (w.id), not employee IDs
        $workerTableIds = [];
        $workerNames = [];
        $employeeIds = []; // For legacy employee_id field in booking
        
        foreach ($workerIds as $workerTableId) {
            // Get worker details from workers table
            $workerStmt = $conn->prepare("
                SELECT w.id, w.nickname, w.worker_name, e.id as employee_id, e.full_name
                FROM workers w
                LEFT JOIN employees e ON w.emp_num = e.employee_code
                WHERE w.id = ?
                LIMIT 1
            ");
            $workerStmt->execute([$workerTableId]);
            $worker = $workerStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($worker) {
                $workerTableIds[] = $worker['id'];
                $workerNames[] = $worker['nickname'] ?: $worker['worker_name'] ?: $worker['full_name'];
                if ($worker['employee_id']) {
                    $employeeIds[] = $worker['employee_id'];
                }
            }
        }

        if (empty($workerTableIds)) {
            echo json_encode(['success' => false, 'error' => 'Invalid worker(s) selected']);
            exit;
        }

        // Find or create client
        $clientId = $booking['client_id'];
        if (!$clientId) {
            // Try to find by phone
            $clientStmt = $conn->prepare("SELECT id FROM client WHERE mobile_num = ? LIMIT 1");
            $clientStmt->execute([$booking['customer_phone']]);
            $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($client) {
                $clientId = $client['id'];
            } else {
                // Create new client
                $insertClientStmt = $conn->prepare("
                    INSERT INTO client (client_name, mobile_num, cell_num, email, address, rate, payment, terms, default_vat_rate, is_active, currency, client_status)
                    VALUES (?, ?, '', ?, ?, 0.00, 'D', 'cash', 5.00, 1, 'AED', 'active')
                ");
                $insertClientStmt->execute([
                    $booking['customer_name'],
                    $booking['customer_phone'],
                    $booking['customer_email'] ?: '',
                    $booking['address'] ?: ''
                ]);
                $clientId = $conn->lastInsertId();
            }
        }

        // Get client details
        $clientStmt = $conn->prepare("SELECT client_name, rate, default_vat_rate, terms FROM client WHERE id = ?");
        $clientStmt->execute([$clientId]);
        $client = $clientStmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            echo json_encode(['success' => false, 'error' => 'Client not found']);
            exit;
        }

        // Calculate times
        $scheduledTime = $booking['scheduled_time'];
        $hours = (float)($booking['hours'] ?? 2);
        $startTime = $scheduledTime;
        
        // Calculate end time
        $startDateTime = new DateTime($booking['scheduled_date'] . ' ' . $startTime);
        $startDateTime->modify("+{$hours} hours");
        $endTime = $startDateTime->format('H:i:s');
        
        // Format for legacy time field
        $timeFrom = substr($startTime, 0, 5);
        $timeTo = substr($endTime, 0, 5);
        $legacyTime = $timeFrom . ' To ' . $timeTo;

        // Calculate pricing - prioritize: provided rate > service price_per_unit > service price > client rate
        $fee = $hourlyRate;
        if (!$fee || $fee <= 0) {
            $fee = (float)($booking['price_per_unit'] ?: 0);
        }
        if (!$fee || $fee <= 0) {
            $fee = (float)($booking['service_price'] ?: 0);
        }
        if (!$fee || $fee <= 0) {
            $fee = (float)($client['rate'] ?: 0);
        }
        
        $numWorkers = count($workerTableIds);
        $hoursBooking = $hours * $numWorkers;
        $durPerCleaner = $hours;
        
        $sub = round($fee * $hoursBooking, 2);
        $vatRate = (float)($client['default_vat_rate'] ?: 5.0);
        $vat = round($sub * ($vatRate / 100), 2);
        $tot = round($sub + $vat, 2);

        // Get driver name if provided
        $driverName = '';
        if ($driverId) {
            $driverStmt = $conn->prepare("SELECT nickname FROM driver WHERE id = ?");
            $driverStmt->execute([$driverId]);
            $driverName = $driverStmt->fetchColumn() ?: '';
        }

        // Build worker name string
        $workerNameStr = implode(' , ', $workerNames);

        // Check for overlaps
        foreach ($workerTableIds as $wid) {
            $conflicts = findOverlap($conn, $wid, $booking['scheduled_date'], $timeFrom, $timeTo, 0);
            if ($conflicts) {
                $c = $conflicts[0];
                $wname = $workerNames[array_search($wid, $workerTableIds)] ?? "Worker #$wid";
                echo json_encode([
                    'success' => false, 
                    'error' => "Overlap for {$wname} on {$booking['scheduled_date']}: conflicts with Order #{$c['id']} ({$c['start_time']}–{$c['end_time']})"
                ]);
                exit;
            }
        }

        // Get current company_id
        require_once __DIR__ . '/../includes/company_helper.php';
        $currentCompanyId = current_company_id($conn) ?: 1;
        
        // Create order
        $insOrder = $conn->prepare("
            INSERT INTO make_order
            (company_id, client_id, client_name, worker_name, email_o, address_o, mobile_num_o,
             fee_charged, hourly_rate, payment, date, service_date, time, start_time, end_time,
             hours, total, balance,
             need_materials, materials_note,
             remark, notes, driver_name, driver_id,
             net_hours, net_amount, amount_afc, discount_amount,
             vat_rate, vat_amount, grand_total, status, payment_status,
             created_at, created_by)
            VALUES
            (?,?,?,?,?,?,?,?,?,?,
             ?, ?, ?, ?, ?,
             ?, ?, 0.00,
             ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?, 0.00,
             ?, ?, ?, 'confirmed','unpaid',
             NOW(), ?)
        ");

        $needMaterials = (int)($booking['materials_included'] ?? 0);
        $remark = $booking['instructions'] ?: ($booking['notes'] ?: '');
        
        $insOrder->execute([
            $currentCompanyId,
            $clientId,
            $client['client_name'],
            $workerNameStr,
            $booking['customer_email'] ?: '',
            $booking['address'] ?: '',
            $booking['customer_phone'],
            $fee,
            $fee,
            $client['terms'] ?: 'cash',
            $booking['scheduled_date'],
            $booking['scheduled_date'],
            $legacyTime,
            $startTime,
            $endTime,
            $hoursBooking,
            $sub,
            $needMaterials,
            $needMaterials ? 'Materials included' : null,
            $remark,
            'Created from online booking #' . $id,
            $driverName,
            $driverId,
            $durPerCleaner,
            round($durPerCleaner * $fee, 2),
            $sub,
            $vatRate,
            $vat,
            $tot,
            $userId
        ]);

        $orderId = (int)$conn->lastInsertId();

        // Link workers
        $insOW = $conn->prepare("INSERT INTO order_workers (order_id, worker_id) VALUES (?, ?)");
        foreach ($workerTableIds as $wid) {
            $insOW->execute([$orderId, $wid]);
        }

        // Link service if available
        if ($booking['service_id']) {
            $serviceStmt = $conn->prepare("SELECT name, price FROM services WHERE id = ?");
            $serviceStmt->execute([$booking['service_id']]);
            $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($service) {
                $insSvc = $conn->prepare("
                    INSERT INTO order_services (order_id, service_id, service_name, description, qty, unit, unit_price, vat_rate)
                    VALUES (?,?,?,?,?,?,?,?)
                ");
                $insSvc->execute([
                    $orderId,
                    $booking['service_id'],
                    $service['name'],
                    null,
                    $hours,
                    'hour',
                    $fee,
                    $vatRate
                ]);
            }
        }

        // Invoice deferred until finalize (Phase 2)
        require_once __DIR__ . '/../includes/work_order_financial_guard.php';
        require_once __DIR__ . '/../includes/AuditService.php';
        $invoiceId = wo_maybe_sync_invoice_after_order_change($conn, $orderId, $userId);
        if ($invoiceId) {
            $up = $conn->prepare("UPDATE make_order SET invoice_id = ? WHERE id = ?");
            $up->execute([$invoiceId, $orderId]);
        }
        wo_sync_ops_status_column($conn, $orderId, 'confirmed');

        // Audit log
        AuditService::logCreate('make_order', $orderId, [
            'client_name' => $client['client_name'],
            'service_date' => $booking['scheduled_date'],
            'worker_count' => count($workerTableIds),
            'total' => $tot,
            'booking_id' => $id
        ], "Created order #{$orderId} from booking #{$id}", $userId ? (int)$userId : null);

        // Update booking
        $updateBookingStmt = $conn->prepare("
            UPDATE online_bookings 
            SET work_order_id = ?, status = 'assigned', employee_id = ?
            WHERE id = ?
        ");
        // Use first employee ID for employee_id field (legacy), or first worker table ID if no employee
        $firstEmpId = !empty($employeeIds) ? $employeeIds[0] : ($workerTableIds[0] ?? null);
        $updateBookingStmt->execute([$orderId, $firstEmpId, $id]);

        // Log event
        $eventStmt = $conn->prepare("
            INSERT INTO booking_events (booking_id, event_type, event_data, user_id)
            VALUES (?, 'worker_assigned', ?, ?)
        ");
        $eventStmt->execute([
            $id,
            json_encode([
                'worker_ids' => $workerTableIds,
                'worker_names' => $workerNames,
                'order_id' => $orderId,
                'assigned_by_user_id' => $userId
            ]),
            $userId
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Worker(s) assigned and order created',
            'order_id' => $orderId
        ]);
        exit;
    }

    // Convert to work order
    if ($action === 'convert_to_order') {
        $id = $_POST['id'] ?? 0;
        $userId = $_SESSION['user']['id'] ?? null;

        // Get booking details
        $bookingStmt = $conn->prepare("SELECT * FROM online_bookings WHERE id = ?");
        $bookingStmt->execute([$id]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            echo json_encode(['success' => false, 'error' => 'Booking not found']);
            exit;
        }

        // Check if already converted
        if ($booking['work_order_id']) {
            echo json_encode(['success' => false, 'error' => 'Already converted to work order']);
            exit;
        }

        // Create work order (simplified - adjust based on your orders table structure)
        // This is a placeholder - implement based on your actual orders schema
        
        // Update booking
        // $stmt = $conn->prepare("UPDATE online_bookings SET work_order_id = ? WHERE id = ?");
        // $stmt->execute([$orderId, $id]);

        echo json_encode(['success' => true, 'message' => 'Work order creation feature - to be implemented']);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);

} catch (PDOException $e) {
    error_log("Online Bookings AJAX Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Online Bookings AJAX Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred']);
}

