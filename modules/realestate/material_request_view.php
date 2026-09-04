<?php
/**
 * Real Estate — Read-only material request detail (requester / viewers with access).
 */
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

require_login();
require_module_access($conn, MODULE_REALESTATE);

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
if (!inv_material_request_can_view_in_module($conn, $req, $userId, 'realestate')) {
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
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="container-fluid px-2 px-md-3" style="max-width:900px;">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars($req['request_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="text-muted small mb-0">
        <span class="badge bg-<?= htmlspecialchars(inv_material_request_status_badge_class((string)($req['status'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($req['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        · <?= htmlspecialchars($req['request_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>
      </p>
    </div>
    <div class="d-flex gap-2">
      <?php if (has_permission('inventory_requests.view', MODULE_INVENTORY, $conn)): ?>
        <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars(($appBase !== '' ? $appBase : '') . '/modules/inventory/request_view.php?id=' . $requestId, ENT_QUOTES, 'UTF-8') ?>">Open in Inventory</a>
      <?php endif; ?>
      <a class="btn btn-sm btn-outline-secondary" href="my_material_requests.php">My requests</a>
    </div>
  </div>
  <?php require __DIR__ . '/../../includes/inventory/partials/material_request_detail_body.php'; ?>
</div>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
