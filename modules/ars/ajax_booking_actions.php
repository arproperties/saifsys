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
require_once __DIR__ . '/includes/ars_payment_plan.php';
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

$planLog = static function (array $planResult, float $monthlyAmount) use ($conn, $arsCompanyId, $userId, &$booking, &$bookingId): void {
    ars_booking_activity_log($conn, [
        'company_id' => $arsCompanyId,
        'booking_id' => $bookingId,
        'booking_number' => $booking['booking_number'] ?? null,
        'event_category' => 'payment',
        'event_type' => 'payment_plan_set',
        'title' => 'Monthly payment plan set',
        'previous_value' => $planResult['previous'] !== null ? number_format((float)$planResult['previous'], 2, '.', '') : null,
        'new_value' => number_format($monthlyAmount, 2, '.', ''),
        'created_by' => $userId,
    ]);
};

// Reverse one extension invoice and put its period back to "not billed".
// Shared by the Payments table's delete and the Extend tab's edit and delete,
// so a billed period is undone the same way from either side. Refuses once
// anything has been received against the invoice; 'gone' means there was no
// live invoice left to reverse.
$arsReverseExtensionBill = static function (int $documentId, string $reason) use ($conn, $arsCompanyId, $userId, $arsAudit, &$booking, &$bookingId): array {
    require_once __DIR__ . '/includes/ars_accounting.php';
    require_once __DIR__ . '/includes/ars_financial_adapter.php';
    $st = $conn->prepare("
        SELECT * FROM ars_financial_documents
        WHERE id = ? AND booking_id = ? AND company_id = ? AND document_type = 'extension_invoice'
    ");
    $st->execute([$documentId, $bookingId, $arsCompanyId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc || in_array(strtolower((string)$doc['status']), ['draft', 'voided', 'reversed'], true)) {
        return ['success' => false, 'gone' => true, 'error' => 'Extension charge not found on this booking.'];
    }
    if ((float)$doc['balance_due'] < (float)$doc['total_amount'] - 0.009) {
        return ['success' => false, 'error' => 'A payment is recorded against this extension. Delete that payment first.'];
    }
    $r = ars_adapter_reverse_document($conn, $arsCompanyId, $documentId, 'Extension charge deleted: ' . $reason, $userId);
    if (empty($r['success'])) {
        return ['success' => false, 'error' => 'Nothing was changed. ' . ($r['error'] ?? 'Reverse failed')];
    }
    // Nothing is owed on a reversed invoice, and its key is freed so the
    // same period can be billed again.
    $conn->prepare("
        UPDATE ars_financial_documents
        SET balance_due = 0, idempotency_key = CONCAT(COALESCE(idempotency_key, ''), ':rev', id)
        WHERE id = ? AND company_id = ?
    ")->execute([$documentId, $arsCompanyId]);
    $conn->prepare("UPDATE ars_booking_extension_log SET document_id = NULL WHERE document_id = ? AND booking_id = ? AND company_id = ?")
        ->execute([$documentId, $bookingId, $arsCompanyId]);
    $amountLabel = number_format((float)$doc['total_amount'], 2);
    $arsAudit('booking_updated', 'Deleted extension charge ' . ($doc['document_number'] ?? ('#' . $documentId)) . ' of AED ' . $amountLabel
        . ' on booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)) . ': ' . $reason, [
        'old_data' => ['document_id' => $documentId, 'total_amount' => $doc['total_amount'], 'status' => $doc['status']],
    ]);
    ars_booking_activity_log($conn, [
        'company_id' => $arsCompanyId,
        'booking_id' => $bookingId,
        'booking_number' => $booking['booking_number'] ?? null,
        'event_category' => 'payment',
        'event_type' => 'extension_charge_deleted',
        'title' => 'Extension charge ' . ($doc['document_number'] ?? ('#' . $documentId)) . ' deleted (AED ' . $amountLabel . ')',
        'description' => $reason,
        'previous_value' => number_format((float)$doc['total_amount'], 2, '.', ''),
        'related_entity_type' => 'ars_financial_document',
        'related_entity_id' => $documentId,
        'related_journal_id' => $r['reversal_journal_id'] ?? null,
        'created_by' => $userId,
    ]);
    return ['success' => true];
};

// Raise the extension invoice for one logged period and tie it to the row.
// Used by Bill, and again when a billed period is corrected.
$arsBillExtensionEntry = static function (array $entry) use ($conn, $arsCompanyId, $userId, &$booking, &$bookingId): array {
    require_once __DIR__ . '/includes/ars_accounting.php';
    require_once __DIR__ . '/includes/ars_financial_adapter.php';
    $entryId = (int)$entry['id'];
    $r = ars_adapter_create_extension_invoice($conn, $booking, [
        'user_id' => $userId,
        'rate' => round((float)($entry['rate_per_night'] ?? 0), 2),
        // Bill what the row says, not rate x nights: a typed total
        // need not multiply back from the rounded rate.
        'amount' => round((float)($entry['amount'] ?? 0), 2),
        'prior_check_out' => (string)$entry['extended_from'],
        'new_check_out' => (string)$entry['extended_to'],
        // The log owns the booking's dates; ars_sync_checkout_to_extension_log()
        // sets them from the whole log, not from this one period.
        'update_booking_dates' => false,
        'idempotency_key' => 'invoice:extension:' . $bookingId . ':log' . $entryId,
    ]);
    if (!empty($r['success'])) {
        $conn->prepare("UPDATE ars_booking_extension_log SET document_id = ? WHERE id = ? AND booking_id = ? AND company_id = ?")
            ->execute([(int)($r['document_id'] ?? 0) ?: null, $entryId, $bookingId, $arsCompanyId]);
    }
    return $r;
};

// Correct one logged period. A billed one has its invoice reversed and raised
// again at the new figures; a new note alone leaves the invoice be. The caller
// owns the transaction and rolls it back when this does not succeed, so a
// period is never left half-corrected.
$arsApplyExtensionEdit = static function (array $entry, array $in) use ($conn, $arsCompanyId, &$bookingId, $arsReverseExtensionBill, $arsBillExtensionEntry): array {
    $entryId = (int)$entry['id'];
    $billed = !empty($entry['document_id']);
    $invoiceChanged = $in['from'] !== (string)$entry['extended_from']
        || $in['to'] !== (string)$entry['extended_to']
        || abs((float)($in['amount'] ?? 0) - (float)($entry['amount'] ?? 0)) > 0.004;
    $rebill = $billed && $invoiceChanged;
    if ($rebill) {
        ars_require_booking_action($conn, 'delete_extension_bill');
        if ((float)($in['amount'] ?? 0) <= 0) {
            return ['success' => false, 'error' => 'A billed period needs a price. Delete the period instead if nothing is to be charged.'];
        }
    }
    $newDoc = null;
    $rebilled = false;
    if ($rebill) {
        $rev = $arsReverseExtensionBill((int)$entry['document_id'], 'Extension period '
            . $entry['extended_from'] . ' to ' . $entry['extended_to'] . ' corrected');
        if (empty($rev['success']) && empty($rev['gone'])) {
            return ['success' => false, 'error' => $rev['error'] ?? 'Could not reverse the invoice.'];
        }
        $rebilled = !empty($rev['success']);
        if (!$rebilled) {
            // Its invoice was already reversed elsewhere: the row goes back
            // to "not billed" rather than pointing at it.
            $conn->prepare("UPDATE ars_booking_extension_log SET document_id = NULL WHERE id = ? AND booking_id = ? AND company_id = ?")
                ->execute([$entryId, $bookingId, $arsCompanyId]);
        }
    }
    $conn->prepare("
        UPDATE ars_booking_extension_log
        SET extended_from = ?, extended_to = ?, nights = ?, rate_per_night = ?, amount = ?, note = ?
        WHERE id = ? AND booking_id = ? AND company_id = ?
    ")->execute([$in['from'], $in['to'], $in['nights'], $in['rate'], $in['amount'], $in['note'], $entryId, $bookingId, $arsCompanyId]);
    if ($rebilled) {
        $newDoc = $arsBillExtensionEntry([
            'id' => $entryId,
            'extended_from' => $in['from'],
            'extended_to' => $in['to'],
            'rate_per_night' => $in['rate'],
            'amount' => $in['amount'],
        ]);
        if (empty($newDoc['success'])) {
            return ['success' => false, 'error' => 'Nothing was changed. ' . ($newDoc['error'] ?? 'Extension invoice failed')];
        }
    }
    return ['success' => true, 'rebill' => $rebill, 'new_doc' => $newDoc];
};

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

            // Staff may check out after the day the guest actually left (an off
            // day, say), so the date can be typed; it defaults to today.
            $todayOut = (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
            $actualOut = trim((string)($_POST['actual_check_out'] ?? ''));
            if ($actualOut === '') {
                $actualOut = $todayOut;
            }
            $dOut = DateTime::createFromFormat('Y-m-d', $actualOut);
            if (!$dOut || $dOut->format('Y-m-d') !== $actualOut) {
                echo json_encode(['success' => false, 'error' => 'Choose a valid check-out date.']);
                exit;
            }
            if ($actualOut > $todayOut) {
                echo json_encode(['success' => false, 'error' => 'Check-out date cannot be in the future.']);
                exit;
            }
            if ($actualOut < (string)$booking['check_in']) {
                echo json_encode(['success' => false, 'error' => 'Check-out date cannot be before check-in.']);
                exit;
            }
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
                    // The cleaning is still to be done, so its work order is dated
                    // today even when the check-out itself is backdated.
                    $cleanResult = ars_trigger_checkout_cleaning($conn, array_merge($booking, ['actual_check_out' => $todayOut]), $unit, $settings, $userId);
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
                // Guest never checked in: nothing is owed, so the invoice is closed
                // and the balance becomes 0. Otherwise only the revenue is reversed.
                if (in_array($booking['status'], ['pending', 'confirmed'], true)) {
                    require_once __DIR__ . '/includes/ars_booking_void.php';
                    ars_booking_cancel_close_invoices($conn, $booking, $userId);
                } elseif ($booking['journal_id']) {
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
            // Zero is allowed: it records a line carrying a Total amount with
            // nothing received against it yet. Only a negative is refused.
            if ($amount < 0) {
                echo json_encode(['success' => false, 'error' => 'Amount cannot be negative']);
                exit;
            }

            $method     = $_POST['payment_method'] ?? 'cash';
            $date       = $_POST['payment_date'] ?? date('Y-m-d');
            $ref        = trim($_POST['reference_number'] ?? '');
            $linkUrl    = trim($_POST['payment_link_url'] ?? '');
            $notes      = trim($_POST['notes'] ?? '');
            $linkStatus = $linkUrl ? ($_POST['payment_link_status'] ?? 'paid') : null;
            $receiptAccountCode = trim((string)($_POST['receipt_account_code'] ?? ''));

            // Manually entered stay total for this row; blank means "use the
            // invoiced total from the AR documents".
            $totalRaw = trim((string)($_POST['total_amount'] ?? ''));
            $totalAmount = ($totalRaw === '') ? null : round((float)$totalRaw, 2);
            if ($totalAmount !== null && $totalAmount < 0) {
                echo json_encode(['success' => false, 'error' => 'Total amount cannot be negative']);
                exit;
            }
            ars_ensure_payment_total_column($conn);

            require_once __DIR__ . '/includes/ars_account_roles.php';
            ars_ensure_receipt_account_columns($conn);
            $glCompanyId = ars_financial_gl_company_id($conn, $arsCompanyId);
            $receiptCheck = ars_resolve_receipt_account($conn, $glCompanyId, (string)$method, $receiptAccountCode);
            if (!$receiptCheck['success']) {
                echo json_encode(['success' => false, 'error' => $receiptCheck['error']]);
                exit;
            }

            // Monthly tab: the plan is saved with the payment, and a refused
            // plan change stops the payment too rather than half-saving.
            $planMode = (string)($_POST['plan_mode'] ?? 'full');
            $planResult = null;
            // Before the transaction: CREATE TABLE commits it behind PDO's
            // back, and the commit below then fails with "There is no active
            // transaction" although the payment was already written.
            if ($planMode === 'monthly') {
                ars_ensure_payment_plan_table($conn);
            }

            $conn->beginTransaction();
            try {
                if ($planMode === 'monthly') {
                    $planResult = ars_payment_plan_save($conn, $arsCompanyId, $bookingId, (float)($_POST['monthly_amount'] ?? 0), $userId);
                    if (empty($planResult['success'])) {
                        $conn->rollBack();
                        echo json_encode(['success' => false, 'error' => $planResult['error']]);
                        exit;
                    }
                }
                $conn->prepare("
                    INSERT INTO ars_booking_payments
                        (booking_id, company_id, amount, total_amount, payment_method, receipt_account_code, payment_date, reference_number, payment_link_url, payment_link_status, notes, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $bookingId, $arsCompanyId, $amount, $totalAmount, $method, $receiptCheck['account_code'], $date,
                    $ref ?: null, $linkUrl ?: null, $linkStatus, $notes ?: null, $userId,
                ]);

                $paymentId = (int)$conn->lastInsertId();

                require_once __DIR__ . '/includes/ars_accounting.php';
                $pmRow = $conn->prepare("SELECT * FROM ars_booking_payments WHERE id = ? AND company_id = ?");
                $pmRow->execute([$paymentId, $arsCompanyId]);
                $pmRow = $pmRow->fetch(PDO::FETCH_ASSOC);
                // No money moved, so there is nothing to post to the GL and
                // nothing to allocate. The row exists purely to carry its
                // Total amount in the Payments table.
                $journalResult = ['success' => true, 'journal_id' => null];
                if ($amount > 0) {
                    $journalResult = ars_post_payment_journal($conn, $pmRow, $booking, $userId);
                    if (empty($journalResult['success'])) {
                        throw new RuntimeException('Payment journal failed: ' . ($journalResult['error'] ?? 'Unknown error'));
                    }
                }

                ars_recalc_booking_totals($conn, $bookingId);
                $freshStmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ? AND company_id = ?");
                $freshStmt->execute([$bookingId, $arsCompanyId]);
                $booking = $freshStmt->fetch(PDO::FETCH_ASSOC) ?: $booking;
                if ($amount > 0) {
                    ars_booking_engage_financial_lock($conn, $booking, 'Payment recorded', $userId);
                }

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
            if ($planResult && !empty($planResult['changed'])) {
                $planLog($planResult, (float)$_POST['monthly_amount']);
            }
            echo json_encode(['success' => true, 'payment_id' => $paymentId]);
            break;

        case 'save_payment_plan':
            $monthlyAmount = (float)($_POST['monthly_amount'] ?? 0);
            $planResult = ars_payment_plan_save($conn, $arsCompanyId, $bookingId, $monthlyAmount, $userId);
            if (empty($planResult['success'])) {
                echo json_encode(['success' => false, 'error' => $planResult['error']]);
                exit;
            }
            if (!empty($planResult['changed'])) {
                $planLog($planResult, $monthlyAmount);
            }
            echo json_encode(['success' => true]);
            break;

        // Correct one month of the plan from the Record Payment window: its
        // due date, its amount, or its status. A blank field goes back to
        // being worked out; nothing here records money.
        case 'update_payment_plan_instalment': {
            if (!ars_payment_plan_get($conn, $arsCompanyId, $bookingId)) {
                echo json_encode(['success' => false, 'error' => 'This booking has no monthly plan. Save the plan first.']);
                exit;
            }
            $seq = (int)($_POST['seq'] ?? -1);
            $monthCount = count(ars_payment_plan_due_dates((string)$booking['check_in'], (string)$booking['check_out']));
            if ($seq < 0 || $seq >= max(1, $monthCount)) {
                echo json_encode(['success' => false, 'error' => 'Month not found on this plan.']);
                exit;
            }
            $dueRaw = trim((string)($_POST['due_date'] ?? ''));
            $amountRaw = trim((string)($_POST['amount'] ?? ''));
            $statusRaw = trim((string)($_POST['status'] ?? ''));
            $saved = ars_payment_plan_instalment_save(
                $conn,
                $arsCompanyId,
                $bookingId,
                $seq,
                $dueRaw !== '' ? $dueRaw : null,
                $amountRaw !== '' ? (float)$amountRaw : null,
                $statusRaw !== '' ? $statusRaw : null,
                (int)$userId
            );
            if (empty($saved['success'])) {
                echo json_encode(['success' => false, 'error' => $saved['error'] ?? 'Could not save the month.']);
                exit;
            }
            $what = !empty($saved['reset'])
                ? 'reset to the plan'
                : trim(($dueRaw !== '' ? 'due ' . $dueRaw . ' ' : '') . ($amountRaw !== '' ? 'AED ' . number_format((float)$amountRaw, 2) . ' ' : '') . ($statusRaw !== '' ? '(' . $statusRaw . ')' : ''));
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'payment',
                'event_type' => 'payment_plan_month_edited',
                'title' => 'Monthly plan: month ' . ($seq + 1) . ' ' . $what,
                'created_by' => $userId,
            ]);
            echo json_encode(['success' => true, 'reset' => !empty($saved['reset'])]);
            break;
        }

        case 'clear_payment_plan':
            if (ars_payment_plan_clear($conn, $arsCompanyId, $bookingId)) {
                ars_booking_activity_log($conn, [
                    'company_id' => $arsCompanyId,
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'] ?? null,
                    'event_category' => 'payment',
                    'event_type' => 'payment_plan_cleared',
                    'title' => 'Switched to full payment',
                    'created_by' => $userId,
                ]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'set_security_deposit':
            $depAmount = max(0, (float)($_POST['deposit_amount'] ?? 0));
            try {
                // Until the deposit is received nothing is posted, so the lock doesn't apply.
                if (!in_array((string)($booking['deposit_status'] ?? 'none'), ['none', 'pending'], true)) {
                    ars_assert_financial_edit_allowed($conn, $booking, ['deposit_amount' => $depAmount]);
                }
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

            ars_ensure_payment_total_column($conn);

            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            // Blank means "use the invoiced total", same as the Record dialog.
            $editTotalRaw = trim((string)($_POST['total_amount'] ?? ''));
            $editTotal = ($editTotalRaw === '') ? null : round((float)$editTotalRaw, 2);
            $method = (string)($_POST['payment_method'] ?? '');
            $receiptCode = trim((string)($_POST['receipt_account_code'] ?? ''));
            $date = trim((string)($_POST['payment_date'] ?? ''));
            $ref = trim((string)($_POST['reference_number'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            $dt = DateTime::createFromFormat('Y-m-d', $date);
            if (!$paymentId || $amount < 0 || !$dt || $dt->format('Y-m-d') !== $date) {
                echo json_encode(['success' => false, 'error' => 'Enter a received amount of zero or more and a valid date.']);
                exit;
            }
            if ($editTotal !== null && $editTotal < 0) {
                echo json_encode(['success' => false, 'error' => 'Total amount cannot be negative']);
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
                'total_amount' => ($payment['total_amount'] === null || $payment['total_amount'] === '')
                    ? '' : number_format((float)$payment['total_amount'], 2, '.', ''),
            ];
            $new = [
                'amount' => number_format($amount, 2, '.', ''),
                'payment_method' => $method,
                'receipt_account_code' => $receiptCode,
                'payment_date' => $date,
                'reference_number' => $ref,
                'notes' => $notes,
                'total_amount' => $editTotal === null ? '' : number_format($editTotal, 2, '.', ''),
            ];
            $changed = array_keys(array_diff_assoc($new, $old));
            if (!$changed) {
                echo json_encode(['success' => true]);
                break;
            }
            // total_amount is display only, so changing it alone never touches the GL.
            $repost = (bool)array_intersect($changed, ['amount', 'payment_method', 'receipt_account_code', 'payment_date']);

            $conn->beginTransaction();
            try {
                if ($repost) {
                    ars_payment_unpost($conn, $payment, $userId, 'Payment #' . $paymentId . ' edited');
                    $conn->prepare("
                        UPDATE ars_booking_payments
                        SET amount = ?, total_amount = ?, payment_method = ?, receipt_account_code = ?, payment_date = ?,
                            reference_number = ?, notes = ?, journal_id = NULL, financial_document_id = NULL
                        WHERE id = ?
                    ")->execute([$amount, $editTotal, $method, $receiptCode, $date, $ref ?: null, $notes ?: null, $paymentId]);

                    // Nothing received means nothing to post to the GL; the row
                    // just carries its Total amount.
                    if ($amount > 0) {
                        $st = $conn->prepare("SELECT * FROM ars_booking_payments WHERE id = ?");
                        $st->execute([$paymentId]);
                        $fresh = $st->fetch(PDO::FETCH_ASSOC);
                        $jr = ars_post_payment_journal($conn, $fresh, $booking, $userId);
                        if (empty($jr['success'])) {
                            throw new RuntimeException('Re-posting failed: ' . ($jr['error'] ?? 'unknown'));
                        }
                    }
                    ars_recalc_booking_totals($conn, $bookingId);
                } else {
                    $conn->prepare("UPDATE ars_booking_payments SET total_amount = ?, reference_number = ?, notes = ? WHERE id = ?")
                        ->execute([$editTotal, $ref ?: null, $notes ?: null, $paymentId]);
                }
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                echo json_encode(['success' => false, 'error' => 'Nothing was changed. ' . $e->getMessage()]);
                exit;
            }

            $labels = ['amount' => 'received amount', 'payment_method' => 'method', 'receipt_account_code' => 'GL account',
                'payment_date' => 'date', 'reference_number' => 'reference', 'notes' => 'notes',
                'total_amount' => 'total amount'];
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
            // Payment evidence: the payment must be on this booking, and the
            // file always files under Payment Receipts.
            $evidencePaymentId = (int)($_POST['payment_id'] ?? 0);
            if ($evidencePaymentId > 0) {
                $pmCheck = $conn->prepare("SELECT id FROM ars_booking_payments WHERE id = ? AND booking_id = ? AND company_id = ?");
                $pmCheck->execute([$evidencePaymentId, $bookingId, $arsCompanyId]);
                if (!$pmCheck->fetchColumn()) {
                    echo json_encode(['success' => false, 'error' => 'Payment not found on this booking.']);
                    exit;
                }
                $docCategory = 'payment_receipt';
            }
            $up = ars_booking_attachment_upload($conn, $arsCompanyId, $bookingId, $_FILES['file'], $userId, $docCategory, $evidencePaymentId ?: null);
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
                'title' => $evidencePaymentId > 0
                    ? 'Payment evidence uploaded — payment #' . $evidencePaymentId
                    : 'Document uploaded — ' . ars_booking_doc_category_label($docCategory),
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

        // --- Extend tab: a record of the periods a stay was extended by, each
        // priced at the rate agreed for it. Saving prices the period but does
        // not bill it — 'bill_extension_entry' is what raises the invoice.
        // See ars_ensure_extension_log_table().
        case 'save_extension_entry': {
            ars_ensure_extension_log_table($conn);
            $in = ars_extension_entry_from_post($_POST);
            if ($in['error'] !== null) {
                echo json_encode(['success' => false, 'error' => $in['error']]);
                exit;
            }
            [$from, $to, $nights, $rate, $amount] = [$in['from'], $in['to'], $in['nights'], $in['rate'], $in['amount']];
            $conn->prepare("
                INSERT INTO ars_booking_extension_log
                    (booking_id, company_id, extended_from, extended_to, nights, rate_per_night, amount, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$bookingId, $arsCompanyId, $from, $to, $nights, $rate, $amount, $in['note'], $userId]);
            $arsAudit('booking_updated', 'Recorded extension ' . $from . ' to ' . $to . ' (' . $nights . ' nights'
                . ($amount !== null ? ', AED ' . number_format($amount, 2) : '') . ') on booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)), [
                'new_data' => ['extended_from' => $from, 'extended_to' => $to, 'nights' => $nights, 'rate_per_night' => $rate, 'amount' => $amount],
            ]);
            $sync = ars_sync_checkout_to_extension_log($conn, $booking, $arsCompanyId, $userId);
            echo json_encode([
                'success' => true,
                'nights' => $nights,
                'amount' => $amount,
                'check_out' => $sync['check_out'] ?? null,
                'warning' => $sync['warning'] ?? null,
            ]);
            break;
        }

        // Bill one logged period: raise an extension invoice for its own
        // rate x nights, so it joins the open balance and takes payment like
        // any other invoice. Deliberately per row and on demand — a mistyped
        // date must not post a journal on its own.
        case 'bill_extension_entry': {
            require_once __DIR__ . '/includes/ars_accounting.php';
            require_once __DIR__ . '/includes/ars_financial_adapter.php';
            ars_ensure_extension_log_table($conn);
            if (!ars_financial_adapter_enabled($conn, $arsCompanyId)) {
                echo json_encode(['success' => false, 'error' => 'Financial adapter is disabled — this extension cannot be invoiced.']);
                exit;
            }
            $entryId = (int)($_POST['entry_id'] ?? 0);
            $st = $conn->prepare("SELECT * FROM ars_booking_extension_log WHERE id = ? AND booking_id = ? AND company_id = ?");
            $st->execute([$entryId, $bookingId, $arsCompanyId]);
            $entry = $st->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                echo json_encode(['success' => false, 'error' => 'Entry not found on this booking.']);
                exit;
            }
            if (!empty($entry['document_id'])) {
                echo json_encode(['success' => false, 'error' => 'This period is already billed. Use a credit note to reverse it.']);
                exit;
            }
            $rate = round((float)($entry['rate_per_night'] ?? 0), 2);
            if ($rate <= 0) {
                echo json_encode(['success' => false, 'error' => 'Set a price per night on this period first.']);
                exit;
            }
            $r = $arsBillExtensionEntry($entry);
            if (empty($r['success'])) {
                echo json_encode(['success' => false, 'error' => $r['error'] ?? 'Extension invoice failed', 'code' => $r['code'] ?? null]);
                exit;
            }
            $arsAudit('booking_updated', 'Billed extension ' . $entry['extended_from'] . ' to ' . $entry['extended_to']
                . ' at AED ' . number_format($rate, 2) . '/night on booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)), [
                'new_data' => ['entry_id' => $entryId, 'document_id' => $r['document_id'] ?? null, 'rate_per_night' => $rate],
            ]);
            require_once __DIR__ . '/includes/ars_pricing.php';
            try {
                ars_recalc_booking_totals($conn, $bookingId);
            } catch (Throwable $ignored) {
            }
            $sync = ars_sync_checkout_to_extension_log($conn, $booking, $arsCompanyId, $userId);
            echo json_encode([
                'success' => true,
                'document_id' => $r['document_id'] ?? null,
                'document_number' => $r['document_number'] ?? null,
                'warning' => $sync['warning'] ?? null,
            ]);
            break;
        }

        case 'delete_extension_entry': {
            ars_ensure_extension_log_table($conn);
            $entryId = (int)($_POST['entry_id'] ?? 0);
            if ($entryId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Entry not found.']);
                exit;
            }
            $st = $conn->prepare("SELECT * FROM ars_booking_extension_log WHERE id = ? AND booking_id = ? AND company_id = ?");
            $st->execute([$entryId, $bookingId, $arsCompanyId]);
            $entry = $st->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                echo json_encode(['success' => false, 'error' => 'Entry not found on this booking.']);
                exit;
            }
            // A billed period has a live invoice behind it, so the record cannot
            // just vanish — its invoice is reversed in the same step, and the
            // row stays put if that reversal is refused.
            $billed = !empty($entry['document_id']);
            if ($billed) {
                ars_require_booking_action($conn, 'delete_extension_bill');
            }
            $period = $entry['extended_from'] . ' to ' . $entry['extended_to'];
            $conn->beginTransaction();
            try {
                if ($billed) {
                    $rev = $arsReverseExtensionBill((int)$entry['document_id'], 'Extension period ' . $period . ' removed');
                    if (empty($rev['success']) && empty($rev['gone'])) {
                        $conn->rollBack();
                        echo json_encode(['success' => false, 'error' => $rev['error'] ?? 'Could not reverse the invoice.']);
                        exit;
                    }
                }
                $conn->prepare("DELETE FROM ars_booking_extension_log WHERE id = ? AND booking_id = ? AND company_id = ?")
                    ->execute([$entryId, $bookingId, $arsCompanyId]);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
            $arsAudit('booking_updated', 'Removed extension entry #' . $entryId . ' (' . $period . ') from booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)), [
                'old_data' => $entry,
            ]);
            if ($billed) {
                try {
                    ars_recalc_booking_totals($conn, $bookingId);
                } catch (Throwable $ignored) {
                }
            }
            // Removing the last period ends the stay where that period began,
            // provided the log had moved the check-out past it.
            $originalOut = (string)$booking['check_out'] > (string)$entry['extended_from'] ? (string)$entry['extended_from'] : null;
            $sync = ars_sync_checkout_to_extension_log($conn, $booking, $arsCompanyId, $userId, $originalOut);
            echo json_encode([
                'success' => true,
                'check_out' => $sync['check_out'] ?? null,
                'warning' => $sync['warning'] ?? null,
            ]);
            break;
        }

        // Correct a logged period. A billed one has its invoice reversed and
        // raised again at the new figures in one transaction, so the period
        // is never left half-corrected; a new note alone leaves the invoice be.
        case 'update_extension_entry': {
            ars_ensure_extension_log_table($conn);
            $entryId = (int)($_POST['entry_id'] ?? 0);
            $st = $conn->prepare("SELECT * FROM ars_booking_extension_log WHERE id = ? AND booking_id = ? AND company_id = ?");
            $st->execute([$entryId, $bookingId, $arsCompanyId]);
            $entry = $st->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                echo json_encode(['success' => false, 'error' => 'Entry not found on this booking.']);
                exit;
            }
            $in = ars_extension_entry_from_post($_POST);
            if ($in['error'] !== null) {
                echo json_encode(['success' => false, 'error' => $in['error']]);
                exit;
            }
            $conn->beginTransaction();
            try {
                $edit = $arsApplyExtensionEdit($entry, $in);
                if (empty($edit['success'])) {
                    $conn->rollBack();
                    echo json_encode(['success' => false, 'error' => $edit['error'] ?? 'Could not save']);
                    exit;
                }
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
            $rebill = !empty($edit['rebill']);
            $newDoc = $edit['new_doc'] ?? null;
            $arsAudit('booking_updated', 'Edited extension ' . $in['from'] . ' to ' . $in['to'] . ' (' . $in['nights'] . ' nights'
                . ($in['amount'] !== null ? ', AED ' . number_format($in['amount'], 2) : '') . ') on booking ' . ($booking['booking_number'] ?? ('#' . $bookingId)), [
                'old_data' => $entry,
                'new_data' => ['extended_from' => $in['from'], 'extended_to' => $in['to'], 'nights' => $in['nights'],
                               'rate_per_night' => $in['rate'], 'amount' => $in['amount'], 'note' => $in['note'],
                               'document_id' => $newDoc['document_id'] ?? $entry['document_id']],
            ]);
            if ($rebill) {
                try {
                    ars_recalc_booking_totals($conn, $bookingId);
                } catch (Throwable $ignored) {
                }
            }
            $sync = ars_sync_checkout_to_extension_log($conn, $booking, $arsCompanyId, $userId);
            echo json_encode([
                'success' => true,
                'nights' => $in['nights'],
                'amount' => $in['amount'],
                'rebilled' => !empty($newDoc['success']),
                'document_number' => $newDoc['document_number'] ?? null,
                'check_out' => $sync['check_out'] ?? null,
                'warning' => $sync['warning'] ?? null,
            ]);
            break;
        }

        // Correct the original stay — the first line of the Extend tab's list,
        // which is the booking itself rather than a log entry.
        //
        // Dates: the check-in moves freely. With extensions logged the stay
        // ends where the first of them begins, so moving the end moves that
        // period's start with it (its amount stays, and a billed one is
        // re-invoiced for the new dates).
        //
        // Price: the original invoice is never rewritten. A higher figure
        // raises a rate-adjustment invoice for the difference; a lower one a
        // credit note, which comes off what the guest owes. On a stay that is
        // already paid it is tied to the original invoice, so the Pricing
        // Summary can show it as credit carried forward from that payment.
        case 'update_original_stay': {
            require_once __DIR__ . '/includes/ars_accounting.php';
            require_once __DIR__ . '/includes/ars_financial_adapter.php';
            require_once __DIR__ . '/includes/ars_financial_adapter_phase2d.php';
            ars_ensure_extension_log_table($conn);
            if (in_array($booking['status'], ['cancelled', 'expired'], true)) {
                echo json_encode(['success' => false, 'error' => 'This booking is ' . $booking['status'] . '. It cannot be changed.']);
                exit;
            }
            $in = ars_extension_entry_from_post($_POST);
            if ($in['error'] !== null) {
                echo json_encode(['success' => false, 'error' => $in['error']]);
                exit;
            }
            $oldIn = (string)$booking['check_in'];
            $oldOut = (string)$booking['check_out'];
            $st = $conn->prepare("
                SELECT * FROM ars_booking_extension_log
                WHERE booking_id = ? AND company_id = ?
                ORDER BY extended_from, id LIMIT 1
            ");
            $st->execute([$bookingId, $arsCompanyId]);
            $firstExt = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            $oldOriginalEnd = $firstExt ? (string)$firstExt['extended_from'] : $oldOut;
            $moveFirstExt = $firstExt && $in['to'] !== $oldOriginalEnd;
            if ($moveFirstExt && $in['to'] >= (string)$firstExt['extended_to']) {
                echo json_encode(['success' => false, 'error' => 'The original stay must end before the first extension does ('
                    . date('d M Y', strtotime((string)$firstExt['extended_to'])) . ').']);
                exit;
            }
            $newIn = $in['from'];
            $newOut = $firstExt ? $oldOut : $in['to'];

            // Only the nights being added need the unit to be free.
            $added = [];
            if ($newIn < $oldIn) {
                $added[] = [$newIn, $oldIn];
            }
            if ($newOut > $oldOut) {
                $added[] = [$oldOut, $newOut];
            }
            foreach ($added as [$spanFrom, $spanTo]) {
                $avail = ars_check_availability($conn, (int)$booking['unit_id'], $spanFrom, $spanTo, $bookingId);
                if (empty($avail['available'])) {
                    $labels = array_map(static fn($c) => $c['label'] ?? 'conflict', $avail['conflicts'] ?? []);
                    echo json_encode(['success' => false, 'error' => 'Unit not available: ' . implode(', ', $labels)]);
                    exit;
                }
            }

            // The price only moves when the stay has an invoice to correct and
            // a different figure was typed.
            $invoiced = ars_original_stay_invoiced($conn, $arsCompanyId, $booking);
            $oldAmount = $invoiced['amount'] ?? null;
            $newAmount = $oldAmount;
            if ($invoiced && $in['amount'] !== null && abs($in['amount'] - $oldAmount) > 0.004) {
                if ($in['amount'] <= 0) {
                    echo json_encode(['success' => false, 'error' => 'The original stay needs a price. Void the booking instead if nothing is to be charged.']);
                    exit;
                }
                if (!ars_financial_adapter_enabled($conn, $arsCompanyId)) {
                    echo json_encode(['success' => false, 'error' => 'Financial adapter is disabled — the price cannot be corrected.']);
                    exit;
                }
                $newAmount = $in['amount'];
            }
            $diff = $invoiced ? round($newAmount - $oldAmount, 2) : 0.0;

            $newNights = (int)round((strtotime($newOut) - strtotime($newIn)) / 86400);
            $oldOriginalNights = (int)round((strtotime($oldOriginalEnd) - strtotime($oldIn)) / 86400);
            $newOriginalNights = $in['nights'];
            // The nightly rate shown for the stay is its figure spread over its nights.
            $newRate = (float)$booking['nightly_rate'];
            if ($invoiced) {
                $newRate = round($newAmount / $newOriginalNights, 2);
            } elseif ((string)($booking['pricing_mode'] ?? '') === 'manual_total' && $oldOriginalNights > 0) {
                $agreedTotal = (float)($booking['entered_amount'] ?? 0);
                $newRate = round(($agreedTotal > 0 ? $agreedTotal : $newRate * $oldOriginalNights) / $newOriginalNights, 2);
            }
            $datesChanged = $newIn !== $oldIn || $newOut !== $oldOut || $moveFirstExt;
            if (!$datesChanged && abs($diff) < 0.005) {
                echo json_encode(['success' => true, 'check_in' => $oldIn, 'check_out' => $oldOut, 'changed' => false]);
                break;
            }

            $moneyDoc = null;
            $conn->beginTransaction();
            try {
                $fail = null;
                if ($moveFirstExt) {
                    $extNights = (int)round((strtotime((string)$firstExt['extended_to']) - strtotime($in['to'])) / 86400);
                    $extAmount = $firstExt['amount'] === null ? null : (float)$firstExt['amount'];
                    $edit = $arsApplyExtensionEdit($firstExt, [
                        'from' => $in['to'],
                        'to' => (string)$firstExt['extended_to'],
                        'nights' => $extNights,
                        'rate' => $extAmount !== null && $extAmount > 0
                            ? round($extAmount / $extNights, 2)
                            : ($firstExt['rate_per_night'] === null ? null : (float)$firstExt['rate_per_night']),
                        'amount' => $extAmount,
                        'note' => $firstExt['note'],
                    ]);
                    if (empty($edit['success'])) {
                        $fail = 'The first extension starts where this stay ends, and it could not be moved: ' . ($edit['error'] ?? 'unknown error');
                    }
                }
                if ($fail === null && $diff > 0.004) {
                    $desc = 'Original stay price corrected to AED ' . number_format($newAmount, 2);
                    $moneyDoc = ars_adapter_create_adjustment_invoice($conn, $booking, [
                        'user_id' => $userId,
                        'amount_net' => $diff,
                        'description' => $desc,
                        'account_role' => 'ROOM_REVENUE',
                        'parent_document_id' => (int)$invoiced['document']['id'],
                        'idempotency_key' => 'adj:' . $bookingId . ':orig:' . md5($newAmount . ':' . microtime(true)),
                    ]);
                    if (empty($moneyDoc['success'])) {
                        $fail = 'Nothing was changed. ' . ($moneyDoc['error'] ?? 'Adjustment invoice failed');
                    } else {
                        // Same charge row the Rate adjustment tab writes, so the
                        // booking's own total follows the document.
                        $split = ars_adapter_vat_split($diff, $booking);
                        $conn->prepare("
                            INSERT INTO ars_booking_charges
                                (booking_id, charge_type, description, quantity, unit_price, total, charge_date, financial_document_id)
                            VALUES (?, 'other', ?, 1, ?, ?, CURDATE(), ?)
                        ")->execute([$bookingId, $desc, $split['net'], $split['net'], $moneyDoc['document_id'] ?? null]);
                    }
                } elseif ($fail === null && $diff < -0.004) {
                    $credit = round(-$diff, 2);
                    $split = ars_adapter_vat_split($credit, $booking);
                    // A plain AR credit note either way: the balance rollup
                    // nets its own balance against the open invoices, so it
                    // must not also become guest credit. The parent is only
                    // named once it is fully paid — on an invoice still owed
                    // the adapter would write the parent down as well, and
                    // the credit would count twice.
                    $parentPaid = (float)$invoiced['document']['balance_due'] < 0.01;
                    $moneyDoc = ars_adapter_create_credit_note($conn, $booking, [
                        'user_id' => $userId,
                        'amount_net' => $credit,
                        'description' => 'Original stay price corrected to AED ' . number_format($newAmount, 2),
                        'to_guest_credit' => false,
                        'parent_document_id' => $parentPaid ? (int)$invoiced['document']['id'] : null,
                        'idempotency_key' => 'cn:' . $bookingId . ':orig:' . md5($newAmount . ':' . microtime(true)),
                    ]);
                    if (empty($moneyDoc['success'])) {
                        $fail = 'Nothing was changed. ' . ($moneyDoc['error'] ?? 'Credit note failed');
                    } else {
                        // The mirror of the adjustment's charge row, so the
                        // booking's own total comes down with the credit note.
                        $conn->prepare("
                            INSERT INTO ars_booking_charges
                                (booking_id, charge_type, description, quantity, unit_price, total, charge_date, financial_document_id)
                            VALUES (?, 'other', ?, 1, ?, ?, CURDATE(), ?)
                        ")->execute([$bookingId, 'Original stay price corrected to AED ' . number_format($newAmount, 2),
                                     -$split['net'], -$split['net'], $moneyDoc['document_id'] ?? null]);
                    }
                }
                if ($fail !== null) {
                    $conn->rollBack();
                    echo json_encode(['success' => false, 'error' => $fail]);
                    exit;
                }
                $conn->prepare("
                    UPDATE ars_bookings
                    SET check_in = ?, check_out = ?, nights = ?, nightly_rate = ?,
                        rate_override = IF(rate_override IS NULL, NULL, ?), updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ")->execute([$newIn, $newOut, $newNights, $newRate, $newRate, $bookingId, $arsCompanyId]);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
            try {
                ars_recalc_booking_totals($conn, $bookingId);
            } catch (Throwable $ignored) {
            }
            $summary = 'Original stay corrected to ' . $newIn . ' – ' . $in['to'] . ' (' . $newOriginalNights . ' nights'
                . ($invoiced ? ', AED ' . number_format($newAmount, 2) : '') . ') on booking ' . ($booking['booking_number'] ?? ('#' . $bookingId));
            $arsAudit('booking_updated', $summary, [
                'old_data' => ['check_in' => $oldIn, 'original_end' => $oldOriginalEnd, 'check_out' => $oldOut,
                               'nightly_rate' => $booking['nightly_rate'], 'amount' => $oldAmount],
                'new_data' => ['check_in' => $newIn, 'original_end' => $in['to'], 'check_out' => $newOut,
                               'nightly_rate' => $newRate, 'amount' => $newAmount, 'document_id' => $moneyDoc['document_id'] ?? null],
            ]);
            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'booking',
                'event_type' => 'dates_changed',
                'title' => 'Original stay corrected',
                'old_value' => $oldIn . ' to ' . $oldOriginalEnd . ($oldAmount !== null ? ' · AED ' . number_format($oldAmount, 2, '.', '') : ''),
                'new_value' => $newIn . ' to ' . $in['to'] . ($invoiced ? ' · AED ' . number_format($newAmount, 2, '.', '') : ''),
                'related_entity_type' => $moneyDoc ? 'ars_financial_document' : null,
                'related_entity_id' => $moneyDoc['document_id'] ?? null,
                'related_journal_id' => $moneyDoc['journal_id'] ?? null,
                'created_by' => $userId,
            ]);
            echo json_encode([
                'success' => true,
                'changed' => true,
                'check_in' => $newIn,
                'check_out' => $newOut,
                'nights' => $newNights,
                'amount' => $newAmount,
                'difference' => $diff,
                'document_number' => $moneyDoc['document_number'] ?? null,
            ]);
            break;
        }

        // Delete an extension charge from the Payments table: reverses the
        // extension invoice's journal (audit trail kept) and puts the period
        // back to "not billed" on the Extend tab. Only while nothing has been
        // received against it — a paid one has its payment deleted first.
        case 'delete_extension_bill': {
            ars_ensure_extension_log_table($conn);
            $documentId = (int)($_POST['document_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($documentId <= 0 || $reason === '') {
                echo json_encode(['success' => false, 'error' => 'Extension charge and reason are required.']);
                exit;
            }
            $rev = $arsReverseExtensionBill($documentId, $reason);
            if (empty($rev['success'])) {
                echo json_encode(['success' => false, 'error' => $rev['error'] ?? 'Reverse failed']);
                exit;
            }
            try {
                ars_recalc_booking_totals($conn, $bookingId);
            } catch (Throwable $ignored) {
            }
            echo json_encode(['success' => true]);
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
