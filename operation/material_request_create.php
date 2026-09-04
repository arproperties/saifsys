<?php
/**
 * Cleaning — New material request (stays in Operations UI; posts to shared inventory logic).
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
require_once __DIR__ . '/../includes/inventory/inv_request_create_controller.php';

require_login();
if (!has_department_access(MODULE_CLEANING, DEPT_CLEANING_OPERATIONS, $conn)) {
    require_module_access($conn, MODULE_CLEANING);
}

$currentCompanyId = current_company_id($conn);
if (!$currentCompanyId) {
    $userCompanies = get_user_companies($conn, current_user_id());
    if (!empty($userCompanies)) {
        foreach ($userCompanies as $company) {
            if ($company['business_type'] === 'cleaning') {
                set_current_company($company['id']);
                break;
            }
        }
    }
}

$brand = getBrandSettings($conn);

$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$hasLegacy = !empty($_SESSION['username']) || !empty($_SESSION['fullname']);
$U = $hasUserObject ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (!isset($_GET['source_module']) || $_GET['source_module'] === '') {
    $_GET['source_module'] = 'cleaning';
}

$v = inv_request_create_bootstrap($conn, 'cleaning');
extract($v);

$appBase = get_application_web_root();
$backUrl = ($appBase !== '' ? $appBase : '') . '/operation';
$backLabel = 'Back to operation';
$pageTitle = 'Material request';
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
      <?php require __DIR__ . '/../modules/inventory/includes/material_request_form.php'; ?>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const sb = document.getElementById('sb');
  const t = document.getElementById('sbToggle');
  if (sb && t) t.addEventListener('click', () => {
    sb.classList.toggle('collapsed');
    const i = t.querySelector('i');
    if (i) { i.classList.toggle('bi-chevron-right'); i.classList.toggle('bi-chevron-left'); }
  });
</script>
</body>
</html>
