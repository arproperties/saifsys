<?php
/**
 * Operations module — page shell.
 * Reuses the cleaning module's shared style kit so branding, dark mode and the
 * collapsible sidebar behave identically across both modules.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/ops_helper.php';
if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}
require_once dirname(__DIR__, 3) . '/includes/url_helper.php';
require_once dirname(__DIR__, 3) . '/includes/permissions.php';

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$currentPage = basename($_SERVER['PHP_SELF']);

$U = !empty($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

$opsCompanyName = '';
$opsCtxId = (int)(current_company_id($conn) ?: 0);
if ($opsCtxId) {
    $co = get_company($conn, $opsCtxId);
    $opsCompanyName = $co['name'] ?? '';
}
$opsEligibleCompanies = get_user_companies($conn, (int)current_user_id());
$opsFlash = ops_take_flash();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title><?= isset($pageTitle) ? h($pageTitle) . ' | ' : '' ?>Operations | <?= h($brand['system_name']) ?></title>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <!-- Searchable dropdowns — same Select2 build the realestate module uses. -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
  <?php require dirname(__DIR__, 3) . '/operation/includes/cleaning_ui_styles.php'; ?>
  <style>
    .nav-pills .nav-link.active{ background-color:var(--primary)!important; }
    /* cleaning_ui_styles sets .card-round{border:0}, which also drops Bootstrap's card border.
       Put it back at Bootstrap's own strength, or white cards vanish into the #f6f7f9 page. */
    .card-round{ border:1px solid rgba(0,0,0,.175); }
    .stat-tile{ border:1px solid rgba(0,0,0,.175); border-radius:16px; box-shadow:0 8px 24px rgba(0,0,0,.06); background:#fff; padding:1rem 1.15rem; }
    .table > :not(caption) > * > *{ border-bottom-color:rgba(0,0,0,.1); }
    /* A table paints square corners straight over the card's 16px radius, so the
       card reads as having its edges sliced off. The scroll box already clips
       (overflow-x:auto), so giving it the same curve makes the table follow it. */
    .card-round > .table-responsive{ border-radius:inherit; }
    .card-round > .table-responsive > .table{ margin-bottom:0; }
    /* Bootstrap's stock control border (#dee2e6) is near-invisible on white.
       Match the card weight so every field reads as a distinct box. */
    .form-control, .form-select{ border:1px solid rgba(0,0,0,.25); }
    .form-control:focus, .form-select:focus{
      border-color:var(--primary);
      box-shadow:0 0 0 .2rem rgba(0,0,0,.12); /* fallback where color-mix is unsupported */
      box-shadow:0 0 0 .2rem color-mix(in srgb, var(--primary) 20%, transparent);
    }
    .input-group > .form-control, .input-group > .form-select{ border-color:rgba(0,0,0,.25); }
    /* Select2 borrows Bootstrap's faint default border; match the weight the
       native controls above use, or the boxes read as two different controls. */
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
    .stat-tile .stat-value{ font-size:1.9rem; font-weight:700; line-height:1.1; color:var(--primary); }
    .stat-tile .stat-label{ color:#6c757d; font-size:.85rem; }
    body.dark-mode .stat-tile,
    body.dark-mode .card-round{ background:rgba(30,41,59,.95); color:#e4e4e7; border-color:#334155; }
    /* A data table that reads as one, rather than as Bootstrap's default rules.
       Quiet uppercase headings, roomy rows, hairline separators between rows
       only, and numbers that line up because they are tabular. */
    .ops-table{ margin-bottom:0; }
    .ops-table > thead > tr > th{
      background:transparent; border-bottom:1px solid rgba(0,0,0,.12);
      font-size:.7rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
      color:#8a9099; padding:.55rem .9rem; white-space:nowrap;
    }
    .ops-table > tbody > tr > td{
      padding:.7rem .9rem; vertical-align:middle; border-top:1px solid rgba(0,0,0,.06);
      border-bottom:0;
    }
    .ops-table > tbody > tr:first-child > td{ border-top:0; }
    .ops-table > tbody > tr:hover{ background:rgba(0,0,0,.018); }
    .ops-table .num{ font-variant-numeric:tabular-nums; }
    /* Right-align the last column: it is the timestamp, and a ragged right edge
       against the card border is what makes a table look unfinished. */
    .ops-table > thead > tr > th:last-child,
    .ops-table > tbody > tr > td:last-child{ text-align:right; }
    /* A quantity is the one number people scan for, so it gets a shape. */
    .ops-pill{
      display:inline-block; padding:.12rem .5rem; border-radius:999px;
      font-weight:700; font-size:.85rem; font-variant-numeric:tabular-nums;
    }
    .ops-pill-out{ background:rgba(220,53,69,.10); color:#b02a37; }
    .ops-pill-in { background:rgba(25,135,84,.12); color:#146c43; }
    body.dark-mode .ops-table > thead > tr > th{ color:#94a3b8; border-bottom-color:#334155; }
    body.dark-mode .ops-table > tbody > tr > td{ border-top-color:rgba(255,255,255,.07); }
    body.dark-mode .ops-table > tbody > tr:hover{ background:rgba(255,255,255,.03); }
    body.dark-mode .ops-pill-out{ background:rgba(220,53,69,.18); color:#ffa2ab; }
    body.dark-mode .ops-pill-in { background:rgba(25,135,84,.20); color:#8ce0b4; }

    /* The grid's twelfths land on 66/33 or 75/25, and neither is the split the
       materials tab wants. Two classes, applied beside col-12 so they still
       stack on a phone like any other column. */
    @media (min-width:992px){
      .ops-col-65{ flex:0 0 auto; width:65%; }
      .ops-col-35{ flex:0 0 auto; width:35%; }
    }

    /* Tabs across the top of a record.
       Bootstrap's own .nav-tabs draws folder tabs that only read correctly when
       the panel below them is a bordered box. Here the panes are free-standing
       rounded cards on a grey page, so the folder edge has nothing to join and
       the strip looks half-drawn. An underline instead: the active tab is the
       one carrying the accent, and the rule under the row is the join. */
    .ops-tabs{
      border-bottom:1px solid rgba(0,0,0,.12);
      gap:.15rem; flex-wrap:nowrap; overflow-x:auto; overflow-y:hidden;
      scrollbar-width:none;
    }
    .ops-tabs::-webkit-scrollbar{ display:none; }
    .ops-tabs .nav-link{
      border:0; border-bottom:3px solid transparent; border-radius:0;
      margin-bottom:-1px; padding:.6rem 1.1rem;
      font-weight:600; color:#6c757d; white-space:nowrap; background:none;
      display:inline-flex; align-items:center; gap:.4rem;
    }
    .ops-tabs .nav-link:hover{ color:var(--primary); border-bottom-color:rgba(0,0,0,.15); }
    .ops-tabs .nav-link.active{ color:var(--primary); background:none; border-bottom-color:var(--primary); }
    .ops-tabs .nav-link:focus-visible{ outline:2px solid var(--primary); outline-offset:-2px; }
    /* The count sits on the tab, so it has to go quiet when the tab is not the
       one you are on — otherwise three badges compete with the active accent. */
    .ops-tabs .nav-link .badge{ font-size:.72rem; font-weight:600; }
    .ops-tabs .nav-link:not(.active) .badge.bg-light{ opacity:.75; }
    body.dark-mode .ops-tabs{ border-bottom-color:#334155; }
    body.dark-mode .ops-tabs .nav-link{ color:#94a3b8; }
    body.dark-mode .ops-tabs .nav-link.active{ color:#e4e4e7; border-bottom-color:var(--primary); }

    /* By-person list: one line per person, capped so a long staff list scrolls
       inside its card instead of stretching the dashboard row. */
    .ops-byperson{ max-height:264px; overflow-y:auto; margin-right:-.25rem; padding-right:.25rem; }
    .ops-byperson-row{ display:flex; align-items:center; gap:.6rem; padding:.28rem 0; }
    .ops-byperson-name{
      flex:0 0 38%; max-width:38%; font-size:.85rem; font-weight:600;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .ops-byperson-bar{ flex:1 1 auto; height:8px; }
    .ops-byperson-count{ flex:0 0 auto; min-width:2.6rem; text-align:right; font-variant-numeric:tabular-nums; }
    @media (max-width: 767.98px){ .sidebar{ display:none; } }
  </style>
  <?php if (isset($pageHead)) { echo $pageHead; } ?>
</head>
<body<?= $brand['dark_mode_enabled'] ? ' class="dark-mode"' : '' ?>>
<div class="d-flex">

  <aside id="sb" class="sidebar d-flex flex-column p-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <?php if (!empty($brand['logo_path']) && file_exists(dirname(__DIR__, 3) . '/' . $brand['logo_path'])): ?>
        <img src="<?= h($appBase . '/' . $brand['logo_path']) ?>" alt="<?= h($brand['system_name']) ?>" style="width:40px;height:40px;object-fit:contain;border-radius:8px;">
      <?php endif; ?>
      <span class="brand ms-1"><?= h($brand['system_name']) ?></span>
    </div>
    <div class="collapse-btn" id="sbToggle" title="Collapse/Expand"><i class="bi bi-chevron-left"></i></div>

    <?php if ($opsCompanyName !== ''): ?>
      <div class="slabel small text-white-50 text-truncate mt-2 ms-1" title="<?= h($opsCompanyName) ?>"><?= h($opsCompanyName) ?></div>
    <?php endif; ?>

    <?php require_once dirname(__DIR__, 3) . '/includes/nav_search.php'; ?>
    <?= nav_search_box() ?>

    <div class="nav-sect mt-3">Operations</div>
      <a href="<?= h($opsBase) ?>/index.php" class="slink <?= in_array($currentPage, ['index.php', 'job_view.php', 'job_form.php'], true) ? 'active' : '' ?>">
        <span class="sicon"><i class="bi bi-speedometer2"></i></span><span class="slabel">Jobs &amp; progress</span>
      </a>
      <?php $opsLowStock = ops_low_stock_count($conn, (int)(current_company_id($conn) ?: 0)); ?>
      <a href="<?= h($opsBase) ?>/stock.php" class="slink <?= in_array($currentPage, ['stock.php', 'item_form.php'], true) ? 'active' : '' ?>">
        <span class="sicon"><i class="bi bi-boxes"></i></span>
        <span class="slabel">Stock
          <?php if ($opsLowStock > 0): ?>
            <span class="badge bg-danger ms-1"><?= (int)$opsLowStock ?></span>
          <?php endif; ?>
        </span>
      </a>

    <div class="nav-sect">Other</div>
    <a href="<?= h($appBase) ?>/select-module.php" class="slink"><span class="sicon"><i class="bi bi-grid"></i></span><span class="slabel">Modules</span></a>
  </aside>

  <!-- Main content -->
  <div class="flex-grow-1">
    <nav class="navbar navbar-expand navbar-light bg-white shadow-sm">
      <div class="container-fluid">
        <span class="navbar-brand fw-bold" style="color:var(--primary)"><?= h($brand['system_name']) ?></span>

        <!-- Company picker lives in the Jobs & progress filter row, not up here. -->

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
      <?php if ($opsFlash): ?>
        <div class="alert alert-<?= h($opsFlash['type']) ?> alert-dismissible fade show" role="alert">
          <?= h($opsFlash['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>
