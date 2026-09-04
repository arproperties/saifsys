<?php
/**
 * ARS Guest in-app notifications.
 */

declare(strict_types=1);

function ars_guest_notifications_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_guest_notifications (
            id INT(11) NOT NULL AUTO_INCREMENT,
            company_id INT(11) NOT NULL,
            guest_id INT(11) NOT NULL,
            booking_id INT(11) DEFAULT NULL,
            payment_id INT(11) DEFAULT NULL,
            event_type ENUM(
                'booking_created',
                'booking_confirmed',
                'payment_succeeded',
                'payment_failed',
                'booking_cancelled',
                'booking_expired',
                'document_available',
                'receipt_available',
                'reminder_checkin',
                'reminder_checkout',
                'request_approved',
                'request_rejected',
                'request_cancelled',
                'deposit_required',
                'deposit_received',
                'deposit_refunded',
                'deposit_cash_selected',
                'manual_general',
                'manual_booking',
                'manual_payment',
                'manual_lifecycle_request',
                'manual_document',
                'manual_receipt',
                'manual_checkin',
                'manual_checkout'
            ) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT DEFAULT NULL,
            cta_route VARCHAR(255) DEFAULT NULL,
            meta_json JSON DEFAULT NULL,
            is_placeholder TINYINT(1) NOT NULL DEFAULT 0,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME DEFAULT NULL,
            scheduled_for DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ars_guest_notif_guest_created (company_id, guest_id, created_at),
            KEY idx_ars_guest_notif_guest_unread (company_id, guest_id, is_read),
            KEY idx_ars_guest_notif_booking_event (booking_id, event_type),
            KEY idx_ars_guest_notif_schedule (is_placeholder, scheduled_for)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $conn->exec("
            ALTER TABLE ars_guest_notifications
            MODIFY event_type ENUM(
                'booking_created',
                'booking_confirmed',
                'payment_succeeded',
                'payment_failed',
                'booking_cancelled',
                'booking_expired',
                'document_available',
                'receipt_available',
                'reminder_checkin',
                'reminder_checkout',
                'request_approved',
                'request_rejected',
                'request_cancelled',
                'deposit_required',
                'deposit_received',
                'deposit_refunded',
                'deposit_cash_selected',
                'manual_general',
                'manual_booking',
                'manual_payment',
                'manual_lifecycle_request',
                'manual_document',
                'manual_receipt',
                'manual_checkin',
                'manual_checkout'
            ) NOT NULL
        ");
    } catch (Throwable $e) {
        error_log('ARS guest notifications enum migration failed: ' . $e->getMessage());
    }
}

/**
 * @param array<string,mixed> $payload
 */
function ars_guest_notification_create(PDO $conn, array $payload): int {
    ars_guest_notifications_ensure_schema($conn);
    $stmt = $conn->prepare("
        INSERT INTO ars_guest_notifications
            (company_id, guest_id, booking_id, payment_id, event_type, title, message, cta_route, meta_json, is_placeholder, scheduled_for)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $meta = $payload['meta'] ?? null;
    $metaJson = null;
    if (is_array($meta)) {
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
    }
    $stmt->execute([
        (int)($payload['company_id'] ?? 0),
        (int)($payload['guest_id'] ?? 0),
        isset($payload['booking_id']) ? (int)$payload['booking_id'] : null,
        isset($payload['payment_id']) ? (int)$payload['payment_id'] : null,
        (string)($payload['event_type'] ?? 'booking_created'),
        (string)($payload['title'] ?? ''),
        isset($payload['message']) ? (string)$payload['message'] : null,
        isset($payload['cta_route']) ? (string)$payload['cta_route'] : null,
        $metaJson,
        !empty($payload['is_placeholder']) ? 1 : 0,
        isset($payload['scheduled_for']) ? (string)$payload['scheduled_for'] : null,
    ]);

    $notificationId = (int)$conn->lastInsertId();
    $scheduledFor = isset($payload['scheduled_for']) ? strtotime((string)$payload['scheduled_for']) : false;
    $shouldPush = $notificationId > 0
        && empty($payload['skip_push'])
        && empty($payload['is_placeholder'])
        && ($scheduledFor === false || $scheduledFor <= time());

    if ($shouldPush) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/customer_push_notifications.php';
            if (function_exists('customer_push_send_guest_auto_notification')) {
                customer_push_send_guest_auto_notification($conn, $payload, $notificationId);
            }
        } catch (Throwable $e) {
            error_log('ARS guest notification push failed: ' . $e->getMessage());
        }
    }

    return $notificationId;
}

/**
 * Create deduped check-in/check-out reminder placeholders for a booking.
 *
 * @param array<string,mixed> $booking
 */
function ars_guest_notifications_schedule_booking_reminders(PDO $conn, array $booking): int {
    ars_guest_notifications_ensure_schema($conn);
    $companyId = (int)($booking['company_id'] ?? 0);
    $guestId = (int)($booking['guest_id'] ?? 0);
    $bookingId = (int)($booking['id'] ?? 0);
    if ($companyId <= 0 || $guestId <= 0 || $bookingId <= 0) {
        return 0;
    }

    $bookingNumber = (string)($booking['booking_number'] ?? ('#' . $bookingId));
    $items = [];
    if (!empty($booking['check_in'])) {
        $ts = strtotime((string)$booking['check_in'] . ' 09:00:00 -1 day');
        if ($ts !== false) {
            $items[] = [
                'event_type' => 'reminder_checkin',
                'title' => 'Check-in reminder',
                'message' => 'Your stay ' . $bookingNumber . ' starts tomorrow.',
                'scheduled_for' => date('Y-m-d H:i:s', $ts),
            ];
        }
    }
    if (!empty($booking['check_out'])) {
        $ts = strtotime((string)$booking['check_out'] . ' 18:00:00 -1 day');
        if ($ts !== false) {
            $items[] = [
                'event_type' => 'reminder_checkout',
                'title' => 'Check-out reminder',
                'message' => 'Your stay ' . $bookingNumber . ' checks out tomorrow.',
                'scheduled_for' => date('Y-m-d H:i:s', $ts),
            ];
        }
    }

    $created = 0;
    $exists = $conn->prepare("
        SELECT id
        FROM ars_guest_notifications
        WHERE company_id = ? AND guest_id = ? AND booking_id = ? AND event_type = ?
          AND is_placeholder = 1
        LIMIT 1
    ");
    foreach ($items as $item) {
        $exists->execute([$companyId, $guestId, $bookingId, $item['event_type']]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $id = ars_guest_notification_create($conn, [
            'company_id' => $companyId,
            'guest_id' => $guestId,
            'booking_id' => $bookingId,
            'event_type' => $item['event_type'],
            'title' => $item['title'],
            'message' => $item['message'],
            'cta_route' => '/guest/bookings/' . $bookingId,
            'is_placeholder' => true,
            'scheduled_for' => $item['scheduled_for'],
            'skip_push' => true,
        ]);
        if ($id > 0) $created++;
    }
    return $created;
}

/**
 * @return list<array<string,mixed>>
 */
function ars_guest_notifications_list(
    PDO $conn,
    int $companyId,
    int $guestId,
    bool $unreadOnly = false,
    int $limit = 50
): array {
    ars_guest_notifications_ensure_schema($conn);
    $limit = max(1, min(200, $limit));
    $sql = "
        SELECT id, booking_id, payment_id, event_type, title, message, cta_route,
               is_placeholder, is_read, read_at, scheduled_for, created_at
        FROM ars_guest_notifications
        WHERE company_id = ? AND guest_id = ?
    ";
    $params = [$companyId, $guestId];
    if ($unreadOnly) {
        $sql .= " AND is_read = 0";
    }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT {$limit}";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ars_guest_notifications_unread_count(PDO $conn, int $companyId, int $guestId): int {
    ars_guest_notifications_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM ars_guest_notifications
        WHERE company_id = ? AND guest_id = ? AND is_read = 0
    ");
    $stmt->execute([$companyId, $guestId]);
    return (int)$stmt->fetchColumn();
}

function ars_guest_notifications_mark_one_read(PDO $conn, int $companyId, int $guestId, int $notificationId): bool {
    ars_guest_notifications_ensure_schema($conn);
    $stmt = $conn->prepare("
        UPDATE ars_guest_notifications
        SET is_read = 1, read_at = COALESCE(read_at, NOW()), updated_at = NOW()
        WHERE id = ? AND company_id = ? AND guest_id = ?
        LIMIT 1
    ");
    $stmt->execute([$notificationId, $companyId, $guestId]);
    return $stmt->rowCount() > 0;
}

function ars_guest_notifications_mark_all_read(PDO $conn, int $companyId, int $guestId): int {
    ars_guest_notifications_ensure_schema($conn);
    $stmt = $conn->prepare("
        UPDATE ars_guest_notifications
        SET is_read = 1, read_at = COALESCE(read_at, NOW()), updated_at = NOW()
        WHERE company_id = ? AND guest_id = ? AND is_read = 0
    ");
    $stmt->execute([$companyId, $guestId]);
    return $stmt->rowCount();
}

