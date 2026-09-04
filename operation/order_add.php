<?php
// operation/order_add.php

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_once __DIR__.'/../includes/overlap.php';
require_once __DIR__.'/../includes/work_order_financial_guard.php';
/* -----------------------------------------------------------
   Lookups
----------------------------------------------------------- */
$clients = $conn->query("
  SELECT id, client_name, email, mobile_num, address,
         rate AS rate_num, terms, default_vat_rate, credit_limit
  FROM client
  ORDER BY client_name
")->fetchAll(PDO::FETCH_ASSOC);

$drivers   = $conn->query("SELECT id, nickname FROM driver ORDER BY nickname")->fetchAll(PDO::FETCH_ASSOC);
$companies = $conn->query("SELECT * FROM comp_sa  ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$workers   = $conn->query("SELECT id, nickname FROM workers ORDER BY nickname")->fetchAll(PDO::FETCH_ASSOC);
$times     = $conn->query("SELECT from_time, to_time FROM fromtime")->fetchAll(PDO::FETCH_ASSOC);
$services  = $conn->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$clientById = [];
foreach ($clients as $c) $clientById[(int)$c['id']] = $c;

$errors  = [];
$success = false;

/* -----------------------------------------------------------
   Helpers
----------------------------------------------------------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** outstanding via view if present; otherwise fallback calc */
function getOutstandingForClient(PDO $conn, int $client_id): float {
  try {
    $st = $conn->prepare("SELECT outstanding FROM v_client_ar_balance WHERE client_id = ?");
    $st->execute([$client_id]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null) return (float)$v;
  } catch (Throwable $e) {}

  $sql = "
    SELECT
      COALESCE(SUM(CASE WHEN i.status IN ('issued','partially_paid') THEN i.total ELSE 0 END),0)
      - COALESCE(SUM(op.amount),0)
    FROM client c
    LEFT JOIN invoices i    ON i.client_id = c.id AND i.status IN ('issued','partially_paid')
    LEFT JOIN make_order mo ON mo.id = i.order_id
    LEFT JOIN order_payment op ON op.order_id = mo.id
    WHERE c.id = ?
  ";
  $st = $conn->prepare($sql);
  $st->execute([$client_id]);
  return (float)($st->fetchColumn() ?? 0);
}

/** given numeric like 7.00 or 7.30 -> 'HH:MM:SS' */
function hoursFloatToTimeStr($f): string {
  $f = (float)$f;
  $h = (int)floor($f);
  // values are like 7.00, 7.50, 7.30 (where .30 means 30 minutes)
  $hundreds = (int)round(($f - $h) * 100);    // 0..99
  $m = (int)round($hundreds * 60 / 100);      // convert “hundred minutes” to minutes
  if ($m >= 60) { $h += 1; $m -= 60; }
  return sprintf('%02d:%02d:00', $h, $m);
}

/** subtotal/vat/total when using hours × fee */
function computeTotals(float $fee, float $hours, bool $addVat, float $vatRate): array {
  $sub = round($fee * $hours, 2);
  $vat = $addVat ? round($sub * ($vatRate/100), 2) : 0.00;
  return [$sub, $vat, round($sub + $vat, 2)];
}

/* -----------------------------------------------------------
   POST
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  // Basics
  $client_id   = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
  $client_name = $_POST['client_name'] ?? '';
  $email   = $_POST['email'] ?? '';
  $address = $_POST['address'] ?? '';
  $phone   = $_POST['phone'] ?? '';
  $fee     = (float)($_POST['fee_charge'] ?? 0);
  $remark  = $_POST['remark'] ?? '';
  $need_materials = isset($_POST['need_materials']) ? (int)$_POST['need_materials'] : 0; // 1 or 0
  $materials_note = $_POST['materials_note'] ?? '';
  $note    = $_POST['note'] ?? '';

  // Driver: store BOTH id and name
  $driver_id   = isset($_POST['driver_id']) ? (int)$_POST['driver_id'] : 0;
  $driver_name = $_POST['driver_name'] ?? ''; // will be auto-filled from list

  $company_name = $_POST['company_name'] ?? '';
  $vat_included = $_POST['vat_included'] ?? 'yes'; // 'no' means add VAT
  $booking_type = $_POST['booking_type'] ?? 'once';
  $user_id      = current_user_id();
  $override     = !empty($_POST['override_credit_limit']);

  // Workers
  $worker_ids = isset($_POST['worker_ids']) && is_array($_POST['worker_ids'])
    ? array_values(array_filter(array_map('intval', $_POST['worker_ids'])))
    : [];

  // Services (optional)
  $svc_ids       = $_POST['svc_service_id'] ?? [];
  $svc_qtys      = $_POST['svc_qty'] ?? [];
  $svc_units     = $_POST['svc_unit'] ?? [];
  $svc_prices    = $_POST['svc_unit_price'] ?? [];
  $svc_vat_rates = $_POST['svc_vat_rate'] ?? [];

  $service_rows = [];
  $svc_sub = $svc_vat = $svc_tot = 0.00;

  // Client defaults
  $cRow          = $clientById[$client_id] ?? null;
  $terms         = $cRow['terms'] ?? 'cash';
  $clientVatRate = (float)($cRow['default_vat_rate'] ?? 5.00);
  $creditLimit   = (float)($cRow['credit_limit'] ?? 0);
  $shouldAddVat  = ($vat_included === 'no');

  // Validate
  if (!$client_id)   $errors[] = 'Client is required.';
  if (!$client_name) $errors[] = 'Client Name is required.';
  if (!$driver_id)   $errors[] = 'Driver Name is required.';
  if (empty($worker_ids)) $errors[] = 'At least one worker is required.';
  if ($fee <= 0 && empty($svc_ids)) $errors[] = 'Fee Charge (per hour) must be > 0 or add services.';
  if (!$cRow) $errors[] = 'Selected client not found.';

  // Build worker name string (legacy text)
  $worker_names = [];
  if ($worker_ids) {
    $ph = implode(',', array_fill(0, count($worker_ids), '?'));
    $st = $conn->prepare("SELECT nickname FROM workers WHERE id IN ($ph)");
    $st->execute($worker_ids);
    $worker_names = $st->fetchAll(PDO::FETCH_COLUMN);
  }
  $worker_name_str = implode(' , ', $worker_names);
  $num_cleaners = max(1, count($worker_ids));

  // Driver name from id (safety)
  if ($driver_id && !$driver_name) {
    $dn = $conn->prepare("SELECT nickname FROM driver WHERE id=?");
    $dn->execute([$driver_id]);
    $driver_name = (string)$dn->fetchColumn();
  }

  // Normalize service rows
  $svcNameById = [];
  foreach ($services as $s) $svcNameById[(int)$s['id']] = $s['name'];

  for ($i=0; $i<count($svc_ids); $i++) {
    $sid = (int)($svc_ids[$i] ?? 0);
    if ($sid<=0) continue;
    $qty   = (float)($svc_qtys[$i] ?? 0);
    $unit  = trim($svc_units[$i] ?? 'hour');
    $price = (float)($svc_prices[$i] ?? 0);
    $vrate = (float)($svc_vat_rates[$i] ?? $clientVatRate);
    if ($qty<=0 || $price<0) continue;

    $lineSub = round($qty*$price,2);
    $lineVat = $shouldAddVat ? round($lineSub*($vrate/100),2) : 0.00;
    $lineTot = $lineSub + $lineVat;

    $svc_sub += $lineSub; $svc_vat += $lineVat; $svc_tot += $lineTot;

    $service_rows[] = [
      'service_id'=>$sid,
      'service_name'=>$svcNameById[$sid] ?? null,
      'qty'=>$qty, 'unit'=>$unit, 'unit_price'=>$price, 'vat_rate'=>$vrate
    ];
  }

  // Build occurrences by booking type
  $occ = [];
  if ($booking_type==='once') {
    $d = $_POST['once_date'] ?? '';
    $f = $_POST['once_time_from'] ?? '';
    $t = $_POST['once_time_to'] ?? '';
    if (!$d) $errors[]='Date is required for Once.';
    if (!$f || !$t) $errors[]='Time From and To are required for Once.';
    if (!$errors) $occ[] = ['date'=>$d,'from'=>$f,'to'=>$t];
  } elseif ($booking_type==='daily') {
    $df = $_POST['daily_from'] ?? '';
    $dt = $_POST['daily_to'] ?? '';
    $f  = $_POST['daily_time_from'] ?? '';
    $t  = $_POST['daily_time_to'] ?? '';
    if (!$df) $errors[]='Date From is required for Daily.';
    if (!$dt) $errors[]='Date To is required for Daily.';
    if (!$f || !$t) $errors[]='Time From and To are required for Daily.';
    $s = strtotime($df); $e = strtotime($dt);
    if (!$errors && $s && $e && $e >= $s) {
      for ($x=$s; $x<=$e; $x+=86400) $occ[] = ['date'=>date('Y-m-d',$x),'from'=>$f,'to'=>$t];
    }
  } elseif ($booking_type==='weekly') {
    $df = $_POST['weekly_from'] ?? '';
    $dt = $_POST['weekly_to'] ?? '';
    $f  = $_POST['weekly_time_from'] ?? '';
    $t  = $_POST['weekly_time_to'] ?? '';
    $days = $_POST['weekly_days'] ?? [];
    if (!$df) $errors[]='Date From is required for Weekly.';
    if (!$dt) $errors[]='Date To is required for Weekly.';
    if (!$f || !$t) $errors[]='Time From and To are required for Weekly.';
    if (empty($days)) $errors[]='Select at least one weekday for Weekly.';
    $s = strtotime($df); $e = strtotime($dt);
    if (!$errors && $s && $e && $e >= $s) {
      for ($x=$s; $x<=$e; $x+=86400) {
        $wk = date('l',$x);
        if (in_array($wk,$days,true)) $occ[] = ['date'=>date('Y-m-d',$x),'from'=>$f,'to'=>$t];
      }
    }
  } elseif ($booking_type==='custom') {
    $cds = $_POST['custom_dates'] ?? [];
    foreach ($cds as $cd) {
      $d=$cd['date']??''; $f=$cd['time_from']??''; $t=$cd['time_to']??'';
      if ($d && $f && $t) $occ[] = ['date'=>$d,'from'=>$f,'to'=>$t];
    }
    if (!$occ) $errors[]='Add at least one custom date/time.';
  }

  if (!$errors) {
    $currentOutstanding = getOutstandingForClient($conn, $client_id);
    $accumNew = 0.00;
      
    // Statements
      // Get current company_id
      require_once __DIR__ . '/../includes/company_helper.php';
      $currentCompanyId = current_company_id($conn) ?: 1;
      
      $insOrder = $conn->prepare("
        INSERT INTO make_order
          (company_id, client_id, client_name, worker_name, email_o, address_o, mobile_num_o,
           fee_charged, hourly_rate, payment, date, service_date, time, start_time, end_time,
           hours, total, balance,
           need_materials, materials_note,        -- NEW
           remark, notes, driver_name, driver_id,
           net_hours, net_amount, amount_afc, discount_amount,
           vat_rate, vat_amount, grand_total, status, payment_status,
           created_at, created_by)
        VALUES
          (?,?,?,?,?,?,?,?,?,?,
           ?, ?, ?, ?, ?,
           ?, ?, 0.00,
           ?, ?,                       -- NEW
           ?, ?, ?, ?,
           ?, ?, ?, 0.00,
           ?, ?, ?, 'confirmed','unpaid',
           NOW(), ?)
      ");

    $insOW  = $conn->prepare("INSERT INTO order_workers (order_id, worker_id) VALUES (?, ?)");
    $insSvc = $conn->prepare("
      INSERT INTO order_services
        (order_id, service_id, service_name, description, qty, unit, unit_price, vat_rate)
      VALUES (?,?,?,?,?,?,?,?)
    ");

    foreach ($occ as $o) {
      $tf = (float)$o['from']; $tt = (float)$o['to'];
      $fromTime = hoursFloatToTimeStr($tf);
      $toTime   = hoursFloatToTimeStr($tt);
      
        // convert the just-computed strings "HH:MM:SS" to "HH:MM"
        $svcDate = $o['date'];
        $startHH = substr($fromTime, 0, 5);
        $endHH   = substr($toTime,   0, 5);

        // overlap validation for each selected worker (for this occurrence)
        foreach ((array)$worker_ids as $wid) {
          $conflicts = findOverlap($conn, (int)$wid, $svcDate, $startHH, $endHH, 0);
          if ($conflicts) {
            $c = $conflicts[0];
              $wname = $conn->prepare("SELECT nickname FROM workers WHERE id=?");
              $wname->execute([$wid]);
              $wlabel = $wname->fetchColumn() ?: ('Worker #'.$wid);

              $errors[] = "Overlap for {$wlabel} on {$svcDate}: conflicts with Order #{$c['id']} "
                        . "({$c['start_time']}–{$c['end_time']}" . ($c['client'] ? " • {$c['client']}" : "") . ").";
            break; // optional: stop at first worker conflict
          }
        }
        if (!empty($errors)) break; // stop processing more occurrences if there was an overlap
      
      $durPerCleaner = max(0, $tt - $tf);        // e.g., 2.00 hours
      $hoursBooking  = round($durPerCleaner * $num_cleaners, 2);

      if ($service_rows) {
        $sub=$svc_sub; $vat=$svc_vat; $tot=$svc_tot;
      } else {
        [$sub,$vat,$tot] = computeTotals($fee, $hoursBooking, $shouldAddVat, $clientVatRate);
      }

      // credit limit check (per occurrence)
      if ($creditLimit > 0) {
        $projected = $currentOutstanding + $accumNew + $tot;
        if ($projected > $creditLimit && !$override) {
          $errors[] =
            "Credit limit exceeded for client '{$client_name}'. "
          . "Outstanding: ".number_format($currentOutstanding,2)
          . " + This order: ".number_format($tot,2)
          . " = ".number_format($projected,2)
          . " (Limit: ".number_format($creditLimit,2)."). "
          . "Tick the override checkbox to proceed.";
          break;
        }
      }
    
        
      

      // Insert order
        $insOrder->execute([
          $currentCompanyId, $client_id, $client_name, $worker_name_str, $email, $address, $phone,
          $fee, $fee, $terms,
          $o['date'], $o['date'], ($tf.' To '.$tt), $fromTime, $toTime,
          $hoursBooking, $sub, /* balance 0.00 literal */
          $need_materials, $materials_note,          // NEW
          $remark, $note, $driver_name, $driver_id,
          $durPerCleaner, ($durPerCleaner * $fee), $sub,
          $clientVatRate, $vat, $tot, $user_id
        ]);

      $order_id = (int)$conn->lastInsertId();
      
      // Audit Log: Track order creation
      require_once __DIR__ . '/../includes/AuditService.php';
      AuditService::logCreate('make_order', $order_id, [
        'client_name' => $client_name,
        'service_date' => $o['date'],
        'worker_count' => count($worker_ids),
        'total' => $tot
      ], "Created order #{$order_id} for {$client_name} on {$o['date']}", $user_id ? (int)$user_id : null);

      // link workers
      foreach ($worker_ids as $wid) $insOW->execute([$order_id, $wid]);

      // services (if any)
      if ($service_rows) {
        foreach ($service_rows as $sr) {
          $insSvc->execute([
            $order_id,
            $sr['service_id'],
            $sr['service_name'],
            null,
            $sr['qty'],
            $sr['unit'],
            $sr['unit_price'],
            $sr['vat_rate']
          ]);
        }
      }

      // Invoice deferred until Admin/Accountant finalizes (Phase 2)
      $invoice_id = wo_maybe_sync_invoice_after_order_change($conn, $order_id, $user_id);
      if ($invoice_id) {
        $up = $conn->prepare("UPDATE make_order SET invoice_id = :iid WHERE id = :id");
        $up->execute([':iid'=>$invoice_id, ':id'=>$order_id]);
      }
      wo_sync_ops_status_column($conn, $order_id, 'confirmed');

      $accumNew += $tot;
    }

    if (!$errors) $success = true;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Add New Work Order</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f8fafb}
    .card{margin-top:32px}
    .nav-tabs .nav-link.active{background:#08c!important;color:#fff!important}
    .booking-option{display:none}
    .booking-option.active{display:block}
  </style>
</head>
<body>
<div class="container py-5">
  <div class="row justify-content-center"><div class="col-xl-10 col-lg-12">

    <?php if ($errors): ?>
      <div class="alert alert-danger"><strong>Fix these issues:</strong><br><?= implode("<br>", array_map('h',$errors)) ?></div>
    <?php elseif ($success): ?>
      <div class="alert alert-success">Order added successfully.</div>
      <script>setTimeout(()=>location.href="../operation.php?tab=workorder",1200);</script>
    <?php endif; ?>

    <form id="order-add-form" method="post" action="order_add.php">
        <?php csrf_field(); ?>
      <!-- Client Info -->
      <div class="card shadow rounded-4 border-0 mb-4">
        <div class="card-header bg-primary text-white rounded-top-4 pb-2"><h4 class="mb-0">Client Info</h4></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label fw-bold">Client Name <span class="text-danger">*</span></label>
              <div class="input-group">
                <select class="form-select" name="client_id" id="client_select" required>
                  <option value="">-- Select Client --</option>
                  <?php foreach ($clients as $c): ?>
                    <option
                      value="<?= (int)$c['id'] ?>"
                      data-name="<?= h($c['client_name']) ?>"
                      data-email="<?= h($c['email']) ?>"
                      data-address="<?= h($c['address']) ?>"
                      data-phone="<?= h($c['mobile_num']) ?>"
                      data-feecharge="<?= h((string)$c['rate_num']) ?>"
                      data-paymentt="<?= h($c['terms']) ?>"
                      data-credit-limit="<?= h((string)($c['credit_limit'] ?? '0')) ?>"
                    ><?= h($c['client_name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="client_name" id="client_name_hidden">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addClientModal">+ Add New Client</button>
              </div>
            </div>
            <div class="col-md-5">
              <label class="form-label">Phone Number</label>
              <input type="text" name="phone" id="phone" class="form-control" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" id="email" class="form-control" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label">Address</label>
              <input type="text" name="address" id="address" class="form-control" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Payment Terms</label>
              <input type="text" name="payment_t" id="payment_t" class="form-control" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Credit Limit</label>
              <input type="text" id="credit_limit" class="form-control" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Fee Charge (per hour) <span class="text-danger">*</span></label>
              <input type="number" name="fee_charge" id="fee_charge" class="form-control" step="0.01" min="0" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Number of Cleaners</label>
              <input type="number" id="num_cleaners" class="form-control" min="1" value="1" required readonly>
            </div>
          </div>
        </div>
      </div>

      <!-- Booking Type -->
      <div class="card mb-4 shadow rounded-4 border-0">
        <div class="card-header bg-info text-white rounded-top-4"><h5 class="mb-0">Booking Type</h5></div>
        <div class="card-body pb-1">
          <input type="hidden" id="booking_type" name="booking_type" value="once">
          <ul class="nav nav-tabs" id="bookingTypeTabs">
            <li class="nav-item"><a class="nav-link active" id="tab-once" href="#">Once</a></li>
            <li class="nav-item"><a class="nav-link" id="tab-daily" href="#">Daily</a></li>
            <li class="nav-item"><a class="nav-link" id="tab-weekly" href="#">Weekly</a></li>
            <li class="nav-item"><a class="nav-link" id="tab-custom" href="#">Custom</a></li>
          </ul>

          <!-- Once -->
          <div id="once-form" class="booking-option active mt-3">
            <div class="row mb-2">
              <div class="col-md-4">
                <label>Date:</label>
                <input type="date" class="form-control" name="once_date" id="once_date">
              </div>
              <div class="col-md-4">
                <label>Time From:</label>
                <select class="form-select" name="once_time_from" id="once_time_from">
                  <option value="">From...</option>
                  <?php foreach ($times as $t): ?>
                    <option value="<?= h($t['from_time']) ?>"><?= h($t['from_time']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label>Time To:</label>
                <select class="form-select" name="once_time_to" id="once_time_to">
                  <option value="">To...</option>
                  <?php foreach ($times as $t): ?>
                    <option value="<?= h($t['to_time']) ?>"><?= h($t['to_time']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="row mt-2">
              <div class="col-md-6">
                <label>Final Hours (this booking):</label>
                <input type="text" class="form-control" id="final_hours_once" readonly>
              </div>
              <div class="col-md-6">
                <label>Final Total (this booking):</label>
                <input type="text" class="form-control" id="final_amount_once" readonly>
              </div>
            </div>
          </div>

          <!-- Daily -->
          <div id="daily-form" class="booking-option mt-3">
            <div class="row mb-2">
              <div class="col-md-4"><label>Date From:</label><input type="date" class="form-control" name="daily_from" id="daily_from"></div>
              <div class="col-md-4"><label>Date To:</label><input type="date" class="form-control" name="daily_to" id="daily_to"></div>
              <div class="col-md-2">
                <label>Time From:</label>
                <select class="form-select" name="daily_time_from" id="daily_time_from">
                  <option value="">From...</option>
                  <?php foreach ($times as $t): ?>
                    <option value="<?= h($t['from_time']) ?>"><?= h($t['from_time']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label>Time To:</label>
                <select class="form-select" name="daily_time_to" id="daily_time_to">
                  <option value="">To...</option>
                  <?php foreach ($times as $t): ?>
                    <option value="<?= h($t['to_time']) ?>"><?= h($t['to_time']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="row mt-2">
              <div class="col-md-6"><label>Final Hours (one day):</label><input type="text" class="form-control" id="final_hours_daily" readonly></div>
              <div class="col-md-6"><label>Final Total (one day):</label><input type="text" class="form-control" id="final_amount_daily" readonly></div>
            </div>
          </div>

          <!-- Weekly -->
          <div id="weekly-form" class="booking-option mt-3">
            <div class="row mb-2">
              <div class="col-md-4"><label>Date From:</label><input type="date" class="form-control" name="weekly_from" id="weekly_from"></div>
              <div class="col-md-4"><label>Date To:</label><input type="date" class="form-control" name="weekly_to" id="weekly_to"></div>
              <div class="col-md-2">
                <label>Time From:</label>
                <select class="form-select" name="weekly_time_from" id="weekly_time_from">
                  <option value="">From...</option>
                  <?php foreach ($times as $t): ?>
                    <option value="<?= h($t['from_time']) ?>"><?= h($t['from_time']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label>Time To:</label>
                <select class="form-select" name="weekly_time_to" id="weekly_time_to">
                  <option value="">To...</option>
                  <?php foreach ($times as $t): ?>
                    <option value="<?= h($t['to_time']) ?>"><?= h($t['to_time']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="row mb-2">
              <div class="col-md-12">
                <label>Select Days:</label><br>
                <?php
                  $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                  $vals = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                  foreach ($days as $i=>$d): ?>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="weekly_days[]" value="<?= $vals[$i] ?>" id="week-<?= strtolower($d) ?>">
                    <label class="form-check-label" for="week-<?= strtolower($d) ?>"><?= $d ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="row mt-2">
              <div class="col-md-6"><label>Final Hours (one day):</label><input type="text" class="form-control" id="final_hours_weekly" readonly></div>
              <div class="col-md-6"><label>Final Total (one day):</label><input type="text" class="form-control" id="final_amount_weekly" readonly></div>
            </div>
          </div>

          <!-- Custom -->
          <div id="custom-form" class="booking-option mt-3">
            <div id="custom-dates-list"></div>
            <button type="button" class="btn btn-outline-primary mt-2" id="add-custom-date">+ Add Date & Time</button>
          </div>
        </div>
      </div>

      <!-- Order details -->
      <div class="card mb-4 shadow rounded-4 border-0">
        <div class="card-header bg-secondary text-white rounded-top-4"><h5 class="mb-0">Order Details</h5></div>
        <div class="card-body pb-1">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Worker Name(s) <span class="text-danger">*</span></label>
              <select name="worker_ids[]" id="worker_names" class="form-select" multiple size="6" required>
                <?php foreach ($workers as $w): ?>
                  <option value="<?= (int)$w['id'] ?>"><?= h($w['nickname']) ?></option>
                <?php endforeach; ?>
              </select>
              <small class="form-text text-muted">Hold Ctrl/Cmd to select multiple.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label">Driver Name <span class="text-danger">*</span></label>
              <select name="driver_id" id="driver_id" class="form-select" required>
                <option value="">-- Select Driver --</option>
                <?php foreach ($drivers as $d): ?>
                  <option value="<?= (int)$d['id'] ?>" data-name="<?= h($d['nickname']) ?>"><?= h($d['nickname']) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="driver_name" id="driver_name">
            </div>

            <div class="col-md-6">
              <label class="form-label">Company Name</label>
              <select name="company_name" id="company_name" class="form-select">
                <option value="">-- Select Company --</option>
                <?php foreach ($companies as $co): ?>
                  <option value="<?= h($co['name']) ?>"><?= h($co['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
        
        <hr class="my-3">

        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label fw-semibold">Materials needed?</label>
            <select name="need_materials" id="need_materials" class="form-select">
              <option value="0">No</option>
              <option value="1">Yes</option>
            </select>
          </div>
          <div class="col-md-9">
            <label class="form-label fw-semibold">Materials note (optional)</label>
            <input type="text" name="materials_note" id="materials_note" class="form-control"
                   placeholder="E.g. bring vacuum, glass cleaner, 2 mops…">
          </div>
        </div>

            <div class="col-12">
              <label class="form-label">Include VAT?</label>
              <div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="vat_included" id="vat_yes" value="yes" checked>
                  <label class="form-check-label" for="vat_yes">Yes (fee includes VAT)</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="vat_included" id="vat_no" value="no">
                  <label class="form-check-label" for="vat_no">No (add VAT)</label>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Services (optional) -->
      <div class="card mb-4 shadow rounded-4 border-0">
        <div class="card-header bg-dark text-white rounded-top-4"><h5 class="mb-0">Services (optional)</h5></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table align-middle" id="svcTable">
              <thead>
                <tr>
                  <th style="width:30%">Service</th>
                  <th style="width:12%">Qty</th>
                  <th style="width:12%">Unit</th>
                  <th style="width:16%">Unit Price</th>
                  <th style="width:12%">VAT %</th>
                  <th style="width:14%" class="text-end">Line Total</th>
                  <th style="width:4%"></th>
                </tr>
              </thead>
              <tbody></tbody>
              <tfoot>
                <tr><td colspan="5" class="text-end fw-semibold">Services Subtotal:</td><td class="text-end"><span id="svcSubtotal">0.00</span></td><td></td></tr>
                <tr><td colspan="5" class="text-end fw-semibold">Services VAT:</td><td class="text-end"><span id="svcVat">0.00</span></td><td></td></tr>
                <tr><td colspan="5" class="text-end fw-bold">Services Total:</td><td class="text-end fw-bold"><span id="svcGrand">0.00</span></td><td></td></tr>
              </tfoot>
            </table>
          </div>
          <button type="button" class="btn btn-outline-primary" id="svcAdd">+ Add service</button>
          <div class="form-text mt-2">
            If you add services, those totals are used (instead of Hours × Fee) for AR & credit check. <strong>Tip:</strong> When using hours as the unit, enter total hours (e.g., for 2 workers × 3 hours = 6 hours).
          </div>
        </div>
      </div>

      <div id="creditAlert" class="alert alert-warning d-none mt-2"></div>
      <div class="form-check me-3 mt-2 d-none" id="overrideWrap">
        <input class="form-check-input" type="checkbox" id="override_credit_limit" name="override_credit_limit" value="1">
        <label class="form-check-label" for="override_credit_limit">Proceed even if the client exceeds their credit limit</label>
      </div>

      <div class="d-flex justify-content-end">
        <button type="submit" class="btn btn-success px-4 me-2">Save Order</button>
        <a href="workorder.php" class="btn btn-secondary px-4">Cancel</a>
      </div>
    </form>

  </div></div>
</div>

<!-- Add Client Modal (unchanged from your version) -->
<div class="modal fade" id="addClientModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="add-client-form" class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add New Client</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Client Name *</label><input type="text" name="client_name" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Payment Type (Ops cadence) *</label>
          <select name="payment" class="form-select" required>
            <option value="">-- Choose --</option>
            <option value="D">D (Daily)</option>
            <option value="W">W (Weekly)</option>
            <option value="Bi-W">Bi-W (Bi-Weekly)</option>
            <option value="M">M (Monthly)</option>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">AR Terms</label>
          <select name="terms" class="form-select">
            <option value="cash">Cash</option>
            <option value="prepaid">Prepaid</option>
            <option value="15d">15 days</option>
            <option value="30d" selected>30 days</option>
            <option value="45d">45 days</option>
            <option value="60d">60 days</option>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">Mobile Number *</label><input type="text" name="mobile_num" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Landline</label><input type="text" name="cell_num" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Default Hourly Rate (AED)</label><input type="number" step="0.01" name="rate" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Address <span class="text-danger">*</span></label><input type="text" name="address" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">TRN Number</label><input type="text" name="trn" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Default VAT %</label><input type="number" step="0.01" name="default_vat_rate" class="form-control" value="5.00"></div>
        <div class="mb-2"><label class="form-label">Credit Limit (AED)</label><input type="number" step="0.01" name="credit_limit" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Opening Balance</label><input type="number" name="balance" step="0.01" class="form-control" placeholder="0.00"></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="key_le" name="key_le" value="Key"><label class="form-check-label" for="key_le">Leave Key?</label></div>
        <div id="add-client-error" class="text-danger mb-2" style="display:none;"></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-success">Save Client</button><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button></div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let AR = {credit_limit:0, outstanding:0, currency:'AED'};

function fillClientFieldsFromOption(opt){
  if(!opt) return;
  document.getElementById('email').value   = opt.getAttribute('data-email')||'';
  document.getElementById('address').value = opt.getAttribute('data-address')||'';
  document.getElementById('phone').value   = opt.getAttribute('data-phone')||'';
  document.getElementById('payment_t').value = opt.getAttribute('data-paymentt')||'';
  document.getElementById('fee_charge').value = opt.getAttribute('data-feecharge')||'';
  document.getElementById('credit_limit').value = opt.getAttribute('data-credit-limit')||'';
  document.getElementById('client_name_hidden').value = opt.getAttribute('data-name')||'';
}

async function fetchClientAR(clientId){
  AR = {credit_limit:0, outstanding:0, currency:'AED'};
  if(!clientId) return;
  try{
    const res = await fetch('ajax_client_ar.php?client_id='+encodeURIComponent(clientId));
    const json = await res.json();
    if(json.ok){
      AR.credit_limit = parseFloat(json.credit_limit)||0;
      AR.outstanding  = parseFloat(json.outstanding)||0;
      AR.currency     = json.currency||'AED';
      document.getElementById('credit_limit').value = AR.credit_limit.toFixed(2);
    }
  }catch(e){}
}

/* Services UI -------------------------------------------------*/
const svcTableBody = document.querySelector('#svcTable tbody');
const SERVICES = [
  <?php foreach ($services as $s): ?>{id:<?= (int)$s['id'] ?>,name:<?= json_encode($s['name']) ?>},<?php endforeach; ?>
];

function escapeHtml(s){return s.replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
function svcRowTemplate(i){
  const opts = SERVICES.map(s=>`<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('');
  return `<tr>
    <td><select class="form-select" name="svc_service_id[${i}]" required><option value="">-- Select --</option>${opts}</select></td>
    <td><input type="number" class="form-control svc-qty" name="svc_qty[${i}]" step="0.01" min="0" value="1"></td>
    <td><select class="form-select svc-unit" name="svc_unit[${i}]"><option>hour</option><option>job</option><option>pcs</option></select></td>
    <td><input type="number" class="form-control svc-price" name="svc_unit_price[${i}]" step="0.01" min="0"></td>
    <td><input type="number" class="form-control svc-vat" name="svc_vat_rate[${i}]" step="0.01" min="0" value="5.00"></td>
    <td class="text-end"><span class="svc-line-total">0.00</span></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger svc-del">&times;</button></td>
  </tr>`;
}
function hookRowEvents(tr){
  tr.querySelectorAll('input,select').forEach(el=>{
    el.addEventListener('input',()=>{recalcServices(); refreshCreditWarning();});
    el.addEventListener('change',()=>{recalcServices(); refreshCreditWarning();});
  });
  tr.querySelector('.svc-del').addEventListener('click',()=>{tr.remove();recalcServices();refreshCreditWarning();});
}
function addServiceRow(){ const i = svcTableBody.children.length; svcTableBody.insertAdjacentHTML('beforeend', svcRowTemplate(i)); hookRowEvents(svcTableBody.lastElementChild); recalcServices(); }
document.getElementById('svcAdd').addEventListener('click', addServiceRow);

function getVatIncluded(){ return document.querySelector('input[name="vat_included"]:checked')?.value !== 'no'; }
function recalcServices(){
  let sub=0, vat=0, tot=0;
  const addVat = !getVatIncluded();
  svcTableBody.querySelectorAll('tr').forEach(tr=>{
    const qty = parseFloat(tr.querySelector('.svc-qty').value)||0;
    const price = parseFloat(tr.querySelector('.svc-price').value)||0;
    const vr = parseFloat(tr.querySelector('.svc-vat').value)||0;
    const lsub = qty*price; const lvat = addVat ? (lsub*(vr/100)) : 0; const ltot = lsub+lvat;
    sub+=lsub; vat+=lvat; tot+=ltot;
    tr.querySelector('.svc-line-total').textContent = ltot.toFixed(2);
  });
  document.getElementById('svcSubtotal').textContent=sub.toFixed(2);
  document.getElementById('svcVat').textContent=vat.toFixed(2);
  document.getElementById('svcGrand').textContent=tot.toFixed(2);
}

/* Hours/fees calc (for credit hint) ---------------------------*/
function parseTime(v){ return parseFloat(v)||0; }
function getNumCleaners(){ const sel=document.getElementById('worker_names'); const n=Array.from(sel.selectedOptions).length; return n>0?n:1; }
function getFee(){ return parseFloat(document.getElementById('fee_charge').value)||0; }
function getVatFactor(){ return (document.querySelector('input[name="vat_included"]:checked')?.value === 'no') ? 1.05 : 1.0; }

function updateCalc(){
  const cleaners = getNumCleaners(), fee=getFee(), vat=getVatFactor();
  const nc=document.getElementById('num_cleaners'); if(nc) nc.value = cleaners;

  // Once
  let f=parseTime(document.getElementById('once_time_from').value), t=parseTime(document.getElementById('once_time_to').value);
  let h=Math.max(0, t-f)*cleaners;
  document.getElementById('final_hours_once').value = h? h.toFixed(2):'';
  document.getElementById('final_amount_once').value = h? (fee*h*vat).toFixed(2):'';

  // Daily
  f=parseTime(document.getElementById('daily_time_from').value); t=parseTime(document.getElementById('daily_time_to').value);
  h=Math.max(0, t-f)*cleaners;
  document.getElementById('final_hours_daily').value = h? h.toFixed(2):'';
  document.getElementById('final_amount_daily').value = h? (fee*h*vat).toFixed(2):'';

  // Weekly
  f=parseTime(document.getElementById('weekly_time_from').value); t=parseTime(document.getElementById('weekly_time_to').value);
  h=Math.max(0, t-f)*cleaners;
  document.getElementById('final_hours_weekly').value = h? h.toFixed(2):'';
  document.getElementById('final_amount_weekly').value = h? (fee*h*vat).toFixed(2):'';

  recalcServices();
}

function countWeekdayBetween(start, end, name){
  const idx=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'].indexOf(name);
  if(idx<0) return 0; let c=0;
  for(let d=new Date(start); d<=end; d.setDate(d.getDate()+1)) if(d.getDay()===idx) c++;
  return c;
}
function computeProjectedTotal(){
  if (svcTableBody.querySelectorAll('tr').length>0) {
    return { projected: parseFloat(document.getElementById('svcGrand').textContent)||0 };
  }
  const fee=getFee(), vat=getVatFactor(), cleaners=getNumCleaners();
  const type=document.getElementById('booking_type').value;
  let per=0, occ=1;
  if(type==='once'){
    const f=parseTime(document.getElementById('once_time_from').value), t=parseTime(document.getElementById('once_time_to').value);
    per=Math.max(0,t-f)*cleaners;
  }else if(type==='daily'){
    const f=parseTime(document.getElementById('daily_time_from').value), t=parseTime(document.getElementById('daily_time_to').value);
    per=Math.max(0,t-f)*cleaners;
    const df=document.getElementById('daily_from').value, dt=document.getElementById('daily_to').value;
    if(df&&dt){ const s=new Date(df), e=new Date(dt); occ=Math.floor((e-s)/86400000)+1; if(occ<1) occ=0; }
  }else if(type==='weekly'){
    const f=parseTime(document.getElementById('weekly_time_from').value), t=parseTime(document.getElementById('weekly_time_to').value);
    per=Math.max(0,t-f)*cleaners;
    const df=document.getElementById('weekly_from').value, dt=document.getElementById('weekly_to').value;
    const checks=Array.from(document.querySelectorAll('input[name="weekly_days[]"]:checked')).map(el=>el.value);
    if(df&&dt&&checks.length){ const s=new Date(df), e=new Date(dt); occ=0; checks.forEach(day=>occ+=countWeekdayBetween(s,new Date(e),day)); } else occ=0;
  }else if(type==='custom'){
    occ=document.querySelectorAll('#custom-dates-list .row').length||1;
    const row=document.querySelector('#custom-dates-list .row');
    if(row){ const fs=row.querySelector('select[name*="[time_from]"]'), ts=row.querySelector('select[name*="[time_to]"]');
      per=Math.max(0,(parseTime(fs?.value||0)-0)-(0-parseTime(ts?.value||0)))*cleaners;
    }
  }
  return { projected: fee*per*occ*vat };
}
function refreshCreditWarning(){
  const box=document.getElementById('creditAlert'), wrap=document.getElementById('overrideWrap'), chk=document.getElementById('override_credit_limit');
  const { projected } = computeProjectedTotal();
  const limit=AR.credit_limit||0, out=AR.outstanding||0, proj=out+Math.round((projected+Number.EPSILON)*100)/100;
  if(limit>0 && proj>limit){ box.classList.remove('d-none'); wrap.classList.remove('d-none');
    box.innerHTML=`<strong>Credit limit warning:</strong> Outstanding ${AR.currency} ${out.toFixed(2)} + this order ${AR.currency} ${(projected||0).toFixed(2)} = ${AR.currency} ${proj.toFixed(2)} (limit ${AR.currency} ${limit.toFixed(2)}). Tick the override checkbox to proceed.`; }
  else{ box.classList.add('d-none'); wrap.classList.add('d-none'); if(chk) chk.checked=false; }
}

/* Events */
document.getElementById('client_select').addEventListener('change', async function(){
  const opt=this.options[this.selectedIndex]; if(!opt||!this.value) return;
  fillClientFieldsFromOption(opt); await fetchClientAR(this.value); updateCalc(); refreshCreditWarning();
});

document.getElementById('worker_names').addEventListener('change',()=>{updateCalc();refreshCreditWarning();});
['once_time_from','once_time_to','daily_time_from','daily_time_to','weekly_time_from','weekly_time_to','fee_charge','daily_from','daily_to','weekly_from','weekly_to']
  .forEach(id=>{ const el=document.getElementById(id); if(el) el.addEventListener('change',()=>{updateCalc();refreshCreditWarning();}); });
Array.from(document.getElementsByName('vat_included')).forEach(r=>r.addEventListener('change',()=>{updateCalc();recalcServices();refreshCreditWarning();}));

document.getElementById('bookingTypeTabs').querySelectorAll('.nav-link').forEach(a=>{
  a.addEventListener('click',e=>{
    e.preventDefault();
    document.querySelectorAll('#bookingTypeTabs .nav-link').forEach(x=>x.classList.remove('active'));
    a.classList.add('active');
    const type=a.id.replace('tab-','');
    document.getElementById('booking_type').value=type;
    document.querySelectorAll('.booking-option').forEach(div=>div.classList.remove('active'));
    document.getElementById(type+'-form').classList.add('active');
    updateCalc(); refreshCreditWarning();
  });
});

document.getElementById('driver_id').addEventListener('change', function(){
  const name=this.options[this.selectedIndex]?.getAttribute('data-name')||'';
  document.getElementById('driver_name').value = name;
});

document.addEventListener('DOMContentLoaded', ()=>{ 
  updateCalc(); 
  
  // Clear add client form errors when modal is shown
  const addClientModal = document.getElementById('addClientModal');
  if (addClientModal) {
    addClientModal.addEventListener('show.bs.modal', function() {
      const errorDiv = document.getElementById('add-client-error');
      if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
      }
    });
  }
});

// Add Client Form Handler
document.getElementById('add-client-form').addEventListener('submit', function(e) {
  e.preventDefault();
  
  const formData = new FormData(this);
  const submitBtn = this.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
  // Show loading state
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
  submitBtn.disabled = true;
  
  fetch('ajax_add_client.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      // Show success message
      const errorDiv = document.getElementById('add-client-error');
      errorDiv.innerHTML = '<div class="alert alert-success">Client added successfully!</div>';
      errorDiv.style.display = 'block';
      
      // Close modal after a short delay
      setTimeout(() => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('addClientModal'));
        if (modal) {
          modal.hide();
        }
        // Reset form
        this.reset();
        // Reload the page to refresh the client list
        location.reload();
      }, 1500);
    } else {
      // Show error message
      const errorDiv = document.getElementById('add-client-error');
      errorDiv.innerHTML = '<div class="alert alert-danger">Error: ' + (data.error || 'Failed to add client') + '</div>';
      errorDiv.style.display = 'block';
    }
  })
  .catch(error => {
    console.error('Add client error:', error);
    const errorDiv = document.getElementById('add-client-error');
    errorDiv.innerHTML = '<div class="alert alert-danger">Error: Failed to add client. Please try again.</div>';
    errorDiv.style.display = 'block';
  })
  .finally(() => {
    // Restore button state
    submitBtn.innerHTML = originalText;
    submitBtn.disabled = false;
  });
});

document.getElementById('order-add-form').addEventListener('submit', function(e){
  const { projected } = computeProjectedTotal();
  const limit=AR.credit_limit||0, out=AR.outstanding||0;
  const exceeds = limit>0 && (out + (projected||0)) > limit;
  const override = document.getElementById('override_credit_limit')?.checked;
  if(exceeds && !override){ e.preventDefault(); refreshCreditWarning(); document.getElementById('overrideWrap').scrollIntoView({behavior:'smooth', block:'center'}); }
});
</script>
</body>
</html>
