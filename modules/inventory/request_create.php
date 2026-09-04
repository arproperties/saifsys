<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/inventory/inv_request_create_controller.php';

require_login();

$v = inv_request_create_bootstrap($conn, 'inventory');
extract($v);

$pageTitle = 'New material request';
$backUrl = 'requests.php';
$backLabel = 'Back to list';

require_once __DIR__ . '/includes/inv_layout_header.php';
?>
<div class="container-fluid px-0" style="max-width:1100px;">
  <?php require __DIR__ . '/includes/material_request_form.php'; ?>
</div>
<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
