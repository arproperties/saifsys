<?php
/**
 * Store API (staff app) — front controller.
 * Same envelope as the other staff APIs: {"ok":true,"data":…} / {"ok":false,"error":…}.
 *
 *   GET    start                     stores, items with stock per store, current staff
 *   GET    items?q=                  items with stock per store
 *   POST   items                     new item {name, unit}; an existing name gives that item
 *   POST   items/{id}/photo          the item's photo (form: photo)
 *   GET    items/{id}/photo
 *   GET    moves?before=             his own entries, newest first, 30 at a time
 *   POST   moves                     new entry (JSON, or form: data = JSON, photo)
 *   GET    moves/{id}
 *   PUT    moves/{id}                change (JSON), on the day it was made
 *   DELETE moves/{id}                on the day it was made
 *   GET    moves/{id}/photo
 *   GET    broken?store=             items still not working (every store keeper's)
 *   POST   broken/{line_id}          {outcome: repaired | thrown}; repaired goes back to stock
 */

declare(strict_types=1);

require_once __DIR__ . '/store_api.php';

store_api_boot($conn);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = store_api_parse_route();

if ($route === 'health' && $method === 'GET') {
    customer_api_send_ok(['version' => 'store-v1']);
}

$keeper = store_api_current_keeper($conn);
if (function_exists('usage_track_identify')) {
    usage_track_identify('store', $route, $keeper['user_id']);
}

try {
    if ($route === 'start' && $method === 'GET') {
        customer_api_send_ok(['stores' => st_stores($conn), 'items' => st_items($conn), 'people' => st_people($conn)]);
    }
    if ($route === 'items' && $method === 'GET') {
        customer_api_send_ok(['items' => st_items($conn, (string)($_GET['q'] ?? ''))]);
    }
    if ($route === 'items' && $method === 'POST') {
        $id = st_save_item($conn, $keeper, 0, store_api_body());
        $match = array_values(array_filter(st_items($conn), static fn(array $i): bool => $i['id'] === $id));
        customer_api_send_ok($match[0] ?? ['id' => $id]);
    }
    if (preg_match('#^items/(\d+)/photo$#', $route, $m)) {
        if ($method === 'POST') {
            $photo = st_uploaded_file('photo');
            if ($photo === null) {
                throw new StError('No photo came through.');
            }
            st_set_item_photo($conn, (int)$m[1], $photo);
            customer_api_send_ok(['id' => (int)$m[1], 'photo' => true]);
        }
        if ($method === 'GET') {
            $file = st_photo($conn, $keeper, 'item', (int)$m[1]);
            if ($file === null) {
                customer_api_send_error('not_found', 'That photo is not there any more.', 404);
            }
            header_remove('Content-Type');
            st_send_file($file);
        }
    }
    if ($route === 'moves' && $method === 'GET') {
        customer_api_send_ok(st_list_mine($conn, $keeper, (int)($_GET['before'] ?? 0)));
    }
    if ($route === 'moves' && $method === 'POST') {
        store_api_send(st_save($conn, $keeper, 0, store_api_body(), st_uploaded_file('photo')));
    }
    if (preg_match('#^moves/(\d+)$#', $route, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') {
            store_api_send(st_get($conn, $keeper, $id));
        }
        if ($method === 'PUT') {
            store_api_send(st_save($conn, $keeper, $id, store_api_body()));
        }
        if ($method === 'DELETE') {
            store_api_send(st_delete($conn, $keeper, $id));
        }
    }
    if ($route === 'broken' && $method === 'GET') {
        customer_api_send_ok(['lines' => st_broken_open($conn, (int)($_GET['store'] ?? 0))]);
    }
    if ($method === 'POST' && preg_match('#^broken/(\d+)$#', $route, $m)) {
        customer_api_send_ok(st_set_outcome($conn, $keeper, (int)$m[1], (string)(store_api_body()['outcome'] ?? '')));
    }
    if ($method === 'GET' && preg_match('#^moves/(\d+)/photo$#', $route, $m)) {
        $file = st_photo($conn, $keeper, 'move', (int)$m[1]);
        if ($file === null) {
            customer_api_send_error('not_found', 'That photo is not there any more.', 404);
        }
        header_remove('Content-Type');
        st_send_file($file);
    }
} catch (StError $e) {
    customer_api_send_error($e->errCode, $e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('store api ' . $method . ' ' . $route . ': ' . $e->getMessage());
    customer_api_send_error('server', 'Please try again in a moment.', 500);
}

customer_api_send_error('not_found', 'Not found', 404);
