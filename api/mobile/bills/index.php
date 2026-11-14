<?php
/**
 * Bills API (staff app) — front controller.
 * Same envelope as the other staff APIs: {"ok":true,"data":…} / {"ok":false,"error":…}.
 *
 *   GET    people                       who a bill can be given to (Bills on in HR), not me
 *   GET    bills?before=                {open, recent, more}: mine to act on, and every bill I am part of
 *   POST   bills                        start one (form: data = JSON, files[] = attachments); "to" optional —
 *                                       without it the bill stays with me, to give later
 *   POST   bills/many                   {"action": accept|reject|give|keep, "ids": [..], "reason", "to", "given_on"}
 *                                       -> {bills: [...done], failed: [{id, message}]}
 *   GET    bills/{id}
 *   POST   bills/{id}/accept
 *   POST   bills/{id}/reject            {"reason"}
 *   POST   bills/{id}/give              {"to", "given_on"}
 *   POST   bills/{id}/keep
 *   POST   bills/{id}/note              {"note"} — the starter or whoever holds it
 *   POST   bills/{id}/request-delete    {"reason"} — only the one who started it; the office deletes or declines
 *   POST   bills/{id}/edit              form: data = {"reason", received_on?, amount?, notes?}, files[] = more attachments
 *   GET    bills/{id}/files/{file}      the attachment itself
 *   GET    push/key                     the site key, to turn on notifications
 *   POST   push/subscribe               {endpoint, keys:{p256dh, auth}}
 *   POST   push/unsubscribe             {endpoint}
 */

declare(strict_types=1);

require_once __DIR__ . '/bills_api.php';

bills_api_boot($conn);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = bills_api_parse_route();

if ($route === 'health' && $method === 'GET') {
    customer_api_send_ok(['version' => 'bills-v1']);
}

$me = bills_api_current_user($conn);
if (function_exists('usage_track_identify')) {
    usage_track_identify('bills', $route, $me['user_id']);
}

try {
    if ($route === 'people' && $method === 'GET') {
        customer_api_send_ok(['people' => bill_people($conn, $me['user_id'])]);
    }
    if ($route === 'bills' && $method === 'GET') {
        customer_api_send_ok(bill_list_mine($conn, $me, (int)($_GET['before'] ?? 0)));
    }
    if ($route === 'bills' && $method === 'POST') {
        bills_api_send($conn, bill_create($conn, $me, bills_api_body(), bill_uploaded_files('files')));
    }
    if ($route === 'bills/many' && $method === 'POST') {
        $body = bills_api_body();
        bills_api_send($conn, bill_many($conn, $me, (string)($body['action'] ?? ''), $body['ids'] ?? [], $body));
    }
    if ($method === 'GET' && preg_match('#^bills/(\d+)$#', $route, $m)) {
        $bill = bill_get($conn, $me, (int)$m[1]);
        bills_api_send($conn, [$bill, []]);
    }
    if ($method === 'POST' && preg_match('#^bills/(\d+)/note$#', $route, $m)) {
        bills_api_send($conn, bill_add_note($conn, $me, (int)$m[1], bills_api_body()['note'] ?? ''));
    }
    if ($method === 'POST' && preg_match('#^bills/(\d+)/request-delete$#', $route, $m)) {
        bills_api_send($conn, bill_request_delete($conn, $me, (int)$m[1], bills_api_body()['reason'] ?? ''));
    }
    if ($method === 'POST' && preg_match('#^bills/(\d+)/edit$#', $route, $m)) {
        bills_api_send($conn, bill_edit($conn, $me, (int)$m[1], bills_api_body(), bill_uploaded_files('files')));
    }
    if ($method === 'POST' && preg_match('#^bills/(\d+)/(accept|reject|give|keep)$#', $route, $m)) {
        $id = (int)$m[1];
        $body = $m[2] === 'reject' || $m[2] === 'give' ? bills_api_body() : [];
        $result = match ($m[2]) {
            'accept' => bill_accept($conn, $me, $id),
            'reject' => bill_reject($conn, $me, $id, $body['reason'] ?? ''),
            'give' => bill_give($conn, $me, $id, $body),
            'keep' => bill_keep($conn, $me, $id),
        };
        bills_api_send($conn, $result);
    }
    if ($method === 'GET' && preg_match('#^bills/(\d+)/files/(\d+)$#', $route, $m)) {
        $file = bill_file($conn, $me, (int)$m[1], (int)$m[2]);
        if ($file === null) {
            customer_api_send_error('not_found', 'That attachment is not there any more.', 404);
        }
        header_remove('Content-Type');
        bill_send_file($file);
    }
    if ($route === 'push/key' && $method === 'GET') {
        customer_api_send_ok(bill_push_ready($conn)
            ? ['available' => true, 'public_key' => web_push_vapid($conn)['public']]
            : ['available' => false]);
    }
    if ($method === 'POST' && preg_match('#^push/(subscribe|unsubscribe)$#', $route, $m)) {
        if (!bill_push_ready($conn)) {
            customer_api_send_error('not_available', 'Notifications are not available yet.', 409);
        }
        $body = bills_api_body();
        $endpoint = trim((string)($body['endpoint'] ?? ''));
        if ($endpoint === '') {
            customer_api_send_error('bad_request', 'Missing subscription.', 422);
        }
        if ($m[1] === 'unsubscribe') {
            bill_push_unsubscribe($conn, $me['user_id'], $endpoint);
            customer_api_send_ok(['subscribed' => false]);
        }
        $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];
        $ok = bill_push_subscribe($conn, $me['user_id'], $endpoint, (string)($keys['p256dh'] ?? ''), (string)($keys['auth'] ?? ''),
            (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!$ok) {
            customer_api_send_error('bad_request', 'That subscription could not be saved.', 422);
        }
        customer_api_send_ok(['subscribed' => true]);
    }
} catch (BillError $e) {
    customer_api_send_error($e->errCode, $e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('bills api ' . $method . ' ' . $route . ': ' . $e->getMessage());
    customer_api_send_error('server', 'Please try again in a moment.', 500);
}

customer_api_send_error('not_found', 'Not found', 404);
