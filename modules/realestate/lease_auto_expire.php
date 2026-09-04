<?php
/**
 * Real Estate Module - Auto Expire Leases Script
 * This script can be run manually or via cron job to automatically update expired leases
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/lease_helper.php';

// Allow running from command line or web
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    // Web access - require authentication
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/module_access.php';
    require_login();
    require_module_access($conn, MODULE_REALESTATE);
}

// Run auto expiry check
$result = auto_check_lease_expiry($conn);

if ($isCli) {
    // CLI output
    echo "Auto Expiry Check Complete\n";
    echo "Updated leases: {$result['updated_count']}\n";
} else {
    // Web output
    header('Content-Type: application/json');
    echo json_encode($result);
}

