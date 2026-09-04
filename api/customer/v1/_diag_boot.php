<?php
/**
 * TEMPORARY live boot diagnostic — delete after use.
 * Open: /api/customer/v1/_diag_boot.php
 * Shows which require fails (Customer API index.php returns empty 500 on live).
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$steps = [
    'db_connect' => __DIR__ . '/../../../includes/db_connect.php',
    'customer_api' => __DIR__ . '/../../../includes/customer_api.php',
    'ars_helpers' => __DIR__ . '/../../../modules/ars/includes/ars_helpers.php',
    'ars_availability' => __DIR__ . '/../../../modules/ars/includes/ars_availability.php',
    'ars_pricing' => __DIR__ . '/../../../modules/ars/includes/ars_pricing.php',
    'ars_stripe' => __DIR__ . '/../../../modules/ars/includes/ars_stripe.php',
    'ars_deposit' => __DIR__ . '/../../../modules/ars/includes/ars_deposit.php',
    'ars_guest_notifications' => __DIR__ . '/../../../modules/ars/includes/ars_guest_notifications.php',
    'ars_booking_requests' => __DIR__ . '/../../../modules/ars/includes/ars_booking_requests.php',
    'stay_endpoints' => __DIR__ . '/stay_endpoints.php',
    'guest_endpoints' => __DIR__ . '/guest_endpoints.php',
    'guest_documents' => __DIR__ . '/guest_documents.php',
    'tenant_endpoints' => __DIR__ . '/tenant_endpoints.php',
    'tenant_services_endpoints' => __DIR__ . '/tenant_services_endpoints.php',
    'tenant_extra_services_endpoints' => __DIR__ . '/tenant_extra_services_endpoints.php',
    'tenant_documents_endpoints' => __DIR__ . '/tenant_documents_endpoints.php',
    'tenant_renewals_endpoints' => __DIR__ . '/tenant_renewals_endpoints.php',
    'tenant_notifications_endpoints' => __DIR__ . '/tenant_notifications_endpoints.php',
    'customer_push_endpoints' => __DIR__ . '/customer_push_endpoints.php',
    'app_config_endpoints' => __DIR__ . '/app_config_endpoints.php',
    'app_mobile_config_helper' => __DIR__ . '/../../../includes/app_mobile_config_helper.php',
];

echo "Customer API boot diagnostic\n";
echo "PHP " . PHP_VERSION . "\n\n";

foreach ($steps as $label => $path) {
    echo "→ $label ... ";
    if (!is_file($path)) {
        echo "MISSING FILE: $path\n";
        exit(1);
    }
    try {
        require_once $path;
        echo "OK\n";
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
        echo $e->getFile() . ':' . $e->getLine() . "\n";
        exit(1);
    }
}

echo "\nAll requires OK.\n";
if (isset($conn) && $conn instanceof PDO) {
    echo "PDO connected.\n";
    try {
        if (function_exists('app_mobile_config_public_payload')) {
            $p = app_mobile_config_public_payload($conn);
            echo "app-config payload ok, keys: " . implode(',', array_keys($p)) . "\n";
        }
    } catch (Throwable $e) {
        echo "app-config payload FAILED: " . $e->getMessage() . "\n";
    }
}
echo "DONE\n";
