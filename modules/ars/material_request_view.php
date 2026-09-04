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
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/../../includes/inventory/inv_material_requests_for_modules.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';

require_login();
require_module_access($conn, MODULE_ARS);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$userId = (int)current_user_id();
$requestId = (int)($_GET['id'] ?? 0);

$bundle = $companyId && $requestId ? inv_material_request_load_detail($conn, $companyId, $requestId) : null;
if (!$bundle) {
    header('Location: my_material_requests.php');
    exit;
}
$req = $bundle['req'];
if (!inv_material_request_can_view_in_module($conn, $req, $userId, 'ars')) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$lines = $bundle['lines'];
$labels = $bundle['labels'];
$titles = $bundle['titles'];
$locationLabel = $bundle['location_label'];

$appBase = get_application_web_root();
$inventoryIssueDocUrl = null;
if (!empty($req['issue_doc_id']) && has_permission('inventory_requests.view', MODULE_INVENTORY, $conn)) {
    $inventoryIssueDocUrl = ($appBase !== '' ? $appBase : '') . '/modules/inventory/document_edit.php?id=' . (int)$req['issue_doc_id'];
}

$pageTitle = 'Material request ' . ($req['request_no'] ?? '');
$materialRequestActions = '';
if (has_permission('inventory_requests.view', MODULE_INVENTORY, $conn)) {
    $materialRequestActions .= '<a class="btn btn-ars btn-sm" href="'
        . h(($appBase !== '' ? $appBase : '') . '/modules/inventory/request_view.php?id=' . $requestId)
        . '">Open in Inventory</a>';
}
$materialRequestActions .= '<a class="btn btn-ars-outline btn-sm" href="my_material_requests.php">My requests</a>';
ars_shell_begin([
    'title' => $pageTitle,
    'subtitle' => 'Company scope: ARS #' . $companyId . ' · Request date: ' . ($req['request_date'] ?? '—'),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'My Material Requests', 'href' => 'my_material_requests.php'],
        ['label' => $req['request_no'] ?? 'Request'],
    ],
    'actions_html' => $materialRequestActions,
    'legacy_bootstrap' => true,
]);
?>
<div class="container-fluid px-2 px-lg-4" style="max-width:900px;">
  <div class="mb-3">
    <span class="badge bg-<?= htmlspecialchars(inv_material_request_status_badge_class((string)($req['status'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $req['status'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
  </div>
  <?php require __DIR__ . '/../../includes/inventory/partials/material_request_detail_body.php'; ?>
</div>
<?php ars_shell_end(); ?>
