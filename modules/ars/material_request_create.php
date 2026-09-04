<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/inventory/inv_request_create_controller.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';

$arsCompanyId = arsPageAuth($conn);
$v = inv_request_create_bootstrap($conn, 'ars');
extract($v);

require_once __DIR__ . '/../../includes/url_helper.php';
$appBase = get_application_web_root();
$backUrl = ($appBase !== '' ? $appBase : '') . '/modules/ars/bookings.php';
$backLabel = 'Bookings';
$pageTitle = 'New material request';

ars_shell_begin([
    'title' => 'New material request',
    'subtitle' => 'Company scope: ARS #' . $arsCompanyId . ' · Inventory request from ARS',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Reservations', 'href' => 'bookings.php'],
        ['label' => 'New Material Request'],
    ],
    'legacy_bootstrap' => true,
]);
?>
<div class="container-fluid px-2" style="max-width:1100px;">
  <?php require __DIR__ . '/../inventory/includes/material_request_form.php'; ?>
</div>
<?php ars_shell_end(); ?>
