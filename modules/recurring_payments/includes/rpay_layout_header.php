<?php
/**
 * Recurring Payments — page shell.
 * A slim copy of the Building Inventory one: same style kit, so branding, dark mode
 * and the collapsible sidebar behave identically. The footer is Building Inventory's.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/rpay_helper.php';
if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}
require_once dirname(__DIR__, 3) . '/includes/url_helper.php';

$appBase = get_application_web_root();
$rpayBase = $appBase . '/modules/recurring_payments';
$currentPage = basename($_SERVER['PHP_SELF']);

$U = !empty($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

$rpayFlash = binv_take_flash();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title><?= isset($pageTitle) ? h($pageTitle) . ' | ' : '' ?>Recurring Payments | <?= h($brand['system_name']) ?></title>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <!-- Searchable dropdowns — same Select2 build the operations module uses. -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
  <?php require dirname(__DIR__, 3) . '/operation/includes/cleaning_ui_styles.php'; ?>
  <style>
    /* cleaning_ui_styles sets .card-round{border:0}, which also drops Bootstrap's card border.
       Put it back at Bootstrap's own strength, or white cards vanish into the #f6f7f9 page. */
    .card-round{ border:1px solid rgba(0,0,0,.175); }
    .card-round > .table-responsive{ border-radius:inherit; }
    .card-round > .table-responsive > .table{ margin-bottom:0; }
    /* Bootstrap's stock control border (#dee2e6) is near-invisible on white. */
    .form-control, .form-select{ border:1px solid rgba(0,0,0,.25); }
    .form-control:focus, .form-select:focus{
      border-color:var(--primary);
      box-shadow:0 0 0 .2rem rgba(0,0,0,.12); /* fallback where color-mix is unsupported */
      box-shadow:0 0 0 .2rem color-mix(in srgb, var(--primary) 20%, transparent);
    }
    .input-group > .form-control, .input-group > .form-select{ border-color:rgba(0,0,0,.25); }
    .select2-container--bootstrap-5 .select2-selection{ border:1px solid rgba(0,0,0,.25); }
    .select2-container--bootstrap-5.select2-container--focus .select2-selection,
    .select2-container--bootstrap-5.select2-container--open .select2-selection{ border-color:var(--primary); }
    .select2-container{ width:100% !important; }
    body.dark-mode .select2-container--bootstrap-5 .select2-selection{
      background:rgba(15,23,42,.6); color:#e4e4e7; border-color:#475569;
    }
    body.dark-mode .select2-container--bootstrap-5 .select2-selection__rendered{ color:#e4e4e7; }
    body.dark-mode .select2-dropdown{ background:#1e293b; color:#e4e4e7; border-color:#475569; }
    body.dark-mode .select2-container--bootstrap-5 .select2-search__field{
      background:rgba(15,23,42,.6); color:#e4e4e7; border-color:#475569;
    }
    body.dark-mode .form-control, body.dark-mode .form-select{
      background:rgba(15,23,42,.6); color:#e4e4e7; border-color:#475569;
    }
    body.dark-mode .card-round{ background:rgba(30,41,59,.95); color:#e4e4e7; border-color:#334155; }

    /* The lists: quiet uppercase headings, roomy rows, hairlines between rows only. */
    .rpay-table{ margin-bottom:0; }
    .rpay-table > thead > tr > th{
      background:transparent; border-bottom:1px solid rgba(0,0,0,.12);
      font-size:.7rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
      color:#8a9099; padding:.55rem .9rem; white-space:nowrap;
    }
    .rpay-table > tbody > tr > td{
      padding:.6rem .9rem; vertical-align:middle; border-top:1px solid rgba(0,0,0,.06); border-bottom:0;
    }
    .rpay-table > tbody > tr:first-child > td{ border-top:0; }
    .rpay-table > tbody > tr:hover{ background:rgba(0,0,0,.018); }
    .rpay-table .num{ font-variant-numeric:tabular-nums; white-space:nowrap; }
    .rpay-total{ font-size:1.5rem; font-weight:700; font-variant-numeric:tabular-nums; }
    body.dark-mode .rpay-table > thead > tr > th{ color:#94a3b8; border-bottom-color:#334155; }
    body.dark-mode .rpay-table > tbody > tr > td{ border-top-color:rgba(255,255,255,.07); }
    body.dark-mode .rpay-table > tbody > tr:hover{ background:rgba(255,255,255,.03); }
    @media (max-width: 767.98px){ .sidebar{ display:none; } }
  </style>
  <?php if (!empty($pageStyles)): ?><style><?= $pageStyles ?></style><?php endif; ?>
</head>
<body<?= $brand['dark_mode_enabled'] ? ' class="dark-mode"' : '' ?>>
<?php
// Check-out bar for staff who have checked in. Prints nothing when the
// feature is off or the person has no attendance to record.
$asWidget = dirname(__DIR__, 3) . '/includes/attendance_self_widget.php';
if (is_file($asWidget)) { require $asWidget; }
?>
<div class="d-flex">

  <aside id="sb" class="sidebar d-flex flex-column p-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <?php if (!empty($brand['logo_path']) && file_exists(dirname(__DIR__, 3) . '/' . $brand['logo_path'])): ?>
        <img src="<?= h($appBase . '/' . $brand['logo_path']) ?>" alt="<?= h($brand['system_name']) ?>" style="width:40px;height:40px;object-fit:contain;border-radius:8px;">
      <?php endif; ?>
      <span class="brand ms-1"><?= h($brand['system_name']) ?></span>
    </div>
    <div class="collapse-btn" id="sbToggle" title="Collapse/Expand"><i class="bi bi-chevron-left"></i></div>

    <div class="nav-sect mt-3">Recurring Payments</div>
      <a href="<?= h($rpayBase) ?>/index.php" class="slink <?= $currentPage === 'index.php' ? 'active' : '' ?>">
        <span class="sicon"><i class="bi bi-cash-coin"></i></span><span class="slabel">Payments</span>
      </a>
      <a href="<?= h($rpayBase) ?>/entries.php" class="slink <?= in_array($currentPage, ['entries.php', 'entry_form.php'], true) ? 'active' : '' ?>">
        <span class="sicon"><i class="bi bi-arrow-repeat"></i></span><span class="slabel">Entries</span>
      </a>

    <div class="nav-sect">Other</div>
    <a href="<?= h($appBase) ?>/select-module.php" class="slink"><span class="sicon"><i class="bi bi-grid"></i></span><span class="slabel">Modules</span></a>
  </aside>

  <!-- Main content -->
  <div class="flex-grow-1" style="min-width:0">
    <nav class="navbar navbar-expand navbar-light bg-white shadow-sm">
      <div class="container-fluid">
        <span class="navbar-brand fw-bold" style="color:var(--primary)"><?= h($brand['system_name']) ?></span>

        <div class="dropdown ms-auto">
          <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <div class="avatar me-2"><?= h($avatarInitial) ?></div>
            <span class="me-2 d-none d-sm-inline"><?= h($fullName) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><span class="dropdown-item-text"><strong><?= h($userName) ?></strong></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= h($appBase) ?>/profile"><i class="bi bi-person-circle me-2"></i>Edit/Update</a></li>
            <li><a class="dropdown-item text-danger" href="<?= h($appBase) ?>/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </nav>

    <div class="container-fluid px-3 px-lg-4 py-4">
      <?php if ($rpayFlash): ?>
        <div class="alert alert-<?= h($rpayFlash['type']) ?> alert-dismissible fade show" role="alert">
          <?= h($rpayFlash['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>
