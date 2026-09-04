<?php
/**
 * Public customer app runtime config (versions / maintenance / flags).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/app_mobile_config_helper.php';

function customer_api_handle_app_config(PDO $conn): void
{
    $payload = app_mobile_config_public_payload($conn);

    // Lightweight + cacheable (public, no auth).
    header('Cache-Control: public, max-age=60');
    header('Vary: Accept-Encoding');
    customer_api_send_ok($payload);
}
