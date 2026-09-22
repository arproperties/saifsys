<?php
/**
 * Driver app — web version (PWA), for iPhones and for phones the APK will not
 * install on. Same PIN, same API (api/mobile/fleet), same trips as the APK.
 *
 * Open /driver/ on the phone, then "Add to Home Screen".
 *
 * One limit the APK does not have: a web page cannot record GPS with the
 * screen locked or another app in front. The page keeps the screen on while a
 * trip runs and says so when recording has paused.
 *
 * The app key is printed into the page, exactly as the APK carries it inside
 * the build. It says "this is our app", not who is driving — the PIN and its
 * lockout do that.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';

$appKey = getenv('OPS_MOBILE_APP_KEY') ?: (defined('OPS_MOBILE_APP_KEY') ? (string)OPS_MOBILE_APP_KEY : '');
$version = '1.0.0';
$asset = static fn(string $file): string => $file . '?v=' . (@filemtime(__DIR__ . '/' . $file) ?: $version);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Driver</title>
<meta name="theme-color" content="#0E2038">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Driver">
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
<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
<script>
window.DRIVER_CONFIG = <?= json_encode([
    'apiBase' => '../api/mobile/fleet',
    'appKey' => $appKey,
    'version' => $version,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= htmlspecialchars($asset('app.js')) ?>"></script>
</body>
</html>
