<?php
/**
 * Monthly reports — the PDFs made on the 1st by cron/monthly_reports/run.php
 * (renewal and new-tenant charge checks, all buildings + Ayla Residence).
 *
 * The files live outside the website (/home/<user>/monthly_reports/<YYYY-MM>/), so this
 * page lists them and streams a PDF on request. Read-only. Owner and Admin only.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/url_helper.php';
require_role(['Owner', 'Admin'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Same folder the cron writes to: /home/<user>/monthly_reports on Hostinger
function monthly_reports_dir(): ?string
{
    if (preg_match('#^(/home/[^/]+)/#', __DIR__, $m)) {
        return $m[1] . '/monthly_reports';
    }
    $local = sys_get_temp_dir() . '/monthly_reports_local';   // localhost testing
    return is_dir($local) ? $local : null;
}

/** @return array<string, array<int, array{name:string,size:int,time:int}>> month => files, newest month first */
function monthly_reports_list(?string $root): array
{
    $out = [];
    if (!$root || !is_dir($root)) return $out;
    foreach (scandir($root) ?: [] as $month) {
        if (!preg_match('/^\d{4}-\d{2}$/', $month) || !is_dir("$root/$month")) continue;
        foreach (scandir("$root/$month") ?: [] as $f) {
            if (!preg_match('/^[A-Za-z0-9_\-]+\.pdf$/', $f)) continue;
            $p = "$root/$month/$f";
            $out[$month][] = ['name' => $f, 'size' => (int)filesize($p), 'time' => (int)filemtime($p)];
        }
        if (!empty($out[$month])) usort($out[$month], fn($a, $b) => strcmp($a['name'], $b['name']));
    }
    krsort($out);
    return $out;
}

$root = monthly_reports_dir();
$list = monthly_reports_list($root);

// Download / view one PDF. Only names that are in the listing are served, always as application/pdf.
if (isset($_GET['month'], $_GET['file'])) {
    $month = (string)$_GET['month'];
    $file  = (string)$_GET['file'];
    $ok = isset($list[$month]) && in_array($file, array_column($list[$month], 'name'), true);
    if (!$ok) { http_response_code(404); echo 'Not found'; exit; }
    $path = "$root/$month/$file";
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

$appBase = get_application_web_root();
$self = $appBase . '/admin/monthly_reports.php';
$titles = [
    'All_Buildings_Renewals'    => ['All buildings', 'Renewals'],
    'All_Buildings_New_Tenants' => ['All buildings', 'New tenants'],
    'Ayla_Renewals'             => ['Ayla Residence', 'Renewals'],
    'Ayla_New_Tenants'          => ['Ayla Residence', 'New tenants'],
];
$describe = function (string $f) use ($titles): array {
    foreach ($titles as $prefix => $t) if (str_starts_with($f, $prefix . '_')) return $t;
    return [pathinfo($f, PATHINFO_FILENAME), ''];
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monthly reports</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f6f7f9; }
  .month-card { background: #fff; border: 1px solid #e5e7eb; border-radius: .5rem; margin-bottom: 1rem; }
  .month-card h5 { padding: .75rem 1rem; margin: 0; border-bottom: 1px solid #e5e7eb; }
  .table td { vertical-align: middle; }
  .muted { color: #6b7280; font-size: .85rem; }
</style>
</head>
<body>
<div class="container py-3" style="max-width:1000px">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h4 class="mb-0">Monthly reports</h4>
      <div class="muted">Renewal and new-tenant charge checks for the previous month, made automatically on the 1st at 08:00 and emailed to the owner.</div>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($appBase) ?>/settings.php">Back to Settings</a>
  </div>

  <?php if (!$list): ?>
    <div class="alert alert-info">No reports yet. The first ones are made on the 1st of next month.</div>
  <?php endif; ?>

  <?php foreach ($list as $month => $files): ?>
    <div class="month-card">
      <h5><?= h(date('F Y', strtotime("$month-01"))) ?> <span class="muted">· leases starting in that month</span></h5>
      <table class="table table-sm mb-0">
        <tbody>
        <?php foreach ($files as $f): [$what, $kind] = $describe($f['name']);
              $q = '?month=' . rawurlencode($month) . '&file=' . rawurlencode($f['name']); ?>
          <tr>
            <td class="ps-3"><strong><?= h($what) ?></strong><?= $kind ? ' · ' . h($kind) : '' ?></td>
            <td class="muted"><?= h(number_format($f['size'] / 1024, 0)) ?> KB · made <?= h(date('j M Y', $f['time'])) ?></td>
            <td class="text-end pe-3">
              <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= h($self . $q) ?>">View</a>
              <a class="btn btn-sm btn-primary" href="<?= h($self . $q . '&dl=1') ?>">Download</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
