<?php
/**
 * Purchase API (staff app) — front controller.
 * Same envelope as the ops and fleet APIs: {"ok":true,"data":…} / {"ok":false,"error":…}.
 *
 *   GET    suppliers?q=                     shops and suppliers, most used first
 *   GET    purchases?before=                his own, newest first, 30 at a time
 *   POST   purchases                        new (form: data = JSON, photos[] = the bill)
 *   GET    purchases/{id}
 *   PUT    purchases/{id}                   change (JSON), on the day it was made
 *   DELETE purchases/{id}                   on the day it was made
 *   POST   purchases/{id}/received          the order came (form: photos[] = delivery note)
 *   POST   purchases/{id}/photos            more photos (form: data = {"kind"}, photos[])
 *   DELETE purchases/{id}/photos/{file}
 *   GET    purchases/{id}/photos/{file}     the photo itself
 */

declare(strict_types=1);

require_once __DIR__ . '/purchase_api.php';

purchase_api_boot($conn);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = purchase_api_parse_route();

if ($route === 'health' && $method === 'GET') {
    customer_api_send_ok(['version' => 'purchase-v1']);
}

$buyer = purchase_api_current_buyer($conn);
if (function_exists('usage_track_identify')) {
    usage_track_identify('purchase', $route, $buyer['user_id']);
}

try {
    if ($route === 'suppliers' && $method === 'GET') {
        customer_api_send_ok(['suppliers' => pur_list_suppliers($conn, (string)($_GET['q'] ?? ''))]);
    }
    if ($route === 'purchases' && $method === 'GET') {
        customer_api_send_ok(pur_list_mine($conn, $buyer, (int)($_GET['before'] ?? 0)));
    }
    if ($route === 'purchases' && $method === 'POST') {
        purchase_api_send(pur_save($conn, $buyer, 0, purchase_api_body(), pur_uploaded_files('photos')));
    }
    if (preg_match('#^purchases/(\d+)$#', $route, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') {
            purchase_api_send(pur_get($conn, $buyer, $id));
        }
        if ($method === 'PUT') {
            purchase_api_send(pur_save($conn, $buyer, $id, purchase_api_body()));
        }
        if ($method === 'DELETE') {
            purchase_api_send(pur_delete($conn, $buyer, $id));
        }
    }
    if ($method === 'POST' && preg_match('#^purchases/(\d+)/received$#', $route, $m)) {
        purchase_api_send(pur_mark_received($conn, $buyer, (int)$m[1], pur_uploaded_files('photos')));
    }
    if ($method === 'POST' && preg_match('#^purchases/(\d+)/photos$#', $route, $m)) {
        purchase_api_send(pur_add_files($conn, $buyer, (int)$m[1], (string)(purchase_api_body()['kind'] ?? ''), pur_uploaded_files('photos')));
    }
    if (preg_match('#^purchases/(\d+)/photos/(\d+)$#', $route, $m)) {
        if ($method === 'DELETE') {
            purchase_api_send(pur_remove_file($conn, $buyer, (int)$m[1], (int)$m[2]));
        }
        if ($method === 'GET') {
            $file = pur_file($conn, $buyer, (int)$m[1], (int)$m[2]);
            if ($file === null) {
                customer_api_send_error('not_found', 'That photo is not there any more.', 404);
            }
            header_remove('Content-Type');
            pur_send_file($file);
        }
    }
} catch (PurError $e) {
    customer_api_send_error($e->errCode, $e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('purchase api ' . $method . ' ' . $route . ': ' . $e->getMessage());
    customer_api_send_error('server', 'Please try again in a moment.', 500);
}

customer_api_send_error('not_found', 'Not found', 404);
