<?php
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
require_once __DIR__ . '/../../includes/inventory/inv_request_links.php';
require_once __DIR__ . '/../../includes/inventory/inv_material_requests_for_modules.php';

require_login();
$hasAccess = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_CORE, $conn)
    || has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_PROJECTS, $conn);
if (!$hasAccess) {
    require_module_access($conn, MODULE_CONSTRUCTION);
}

if (!has_permission('inventory_requests.create', MODULE_INVENTORY, $conn) || !inv_user_can_access_material_request_create($conn, 'construction')) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$userId = (int)current_user_id();
$rows = $companyId && $userId ? inv_material_requests_fetch_for_user($conn, $companyId, $userId, 'construction') : [];
$detailPage = 'material_request_view.php';

$pageTitle = 'My material requests';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h4 mb-0">My material requests</h1>
      <p class="text-muted small mb-0">Requests you submitted from Construction.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="projects.php">Back to projects</a>
  </div>
</div>
<div class="card card-round">
  <div class="card-body">
    <?php require __DIR__ . '/../../includes/inventory/partials/material_requests_list_table.php'; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
