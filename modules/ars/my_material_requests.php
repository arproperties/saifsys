<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_request_links.php';
require_once __DIR__ . '/../../includes/inventory/inv_material_requests_for_modules.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';

require_login();
require_module_access($conn, MODULE_ARS);

if (!has_permission('inventory_requests.create', MODULE_INVENTORY, $conn) || !inv_user_can_access_material_request_create($conn, 'ars')) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$userId = (int)current_user_id();
$rows = $companyId && $userId ? inv_material_requests_fetch_for_user($conn, $companyId, $userId, 'ars') : [];
$detailPage = 'material_request_view.php';

$pageTitle = 'My material requests';
ars_shell_begin([
    'title' => 'My material requests',
    'subtitle' => 'Company scope: ARS #' . $companyId . ' · ' . count($rows) . ' request' . (count($rows) === 1 ? '' : 's'),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'My Material Requests'],
    ],
    'actions_html' => '<a class="btn btn-ars-outline btn-sm" href="bookings.php">Back to bookings</a>',
    'legacy_bootstrap' => true,
]);
?>
<div class="container-fluid px-2 px-lg-4" style="max-width:1100px;">
  <div class="ars-card">
    <div class="card-body">
      <?php require __DIR__ . '/../../includes/inventory/partials/material_requests_list_table.php'; ?>
    </div>
  </div>
</div>
<?php ars_shell_end(); ?>
