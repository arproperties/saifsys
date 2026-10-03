<?php
/**
 * Building Inventory — show one item's photo.
 * The photo is kept in Reem. This page asks Reem for it as the person logged in here,
 * so Reem decides whether they may see it, and passes the picture on.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/binv_helper.php';

require_login(get_application_web_root() . '/login');
$binv = binv_boot($conn);

try {
    [$bytes, $type] = binv_reem($binv['code'], 'GET',
        '/' . (int)($_GET['building'] ?? 0) . '/items/' . (int)($_GET['id'] ?? 0) . '/photo', null, null, true, 30);
} catch (BinvError $e) {
    http_response_code(404);
    exit('Not found');
}

// Only ever served as a picture: the type must be one of the three Reem stores,
// whatever header came back.
if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true) || $bytes === '') {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: inline; filename="item-' . (int)($_GET['id'] ?? 0) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
echo $bytes;
