<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/inventory/inv_request_create_controller.php';

require_login();
$hasAccess = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_CORE, $conn)
    || has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_PROJECTS, $conn);
if (!$hasAccess) {
    require_module_access($conn, MODULE_CONSTRUCTION);
}

$v = inv_request_create_bootstrap($conn, 'construction');
extract($v);

require_once __DIR__ . '/../../includes/url_helper.php';
$appBase = get_application_web_root();
$pid = (int)($_GET['project_id'] ?? 0);
if ($pid) {
    $backUrl = ($appBase !== '' ? $appBase : '') . '/modules/construction/project_view.php?id=' . $pid;
    $backLabel = 'Back to project';
} else {
    $backUrl = ($appBase !== '' ? $appBase : '') . '/modules/construction/projects.php';
    $backLabel = 'Projects';
}
$pageTitle = 'New material request';

require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="container-fluid px-2" style="max-width:1100px;">
  <?php require __DIR__ . '/../inventory/includes/material_request_form.php'; ?>
</div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
