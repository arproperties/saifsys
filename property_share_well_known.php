<?php
/**
 * Dynamic Digital Asset Links / Apple App Site Association from ERP Mobile App Management.
 * Served at:
 *   /.well-known/assetlinks.json
 *   /.well-known/apple-app-site-association
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/app_mobile_config_helper.php';

$type = strtolower(trim((string)($_GET['type'] ?? '')));
if ($type === '' && !empty($_SERVER['REQUEST_URI'])) {
    if (str_contains((string)$_SERVER['REQUEST_URI'], 'assetlinks')) {
        $type = 'assetlinks';
    } elseif (str_contains((string)$_SERVER['REQUEST_URI'], 'apple-app-site-association')) {
        $type = 'aasa';
    }
}

$cfg = app_mobile_config_get($conn);

header('Cache-Control: public, max-age=300');

if ($type === 'assetlinks') {
    header('Content-Type: application/json; charset=utf-8');
    $package = trim((string)($cfg['android_package_name'] ?? 'com.ainalreem.living'));
    $raw = (string)($cfg['android_sha256_fingerprints'] ?? '');
    $fps = preg_split('/[\s,;]+/', $raw) ?: [];
    $fps = array_values(array_filter(array_map('trim', $fps), static fn($v) => $v !== ''));
    $payload = [[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => [
            'namespace' => 'android_app',
            'package_name' => $package,
            'sha256_cert_fingerprints' => $fps,
        ],
    ]];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($type === 'aasa') {
    // Apple requires no file extension and application/json (or pkcs7).
    header('Content-Type: application/json; charset=utf-8');
    $team = trim((string)($cfg['ios_team_id'] ?? ''));
    $bundle = trim((string)($cfg['ios_bundle_id'] ?? 'com.ainalreem.living'));
    $appId = ($team !== '' ? $team . '.' : '') . $bundle;
    $payload = [
        'applinks' => [
            'apps' => [],
            'details' => [[
                'appID' => $appId,
                'paths' => ['/p/*', '/*/p/*'],
            ]],
        ],
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
exit;
