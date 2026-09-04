<?php
/**
 * Phase C — guest auth + bookings. Requires includes/customer_api.php, ARS helpers, ars_* loaded by index.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/mailer.php';
require_once dirname(__DIR__, 3) . '/modules/ars/includes/ars_guest_notifications.php';
require_once dirname(__DIR__, 3) . '/modules/ars/includes/ars_booking_requests.php';

/**
 * @return array<string,mixed>
 */
function customer_api_guest_require_access(PDO $conn): array {
    $token = customer_api_get_bearer();
    if ($token === null || $token === '') {
        customer_api_send_error('unauthorized', 'Authorization Bearer token required', 401);
    }
    $pl = customer_api_jwt_verify($token);
    if (!$pl || ($pl['typ'] ?? '') !== 'guest') {
        customer_api_send_error('invalid_token', 'Invalid or expired access token', 401);
    }
    if (!preg_match('/^pu:(\d+)$/', (string)($pl['sub'] ?? ''), $m)) {
        customer_api_send_error('invalid_token', 'Invalid access token subject', 401);
    }
    $puid = (int)$m[1];
    $stmt = $conn->prepare("
        SELECT pu.id AS portal_user_id, pu.guest_id, pu.company_id, pu.email, pu.status,
               ag.first_name, ag.last_name, ag.phone, ag.nationality
        FROM portal_users pu
        INNER JOIN ars_guests ag ON ag.id = pu.guest_id
        WHERE pu.id = ? AND pu.user_type = 'guest'
        LIMIT 1
    ");
    $stmt->execute([$puid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($row['status'] ?? '') !== 'active') {
        customer_api_send_error('unauthorized', 'Account not found or inactive', 401);
    }
    $gid = (int)$row['guest_id'];
    $jwtGid = (int)($pl['gid'] ?? 0);
    if ($jwtGid !== 0 && $jwtGid !== $gid) {
        customer_api_send_error('invalid_token', 'Token guest mismatch', 401);
    }
    return $row;
}

/**
 * @return array{access_token:string,refresh_token:string,expires_in:int,token_type:string,guest:array<string,mixed>}
 */
function customer_api_guest_issue_auth_response(PDO $conn, int $portalUserId, int $guestId, int $companyId): array {
    $pair = customer_api_issue_token_pair([
        'typ' => 'guest',
        'sub' => 'pu:' . $portalUserId,
        'cid' => $companyId,
        'gid' => $guestId,
    ]);
    $gStmt = $conn->prepare('SELECT id, first_name, last_name, email FROM ars_guests WHERE id = ? LIMIT 1');
    $gStmt->execute([$guestId]);
    $g = $gStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return array_merge([
        'token_type' => 'Bearer',
    ], $pair, [
        'guest' => [
            'id' => (int)($g['id'] ?? $guestId),
            'first_name' => (string)($g['first_name'] ?? ''),
            'last_name' => (string)($g['last_name'] ?? ''),
            'email' => (string)($g['email'] ?? ''),
        ],
    ]);
}

function customer_api_guest_handle_register(PDO $conn): void {
    $body = customer_api_read_json_body();
    $first = trim((string)($body['first_name'] ?? ''));
    $last = trim((string)($body['last_name'] ?? ''));
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $phone = trim((string)($body['phone'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($first === '' || $last === '' || $email === '' || $password === '') {
        customer_api_send_error('validation_error', 'first_name, last_name, email, and password are required', 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        customer_api_send_error('validation_error', 'Invalid email address', 400);
    }
    if (strlen($password) < 6) {
        customer_api_send_error('validation_error', 'Password must be at least 6 characters', 400);
    }

    $arsCompanyId = getArsCompanyId($conn);
    $dup = $conn->prepare('SELECT id FROM portal_users WHERE email = ? LIMIT 1');
    $dup->execute([$email]);
    if ($dup->fetchColumn()) {
        customer_api_send_error('guest_email_taken', 'An account with this email already exists', 409);
    }

    try {
        $conn->beginTransaction();
        $conn->prepare(
            'INSERT INTO ars_guests (company_id, first_name, last_name, email, phone, is_active) VALUES (?,?,?,?,?,1)'
        )->execute([$arsCompanyId, $first, $last, $email, $phone !== '' ? $phone : null]);
        $guestId = (int)$conn->lastInsertId();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $conn->prepare(
            "INSERT INTO portal_users (company_id, user_type, guest_id, email, password_hash, display_name, phone, status, email_verified_at) VALUES (?,'guest',?,?,?,?,?,'active',NOW())"
        )->execute([
            $arsCompanyId,
            $guestId,
            $email,
            $hash,
            $first . ' ' . $last,
            $phone !== '' ? $phone : null,
        ]);
        $portalUserId = (int)$conn->lastInsertId();

        $conn->prepare('UPDATE ars_guests SET portal_user_id = ? WHERE id = ?')->execute([$portalUserId, $guestId]);
        $conn->commit();
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
            customer_api_send_error('guest_email_taken', 'An account with this email already exists', 409);
        }
        customer_api_send_error('server_error', 'Registration failed', 500);
    }

    customer_api_send_ok(customer_api_guest_issue_auth_response($conn, $portalUserId, $guestId, $arsCompanyId));
}

function customer_api_guest_handle_login(PDO $conn): void {
    $body = customer_api_read_json_body();
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $password = (string)($body['password'] ?? '');

    if ($email === '' || $password === '') {
        customer_api_send_error('validation_error', 'email and password are required', 400);
    }

    $s = $conn->prepare("
        SELECT pu.*, ag.first_name, ag.last_name, ag.email AS guest_email
        FROM portal_users pu
        LEFT JOIN ars_guests ag ON ag.id = pu.guest_id
        WHERE pu.email = ? AND pu.user_type = 'guest' AND pu.status = 'active'
        LIMIT 1
    ");
    $s->execute([$email]);
    $pu = $s->fetch(PDO::FETCH_ASSOC);
    if (!$pu || !password_verify($password, (string)($pu['password_hash'] ?? ''))) {
        customer_api_send_error('invalid_credentials', 'Invalid email or password', 401);
    }

    $guestId = (int)($pu['guest_id'] ?? 0);
    $portalUserId = (int)$pu['id'];
    $companyId = (int)($pu['company_id'] ?? 0);
    if ($guestId <= 0) {
        customer_api_send_error('invalid_credentials', 'Invalid email or password', 401);
    }

    customer_api_send_ok(customer_api_guest_issue_auth_response($conn, $portalUserId, $guestId, $companyId));
}

/**
 * @return array<string,mixed>
 */
function customer_api_guest_me_data(PDO $conn, array $ctx): array {
    $portalUserId = (int)$ctx['portal_user_id'];
    $guestId = (int)$ctx['guest_id'];
    $first = (string)($ctx['first_name'] ?? '');
    $last = (string)($ctx['last_name'] ?? '');

    return [
        'portal_user_id' => $portalUserId,
        'guest_id' => $guestId,
        'email' => (string)($ctx['email'] ?? ''),
        'display_name' => trim($first . ' ' . $last),
        'phone' => (string)($ctx['phone'] ?? ''),
        'nationality' => $ctx['nationality'] !== null && $ctx['nationality'] !== ''
            ? (string)$ctx['nationality']
            : null,
    ];
}

function customer_api_datetime_utc_iso(?string $mysqlDatetime): ?string {
    if ($mysqlDatetime === null || $mysqlDatetime === '') {
        return null;
    }
    $ts = strtotime($mysqlDatetime);
    if ($ts === false) {
        return null;
    }
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function customer_api_guest_notification_row(array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'booking_id' => isset($row['booking_id']) && $row['booking_id'] !== null ? (int)$row['booking_id'] : null,
        'payment_id' => isset($row['payment_id']) && $row['payment_id'] !== null ? (int)$row['payment_id'] : null,
        'event_type' => (string)($row['event_type'] ?? ''),
        'title' => (string)($row['title'] ?? ''),
        'message' => $row['message'] !== null ? (string)$row['message'] : null,
        'cta_route' => $row['cta_route'] !== null ? (string)$row['cta_route'] : null,
        'is_placeholder' => !empty($row['is_placeholder']),
        'is_read' => !empty($row['is_read']),
        'read_at' => customer_api_datetime_utc_iso($row['read_at'] ?? null),
        'scheduled_for' => customer_api_datetime_utc_iso($row['scheduled_for'] ?? null),
        'created_at' => customer_api_datetime_utc_iso($row['created_at'] ?? null),
    ];
}

/**
 * @param array<string,mixed> $ctx
 */
function customer_api_guest_handle_notifications_list(PDO $conn, array $ctx): void {
    ars_guest_notifications_ensure_schema($conn);
    $companyId = (int)($ctx['company_id'] ?? 0);
    $guestId = (int)($ctx['guest_id'] ?? 0);
    $unreadOnly = in_array(strtolower((string)($_GET['unread_only'] ?? '0')), ['1', 'true', 'yes'], true);
    $limit = (int)($_GET['limit'] ?? 50);
    $rows = ars_guest_notifications_list($conn, $companyId, $guestId, $unreadOnly, $limit);
    $items = array_map('customer_api_guest_notification_row', $rows);
    customer_api_send_ok(['notifications' => $items]);
}

/**
 * @param array<string,mixed> $ctx
 */
function customer_api_guest_handle_notifications_unread_count(PDO $conn, array $ctx): void {
    ars_guest_notifications_ensure_schema($conn);
    $companyId = (int)($ctx['company_id'] ?? 0);
    $guestId = (int)($ctx['guest_id'] ?? 0);
    $count = ars_guest_notifications_unread_count($conn, $companyId, $guestId);
    customer_api_send_ok(['unread_count' => $count]);
}

/**
 * @param array<string,mixed> $ctx
 */
function customer_api_guest_handle_notifications_mark_one_read(PDO $conn, array $ctx, int $notificationId): void {
    ars_guest_notifications_ensure_schema($conn);
    if ($notificationId <= 0) {
        customer_api_send_error('validation_error', 'Invalid notification id', 422);
    }
    $companyId = (int)($ctx['company_id'] ?? 0);
    $guestId = (int)($ctx['guest_id'] ?? 0);
    $ok = ars_guest_notifications_mark_one_read($conn, $companyId, $guestId, $notificationId);
    if (!$ok) {
        customer_api_send_error('not_found', 'Notification not found', 404);
    }
    customer_api_send_ok(['marked' => true]);
}

/**
 * @param array<string,mixed> $ctx
 */
function customer_api_guest_handle_notifications_mark_all_read(PDO $conn, array $ctx): void {
    ars_guest_notifications_ensure_schema($conn);
    $companyId = (int)($ctx['company_id'] ?? 0);
    $guestId = (int)($ctx['guest_id'] ?? 0);
    $updated = ars_guest_notifications_mark_all_read($conn, $companyId, $guestId);
    customer_api_send_ok(['updated' => $updated]);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function customer_api_guest_booking_request_row(array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'booking_id' => (int)($row['booking_id'] ?? 0),
        'guest_id' => (int)($row['guest_id'] ?? 0),
        'request_type' => (string)($row['request_type'] ?? ''),
        'status' => (string)($row['status'] ?? 'pending'),
        'guest_note' => $row['guest_note'] !== null ? (string)$row['guest_note'] : null,
        'admin_note' => $row['admin_note'] !== null ? (string)$row['admin_note'] : null,
        'requested_check_in' => $row['requested_check_in'] !== null ? (string)$row['requested_check_in'] : null,
        'requested_check_out' => $row['requested_check_out'] !== null ? (string)$row['requested_check_out'] : null,
        'requested_time' => $row['requested_time'] !== null ? (string)$row['requested_time'] : null,
        'requested_nights' => $row['requested_nights'] !== null ? (int)$row['requested_nights'] : null,
        'contact_subject' => $row['contact_subject'] !== null ? (string)$row['contact_subject'] : null,
        'reviewed_by_name' => $row['reviewed_by_name'] !== null ? (string)$row['reviewed_by_name'] : null,
        'reviewed_at' => customer_api_datetime_utc_iso($row['reviewed_at'] ?? null),
        'cancelled_at' => customer_api_datetime_utc_iso($row['cancelled_at'] ?? null),
        'created_at' => customer_api_datetime_utc_iso($row['created_at'] ?? null),
        'updated_at' => customer_api_datetime_utc_iso($row['updated_at'] ?? null),
    ];
}

/**
 * @param array<string,mixed> $ctx
 */
function customer_api_guest_handle_booking_requests_list(PDO $conn, array $ctx, int $bookingId): void {
    $guestId = (int)($ctx['guest_id'] ?? 0);
    $booking = customer_api_guest_booking_for_payment($conn, $guestId, $bookingId);
    $rows = ars_booking_requests_for_booking($conn, (int)$booking['company_id'], (int)$booking['id']);
    $items = array_map('customer_api_guest_booking_request_row', $rows);
    customer_api_send_ok(['requests' => $items]);
}

/**
 * @param array<string,mixed> $ctx
 */
function customer_api_guest_handle_booking_request_create(PDO $conn, array $ctx, int $bookingId): void {
    $guestId = (int)($ctx['guest_id'] ?? 0);
    $booking = customer_api_guest_booking_for_payment($conn, $guestId, $bookingId);
    if (in_array((string)$booking['status'], ['cancelled', 'expired', 'completed'], true)) {
        customer_api_send_error('booking_not_editable', 'This booking can no longer accept lifecycle requests.', 422);
    }
    $body = customer_api_read_json_body();
    try {
        $request = ars_booking_request_create($conn, $booking, $guestId, is_array($body) ? $body : []);
    } catch (InvalidArgumentException $e) {
        customer_api_send_error('validation_error', $e->getMessage(), 422);
    }
    customer_api_send_ok([
        'request' => customer_api_guest_booking_request_row($request),
    ], 201);
}

/**
 * @param array<string,mixed> $ctx
 */
function customer_api_guest_handle_booking_request_cancel(PDO $conn, array $ctx, int $bookingId, int $requestId): void {
    $guestId = (int)($ctx['guest_id'] ?? 0);
    $booking = customer_api_guest_booking_for_payment($conn, $guestId, $bookingId);
    $request = ars_booking_request_by_id($conn, (int)$booking['company_id'], $requestId);
    if (!$request || (int)$request['booking_id'] !== (int)$booking['id'] || (int)$request['guest_id'] !== $guestId) {
        customer_api_send_error('not_found', 'Request not found', 404);
    }
    if ((string)$request['status'] !== 'pending') {
        customer_api_send_error('invalid_state', 'Only pending requests can be cancelled.', 422);
    }

    $updated = ars_booking_request_update_status(
        $conn,
        (int)$booking['company_id'],
        (int)$request['id'],
        'cancelled',
        0,
        'Cancelled by guest'
    );

    ars_guest_notification_create($conn, [
        'company_id' => (int)$booking['company_id'],
        'guest_id' => $guestId,
        'booking_id' => (int)$booking['id'],
        'event_type' => 'request_cancelled',
        'title' => 'Request cancelled',
        'message' => 'Your ' . str_replace('_', ' ', (string)$request['request_type']) . ' request was cancelled.',
        'cta_route' => '/guest/bookings/' . (int)$booking['id'],
        'meta' => [
            'request_id' => (int)$updated['id'],
            'request_type' => (string)$request['request_type'],
        ],
    ]);

    customer_api_send_ok([
        'request' => customer_api_guest_booking_request_row($updated),
    ]);
}

/**
 * @return list<array<string,mixed>>
 */
function customer_api_guest_bookings_list(PDO $conn, int $guestId): array {
    $arsCompanyId = getArsCompanyId($conn);
    expirePendingBookings($conn, $arsCompanyId);
    $settings = getArsSettings($conn, $arsCompanyId);
    $currency = (string)($settings['currency'] ?? 'AED');

    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $upcomingOnly = false;
    if (array_key_exists('upcoming_only', $_GET)) {
        $v = strtolower(trim((string)$_GET['upcoming_only']));
        $upcomingOnly = ($v === '1' || $v === 'true' || $v === 'yes');
    }

    $sql = "
        SELECT bk.*, u.unit_number, u.listing_title, b.name AS building_name,
               (SELECT file_path FROM ars_unit_photos WHERE unit_id = bk.unit_id AND is_primary = 1 LIMIT 1) AS photo
        FROM ars_bookings bk
        LEFT JOIN re_units u ON u.id = bk.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE bk.guest_id = ?
    ";
    $params = [$guestId];
    if ($statusFilter !== '') {
        $sql .= ' AND bk.status = ?';
        $params[] = $statusFilter;
    }
    if ($upcomingOnly) {
        $sql .= " AND bk.status IN ('pending','confirmed','checked_in') AND bk.check_out >= CURDATE()";
    }
    $sql .= ' ORDER BY bk.check_in DESC';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $bk) {
        $photoPath = trim((string)($bk['photo'] ?? ''));
        $primaryUrl = $photoPath !== ''
            ? customer_api_absolute_url(customer_api_public_path_url($photoPath))
            : null;
        $title = $bk['listing_title']
            ? (string)$bk['listing_title']
            : (string)(($bk['building_name'] ?? '') . ' - Unit ' . ($bk['unit_number'] ?? ''));

        $out[] = [
            'id' => (int)$bk['id'],
            'booking_number' => (string)($bk['booking_number'] ?? ''),
            'unit_id' => (int)$bk['unit_id'],
            'listing_title' => $title,
            'building_name' => (string)($bk['building_name'] ?? ''),
            'check_in' => (string)($bk['check_in'] ?? ''),
            'check_out' => (string)($bk['check_out'] ?? ''),
            'nights' => (int)($bk['nights'] ?? 0),
            'status' => (string)($bk['status'] ?? ''),
            'total_amount' => number_format((float)($bk['total_amount'] ?? 0), 2, '.', ''),
            'balance_due' => number_format((float)($bk['balance_due'] ?? 0), 2, '.', ''),
            'payment_status' => (string)($bk['payment_status'] ?? ''),
            'currency' => $currency,
            'primary_photo_url' => $primaryUrl,
        ];
    }

    return $out;
}

/**
 * @return array<string,mixed>|null
 */
function customer_api_guest_booking_detail(PDO $conn, int $guestId, int $bookingId): ?array {
    ars_stripe_ensure_schema($conn);
    ars_deposit_ensure_schema($conn);
    $arsCompanyId = getArsCompanyId($conn);
    expirePendingBookings($conn, $arsCompanyId);

    $stmt = $conn->prepare("
        SELECT bk.*, u.unit_number, u.unit_type, u.area_sqm, u.listing_title, u.short_description,
               u.nightly_rate, u.max_guests, u.rental_mode,
               b.name AS building_name, b.address AS building_address,
               (SELECT file_path FROM ars_unit_photos WHERE unit_id = bk.unit_id AND is_primary = 1 LIMIT 1) AS photo
        FROM ars_bookings bk
        LEFT JOIN re_units u ON u.id = bk.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE bk.id = ? AND bk.guest_id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId, $guestId]);
    $bk = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bk) {
        return null;
    }

    $payStmt = $conn->prepare(
        'SELECT id, amount, payment_method, payment_date, reference_number, payment_link_url, payment_link_status, notes, created_at,
                payment_gateway, payment_type, currency, gateway_payment_intent_id, gateway_charge_id, gateway_status,
                amount_refunded, refunded_at, failure_code, failure_message
         FROM ars_booking_payments WHERE booking_id = ? ORDER BY payment_date DESC, id DESC'
    );
    $payStmt->execute([$bookingId]);
    $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    $photoPath = trim((string)($bk['photo'] ?? ''));
    $primaryUrl = $photoPath !== ''
        ? customer_api_absolute_url(customer_api_public_path_url($photoPath))
        : null;

    $money = static function ($v): string {
        return number_format((float)$v, 2, '.', '');
    };

    $bookingOut = [
        'id' => (int)$bk['id'],
        'booking_number' => (string)($bk['booking_number'] ?? ''),
        'unit_id' => (int)$bk['unit_id'],
        'guest_id' => (int)$bk['guest_id'],
        'check_in' => (string)($bk['check_in'] ?? ''),
        'check_out' => (string)($bk['check_out'] ?? ''),
        'nights' => (int)($bk['nights'] ?? 0),
        'num_guests' => (int)($bk['num_guests'] ?? 0),
        'status' => (string)($bk['status'] ?? ''),
        'nightly_rate' => $money($bk['nightly_rate'] ?? 0),
        'subtotal' => $money($bk['subtotal'] ?? 0),
        'vat_rate' => (float)($bk['vat_rate'] ?? 0),
        'vat_amount' => $money($bk['vat_amount'] ?? 0),
        'total_amount' => $money($bk['total_amount'] ?? 0),
        'paid_amount' => $money($bk['paid_amount'] ?? 0),
        'balance_due' => $money($bk['balance_due'] ?? 0),
        'payment_status' => (string)($bk['payment_status'] ?? ''),
        'expires_at' => customer_api_datetime_utc_iso($bk['expires_at'] ?? null),
        'special_requests' => $bk['special_requests'] !== null && $bk['special_requests'] !== ''
            ? (string)$bk['special_requests']
            : null,
        'created_at' => (string)($bk['created_at'] ?? ''),
        'primary_photo_url' => $primaryUrl,
    ];

    $unitOut = [
        'id' => (int)$bk['unit_id'],
        'unit_number' => (string)($bk['unit_number'] ?? ''),
        'unit_type' => (string)($bk['unit_type'] ?? ''),
        'area_sqm' => $bk['area_sqm'] !== null && $bk['area_sqm'] !== '' ? (float)$bk['area_sqm'] : null,
        'listing_title' => (string)($bk['listing_title'] ?? ''),
        'short_description' => (string)($bk['short_description'] ?? ''),
        'building_name' => (string)($bk['building_name'] ?? ''),
        'building_address' => (string)($bk['building_address'] ?? ''),
        'nightly_rate' => (float)($bk['nightly_rate'] ?? 0),
        'max_guests' => (int)($bk['max_guests'] ?? 0),
        'rental_mode' => (string)($bk['rental_mode'] ?? ''),
    ];

    $paymentsOut = [];
    foreach ($payments as $p) {
        if ((string)($p['payment_type'] ?? '') === 'security_deposit') {
            continue;
        }
        $paymentsOut[] = [
            'id' => (int)$p['id'],
            'amount' => number_format((float)($p['amount'] ?? 0), 2, '.', ''),
            'payment_method' => (string)($p['payment_method'] ?? ''),
            'payment_date' => (string)($p['payment_date'] ?? ''),
            'reference_number' => $p['reference_number'] !== null ? (string)$p['reference_number'] : null,
            'payment_link_url' => !empty($p['payment_link_url']) ? (string)$p['payment_link_url'] : null,
            'payment_link_status' => $p['payment_link_status'] !== null ? (string)$p['payment_link_status'] : null,
            'notes' => $p['notes'] !== null ? (string)$p['notes'] : null,
            'created_at' => (string)($p['created_at'] ?? ''),
            'payment_gateway' => $p['payment_gateway'] !== null ? (string)$p['payment_gateway'] : null,
            'payment_type' => (string)($p['payment_type'] ?? 'manual'),
            'currency' => $p['currency'] !== null ? (string)$p['currency'] : null,
            'gateway_payment_intent_id' => $p['gateway_payment_intent_id'] !== null ? (string)$p['gateway_payment_intent_id'] : null,
            'gateway_charge_id' => $p['gateway_charge_id'] !== null ? (string)$p['gateway_charge_id'] : null,
            'gateway_status' => $p['gateway_status'] !== null ? (string)$p['gateway_status'] : null,
            'amount_refunded' => number_format((float)($p['amount_refunded'] ?? 0), 2, '.', ''),
            'refunded_at' => $p['refunded_at'] !== null ? (string)$p['refunded_at'] : null,
            'failure_code' => $p['failure_code'] !== null ? (string)$p['failure_code'] : null,
            'failure_message' => $p['failure_message'] !== null ? (string)$p['failure_message'] : null,
        ];
    }

    $settings = getArsSettings($conn, (int)$bk['company_id']);

    return [
        'booking' => $bookingOut,
        'unit' => $unitOut,
        'payments' => $paymentsOut,
        'security_deposit' => ars_booking_security_deposit_payload($bk, $settings),
    ];
}

function customer_api_guest_handle_security_deposit_cash(PDO $conn, int $guestId, int $bookingId): void {
    ars_deposit_ensure_schema($conn);
    $booking = customer_api_guest_booking_for_payment($conn, $guestId, $bookingId);
    if (in_array((string)$booking['status'], ['cancelled', 'expired'], true)) {
        customer_api_send_error('booking_not_payable', 'This booking is not active.', 422);
    }
    try {
        ars_booking_request_deposit_cash($conn, $booking);
    } catch (Throwable $e) {
        customer_api_send_error('deposit_error', $e->getMessage(), 422);
    }
    $stmt = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$bookingId]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC) ?: $booking;
    $settings = getArsSettings($conn, (int)$fresh['company_id']);
    customer_api_send_ok([
        'security_deposit' => ars_booking_security_deposit_payload($fresh, $settings),
    ]);
}

/**
 * @return list<string>
 */
function customer_api_guest_booking_notification_recipients(array $settings): array {
    $raw = (string)($settings['booking_notification_emails'] ?? '');
    if (trim($raw) === '') {
        return [];
    }
    $parts = preg_split('/[,\n;\s]+/', $raw) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $email = trim($part);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = $email;
        }
    }
    return array_values($emails);
}

function customer_api_guest_html(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function customer_api_guest_booking_admin_url(int $bookingId): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $protocol = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $base = preg_replace('#/api/customer/v1(?:/index\.php)?$#', '', $script) ?: '';
    return $protocol . '://' . $host . $base . '/modules/ars/booking_view.php?id=' . $bookingId;
}

function customer_api_guest_send_booking_notification(
    PDO $conn,
    array $settings,
    int $bookingId,
    string $bookingNumber,
    array $ctx,
    array $unit,
    string $checkIn,
    string $checkOut,
    int $nights,
    int $guests,
    float $totalAmount,
    string $specialRequests
): void {
    $recipients = customer_api_guest_booking_notification_recipients($settings);
    if (!$recipients) {
        return;
    }

    try {
        $smtp = $conn->query("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$smtp) {
            error_log('ARS booking notification skipped: SMTP settings are disabled or missing.');
            return;
        }

        $currency = (string)($settings['currency'] ?? 'AED');
        $guestName = trim((string)($ctx['first_name'] ?? '') . ' ' . (string)($ctx['last_name'] ?? ''));
        $guestEmail = (string)($ctx['email'] ?? '');
        $guestPhone = (string)($ctx['phone'] ?? '');
        $unitTitle = (string)($unit['listing_title'] ?: ('Unit ' . ($unit['unit_number'] ?? '')));
        $building = (string)($unit['building_name'] ?? '');
        $adminUrl = customer_api_guest_booking_admin_url($bookingId);
        $subject = 'New pending booking ' . $bookingNumber;

        $html = '
            <div style="font-family:Arial,sans-serif;max-width:680px;margin:0 auto;color:#1f2937">
                <h2 style="margin-bottom:8px">New pending booking</h2>
                <p style="margin-top:0;color:#6b7280">A guest submitted a booking from the mobile app. Please review and confirm it in ARS.</p>
                <div style="background:#f3f7fb;border:1px solid #dbe7f3;border-radius:14px;padding:16px;margin:18px 0">
                    <p style="margin:0 0 8px"><strong>Booking:</strong> ' . customer_api_guest_html($bookingNumber) . '</p>
                    <p style="margin:0 0 8px"><strong>Status:</strong> Pending</p>
                    <p style="margin:0 0 8px"><strong>Unit:</strong> ' . customer_api_guest_html($unitTitle) . '</p>
                    <p style="margin:0"><strong>Building:</strong> ' . customer_api_guest_html($building) . '</p>
                </div>
                <table style="width:100%;border-collapse:collapse;margin:18px 0">
                    <tr><td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280">Dates</td><td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right">' . customer_api_guest_html($checkIn . ' to ' . $checkOut) . '</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280">Nights / Guests</td><td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right">' . $nights . ' nights · ' . $guests . ' guests</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280">Total</td><td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right"><strong>' . customer_api_guest_html($currency) . ' ' . number_format($totalAmount, 2) . '</strong></td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280">Guest</td><td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right">' . customer_api_guest_html($guestName) . '</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280">Contact</td><td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right">' . customer_api_guest_html(trim($guestEmail . ' ' . $guestPhone)) . '</td></tr>
                </table>
                ' . ($specialRequests !== '' ? '<p><strong>Special requests:</strong><br>' . nl2br(customer_api_guest_html($specialRequests)) . '</p>' : '') . '
                <p style="margin-top:22px"><a href="' . customer_api_guest_html($adminUrl) . '" style="display:inline-block;background:#0f4c75;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px">Open booking</a></p>
            </div>
        ';

        foreach ($recipients as $email) {
            $result = send_smtp_mail($smtp, $email, $subject, $html);
            if (empty($result['ok'])) {
                error_log('ARS booking notification failed for ' . $email . ': ' . ($result['error'] ?? 'unknown error'));
            }
        }
    } catch (Throwable $e) {
        error_log('ARS booking notification failed: ' . $e->getMessage());
    }
}

function customer_api_guest_booking_for_payment(PDO $conn, int $guestId, int $bookingId): array {
    $stmt = $conn->prepare("
        SELECT b.*, g.first_name, g.last_name, g.email AS guest_email, g.phone AS guest_phone,
               u.unit_number, u.listing_title, bl.name AS building_name
        FROM ars_bookings b
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bl ON bl.id = u.building_id
        WHERE b.id = ? AND b.guest_id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId, $guestId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        customer_api_send_error('not_found', 'Booking not found', 404);
    }
    return $booking;
}

function customer_api_guest_handle_stripe_config(PDO $conn, int $guestId): void {
    ars_stripe_ensure_schema($conn);
    $bookingId = (int)($_GET['booking_id'] ?? 0);
    $arsCompanyId = getArsCompanyId($conn);
    $settings = getArsSettings($conn, $arsCompanyId);
    $public = ars_stripe_public_settings($settings);
    $data = [
        'stripe' => $public,
    ];
    if ($bookingId > 0) {
        $booking = customer_api_guest_booking_for_payment($conn, $guestId, $bookingId);
        $data['booking'] = [
            'booking_id' => (int)$booking['id'],
            'booking_number' => (string)$booking['booking_number'],
            'status' => (string)$booking['status'],
            'payment_status' => (string)$booking['payment_status'],
            'total_amount' => number_format((float)$booking['total_amount'], 2, '.', ''),
            'paid_amount' => number_format((float)$booking['paid_amount'], 2, '.', ''),
            'balance_due' => number_format((float)$booking['balance_due'], 2, '.', ''),
            'payable_full' => number_format(ars_stripe_payment_amount($booking, $settings, 'full'), 2, '.', ''),
            'payable_deposit' => number_format(ars_stripe_payment_amount($booking, $settings, 'deposit'), 2, '.', ''),
            'payable_balance' => number_format(ars_stripe_payment_amount($booking, $settings, 'balance'), 2, '.', ''),
            'security_deposit' => ars_booking_security_deposit_payload($booking, $settings),
        ];
    }
    customer_api_send_ok($data);
}

function customer_api_guest_handle_create_stripe_payment_intent(PDO $conn, int $guestId, int $bookingId): void {
    ars_stripe_ensure_schema($conn);
    $body = customer_api_read_json_body();
    $paymentType = (string)($body['payment_type'] ?? 'full');
    if (!in_array($paymentType, ['full', 'deposit', 'balance', 'partial', 'security_deposit'], true)) {
        customer_api_send_error('validation_error', 'Invalid payment_type', 422);
    }
    $requestedAmount = isset($body['amount']) ? (float)$body['amount'] : null;
    $booking = customer_api_guest_booking_for_payment($conn, $guestId, $bookingId);
    if (in_array((string)$booking['status'], ['cancelled', 'expired'], true)) {
        customer_api_send_error('booking_not_payable', 'This booking can no longer be paid.', 422);
    }
    if ($paymentType !== 'security_deposit' && (float)$booking['balance_due'] <= 0) {
        customer_api_send_error('already_paid', 'This booking has no remaining balance.', 422);
    }
    if ($paymentType === 'security_deposit' && (string)($booking['deposit_status'] ?? 'none') !== 'pending') {
        customer_api_send_error('deposit_not_due', 'Security deposit is not due.', 422);
    }

    $settings = getArsSettings($conn, (int)$booking['company_id']);
    $errors = ars_stripe_validate_settings($settings);
    if ($errors || empty($settings['stripe_enabled'])) {
        customer_api_send_error('stripe_not_configured', $errors ? implode(' ', $errors) : 'Stripe is not enabled.', 422);
    }

    try {
        $intent = ars_stripe_create_payment_intent($conn, $booking, $settings, $paymentType, $requestedAmount);
        customer_api_send_ok($intent, 201);
    } catch (Throwable $e) {
        customer_api_send_error('stripe_payment_intent_failed', $e->getMessage(), 422);
    }
}

function customer_api_guest_handle_create_booking(PDO $conn, array $ctx): void {
    $body = customer_api_read_json_body();
    $unitId = (int)($body['unit_id'] ?? 0);
    $checkIn = trim((string)($body['check_in'] ?? ''));
    $checkOut = trim((string)($body['check_out'] ?? ''));
    $guests = max(1, (int)($body['guests'] ?? 1));
    $specialReqs = trim((string)($body['special_requests'] ?? ''));
    $promoCode = trim((string)($body['promo_code'] ?? ''));

    if ($unitId <= 0 || !customer_api_stay_date_ok($checkIn) || !customer_api_stay_date_ok($checkOut) || $checkIn >= $checkOut) {
        customer_api_send_error('validation_error', 'unit_id, check_in, and check_out are required (YYYY-MM-DD; check_out after check_in)', 400);
    }
    if ($checkIn < date('Y-m-d')) {
        customer_api_send_error('validation_error', 'check_in cannot be in the past', 400);
    }

    $guestId = (int)$ctx['guest_id'];
    $arsCompanyId = getArsCompanyId($conn);
    $settings = getArsSettings($conn, $arsCompanyId);
    $vatRate = (float)($settings['default_vat_rate'] ?? 5);
    $expiryHours = (int)($settings['pending_expiry_hours'] ?? 24);

    expirePendingBookings($conn, $arsCompanyId);

    $stmt = $conn->prepare("
        SELECT u.*, b.name AS building_name, b.address AS building_address
        FROM re_units u
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE u.id = ? AND u.is_listed = 1 AND u.rental_mode IN ('short_term','both')
        LIMIT 1
    ");
    $stmt->execute([$unitId]);
    $unit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$unit) {
        customer_api_send_error('not_found', 'Unit not found', 404);
    }

    $maxG = (int)($unit['max_guests'] ?? 0);
    if ($maxG > 0 && $guests > $maxG) {
        customer_api_send_error('validation_error', 'Number of guests exceeds unit maximum', 422);
    }

    $nights = max(1, (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    $minStay = ars_validate_minimum_stay($conn, $arsCompanyId, $unitId, $checkIn, $checkOut, $nights);
    if ($minStay !== null) {
        customer_api_send_error('min_stay', $minStay, 422);
    }

    $discount = [];
    $bPromoCodeId = null;
    if ($promoCode !== '') {
        $baseSubtotal = 0.0;
        $nb = ars_get_nightly_breakdown($conn, $arsCompanyId, $unitId, $checkIn, $checkOut, (float)$unit['nightly_rate']);
        foreach ($nb as $n) {
            $baseSubtotal += (float)($n['rate'] ?? 0);
        }
        $baseSubtotal = round($baseSubtotal, 2);
        $pr = ars_validate_promo_code($conn, $arsCompanyId, $promoCode, $nights, $baseSubtotal);
        if ($pr['valid']) {
            $pc = $pr['promo'];
            $discount = [
                'type' => 'promo',
                'discount_type' => $pc['discount_type'],
                'discount_value' => (float)$pc['discount_value'],
                'max_discount_amount' => $pc['max_discount_amount'] !== null && $pc['max_discount_amount'] !== ''
                    ? (float)$pc['max_discount_amount'] : null,
                'promo_id' => (int)$pc['id'],
                'label' => $pc['code'],
            ];
            $bPromoCodeId = (int)$pc['id'];
        }
    }

    $pricing = ars_calculate_booking_price_v2(
        $conn,
        $arsCompanyId,
        $unitId,
        (float)$unit['nightly_rate'],
        $nights,
        $checkIn,
        $checkOut,
        null,
        [],
        $vatRate,
        $discount
    );

    try {
        $conn->beginTransaction();

        $availResult = ars_check_availability($conn, $unitId, $checkIn, $checkOut);
        if (!$availResult['available']) {
            $conn->rollBack();
            customer_api_send_error(
                'not_available',
                'Sorry, this unit is no longer available for your selected dates.',
                409,
                ['conflicts' => $availResult['conflicts'] ?? []]
            );
        }

        $bookingNumber = generateBookingNumber($conn, $arsCompanyId);
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours"));

        $lengthDiscAmt = $pricing['length_discount_amount'] ?? 0;
        $lengthDiscLabel = $pricing['length_discount_label'] ?? '';
        $rulesJson = !empty($pricing['rules_applied']) ? json_encode($pricing['rules_applied']) : null;
        $bDiscType = !empty($discount) ? 'promo' : 'none';
        $bDiscLabel = !empty($discount) ? ($discount['label'] ?? '') : null;
        $bDiscPct = (!empty($discount) && ($discount['discount_type'] ?? '') === 'percentage')
            ? (float)($discount['discount_value'] ?? 0) : 0;
        $bDiscAmt = $pricing['discount_amount'] ?? 0;

        $conn->prepare("
            INSERT INTO ars_bookings
                (company_id, unit_id, guest_id, booking_number, check_in, check_out, nights, num_guests,
                 status, nightly_rate, rate_override, subtotal, extras_total, vat_rate, vat_amount, net_amount,
                 length_discount_amount, length_discount_label, pricing_rules_applied,
                 pricing_mode, vat_mode, entered_amount,
                 discount_type, discount_label, discount_percent, discount_amount, promo_code_id,
                 total_amount, paid_amount, balance_due, payment_status, expires_at,
                 deposit_amount, deposit_status, is_historical,
                 special_requests, internal_notes, created_by)
            VALUES (
                ?,?,?,?,?,?,?,?,
                'pending',?,?,?,0.00,?,?,?,
                ?,?,?,
                'nightly','exclusive',NULL,
                ?,?,?,?,?,
                ?,?,?, 'unpaid', ?,
                0.00,'none',0,
                ?,?,?
            )
        ")->execute([
            $arsCompanyId, $unitId, $guestId, $bookingNumber, $checkIn, $checkOut, $nights, $guests,
            $pricing['effective_rate'], null, $pricing['subtotal'],
            $pricing['vat_rate'], $pricing['vat_amount'], $pricing['net_amount'] ?? null,
            $lengthDiscAmt, $lengthDiscLabel, $rulesJson,
            $bDiscType, $bDiscLabel, $bDiscPct, $bDiscAmt, $bPromoCodeId,
            $pricing['total_amount'], 0.00, $pricing['total_amount'], $expiresAt,
            $specialReqs !== '' ? $specialReqs : null, 'API booking', null,
        ]);

        if ($bPromoCodeId) {
            ars_increment_promo_usage($conn, $bPromoCodeId);
        }

        $bookingId = (int)$conn->lastInsertId();

        $conn->prepare('UPDATE ars_guests SET total_bookings = total_bookings + 1 WHERE id = ?')->execute([$guestId]);

        $conn->commit();

        customer_api_guest_send_booking_notification(
            $conn,
            $settings,
            $bookingId,
            $bookingNumber,
            $ctx,
            $unit,
            $checkIn,
            $checkOut,
            $nights,
            $guests,
            (float)$pricing['total_amount'],
            $specialReqs
        );

        $bookingRoute = '/guest/bookings/' . $bookingId;
        ars_guest_notification_create($conn, [
            'company_id' => $arsCompanyId,
            'guest_id' => $guestId,
            'booking_id' => $bookingId,
            'event_type' => 'booking_created',
            'title' => 'Booking request received',
            'message' => 'Your booking ' . $bookingNumber . ' is pending confirmation.',
            'cta_route' => $bookingRoute,
            'meta' => [
                'booking_number' => $bookingNumber,
                'status' => 'pending',
            ],
        ]);

        $checkInReminderAt = date('Y-m-d 10:00:00', strtotime($checkIn . ' -1 day'));
        $checkOutReminderAt = date('Y-m-d 10:00:00', strtotime($checkOut . ' -1 day'));
        ars_guest_notification_create($conn, [
            'company_id' => $arsCompanyId,
            'guest_id' => $guestId,
            'booking_id' => $bookingId,
            'event_type' => 'reminder_checkin',
            'title' => 'Check-in reminder scheduled',
            'message' => 'Reminder placeholder for your check-in on ' . $checkIn . '.',
            'cta_route' => $bookingRoute,
            'scheduled_for' => $checkInReminderAt,
            'is_placeholder' => 1,
        ]);
        ars_guest_notification_create($conn, [
            'company_id' => $arsCompanyId,
            'guest_id' => $guestId,
            'booking_id' => $bookingId,
            'event_type' => 'reminder_checkout',
            'title' => 'Check-out reminder scheduled',
            'message' => 'Reminder placeholder for your check-out on ' . $checkOut . '.',
            'cta_route' => $bookingRoute,
            'scheduled_for' => $checkOutReminderAt,
            'is_placeholder' => 1,
        ]);

        customer_api_send_ok([
            'booking_id' => $bookingId,
            'booking_number' => $bookingNumber,
            'status' => 'pending',
            'expires_at' => customer_api_datetime_utc_iso($expiresAt),
        ], 201);
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        customer_api_send_error('server_error', 'Booking failed', 500);
    }
}
