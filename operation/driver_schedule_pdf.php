<?php
// operation/driver_schedule_pdf.php

declare(strict_types=1);

require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/service_category_helper.php';

$driverId = (int)($_GET['driver'] ?? 0);
$date     = $_GET['date'] ?? date('Y-m-d');

if ($driverId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  http_response_code(400);
  echo "Missing or bad driver/date";
  exit;
}

// Get driver info
$st = $conn->prepare("SELECT id, nickname AS name, mobile_num FROM driver WHERE id=?");
$st->execute([$driverId]);
$driver = $st->fetch(PDO::FETCH_ASSOC);
if (!$driver) {
  http_response_code(404);
  echo "Driver not found";
  exit;
}

$hasBookingCats = sm_make_order_has_booking_categories($conn);
$bookingCatSql = $hasBookingCats ? 'mo.booking_categories, mo.service_category_id,' : 'mo.service_category_id,';

// Jobs for the day
$q = $conn->prepare("
  SELECT
    mo.id,
    mo.client_name,
    mo.address_o,
    mo.start_time,
    mo.end_time,
    {$bookingCatSql}
    COALESCE(mo.need_materials,0) AS need_materials,
    COALESCE(mo.materials_note,'') AS materials_note,
    LOWER(COALESCE(mo.payment,'')) AS payment,
    COALESCE(mo.grand_total,0) AS grand_total,
    COALESCE(mo.remark,'') AS remark
  FROM make_order mo
  WHERE mo.driver_id=? AND mo.svc_date_calc=? AND COALESCE(mo.status,'') <> 'cancelled'
  ORDER BY mo.start_time
");
$q->execute([$driverId, $date]);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

// Workers per order - get both names and count
$w = $conn->prepare("
  SELECT 
    GROUP_CONCAT(w.nickname ORDER BY w.nickname SEPARATOR ', ') AS wnames,
    COUNT(ow.worker_id) AS worker_count
  FROM order_workers ow
  JOIN workers w ON w.id=ow.worker_id
  WHERE ow.order_id=?
  GROUP BY ow.order_id
");

$jobs = [];
$totalHours = 0;

foreach ($rows as $index => $r) {
  $w->execute([$r['id']]);
  $workerData = $w->fetch(PDO::FETCH_ASSOC);
  $workers = $workerData ? (string)$workerData['wnames'] : '';
  $workerCount = $workerData ? (int)$workerData['worker_count'] : 0;
  
  // If no workers found, default to 1 worker
  if ($workerCount === 0) {
    $workerCount = 1;
  }
  
  // Format time as "8.30 To 12.30" (remove leading zeros from hours)
  $startTime = new DateTime($r['start_time']);
  $endTime = new DateTime($r['end_time']);
  $startFormatted = $startTime->format('G.i'); // G = 24-hour without leading zero, i = minutes
  $endFormatted = $endTime->format('G.i');
  $timeRange = "{$startFormatted} To {$endFormatted}";
  
  // Calculate hours (decimal) - duration of the job
  $hoursDuration = ($endTime->getTimestamp() - $startTime->getTimestamp()) / 3600;
  
  // Total hours = duration * number of workers
  $totalHoursForOrder = $hoursDuration * $workerCount;
  $totalHours += $totalHoursForOrder;
  
  // Materials: yes or no
  $materials = ((int)$r['need_materials'] === 1) ? 'yes' : 'no';
  
  // Amount
  $amount = (float)$r['grand_total'];
  
  // Payment type: cash or online. If cash, show amount.
  $paymentDisplay = ($r['payment'] === 'cash') 
    ? 'cash (' . number_format($amount, 2) . ')' 
    : 'online';

  $categoryLabels = sm_order_category_labels(
      $conn,
      $hasBookingCats ? ($r['booking_categories'] ?? null) : null,
      (int)($r['service_category_id'] ?? 0) ?: null
  );

  $jobs[] = [
    'sn'        => $index + 1,
    'name'      => $workers ?: '',
    'time'      => $timeRange,
    'hours'     => number_format($totalHoursForOrder, 1),
    'client'    => $r['client_name'] ?? '',
    'services'  => $categoryLabels,
    'address'   => $r['address_o'] ?? '',
    'materials' => $materials,
    'payment'   => $paymentDisplay,
    'remark'    => $r['remark'] ?? '',
  ];
}

// HTML template matching screenshot design
ob_start(); ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Daily Schedule</title>
<style>
  * { box-sizing: border-box; font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
  body { margin: 20px; color: #111; }
  
  .title-header {
    background-color: #E91E63;
    color: white;
    text-align: center;
    padding: 10px;
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 8px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  
  .info-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 12px;
    font-weight: bold;
  }
  
  .info-left {
    text-align: left;
  }
  
  .info-right {
    text-align: right;
  }
  
  table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #000;
  }
  
  th, td {
    border: 1px solid #000;
    padding: 6px;
    vertical-align: top;
    text-align: left;
    font-size: 11px;
    font-weight: bold;
  }
  
  th {
    background-color: #E91E63;
    color: white;
    font-weight: bold;
    text-align: center;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    font-size: 11px;
  }
  
  td {
    background-color: white;
    color: #000;
    font-weight: bold;
    font-size: 11px;
  }
  
  /* Prevent Time column from wrapping */
  td:nth-child(3), th:nth-child(3) {
    white-space: nowrap;
    min-width: 120px;
  }
  
  .summary-row {
    background-color: #F8BBD0;
  }
  
  .summary-label {
    background-color: white;
    font-weight: bold;
    font-size: 11px;
  }
  
  .summary-value {
    background-color: #F8BBD0;
    text-align: right;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    font-size: 11px;
    font-weight: bold;
  }
  
  @media print {
    .no-print { display: none !important; }
    body { margin: 6mm; }
    * {
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
      color-adjust: exact !important;
    }
  }
</style>
</head>
<body>
  <div class="title-header">Daily Schedule</div>
  
  <div class="info-header">
    <div class="info-left">Date: <?=htmlspecialchars($date)?></div>
    <div class="info-right">Driver Name: <?=htmlspecialchars($driver['name'] ?? ('#'.$driverId))?> <span style="color:#999;"></span></div>
  </div>

  <?php if (!$jobs): ?>
    <p>No jobs for this day.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>SN</th>
          <th>Name</th>
          <th>Time</th>
          <th>HO</th>
          <th>Client Name</th>
          <th>Services</th>
          <th>Address</th>
          <th>Ma</th>
          <th>Pa</th>
          <th>Remark</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($jobs as $j): ?>
        <tr>
          <td style="text-align:center;"><?=$j['sn']?></td>
          <td><?=htmlspecialchars($j['name'])?></td>
          <td><?=htmlspecialchars($j['time'])?></td>
          <td style="text-align:center;"><?=$j['hours']?></td>
          <td><?=htmlspecialchars($j['client'])?></td>
          <td><?=htmlspecialchars($j['services'])?></td>
          <td><?=htmlspecialchars($j['address'])?></td>
          <td style="text-align:center;"><?=htmlspecialchars($j['materials'])?></td>
          <td style="text-align:center;"><?=htmlspecialchars($j['payment'])?></td>
          <td><?=htmlspecialchars($j['remark'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" class="summary-label">Total Hours</td>
          <td class="summary-value"><?=number_format($totalHours, 1)?></td>
          <td class="summary-value">Hours</td>
          <td colspan="5"></td>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>

  <div class="no-print" style="margin-top:16px;">
    <button onclick="window.print()">Print / Save as PDF</button>
  </div>
</body>
</html>
<?php
$html = ob_get_clean();
      // ---- DEBUG switch: show HTML instead of PDF when &raw=1 ----
      if (isset($_GET['raw']) && $_GET['raw'] === '1') {
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
      }
// Try Dompdf; if not installed, output HTML directly
$useDompdf = class_exists('\\Dompdf\\Dompdf');

if (!$useDompdf) {
  // (A) Print-friendly HTML fallback (no forced download)
  header('Content-Type: text/html; charset=utf-8');
  echo $html;
  exit;
}

// (B) Real PDF with Dompdf
require_once __DIR__.'/../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$opt = new Options();
$opt->set('isRemoteEnabled', true);

$dompdf = new Dompdf($opt);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// inline = open in-browser; attachment = force download
$filename = 'driver-'.$driverId.'-'.$date.'.pdf';
$stream   = ($_GET['dl'] ?? '') === '1' ? ['Attachment' => true] : ['Attachment' => false];

header('Cache-Control: private, max-age=60');
$dompdf->stream($filename, $stream);
