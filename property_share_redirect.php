<?php
/**
 * Minimal share link host — NOT a property landing/details page.
 * GET /p/{share_code}
 *
 * - If share is available and request looks like an in-app / bot verification: still no HTML details.
 * - Browser without app: 302 to Play Store / App Store (ERP Mobile App Management URLs).
 * - Unavailable codes: still 302 to store (V1) — app handles friendly unavailable when installed.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/app_mobile_config_helper.php';
require_once __DIR__ . '/includes/property_share_helper.php';

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' && !empty($_SERVER['REQUEST_URI'])) {
    if (preg_match('#/p/([A-Za-z0-9\-]+)#', (string)$_SERVER['REQUEST_URI'], $m)) {
        $code = $m[1];
    }
}

$cfg = app_mobile_config_get($conn);
$ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$isIos = str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod');
$isAndroid = str_contains($ua, 'android');

$storeUrl = '';
if ($isIos) {
    $storeUrl = trim((string)($cfg['app_store_url'] ?? ''));
} elseif ($isAndroid) {
    $storeUrl = trim((string)($cfg['play_store_url'] ?? ''));
}
if ($storeUrl === '') {
    $storeUrl = trim((string)($cfg['play_store_url'] ?? ''))
        ?: trim((string)($cfg['app_store_url'] ?? ''));
}

// Soft-touch resolve so dead codes don't 500 — still no property HTML.
if ($code !== '') {
    try {
        re_property_share_resolve($conn, $code);
    } catch (Throwable $e) {
        error_log('property_share_redirect resolve: ' . $e->getMessage());
    }
}

if ($storeUrl !== '') {
    header('Location: ' . $storeUrl, true, 302);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);
echo "Open this link on a device with Ain Al Reem Living installed.";
exit;
