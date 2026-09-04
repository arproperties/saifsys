<?php
/**
 * Workers Availability API Endpoint
 * GET /api/mobile/workers_available.php?service_id=1&date=2025-11-01
 * Returns available time slots with workers
 */

require_once __DIR__ . '/config.php';

// Set timezone to Asia/Dubai (UAE timezone)
// Check if timezone is set in settings table, otherwise use default
try {
    $timezoneStmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = 'timezone' LIMIT 1");
    $timezoneStmt->execute();
    $timezoneRow = $timezoneStmt->fetch(PDO::FETCH_ASSOC);
    $timezone = $timezoneRow ? $timezoneRow['value'] : 'Asia/Dubai';
} catch (Exception $e) {
    $timezone = 'Asia/Dubai'; // Default fallback
}

// Set PHP timezone
date_default_timezone_set($timezone);
error_log("🌍 Timezone set to: $timezone (was: " . date_default_timezone_get() . ")");

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        errorResponse('Method not allowed', 405);
    }

    // Validate required parameters
    if (!isset($_GET['service_id']) || !isset($_GET['date'])) {
        errorResponse('service_id and date are required');
    }

    $serviceId = filter_var($_GET['service_id'], FILTER_VALIDATE_INT);
    $date = sanitizeInput($_GET['date']);
    $workerId = isset($_GET['worker_id']) ? filter_var($_GET['worker_id'], FILTER_VALIDATE_INT) : null;

    if (!$serviceId) {
        errorResponse('Invalid service_id');
    }

    // Validate date format (use timezone from settings)
    // Create timezone object once for all DateTime operations
    $timezoneObj = new DateTimeZone($timezone);
    $dateObj = DateTime::createFromFormat('Y-m-d', $date, $timezoneObj);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        errorResponse('Invalid date format. Use YYYY-MM-DD');
    }

    // Check if date is in the past (use timezone from settings)
    $today = new DateTime('today', $timezoneObj);
    if ($dateObj < $today) {
        errorResponse('Cannot book in the past');
    }

    // Get day of week (0 = Sunday, 6 = Saturday)
    $dayOfWeek = $dateObj->format('w');

    // Get service details
    $serviceStmt = $conn->prepare("
        SELECT duration_minutes 
        FROM services 
        WHERE id = ? AND is_active = 1
    ");
    $serviceStmt->execute([$serviceId]);
    $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$service) {
        errorResponse('Service not found', 404);
    }

    $serviceDuration = $service['duration_minutes'];

    // Get actual booking duration from request (hours or minutes)
    // Priority: hours > duration > service duration
    $bookingDurationMinutes = $serviceDuration; // Default to service duration
    
    if (isset($_GET['hours'])) {
        // If hours is provided, convert to minutes
        $bookingHours = filter_var($_GET['hours'], FILTER_VALIDATE_INT);
        if ($bookingHours && $bookingHours > 0) {
            $bookingDurationMinutes = $bookingHours * 60;
            error_log("📊 Using booking hours from request: {$bookingHours} hours = {$bookingDurationMinutes} minutes");
        }
    } elseif (isset($_GET['duration'])) {
        // If duration in minutes is provided
        $bookingDuration = filter_var($_GET['duration'], FILTER_VALIDATE_INT);
        if ($bookingDuration && $bookingDuration > 0) {
            $bookingDurationMinutes = $bookingDuration;
            error_log("📊 Using booking duration from request: {$bookingDurationMinutes} minutes");
        }
    }
    
    // Use the actual booking duration for slot calculations
    $actualDuration = $bookingDurationMinutes;

    // Find bookable workers
    // Query from workers table (like main system) and LEFT JOIN to employees
    // This ensures we find all workers, even if they don't have an employees entry
    // Match the main system query exactly: only filter by status != 'Inactive'
    $workerSql = "
        SELECT DISTINCT 
            COALESCE(e.id, w.id) as id,
            COALESCE(e.full_name, w.nickname, w.worker_name) as full_name,
            COALESCE(e.email, '') as email,
            COALESCE(e.phone, '') as phone,
            w.id as worker_table_id,
            w.nickname as worker_nickname
        FROM workers w
        LEFT JOIN employees e ON w.emp_num = e.employee_code
        WHERE e.status IS NULL OR e.status != 'Inactive'
    ";

    if ($workerId) {
        $workerSql .= " AND (e.id = :worker_id OR w.id = :worker_id)";
    }

    // Check if worker has this service in their skills (JSON array)
    // For simplicity, we'll check all bookable workers
    // In production, filter by booking_skills JSON field

    $workerStmt = $conn->prepare($workerSql);
    if ($workerId) {
        $workerStmt->bindParam(':worker_id', $workerId);
    }
    $workerStmt->execute();
    $workers = $workerStmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("🔍 Worker query executed - found " . count($workers) . " workers");
    error_log("🔍 Worker query SQL: " . $workerSql);
    foreach ($workers as $w) {
        error_log("  - Worker: ID={$w['id']}, Name={$w['full_name']}, WorkerTableID={$w['worker_table_id']}");
    }

    if (empty($workers)) {
        successResponse([
            'slots' => [],
            'message' => 'No workers available for this service'
        ]);
    }

    $availableSlots = [];
    
    // Booking window: 7 AM to 7 PM (last booking must finish by 7 PM)
    $bookingWindowStart = new DateTime($date . ' 07:00:00', $timezoneObj);
    $bookingWindowEnd = new DateTime($date . ' 19:00:00', $timezoneObj); // 7 PM
    
    // Calculate latest start time based on ACTUAL booking duration (must finish by 7 PM)
    $latestStartTime = clone $bookingWindowEnd;
    $latestStartTime->modify("-{$actualDuration} minutes");
    
    // If today, filter out past time slots
    $isToday = ($date === date('Y-m-d'));
    $currentTime = null;
    if ($isToday) {
        // Get current time in the correct timezone (timezoneObj already created above)
        $now = new DateTime('now', $timezoneObj);
        $currentHour = (int)$now->format('H');
        $currentMinute = (int)$now->format('i');
        
        // Round up to next 30-minute slot
        // Examples:
        // 10:00 → 10:30 (if current time is 10:00-10:29)
        // 10:24 → 10:30 (if current time is 10:24)
        // 10:30 → 11:00 (if current time is 10:30-10:59)
        // 10:55 → 11:00 (if current time is 10:55)
        
        // Create currentTime with the same date as booking date and correct timezone
        $currentTime = new DateTime($date, $timezoneObj);
        
        if ($currentMinute < 30) {
            // Round to :30 of current hour
            $currentTime->setTime($currentHour, 30, 0);
        } else {
            // Round to :00 of next hour
            $currentTime->setTime($currentHour + 1, 0, 0);
        }
        
        // Ensure we don't go past booking window
        if ($currentTime > $bookingWindowEnd) {
            // If rounding pushed us past booking window, use end of booking window
            $currentTime = clone $bookingWindowEnd;
        }
        
        // If current time is before booking window start, use booking window start
        if ($currentTime < $bookingWindowStart) {
            $currentTime = clone $bookingWindowStart;
        }
        
        error_log("⏰ Today's date - Current time: " . $now->format('Y-m-d H:i:s T') . " | Rounded to next 30-min slot: " . $currentTime->format('Y-m-d H:i:s T'));
    }
    
    // Log calculation details
    error_log("📅 Availability Calculation - Date: $date | Service Duration: {$serviceDuration} min | Booking Duration: {$actualDuration} min | Latest Start: " . $latestStartTime->format('H:i') . " | Is Today: " . ($isToday ? 'Yes' : 'No'));
    error_log("👷 Total workers found: " . count($workers));

    foreach ($workers as $worker) {
        error_log("🔄 Processing worker: {$worker['id']} ({$worker['full_name']})");
        
        // Use worker_table_id from the query if available, otherwise try to find it
        if (isset($worker['worker_table_id']) && $worker['worker_table_id']) {
            $workerId = $worker['worker_table_id'];
            $workerRow = ['id' => $workerId]; // Mark as found
            error_log("✅ Worker {$worker['id']} ({$worker['full_name']}) - using worker_table_id: $workerId");
        } else {
            // Fallback: try to find worker ID from workers table
            $workerIdStmt = $conn->prepare("
                SELECT w.id 
                FROM workers w
                LEFT JOIN employees e ON w.emp_num = e.employee_code
                WHERE e.id = ? OR w.id = ?
                LIMIT 1
            ");
            $workerIdStmt->execute([$worker['id'], $worker['id']]);
            $workerRow = $workerIdStmt->fetch(PDO::FETCH_ASSOC);
            
            // Use worker ID if found, otherwise use the ID from query
            $workerId = $workerRow ? $workerRow['id'] : $worker['id'];
            
            if (!$workerRow) {
                error_log("⚠️ Worker {$worker['id']} ({$worker['full_name']}) - using query ID as worker_id: $workerId");
            }
        }

        // Check worker's shifts for this day
        // Try to find employee_id first (if worker has employees entry)
        $employeeIdForShifts = null;
        if (isset($worker['id']) && $workerRow) {
            // Try to get employee_id from the worker data
            // If worker has employees entry, use that ID for shifts
            $empCheckStmt = $conn->prepare("
                SELECT e.id 
                FROM employees e
                INNER JOIN workers w ON w.emp_num = e.employee_code
                WHERE w.id = ?
                LIMIT 1
            ");
            $empCheckStmt->execute([$workerId]);
            $empRow = $empCheckStmt->fetch(PDO::FETCH_ASSOC);
            $employeeIdForShifts = $empRow ? $empRow['id'] : null;
        }
        
        $shifts = [];
        if ($employeeIdForShifts) {
            $shiftStmt = $conn->prepare("
                SELECT start_time, end_time
                FROM employee_shifts
                WHERE employee_id = ? 
                  AND day_of_week = ?
                  AND is_active = 1
            ");
            $shiftStmt->execute([$employeeIdForShifts, $dayOfWeek]);
            $shifts = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // If no shifts configured, use default 7 AM - 7 PM window
        if (empty($shifts)) {
            $shifts = [
                ['start_time' => '07:00:00', 'end_time' => '19:00:00']
            ];
            error_log("ℹ️ Worker {$worker['id']} ({$worker['full_name']}) has no shifts for day $dayOfWeek, using default 7 AM - 7 PM");
        } else {
            error_log("📅 Worker {$worker['id']} ({$worker['full_name']}) has " . count($shifts) . " shift(s) configured");
        }

        // Check if worker is on time off
        $timeOffStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM employee_time_off
            WHERE employee_id = ?
              AND ? BETWEEN date_from AND date_to
        ");
        $timeOffStmt->execute([$worker['id'], $date]);
        $isOnTimeOff = $timeOffStmt->fetchColumn() > 0;

        if ($isOnTimeOff) {
            error_log("⏸️ Worker {$worker['id']} ({$worker['full_name']}) is on time off - skipping");
            continue; // Worker is on time off
        }
        
        error_log("✅ Worker {$worker['id']} ({$worker['full_name']}) is available - checking shifts and orders");

        // Get existing orders from make_order for this worker on this date
        // This is the accurate source of worker availability
        // Only check orders if we have a valid workers table entry
        $existingOrders = [];
        if ($workerRow) {
            $ordersStmt = $conn->prepare("
                SELECT mo.start_time, mo.end_time
                FROM make_order mo
                INNER JOIN order_workers ow ON ow.order_id = mo.id
                WHERE ow.worker_id = ?
                  AND mo.svc_date_calc = ?
                  AND COALESCE(mo.status, '') NOT IN ('cancelled')
            ");
            $ordersStmt->execute([$workerId, $date]);
            $existingOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // If no workers table entry, check orders via employee_id instead
            // This handles workers that exist only in employees table
            $ordersStmt = $conn->prepare("
                SELECT mo.start_time, mo.end_time
                FROM make_order mo
                INNER JOIN order_workers ow ON ow.order_id = mo.id
                INNER JOIN workers w ON w.id = ow.worker_id
                INNER JOIN employees e ON e.employee_code = w.emp_num
                WHERE e.id = ?
                  AND mo.svc_date_calc = ?
                  AND COALESCE(mo.status, '') NOT IN ('cancelled')
            ");
            $ordersStmt->execute([$worker['id'], $date]);
            $existingOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Also check online_bookings for mobile app bookings (in case they haven't been converted to orders yet)
        $bookingsStmt = $conn->prepare("
            SELECT scheduled_time as start_time,
                   ADDTIME(scheduled_time, SEC_TO_TIME(s.duration_minutes * 60)) as end_time
            FROM online_bookings ob
            INNER JOIN services s ON ob.service_id = s.id
            WHERE ob.employee_id = ?
              AND ob.scheduled_date = ?
              AND ob.status NOT IN ('cancelled', 'no_show')
        ");
        $bookingsStmt->execute([$worker['id'], $date]);
        $existingBookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Combine orders and bookings
        $allExistingAppointments = array_merge($existingOrders, $existingBookings);
        error_log("📋 Worker {$worker['id']} ({$worker['full_name']}) has " . count($allExistingAppointments) . " existing appointments");
        if (count($allExistingAppointments) > 0) {
            foreach ($allExistingAppointments as $apt) {
                error_log("  - Appointment: {$apt['start_time']} to {$apt['end_time']}");
            }
        }

        // Generate time slots for each shift
        foreach ($shifts as $shift) {
            $shiftStart = new DateTime($date . ' ' . $shift['start_time'], $timezoneObj);
            $shiftEnd = new DateTime($date . ' ' . $shift['end_time'], $timezoneObj);

            // Ensure shift respects booking window (7 AM - 7 PM)
            // Always start from 7 AM minimum (ignore shift start if it's later)
            // But respect shift end time
            $actualStart = clone $bookingWindowStart; // Always start from 7 AM minimum
            
            if ($shiftStart > $bookingWindowStart) {
                error_log("ℹ️ Worker {$worker['id']} shift configured at " . $shift['start_time'] . " but allowing slots from 7 AM");
            }
            
            if ($shiftEnd > $bookingWindowEnd) {
                $shiftEnd = clone $bookingWindowEnd;
            }

            // Start generating slots from the later of: actual start (7 AM minimum) OR current time (if today)
            $slotStart = clone $actualStart;
            if ($isToday && $currentTime) {
                // Ensure we don't generate slots before current time
                if ($slotStart < $currentTime) {
                    $slotStart = clone $currentTime;
                    error_log("⏰ Filtering past slots - starting from " . $currentTime->format('H:i') . " (current time: " . ($currentTime->format('H:i')) . ")");
                }
                // Also ensure we don't generate slots that are already in the past
                // Double-check: if slotStart is still before currentTime, set it to currentTime
                if ($slotStart < $currentTime) {
                    $slotStart = clone $currentTime;
                }
            }
            
            // Don't generate slots beyond the latest start time (must finish by 7 PM)
            // Use the later of: shift end time OR latest start time (whichever is earlier)
            $effectiveEnd = $shiftEnd < $latestStartTime ? $shiftEnd : $latestStartTime;
            
            // But also ensure we don't go beyond booking window end
            if ($effectiveEnd > $bookingWindowEnd) {
                $effectiveEnd = clone $bookingWindowEnd;
            }
            
            error_log("🔍 Worker {$worker['id']} - Shift: " . $shift['start_time'] . " to " . $shift['end_time'] . " | Slot range: " . $slotStart->format('H:i') . " to " . $effectiveEnd->format('H:i') . " | Latest start: " . $latestStartTime->format('H:i'));
            error_log("📊 Will generate slots from " . $slotStart->format('H:i') . " to " . $effectiveEnd->format('H:i') . " (every 30 minutes)");

            // Generate 30-minute intervals
            // Use <= to allow slots that start exactly at effectiveEnd
            $slotsGeneratedForWorker = 0;
            while ($slotStart <= $effectiveEnd) {
                // Additional check: if today, skip slots that are in the past
                if ($isToday && $currentTime && $slotStart < $currentTime) {
                    error_log("⏰ Skipping past slot: " . $slotStart->format('H:i') . " (current time: " . $currentTime->format('H:i') . ")");
                    $slotStart->modify('+30 minutes');
                    continue;
                }
                
                $slotEnd = clone $slotStart;
                $slotEnd->modify("+{$actualDuration} minutes");

                // Ensure booking finishes by 7 PM (last slot must end by 7 PM or exactly at 7 PM)
                if ($slotEnd > $bookingWindowEnd) {
                    error_log("⛔ Slot " . $slotStart->format('H:i') . " rejected - would finish at " . $slotEnd->format('H:i') . " (after 7 PM)");
                    break; // Cannot fit this booking
                }

                // Check if slot start time is beyond effective end (shouldn't happen with <= condition)
                if ($slotStart > $effectiveEnd) {
                    break;
                }

                // Check if slot overlaps with existing orders/bookings
                $isAvailable = true;
                $slotStartTime = $slotStart->format('H:i:s');
                $slotEndTime = $slotEnd->format('H:i:s');
                
                foreach ($allExistingAppointments as $appointment) {
                    $apptStart = $appointment['start_time'];
                    $apptEnd = $appointment['end_time'];
                    
                    // Convert to comparable format if needed
                    if (is_string($apptStart) && strlen($apptStart) <= 8) {
                        // Already in HH:MM:SS format
                    } else {
                        $apptStart = date('H:i:s', strtotime($apptStart));
                    }
                    if (is_string($apptEnd) && strlen($apptEnd) <= 8) {
                        // Already in HH:MM:SS format
                    } else {
                        $apptEnd = date('H:i:s', strtotime($apptEnd));
                    }

                    // Check for overlap: slot overlaps if start < end AND end > start
                    if ($slotStartTime < $apptEnd && $slotEndTime > $apptStart) {
                        $isAvailable = false;
                        break;
                    }
                }

                if ($isAvailable) {
                    $availableSlots[] = [
                        'start_time' => $slotStart->format('H:i'),
                        'end_time' => $slotEnd->format('H:i'),
                        'datetime' => $slotStart->format('Y-m-d\TH:i:s'),
                        'worker_id' => $worker['id'],
                        'worker_name' => $worker['full_name'],
                        'available' => true
                    ];
                    $slotsGeneratedForWorker++;
                    error_log("✅ Available slot: " . $slotStart->format('H:i') . " - " . $slotEnd->format('H:i') . " for worker {$worker['full_name']}");
                } else {
                    error_log("❌ Slot " . $slotStart->format('H:i') . " - " . $slotEnd->format('H:i') . " blocked for worker {$worker['full_name']}");
                }

                // Move to next 30-minute slot
                $slotStart->modify('+30 minutes');
            }
            
            error_log("📈 Worker {$worker['id']} ({$worker['full_name']}) generated $slotsGeneratedForWorker available slots for this shift");
        }
        
        error_log("✅ Finished processing worker {$worker['id']} ({$worker['full_name']})");
    }

    // Sort slots by datetime
    usort($availableSlots, function($a, $b) {
        return strcmp($a['datetime'], $b['datetime']);
    });
    
    // Log final results
    error_log("📊 Final Results - Total slots found: " . count($availableSlots));
    if (count($availableSlots) > 0) {
        $firstSlot = $availableSlots[0]['start_time'];
        $lastSlot = $availableSlots[count($availableSlots) - 1]['start_time'];
        error_log("📊 Slot range: $firstSlot to $lastSlot");
        
        // Log sample of available slots
        $sampleSlots = array_slice($availableSlots, 0, min(10, count($availableSlots)));
        foreach ($sampleSlots as $s) {
            error_log("📋 Sample slot: {$s['start_time']} - {$s['end_time']} for worker {$s['worker_name']}");
        }
    } else {
        error_log("⚠️ WARNING: No available slots found! Check worker shifts and existing orders.");
    }

    // Group slots by time and count available workers
    $groupedSlots = [];
    foreach ($availableSlots as $slot) {
        $timeKey = $slot['start_time'];
        if (!isset($groupedSlots[$timeKey])) {
            $groupedSlots[$timeKey] = [
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'datetime' => $slot['datetime'],
                'display_time' => $slot['start_time'] . '-' . $slot['end_time'],
                'available' => true,
                'available_workers' => 0,
                'workers' => []
            ];
        }
        $groupedSlots[$timeKey]['available_workers']++;
        $groupedSlots[$timeKey]['workers'][] = [
            'id' => $slot['worker_id'],
            'name' => $slot['worker_name']
        ];
    }

    // Convert to array and filter by workers_needed if provided
    $workersNeeded = isset($_GET['workers_needed']) ? (int)$_GET['workers_needed'] : 1;
    error_log("👥 Workers needed: $workersNeeded | Grouped slots: " . count($groupedSlots));
    
    $finalSlots = [];
    foreach ($groupedSlots as $timeKey => $slot) {
        error_log("🔍 Time slot {$timeKey}: {$slot['available_workers']} workers available (needed: $workersNeeded)");
        if ($slot['available_workers'] >= $workersNeeded) {
            $finalSlots[] = $slot;
        } else {
            error_log("❌ Time slot {$timeKey} filtered out - only {$slot['available_workers']} workers available (need $workersNeeded)");
        }
    }
    
    error_log("📊 Final slots after filtering: " . count($finalSlots));

    // Return in format expected by mobile app
    successResponse([
        'slots' => $finalSlots,
        'available_slots' => $finalSlots, // Alias for compatibility
        'date' => $date,
        'service_id' => $serviceId,
        'total_slots' => count($finalSlots),
        'booking_window' => [
            'start' => '07:00',
            'end' => '19:00'
        ]
    ]);

} catch (PDOException $e) {
    error_log("Workers Availability API Error: " . $e->getMessage());
    errorResponse('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Workers Availability API Error: " . $e->getMessage());
    errorResponse('An error occurred: ' . $e->getMessage(), 500);
}

