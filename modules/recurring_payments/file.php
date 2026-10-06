<?php
/**
 * Recurring Payments — show the file kept with a paid line or with a transfer.
 * The file is in Reem. This page asks Reem for it as the person logged in here and
 * passes it on.
 *
 *   file.php?kind=due&id=12
 *   file.php?kind=transfer&id=3
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/rpay_helper.php';

require_login(get_application_web_root() . '/login');
$code = rpay_boot($conn);

$id = (int)($_GET['id'] ?? 0);
$of = ($_GET['kind'] ?? '') === 'transfer' ? '/transfers/' : '/dues/';

try {
    [$bytes, $type] = binv_reem($code, 'GET', $of . $id . '/attachment', null, null, true, 30, RPAY_REEM_PATH);
} catch (BinvError $e) {
    http_response_code(404);
    exit('Not found');
}

// Only ever served as one of the types Reem keeps, whatever header came back.
if (!in_array($type, RPAY_FILE_TYPES, true) || $bytes === '') {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: inline; filename="attachment-' . $id . ($type === 'application/pdf' ? '.pdf' : '') . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
echo $bytes;
