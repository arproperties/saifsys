<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';

// ---- helpers ---------------------------------------------------------------
function car_label($row) {
    foreach (['plate','car_number','number','carno','car_no','car'] as $k) {
        if (!empty($row[$k])) return $row[$k];
    }
    return $row['id'] ?? '';
}
function time_compact($t) {
    // expects "8 To 11" or "08:00 To 11:00"
    if (!$t) return '';
    $parts = explode(' To ', $t);
    if (count($parts) !== 2) return htmlspecialchars($t);
    return htmlspecialchars(trim($parts[0]).'–'.trim($parts[1]));
}

// ---- filters ---------------------------------------------------------------
$date   = $_GET['date']   ?? date('Y-m-d');
$driver = $_GET['driver'] ?? '';
$car    = $_GET['car']    ?? '';

// preload dropdowns
$drivers = $conn->query("SELECT nickname FROM driver ORDER BY nickname ASC")->fetchAll(PDO::FETCH_COLUMN);
try {
    $cars = $conn->query("SELECT * FROM cars ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $cars = []; // cars table might not exist yet
}

// fetch orders for the day/driver
$params = [':d'=>$date];
$where  = "date = :d";
if ($driver !== '') { $where .= " AND driver_name = :dr"; $params[':dr'] = $driver; }

$sql = "SELECT id, client_name, address_o, worker_name, time, hours, total, remark, Notes, driver_name
        FROM make_order
        WHERE $where
        ORDER BY CAST(REPLACE(SUBSTRING_INDEX(time, ' To ', 1), ',', '.') AS DECIMAL(5,2)),
                 LENGTH(time), time";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// workers map (id -> nickname)
$worker_map = [];
try {
    $wstmt = $conn->query("SELECT id, nickname FROM workers");
    while ($r = $wstmt->fetch(PDO::FETCH_ASSOC)) {
        $worker_map[$r['id']] = $r['nickname'];
    }
} catch (Throwable $e) {}

// gather order_workers for displayed orders
$all_order_workers = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $ow = $conn->prepare("SELECT order_id, worker_id FROM order_workers WHERE order_id IN ($ph)");
        $ow->execute($ids);
        while ($r = $ow->fetch(PDO::FETCH_ASSOC)) {
            $all_order_workers[$r['order_id']][] = (int)$r['worker_id'];
        }
    } catch (Throwable $e) {}
}

// totals
$total_jobs   = count($orders);
$total_hours  = 0.0;
$total_amount = 0.0;
foreach ($orders as $r) {
    $total_hours  += (float)$r['hours'];
    $total_amount += (float)$r['total'];
}

// build query string for PDF/Print buttons
$q = http_build_query([
    'date'   => $date,
    'driver' => $driver,
    'car'    => $car,
]);
?>
<div class="container-fluid">
    <!-- Filters -->
    <div class="row g-2 align-items-end mb-3">
        <div class="col-md-3">
            <label class="form-label">Date</label>
            <input type="date" class="form-control" id="ds-date" value="<?= htmlspecialchars($date) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Driver</label>
            <select class="form-select" id="ds-driver">
                <option value="">-- Any driver --</option>
                <?php foreach ($drivers as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>" <?= $driver===$d?'selected':'' ?>>
                        <?= htmlspecialchars($d) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Car</label>
            <select class="form-select" id="ds-car">
                <option value="">-- No car --</option>
                <?php foreach ($cars as $c): $label = car_label($c); ?>
                    <option value="<?= htmlspecialchars($label) ?>" <?= $car===$label?'selected':'' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary" id="ds-apply">
                <i class="bi bi-search"></i> Apply
            </button>
            <a class="btn btn-danger" target="_blank"
               href="operation/daily_schedule_pdf.php?<?= $q ?>">
                <i class="bi bi-file-earmark-pdf"></i> PDF
            </a>
            <a class="btn btn-outline-secondary" target="_blank"
               href="operation/daily_schedule_print.php?<?= $q ?>">
                <i class="bi bi-printer"></i> Print View
            </a>
        </div>
    </div>

    <!-- Card -->
    <div class="card shadow-sm rounded-4">
        <div class="card-header bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-bold">Daily Driver Schedules</div>
                    <div class="text-muted small">Date: <?= htmlspecialchars($date) ?></div>
                    <?php if ($driver): ?>
                        <div class="text-muted small">Driver: <?= htmlspecialchars($driver) ?><?= $car ? ' — Car: '.htmlspecialchars($car) : '' ?></div>
                    <?php endif; ?>
                </div>
                <span class="badge text-bg-light"><?= $total_jobs ?> jobs</span>
            </div>
        </div>
        <div class="card-body pt-2">
            <?php if ($orders): ?>
                <div class="table-responsive">
                    <style>
  /* keeps widths stable on screen & print */
  .ds-table { table-layout: fixed; }
  .ds-nowrap { white-space: nowrap; }
  .ds-right { text-align: right; }
  .ds-address { word-break: break-word; }
</style>

<table class="table table-bordered align-middle ds-table">
  <colgroup>
    <col style="width:11%"><!-- Time -->
    <col style="width:17%"><!-- Client -->
    <col style="width:27%"><!-- Address -->
    <col style="width:17%"><!-- Workers -->
    <col style="width:8%"><!-- Hours -->
    <col style="width:8%"><!-- Amount -->
    <col style="width:6%"><!-- Remark -->
    <col style="width:6%"><!-- Notes -->
  </colgroup>

  <thead class="table-dark">
    <tr>
      <th>Time</th>
      <th>Client</th>
      <th>Address</th>
      <th>Workers</th>
      <th class="ds-right">Hours</th>
      <th class="ds-right">Amount</th>
      <th>Remark</th>
      <th>Notes</th>
    </tr>
  </thead>

  <tbody>
  <?php foreach ($orders as $r): ?>
    <?php
      // workers (prefer order_workers -> workers table; fallback to stored string)
      $names = [];
      if (!empty($all_order_workers[$r['id']])) {
          foreach ($all_order_workers[$r['id']] as $wid) {
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
      <td class="ds-nowrap"><?= time_compact($r['time']) ?></td>
      <td><?= htmlspecialchars($r['client_name']) ?></td>
      <td class="ds-address"><?= htmlspecialchars($r['address_o']) ?></td>
      <td>
        <?= $names ? implode(' , ', $names) : '' ?>
        <?php if ($wcount): ?>
          <span class="badge bg-light text-dark ms-2"><?= $wcount ?></span>
        <?php endif; ?>
      </td>
      <td class="ds-right"><?= number_format((float)$r['hours'], 2) ?></td>
      <td class="ds-right"><?= number_format((float)$r['total'], 2) ?></td>
      <td><?= htmlspecialchars($r['remark']) ?></td>
      <td><?= htmlspecialchars($r['Notes']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>

  <tfoot>
    <tr>
      <th colspan="4" class="text-end">Totals:</th>
      <th class="ds-right"><?= number_format($total_hours, 2) ?></th>
      <th class="ds-right"><?= 'AED '.number_format($total_amount, 2) ?></th>
      <th colspan="2" class="text-muted">Jobs: <?= $total_jobs ?></th>
    </tr>
  </tfoot>
</table>

                </div>
            <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inboxes" style="font-size:44px"></i>
                    <div class="mt-2">No jobs found for the selected day/driver.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('ds-apply').addEventListener('click', function(){
    const d = document.getElementById('ds-date').value;
    const dr= document.getElementById('ds-driver').value;
    const car=document.getElementById('ds-car').value;
    const qs = new URLSearchParams({tab:'dailyschedule', date:d, driver:dr, car:car}).toString();
    // navigate within operation.php
    window.location.href = 'operation.php?'+qs;
});
</script>
