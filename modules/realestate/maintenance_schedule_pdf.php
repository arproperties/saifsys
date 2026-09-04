<?php
/**
 * Real Estate Module - Maintenance Schedule PDF export.
 * Types: daily | weekly | building | employee. Uses the existing mPDF install.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/maintenance_schedule_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_MAINTENANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

function hp($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$type = $_GET['type'] ?? 'daily';
if (!in_array($type, ['daily', 'weekly', 'building', 'employee'], true)) $type = 'daily';

$date = $_GET['date'] ?? date('Y-m-d');
$dchk = DateTime::createFromFormat('Y-m-d', $date);
if (!$dchk || $dchk->format('Y-m-d') !== $date) $date = date('Y-m-d');

$filters = [
    'building_id' => (int)($_GET['building_id'] ?? 0),
    'employee_id' => (int)($_GET['employee_id'] ?? 0),
    'status' => (in_array(($_GET['status'] ?? ''), array_keys(re_ms_statuses()), true)) ? $_GET['status'] : '',
    'task_type' => (in_array(($_GET['task_type'] ?? ''), array_keys(re_ms_task_types()), true)) ? $_GET['task_type'] : '',
];

if (in_array($type, ['weekly'], true) || ($_GET['view'] ?? '') === 'week') {
    $monday = (new DateTime($date))->modify('monday this week');
    $dateFrom = $monday->format('Y-m-d');
    $dateTo = (clone $monday)->modify('+6 days')->format('Y-m-d');
} else {
    $dateFrom = $dateTo = $date;
}
if ($type === 'weekly') {
    $monday = (new DateTime($date))->modify('monday this week');
    $dateFrom = $monday->format('Y-m-d');
    $dateTo = (clone $monday)->modify('+6 days')->format('Y-m-d');
}

$schedules = re_ms_fetch_schedules($conn, $currentCompanyId, $dateFrom, $dateTo, $filters);

$taskTypes = re_ms_task_types();
$statuses = re_ms_statuses();
$statusColors = re_ms_status_colors();
$priorities = re_ms_priorities();

// Company / logo
$companyName = $brand['system_name'] ?? 'Company';
try {
    $cn = $conn->prepare("SELECT name FROM companies WHERE id = ?");
    $cn->execute([$currentCompanyId]);
    $maybe = $cn->fetchColumn();
    if ($maybe) $companyName = $maybe;
} catch (Throwable $e) { /* ignore */ }

$logoData = '';
if (!empty($brand['logo_path'])) {
    $logoFile = dirname(__DIR__, 2) . '/' . ltrim($brand['logo_path'], '/');
    if (is_file($logoFile)) {
        $ext = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'svg' ? 'image/svg+xml' : 'image/jpeg');
        $raw = @file_get_contents($logoFile);
        if ($raw !== false) {
            $logoData = 'data:' . $mime . ';base64,' . base64_encode($raw);
        }
    }
}

$titleMap = [
    'daily' => 'Daily Maintenance Schedule',
    'weekly' => 'Weekly Maintenance Schedule',
    'building' => 'Maintenance Schedule by Building',
    'employee' => 'Maintenance Schedule by Employee',
];
$reportTitle = $titleMap[$type];
$rangeLabel = ($dateFrom === $dateTo)
    ? date('l, F j, Y', strtotime($dateFrom))
    : (date('M j, Y', strtotime($dateFrom)) . ' – ' . date('M j, Y', strtotime($dateTo)));

$roleAbbr = ['engineer' => 'Eng', 'supervisor' => 'Sup', 'technician' => 'Tech', 'helper' => 'Help', 'other' => ''];

$roleAbbr = ['engineer' => 'Eng', 'supervisor' => 'Sup', 'technician' => 'Tech', 'helper' => 'Help', 'other' => ''];

/** Take a sensible first name from a full name. */
function ms_first_name(string $name): string
{
    $name = trim($name);
    if ($name === '') return '-';
    $parts = preg_split('/\s+/', $name);
    return $parts[0] ?? $name;
}

/** Column widths shared by every report table. */
$colgroup = '<colgroup>'
    . '<col style="width:9%">'   // WO #
    . '<col style="width:6%">'   // Date
    . '<col style="width:11%">'  // Time
    . '<col style="width:9%">'   // Task
    . '<col style="width:8%">'   // Priority
    . '<col style="width:18%">'  // Building / Unit
    . '<col style="width:18%">'  // Team
    . '<col style="width:8%">'   // Status
    . '<col style="width:13%">'  // Notes
    . '</colgroup>';

$headRow = '<tr><th>WO #</th><th>Date</th><th>Time</th><th>Task</th><th>Priority</th><th>Building / Unit</th><th>Team</th><th>Status</th><th>Notes</th></tr>';

/** Render a work order row as HTML cells (without employee column). */
function ms_pdf_row(array $s, array $taskTypes, array $statuses, array $statusColors, array $priorities, array $roleAbbr): string
{
    $loc = htmlspecialchars((string)($s['building_name'] ?: '-'), ENT_QUOTES, 'UTF-8');
    if (!empty($s['unit_number'])) {
        $loc .= '<br><span class="sub">Unit ' . htmlspecialchars((string)$s['unit_number'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $teamParts = [];
    foreach ($s['assignees'] as $a) {
        $ab = $roleAbbr[$a['team_role'] ?? 'other'] ?? '';
        $teamParts[] = htmlspecialchars(ms_first_name((string)$a['employee_name']), ENT_QUOTES, 'UTF-8')
            . ($ab ? ' <span class="rl">' . $ab . '</span>' : '');
    }
    $team = $teamParts ? implode('<br>', $teamParts) : '-';
    $col = $statusColors[$s['status']] ?? '#6c757d';
    $prio = $priorities[$s['priority']] ?? $s['priority'];
    $prioStyle = in_array($s['priority'], ['high', 'emergency'], true) ? ' style="color:#c92a2a; font-weight:bold;"' : '';
    return '<td><strong>' . hp($s['work_order_number'] ?: ('#' . $s['id'])) . '</strong></td>'
        . '<td>' . hp(date('M j', strtotime($s['schedule_date']))) . '</td>'
        . '<td>' . hp(substr($s['start_time'], 0, 5) . ' - ' . substr($s['end_time'], 0, 5)) . '</td>'
        . '<td>' . hp($taskTypes[$s['task_type']] ?? $s['task_type']) . '</td>'
        . '<td' . $prioStyle . '>' . hp($prio) . '</td>'
        . '<td>' . $loc . '</td>'
        . '<td class="team">' . $team . '</td>'
        . '<td><span style="background:' . $col . '; color:#fff; padding:1px 6px; border-radius:4px; font-size:8.5px;">' . hp($statuses[$s['status']] ?? $s['status']) . '</span></td>'
        . '<td class="sub">' . hp($s['notes'] ?: '') . '</td>';
}

// Status summary counts for the header band
$summary = ['scheduled' => 0, 'in_progress' => 0, 'completed' => 0, 'delayed' => 0, 'cancelled' => 0];
foreach ($schedules as $s) {
    if (isset($summary[$s['status']])) $summary[$s['status']]++;
}

ob_start();
?>
<style>
  body { font-family: DejaVu Sans, sans-serif; color:#222; font-size:10px; }
  .hdr { width:100%; border-bottom:2px solid #0d6efd; padding-bottom:6px; margin-bottom:10px; }
  .hdr td { vertical-align:middle; }
  .hdr .title { font-size:16px; font-weight:bold; color:#0d6efd; }
  .hdr .sub { font-size:11px; color:#444; }
  .hdr .company { font-size:13px; font-weight:bold; }
  table.grid { width:100%; border-collapse:collapse; margin-bottom:14px; table-layout:fixed; }
  table.grid th { background:#0d6efd; color:#fff; padding:5px 6px; text-align:left; font-size:9px; text-transform:uppercase; letter-spacing:.2px; }
  table.grid td { border-bottom:1px solid #e3e6ea; padding:5px 6px; font-size:9.5px; vertical-align:top; word-wrap:break-word; }
  table.grid tr:nth-child(even) td { background:#f7f9fc; }
  td.team { font-size:9px; line-height:1.5; }
  td.team .rl { color:#0d6efd; font-size:7.5px; font-weight:bold; }
  td .sub, td.sub { color:#777; font-size:8.5px; }
  h3.group { background:#eef3fb; padding:5px 8px; border-left:4px solid #0d6efd; margin:14px 0 4px; font-size:12px; }
  .muted { color:#888; }
  .summary { margin:0 0 12px; }
  .summary .chip { display:inline-block; padding:3px 9px; border-radius:11px; color:#fff; font-size:9px; font-weight:bold; margin-right:5px; }
</style>

<table class="hdr">
  <tr>
    <td style="width:70px;">
      <?php if ($logoData): ?><img src="<?= $logoData ?>" style="max-width:64px; max-height:64px;"><?php endif; ?>
    </td>
    <td>
      <div class="company"><?= hp($companyName) ?></div>
      <div class="title"><?= hp($reportTitle) ?></div>
      <div class="sub"><?= hp($rangeLabel) ?>
        <?php if ($filters['building_id'] || $filters['status'] || $filters['task_type']): ?>
          &nbsp;|&nbsp; Filters applied
        <?php endif; ?>
      </div>
    </td>
    <td style="text-align:right;" class="sub">
      Generated: <?= date('Y-m-d H:i') ?><br>
      Total work orders: <?= count($schedules) ?>
    </td>
  </tr>
</table>

<div class="summary">
  <span class="chip" style="background:#343a40;">Total: <?= count($schedules) ?></span>
  <span class="chip" style="background:<?= $statusColors['scheduled'] ?>;">Scheduled: <?= $summary['scheduled'] ?></span>
  <span class="chip" style="background:<?= $statusColors['in_progress'] ?>;">In Progress: <?= $summary['in_progress'] ?></span>
  <span class="chip" style="background:<?= $statusColors['completed'] ?>;">Completed: <?= $summary['completed'] ?></span>
  <span class="chip" style="background:<?= $statusColors['delayed'] ?>;">Delayed: <?= $summary['delayed'] ?></span>
  <span class="chip" style="background:<?= $statusColors['cancelled'] ?>;">Cancelled: <?= $summary['cancelled'] ?></span>
</div>

<?php if (empty($schedules)): ?>
  <p class="muted">No scheduled work orders found for this period / filters.</p>
<?php else: ?>

  <?php
  if ($type === 'building') {
      $groups = [];
      foreach ($schedules as $s) {
          $key = $s['building_name'] ?: 'Unassigned building';
          $groups[$key][] = $s;
      }
      ksort($groups);
      foreach ($groups as $gname => $rows): ?>
        <h3 class="group"><?= hp($gname) ?> <span class="muted">(<?= count($rows) ?>)</span></h3>
        <table class="grid"><?= $colgroup ?><?= $headRow ?>
          <?php foreach ($rows as $s): ?><tr><?= ms_pdf_row($s, $taskTypes, $statuses, $statusColors, $priorities, $roleAbbr) ?></tr><?php endforeach; ?>
        </table>
      <?php endforeach;

  } elseif ($type === 'employee') {
      $byEmp = [];
      $names = [];
      foreach ($schedules as $s) {
          foreach ($s['assignees'] as $a) {
              $byEmp[(int)$a['employee_id']][] = $s;
              $names[(int)$a['employee_id']] = $a['employee_name'];
          }
      }
      asort($names);
      if (empty($names)) {
          echo '<p class="muted">No team assignments in this period.</p>';
      }
      foreach ($names as $eid => $nm): ?>
        <h3 class="group"><?= hp($nm) ?> <span class="muted">(<?= count($byEmp[$eid]) ?>)</span></h3>
        <table class="grid"><?= $colgroup ?><?= $headRow ?>
          <?php foreach ($byEmp[$eid] as $s): ?><tr><?= ms_pdf_row($s, $taskTypes, $statuses, $statusColors, $priorities, $roleAbbr) ?></tr><?php endforeach; ?>
        </table>
      <?php endforeach;

  } else {
      // daily / weekly: single chronological table
      ?>
      <table class="grid"><?= $colgroup ?><?= $headRow ?>
        <?php foreach ($schedules as $s): ?><tr><?= ms_pdf_row($s, $taskTypes, $statuses, $statusColors, $priorities, $roleAbbr) ?></tr><?php endforeach; ?>
      </table>
      <?php
  }
  ?>
<?php endif; ?>
<?php
$html = ob_get_clean();

// Render with mPDF
$vendorAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

if (!class_exists('\Mpdf\Mpdf')) {
    // Graceful fallback: render printable HTML page
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . hp($reportTitle) . '</title>'
        . '<script>window.onload=function(){window.print();}</script></head><body>' . $html . '</body></html>';
    exit;
}

// Resolve a writable temp dir for mPDF. Prefer project-relative folders
// (system temp is often not writable for the Apache user on XAMPP/macOS).
$projectRoot = dirname(__DIR__, 2);
$tmpDir = '';
foreach ([
    $projectRoot . '/uploads/temp/mpdf',
    $projectRoot . '/uploads/mpdf_tmp',
    rtrim(sys_get_temp_dir(), '/\\') . '/herosysgro_mpdf',
] as $cand) {
    if (!is_dir($cand)) { @mkdir($cand, 0777, true); @chmod($cand, 0777); }
    if (is_dir($cand) && is_writable($cand)) { $tmpDir = $cand; break; }
}

try {
    $mpdfConfig = [
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 12,
        'margin_bottom' => 12,
    ];
    if ($tmpDir !== '') { $mpdfConfig['tempDir'] = $tmpDir; }
    $mpdf = new \Mpdf\Mpdf($mpdfConfig);
    $mpdf->SetTitle($reportTitle);
    $footerText = hp($companyName) . ' — ' . hp($reportTitle);
    $mpdf->SetHTMLFooter('<div style="border-top:1px solid #cfd6df; padding-top:3px; font-size:8px; color:#888;">'
        . '<table width="100%"><tr><td>' . $footerText . '</td>'
        . '<td style="text-align:right;">Page {PAGENO} of {nbpg}</td></tr></table></div>');
    $mpdf->WriteHTML($html);
    $filename = 'maintenance_schedule_' . $type . '_' . $dateFrom . '.pdf';
    $mpdf->Output($filename, 'I');
    exit;
} catch (Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . hp($reportTitle) . '</title></head><body>'
        . '<div style="color:#b00;font-family:sans-serif;padding:10px;">PDF engine error: ' . hp($e->getMessage()) . '. Showing printable version.</div>'
        . $html . '</body></html>';
    exit;
}
