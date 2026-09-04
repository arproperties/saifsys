<?php
// includes/notify.php
declare(strict_types=1);

function nt_render(string $text, array $vars): string {
  return preg_replace_callback('/\{\{(\w+)\}\}/', function($m) use($vars){
    $k = $m[1];
    return array_key_exists($k,$vars) ? (string)$vars[$k] : $m[0];
  }, $text);
}

function nt_enqueue(PDO $conn, string $code, string $channel, string $recipient, array $vars=[], ?int $orderId=null, ?string $scheduleAt=null): int {
  $st = $conn->prepare("
    INSERT INTO outbox_messages (channel, recipient, template_code, variables_json, related_order_id, status, scheduled_at)
    VALUES (:ch, :rcp, :code, :vars, :oid, 'queued', COALESCE(:sch, NOW()))
  ");
  $st->execute([
    ':ch'=>$channel,
    ':rcp'=>$recipient,
    ':code'=>$code,
    ':vars'=>json_encode($vars, JSON_UNESCAPED_UNICODE),
    ':oid'=>$orderId,
    ':sch'=>$scheduleAt
  ]);
  return (int)$conn->lastInsertId();
}

function audit_order(PDO $conn, int $orderId, ?int $actorId, string $action, $old=null, $new=null): void {
  $st = $conn->prepare("INSERT INTO order_audit (order_id, actor_id, action, old_json, new_json) VALUES (?,?,?,?,?)");
  $st->execute([$orderId, $actorId, $action, json_encode($old), json_encode($new)]);
}

/* ---- DRIVER helpers ---- */

function nt_get_driver_phone(PDO $conn, ?int $driverId, ?string $fallbackName, ?string &$outDriverName): ?string {
  $outDriverName = $fallbackName ?: null;
  if (!$driverId) return null;

  // Only the columns you actually have
  $st = $conn->prepare("SELECT nickname, mobile_num FROM driver WHERE id=?");
  $st->execute([$driverId]);
  $d = $st->fetch(PDO::FETCH_ASSOC);
  if (!$d) return null;

  // prefer DB nickname if available
  if (!empty($d['nickname'])) {
    $outDriverName = $d['nickname'];
  }

  // Normalize the stored phone (strip spaces/dashes etc.)
  $raw = (string)($d['mobile_num'] ?? '');
  $digits = preg_replace('/\D+/', '', $raw); // keep only numbers

  // If empty after normalization, we can’t send
  if ($digits === '') return null;

  // OPTIONAL: If your WhatsApp provider requires a country code, you can add it here.
  // Example for UAE (971). Comment these lines out if your DB already stores full international format.
  // if (strpos($digits, '971') !== 0) {
  //   $digits = '971' . ltrim($digits, '0');
  // }

  // If your provider needs a leading "+", you can return "+$digits" instead.
  // return '+' . $digits;

  return $digits;
}

/** Build driver message variables for an order and enqueue one WhatsApp message to the driver. */
function nt_notify_driver_for_order(PDO $conn, int $orderId, string $templateCode): int {
  // load order
  $st = $conn->prepare("
    SELECT
      mo.id, mo.client_id, mo.client_name,
      mo.address_o AS client_address,
      COALESCE(mo.service_date, mo.`date`) AS svc_date,
      DATE_FORMAT(mo.start_time,'%H:%i') AS s,
      DATE_FORMAT(mo.end_time  ,'%H:%i') AS e,
      mo.status, mo.grand_total, mo.payment,
      mo.driver_id, COALESCE(mo.driver_name,'') AS driver_name,
      COALESCE(mo.need_materials,0) AS need_materials,
      COALESCE(mo.materials_note,'') AS materials_note
    FROM make_order mo
    WHERE mo.id=?
  ");
  $st->execute([$orderId]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  if (!$o) return 0;

  // workers list
  $ws = $conn->prepare("SELECT w.nickname FROM order_workers ow JOIN workers w ON w.id=ow.worker_id WHERE ow.order_id=? ORDER BY w.nickname");
  $ws->execute([$orderId]);
  $workers = $ws->fetchAll(PDO::FETCH_COLUMN);
  $workersStr = $workers ? implode(', ', array_map(fn($x)=>$x?:'Worker', $workers)) : '—';

  // driver phone
  $driverName = $o['driver_name'] ?: null;
  $phone = nt_get_driver_phone($conn, (int)$o['driver_id'], $driverName, $driverName);
  if (!$phone) return 0; // no phone, skip

  // materials line
  $materials = ((int)$o['need_materials'] === 1)
    ? ('Yes'.($o['materials_note'] ? ' — '.$o['materials_note'] : ''))
    : 'No';

  // payment line
  $terms = strtolower((string)($o['payment'] ?? ''));
  $paymentLine = ($terms === 'cash')
    ? ('Cash: AED '.number_format((float)($o['grand_total'] ?? 0), 2))
    : 'Online';

  $vars = [
    'order_id'       => (string)$orderId,
    'client_name'    => (string)($o['client_name'] ?? ''),
    'client_address' => (string)($o['client_address'] ?? ''),
    'date'           => (string)($o['svc_date'] ?? ''),
    'start'          => (string)($o['s'] ?? ''),
    'end'            => (string)($o['e'] ?? ''),
    'workers'        => $workersStr,
    'materials'      => $materials,
    'payment_line'   => $paymentLine,
    'status'         => (string)($o['status'] ?? ''),
    'driver_name'    => (string)($driverName ?? ''),
  ];

  nt_enqueue($conn, $templateCode, 'whatsapp', $phone, $vars, $orderId, null);
  return 1;
}
