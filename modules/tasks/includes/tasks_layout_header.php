<?php
if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
$pageTitleSafe = isset($pageTitle) ? h($pageTitle) : 'Tasks';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $pageTitleSafe ?> | <?= h($brand['system_name'] ?? 'ERP') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --primary: <?= h($brand['primary_color'] ?? '#0d6efd') ?>;
      --bg: #f6f7fb;
    }
    body { background: var(--bg); }
    .topbar {
      background: #fff; border-bottom: 1px solid #e9ecef;
      position: sticky; top: 0; z-index: 1020;
    }
    .page-wrap { max-width: 1180px; margin: 0 auto; padding: 14px; }
    .app-title { font-weight: 700; color: #1f2937; }
    .mobile-stack { display: flex; gap: .5rem; flex-wrap: wrap; }
    @media (max-width: 768px) {
      .page-wrap { padding: 10px; }
      .app-title { font-size: 1.05rem; }
      .mobile-stack .btn { width: 100%; }
    }
  </style>
  <?php if (isset($pageStyles)) echo '<style>' . $pageStyles . '</style>'; ?>
</head>
<body>
<?php
// Check-out bar for staff who have checked in. Prints nothing when the
// feature is off or the person has no attendance to record.
$asWidget = dirname(__DIR__, 3) . '/includes/attendance_self_widget.php';
if (is_file($asWidget)) { require $asWidget; }
?>
  <div class="topbar">
    <div class="page-wrap d-flex align-items-center justify-content-between">
      <div class="app-title d-flex align-items-center gap-2">
        <?php if ($logoSrc = brand_logo_src($brand)): ?>
          <img src="<?= h($logoSrc) ?>" alt="<?= h($brand['system_name'] ?? '') ?>" style="width:30px;height:30px;object-fit:contain;border-radius:6px;">
        <?php else: ?>
          <i class="bi bi-list-check"></i>
        <?php endif; ?>
        <span>Tasks</span>
      </div>
      <div class="small text-muted">Shared task workspace</div>
    </div>
  </div>
  <main class="page-wrap">

