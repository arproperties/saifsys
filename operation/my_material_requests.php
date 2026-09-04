<?php
/**
 * Cleaning / Operations — My material requests.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/url_helper.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../includes/module_access.php';
require_once __DIR__ . '/../includes/rbac_department.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/inventory/inv_request_links.php';
require_once __DIR__ . '/../includes/inventory/inv_material_requests_for_modules.php';

require_login();
if (!has_department_access(MODULE_CLEANING, DEPT_CLEANING_OPERATIONS, $conn)) {
    require_module_access($conn, MODULE_CLEANING);
}

$currentCompanyId = current_company_id($conn);
if (!$currentCompanyId) {
    $userCompanies = get_user_companies($conn, current_user_id());
    if (!empty($userCompanies)) {
        foreach ($userCompanies as $company) {
            if (($company['business_type'] ?? '') === 'cleaning') {
                set_current_company($company['id']);
                break;
            }
        }
    }
}

if (!has_permission('inventory_requests.create', MODULE_INVENTORY, $conn) || !inv_user_can_access_material_request_create($conn, 'cleaning')) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$userId = (int)current_user_id();
$rows = $companyId && $userId ? inv_material_requests_fetch_for_user($conn, $companyId, $userId, 'cleaning') : [];
$detailPage = 'material_request_view.php';

$appBase = get_application_web_root();
$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$U = $hasUserObject ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'My material requests';
$activeNav = 'material_request';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?> | <?= h($brand['system_name']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <?php require __DIR__ . '/includes/cleaning_ui_styles.php'; ?>
</head>
<body<?= !empty($brand['dark_mode_enabled']) ? ' class="dark-mode"' : '' ?>>
<div class="d-flex">
<?php require __DIR__ . '/includes/cleaning_ui_sidebar.php'; ?>
  <div class="flex-grow-1">
    <nav class="navbar navbar-expand navbar-light bg-white shadow-sm">
      <div class="container-fluid">
        <span class="navbar-brand fw-bold" style="color:var(--primary)"><?= h($brand['system_name']) ?></span>
        <div class="dropdown ms-auto">
          <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <div class="avatar me-2"><?= h($avatarInitial) ?></div>
            <span class="me-2"><?= h($fullName) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><span class="dropdown-item-text"><strong><?= h($userName) ?></strong></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= h($appBase ?: '') ?>/profile">Profile</a></li>
            <li><a class="dropdown-item text-danger" href="<?= h($appBase ?: '') ?>/logout">Logout</a></li>
          </ul>
        </div>
      </div>
    </nav>
    <div class="container-fluid py-4 px-3 px-lg-4" style="max-width:1100px;">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
          <h1 class="h5 mb-0" style="color:var(--primary)">My material requests</h1>
          <p class="text-muted small mb-0">Requests you submitted from Cleaning / Operations.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h($appBase ?: '') ?>/operation">Back</a>
      </div>
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <?php require __DIR__ . '/../includes/inventory/partials/material_requests_list_table.php'; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
