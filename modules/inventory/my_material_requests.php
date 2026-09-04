<?php
/**
 * Inventory — Current user's material requests (source = inventory).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_material_requests_for_modules.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_requests.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$userId = (int)current_user_id();
$rows = $companyId && $userId ? inv_material_requests_fetch_for_user($conn, $companyId, $userId, 'inventory') : [];

require_once __DIR__ . '/../../includes/url_helper.php';
$appBase = get_application_web_root();
$detailPage = ($appBase !== '' ? $appBase : '') . '/modules/inventory/request_view.php';

$pageTitle = 'My material requests';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="page-header-label">My material requests</div>
    <div class="text-muted small">Requests you created from Inventory (new request screen).</div>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="requests.php">All requests</a>
</div>
<div class="card p-3">
  <?php
  $showRequestedBy = false;
  require __DIR__ . '/../../includes/inventory/partials/material_requests_list_table.php';
  ?>
</div>
<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
