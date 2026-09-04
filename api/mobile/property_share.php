<?php
/**
 * Public property share resolve API.
 * GET /api/mobile/property-share/{share_code}
 *
 * Never returns listing details for unavailable codes — only available|unavailable metadata.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../includes/property_share_helper.php';

header('Cache-Control: public, max-age=30');

function property_share_route(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $marker = 'property-share';
    $pos = strpos($uri, $marker);
    if ($pos === false) {
        return trim((string)($_GET['code'] ?? ''), '/');
    }
    $rest = substr($uri, $pos + strlen($marker));
    return trim($rest, '/');
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        errorResponse('Method not allowed', 405);
    }

    $code = property_share_route();
    if ($code === '') {
        errorResponse('share_code required', 400);
    }

    $result = re_property_share_resolve($conn, $code);

    // Strip empty message noise for available responses.
    if (!empty($result['available'])) {
        unset($result['message'], $result['reason']);
    }

    successResponse($result);
} catch (Throwable $e) {
    error_log('property_share API error: ' . $e->getMessage());
    errorResponse('Server error', 500);
}
