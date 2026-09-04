<?php
// operation/daily_schedule_pdf.php
declare(strict_types=1);



require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

// ---------- Inputs ----------
$date   = $_GET['date']   ?? date('Y-m-d');
$driver = $_GET['driver'] ?? '';
$car    = $_GET['car']    ?? '';

// ---------- Dompdf cache: guaranteed non-empty & writable ----------
$cacheDir = realpath(__DIR__ . '/../_dompdf_cache');
if ($cacheDir === false) {
    $cacheDir = __DIR__ . '/../_dompdf_cache';
}
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}
if (!is_writable($cacheDir)) {
    @chmod($cacheDir, 0777);
}

// ---------- Helpers ----------
function time_compact(string $t): string {
    $t = trim($t);
    if ($t === '') return '';
    // Normalize separators
    $norm = preg_replace('/\s*(to|–|-|—)\s*/i', '-', $t);
    $norm = str_replace(' ', '', $norm);
    // Split
    [$a, $b] = array_pad(explode('-', $norm, 2), 2, '');
    $fmt = function ($x) {
        if ($x === '') return '';
        // Convert "8.00" -> "8.00", "8" -> "8.00"
        if (preg_match('/^\d{1,2}(\.\d+)?$/', $x)) {
            $f = floatval($x);
            // Print as "8.00" style
            return number_format($f, 2);
        }
        return $x;
    };
    if ($b === '') return $fmt($a);
    return $fmt($a) . '–' . $fmt($b);
}

// For sorting: turn a time range into a numeric start (e.g. 8.00 -> 8.00, 17.30 -> 17.5)
function time_sort_key(?string $t): float {
    if (!$t) return 9999.0;
    $s = strtolower(trim($t));
    $s = preg_replace('/\s*(to|–|-|—)\s*.*/i', '', $s); // keep only start
    $s = str_replace([' ', 'am', 'pm'], '', $s);
    // 8.30 -> 8.30 ; 8:30 -> 8.30
    $s = str_replace(':', '.', $s);
    if (!preg_match('/^\d{1,2}(\.\d+)?$/', $s)) return 9999.0;
    $f = floatval($s);
    // If the input ever had "pm" (not common here), add 12, but we already stripped above.
    return $f;
}

// ---------- Fetch base rows ----------
$params = [':d' => $date];
$sql = "SELECT * FROM make_order WHERE date = :d";
if ($driver !== '') {
    $sql .= " AND driver_name = :dr";
    $params[':dr'] = $driver;
}
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If nothing, still render a friendly empty PDF
if (!$orders) {
    $orders = [];
}

// ---------- Workers map & order_workers ----------
$worker_map = [];
$wq = $conn->query("SELECT id, nickname FROM workers");
while ($row = $wq->fetch(PDO::FETCH_ASSOC)) {
    $worker_map[(int)$row['id']] = $row['nickname'];
}

$order_ids = array_column($orders, 'id');
$all_order_workers = [];
if ($order_ids) {
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $st = $conn->prepare("SELECT order_id, worker_id FROM order_workers WHERE order_id IN ($placeholders)");
    $st->execute($order_ids);
    while ($ow = $st->fetch(PDO::FETCH_ASSOC)) {
        $oid = (int)$ow['order_id'];
        $wid = (int)$ow['worker_id'];
        $all_order_workers[$oid][] = $wid;
    }
}

// ---------- Sort by time start ----------
usort($orders, function ($a, $b) {
    $ka = time_sort_key($a['time'] ?? '');
    $kb = time_sort_key($b['time'] ?? '');
    if ($ka == $kb) return ($a['id'] <=> $b['id']);
    return ($ka < $kb) ? -1 : 1;
});

// ---------- Tally ----------
$total_hours = 0.0;
$total_amount = 0.0;
foreach ($orders as $r) {
    $total_hours  += (float)($r['hours'] ?? 0);
    $total_amount += (float)($r['total'] ?? 0);
}
$jobs_count = count($orders);

// Font auto-compaction if many rows
$base_font = 9.0;                // start small
if     ($jobs_count > 26) $base_font = 8.5;
if     ($jobs_count > 32) $base_font = 8.0;
if     ($jobs_count > 38) $base_font = 7.5;

// ---------- Build HTML ----------
ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { size: A4 portrait; margin: 8mm; }
  * { box-sizing: border-box; }
  body {
    font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
    font-size: <?= $base_font ?>pt;
    color: #000;
  }
  h1 { font-size: <?= $base_font + 3 ?>pt; margin: 0 0 6px 0; }
  .meta { margin: 2px 0 10px 0; }
  .meta div { margin: 0; }
  table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed; /* keeps widths consistent */
  }
  thead th {
    background: #222;
    color: #fff;
    border: 0.4pt solid #000;
    padding: 3px 4px;
    font-weight: bold;
    text-align: left;
  }
  tbody td, tfoot th, tfoot td {
    border: 0.4pt solid #000;
    padding: 2px 3px;
    vertical-align: top;
    word-wrap: break-word;
    overflow-wrap: anywhere;
    line-height: 1.15;
  }
  .right { text-align: right; }
  .center { text-align: center; }

  /* Column widths (sum ≈ 100%) */
  th.col-time    { width: 12%; }
  th.col-client  { width: 20%; }
  th.col-address { width: 28%; }
  th.col-workers { width: 18%; }
  th.col-hours   { width: 6%;  }
  th.col-amount  { width: 8%;  }
  th.col-remark  { width: 4%;  }
  th.col-notes   { width: 4%;  }

  .badge {
    border: 0.4pt solid #666;
    border-radius: 8px;
    padding: 0 3px;
    font-size: <?= max(6.5, $base_font - 2) ?>pt;
    display: inline-block;
    margin-left: 3px;
    color: #444;
  }

  .muted { color:#666; }
  .totals { font-weight: bold; }
</style>
</head>
<body>
  <h1>Daily Schedule</h1>
  <div class="meta">
    <div><strong>Date:</strong> <?= htmlspecialchars($date) ?></div>
    <div><strong>Driver:</strong> <?= htmlspecialchars($driver ?: '—') ?></div>
    <div><strong>Car:</strong> <?= htmlspecialchars($car ?: '—') ?></div>
    <div class="muted">Generated at <?= date('Y-m-d H:i') ?></div>
  </div>

  <table>
    <thead>
      <tr>
        <th class="col-time">Time</th>
        <th class="col-client">Client</th>
        <th class="col-address">Address</th>
        <th class="col-workers">Workers</th>
        <th class="col-hours center">Hours</th>
        <th class="col-amount right">Amount</th>
        <th class="col-remark center">Rem</th>
        <th class="col-notes">Notes</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $r): ?>
        <?php
          // Workers (prefer order_workers, fallback to stored string)
          $names = [];
          $oid = (int)$r['id'];
          if (!empty($all_order_workers[$oid])) {
              foreach ($all_order_workers[$oid] as $wid) {
                  if (isset($worker_map[$wid])) $names[] = htmlspecialchars($worker_map[$wid]);
              }
          }
          if (!$names) {
              $fallback = array_map('trim', explode(',', str_replace(' , ', ',', $r['worker_name'] ?? '')));
              foreach ($fallback as $n) if ($n!=='') $names[] = htmlspecialchars($n);
          }
          $wcount = count($names);
        ?>
        <tr>
          <td><?= htmlspecialchars(time_compact($r['time'] ?? '')) ?></td>
          <td><?= htmlspecialchars($r['client_name'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['address_o'] ?? '') ?></td>
          <td>
            <?= $names ? implode(' , ', $names) : '' ?>
            <?php if ($wcount): ?><span class="badge"><?= $wcount ?></span><?php endif; ?>
          </td>
          <td class="center"><?= number_format((float)($r['hours'] ?? 0), 2) ?></td>
          <td class="right"><?= number_format((float)($r['total'] ?? 0), 2) ?></td>
          <td class="center"><?= htmlspecialchars($r['remark'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['Notes'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="3" class="totals">Totals</th>
        <th class="totals"><?= $jobs_count ?> jobs</th>
        <th class="center totals"><?= number_format($total_hours, 2) ?></th>
        <th class="right totals"><?= 'AED '.number_format($total_amount, 2) ?></th>
        <th colspan="2"></th>
      </tr>
    </tfoot>
  </table>
</body>
</html>
<?php
$html = ob_get_clean();


/* ---------- Dompdf ---------- */
use Dompdf\Dompdf;
use Dompdf\Options;

// Make a definite, writable cache dir inside your project
$projectRoot = realpath(__DIR__ . '/..');                // /bmsystem-web
$cacheDir    = $projectRoot . '/storage/dompdf';         // /bmsystem-web/storage/dompdf
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}
if (!is_writable($cacheDir)) {
    // last-ditch fallback to system temp
    $cacheDir = rtrim(sys_get_temp_dir(), '/').'/dompdf';
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
}

$options = new Options();
$options->set([
    'isHtml5ParserEnabled' => true,
    'isRemoteEnabled'      => false,
    // point all caches/temp to a path we control
    'fontCache'            => $cacheDir,
    'fontDir'              => $cacheDir,
    'tempDir'              => $cacheDir,
    // use a font Dompdf ships with; exact id is 'dejavusans'
    'defaultFont'          => 'dejavusans',
]);

// Force CSS to use DejaVu Sans only (avoid Arial/Helvetica lookups)
$html = str_replace(
    '* { font-family: DejaVu Sans, Arial, sans-serif; }',
    '* { font-family: DejaVu Sans; }',
    $html
);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');

// If caches contain corrupted/old font data, clear once:
// @array_map('unlink', glob($cacheDir.'/*'));

$dompdf->render();

// Stream inline (opens in browser)
$filename = 'daily_schedule_'.$driver.'_'.$date.'.pdf';
$dompdf->stream($filename, ['Attachment' => 0]);
