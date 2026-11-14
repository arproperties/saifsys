<?php
/**
 * Loan Tracker — page shell. Same style kit as the other modules (sidebar, dark mode,
 * collapse), with the module's own colours: orange and white.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/lt_helper.php';
if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}
require_once dirname(__DIR__, 3) . '/includes/url_helper.php';

$appBase = get_application_web_root();
$ltBase = $appBase . '/modules/loan_tracker';
$currentPage = basename($_SERVER['PHP_SELF']);

$U = !empty($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

$ltFlash = lt_take_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title><?= isset($pageTitle) ? h($pageTitle) . ' | ' : '' ?>Loan Tracker | <?= h($brand['system_name']) ?></title>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
  <?php require dirname(__DIR__, 3) . '/operation/includes/cleaning_ui_styles.php'; ?>
  <style>
    /* Brand colours, the same as Recurring Payments. Tints are mixed from the brand primary. */
    :root{
      --lt-ink:#1f2937; --lt-muted:#6b7280; --lt-line:#e5e7eb; --lt-bg:#f6f7f9;
      --lt-tint:color-mix(in srgb, var(--primary) 7%, #fff); --lt-tint-2:color-mix(in srgb, var(--primary) 13%, #fff);
      --lt-tint-3:color-mix(in srgb, var(--primary) 24%, #fff);
      --lt-orange:var(--primary); --lt-amber:#d97706; --lt-peach:var(--primary-light); --lt-green:#16a34a; --lt-rose:#dc2626;
    }
    body{ background:var(--lt-bg); }

    .card-round{ border:1px solid var(--lt-line); border-radius:14px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .card-round > .table-responsive{ border-radius:inherit; }
    .card-round > .table-responsive > .table{ margin-bottom:0; }
    .form-control, .form-select{ border:1px solid #d1d5db; }
    .form-control:focus, .form-select:focus{ border-color:var(--primary); box-shadow:0 0 0 .2rem rgba(0,0,0,.15); }
    .select2-container--bootstrap-5 .select2-selection{ border:1px solid #d1d5db; }
    .select2-container--bootstrap-5.select2-container--focus .select2-selection,
    .select2-container--bootstrap-5.select2-container--open .select2-selection{ border-color:var(--primary); }
    .select2-container{ width:100% !important; }

    .btn-lt{ background:var(--primary); color:#fff; border:0; border-radius:10px; padding:.55rem 1.1rem; font-weight:600; }
    .btn-lt:hover, .btn-lt:focus{ background:var(--primary); color:#fff; }
    .btn-lt-ghost{ background:#fff; color:var(--lt-ink); border:1px solid var(--lt-line); border-radius:10px; padding:.55rem 1.1rem; font-weight:600; }
    .btn-lt-ghost:hover{ background:var(--lt-tint); color:var(--lt-ink); }
    .btn-lt-ghost.active, .btn-lt-ghost.active .text-muted{ background:var(--primary); color:#fff !important; border-color:var(--primary); }
    .btn-group-sm > .btn-lt-ghost{ padding:.3rem .75rem; border-radius:8px; }

    .lt-title{ font-size:1.6rem; font-weight:700; color:var(--lt-ink); margin:0; }
    .lt-sub{ color:var(--lt-muted); margin:0; }
    .lt-section{ font-size:.75rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--lt-muted); }

    /* Stat tiles: a thin coloured bar on the left, the number large. */
    .lt-stat{ position:relative; padding:1rem 1.15rem; overflow:hidden; }
    .lt-stat::before{ content:""; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--c, var(--primary)); }
    .lt-stat .lt-stat-label{ font-size:.8rem; color:var(--lt-muted); font-weight:600; }
    .lt-stat .lt-stat-value{ font-size:1.75rem; font-weight:700; color:var(--lt-ink); font-variant-numeric:tabular-nums; line-height:1.2; }
    .lt-stat .lt-stat-icon{ position:absolute; right:1rem; top:1rem; width:38px; height:38px; border-radius:10px;
      display:grid; place-items:center; color:var(--c); background:color-mix(in srgb, var(--c) 12%, transparent); font-size:1.1rem; }

    .lt-table{ margin-bottom:0; }
    .lt-table > thead > tr > th{ background:transparent; border-bottom:1px solid var(--lt-line); font-size:.7rem; font-weight:700;
      letter-spacing:.05em; text-transform:uppercase; color:#6b7280; padding:.6rem 1rem; white-space:nowrap; }
    .lt-table > tbody > tr > td{ padding:.7rem 1rem; vertical-align:middle; border-top:1px solid var(--lt-tint); border-bottom:0; }
    .lt-table > tbody > tr:first-child > td{ border-top:0; }
    .lt-table > tbody > tr:hover{ background:#f9fafb; }

    .lt-badge{ display:inline-block; font-size:.72rem; font-weight:600; padding:.25rem .6rem; border-radius:999px; }
    .lt-badge-draft{ background:#fef3c7; color:#92400e; }
    .lt-badge-active{ background:var(--lt-tint-2); color:var(--primary-dark); }
    .lt-badge-closed{ background:#dcfce7; color:#166534; }
    .lt-badge-cancelled{ background:#f3f4f6; color:#4b5563; }
    .lt-type{ display:inline-flex; align-items:center; gap:.4rem; font-size:.85rem; }
    .lt-type i{ color:var(--c); }

    /* Notifications */
    .lt-note{ display:flex; gap:.75rem; padding:.8rem 1rem; border-top:1px solid var(--lt-tint); text-decoration:none; color:inherit; }
    .lt-note:first-child{ border-top:0; }
    .lt-note:hover{ background:#f9fafb; color:inherit; }
    .lt-note-icon{ flex:0 0 32px; height:32px; border-radius:9px; display:grid; place-items:center; color:var(--c);
      background:color-mix(in srgb, var(--c) 12%, transparent); }
    .lt-note-title{ font-weight:600; font-size:.9rem; color:var(--lt-ink); }
    .lt-note-text{ font-size:.8rem; color:var(--lt-muted); }

    /* Steps on the New loan pages */
    .lt-steps{ display:flex; gap:0; list-style:none; padding:0; margin:0 0 1.5rem; max-width:760px; }
    .lt-steps li{ flex:1; display:flex; align-items:center; gap:.5rem; color:#9ca3af; font-weight:600; font-size:.85rem; position:relative; }
    .lt-steps li:not(:last-child)::after{ content:""; flex:1; height:2px; background:#e5e7eb; margin:0 .6rem; }
    .lt-steps li.done:not(:last-child)::after{ background:var(--lt-amber); }
    .lt-step-dot{ width:28px; height:28px; border-radius:50%; display:grid; place-items:center; background:#e5e7eb; color:#6b7280; font-size:.8rem; flex:0 0 28px; }
    .lt-steps li.current{ color:var(--lt-ink); }
    .lt-steps li.current .lt-step-dot{ background:var(--primary); color:#fff; box-shadow:0 0 0 4px rgba(0,0,0,.15); }
    .lt-steps li.done{ color:var(--lt-amber); }
    .lt-steps li.done .lt-step-dot{ background:var(--lt-amber); color:#fff; }

    /* Borrower type cards on step 1 */
    .lt-choice{ display:block; cursor:pointer; height:100%; }
    .lt-choice input{ position:absolute; opacity:0; pointer-events:none; }
    .lt-choice .lt-choice-box{ height:100%; border:1.5px solid var(--lt-line); border-radius:14px; padding:1.1rem; background:#fff; transition:.15s; }
    .lt-choice .lt-choice-box i{ font-size:1.4rem; color:var(--c); }
    .lt-choice:hover .lt-choice-box{ border-color:color-mix(in srgb, var(--c) 45%, #fff); }
    .lt-choice input:checked + .lt-choice-box{ border-color:var(--c); background:color-mix(in srgb, var(--c) 6%, #fff);
      box-shadow:0 0 0 3px color-mix(in srgb, var(--c) 15%, transparent); }
    .lt-choice input:focus-visible + .lt-choice-box{ outline:2px solid var(--c); outline-offset:2px; }

    /* Document blocks on step 2 */
    .lt-doc{ border:1px solid var(--lt-line); border-radius:12px; padding:1rem 1.1rem; background:#fff; }
    .lt-doc-head{ display:flex; align-items:center; gap:.6rem; margin-bottom:.8rem; }
    .lt-doc-head .lt-doc-icon{ width:34px; height:34px; border-radius:9px; display:grid; place-items:center; background:var(--lt-tint); color:var(--primary); }
    .lt-req{ color:var(--lt-rose); }
    .lt-hr-file{ display:flex; align-items:center; gap:.5rem; padding:.5rem .75rem; border-radius:9px; background:#fef3c7; color:var(--primary-dark); font-size:.85rem; }
    .lt-missing{ display:flex; align-items:center; gap:.5rem; padding:.5rem .75rem; border-radius:9px; background:#fff7ed; color:var(--primary-dark); font-size:.85rem; }

    /* "Loan to" badge at the right of the New loan heading */
    .lt-loan-to{ display:inline-flex; align-items:center; gap:.75rem; background:var(--primary); color:#fff; border-radius:12px; padding:.55rem 1.1rem;
      box-shadow:0 4px 14px rgba(0,0,0,.18); }
    .lt-loan-to-label{ font-size:.72rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--lt-tint-2); }
    .lt-loan-to-value{ font-size:1.1rem; font-weight:800; }
    .lt-loan-to-value i{ color:#fff; }

    /* Timeline */
    .lt-tl{ list-style:none; margin:0; padding:0; position:relative; }
    .lt-tl::before{ content:""; position:absolute; left:15px; top:10px; bottom:10px; width:2px; background:#e5e7eb; }
    .lt-tl li{ position:relative; padding:0 0 1.1rem 2.9rem; }
    .lt-tl li:last-child{ padding-bottom:0; }
    .lt-tl-dot{ position:absolute; left:0; top:0; width:32px; height:32px; border-radius:50%; display:grid; place-items:center; color:#fff; font-size:.9rem;
      box-shadow:0 0 0 4px #fff; }
    .lt-tl-given .lt-tl-dot{ background:var(--primary); }
    .lt-tl-pay .lt-tl-dot{ background:#22c55e; }
    .lt-tl-warn .lt-tl-dot{ background:#f59e0b; }
    .lt-tl-end .lt-tl-dot{ background:var(--lt-tint-3); color:var(--primary-dark); }
    .lt-tl-done .lt-tl-dot{ background:#15803d; }
    .lt-tl-date{ font-size:.76rem; color:var(--lt-muted); font-weight:600; }
    .lt-tl-title{ font-weight:600; color:var(--lt-ink); }
    .lt-tl-amt{ font-weight:700; font-variant-numeric:tabular-nums; white-space:nowrap; }
    .lt-tl-pay .lt-tl-amt{ color:#15803d; }
    .lt-tl-end .lt-tl-amt{ color:var(--primary); }
    .lt-tl-note{ font-size:.8rem; color:var(--lt-muted); }
    .lt-tl-file{ display:inline-flex; align-items:center; gap:.35rem; margin-top:.4rem; padding:.3rem .65rem; border-radius:8px;
      background:var(--lt-tint); border:1px solid var(--lt-tint-3); color:var(--primary-dark); font-size:.8rem; font-weight:600; text-decoration:none; }
    .lt-tl-file:hover{ background:var(--lt-tint-2); color:var(--primary-dark); }

    /* Skipping a required document */
    .lt-skip{ border-top:1px dashed var(--lt-line); padding-top:.75rem; }
    .lt-skip .form-check-input:checked{ background-color:#f59e0b; border-color:#f59e0b; }
    .lt-doc-skipped{ background:#fffbeb; border-color:#fcd9a8; }
    .lt-doc-skipped .row input:not([type=checkbox]){ opacity:.6; }

    body.dark-mode{ background:#0f172a; }
    body.dark-mode .card-round, body.dark-mode .lt-doc, body.dark-mode .lt-choice .lt-choice-box{ background:rgba(30,41,59,.95); color:#e4e4e7; border-color:#334155; }
    body.dark-mode .lt-title, body.dark-mode .lt-stat .lt-stat-value, body.dark-mode .lt-note-title, body.dark-mode .lt-steps li.current{ color:#e4e4e7; }
    body.dark-mode .form-control, body.dark-mode .form-select{ background:rgba(15,23,42,.6); color:#e4e4e7; border-color:#475569; }
    body.dark-mode .lt-table > tbody > tr > td, body.dark-mode .lt-note{ border-top-color:rgba(255,255,255,.07); }
    body.dark-mode .lt-table > tbody > tr:hover, body.dark-mode .lt-note:hover{ background:rgba(255,255,255,.03); }
    @media (max-width: 767.98px){ .sidebar{ display:none; } .lt-step-label{ display:none; } }
  </style>
  <?php if (!empty($pageStyles)): ?><style><?= $pageStyles ?></style><?php endif; ?>
</head>
<body<?= $brand['dark_mode_enabled'] ? ' class="dark-mode"' : '' ?>>
<?php
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
      <a href="<?= h($appBase) ?>/modules/recurring_payments/index.php" class="slink">
        <span class="sicon"><i class="bi bi-cash-coin"></i></span><span class="slabel">Recurring Payments</span>
      </a>

    <div class="nav-sect">Loan Tracker</div>
      <a href="<?= h($ltBase) ?>/index.php" class="slink <?= in_array($currentPage, ['index.php', 'loan_view.php'], true) ? 'active' : '' ?>">
        <span class="sicon"><i class="bi bi-speedometer2"></i></span><span class="slabel">Dashboard</span>
      </a>
      <a href="<?= h($ltBase) ?>/new_loan.php" class="slink <?= in_array($currentPage, ['new_loan.php', 'loan_docs.php'], true) ? 'active' : '' ?>">
        <span class="sicon"><i class="bi bi-plus-circle"></i></span><span class="slabel">New loan</span>
      </a>
      <a href="<?= h($ltBase) ?>/timeline.php" class="slink <?= $currentPage === 'timeline.php' ? 'active' : '' ?>">
        <span class="sicon"><i class="bi bi-clock-history"></i></span><span class="slabel">Loan timeline</span>
      </a>
      <a href="<?= h($ltBase) ?>/repayments.php" class="slink <?= $currentPage === 'repayments.php' ? 'active' : '' ?>">
        <span class="sicon"><i class="bi bi-cash-stack"></i></span><span class="slabel">Repayments</span>
      </a>
    <div class="nav-sect">Other</div>
    <a href="<?= h($appBase) ?>/select-module.php" class="slink"><span class="sicon"><i class="bi bi-grid"></i></span><span class="slabel">Modules</span></a>
  </aside>

  <div class="flex-grow-1" style="min-width:0">
    <nav class="navbar navbar-expand navbar-light bg-white shadow-sm">
      <div class="container-fluid">
        <span class="navbar-brand fw-bold" style="color:var(--lt-ink)"><?= h($brand['system_name']) ?></span>
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
      <?php if ($ltFlash): ?>
        <div class="alert alert-<?= h($ltFlash['type']) ?> alert-dismissible fade show" role="alert">
          <?= h($ltFlash['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>
