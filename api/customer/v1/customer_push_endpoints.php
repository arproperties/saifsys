<?php
/**
 * Unified customer push token endpoints.
 *
 * No public endpoints: tenant routes require tenant Bearer auth and guest routes
 * require guest Bearer auth. Identity is always resolved server-side.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/customer_push_notifications.php';

/**
 * @return array<string,mixed>
 */
function customer_api_push_device_body(): array {
    $body = customer_api_read_json_body();
    $token = trim((string)($body['fcm_token'] ?? ''));
    if ($token === '' || strlen($token) < 20) {
        customer_api_send_error('validation_error', 'Valid fcm_token is required', 422);
    }
    $deviceId = trim((string)($body['device_id'] ?? ''));
    if ($deviceId === '') {
        $deviceId = substr(hash('sha256', $token), 0, 32);
    }
    return [
        'fcm_token' => $token,
        'platform' => $body['platform'] ?? 'unknown',
        'device_id' => $deviceId,
        'app_version' => $body['app_version'] ?? null,
        'device_name' => $body['device_name'] ?? null,
        'lease_id' => isset($body['lease_id']) ? (int)$body['lease_id'] : null,
        'booking_id' => isset($body['booking_id']) ? (int)$body['booking_id'] : null,
    ];
}

function customer_api_push_handle_tenant_token_register(PDO $conn): void {
    $ctx = customer_api_tenant_require_access($conn);
    $device = customer_api_push_device_body();

    $leaseId = customer_api_tenant_lease_header_value();
    if ($leaseId === null && !empty($device['lease_id'])) {
        $leaseId = (int)$device['lease_id'];
    }
    if ($leaseId !== null && $leaseId > 0) {
        $allowed = customer_api_tenant_allowed_lease_ids($conn, $ctx);
        if (!in_array($leaseId, $allowed, true)) {
            customer_api_send_error('forbidden', 'Lease is not linked to this tenant account', 403);
        }
    } else {
        $leaseId = null;
    }

    $identity = [
        'company_id' => (int)$ctx['company_id'],
        'tenant_portal_user_id' => $ctx['tenant_portal_user_id'] !== null ? (int)$ctx['tenant_portal_user_id'] : null,
        'tenant_id' => (int)$ctx['tenant_id'],
        'lease_id' => $leaseId,
    ];
    $result = customer_push_upsert_token($conn, 'tenant', $identity, $device);
    if (empty($result['ok'])) {
        customer_api_send_error('token_register_failed', (string)($result['error'] ?? 'Could not register device token'), 422);
    }
    customer_api_send_ok(['device_token_id' => (int)$result['id']]);
}

function customer_api_push_handle_tenant_token_delete(PDO $conn): void {
    $ctx = customer_api_tenant_require_access($conn);
    $body = customer_api_read_json_body();
    $identity = [
        'company_id' => (int)$ctx['company_id'],
        'tenant_portal_user_id' => $ctx['tenant_portal_user_id'] !== null ? (int)$ctx['tenant_portal_user_id'] : null,
        'tenant_id' => (int)$ctx['tenant_id'],
    ];
    $updated = customer_push_deactivate_token(
        $conn,
        'tenant',
        $identity,
        isset($body['fcm_token']) ? (string)$body['fcm_token'] : null,
        isset($body['device_id']) ? (string)$body['device_id'] : null
    );
    customer_api_send_ok(['updated' => $updated]);
}

function customer_api_push_guest_booking_allowed(PDO $conn, array $ctx, int $bookingId): ?int {
    if ($bookingId <= 0) return null;
    $stmt = $conn->prepare("
        SELECT id
        FROM ars_bookings
        WHERE id = ? AND guest_id = ? AND company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId, (int)$ctx['guest_id'], (int)$ctx['company_id']]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id <= 0) {
        customer_api_send_error('forbidden', 'Booking is not linked to this guest account', 403);
    }
    return $id;
}

function customer_api_push_handle_guest_token_register(PDO $conn): void {
    $ctx = customer_api_guest_require_access($conn);
    $device = customer_api_push_device_body();
    $bookingId = !empty($device['booking_id'])
        ? customer_api_push_guest_booking_allowed($conn, $ctx, (int)$device['booking_id'])
        : null;

    $identity = [
        'company_id' => (int)$ctx['company_id'],
        'guest_portal_user_id' => (int)$ctx['portal_user_id'],
        'guest_id' => (int)$ctx['guest_id'],
        'booking_id' => $bookingId,
    ];
    $result = customer_push_upsert_token($conn, 'guest', $identity, $device);
    if (empty($result['ok'])) {
        customer_api_send_error('token_register_failed', (string)($result['error'] ?? 'Could not register device token'), 422);
    }
    customer_api_send_ok(['device_token_id' => (int)$result['id']]);
}

function customer_api_push_handle_guest_token_delete(PDO $conn): void {
    $ctx = customer_api_guest_require_access($conn);
    $body = customer_api_read_json_body();
    $identity = [
        'company_id' => (int)$ctx['company_id'],
        'guest_portal_user_id' => (int)$ctx['portal_user_id'],
        'guest_id' => (int)$ctx['guest_id'],
    ];
    $updated = customer_push_deactivate_token(
        $conn,
        'guest',
        $identity,
        isset($body['fcm_token']) ? (string)$body['fcm_token'] : null,
        isset($body['device_id']) ? (string)$body['device_id'] : null
    );
    customer_api_send_ok(['updated' => $updated]);
}
