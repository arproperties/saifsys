<?php
/**
 * Real Estate — New material request (module shell; shared inventory logic).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/inventory/inv_request_create_controller.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$v = inv_request_create_bootstrap($conn, 'realestate');
extract($v);

require_once __DIR__ . '/../../includes/url_helper.php';
$appBase = get_application_web_root();
$backUrl = ($appBase !== '' ? $appBase : '') . '/modules/realestate/maintenance.php';
$backLabel = 'Maintenance';
$pageTitle = 'New material request';

require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="container-fluid px-2 px-md-3" style="max-width:1100px;">
  <?php require __DIR__ . '/../inventory/includes/material_request_form.php'; ?>
</div>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
