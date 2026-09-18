<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_pricing.php';
require_once __DIR__ . '/includes/ars_guest_notifications.php';
require_once __DIR__ . '/includes/ars_booking_requests.php';
require_once __DIR__ . '/includes/ars_deposit.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_financial_lock.php';
require_once __DIR__ . '/includes/ars_activity.php';
require_once __DIR__ . '/includes/ars_availability.php';
require_once __DIR__ . '/../../includes/AuditService.php';

header('Content-Type: application/json');
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();

$userId = current_user_id();
$action    = $_POST['action'] ?? '';
$bookingId = (int)($_POST['booking_id'] ?? 0);
$booking = null;

$arsAudit = static function (string $action, string $summary, array $extra = []) use ($conn, $arsCompanyId, $userId, &$booking, &$bookingId): void {
    try {
        AuditService::logEvent(array_merge([
            'action' => $action,
            'module' => 'ars',
            'company_id' => $arsCompanyId,
            'object_type' => 'ars_bookings',
            'object_id' => (string)$bookingId,
            'object_ref' => (string)($booking['booking_number'] ?? ('Booking #' . $bookingId)),
            'summary' => $summary,
            'user_id' => $userId,
            'source' => 'user',
            'success' => true,
        ], $extra));
    } catch (Throwable $ignored) {}
};

if (!$bookingId) {
    echo json_encode(['success' => false, 'error' => 'Missing booking_id']);
    exit;
}

ars_require_booking_action($conn, $action);

$stmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ? AND company_id = ?");
$stmt->execute([$bookingId, $arsCompanyId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    echo json_encode(['success' => false, 'error' => 'Booking not found']);
    exit;
}

try {
    switch ($action) {

        case 'confirm':
            if ($booking['status'] !== 'pending') {
                echo json_encode(['success' => false, 'error' => 'Only pending bookings can be confirmed.']);
                exit;
            }

            $conn->beginTransaction();
            try {
                $lockStmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ? AND company_id = ? FOR UPDATE");
                $lockStmt->execute([$bookingId, $arsCompanyId]);
                $booking = $lockStmt->fetch(PDO::FETCH_ASSOC) ?: $booking;
                if ($booking['status'] !== 'pending') {
                    throw new RuntimeException('Only pending bookings can be confirmed.');
                }

                $conn->prepare("SELECT id FROM re_units WHERE id = ? FOR UPDATE")->execute([(int)$booking['unit_id']]);
                $avail = ars_check_availability(
                    $conn,
                    (int)$booking['unit_id'],
                    (string)$booking['check_in'],
                    (string)$booking['check_out'],
                    $bookingId
                );
                if (empty($avail['available'])) {
                    $labels = array_map(static fn($c) => $c['label'] ?? 'conflict', $avail['conflicts'] ?? []);
                    throw new RuntimeException('Unit not available: ' . implode(', ', $labels));
                }

                // Idempotent: if revenue journal already exists, reuse it (do not double-post).
                $journalResult = null;
                if (!empty($booking['journal_id'])) {
                    $journalResult = [
                        'success' => true,
                        'journal_id' => (int) $booking['journal_id'],
                        'reused' => true,
                        'error' => null,
                    ];
                } else {
                    require_once __DIR__ . '/includes/ars_accounting.php';
                    $journalResult = ars_post_booking_revenue($conn, $booking, $userId);
                    if (empty($journalResult['success'])) {
                        throw new RuntimeException('Accounting post failed: ' . ($journalResult['error'] ?? 'Unknown error'));
                    }
                }

                $conn->prepare("UPDATE ars_bookings SET status = 'confirmed', expires_at = NULL, updated_at = NOW() WHERE id = ? AND company_id = ?")
                     ->execute([$bookingId, $arsCompanyId]);

                $freshStmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ? AND company_id = ?");
                $freshStmt->execute([$bookingId, $arsCompanyId]);
                $booking = $freshStmt->fetch(PDO::FETCH_ASSOC) ?: $booking;

                ars_booking_engage_financial_lock(
                    $conn,
                    $booking,
                    'Revenue journal posted on confirm',
                    $userId,
                    'invoice_created'
                );

                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }

            ars_guest_notification_create($conn, [
                'company_id' => $arsCompanyId,
                'guest_id' => (int)$booking['guest_id'],
                'booking_id' => $bookingId,
                'event_type' => 'booking_confirmed',
                'title' => 'Booking confirmed',
                'message' => 'Your booking ' . (string)($booking['booking_number'] ?? ('#' . $bookingId)) . ' has been confirmed.',
                'cta_route' => '/guest/bookings/' . $bookingId,
            ]);
            ars_guest_notification_create($conn, [
                'company_id' => $arsCompanyId,
                'guest_id' => (int)$booking['guest_id'],
                'booking_id' => $bookingId,
                'event_type' => 'document_available',
                'title' => 'Booking document available',
                'message' => 'Your booking confirmation document is now available.',
                'cta_route' => '/guest/bookings/' . $bookingId,
                'meta' => ['entity_type' => 'document', 'entity_id' => $bookingId],
            ]);
            ars_guest_notifications_schedule_booking_reminders($conn, array_merge($booking, ['id' => $bookingId, 'company_id' => $arsCompanyId]));
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'operational',
                'event_type' => 'booking_confirmed',
                'title' => 'Booking confirmed',
                'related_journal_id' => $journalResult['journal_id'] ?? null,
                'status' => 'confirmed',
                'created_by' => $userId,
            ]);
            $arsAudit('status_change', 'Confirmed booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)));
            echo json_encode(['success' => true, 'journal' => $journalResult]);
            break;

        case 'checkin':
            if ($booking['status'] !== 'confirmed') {
                echo json_encode(['success' => false, 'error' => 'Only confirmed bookings can be checked in.']);
                exit;
            }
            $conn->prepare("UPDATE ars_bookings SET status = 'checked_in', updated_at = NOW() WHERE id = ? AND company_id = ?")
                 ->execute([$bookingId, $arsCompanyId]);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'operational',
                'event_type' => 'check_in',
                'title' => 'Guest checked in',
                'previous_value' => 'confirmed',
                'new_value' => 'checked_in',
                'created_by' => $userId,
            ]);
            $arsAudit('status_change', 'Checked in booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)));
            echo json_encode(['success' => true]);
            break;

        case 'checkout':
            if ($booking['status'] !== 'checked_in') {
                echo json_encode(['success' => false, 'error' => 'Only checked-in bookings can be checked out.']);
                exit;
            }
            require_once __DIR__ . '/includes/ars_early_checkout.php';
            ars_early_checkout_ensure_schema($conn);

            $actualOut = date('Y-m-d');
            $plannedOut = ars_booking_planned_check_out($booking);
            $isEarly = ars_checkout_would_be_early($booking, $actualOut);

            // Do not change planned check_out / nights / totals (no stay refund — BR-ARS-OPS-001).
            $conn->prepare("
                UPDATE ars_bookings SET
                    status = 'checked_out',
                    actual_check_out = ?,
                    is_early_checkout = ?,
                    updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$actualOut, $isEarly ? 1 : 0, $bookingId, $arsCompanyId]);

            $booking['actual_check_out'] = $actualOut;
            $booking['is_early_checkout'] = $isEarly ? 1 : 0;
            $booking['status'] = 'checked_out';

            $arsAudit('status_change', 'Checked out booking ' . ($booking['booking_number'] ?? ('#' . $bookingId))
                . ($isEarly ? (' (early; actual ' . $actualOut . ', planned ' . $plannedOut . '; no stay refund)') : ''));

            $cleanResult = null;
            $settings = getArsSettings($conn, $arsCompanyId);
            if (!empty($settings['cleaning_company_id'])) {
                require_once __DIR__ . '/includes/ars_cleaning_trigger.php';
                $stmt = $conn->prepare("SELECT * FROM re_units WHERE id = ?");
                $stmt->execute([$booking['unit_id']]);
                $unit = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($unit) {
                    $cleanResult = ars_trigger_checkout_cleaning($conn, $booking, $unit, $settings, $userId);
                } else {
                    $cleanResult = [
                        'success' => false,
                        'order_id' => null,
                        'error' => 'Unit not found for cleaning work order.',
                        'service_date' => $actualOut,
                        'is_early_checkout' => $isEarly,
                    ];
                }
            } else {
                $cleanResult = [
                    'success' => false,
                    'order_id' => null,
                    'error' => 'No cleaning company configured in ARS settings.',
                    'service_date' => $actualOut,
                    'is_early_checkout' => $isEarly,
                ];
            }

            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'operational',
                'event_type' => $isEarly ? 'early_check_out' : 'check_out',
                'title' => $isEarly ? 'Guest checked out early' : 'Guest checked out',
                'description' => $isEarly
                    ? ('Actual ' . $actualOut . '; planned ' . $plannedOut . '. No stay refund for unused nights.')
                    : null,
                'previous_value' => 'checked_in',
                'new_value' => 'checked_out',
                'meta' => [
                    'actual_check_out' => $actualOut,
                    'planned_check_out' => $plannedOut,
                    'is_early_checkout' => $isEarly,
                    'stay_refund' => false,
                ],
                'created_by' => $userId,
            ]);

            // Dedicated Timeline event so operators can confirm HK WO creation (filter: Housekeeping).
            $cleanOk = !empty($cleanResult['success']) && !empty($cleanResult['order_id']);
            $serviceDate = (string)($cleanResult['service_date'] ?? $actualOut);
            $unitLabel = trim((string)($cleanResult['unit_label'] ?? ''));
            if ($cleanOk) {
                $hkDesc = 'Work order #' . (int)$cleanResult['order_id']
                    . ' created for service date ' . $serviceDate
                    . ($unitLabel !== '' ? (' · Unit ' . $unitLabel) : '')
                    . ($isEarly ? ' (early checkout)' : '')
                    . (!empty($cleanResult['worker_name']) ? ('. Assigned: ' . $cleanResult['worker_name']) : '')
                    . '.';
                ars_booking_activity_log($conn, [
                    'company_id' => $arsCompanyId,
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'] ?? null,
                    'event_category' => 'housekeeping',
                    'event_type' => 'cleaning_order_created',
                    'title' => 'Cleaning work order created',
                    'description' => $hkDesc,
                    'related_entity_type' => 'housekeeping_order',
                    'related_entity_id' => (int)$cleanResult['order_id'],
                    'related_document_number' => 'WO #' . (int)$cleanResult['order_id'],
                    'status' => 'confirmed',
                    'source' => 'system',
                    'dedupe_key' => 'cleaning_wo_checkout_' . $bookingId . '_' . (int)$cleanResult['order_id'],
                    'meta' => [
                        'cleaning' => $cleanResult,
                        'service_date' => $serviceDate,
                        'is_early_checkout' => $isEarly,
                    ],
                    'created_by' => $userId,
                ]);
            } else {
                ars_booking_activity_log($conn, [
                    'company_id' => $arsCompanyId,
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'] ?? null,
                    'event_category' => 'housekeeping',
                    'event_type' => 'cleaning_order_failed',
                    'title' => 'Cleaning work order not created',
                    'description' => (string)($cleanResult['error'] ?? 'Cleaning order was not created.'),
                    'status' => 'failed',
                    'source' => 'system',
                    'dedupe_key' => 'cleaning_wo_checkout_fail_' . $bookingId . '_' . $actualOut,
                    'meta' => [
                        'cleaning' => $cleanResult,
                        'is_early_checkout' => $isEarly,
                    ],
                    'created_by' => $userId,
                ]);
            }

            echo json_encode([
                'success' => true,
                'cleaning' => $cleanResult,
                'is_early_checkout' => $isEarly,
                'actual_check_out' => $actualOut,
                'planned_check_out' => $plannedOut,
            ]);
            break;

        case 'complete':
            if ($booking['status'] !== 'checked_out') {
                echo json_encode(['success' => false, 'error' => 'Only checked-out bookings can be marked complete.']);
                exit;
            }
            $conn->prepare("UPDATE ars_bookings SET status = 'completed', updated_at = NOW() WHERE id = ? AND company_id = ?")
                 ->execute([$bookingId, $arsCompanyId]);
            $conn->prepare("
                UPDATE ars_guests SET
                    total_bookings = total_bookings + 1,
                    total_spent = total_spent + ?
                WHERE id = ? AND company_id = ?
            ")->execute([(float)$booking['total_amount'], $booking['guest_id'], $arsCompanyId]);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'operational',
                'event_type' => 'booking_completed',
                'title' => 'Booking completed',
                'new_value' => 'completed',
                'created_by' => $userId,
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'cancel':
            if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
                echo json_encode(['success' => false, 'error' => 'Only pending or confirmed bookings can be cancelled.']);
                exit;
            }
            $conn->beginTransaction();
            try {
                if ($booking['journal_id']) {
                    require_once __DIR__ . '/includes/ars_accounting.php';
                    $rev = ars_reverse_booking_journal((int)$booking['journal_id'], $userId);
                    if (empty($rev['success'])) {
                        throw new RuntimeException('Journal reversal failed: ' . ($rev['error'] ?? 'Unknown error'));
                    }
                }
                $conn->prepare("UPDATE ars_bookings SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
                     ->execute([$userId, $bookingId, $arsCompanyId]);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
            $arsAudit('booking_cancelled', 'Cancelled booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)));
            ars_guest_notification_create($conn, [
                'company_id' => $arsCompanyId,
                'guest_id' => (int)$booking['guest_id'],
                'booking_id' => $bookingId,
                'event_type' => 'booking_cancelled',
                'title' => 'Booking cancelled',
                'message' => 'Booking ' . (string)($booking['booking_number'] ?? ('#' . $bookingId)) . ' has been cancelled.',
                'cta_route' => '/guest/bookings/' . $bookingId,
            ]);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'operational',
                'event_type' => 'booking_cancelled',
                'title' => 'Booking cancelled',
                'previous_value' => $booking['status'],
                'new_value' => 'cancelled',
                'created_by' => $userId,
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'void_booking': {
            // Wrong entry: remove payments, reverse invoice journals, mark cancelled. No guest message.
            require_once __DIR__ . '/includes/ars_booking_void.php';
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') {
                echo json_encode(['success' => false, 'error' => 'A reason is required.']);
                exit;
            }
            $blockers = ars_booking_void_blockers($conn, $booking);
            if ($blockers) {
                echo json_encode(['success' => false, 'error' => 'Cannot void: ' . implode(' ', $blockers)]);
                exit;
            }
            $conn->beginTransaction();
            try {
                ars_booking_void($conn, $booking, $userId, $reason);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                echo json_encode(['success' => false, 'error' => 'Nothing was changed. ' . $e->getMessage()]);
                exit;
            }
            $arsAudit('booking_cancelled', 'Voided booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)) . ' (wrong entry): ' . $reason, [
                'old_data' => ['status' => $booking['status'], 'total_amount' => $booking['total_amount'], 'paid_amount' => $booking['paid_amount']],
                'new_data' => ['status' => 'cancelled'],
            ]);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'operational',
                'event_type' => 'booking_voided',
                'title' => 'Booking voided (wrong entry)',
                'description' => $reason,
                'previous_value' => $booking['status'],
                'new_value' => 'cancelled',
                'created_by' => $userId,
            ]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'add_charge':
            if (ars_booking_is_financially_locked($conn, $booking)) {
                ars_booking_activity_log($conn, [
                    'company_id' => $arsCompanyId,
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'] ?? null,
                    'event_category' => 'financial',
                    'event_type' => 'amendment_required',
                    'title' => 'Additional charge blocked — amendment required',
                    'description' => 'Booking is financially locked. Additional charges require a Booking Amendment (Phase 2).',
                    'status' => 'blocked',
                    'source' => 'user',
                    'created_by' => $userId,
                    'meta' => ['preview_documents' => ['Additional Service Invoice — Phase 2 adapter']],
                ]);
                echo json_encode([
                    'success' => false,
                    'error' => 'Booking is financially locked. Additional charges require a Booking Amendment (creates a new service invoice in Phase 2).',
                    'requires_amendment' => true,
                    'preview_documents' => ['Additional Service Invoice — Phase 2 adapter'],
                ]);
                exit;
            }
            $chargeType = $_POST['charge_type'] ?? 'other';
            $desc       = trim($_POST['description'] ?? '');
            $qty        = max(1, (float)($_POST['quantity'] ?? 1));
            $unitPrice  = max(0, (float)($_POST['unit_price'] ?? 0));
            $total      = round($qty * $unitPrice, 2);

            if (!$desc) {
                echo json_encode(['success' => false, 'error' => 'Description required']);
                exit;
            }

            $conn->prepare("
                INSERT INTO ars_booking_charges (booking_id, charge_type, description, quantity, unit_price, total, charge_date)
                VALUES (?, ?, ?, ?, ?, ?, CURDATE())
            ")->execute([$bookingId, $chargeType, $desc, $qty, $unitPrice, $total]);
            $chargeId = (int)$conn->lastInsertId();

            ars_recalc_booking_totals($conn, $bookingId);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'financial',
                'event_type' => 'additional_charge_added',
                'title' => 'Additional charge added',
                'description' => $desc,
                'new_value' => number_format($total, 2, '.', ''),
                'related_entity_type' => 'ars_booking_charge',
                'related_entity_id' => $chargeId,
                'created_by' => $userId,
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'record_payment':
            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0) {
                echo json_encode(['success' => false, 'error' => 'Amount must be positive']);
                exit;
            }

            $method     = $_POST['payment_method'] ?? 'cash';
            $date       = $_POST['payment_date'] ?? date('Y-m-d');
            $ref        = trim($_POST['reference_number'] ?? '');
            $linkUrl    = trim($_POST['payment_link_url'] ?? '');
            $notes      = trim($_POST['notes'] ?? '');
            $linkStatus = $linkUrl ? ($_POST['payment_link_status'] ?? 'paid') : null;
            $receiptAccountCode = trim((string)($_POST['receipt_account_code'] ?? ''));

            require_once __DIR__ . '/includes/ars_account_roles.php';
            ars_ensure_receipt_account_columns($conn);
            $glCompanyId = ars_financial_gl_company_id($conn, $arsCompanyId);
            $receiptCheck = ars_resolve_receipt_account($conn, $glCompanyId, (string)$method, $receiptAccountCode);
            if (!$receiptCheck['success']) {
                echo json_encode(['success' => false, 'error' => $receiptCheck['error']]);
                exit;
            }

            $conn->beginTransaction();
            try {
                $conn->prepare("
                    INSERT INTO ars_booking_payments
                        (booking_id, company_id, amount, payment_method, receipt_account_code, payment_date, reference_number, payment_link_url, payment_link_status, notes, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $bookingId, $arsCompanyId, $amount, $method, $receiptCheck['account_code'], $date,
                    $ref ?: null, $linkUrl ?: null, $linkStatus, $notes ?: null, $userId,
                ]);

                $paymentId = (int)$conn->lastInsertId();

                require_once __DIR__ . '/includes/ars_accounting.php';
                $pmRow = $conn->prepare("SELECT * FROM ars_booking_payments WHERE id = ? AND company_id = ?");
                $pmRow->execute([$paymentId, $arsCompanyId]);
                $pmRow = $pmRow->fetch(PDO::FETCH_ASSOC);
                $journalResult = ars_post_payment_journal($conn, $pmRow, $booking, $userId);
                if (empty($journalResult['success'])) {
                    throw new RuntimeException('Payment journal failed: ' . ($journalResult['error'] ?? 'Unknown error'));
                }

                ars_recalc_booking_totals($conn, $bookingId);
                $freshStmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ? AND company_id = ?");
                $freshStmt->execute([$bookingId, $arsCompanyId]);
                $booking = $freshStmt->fetch(PDO::FETCH_ASSOC) ?: $booking;
                ars_booking_engage_financial_lock($conn, $booking, 'Payment recorded', $userId);

                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }

            $arsAudit('receipt_created', 'Recorded payment of ' . number_format($amount, 2) . ' for booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)), [
                'new_data' => ['amount' => $amount, 'payment_method' => $method],
            ]);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'payment',
                'event_type' => 'payment_received',
                'title' => 'Payment received',
                'new_value' => number_format($amount, 2, '.', ''),
                'related_entity_type' => 'ars_booking_payment',
                'related_entity_id' => $paymentId,
                'related_journal_id' => $journalResult['journal_id'] ?? null,
                'created_by' => $userId,
            ]);
            ars_guest_notification_create($conn, [
                'company_id' => $arsCompanyId,
                'guest_id' => (int)$booking['guest_id'],
                'booking_id' => $bookingId,
                'payment_id' => $paymentId,
                'event_type' => 'payment_succeeded',
                'title' => 'Payment received',
                'message' => 'We received your payment for booking ' . (string)($booking['booking_number'] ?? ('#' . $bookingId)) . '.',
                'cta_route' => '/guest/bookings/' . $bookingId,
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'set_security_deposit':
            $depAmount = max(0, (float)($_POST['deposit_amount'] ?? 0));
            try {
                ars_assert_financial_edit_allowed($conn, $booking, ['deposit_amount' => $depAmount]);
                ars_booking_set_security_deposit_amount($conn, $booking, $depAmount);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage(), 'requires_amendment' => true]);
                exit;
            }
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'financial',
                'event_type' => 'deposit_amount_set',
                'title' => 'Security deposit amount set',
                'previous_value' => (string)($booking['deposit_amount'] ?? '0'),
                'new_value' => number_format($depAmount, 2, '.', ''),
                'created_by' => $userId,
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'receive_deposit':
            if (($booking['deposit_status'] ?? 'none') !== 'pending') {
                echo json_encode(['success' => false, 'error' => 'Deposit is not in pending status.']);
                exit;
            }
            $depAmount = (float)($_POST['amount'] ?? $booking['deposit_amount'] ?? 0);
            $depMethod = $_POST['method'] ?? 'cash';
            $depDate   = $_POST['date'] ?? date('Y-m-d');
            $receiptAccountCode = trim((string)($_POST['receipt_account_code'] ?? ''));
            if ($depAmount <= 0) {
                echo json_encode(['success' => false, 'error' => 'No deposit amount set.']);
                exit;
            }
            try {
                ars_booking_mark_deposit_received($conn, $booking, $depAmount, $depMethod, $userId, null, $receiptAccountCode);
                $conn->prepare('UPDATE ars_bookings SET deposit_received_date = ? WHERE id = ? AND company_id = ?')
                    ->execute([$depDate, $bookingId, $arsCompanyId]);
                $freshStmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ? AND company_id = ?");
                $freshStmt->execute([$bookingId, $arsCompanyId]);
                $booking = $freshStmt->fetch(PDO::FETCH_ASSOC) ?: $booking;
                ars_booking_engage_financial_lock($conn, $booking, 'Security deposit received', $userId);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'financial',
                'event_type' => 'security_deposit_received',
                'title' => 'Security deposit received',
                'new_value' => number_format($depAmount, 2, '.', ''),
                'created_by' => $userId,
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'refund_deposit':
        case 'settle_deposit':
            if (!in_array($booking['deposit_status'] ?? '', ['received', 'partially_refunded'], true)) {
                echo json_encode(['success' => false, 'error' => 'Deposit must be received before settling.']);
                exit;
            }
            require_once __DIR__ . '/includes/ars_deposit.php';
            ars_deposit_ensure_schema($conn);

            $refAmount = round((float)($_POST['amount'] ?? 0), 2);
            $refMethod = $_POST['method'] ?? 'cash';
            $refDate   = $_POST['date'] ?? date('Y-m-d');
            $receiptAccountCode = trim((string)($_POST['receipt_account_code'] ?? ''));
            $deductionRaw = $_POST['deductions'] ?? '[]';
            $norm = ars_deposit_normalize_deductions($deductionRaw);
            if (!$norm['ok']) {
                echo json_encode(['success' => false, 'error' => $norm['error']]);
                exit;
            }
            $deductions = $norm['deductions'];
            $deductTotal = $norm['total'];
            $held = ars_deposit_held_remaining($booking);

            if ($refAmount < 0 || $deductTotal < 0) {
                echo json_encode(['success' => false, 'error' => 'Amounts cannot be negative.']);
                exit;
            }
            if ($refAmount <= 0.009 && $deductTotal <= 0.009) {
                echo json_encode(['success' => false, 'error' => 'Enter a refund amount and/or at least one deduction.']);
                exit;
            }
            $release = round($refAmount + $deductTotal, 2);
            if ($release > $held + 0.009) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Refund + deductions exceed held remaining (AED ' . number_format($held, 2) . ').',
                ]);
                exit;
            }

            require_once __DIR__ . '/includes/ars_accounting.php';
            $refResult = ars_post_deposit_settlement(
                $conn, $booking, $refAmount, $refMethod, $deductions, $userId, $receiptAccountCode
            );
            if (!$refResult['success']) {
                echo json_encode(['success' => false, 'error' => 'Settlement journal failed: ' . ($refResult['error'] ?? 'Unknown')]);
                exit;
            }

            $alreadyRefunded = round((float)($booking['deposit_refunded_amount'] ?? 0), 2);
            $alreadyForfeited = round((float)($booking['deposit_forfeited_amount'] ?? 0), 2);
            $newRefunded = round($alreadyRefunded + $refAmount, 2);
            $newForfeited = round($alreadyForfeited + $deductTotal, 2);
            $depositAmount = round((float)$booking['deposit_amount'], 2);
            $newStatus = ars_deposit_status_after_settlement($depositAmount, $newRefunded, $newForfeited);

            $conn->prepare("
                UPDATE ars_bookings SET
                    deposit_status = ?,
                    deposit_refunded_amount = ?,
                    deposit_forfeited_amount = ?,
                    deposit_refunded_date = ?,
                    updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$newStatus, $newRefunded, $newForfeited, $refDate, $bookingId, $arsCompanyId]);

            if ($refAmount > 0.009) {
                ars_guest_notification_create($conn, [
                    'company_id' => $arsCompanyId,
                    'guest_id' => (int)$booking['guest_id'],
                    'booking_id' => $bookingId,
                    'event_type' => 'deposit_refunded',
                    'title' => 'Security deposit refunded',
                    'message' => 'AED ' . number_format($refAmount, 2) . ' of your security deposit was refunded for booking '
                        . (string)($booking['booking_number'] ?? ('#' . $bookingId)) . '.',
                    'cta_route' => '/guest/bookings/' . $bookingId,
                ]);
            }

            $descParts = [];
            if ($refAmount > 0.009) {
                $descParts[] = 'Refund AED ' . number_format($refAmount, 2);
            }
            foreach ($deductions as $d) {
                $descParts[] = ucfirst(str_replace('_', ' ', $d['type']))
                    . ' AED ' . number_format($d['amount'], 2)
                    . ' — ' . $d['note'];
            }
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'financial',
                'event_type' => $deductTotal > 0.009 ? 'security_deposit_settled' : 'security_deposit_returned',
                'title' => $deductTotal > 0.009 ? 'Security deposit settled' : 'Security deposit refunded',
                'description' => implode('; ', $descParts),
                'related_journal_id' => $refResult['journal_id'] ?? null,
                'meta' => [
                    'refund_amount' => $refAmount,
                    'deductions' => $deductions,
                    'deduction_total' => $deductTotal,
                    'method' => $refMethod,
                    'no_vat' => true,
                ],
                'created_by' => $userId,
            ]);

            echo json_encode([
                'success' => true,
                'journal' => $refResult,
                'deposit_status' => $newStatus,
                'refunded' => $newRefunded,
                'forfeited' => $newForfeited,
            ]);
            break;

        case 'delete_payment': {
            // Removes a manually recorded payment entered by mistake: reverses its
            // journal (audit trail kept), takes its allocations back off the invoices,
            // deletes the payment row and recalculates the booking.
            require_once __DIR__ . '/includes/ars_payment_edit.php';

            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (!$paymentId || $reason === '') {
                echo json_encode(['success' => false, 'error' => 'Payment and reason are required.']);
                exit;
            }

            $st = $conn->prepare("SELECT * FROM ars_booking_payments WHERE id = ? AND booking_id = ? AND company_id = ?");
            $st->execute([$paymentId, $bookingId, $arsCompanyId]);
            $payment = $st->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                echo json_encode(['success' => false, 'error' => 'Payment not found on this booking.']);
                exit;
            }
            if ($blocker = ars_payment_edit_blocker($conn, $payment)) {
                echo json_encode(['success' => false, 'error' => $blocker]);
                exit;
            }

            $conn->beginTransaction();
            try {
                $reversalJournalId = ars_payment_unpost($conn, $payment, $userId, 'Payment deleted: ' . $reason);
                $conn->prepare("DELETE FROM ars_guest_notifications WHERE payment_id = ?")->execute([$paymentId]);
                $conn->prepare("DELETE FROM ars_booking_payments WHERE id = ?")->execute([$paymentId]);
                ars_recalc_booking_totals($conn, $bookingId);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                echo json_encode(['success' => false, 'error' => 'Nothing was changed. ' . $e->getMessage()]);
                exit;
            }

            $amountLabel = number_format((float)$payment['amount'], 2);
            AuditService::logDelete('ars_booking_payments', $paymentId, $payment,
                'Deleted payment #' . $paymentId . ' of ' . $amountLabel . ' on ' . ($booking['booking_number'] ?? ('#' . $bookingId)) . ': ' . $reason);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'payment',
                'event_type' => 'payment_deleted',
                'title' => 'Payment #' . $paymentId . ' deleted (AED ' . $amountLabel . ')',
                'description' => $reason,
                'previous_value' => number_format((float)$payment['amount'], 2, '.', ''),
                'related_entity_type' => 'ars_booking_payment',
                'related_entity_id' => $paymentId,
                'related_journal_id' => $reversalJournalId,
                'created_by' => $userId,
            ]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'edit_payment': {
            // Amount / method / GL account / date changes reverse the old journal and
            // re-post the payment (same payment id). Reference / notes alone just update.
            require_once __DIR__ . '/includes/ars_payment_edit.php';
            require_once __DIR__ . '/includes/ars_account_roles.php';

            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $method = (string)($_POST['payment_method'] ?? '');
            $receiptCode = trim((string)($_POST['receipt_account_code'] ?? ''));
            $date = trim((string)($_POST['payment_date'] ?? ''));
            $ref = trim((string)($_POST['reference_number'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            $dt = DateTime::createFromFormat('Y-m-d', $date);
            if (!$paymentId || $amount <= 0 || !$dt || $dt->format('Y-m-d') !== $date) {
                echo json_encode(['success' => false, 'error' => 'Enter an amount above zero and a valid date.']);
                exit;
            }
            if ($date > date('Y-m-d')) {
                echo json_encode(['success' => false, 'error' => 'Payment date cannot be in the future.']);
                exit;
            }
            if (!in_array($method, ['cash', 'bank_transfer', 'card', 'online'], true)) {
                echo json_encode(['success' => false, 'error' => 'Choose a payment method.']);
                exit;
            }

            $st = $conn->prepare("SELECT * FROM ars_booking_payments WHERE id = ? AND booking_id = ? AND company_id = ?");
            $st->execute([$paymentId, $bookingId, $arsCompanyId]);
            $payment = $st->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                echo json_encode(['success' => false, 'error' => 'Payment not found on this booking.']);
                exit;
            }
            if ($blocker = ars_payment_edit_blocker($conn, $payment)) {
                echo json_encode(['success' => false, 'error' => $blocker]);
                exit;
            }

            ars_ensure_receipt_account_columns($conn);
            $glCompanyId = ars_financial_gl_company_id($conn, $arsCompanyId);
            $receiptCheck = ars_resolve_receipt_account($conn, $glCompanyId, $method, $receiptCode);
            if (!$receiptCheck['success']) {
                echo json_encode(['success' => false, 'error' => $receiptCheck['error']]);
                exit;
            }
            $receiptCode = (string)$receiptCheck['account_code'];

            $old = [
                'amount' => number_format((float)$payment['amount'], 2, '.', ''),
                'payment_method' => (string)$payment['payment_method'],
                'receipt_account_code' => (string)$payment['receipt_account_code'],
                'payment_date' => (string)$payment['payment_date'],
                'reference_number' => (string)($payment['reference_number'] ?? ''),
                'notes' => (string)($payment['notes'] ?? ''),
            ];
            $new = [
                'amount' => number_format($amount, 2, '.', ''),
                'payment_method' => $method,
                'receipt_account_code' => $receiptCode,
                'payment_date' => $date,
                'reference_number' => $ref,
                'notes' => $notes,
            ];
            $changed = array_keys(array_diff_assoc($new, $old));
            if (!$changed) {
                echo json_encode(['success' => true]);
                break;
            }
            $repost = (bool)array_intersect($changed, ['amount', 'payment_method', 'receipt_account_code', 'payment_date']);

            $conn->beginTransaction();
            try {
                if ($repost) {
                    ars_payment_unpost($conn, $payment, $userId, 'Payment #' . $paymentId . ' edited');
                    $conn->prepare("
                        UPDATE ars_booking_payments
                        SET amount = ?, payment_method = ?, receipt_account_code = ?, payment_date = ?,
                            reference_number = ?, notes = ?, journal_id = NULL, financial_document_id = NULL
                        WHERE id = ?
                    ")->execute([$amount, $method, $receiptCode, $date, $ref ?: null, $notes ?: null, $paymentId]);

                    $st = $conn->prepare("SELECT * FROM ars_booking_payments WHERE id = ?");
                    $st->execute([$paymentId]);
                    $fresh = $st->fetch(PDO::FETCH_ASSOC);
                    $jr = ars_post_payment_journal($conn, $fresh, $booking, $userId);
                    if (empty($jr['success'])) {
                        throw new RuntimeException('Re-posting failed: ' . ($jr['error'] ?? 'unknown'));
                    }
                    ars_recalc_booking_totals($conn, $bookingId);
                } else {
                    $conn->prepare("UPDATE ars_booking_payments SET reference_number = ?, notes = ? WHERE id = ?")
                        ->execute([$ref ?: null, $notes ?: null, $paymentId]);
                }
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                echo json_encode(['success' => false, 'error' => 'Nothing was changed. ' . $e->getMessage()]);
                exit;
            }

            $labels = ['amount' => 'amount', 'payment_method' => 'method', 'receipt_account_code' => 'GL account',
                'payment_date' => 'date', 'reference_number' => 'reference', 'notes' => 'notes'];
            $summary = [];
            foreach ($changed as $k) {
                $summary[] = $labels[$k] . ': ' . ($old[$k] !== '' ? $old[$k] : '—') . ' → ' . ($new[$k] !== '' ? $new[$k] : '—');
            }
            $arsAudit('receipt_updated', 'Edited payment #' . $paymentId . ' (' . implode('; ', $summary) . ')', [
                'old_data' => array_intersect_key($old, array_flip($changed)),
                'new_data' => array_intersect_key($new, array_flip($changed)),
            ]);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'payment',
                'event_type' => 'payment_edited',
                'title' => 'Payment #' . $paymentId . ' edited',
                'description' => implode("\n", $summary),
                'related_entity_type' => 'ars_booking_payment',
                'related_entity_id' => $paymentId,
                'created_by' => $userId,
            ]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'mark_link_paid':
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            if (!$paymentId) {
                echo json_encode(['success' => false, 'error' => 'Missing payment_id']);
                exit;
            }
            $conn->prepare("UPDATE ars_booking_payments SET payment_link_status = 'paid' WHERE id = ? AND booking_id = ? AND company_id = ?")
                 ->execute([$paymentId, $bookingId, $arsCompanyId]);
            echo json_encode(['success' => true]);
            break;

        case 'detect_amendment_impact':
            $proposed = [];
            foreach (ars_financial_protected_fields() as $f) {
                if (array_key_exists($f, $_POST)) {
                    $proposed[$f] = $_POST[$f];
                }
            }
            foreach (ars_operational_free_fields() as $f) {
                if (array_key_exists($f, $_POST)) {
                    $proposed[$f] = $_POST[$f];
                }
            }
            echo json_encode(['success' => true, 'impact' => ars_detect_amendment_financial_impact($conn, $booking, $proposed)]);
            break;

        case 'update_operational_notes':
            $special = array_key_exists('special_requests', $_POST) ? trim((string)$_POST['special_requests']) : ($booking['special_requests'] ?? '');
            $internal = array_key_exists('internal_notes', $_POST) ? trim((string)$_POST['internal_notes']) : ($booking['internal_notes'] ?? '');
            ars_assert_financial_edit_allowed($conn, $booking, [
                'special_requests' => $special,
                'internal_notes' => $internal,
            ]);
            $conn->prepare("UPDATE ars_bookings SET special_requests = ?, internal_notes = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
                 ->execute([$special !== '' ? $special : null, $internal !== '' ? $internal : null, $bookingId, $arsCompanyId]);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'notes',
                'event_type' => 'notes_updated',
                'title' => 'Booking notes updated',
                'created_by' => $userId,
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'reapply_lifecycle_request':
            $requestId = (int)($_POST['request_id'] ?? 0);
            if ($requestId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Missing request_id']);
                exit;
            }
            $request = ars_booking_request_by_id($conn, $arsCompanyId, $requestId);
            if (!$request || (int)$request['booking_id'] !== $bookingId) {
                echo json_encode(['success' => false, 'error' => 'Request not found for this booking.']);
                exit;
            }
            if ((string)$request['status'] !== 'approved') {
                echo json_encode(['success' => false, 'error' => 'Only approved requests can be re-applied.']);
                exit;
            }
            if (ars_booking_is_financially_locked($conn, $booking)
                && in_array((string)$request['request_type'], ['extension', 'early_checkin', 'late_checkout', 'cancellation'], true)
            ) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Financially locked booking: this change requires Booking Amendment (Phase 2).',
                    'requires_amendment' => true,
                ]);
                exit;
            }
            $conn->beginTransaction();
            try {
                $applyResult = ars_booking_request_apply_approval($conn, $booking, $request, $userId);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
            $stmt = $conn->prepare('SELECT check_in, check_out, nights, total_amount, paid_amount, balance_due, payment_status, status FROM ars_bookings WHERE id = ? AND company_id = ?');
            $stmt->execute([$bookingId, $arsCompanyId]);
            $bookingFresh = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            echo json_encode(['success' => true, 'apply' => $applyResult, 'booking' => $bookingFresh]);
            break;

        case 'approve_lifecycle_request':
        case 'reject_lifecycle_request':
            $requestId = (int)($_POST['request_id'] ?? 0);
            $adminNote = trim((string)($_POST['admin_note'] ?? ''));
            if ($requestId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Missing request_id']);
                exit;
            }
            $request = ars_booking_request_by_id($conn, $arsCompanyId, $requestId);
            if (!$request || (int)$request['booking_id'] !== $bookingId) {
                echo json_encode(['success' => false, 'error' => 'Request not found for this booking.']);
                exit;
            }
            $newStatus = $action === 'approve_lifecycle_request' ? 'approved' : 'rejected';
            $applyResult = null;

            if ($newStatus === 'approved'
                && ars_booking_is_financially_locked($conn, $booking)
                && in_array((string)$request['request_type'], ['extension', 'early_checkin', 'late_checkout', 'cancellation'], true)
            ) {
                ars_booking_activity_log($conn, [
                    'company_id' => $arsCompanyId,
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'] ?? null,
                    'event_category' => 'financial',
                    'event_type' => 'amendment_required',
                    'title' => 'Lifecycle approval blocked — amendment required',
                    'description' => 'Request type: ' . (string)$request['request_type'],
                    'related_entity_type' => 'ars_booking_lifecycle_request',
                    'related_entity_id' => $requestId,
                    'status' => 'blocked',
                    'source' => 'user',
                    'created_by' => $userId,
                ]);
                echo json_encode([
                    'success' => false,
                    'error' => 'Financially locked booking: approving this request requires Booking Amendment (Phase 2).',
                    'requires_amendment' => true,
                    'impact' => ars_detect_amendment_financial_impact($conn, $booking, [
                        'check_out' => $request['requested_check_out'] ?? $booking['check_out'],
                        'check_in' => $request['requested_check_in'] ?? $booking['check_in'],
                    ]),
                ]);
                exit;
            }

            $conn->beginTransaction();
            try {
                $updated = ars_booking_request_update_status(
                    $conn,
                    $arsCompanyId,
                    $requestId,
                    $newStatus,
                    $userId,
                    $adminNote
                );

                if ($newStatus === 'approved') {
                    $applyResult = ars_booking_request_apply_approval(
                        $conn,
                        $booking,
                        $updated,
                        $userId
                    );
                }

                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }

            $notifyMessage = $newStatus === 'approved' && $applyResult
                ? (string)($applyResult['message'] ?? '')
                : 'Your ' . str_replace('_', ' ', (string)$request['request_type']) . ' request was ' . $newStatus . '.';
            if ($newStatus === 'rejected' && $adminNote !== '') {
                $notifyMessage .= ' Note: ' . $adminNote;
            }

            ars_guest_notification_create($conn, [
                'company_id' => $arsCompanyId,
                'guest_id' => (int)$request['guest_id'],
                'booking_id' => $bookingId,
                'event_type' => $newStatus === 'approved' ? 'request_approved' : 'request_rejected',
                'title' => $newStatus === 'approved' ? 'Request approved' : 'Request rejected',
                'message' => $notifyMessage,
                'cta_route' => '/guest/bookings/' . $bookingId,
                'meta' => [
                    'request_id' => (int)$updated['id'],
                    'request_type' => (string)$request['request_type'],
                    'admin_note' => $adminNote !== '' ? $adminNote : null,
                    'apply' => $applyResult,
                ],
            ]);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'operational',
                'event_type' => 'lifecycle_request_' . $newStatus,
                'title' => 'Lifecycle request ' . $newStatus,
                'description' => (string)$request['request_type'],
                'related_entity_type' => 'ars_booking_request',
                'related_entity_id' => (int)$updated['id'],
                'created_by' => $userId,
            ]);

            $stmt = $conn->prepare('SELECT check_in, check_out, nights, total_amount, paid_amount, balance_due, payment_status, status FROM ars_bookings WHERE id = ? AND company_id = ?');
            $stmt->execute([$bookingId, $arsCompanyId]);
            $bookingFresh = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            echo json_encode([
                'success' => true,
                'request' => $updated,
                'apply' => $applyResult,
                'booking' => $bookingFresh,
            ]);
            break;

        case 'preview_stay_dates': {
            $checkIn = (string)($_POST['check_in'] ?? '');
            $checkOut = (string)($_POST['check_out'] ?? '');
            $preview = ars_booking_preview_pending_stay_dates($conn, $booking, $checkIn, $checkOut);
            echo json_encode(['success' => true, 'preview' => $preview]);
            break;
        }

        case 'apply_stay_dates': {
            $checkIn = (string)($_POST['check_in'] ?? '');
            $checkOut = (string)($_POST['check_out'] ?? '');
            $depositMode = strtolower(trim((string)($_POST['deposit_mode'] ?? 'keep')));
            $newDeposit = isset($_POST['deposit_amount']) && $_POST['deposit_amount'] !== ''
                ? (float)$_POST['deposit_amount']
                : null;

            $conn->beginTransaction();
            try {
                $lockStmt = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? AND company_id = ? FOR UPDATE');
                $lockStmt->execute([$bookingId, $arsCompanyId]);
                $booking = $lockStmt->fetch(PDO::FETCH_ASSOC) ?: $booking;

                $conn->prepare('SELECT id FROM re_units WHERE id = ? FOR UPDATE')->execute([(int)$booking['unit_id']]);

                $result = ars_booking_apply_pending_stay_dates(
                    $conn,
                    $booking,
                    $checkIn,
                    $checkOut,
                    $depositMode,
                    $newDeposit
                );

                $fmtDate = static function ($d): string {
                    $d = trim((string)$d);
                    if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) {
                        return $d !== '' ? $d : '—';
                    }
                    $ts = strtotime(substr($d, 0, 10));
                    return $ts ? date('j M Y', $ts) : $d;
                };
                $oldIn = (string)($result['old']['check_in'] ?? '');
                $oldOut = (string)($result['old']['check_out'] ?? '');
                $newIn = (string)($result['check_in'] ?? '');
                $newOut = (string)($result['check_out'] ?? '');
                $oldNights = (int)($result['old']['nights'] ?? 0);
                $newNights = (int)($result['nights'] ?? 0);
                $oldTotal = number_format((float)($result['old']['total_amount'] ?? 0), 2);
                $newTotal = number_format((float)($result['total_amount'] ?? 0), 2);
                $depAmt = number_format((float)($result['deposit_amount'] ?? 0), 2);
                $oldDep = number_format((float)($result['old']['deposit_amount'] ?? 0), 2);

                $descParts = [
                    'Check-in ' . $fmtDate($oldIn) . ' → ' . $fmtDate($newIn),
                    'Check-out ' . $fmtDate($oldOut) . ' → ' . $fmtDate($newOut),
                    'Nights ' . $oldNights . ' → ' . $newNights,
                    'Stay total AED ' . $oldTotal . ' → AED ' . $newTotal,
                ];
                if (($result['deposit_mode'] ?? 'keep') === 'set') {
                    $descParts[] = 'Deposit AED ' . $oldDep . ' → AED ' . $depAmt;
                } else {
                    $descParts[] = 'Deposit kept AED ' . $depAmt;
                }

                $prevLabel = $fmtDate($oldIn) . ' → ' . $fmtDate($oldOut)
                    . ' · ' . $oldNights . ' night' . ($oldNights === 1 ? '' : 's')
                    . ' · AED ' . $oldTotal;
                $newLabel = $fmtDate($newIn) . ' → ' . $fmtDate($newOut)
                    . ' · ' . $newNights . ' night' . ($newNights === 1 ? '' : 's')
                    . ' · AED ' . $newTotal;

                ars_booking_activity_log($conn, [
                    'company_id' => $arsCompanyId,
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'] ?? null,
                    'event_category' => 'operational',
                    'event_type' => 'stay_dates_corrected',
                    'title' => 'Stay dates corrected',
                    'description' => implode(' · ', $descParts),
                    'previous_value' => $prevLabel,
                    'new_value' => $newLabel,
                    'created_by' => $userId,
                    'source' => 'user',
                ]);

                $arsAudit(
                    'ars.booking.stay_dates_corrected',
                    'Corrected stay dates on pending booking '
                        . (string)($booking['booking_number'] ?? ('#' . $bookingId)),
                    [
                        'meta' => [
                            'old' => $result['old'] ?? [],
                            'new' => [
                                'check_in' => $result['check_in'] ?? null,
                                'check_out' => $result['check_out'] ?? null,
                                'nights' => $result['nights'] ?? null,
                                'total_amount' => $result['total_amount'] ?? null,
                                'deposit_amount' => $result['deposit_amount'] ?? null,
                                'deposit_mode' => $result['deposit_mode'] ?? 'keep',
                            ],
                        ],
                    ]
                );

                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }

            echo json_encode(['success' => true, 'result' => $result]);
            break;
        }

        case 'list_booking_documents': {
            require_once __DIR__ . '/includes/ars_booking_documents.php';
            $catalog = ars_booking_documents_catalog($conn, $booking);
            foreach ($catalog as &$item) {
                $qs = http_build_query([
                    'booking_id' => $bookingId,
                    'doc_type' => $item['doc_type'],
                    'payment_id' => $item['payment_id'] ?? null,
                ]);
                $item['staff_download_url'] = 'document_download.php?' . $qs;
            }
            unset($item);
            echo json_encode(['success' => true, 'documents' => $catalog]);
            break;
        }

        case 'send_booking_document': {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_start();
            require_once __DIR__ . '/includes/ars_booking_documents.php';
            require_once __DIR__ . '/../../includes/email_service.php';
            $docType = (string)($_POST['doc_type'] ?? '');
            $paymentId = isset($_POST['payment_id']) && $_POST['payment_id'] !== ''
                ? (int)$_POST['payment_id']
                : null;
            $allowed = ['booking_confirmation', 'payment_receipt', 'tax_invoice'];
            if (!in_array($docType, $allowed, true)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => 'Invalid document type.']);
                exit;
            }
            $gStmt = $conn->prepare('SELECT email, first_name, last_name FROM ars_guests WHERE id = ? LIMIT 1');
            $gStmt->execute([(int)$booking['guest_id']]);
            $guest = $gStmt->fetch(PDO::FETCH_ASSOC);
            $email = trim((string)($guest['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => 'Guest has no valid email on file.']);
                exit;
            }
            try {
                $file = ars_booking_document_get_pdf($conn, $booking, $docType, $paymentId);
            } catch (Throwable $e) {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => 'PDF failed: ' . $e->getMessage()]);
                exit;
            }
            $tmpDir = function_exists('ars_booking_pdf_temp_dir')
                ? ars_booking_pdf_temp_dir()
                : sys_get_temp_dir();
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0777, true);
            }
            $tmpPath = rtrim($tmpDir, '/\\') . '/ars_doc_' . $bookingId . '_' . bin2hex(random_bytes(6)) . '.pdf';
            if (file_put_contents($tmpPath, $file['bytes']) === false) {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => 'Could not write temporary PDF.']);
                exit;
            }
            $labels = [
                'booking_confirmation' => 'Booking confirmation',
                'payment_receipt' => 'Payment receipt',
                'tax_invoice' => 'Tax invoice',
            ];
            $label = $labels[$docType] ?? 'Document';
            $guestName = trim(((string)($guest['first_name'] ?? '')) . ' ' . ((string)($guest['last_name'] ?? '')));
            $subject = $label . ' — ' . (string)($booking['booking_number'] ?? ('#' . $bookingId));
            $body = '<p>Dear ' . htmlspecialchars($guestName !== '' ? $guestName : 'Guest', ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>Please find attached your <strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong> '
                . 'for booking <strong>' . htmlspecialchars((string)($booking['booking_number'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                . '<p>Thank you.</p>';
            try {
                $mailer = new EmailService($conn);
                $send = $mailer->sendCustomEmail($email, $subject, $body, strip_tags($body), [
                    ['path' => $tmpPath, 'name' => $file['filename'], 'type' => 'application/pdf'],
                ]);
            } catch (Throwable $e) {
                @unlink($tmpPath);
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => 'Email failed: ' . $e->getMessage()]);
                exit;
            }
            @unlink($tmpPath);
            if (empty($send['success'])) {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => $send['error'] ?? 'Email send failed. Check Email Settings (SMTP).']);
                exit;
            }
            try {
                ars_booking_activity_log($conn, [
                    'company_id' => $arsCompanyId,
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'] ?? null,
                    'event_category' => 'documents',
                    'event_type' => 'document_sent',
                    'title' => $label . ' emailed to guest',
                    'description' => $email,
                    'created_by' => $userId,
                    'source' => 'user',
                ]);
            } catch (Throwable $ignored) {
            }
            try {
                ars_guest_notification_create($conn, [
                    'company_id' => $arsCompanyId,
                    'guest_id' => (int)$booking['guest_id'],
                    'booking_id' => $bookingId,
                    'event_type' => 'document_sent',
                    'title' => $label . ' sent',
                    'message' => 'We emailed your ' . $label . ' for booking ' . (string)($booking['booking_number'] ?? ('#' . $bookingId)) . '.',
                    'cta_route' => '/guest/bookings/' . $bookingId,
                ]);
            } catch (Throwable $ignored) {
            }
            ob_end_clean();
            echo json_encode(['success' => true, 'sent_to' => $email]);
            break;
        }

        case 'list_attachments': {
            require_once __DIR__ . '/includes/ars_booking_attachments.php';
            $rows = ars_booking_attachments_list($conn, $arsCompanyId, $bookingId);
            foreach ($rows as &$r) {
                $r['download_url'] = 'document_download.php?booking_id=' . $bookingId . '&attachment_id=' . (int)$r['id'];
            }
            unset($r);
            echo json_encode(['success' => true, 'attachments' => $rows]);
            break;
        }

        case 'upload_attachment': {
            require_once __DIR__ . '/includes/ars_booking_attachments.php';
            if (empty($_FILES['file'])) {
                echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
                exit;
            }
            $docCategory = ars_booking_doc_category_normalize((string)($_POST['doc_category'] ?? 'other'));
            $up = ars_booking_attachment_upload($conn, $arsCompanyId, $bookingId, $_FILES['file'], $userId, $docCategory);
            if (!$up['success']) {
                echo json_encode(['success' => false, 'error' => $up['error']]);
                exit;
            }
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'documents',
                'event_type' => 'attachment_uploaded',
                'title' => 'Document uploaded — ' . ars_booking_doc_category_label($docCategory),
                'description' => (string)($up['attachment']['original_name'] ?? ''),
                'related_entity_type' => 'ars_booking_attachment',
                'related_entity_id' => (int)($up['attachment']['id'] ?? 0),
                'created_by' => $userId,
                'source' => 'user',
            ]);
            echo json_encode(['success' => true, 'attachment' => $up['attachment']]);
            break;
        }

        case 'set_attachment_category': {
            require_once __DIR__ . '/includes/ars_booking_attachments.php';
            $attachmentId = (int)($_POST['attachment_id'] ?? 0);
            if ($attachmentId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Missing attachment.']);
                exit;
            }
            $docCategory = ars_booking_doc_category_normalize((string)($_POST['doc_category'] ?? 'other'));
            $res = ars_booking_attachment_set_category($conn, $arsCompanyId, $bookingId, $attachmentId, $docCategory);
            if (!$res['success']) {
                echo json_encode(['success' => false, 'error' => $res['error']]);
                exit;
            }
            try {
                ars_booking_activity_log($conn, [
                    'company_id' => $arsCompanyId,
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'] ?? null,
                    'event_category' => 'documents',
                    'event_type' => 'attachment_recategorised',
                    'title' => 'Document re-filed under ' . ars_booking_doc_category_label($docCategory),
                    'related_entity_type' => 'ars_booking_attachment',
                    'related_entity_id' => $attachmentId,
                    'created_by' => $userId,
                    'source' => 'user',
                ]);
            } catch (Throwable $ignored) {
            }
            echo json_encode(['success' => true, 'doc_category' => $res['doc_category']]);
            break;
        }

        case 'delete_attachment': {
            require_once __DIR__ . '/includes/ars_booking_attachments.php';
            $attachmentId = (int)($_POST['attachment_id'] ?? 0);
            if ($attachmentId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Missing attachment.']);
                exit;
            }
            $res = ars_booking_attachment_delete($conn, $arsCompanyId, $bookingId, $attachmentId);
            if (!$res['success']) {
                echo json_encode(['success' => false, 'error' => $res['error']]);
                exit;
            }
            try {
                ars_booking_activity_log($conn, [
                    'company_id' => $arsCompanyId,
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'] ?? null,
                    'event_category' => 'documents',
                    'event_type' => 'attachment_deleted',
                    'title' => 'Document deleted — ' . ars_booking_doc_category_label($res['doc_category']),
                    'description' => (string)$res['original_name'],
                    'related_entity_type' => 'ars_booking_attachment',
                    'related_entity_id' => $attachmentId,
                    'created_by' => $userId,
                    'source' => 'user',
                ]);
            } catch (Throwable $ignored) {
            }
            $arsAudit('delete', 'Deleted uploaded document: ' . (string)$res['original_name']);
            echo json_encode(['success' => true]);
            break;
        }

        case 'create_service_invoice': {
            require_once __DIR__ . '/includes/ars_accounting.php';
            require_once __DIR__ . '/includes/ars_financial_adapter.php';
            if (!ars_financial_adapter_enabled($conn, $arsCompanyId)) {
                echo json_encode(['success' => false, 'error' => 'Financial adapter is disabled.']);
                exit;
            }
            $lineType = (string)($_POST['line_type'] ?? 'service');
            if (!in_array($lineType, ['service', 'damage', 'other'], true)) {
                $lineType = 'service';
            }
            if ($lineType === 'other') {
                $lineType = 'service';
            }
            $desc = trim((string)($_POST['description'] ?? ''));
            $net = round((float)($_POST['amount_net'] ?? $_POST['amount'] ?? 0), 2);
            if ($desc === '' || $net <= 0) {
                echo json_encode(['success' => false, 'error' => 'Description and positive amount (ex-VAT net) are required.']);
                exit;
            }
            $applyDeposit = !empty($_POST['apply_deposit']) && $lineType === 'damage';
            $r = ars_adapter_create_service_invoice($conn, $booking, [
                'user_id' => $userId,
                'line_type' => $lineType === 'damage' ? 'damage' : 'service',
                'description' => $desc,
                'amount_net' => $net,
                'apply_deposit' => $applyDeposit,
                'deposit_apply_amount' => $applyDeposit ? round((float)($_POST['deposit_apply_amount'] ?? $net), 2) : null,
            ]);
            if (empty($r['success'])) {
                echo json_encode(['success' => false, 'error' => $r['error'] ?? 'Service invoice failed', 'code' => $r['code'] ?? null]);
                exit;
            }
            // Keep ops charge row for Money tab visibility (non-blocking)
            try {
                $qty = max(1, (float)($_POST['quantity'] ?? 1));
                $conn->prepare("
                    INSERT INTO ars_booking_charges (booking_id, charge_type, description, quantity, unit_price, total, charge_date)
                    VALUES (?, ?, ?, ?, ?, ?, CURDATE())
                ")->execute([
                    $bookingId,
                    $lineType === 'damage' ? 'damage' : 'other',
                    $desc,
                    $qty,
                    $net,
                    round($qty * $net, 2),
                ]);
            } catch (Throwable $ignored) {
            }
            require_once __DIR__ . '/includes/ars_pricing.php';
            try {
                ars_recalc_booking_totals($conn, $bookingId);
            } catch (Throwable $ignored) {
            }
            echo json_encode([
                'success' => true,
                'document_id' => $r['document_id'] ?? null,
                'journal_id' => $r['journal_id'] ?? null,
                'document_number' => $r['document_number'] ?? null,
            ]);
            break;
        }

        case 'create_extension_invoice': {
            require_once __DIR__ . '/includes/ars_accounting.php';
            require_once __DIR__ . '/includes/ars_financial_adapter.php';
            require_once __DIR__ . '/includes/ars_availability.php';
            if (!ars_financial_adapter_enabled($conn, $arsCompanyId)) {
                echo json_encode(['success' => false, 'error' => 'Financial adapter is disabled.']);
                exit;
            }
            $newOut = trim((string)($_POST['new_check_out'] ?? ''));
            $r = ars_adapter_create_extension_invoice($conn, $booking, [
                'user_id' => $userId,
                'new_check_out' => $newOut,
                'prior_check_out' => (string)$booking['check_out'],
            ]);
            if (empty($r['success'])) {
                echo json_encode(['success' => false, 'error' => $r['error'] ?? 'Extension invoice failed', 'code' => $r['code'] ?? null]);
                exit;
            }
            if (function_exists('ars_recalc_booking_totals')) {
                try {
                    ars_recalc_booking_totals($conn, $bookingId);
                } catch (Throwable $ignored) {
                }
            }
            echo json_encode([
                'success' => true,
                'document_id' => $r['document_id'] ?? null,
                'journal_id' => $r['journal_id'] ?? null,
            ]);
            break;
        }

        case 'create_credit_note': {
            require_once __DIR__ . '/includes/ars_accounting.php';
            require_once __DIR__ . '/includes/ars_financial_adapter.php';
            if (!ars_financial_adapter_enabled($conn, $arsCompanyId)) {
                echo json_encode(['success' => false, 'error' => 'Financial adapter is disabled.']);
                exit;
            }
            $net = round((float)($_POST['amount_net'] ?? 0), 2);
            $desc = trim((string)($_POST['description'] ?? 'Credit note'));
            $toCredit = !empty($_POST['to_guest_credit']);
            if ($net <= 0) {
                echo json_encode(['success' => false, 'error' => 'Credit note net amount must be positive.']);
                exit;
            }
            $parentId = isset($_POST['applies_to_document_id']) ? (int)$_POST['applies_to_document_id'] : null;
            $r = ars_adapter_create_credit_note($conn, $booking, [
                'user_id' => $userId,
                'amount_net' => $net,
                'description' => $desc !== '' ? $desc : 'Credit note',
                'to_guest_credit' => $toCredit,
                'parent_document_id' => $parentId > 0 ? $parentId : null,
                'idempotency_key' => 'cn:' . $bookingId . ':' . $net . ':' . md5($desc . microtime(true)),
            ]);
            if (empty($r['success'])) {
                echo json_encode(['success' => false, 'error' => $r['error'] ?? 'Credit note failed', 'code' => $r['code'] ?? null]);
                exit;
            }
            echo json_encode([
                'success' => true,
                'document_id' => $r['document_id'] ?? null,
                'journal_id' => $r['journal_id'] ?? null,
                'guest_credit' => $r['guest_credit'] ?? null,
            ]);
            break;
        }

        case 'create_adjustment_invoice': {
            require_once __DIR__ . '/includes/ars_accounting.php';
            require_once __DIR__ . '/includes/ars_financial_adapter.php';
            require_once __DIR__ . '/includes/ars_financial_adapter_phase2d.php';
            require_once __DIR__ . '/includes/ars_pricing.php';
            if (!ars_financial_adapter_enabled($conn, $arsCompanyId)) {
                echo json_encode(['success' => false, 'error' => 'Financial adapter is disabled.']);
                exit;
            }
            // Rate/total correction on a posted booking. Does NOT rewrite the original
            // invoice — raises a separate ARS-ADJ document against ROOM_REVENUE.
            //
            // Amount semantics follow the booking's vat_mode (ars_adapter_vat_split):
            //   exclusive -> value is net, VAT added on top
            //   inclusive -> value is the gross total, VAT extracted from it
            $amount = round((float)($_POST['amount_net'] ?? 0), 2);
            $desc = trim((string)($_POST['description'] ?? ''));
            if ($amount <= 0) {
                echo json_encode(['success' => false, 'error' => 'Adjustment amount must be positive.']);
                exit;
            }
            if ($desc === '') {
                $desc = 'Price adjustment';
            }
            $amounts = ars_adapter_vat_split($amount, $booking);

            $r = ars_adapter_create_adjustment_invoice($conn, $booking, [
                'user_id' => $userId,
                'amount_net' => $amount,
                'description' => $desc,
                'account_role' => 'ROOM_REVENUE',
                'idempotency_key' => 'adj:' . $bookingId . ':' . $amount . ':' . md5($desc),
            ]);
            if (empty($r['success'])) {
                echo json_encode(['success' => false, 'error' => $r['error'] ?? 'Adjustment invoice failed', 'code' => $r['code'] ?? null]);
                exit;
            }

            // ars_recalc_booking_totals() derives the booking total from subtotal +
            // ars_booking_charges only — it never reads ars_financial_documents. Without
            // a matching charge row the posted document would not move "Stay total".
            // ars_finalize_booking_totals() treats extras as NET in every VAT mode, so
            // the row carries the document's net, not the entered amount.
            if (empty($r['replay'])) {
                $conn->prepare("
                    INSERT INTO ars_booking_charges
                        (booking_id, charge_type, description, quantity, unit_price, total, charge_date, financial_document_id)
                    VALUES (?, 'other', ?, 1, ?, ?, CURDATE(), ?)
                ")->execute([
                    $bookingId,
                    $desc,
                    $amounts['net'],
                    $amounts['net'],
                    $r['document_id'] ?? null,
                ]);
            }

            try {
                ars_recalc_booking_totals($conn, $bookingId);
            } catch (Throwable $ignored) {
            }

            $fresh = $conn->prepare("SELECT total_amount FROM ars_bookings WHERE id = ? AND company_id = ?");
            $fresh->execute([$bookingId, $arsCompanyId]);
            $newTotal = (float)$fresh->fetchColumn();

            $arsAudit('adjustment_invoice_created', 'Adjustment invoice AED ' . number_format($amounts['total'], 2) . ' for booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)) . ' — stay total now AED ' . number_format($newTotal, 2), [
                'old_data' => ['total_amount' => (float)$booking['total_amount']],
                'new_data' => ['total_amount' => $newTotal, 'description' => $desc],
            ]);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'financial',
                'event_type' => 'adjustment_invoice_created',
                'title' => 'Rate adjustment AED ' . number_format($amounts['total'], 2),
                'description' => $desc,
                'previous_value' => number_format((float)$booking['total_amount'], 2, '.', ''),
                'new_value' => number_format($newTotal, 2, '.', ''),
                'related_entity_type' => 'ars_financial_document',
                'related_entity_id' => $r['document_id'] ?? null,
                'related_journal_id' => $r['journal_id'] ?? null,
                'created_by' => $userId,
            ]);

            echo json_encode([
                'success' => true,
                'document_id' => $r['document_id'] ?? null,
                'journal_id' => $r['journal_id'] ?? null,
                'document_number' => $r['document_number'] ?? null,
                'stay_total' => $newTotal,
                'replay' => !empty($r['replay']),
            ]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
