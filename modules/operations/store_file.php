<?php
// modules/operations/store_file.php?item= | ?move= — the photo of a store item, or of a goods-in
// entry. Same access as Operations → Store. The files sit in uploads/store/, which the web
// server refuses to serve.

require_once __DIR__ . '/includes/ops_store_page.php';
session_write_close();

$file = null;
if ($stReady) {
    $file = isset($_GET['item'])
        ? st_photo($conn, $stWho, 'item', (int)$_GET['item'])
        : st_photo($conn, $stWho, 'move', (int)($_GET['move'] ?? 0));
}
if ($file === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
st_send_file($file);
