<?php
// operation/order_edit.php

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/module_access.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/inventory/inv_request_links.php';
require_once __DIR__.'/../includes/company_helper.php';
require_once __DIR__.'/../includes/inventory/inv_material_requests_for_modules.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_once __DIR__.'/../includes/overlap.php';
require_once __DIR__.'/../includes/work_order_financial_guard.php';
require_once __DIR__.'/../includes/work_order_adjustment_service.php';
require_once __DIR__.'/../includes/cleaning_order_cancellation_helper.php';

require_role(['Owner','Admin','Operation'], $conn);

$order_id = (int)($_GET['id'] ?? 0);
if ($order_id <= 0) { header("Location: ../operation.php?tab=workorder"); exit; }

/* -----------------------------------------------------------
   Helpers
----------------------------------------------------------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function dec_to_time($d){ // 7.00 -> 07:00:00, 8.50 -> 08:30:00
  $d = (float)$d;
  $h = (int)floor($d);
  $m = (int)round(($d - $h) * 60);
  if ($m === 60) { $h++; $m = 0; }
  return sprintf('%02d:%02d:00', $h, $m);
}
function time_to_dec($t){ // '07:00:00' -> 7.00
  if ($t === null || $t === '') return 0.0;
  [$h,$m] = array_map('intval', explode(':', substr($t,0,5)));
  return $h + ($m/60);
}

/* ---------- preload lists ---------- */
$clients   = $conn->query("SELECT id, client_name, email, mobile_num, address, rate AS rate_num, terms, default_vat_rate, credit_limit FROM client ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);
$drivers   = $conn->query("SELECT * FROM driver ORDER BY nickname")->fetchAll(PDO::FETCH_ASSOC);
$workers   = $conn->query("SELECT * FROM workers ORDER BY nickname")->fetchAll(PDO::FETCH_ASSOC);
$times     = $conn->query("SELECT * FROM fromtime")->fetchAll(PDO::FETCH_ASSOC); // has from_time / to_time like '7.00'
$services  = $conn->query("SELECT id, name FROM services WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$clientById=[]; foreach($clients as $c){ $clientById[(int)$c['id']]=$c; }

/* ---------- load order ---------- */
$ord = $conn->prepare("SELECT * FROM make_order WHERE id=?");
$ord->execute([$order_id]);
$order = $ord->fetch(PDO::FETCH_ASSOC);
if (!$order){ header("Location: ../operation.php?tab=workorder"); exit; }

$opCompanyId = current_company_id($conn) ?: 0;
$matReqForOrder = $opCompanyId ? inv_material_requests_fetch_for_cleaning_order($conn, $opCompanyId, $order_id) : [];

/* current workers/services */
$curWorkerIds = $conn->prepare("SELECT worker_id FROM order_workers WHERE order_id=?");
$curWorkerIds->execute([$order_id]);
$curWorkerIds = array_map('intval', $curWorkerIds->fetchAll(PDO::FETCH_COLUMN));

$curServicesSt = $conn->prepare("SELECT * FROM order_services WHERE order_id=? ORDER BY id");
$curServicesSt->execute([$order_id]);
$curServices = $curServicesSt->fetchAll(PDO::FETCH_ASSOC);

$financialLocked = wo_financial_is_locked($conn, $order_id);
$isFinalized = wo_is_finalized($conn, $order);
$opsLocked = wo_ops_is_locked($conn, $order);
[$canDirectCancel, $directCancelBlock] = wo_ops_can_direct_cancel($conn, $order_id);
$canFinalize = sm_user_can_finalize($conn);
[$finalizeReady, $finalizeReason] = wo_can_finalize($conn, $order);
$invoiceRow = null;
$invSt = $conn->prepare("SELECT id, invoice_no, status, subtotal, vat_amount, total FROM invoices WHERE order_id = ? AND status <> 'void' ORDER BY id DESC LIMIT 1");
$invSt->execute([$order_id]);
$invoiceRow = $invSt->fetch(PDO::FETCH_ASSOC) ?: null;
$pendingAdj = sm_pending_adjustment_for_order($conn, $order_id);
$canRequestAdj = sm_user_can_request_adjustment($conn) && ($financialLocked || $isFinalized);
$canMarkComplete = sm_user_can_mark_complete($conn) && !$opsLocked
  && !in_array(strtolower((string)$order['status']), ['completed', 'invoiced', 'cancelled'], true);
$opsLockReason = $opsLocked ? wo_ops_lock_reason($conn, $order) : '';
$financialLockReason = (!$opsLocked && $financialLocked) ? wo_financial_lock_reason($conn, $order_id) : '';
$frozenGrand = round((float)($order['frozen_grand_total'] ?? $order['grand_total'] ?? 0), 2);
$invoiceGrand = $invoiceRow ? round((float)($invoiceRow['total'] ?? 0), 2) : 0.0;
$woInvoiceMismatch = $isFinalized && $invoiceRow && $invoiceGrand > 0 && abs($frozenGrand - $invoiceGrand) > 0.02;
$canSyncWoFromInvoice = $canFinalize && $woInvoiceMismatch && !$pendingAdj;

/* defaults for UI (pre-POST) */
$clientRow     = $clientById[(int)$order['client_id']] ?? null;
$clientVatRate = (float)($clientRow['default_vat_rate'] ?? 5.00);
$vat_included  = ((float)$order['vat_amount'] > 0) ? 'no' : 'yes';

$service_date  = $order['service_date'] ?: date('Y-m-d');
$start_dec     = time_to_dec($order['start_time']); // numeric, e.g. 7
$end_dec       = time_to_dec($order['end_time']);   // numeric
$hours_val     = (float)$order['hours'];

/* ---------- POST save ---------- */
$errors=[];

if ($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $client_id   = (int)($_POST['client_id'] ?? $order['client_id']);
  $client_name = trim($_POST['client_name'] ?? $order['client_name']);
  $email       = trim($_POST['email'] ?? $order['email_o']);
  $address     = trim($_POST['address'] ?? $order['address_o']);
  $phone       = trim($_POST['phone'] ?? $order['mobile_num_o']);

  $date        = $_POST['date'] ?: ($order['service_date'] ?: date('Y-m-d'));
  $time_from   = (float)($_POST['time_from'] ?? $start_dec);
  $time_to     = (float)($_POST['time_to']   ?? $end_dec);

  $fee_charge  = (float)($_POST['fee_charge'] ?? $order['fee_charged']);

  // Driver handling: select by ID + hidden name
  $driver_id   = isset($_POST['driver_id']) ? (int)$_POST['driver_id'] : (int)($order['driver_id'] ?? 0);
  $driver_name = trim($_POST['driver_name'] ?? $order['driver_name']);

  $vat_included= $_POST['vat_included'] ?? $vat_included;
  $remark      = trim($_POST['remark'] ?? '');
  $note        = trim($_POST['note'] ?? '');

  $worker_ids  = isset($_POST['worker_ids']) && is_array($_POST['worker_ids'])
                 ? array_values(array_filter(array_map('intval', $_POST['worker_ids'])))
                 : [];

  $svc_ids       = $_POST['svc_service_id'] ?? [];
  $svc_qtys      = $_POST['svc_qty'] ?? [];
  $svc_units     = $_POST['svc_unit'] ?? [];
  $svc_prices    = $_POST['svc_unit_price'] ?? [];
  $svc_vatrates  = $_POST['svc_vat_rate'] ?? [];

  // Recompute client VAT rate based on the *selected* client
  $clientVatRate = (float)($clientById[$client_id]['default_vat_rate'] ?? 5.00);

  if (!$client_id)   $errors[]="Client is required.";
  if (!$client_name) $errors[]="Client name is required.";

  // Fallback: if driver_name is empty but driver_id provided, pull nickname
  if ($driver_id > 0 && $driver_name === '') {
    $st = $conn->prepare("SELECT nickname FROM driver WHERE id=?");
    $st->execute([$driver_id]);
    $driver_name = (string)$st->fetchColumn();
  }
  if ($driver_id <= 0 || $driver_name === '') $errors[]="Driver is required.";

  if ($time_to <= $time_from) $errors[]="Time range is invalid.";
  if ($fee_charge <= 0) $errors[]="Fee per hour must be > 0.";
  if (empty($worker_ids)) $errors[]="Select at least one worker.";

  /* build services */
  $service_rows=[]; $services_sub=0; $services_vat=0; $services_tot=0;
  $addVat = ($vat_included==='no'); // "no" means VAT not included; add it
  for($i=0;$i<count($svc_ids);$i++){
    $sid=(int)($svc_ids[$i]??0); if($sid<=0) continue;
    $qty=(float)($svc_qtys[$i]??0); $unit=trim((string)($svc_units[$i]??'hour'));
    $price=(float)($svc_prices[$i]??0); $vr=(float)($svc_vatrates[$i]??$clientVatRate);
    if ($qty<=0||$price<0) continue;
    $lineSub=round($qty*$price,2);
    $lineVat=$addVat?round($lineSub*$vr/100,2):0.00;
    $lineTot=$lineSub+$lineVat;
    $services_sub+=$lineSub; $services_vat+=$lineVat; $services_tot+=$lineTot;
    $service_rows[]=['service_id'=>$sid,'qty'=>$qty,'unit'=>$unit,'unit_price'=>$price,'vat_rate'=>$vr];
  }

    if (!$errors) {
      if (wo_ops_is_locked($conn, $order)) {
        $errors[] = wo_ops_lock_reason($conn, $order);
      }
    }

    if (!$errors) {
      $financialLockedNow = wo_financial_is_locked($conn, $order_id);
      $curSvcSig = wo_services_signature(array_map(static function ($r) {
        return [
          'service_id' => (int)$r['service_id'],
          'qty' => (float)$r['qty'],
          'unit' => (string)$r['unit'],
          'unit_price' => (float)$r['unit_price'],
          'vat_rate' => (float)$r['vat_rate'],
        ];
      }, $curServices));
      $newSvcSig = wo_services_signature($service_rows);
      $moneyFieldsChanged =
        $client_id !== (int)$order['client_id']
        || abs($fee_charge - (float)$order['fee_charged']) > 0.009
        || $curSvcSig !== $newSvcSig
        || ($vat_included === 'no') !== ((float)$order['vat_amount'] > 0);

      if ($financialLockedNow && $moneyFieldsChanged) {
        $errors[] = wo_financial_lock_reason($conn, $order_id)
          ?: 'This work order is financially locked. Money fields require an Accounts adjustment.';
      }
    }

    if (!$errors) {
      $financialLockedNow = wo_financial_is_locked($conn, $order_id);
      $opsLockedNow = wo_ops_is_locked($conn, $order);
      if ($opsLockedNow) {
        $errors[] = wo_ops_lock_reason($conn, $order);
      } elseif ($financialLockedNow) {
        // Ops schedule/contact editable; money columns stay frozen to existing invoice amounts.
        $duration = max(0, $time_to - $time_from);
        $num_cleaners = max(1, count($worker_ids));
        $hours_total = round($duration * $num_cleaners, 2);
        $startHH = substr(dec_to_time($time_from), 0, 5);
        $endHH   = substr(dec_to_time($time_to),   0, 5);

        foreach ((array)$worker_ids as $wid) {
          $conflicts = findOverlap($conn, (int)$wid, $date, $startHH, $endHH, (int)$order_id);
          if ($conflicts) {
            $c = $conflicts[0];
            $wname  = $conn->prepare("SELECT nickname FROM workers WHERE id=?");
            $wname->execute([$wid]);
            $wlabel = $wname->fetchColumn() ?: ('Worker #'.$wid);
            $errors[] =
              "Overlap for {$wlabel} on {$date}: conflicts with Order #{$c['id']} ".
              "({$c['start_time']}–{$c['end_time']}" . ($c['client'] ? " • {$c['client']}" : "") . ").";
            break;
          }
        }

        if (!$errors) {
          $wstr = '';
          if ($worker_ids) {
            $ph = implode(',', array_fill(0, count($worker_ids), '?'));
            $wn = $conn->prepare("SELECT nickname FROM workers WHERE id IN ($ph)");
            $wn->execute($worker_ids);
            $wstr = implode(' , ', $wn->fetchAll(PDO::FETCH_COLUMN));
          }

          $up = $conn->prepare("
            UPDATE make_order
               SET worker_name   = :wstr,
                   email_o       = :email,
                   address_o     = :addr,
                   mobile_num_o  = :phone,
                   date          = :dte,
                   service_date  = :dte,
                   time          = :tf2,
                   start_time    = :tf,
                   end_time      = :tt,
                   hours         = :hrs,
                   remark        = :remark,
                   notes         = :notes,
                   driver_id     = :driver_id,
                   driver_name   = :driver,
                   updated_at    = NOW()
             WHERE id = :id
          ");
          $up->execute([
            ':wstr' => $wstr,
            ':email' => $email,
            ':addr' => $address,
            ':phone' => $phone,
            ':dte' => $date,
            ':tf2' => sprintf('%0.2f', $time_from) . ' To ' . sprintf('%0.2f', $time_to),
            ':tf' => dec_to_time($time_from),
            ':tt' => dec_to_time($time_to),
            ':hrs' => $hours_total,
            ':remark' => $remark,
            ':notes' => $note,
            ':driver_id' => $driver_id ?: null,
            ':driver' => $driver_name,
            ':id' => $order_id,
          ]);

          $conn->prepare("DELETE FROM order_workers WHERE order_id = ?")->execute([$order_id]);
          $insOW = $conn->prepare("INSERT INTO order_workers (order_id, worker_id) VALUES (?, ?)");
          foreach ($worker_ids as $wid) {
            $insOW->execute([$order_id, $wid]);
          }

          require_once __DIR__ . '/../includes/AuditService.php';
          $actorId = current_user_id();
          AuditService::logUpdate('make_order', $order_id, $order, [
            'service_date' => $date,
            'start_time' => dec_to_time($time_from),
            'end_time' => dec_to_time($time_to),
            'worker_name' => $wstr,
            'remark' => $remark,
            'notes' => $note,
          ], "Updated schedule/ops fields on financially locked order #{$order_id} (money unchanged)", $actorId ? (int)$actorId : null);

          header("Location: order_edit.php?id=".$order_id."&ok=1");
          exit;
        }
      }
    }

    if (!$errors && !wo_financial_is_locked($conn, $order_id)) {
      $duration = max(0, $time_to - $time_from);
    $num_cleaners = max(1, count($worker_ids));
    $hours_total = round($duration * $num_cleaners, 2);

    if (!empty($service_rows)){
      $subtotal=$services_sub; $vat_amount=$services_vat; $grand=$services_tot;
    } else {
      $subtotal = round($fee_charge * $hours_total, 2);
      $vat_amount = $addVat ? round($subtotal * ($clientVatRate/100), 2) : 0.00;
      $grand = $subtotal + $vat_amount;
    }

    $terms = $clientById[$client_id]['terms'] ?? 'cash';

    /* worker names string */
    $wstr='';
    if ($worker_ids){
      $ph = implode(',', array_fill(0,count($worker_ids),'?'));
      $wn = $conn->prepare("SELECT nickname FROM workers WHERE id IN ($ph)");
      $wn->execute($worker_ids);
      $wstr = implode(' , ', $wn->fetchAll(PDO::FETCH_COLUMN));
    }

    // ---- Overlap validation (EDIT) ----
    $startHH = substr(dec_to_time($time_from), 0, 5); // 'HH:MM'
    $endHH   = substr(dec_to_time($time_to),   0, 5);
    foreach ((array)$worker_ids as $wid) {
      $conflicts = findOverlap($conn, (int)$wid, $date, $startHH, $endHH, (int)$order_id); // exclude this order
      if ($conflicts) {
        $c = $conflicts[0];
        $wname  = $conn->prepare("SELECT nickname FROM workers WHERE id=?");
        $wname->execute([$wid]);
        $wlabel = $wname->fetchColumn() ?: ('Worker #'.$wid);
        $errors[] =
          "Overlap for {$wlabel} on {$date}: conflicts with Order #{$c['id']} ".
          "({$c['start_time']}–{$c['end_time']}" . ($c['client'] ? " • {$c['client']}" : "") . ").";
        break;
      }
    }

    if (!$errors) {
      $up = $conn->prepare("
        UPDATE make_order
           SET client_id     = :cid,
               client_name   = :cname,
               worker_name   = :wstr,
               email_o       = :email,
               address_o     = :addr,
               mobile_num_o  = :phone,
               fee_charged   = :fee,
               hourly_rate   = :fee,
               payment       = :terms,
               date          = :dte,
               service_date  = :dte,
               time          = :tf2,
               start_time    = :tf,
               end_time      = :tt,
               hours         = :hrs,
               total         = :subtotal,
               vat_rate      = :vrate,
               vat_amount    = :vamt,
               grand_total   = :gtot,
               remark        = :remark,
               notes         = :notes,
               driver_id     = :driver_id,   -- REMOVE if your table lacks this column
               driver_name   = :driver,
               updated_at    = NOW()
         WHERE id = :id
      ");
      $up->execute([
        ':cid'=>$client_id,
        ':cname'=>$client_name,
        ':wstr'=>$wstr,
        ':email'=>$email,
        ':addr'=>$address,
        ':phone'=>$phone,
        ':fee'=>$fee_charge,
        ':terms'=>$terms,
        ':dte'=>$date,
        ':tf2'=>sprintf('%0.2f',$time_from).' To '.sprintf('%0.2f',$time_to),
        ':tf'=>dec_to_time($time_from),
        ':tt'=>dec_to_time($time_to),
        ':hrs'=>$hours_total,
        ':subtotal'=>$subtotal,
        ':vrate'=>$clientVatRate,
        ':vamt'=>$vat_amount,
        ':gtot'=>$grand,
        ':remark'=>$remark,
        ':notes'=>$note,
        ':driver_id'=>$driver_id, // REMOVE if your table lacks this column
        ':driver'=>$driver_name,
        ':id'=>$order_id
      ]);

      /* replace workers */
      $conn->prepare("DELETE FROM order_workers WHERE order_id=?")->execute([$order_id]);
      $insOW=$conn->prepare("INSERT INTO order_workers (order_id, worker_id) VALUES (?,?)");
      foreach($worker_ids as $wid){ $insOW->execute([$order_id,$wid]); }

      /* replace services */
      $conn->prepare("DELETE FROM order_services WHERE order_id=?")->execute([$order_id]);
      if ($service_rows){
        $svcName=[]; foreach($services as $s){ $svcName[(int)$s['id']]=$s['name']; }
        $insSvc=$conn->prepare("
          INSERT INTO order_services
            (order_id, service_id, service_name, description, qty, unit, unit_price, vat_rate)
          VALUES (:oid,:sid,:sname,NULL,:qty,:unit,:price,:vr)
        ");
        foreach($service_rows as $r){
          $insSvc->execute([
            ':oid'=>$order_id, ':sid'=>$r['service_id'],
            ':sname'=>$svcName[$r['service_id']] ?? null,
            ':qty'=>$r['qty'], ':unit'=>$r['unit'],
            ':price'=>$r['unit_price'], ':vr'=>$r['vat_rate']
          ]);
        }
      }

      /* legacy auto-invoice only when defer flag is off and order is not locked */
      $invoiceId = wo_maybe_sync_invoice_after_order_change($conn, $order_id, current_user_id());
      if ($invoiceId) {
        $conn->prepare('UPDATE make_order SET invoice_id = ? WHERE id = ?')->execute([$invoiceId, $order_id]);
      }
      wo_sync_ops_status_column($conn, $order_id, (string)($order['status'] ?? 'confirmed'));

      // Audit Log: Track order update
      require_once __DIR__ . '/../includes/AuditService.php';
      $actorId = current_user_id();
      AuditService::logUpdate('make_order', $order_id, $order, [
        'client_name' => $client_name,
        'service_date' => $date,
        'worker_count' => count($worker_ids),
        'total' => $grand,
        'status' => $order['status']
      ], "Updated order #{$order_id} for {$client_name}", $actorId ? (int)$actorId : null);

      header("Location: order_edit.php?id=".$order_id."&ok=1");
      exit;
    }
  }
}

/* refresh values for initial load / after ok */
$prefClient  = $clientById[(int)$order['client_id']] ?? null;
$prefEmail   = $order['email_o'] ?: ($prefClient['email'] ?? '');
$prefPhone   = $order['mobile_num_o'] ?: ($prefClient['mobile_num'] ?? '');
$prefAddr    = $order['address_o'] ?: ($prefClient['address'] ?? '');
$fee_val     = (float)$order['fee_charged'];
$driver_id_val = (int)($order['driver_id'] ?? 0);
$driver_val  = $order['driver_name'];
$grand_val   = (float)($order['grand_total'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Work Order #<?= h($order_id) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f6f8fb}
  .card{margin-top:24px}
  .table tfoot td{font-weight:600}
</style>
</head>
<body>
<div class="container py-4">
  <div class="col-xl-10 mx-auto">
    <?php if ($errors): ?>
      <div class="alert alert-danger"><b>Fix these:</b><br><?= implode("<br>", array_map('h',$errors)) ?></div>
    <?php elseif(isset($_GET['ok'])): ?>
      <div class="alert alert-success">Order saved successfully.<?= (sm_defer_auto_invoice($conn) && !$financialLocked) ? ' Invoice will be generated when an Admin or Accountant finalizes this job.' : '' ?></div>
    <?php elseif(isset($_GET['finalized'])): ?>
      <div class="alert alert-success">Work order finalized — invoice created and posted to GL.</div>
    <?php endif; ?>

    <?php
    $opsBadge = wo_column_exists($conn, 'ops_status')
      ? strtoupper((string)($order['ops_status'] ?? wo_map_status_to_ops((string)$order['status'])))
      : strtoupper((string)$order['status']);
    ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
      <span class="badge bg-secondary">Status: <?= h($opsBadge) ?></span>
      <?php if ($isFinalized): ?>
        <span class="badge bg-success">Finalized</span>
      <?php elseif ($financialLocked): ?>
        <span class="badge bg-warning text-dark">Financially locked</span>
      <?php else: ?>
        <span class="badge bg-info text-dark">Awaiting finalize</span>
      <?php endif; ?>
      <?php if ($invoiceRow): ?>
        <span class="badge bg-light text-dark border">Invoice <?= h($invoiceRow['invoice_no'] ?? ('#'.$invoiceRow['id'])) ?> (<?= h($invoiceRow['status']) ?>)</span>
      <?php endif; ?>
      <?php if ($pendingAdj): ?>
        <span class="badge bg-primary">Adjustment pending #<?= (int)$pendingAdj['id'] ?></span>
      <?php endif; ?>
      <?php if ($canMarkComplete): ?>
        <button type="button" class="btn btn-sm btn-success" id="btn-mark-complete">Mark Complete</button>
      <?php endif; ?>
      <?php if ($canFinalize && !$isFinalized): ?>
        <button type="button" class="btn btn-sm btn-warning" id="btn-finalize"
          <?= $finalizeReady ? '' : 'disabled title="'.h($finalizeReason).'"' ?>>
          Finalize &amp; Generate Invoice
        </button>
        <?php if (!$finalizeReady): ?>
          <small class="text-muted"><?= h($finalizeReason) ?></small>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($canRequestAdj && !$pendingAdj): ?>

        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#adjRequestModal">
          Request Adjustment
        </button>
      <?php endif; ?>
      <?php if (sm_user_can_approve_adjustment($conn) && $pendingAdj): ?>
        <a class="btn btn-sm btn-outline-primary" href="../accounts/adjustment_requests.php">Review in Accounts</a>
      <?php endif; ?>
      <?php if ($canSyncWoFromInvoice): ?>
        <button type="button" class="btn btn-sm btn-outline-warning" id="btn-sync-wo-invoice"
          title="Align work order totals with the invoice — no new AR document">
          Sync WO from invoice
        </button>
      <?php endif; ?>
    </div>

    <?php if ($opsLockReason !== ''): ?>
      <div class="alert alert-secondary py-2 mb-3"><?= h($opsLockReason) ?></div>
    <?php elseif ($financialLockReason !== ''): ?>
      <div class="alert alert-warning py-2 mb-3"><?= h($financialLockReason) ?></div>
    <?php endif; ?>
    <?php if (!$canDirectCancel && $directCancelBlock !== '' && !$isFinalized): ?>
      <div class="alert alert-info py-2 mb-3"><?= h($directCancelBlock) ?></div>
    <?php endif; ?>

    <?php if ($woInvoiceMismatch): ?>
    <div class="alert alert-warning py-2 mb-3" id="wo-invoice-mismatch-alert">
      <strong>Total mismatch:</strong> Work order shows <strong>AED <?= number_format($frozenGrand, 2) ?></strong>
      but invoice <a href="../accounts/invoice_view.php?id=<?= (int)$invoiceRow['id'] ?>"><?= h($invoiceRow['invoice_no'] ?? '') ?></a>
      shows <strong>AED <?= number_format($invoiceGrand, 2) ?></strong>.
      <?php if ($canSyncWoFromInvoice): ?>
        Use <em>Sync WO from invoice</em> for a one-time fix (does not change the invoice or post extra GL).
        Do <strong>not</strong> use Request Adjustment if the invoice is already correct — that would duplicate billing.
      <?php else: ?>
        Ask Admin/Accountant to sync the work order from the invoice.
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (has_permission('inventory_requests.create', MODULE_INVENTORY, $conn) && inv_user_can_access_material_request_create($conn, 'cleaning')): ?>
      <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(inv_request_material_create_url($conn, [
            'source_module' => 'cleaning',
            'source_table' => 'make_order',
            'source_id' => $order_id,
            'context_cleaning_job_id' => $order_id,
            'notes_hint' => 'Cleaning work order #' . $order_id,
        ])) ?>">Request inventory materials</a>
        <a class="btn btn-outline-primary btn-sm" href="my_material_requests.php">My material requests</a>
      </div>
    <?php endif; ?>

    <div class="card border mb-3">
      <div class="card-header py-2"><strong>Material requests for this work order</strong></div>
      <div class="card-body py-2">
        <p class="small text-muted mb-2">Linked stock requests (any user). Avoid duplicate submissions.</p>
        <?php
        $companyId = $opCompanyId;
        $rows = $matReqForOrder;
        $detailPage = 'material_request_view.php';
        $showRequestedBy = true;
        require __DIR__ . '/../includes/inventory/partials/material_requests_list_table.php';
        ?>
      </div>
    </div>

    <form method="post" id="edit-form"<?= $financialLocked ? ' data-financial-locked="1"' : '' ?>>
      <?php csrf_field(); ?>
      <div class="card shadow rounded-4 border-0">
        <div class="card-header bg-primary text-white rounded-top-4"><b>Edit Work Order #<?= h($order_id) ?></b></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Client Name *</label>
              <div class="input-group">
                <select class="form-select" name="client_id" id="client_select" required>
                  <?php foreach($clients as $c): ?>
                    <option
                      value="<?= (int)$c['id'] ?>"
                      data-name="<?= h($c['client_name']) ?>"
                      data-email="<?= h($c['email']) ?>"
                      data-address="<?= h($c['address']) ?>"
                      data-phone="<?= h($c['mobile_num']) ?>"
                      data-paymentt="<?= h($c['terms']) ?>"
                      data-feecharge="<?= h((string)$c['rate_num']) ?>"
                      data-credit-limit="<?= h((string)($c['credit_limit'] ?? 0)) ?>"
                      data-vat-rate="<?= h((string)($c['default_vat_rate'] ?? '5.00')) ?>"
                      <?= (int)$order['client_id']===(int)$c['id']?'selected':'' ?>
                    ><?= h($c['client_name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="client_name" id="client_name_hidden" value="<?= h($order['client_name']) ?>">
                <!-- VAT for JS totals -->
                <input type="hidden" id="client_vat" value="<?= number_format($clientVatRate, 2, '.', '') ?>">
              </div>
            </div>

            <div class="col-md-3">
              <label class="form-label">Email</label>
              <input class="form-control" name="email" id="email" value="<?= h($prefEmail) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Phone Number</label>
              <input class="form-control" name="phone" id="phone" value="<?= h($prefPhone) ?>">
            </div>
            <div class="col-md-12">
              <label class="form-label">Address</label>
              <input class="form-control" name="address" id="address" value="<?= h($prefAddr) ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label">Date *</label>
              <input type="date" class="form-control" name="date" id="date" value="<?= h($service_date) ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">Time From *</label>
              <select class="form-select" name="time_from" id="time_from" required>
                <?php foreach($times as $t): $fv=(float)$t['from_time']; ?>
                  <option value="<?= $fv ?>" <?= (abs($start_dec - $fv) < 0.001)?'selected':'' ?>><?= h($t['from_time']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Time To *</label>
              <select class="form-select" name="time_to" id="time_to" required>
                <?php foreach($times as $t): $tv=(float)$t['to_time']; ?>
                  <option value="<?= $tv ?>" <?= (abs($end_dec - $tv) < 0.001)?'selected':'' ?>><?= h($t['to_time']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Hours</label>
              <input class="form-control" id="hours" value="<?= number_format($hours_val,2) ?>" readonly>
            </div>

            <div class="col-md-5">
              <label class="form-label">Worker Name(s) *</label>
              <select name="worker_ids[]" id="worker_names" class="form-select" multiple size="6" required>
                <?php foreach($workers as $w): $wid=(int)$w['id']; ?>
                  <option value="<?= $wid ?>" <?= in_array($wid,$curWorkerIds,true)?'selected':'' ?>><?= h($w['nickname']) ?></option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">Hold Ctrl/Cmd to select multiple.</small>
            </div>

            <div class="col-md-3">
              <label class="form-label">Driver *</label>
              <select class="form-select" name="driver_id" id="driver_id" required>
                <option value="">-- Select Driver --</option>
                <?php foreach($drivers as $d): ?>
                  <option value="<?= (int)$d['id'] ?>" data-name="<?= h($d['nickname']) ?>" <?= ((int)$driver_id_val === (int)$d['id'])?'selected':'' ?>>
                    <?= h($d['nickname']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="driver_name" id="driver_name" value="<?= h($driver_val) ?>">
            </div>

            <div class="col-md-2">
              <label class="form-label">Fee Charge (per hour) *</label>
              <input type="number" step="0.01" class="form-control" name="fee_charge" id="fee_charge" value="<?= number_format($fee_val,2,'.','') ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">Include VAT?</label>
              <div class="form-check"><input class="form-check-input" type="radio" name="vat_included" id="vat_yes" value="yes" <?= $vat_included==='yes'?'checked':'' ?>><label class="form-check-label" for="vat_yes">Yes</label></div>
              <div class="form-check"><input class="form-check-input" type="radio" name="vat_included" id="vat_no" value="no" <?= $vat_included==='no'?'checked':'' ?>><label class="form-check-label" for="vat_no">No (add VAT)</label></div>
            </div>

            <div class="col-md-3">
              <label class="form-label">Final Total (calculated)</label>
              <input class="form-control" id="final_total" value="<?= number_format($grand_val,2) ?>" readonly>
            </div>

            <div class="col-md-12">
              <label class="form-label">Remarks</label>
              <textarea class="form-control" name="remark"><?= h($order['remark']) ?></textarea>
            </div>
            <div class="col-md-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="note"><?= h($order['notes']) ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Services (optional) -->
      <div class="card shadow rounded-4 border-0">
        <div class="card-header bg-dark text-white rounded-top-4"><b>Services (optional)</b></div>
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
              <tbody>
                <?php foreach($curServices as $idx=>$r): ?>
                  <tr>
                    <td>
                      <select class="form-select" name="svc_service_id[<?= $idx ?>]">
                        <option value="">-- Select --</option>
                        <?php foreach($services as $s): ?>
                          <option value="<?= (int)$s['id'] ?>" <?= (int)$r['service_id']===(int)$s['id']?'selected':'' ?>><?= h($s['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input type="number" class="form-control svc-qty"   name="svc_qty[<?= $idx ?>]" step="0.01" min="0" value="<?= h($r['qty']) ?>"></td>
                    <td>
                      <select class="form-select svc-unit" name="svc_unit[<?= $idx ?>]">
                        <?php foreach(['hour','job','pcs'] as $u): ?>
                          <option value="<?= $u ?>" <?= $r['unit']===$u?'selected':'' ?>><?= $u ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input type="number" class="form-control svc-price" name="svc_unit_price[<?= $idx ?>]" step="0.01" min="0" value="<?= h($r['unit_price']) ?>"></td>
                    <td><input type="number" class="form-control svc-vat"   name="svc_vat_rate[<?= $idx ?>]" step="0.01" min="0" value="<?= h($r['vat_rate']) ?>"></td>
                    <td class="text-end"><span class="svc-line-total">0.00</span></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger svc-del">&times;</button></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr><td colspan="5" class="text-end">Services Subtotal:</td><td class="text-end"><span id="svcSubtotal">0.00</span></td><td></td></tr>
                <tr><td colspan="5" class="text-end">Services VAT:</td><td class="text-end"><span id="svcVat">0.00</span></td><td></td></tr>
                <tr><td colspan="5" class="text-end fw-bold">Services Total:</td><td class="text-end fw-bold"><span id="svcGrand">0.00</span></td><td></td></tr>
              </tfoot>
            </table>
          </div>
          <button type="button" class="btn btn-outline-primary" id="svcAdd">+ Add service</button>
          <div class="form-text mt-2">If you add any service lines, those amounts are used for totals; otherwise system uses Hours × Fee × Cleaners. <strong>Tip:</strong> When using hours as the unit, enter total hours (e.g., for 2 workers × 3 hours = 6 hours).</div>
        </div>
      </div>

      <div class="text-end">
        <button class="btn btn-success px-4">Save Changes</button>
        <a href="../operation.php?tab=workorder" class="btn btn-secondary px-4">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php if ($canRequestAdj && !$pendingAdj): ?>
<div class="modal fade" id="adjRequestModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Request Adjustment — WO #<?= (int)$order_id ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">Current frozen total: <strong>AED <?= number_format($frozenGrand, 2) ?></strong>. Accounts will post a credit note or supplementary invoice — the work order will not be edited directly.</p>
        <div class="mb-3">
          <label class="form-label">Type *</label>
          <select class="form-select" id="adj-type">
            <option value="amount_decrease">Amount decrease (credit note)</option>
            <option value="amount_increase">Amount increase (extra invoice)</option>
            <option value="cancellation">Cancel finalized job</option>
            <option value="other">Other (manual review)</option>
          </select>
        </div>
        <div class="mb-3" id="adj-grand-wrap">
          <label class="form-label">Proposed new total (AED)</label>
          <input type="number" step="0.01" min="0" class="form-control" id="adj-requested-grand" placeholder="<?= number_format($frozenGrand, 2, '.', '') ?>">
        </div>
        <div class="mb-3" id="adj-category-wrap" style="display:none">
          <label class="form-label">Category *</label>
          <select class="form-select" id="adj-category">
            <option value="">— Select —</option>
            <?php foreach (cleaning_order_cancel_categories() as $cancelCat): ?>
              <option value="<?= h($cancelCat) ?>"><?= h($cancelCat) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Who/what caused the cancellation — shown to Accounts.</div>
        </div>
        <div class="mb-3" id="adj-reason-wrap">
          <label class="form-label" id="adj-reason-label">Reason *</label>
          <input type="text" class="form-control" id="adj-reason" maxlength="500">
        </div>
        <div class="mb-3">
          <label class="form-label" id="adj-notes-label">Details</label>
          <textarea class="form-control" id="adj-notes" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="adj-submit">Submit Request</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const SERVICES = [
  <?php foreach($services as $s): ?>{ id: <?= (int)$s['id'] ?>, name: <?= json_encode($s['name']) ?> },<?php endforeach; ?>
];

const svcBody = document.querySelector('#svcTable tbody');
const svcAdd  = document.getElementById('svcAdd');

function addSvcRow(){
  const idx = svcBody.children.length;
  const opts = SERVICES.map(s=>`<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('');
  const row = document.createElement('tr');
  row.innerHTML = `
    <td><select class="form-select" name="svc_service_id[${idx}]">
          <option value="">-- Select --</option>${opts}</select></td>
    <td><input type="number" class="form-control svc-qty" name="svc_qty[${idx}]" step="0.01" min="0" value="1"></td>
    <td><select class="form-select svc-unit" name="svc_unit[${idx}]">
          <option value="hour">hour</option><option value="job">job</option><option value="pcs">pcs</option></select></td>
    <td><input type="number" class="form-control svc-price" name="svc_unit_price[${idx}]" step="0.01" min="0" value=""></td>
    <td><input type="number" class="form-control svc-vat" name="svc_vat_rate[${idx}]" step="0.01" min="0" value="<?= number_format($clientVatRate,2) ?>"></td>
    <td class="text-end"><span class="svc-line-total">0.00</span></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger svc-del">&times;</button></td>`;
  svcBody.appendChild(row);
  hookSvcRow(row);
  recalcServices(); recalcTotals();
}
function hookSvcRow(tr){
  tr.querySelectorAll('input,select').forEach(el=>{
    el.addEventListener('input', ()=>{ recalcServices(); recalcTotals(); });
    el.addEventListener('change', ()=>{ recalcServices(); recalcTotals(); });
  });
  tr.querySelector('.svc-del').addEventListener('click', ()=>{ tr.remove(); recalcServices(); recalcTotals(); });
}
Array.from(svcBody.children).forEach(hookSvcRow);
svcAdd.addEventListener('click', addSvcRow);

function getVatIncluded(){ return document.querySelector('input[name="vat_included"]:checked')?.value !== 'no'; }
function parseNum(v){ return parseFloat(v)||0; }
function escapeHtml(s){ return String(s).replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m])); }

function recalcServices(){
  let sub=0, vat=0, tot=0;
  const addVat = !getVatIncluded();
  document.querySelectorAll('#svcTable tbody tr').forEach(tr=>{
    const qty=parseNum(tr.querySelector('.svc-qty').value);
    const price=parseNum(tr.querySelector('.svc-price').value);
    const vr=parseNum(tr.querySelector('.svc-vat').value);
    const s=qty*price;
    const v=addVat ? s*(vr/100) : 0;
    const t=s+v;
    sub+=s; vat+=v; tot+=t;
    tr.querySelector('.svc-line-total').textContent = t.toFixed(2);
  });
  document.getElementById('svcSubtotal').textContent=sub.toFixed(2);
  document.getElementById('svcVat').textContent=vat.toFixed(2);
  document.getElementById('svcGrand').textContent=tot.toFixed(2);
}

function recalcHours(){
  const from=parseNum(document.getElementById('time_from').value);
  const to  =parseNum(document.getElementById('time_to').value);
  const cleaners = Array.from(document.getElementById('worker_names').selectedOptions).length || 1;
  const h = Math.max(0,to-from)*cleaners;
  document.getElementById('hours').value = h.toFixed(2);
  return h;
}

function recalcTotals(){
  recalcHours();
  const svcRows = document.querySelectorAll('#svcTable tbody tr').length;
  let grand=0;
  if (svcRows>0){
    grand = parseNum(document.getElementById('svcGrand').textContent);
  } else {
    const fee = parseNum(document.getElementById('fee_charge').value);
    const hours = parseNum(document.getElementById('hours').value);
    const addVat = !getVatIncluded();
    const sub = fee*hours;
    const vatRate = parseFloat(document.getElementById('client_vat').value)||5;
    const vat = addVat ? sub*(vatRate/100) : 0;
    grand = sub + vat;
  }
  document.getElementById('final_total').value = grand.toFixed(2);
}

// change listeners
['time_from','time_to','fee_charge'].forEach(id=>{
  const el=document.getElementById(id); if (el) el.addEventListener('change', recalcTotals);
});
document.getElementById('worker_names').addEventListener('change', recalcTotals);
document.querySelectorAll('input[name="vat_included"]').forEach(r=>r.addEventListener('change', ()=>{ recalcServices(); recalcTotals(); }));

// client autofill on change
function fillClientFieldsFromOption(opt){
  if (!opt) return;
  document.getElementById('email').value   = opt.getAttribute('data-email') || '';
  document.getElementById('address').value = opt.getAttribute('data-address') || '';
  document.getElementById('phone').value   = opt.getAttribute('data-phone') || '';
  document.getElementById('client_name_hidden').value = opt.getAttribute('data-name') || '';

  const rate = opt.getAttribute('data-feecharge');
  if (rate && !document.getElementById('fee_charge').value) {
    document.getElementById('fee_charge').value = rate;
  }
  const vat = parseFloat(opt.getAttribute('data-vat-rate'));
  if (!isNaN(vat)) {
    document.getElementById('client_vat').value = vat.toFixed(2);
  }
}
document.getElementById('client_select').addEventListener('change', function(){
  fillClientFieldsFromOption(this.options[this.selectedIndex]);
  recalcServices(); recalcTotals();
});

// driver name sync
document.getElementById('driver_id').addEventListener('change', function(){
  const name = this.options[this.selectedIndex]?.getAttribute('data-name') || '';
  document.getElementById('driver_name').value = name;
});

// initial calc for already-loaded data
recalcServices(); recalcTotals();

<?php if ($isFinalized): ?>
(function lockFinalizedOrderFields(){
  const sel = '#client_select,#date,#time_from,#time_to,#fee_charge,#worker_names,#svcAdd,input[name="vat_included"],#svcTable input,#svcTable select,.svc-del,#edit-form button[type="submit"]';
  document.querySelectorAll(sel).forEach(el=>{
    if (el.type === 'radio') { el.disabled = true; return; }
    el.disabled = true;
    if (el.tagName === 'BUTTON') el.style.display = 'none';
  });
  document.querySelectorAll('#svcTable .svc-del').forEach(b=>b.style.display='none');
})();
<?php elseif ($financialLocked): ?>
(function lockMoneyFieldsOnly(){
  const sel = '#client_select,#fee_charge,#svcAdd,input[name="vat_included"],#svcTable input,#svcTable select,.svc-del';
  document.querySelectorAll(sel).forEach(el=>{
    if (el.type === 'radio') { el.disabled = true; return; }
    el.disabled = true;
    if (el.tagName === 'BUTTON') el.style.display = 'none';
  });
  document.querySelectorAll('#svcTable .svc-del').forEach(b=>b.style.display='none');
})();
<?php endif; ?>

<?php if ($canFinalize && !$isFinalized): ?>
document.getElementById('btn-finalize')?.addEventListener('click', async function(){
  if (!confirm('Finalize this work order? This will lock financial fields and create/post the invoice.')) return;
  const btn = this;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('order_id', '<?= (int)$order_id ?>');
  fd.append('_csrf', document.querySelector('input[name="_csrf"]')?.value || '');
  try {
    const res = await fetch('ajax_finalize_order.php', { method:'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      window.location = 'order_edit.php?id=<?= (int)$order_id ?>&finalized=1';
    } else {
      alert(data.message || 'Finalize failed');
      btn.disabled = false;
    }
  } catch (e) {
    alert('Finalize request failed');
    btn.disabled = false;
  }
});
<?php endif; ?>

<?php if ($canSyncWoFromInvoice): ?>
document.getElementById('btn-sync-wo-invoice')?.addEventListener('click', async function(){
  if (!confirm('Sync work order totals from the invoice?\n\nWork order: AED <?= number_format($frozenGrand, 2) ?>\nInvoice: AED <?= number_format($invoiceGrand, 2) ?>\n\nThis updates the WO display only. It does NOT change the invoice or create credit notes / supplementary invoices.')) return;
  const btn = this;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('order_id', '<?= (int)$order_id ?>');
  fd.append('_csrf', document.querySelector('input[name="_csrf"]')?.value || '');
  try {
    const res = await fetch('ajax_sync_wo_invoice_totals.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      alert(data.message || 'Synced');
      location.reload();
    } else {
      alert(data.message || 'Sync failed');
      btn.disabled = false;
    }
  } catch (e) {
    alert('Sync request failed');
    btn.disabled = false;
  }
});
<?php endif; ?>

<?php if ($canMarkComplete): ?>
document.getElementById('btn-mark-complete')?.addEventListener('click', async function(){
  if (!confirm('Mark this job as completed?')) return;
  const fd = new FormData();
  fd.append('order_id', '<?= (int)$order_id ?>');
  fd.append('_csrf', document.querySelector('input[name="_csrf"]')?.value || '');
  const res = await fetch('ajax_mark_complete.php', { method:'POST', body: fd });
  const data = await res.json();
  if (data.success) location.reload();
  else alert(data.message || 'Failed');
});
<?php endif; ?>

<?php if ($canRequestAdj && !$pendingAdj): ?>
document.getElementById('adj-type')?.addEventListener('change', function(){
  const isCancel = this.value === 'cancellation';
  const hideGrand = isCancel || this.value === 'other';
  document.getElementById('adj-grand-wrap').style.display = hideGrand ? 'none' : 'block';
  const catWrap = document.getElementById('adj-category-wrap');
  const reasonWrap = document.getElementById('adj-reason-wrap');
  if (catWrap) catWrap.style.display = isCancel ? 'block' : 'none';
  if (reasonWrap) reasonWrap.style.display = isCancel ? 'none' : 'block';
  const notesLabel = document.getElementById('adj-notes-label');
  if (notesLabel) notesLabel.textContent = isCancel ? 'Details *' : 'Details';
  const notes = document.getElementById('adj-notes');
  if (notes) notes.placeholder = isCancel ? 'Why is this job being cancelled?' : '';
});
document.getElementById('adj-submit')?.addEventListener('click', async function(){
  const type = document.getElementById('adj-type').value;
  let reason = (document.getElementById('adj-reason').value || '').trim();
  let notes = (document.getElementById('adj-notes').value || '').trim();
  if (type === 'cancellation') {
    const category = (document.getElementById('adj-category')?.value || '').trim();
    if (!category) { alert('Please select a category (Cleaner, Driver, Management, or Client).'); return; }
    if (!notes) { alert('Details are required for cancellation.'); return; }
    reason = (category + ': ' + notes).substring(0, 500);
    notes = 'Cancellation category: ' + category + '\nDetails: ' + notes;
  } else if (!reason) {
    alert('Reason is required.');
    return;
  }
  const fd = new FormData();
  fd.append('order_id', '<?= (int)$order_id ?>');
  fd.append('_csrf', document.querySelector('input[name="_csrf"]')?.value || '');
  fd.append('request_type', type);
  fd.append('reason', reason);
  fd.append('notes', notes);
  const g = document.getElementById('adj-requested-grand').value;
  if (g && type !== 'cancellation') fd.append('requested_grand', g);
  const res = await fetch('ajax_request_adjustment.php', { method:'POST', body: fd });
  const data = await res.json();
  if (data.success) {
    alert(data.message);
    location.reload();
  } else {
    alert(data.message || 'Request failed');
  }
});
<?php endif; ?>
</script>
</body>
</html>
