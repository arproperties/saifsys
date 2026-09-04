<?php
/**
 * ARS Home Rentals — Cleaning Trigger
 * Creates a work order in the cleaning module on check-out.
 */

/**
 * Find or create the "ARS Home Rentals" client record in the cleaning company's
 * client list so work orders have a valid client_id for the operation module.
 */
function ars_get_or_create_cleaning_client(PDO $conn, int $cleaningCompanyId): int {
    $stmt = $conn->prepare(
        "SELECT id FROM client WHERE company_id = ? AND client_name = 'ARS Home Rentals' LIMIT 1"
    );
    $stmt->execute([$cleaningCompanyId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

    $conn->prepare("
        INSERT INTO client (company_id, client_name, mobile_num, cell_num, payment, address, is_active)
        VALUES (?, 'ARS Home Rentals', '', '', 'cash', '', 1)
    ")->execute([$cleaningCompanyId]);

    return (int) $conn->lastInsertId();
}

/**
 * Create a cleaning work order for a unit after guest check-out.
 *
 * @param PDO   $conn
 * @param array $booking   Full booking row
 * @param array $unit      Full unit row (from re_units)
 * @param array $settings  ARS company settings
 * @param int|null $userId Current user ID
 * @return array{success:bool,order_id:?int,error:?string,service_date:?string,is_early_checkout:bool}
 */
function ars_trigger_checkout_cleaning(PDO $conn, array $booking, array $unit, array $settings, ?int $userId = null): array {
    if (!function_exists('ars_booking_effective_check_out')) {
        require_once __DIR__ . '/ars_early_checkout.php';
    }
    $isEarly = !empty($booking['is_early_checkout']) || ars_booking_is_early_checkout($booking);
    $cleaningCompanyId = $settings['cleaning_company_id'] ?? null;
    if (!$cleaningCompanyId) {
        return [
            'success' => false,
            'order_id' => null,
            'error' => 'No cleaning company configured in ARS settings.',
            'service_date' => null,
            'is_early_checkout' => $isEarly,
        ];
    }

    $clientId = ars_get_or_create_cleaning_client($conn, $cleaningCompanyId);

    $unitLabel = ($unit['unit_number'] ?? '') . ' - ' . ($unit['listing_title'] ?? $unit['unit_type'] ?? 'Unit');
    $buildingName = '';
    $buildingAddress = '';
    try {
        $stmt = $conn->prepare("SELECT name, address FROM re_buildings WHERE id = ? LIMIT 1");
        $stmt->execute([$unit['building_id'] ?? 0]);
        $bld = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($bld) {
            $buildingName = $bld['name'] ?? '';
            $buildingAddress = $bld['address'] ?? '';
        }
    } catch (PDOException $e) { /* building lookup optional */ }

    $serviceDate  = ars_booking_effective_check_out($booking);
    if ($serviceDate === '') {
        $serviceDate = date('Y-m-d');
    }
    $checkOutTime = $settings['default_check_out_time'] ?? '12:00:00';
    $cleaningFee  = (float)($settings['default_cleaning_fee'] ?? 0);
    $workerId     = $settings['default_worker_id'] ?? null;
    $driverId     = $settings['default_driver_id'] ?? null;

    $remark = 'ARS checkout cleaning — Booking ' . $booking['booking_number']
            . ', Unit ' . $unitLabel
            . ($buildingName ? ', ' . $buildingName : '');
    if ($isEarly) {
        $remark .= ' (early checkout ' . $serviceDate . '; planned ' . ars_booking_planned_check_out($booking) . ')';
    }

    // Resolve worker and driver names
    $workerName = '';
    $driverName = '';
    if ($workerId) {
        $stmt = $conn->prepare("SELECT worker_name FROM workers WHERE id = ? LIMIT 1");
        $stmt->execute([$workerId]);
        $workerName = $stmt->fetchColumn() ?: '';
    }
    if ($driverId) {
        $stmt = $conn->prepare("SELECT nickname FROM driver WHERE id = ? LIMIT 1");
        $stmt->execute([$driverId]);
        $driverName = $stmt->fetchColumn() ?: '';
    }

    // Calculate start_time and end_time for worker availability timeline
    $startTime = substr($checkOutTime, 0, 5) . ':00';
    $endHour = ((int)substr($checkOutTime, 0, 2)) + 1;
    $endTime = str_pad($endHour, 2, '0', STR_PAD_LEFT) . ':' . substr($checkOutTime, 3, 2) . ':00';

    try {
        $stmt = $conn->prepare("
            INSERT INTO make_order
                (company_id, client_id, client_name, worker_name, email_o, address_o, mobile_num_o,
                 fee_charged, hourly_rate, payment, date, service_date, time,
                 start_time, end_time,
                 hours, total, balance,
                 remark, notes, driver_name, driver_id,
                 status, payment_status,
                 ars_booking_id,
                 created_at, created_by)
            VALUES
                (?, ?, ?, ?, '', ?, '',
                 ?, 0.00, 'cash', ?, ?, ?,
                 ?, ?,
                 1.00, ?, 0.00,
                 ?, ?, ?, ?,
                 'confirmed', 'unpaid',
                 ?,
                 NOW(), ?)
        ");
        $stmt->execute([
            $cleaningCompanyId,
            $clientId,
            'ARS Home Rentals',
            $workerName,
            $buildingAddress,
            $cleaningFee,
            $serviceDate,
            $serviceDate,
            $checkOutTime,
            $startTime,
            $endTime,
            $cleaningFee,
            $remark,
            'Auto-created by ARS checkout. Booking #' . $booking['booking_number'],
            $driverName,
            $driverId,
            $booking['id'] ?? null,
            $userId,
        ]);
        $orderId = (int) $conn->lastInsertId();

        // Assign the default worker to order_workers (cleaners only, not drivers)
        if ($workerId) {
            $conn->prepare("
                INSERT INTO order_workers (order_id, worker_id, role, created_at)
                VALUES (?, ?, 'cleaner', NOW())
            ")->execute([$orderId, $workerId]);
        }

        return [
            'success' => true,
            'order_id' => $orderId,
            'error' => null,
            'service_date' => $serviceDate,
            'is_early_checkout' => $isEarly,
            'unit_label' => $unitLabel,
            'worker_name' => $workerName !== '' ? $workerName : null,
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'order_id' => null,
            'error' => 'Failed to create cleaning order: ' . $e->getMessage(),
            'service_date' => $serviceDate,
            'is_early_checkout' => $isEarly,
        ];
    }
}
