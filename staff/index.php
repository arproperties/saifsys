<?php
/**
 * Staff app — web version (PWA). One app for field staff: after the PIN, the
 * person picks Driver or Cleaning, and each works exactly as its own app does.
 *
 *   Driver    same API, trips and rules as /driver/ and DRIVER-MOBILE-APP
 *             (api/mobile/fleet)
 *   Cleaning  same API, jobs and rules as OPERATION-MOBILE-APP
 *             (api/mobile/ops)
 *
 * One PIN signs in to both through api/mobile/staff/signin.php, which hands
 * back a separate token for each app the person may use. Someone with only one
 * of them goes straight into it.
 *
 * Open /staff/ on the phone, then "Add to Home Screen". The APKs and /driver/
 * carry on working alongside it.
 *
 * The app key is printed into the page, exactly as the APKs carry it inside
 * the build. It says "this is our app", not who is holding the phone — the PIN
 * and its lockout do that.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';

$appKey = getenv('OPS_MOBILE_APP_KEY') ?: (defined('OPS_MOBILE_APP_KEY') ? (string)OPS_MOBILE_APP_KEY : '');
// Dev only: point a local copy of the page at another server's API (the live API allows cross-origin calls).
$apiRoot = rtrim(getenv('STAFF_API_ROOT') ?: '../api/mobile', '/');
$version = '1.0.0';
$asset = static fn(string $file): string => $file . '?v=' . (@filemtime(__DIR__ . '/' . $file) ?: $version);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Staff</title>
<meta name="theme-color" content="#0E2038">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Staff">
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" href="icon-192.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars($asset('app.css')) ?>">
</head>
<body>
<div id="app"></div>
<div id="sheet-root"></div>
<div id="viewer-root"></div>
<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
<script>
window.STAFF_CONFIG = <?= json_encode([
    'signinUrl' => $apiRoot . '/staff/signin.php',
    'opsBase' => $apiRoot . '/ops',
    'fleetBase' => $apiRoot . '/fleet',
    'appKey' => $appKey,
    'version' => $version,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= htmlspecialchars($asset('js/core.js')) ?>"></script>
<script src="<?= htmlspecialchars($asset('js/driver.js')) ?>"></script>
<script src="<?= htmlspecialchars($asset('js/cleaning.js')) ?>"></script>
<script src="<?= htmlspecialchars($asset('js/app.js')) ?>"></script>
</body>
</html>
