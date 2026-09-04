<?php
// operation/daily_schedule_print.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

// -------- Inputs --------
$date   = $_GET['date']   ?? '';
$driver = $_GET['driver'] ?? '';  // nickname stored in make_order.driver_name
$car    = $_GET['car']    ?? '';

if (!$date) {
    http_response_code(400);
    echo "Missing required parameter: date (YYYY-MM-DD).";
    exit;
}

// -------- Helpers --------
function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function time_compact(?string $s): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $parts = explode(' To ', $s);
    if (count($parts) !== 2) return h($s);
    $from = preg_replace('/\.00$/', '', trim($parts[0]));
    $to   = preg_replace('/\.00$/', '', trim($parts[1]));
    return h($from . '–' . $to);
}

// -------- Data --------
$where = "WHERE date = :date";
$params = [':date' => $date];

if ($driver !== '') {
    $where .= " AND driver_name = :driver";
    $params[':driver'] = $driver;
}

// IMPORTANT: earliest first by time string
$sql = "SELECT * FROM make_order
        $where
        ORDER BY LENGTH(time), time, id";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build worker map
$worker_map = [];
$stmtW = $conn->query("SELECT id, nickname FROM workers");
while ($row = $stmtW->fetch(PDO::FETCH_ASSOC)) {
    $worker_map[(int)$row['id']] = $row['nickname'];
}

// Load order_workers for these orders
$order_ids = array_column($orders, 'id');
$all_order_workers = [];
if (!empty($order_ids)) {
    $ph = implode(',', array_fill(0, count($order_ids), '?'));
    $stmtOW = $conn->prepare("SELECT order_id, worker_id FROM order_workers WHERE order_id IN ($ph)");
    $stmtOW->execute($order_ids);
    while ($ow = $stmtOW->fetch(PDO::FETCH_ASSOC)) {
        $oid = (int)$ow['order_id'];
        $wid = (int)$ow['worker_id'];
        if (!isset($all_order_workers[$oid])) $all_order_workers[$oid] = [];
        $all_order_workers[$oid][] = $wid;
    }
}

// Totals
$total_hours  = 0.0;
$total_amount = 0.0;
$total_jobs   = count($orders);

// For PDF button link
$query_for_pdf = http_build_query(['date' => $date, 'driver' => $driver, 'car' => $car]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Daily Schedule (Print)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Optional: Bootstrap just for nicer on-screen; print styles below keep it clean -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color:#222; }
  .container-print { max-width: 1100px; margin: 24px auto; }
  .page-head { display:flex; gap:12px; align-items:flex-start; justify-content:space-between; margin-bottom: 12px; }
  .title { margin:0; font-weight:700; color:#111; }
  .meta small { display:block; color:#555; }
  .toolbar { display:flex; gap:8px; }
  table { width:100%; border-collapse: collapse; }
  th, td { border:1px solid #333; padding:6px 6px; vertical-align: top; font-size: 12px; }
  thead th { background:#333; color:#fff; }
  tfoot th { background:#f0f0f0; }
  .right { text-align: right; }
  .muted { color:#666; }

  /* Column widths tuned for A4 portrait */
  th.col-time    { width: 70px;  }
  th.col-client  { width: 160px; }
  th.col-workers { width: 220px; }
  th.col-hours   { width: 70px;  }
  th.col-amount  { width: 90px;  }
  th.col-remark  { width: 140px; }
  th.col-notes   { width: 140px; }

  .pill {
    border:1px solid #bbb; border-radius:10px; padding:1px 6px; margin-left:6px; font-size:10px; color:#333;
  }

  @media print {
    .no-print { display:none !important; }
    body { margin:0; }
    .container-print { margin:0; padding:0; max-width:none; }
  }
</style>
</head>
<body>
<div class="container-print">
  <div class="page-head">
    <div>
      <h2 class="title">Daily Schedule</h2>
      <div class="meta">
        <small><strong>Date:</strong> <?= h($date) ?></small>
        <?php if ($driver !== ''): ?>
          <small><strong>Driver:</strong> <?= h($driver) ?></small>
        <?php endif; ?>
        <?php if ($car !== ''): ?>
          <small><strong>Car:</strong> <?= h($car) ?></small>
        <?php endif; ?>
        <small class="muted">Generated at <?= date('Y-m-d H:i') ?></small>
      </div>
    </div>
    <div class="toolbar no-print">
      <a href="javascript:history.back()" class="btn btn-outline-secondary">Back</a>
      <a href="daily_schedule_pdf.php?<?= $query_for_pdf ?>" target="_blank" class="btn btn-outline-primary">PDF</a>
      <button class="btn btn-dark" onclick="window.print()">Print</button>
    </div>
  </div>

  <div class="table-responsive">
    <table>
      <thead>
        <tr>
          <th class="col-time">Time</th>
          <th class="col-client">Client</th>
          <th>Address</th>
          <th class="col-workers">Workers</th>
          <th class="col-hours right">Hours</th>
          <th class="col-amount right">Amount</th>
          <th class="col-remark">Remark</th>
          <th class="col-notes">Notes</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($orders): ?>
        <?php foreach ($orders as $o): ?>
          <?php
          $names = [];
          if (!empty($all_order_workers[$o['id']])) {
              foreach ($all_order_workers[$o['id']] as $wid) {
                  if (isset($worker_map[$wid])) $names[] = h($worker_map[$wid]);
              }
          }
          if (!$names) {
              $fallback = array_map('trim', explode(',', str_replace(' , ', ',', $o['worker_name'] ?? '')));
              foreach ($fallback as $n) if ($n!=='') $names[] = h($n);
          }
          $wcount = max(1, count($names));
          $hours  = (float)$o['hours'];
          $amount = (float)$o['total'];
          $total_hours  += $hours;
          $total_amount += $amount;
          ?>
          <tr>
            <td><?= time_compact($o['time'] ?? '') ?></td>
            <td><?= h($o['client_name']) ?></td>
            <td><?= h($o['address_o']) ?></td>
            <td>
              <?= $names ? implode(' , ', $names) : '' ?>
              <span class="pill"><?= $wcount ?></span>
            </td>
            <td class="right"><?= number_format($hours, 2) ?></td>
            <td class="right"><?= number_format($amount, 2) ?></td>
            <td><?= h($o['remark']) ?></td>
            <td><?= h($o['Notes']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="8" class="text-center muted">No jobs found for this selection.</td></tr>
      <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="4" class="right">Totals:</th>
          <th class="right"><?= number_format($total_hours, 2) ?></th>
          <th class="right"><?= 'AED ' . number_format($total_amount, 2) ?></th>
          <th colspan="2" class="muted">Jobs: <?= (int)$total_jobs ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
</body>
</html>
