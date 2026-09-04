<?php
/**
 * Tenant Portal — Entry point
 * Redirect to dashboard if logged-in tenant, else to login.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

if (is_tenant_user($conn)) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
