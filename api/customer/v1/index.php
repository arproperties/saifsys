<?php
/**
 * Unified customer API v1 — front controller (Phases A + B + C guest + D-light tenant).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/customer_api.php';
require_once __DIR__ . '/../../../modules/ars/includes/ars_helpers.php';
require_once __DIR__ . '/../../../modules/ars/includes/ars_availability.php';
require_once __DIR__ . '/../../../modules/ars/includes/ars_pricing.php';
require_once __DIR__ . '/../../../modules/ars/includes/ars_stripe.php';
require_once __DIR__ . '/../../../modules/ars/includes/ars_deposit.php';
require_once __DIR__ . '/../../../modules/ars/includes/ars_guest_notifications.php';
require_once __DIR__ . '/../../../modules/ars/includes/ars_booking_requests.php';
require_once __DIR__ . '/stay_endpoints.php';
require_once __DIR__ . '/guest_endpoints.php';
require_once __DIR__ . '/guest_documents.php';
require_once __DIR__ . '/tenant_endpoints.php';
require_once __DIR__ . '/tenant_services_endpoints.php';
require_once __DIR__ . '/tenant_extra_services_endpoints.php';
require_once __DIR__ . '/tenant_documents_endpoints.php';
require_once __DIR__ . '/tenant_renewals_endpoints.php';
require_once __DIR__ . '/tenant_notifications_endpoints.php';
require_once __DIR__ . '/customer_push_endpoints.php';
require_once __DIR__ . '/app_config_endpoints.php';

customer_api_json_headers();
customer_api_handle_options();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = customer_api_parse_route();

if ($route === 'health' && $method === 'GET') {
    customer_api_send_ok(['version' => 'v1']);
}

if ($route === 'app-config' && $method === 'GET') {
    customer_api_handle_app_config($conn);
}

if ($route === 'stripe/webhook' && $method === 'POST') {
    $payload = file_get_contents('php://input') ?: '';
    $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $mode = strtolower((string)($_GET['mode'] ?? 'test'));
    $livemode = $mode === 'live';
    try {
        $result = ars_stripe_process_webhook($conn, $payload, $signature, $livemode);
        customer_api_send_ok($result);
    } catch (Throwable $e) {
        customer_api_send_error('stripe_webhook_error', $e->getMessage(), 400);
    }
}

if ($route === 'auth/refresh' && $method === 'POST') {
    $body = customer_api_read_json_body();
    $refresh = isset($body['refresh_token']) ? trim((string)$body['refresh_token']) : '';
    if ($refresh === '') {
        customer_api_send_error('validation_error', 'refresh_token is required', 400);
    }
    $payload = customer_api_jwt_verify($refresh);
    if (!$payload || ($payload['token_use'] ?? '') !== 'refresh') {
        customer_api_send_error('invalid_token', 'Invalid or expired refresh token', 401);
    }
    $accessClaims = $payload;
    unset($accessClaims['token_use'], $accessClaims['iat'], $accessClaims['exp']);
    if (!isset($accessClaims['typ'], $accessClaims['sub'])) {
        customer_api_send_error('invalid_token', 'Invalid refresh token claims', 401);
    }
    $pair = customer_api_issue_token_pair($accessClaims);
    customer_api_send_ok(array_merge([
        'token_type' => 'Bearer',
    ], $pair));
}

if ($route === 'auth/logout' && $method === 'POST') {
    customer_api_send_ok(['acknowledged' => true]);
}

if ($route === 'tenant/device-tokens' && $method === 'POST') {
    customer_api_push_handle_tenant_token_register($conn);
}

if ($route === 'tenant/device-tokens' && $method === 'DELETE') {
    customer_api_push_handle_tenant_token_delete($conn);
}

if ($route === 'guest/device-tokens' && $method === 'POST') {
    customer_api_push_handle_guest_token_register($conn);
}

if ($route === 'guest/device-tokens' && $method === 'DELETE') {
    customer_api_push_handle_guest_token_delete($conn);
}

if ($route === 'auth/guest/register' && $method === 'POST') {
    customer_api_guest_handle_register($conn);
}

if ($route === 'auth/guest/login' && $method === 'POST') {
    customer_api_guest_handle_login($conn);
}

if ($route === 'auth/guest/me' && $method === 'GET') {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_send_ok(customer_api_guest_me_data($conn, $ctx));
}

if ($method === 'GET' && preg_match('#^stay/bookings/(\d+)$#', $route, $m)) {
    $ctx = customer_api_guest_require_access($conn);
    $detail = customer_api_guest_booking_detail($conn, (int)$ctx['guest_id'], (int)$m[1]);
    if ($detail === null) {
        customer_api_send_error('not_found', 'Booking not found', 404);
    }
    customer_api_send_ok($detail);
}

if ($method === 'GET' && $route === 'stay/payments/stripe/config') {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_stripe_config($conn, (int)$ctx['guest_id']);
}

if ($method === 'POST' && preg_match('#^stay/bookings/(\d+)/payments/stripe/payment-intent$#', $route, $m)) {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_create_stripe_payment_intent($conn, (int)$ctx['guest_id'], (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^stay/bookings/(\d+)/security-deposit/cash$#', $route, $m)) {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_security_deposit_cash($conn, (int)$ctx['guest_id'], (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^stay/bookings/(\d+)/documents$#', $route, $m)) {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_booking_documents_list($conn, (int)$ctx['guest_id'], (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^stay/bookings/(\d+)/documents/(booking_confirmation|payment_receipt|tax_invoice)/download$#', $route, $m)) {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_booking_document_download($conn, (int)$ctx['guest_id'], (int)$m[1], $m[2]);
}

if ($method === 'GET' && preg_match('#^stay/bookings/(\d+)/requests$#', $route, $m)) {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_booking_requests_list($conn, $ctx, (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^stay/bookings/(\d+)/requests$#', $route, $m)) {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_booking_request_create($conn, $ctx, (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^stay/bookings/(\d+)/requests/(\d+)/cancel$#', $route, $m)) {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_booking_request_cancel($conn, $ctx, (int)$m[1], (int)$m[2]);
}

if ($method === 'GET' && $route === 'stay/notifications') {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_notifications_list($conn, $ctx);
}

if ($method === 'GET' && $route === 'stay/notifications/unread-count') {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_notifications_unread_count($conn, $ctx);
}

if ($method === 'POST' && preg_match('#^stay/notifications/(\d+)/read$#', $route, $m)) {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_notifications_mark_one_read($conn, $ctx, (int)$m[1]);
}

if ($method === 'POST' && $route === 'stay/notifications/read-all') {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_notifications_mark_all_read($conn, $ctx);
}

if ($route === 'stay/bookings' && $method === 'GET') {
    $ctx = customer_api_guest_require_access($conn);
    $list = customer_api_guest_bookings_list($conn, (int)$ctx['guest_id']);
    customer_api_send_ok(['bookings' => $list]);
}

if ($route === 'stay/bookings' && $method === 'POST') {
    $ctx = customer_api_guest_require_access($conn);
    customer_api_guest_handle_create_booking($conn, $ctx);
}

if ($route === 'auth/tenant/login' && $method === 'POST') {
    customer_api_tenant_handle_login($conn);
}

if ($route === 'auth/tenant/register' && $method === 'POST') {
    customer_api_tenant_handle_register($conn);
}

if ($route === 'auth/tenant/forgot-password' && $method === 'POST') {
    customer_api_tenant_handle_forgot_password($conn);
}

if ($route === 'auth/tenant/me' && $method === 'GET') {
    customer_api_tenant_handle_me($conn);
}

if ($route === 'tenant/leases/active' && $method === 'POST') {
    customer_api_tenant_handle_set_active_lease($conn);
}

if ($route === 'tenant/dashboard' && $method === 'GET') {
    customer_api_tenant_handle_dashboard($conn);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/financial-summary$#', $route, $m)) {
    customer_api_tenant_handle_financial_summary($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/outstanding-items$#', $route, $m)) {
    customer_api_tenant_handle_outstanding_items($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/payment-history$#', $route, $m)) {
    customer_api_tenant_handle_payment_history($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/payment-timeline$#', $route, $m)) {
    customer_api_tenant_handle_payment_timeline($conn, (int)$m[1]);
}

if ($route === 'tenant/notifications' && $method === 'GET') {
    customer_api_tenant_handle_notifications_list($conn);
}

if ($route === 'tenant/notifications/unread-count' && $method === 'GET') {
    customer_api_tenant_handle_notifications_unread_count($conn);
}

if ($route === 'tenant/notifications/read-all' && $method === 'POST') {
    customer_api_tenant_handle_notifications_mark_all_read($conn);
}

if ($method === 'POST' && preg_match('#^tenant/notifications/(\d+)/read$#', $route, $m)) {
    customer_api_tenant_handle_notification_mark_read($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/installments$#', $route, $m)) {
    customer_api_tenant_handle_installments($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/payments$#', $route, $m)) {
    customer_api_tenant_handle_payments($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/penalties$#', $route, $m)) {
    customer_api_tenant_handle_penalties($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/invoices$#', $route, $m)) {
    customer_api_tenant_handle_invoices($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/service-charges$#', $route, $m)) {
    customer_api_tenant_handle_service_charges($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/maintenance$#', $route, $m)) {
    customer_api_tenant_handle_maintenance_list($conn, (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/maintenance$#', $route, $m)) {
    customer_api_tenant_handle_maintenance_create($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/maintenance/(\d+)$#', $route, $m)) {
    customer_api_tenant_handle_maintenance_detail($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/maintenance/(\d+)/photos$#', $route, $m)) {
    customer_api_tenant_handle_maintenance_photos($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/cleaning/rates$#', $route, $m)) {
    customer_api_tenant_handle_cleaning_rates($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/cleaning$#', $route, $m)) {
    customer_api_tenant_handle_cleaning_list($conn, (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/cleaning$#', $route, $m)) {
    customer_api_tenant_handle_cleaning_create($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/cleaning/(\d+)$#', $route, $m)) {
    customer_api_tenant_handle_cleaning_detail($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/pest-control/quote$#', $route, $m)) {
    customer_api_tenant_handle_pest_control_quote($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/pest-control$#', $route, $m)) {
    customer_api_tenant_handle_pest_control_list($conn, (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/pest-control$#', $route, $m)) {
    customer_api_tenant_handle_pest_control_create($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/pest-control/(\d+)$#', $route, $m)) {
    customer_api_tenant_handle_pest_control_detail($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/extra-services/rates$#', $route, $m)) {
    customer_api_tenant_handle_extra_service_rates($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/extra-services/quote$#', $route, $m)) {
    customer_api_tenant_handle_extra_service_quote($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/extra-services$#', $route, $m)) {
    customer_api_tenant_handle_extra_service_list($conn, (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/extra-services$#', $route, $m)) {
    customer_api_tenant_handle_extra_service_create($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/extra-services/(\d+)$#', $route, $m)) {
    customer_api_tenant_handle_extra_service_detail($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/documents$#', $route, $m)) {
    customer_api_tenant_handle_documents_list($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/documents/([a-z0-9_\-]+)/(\d+)/download$#', $route, $m)) {
    customer_api_tenant_handle_document_download($conn, (int)$m[1], $m[2], (int)$m[3]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/documents/([a-z0-9_\-]+)/(\d+)$#', $route, $m)) {
    customer_api_tenant_handle_document_detail($conn, (int)$m[1], $m[2], (int)$m[3]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/renewals$#', $route, $m)) {
    customer_api_tenant_handle_renewals_list($conn, (int)$m[1]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/renewals/(\d+)/notice/download$#', $route, $m)) {
    customer_api_tenant_renewal_stream_notice($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/renewals/(\d+)/contract/download$#', $route, $m)) {
    customer_api_tenant_renewal_stream_contract($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/renewals/(\d+)/uploads/(\d+)/download$#', $route, $m)) {
    customer_api_tenant_renewal_stream_upload($conn, (int)$m[1], (int)$m[2], (int)$m[3]);
}

if ($method === 'GET' && preg_match('#^tenant/leases/(\d+)/renewals/(\d+)$#', $route, $m)) {
    customer_api_tenant_handle_renewal_detail($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/renewals/(\d+)/acknowledge$#', $route, $m)) {
    customer_api_tenant_handle_renewal_acknowledge($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/renewals/(\d+)/decision$#', $route, $m)) {
    customer_api_tenant_handle_renewal_decision($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/renewals/(\d+)/negotiation-message$#', $route, $m)) {
    customer_api_tenant_handle_renewal_negotiation_message($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/renewals/(\d+)/negotiation-finalize$#', $route, $m)) {
    customer_api_tenant_handle_renewal_negotiation_finalize($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/renewals/(\d+)/electronic-sign$#', $route, $m)) {
    customer_api_tenant_handle_renewal_electronic_sign($conn, (int)$m[1], (int)$m[2]);
}

if ($method === 'POST' && preg_match('#^tenant/leases/(\d+)/renewals/(\d+)/upload$#', $route, $m)) {
    customer_api_tenant_handle_renewal_upload($conn, (int)$m[1], (int)$m[2]);
}

if ($route === 'stay/settings' && $method === 'GET') {
    customer_api_send_ok(customer_api_stay_settings($conn));
}

if ($route === 'stay/buildings' && $method === 'GET') {
    customer_api_send_ok(customer_api_stay_buildings($conn));
}

if ($route === 'stay/units' && $method === 'GET') {
    $building = trim((string)($_GET['building'] ?? ''));
    $checkIn = trim((string)($_GET['check_in'] ?? ''));
    $checkOut = trim((string)($_GET['check_out'] ?? ''));
    $guests = max(1, (int)($_GET['guests'] ?? 1));

    if (($checkIn !== '' || $checkOut !== '') && (!customer_api_stay_date_ok($checkIn) || !customer_api_stay_date_ok($checkOut) || $checkIn >= $checkOut)) {
        customer_api_send_error('validation_error', 'Invalid check_in / check_out (use YYYY-MM-DD, check_out after check_in)', 400);
    }

    $units = customer_api_stay_units_rows($conn, $building, $checkIn ?: null, $checkOut ?: null, $guests);
    customer_api_send_ok(['units' => $units]);
}

if ($method === 'GET' && preg_match('#^stay/units/(\d+)/quote$#', $route, $m)) {
    $unitId = (int)$m[1];
    $checkIn = trim((string)($_GET['check_in'] ?? ''));
    $checkOut = trim((string)($_GET['check_out'] ?? ''));
    $promo = trim((string)($_GET['promo_code'] ?? ''));

    if (!customer_api_stay_date_ok($checkIn) || !customer_api_stay_date_ok($checkOut) || $checkIn >= $checkOut) {
        customer_api_send_error('validation_error', 'check_in and check_out are required (YYYY-MM-DD)', 400);
    }

    $stmt = $conn->prepare("
        SELECT id FROM re_units
        WHERE id = ? AND is_listed = 1 AND rental_mode IN ('short_term','both')
        LIMIT 1
    ");
    $stmt->execute([$unitId]);
    if (!$stmt->fetchColumn()) {
        customer_api_send_error('not_found', 'Unit not found', 404);
    }

    $arsCompanyId = getArsCompanyId($conn);
    $settings = getArsSettings($conn, $arsCompanyId);
    $vatRate = (float)($settings['default_vat_rate'] ?? 5);

    $data = customer_api_stay_compute_quote($conn, $arsCompanyId, $vatRate, $unitId, $checkIn, $checkOut, $promo);
    customer_api_send_ok($data);
}

if ($method === 'GET' && preg_match('#^stay/units/(\d+)$#', $route, $m)) {
    $unitId = (int)$m[1];
    $calYear = (int)($_GET['cal_year'] ?? date('Y'));
    $calMonth = (int)($_GET['cal_month'] ?? date('n'));
    if ($calMonth < 1 || $calMonth > 12) {
        customer_api_send_error('validation_error', 'cal_month must be 1–12', 400);
    }
    $detail = customer_api_stay_unit_detail($conn, $unitId, $calYear, $calMonth);
    if ($detail === null) {
        customer_api_send_error('not_found', 'Unit not found', 404);
    }
    customer_api_send_ok($detail);
}

customer_api_send_error('not_found', 'Not found', 404);
