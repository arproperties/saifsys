<?php
/**
 * Tenant Portal — Switch active lease (multi-lease support)
 */
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);

$lease_id = (int)($_POST['lease_id'] ?? $_GET['lease_id'] ?? 0);
$allowed = current_tenant_lease_ids($conn);
if ($lease_id && in_array($lease_id, $allowed, true)) {
    $_SESSION['tenant_portal_lease_id'] = $lease_id;
}
header('Location: dashboard.php');
exit;
