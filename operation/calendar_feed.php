<?php
// operation/calendar_feed.php
declare(strict_types=1);

header('Content-Type: text/calendar; charset=utf-8');

require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/calendar_config.php';
require_once __DIR__.'/../includes/calendar_tokens.php';
require_once __DIR__.'/../includes/calendar_ics.php';

/*
  URL forms:
    /operation/calendar_feed.php?token=...           (auto-detect entity by token)
    /operation/calendar_feed.php?driver=ID           (requires logged-in session; creates token & redirects 302)
    /operation/calendar_feed.php?worker=ID           (same as above)
*/

try {
  // 1) If driver= / worker= is provided (and user is logged in), mint token and redirect to token URL
    // 1) If driver= / worker= is provided (and user is logged in), mint token
    if (isset($_GET['driver']) || isset($_GET['worker'])) {
      require_once __DIR__.'/../includes/auth.php'; // must be signed-in for minting
      $type = isset($_GET['driver']) ? 'driver' : 'worker';
      $id   = (int)($_GET['driver'] ?? $_GET['worker']);
      if ($id <= 0) throw new Exception('Bad id');

      $token = cal_ensure_token($conn, $type, $id);
      $url   = calendar_base_url()."/operation/calendar_feed.php?token=".urlencode($token);

      // NEW: when mode=link, return JSON instead of redirect
      if (isset($_GET['mode']) && $_GET['mode'] === 'link') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['url' => $url]);
        exit;
      }

      header('Location: '.$url, true, 302);
      echo "Redirecting to your ICS URL...";
      exit;
    }
    
  // 2) Token mode (public, read-only)
  $token = $_GET['token'] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Invalid//EN\r\nEND:VCALENDAR";
    exit;
  }

  $st = $conn->prepare("SELECT * FROM calendar_tokens WHERE token=? AND revoked_at IS NULL");
  $st->execute([$token]);
  $tk = $st->fetch(PDO::FETCH_ASSOC);
  if (!$tk) {
    http_response_code(404);
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NotFound//EN\r\nEND:VCALENDAR";
    exit;
  }

  $type = $tk['entity_type'];   // 'driver' | 'worker'
  $id   = (int)$tk['entity_id'];

  // 3) Find date window
  $from = (new DateTimeImmutable('-'.ICS_PAST_DAYS.' days'))->format('Y-m-d');
  $to   = (new DateTimeImmutable('+'.ICS_FUTURE_DAYS.' days'))->format('Y-m-d');

  // 4) Pull events for this entity
  if ($type === 'driver') {
    $q = $conn->prepare("
      SELECT
        mo.id,
        COALESCE(mo.service_date, mo.`date`) AS svc_date,
        DATE_FORMAT(mo.start_time, '%H:%i') AS s,
        DATE_FORMAT(mo.end_time,   '%H:%i') AS e,
        mo.client_name,
        mo.address_o AS client_address,
        mo.payment,
        mo.grand_total,
        mo.status,
        mo.updated_at
      FROM make_order mo
      WHERE mo.driver_id = :id
        AND mo.svc_date_calc BETWEEN :f AND :t
        AND COALESCE(mo.status,'') <> 'cancelled'
      ORDER BY mo.svc_date_calc, mo.start_time
    ");
    $q->execute([':id'=>$id, ':f'=>$from, ':t'=>$to]);

    // build workers label per order
    $ws = $conn->prepare("
      SELECT w.nickname
      FROM order_workers ow
      JOIN workers w ON w.id = ow.worker_id
      WHERE ow.order_id = ?
      ORDER BY w.nickname
    ");

    $events = [];
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
      $ws->execute([$r['id']]);
      $wNames = $ws->fetchAll(PDO::FETCH_COLUMN);
      $workers = $wNames ? implode(', ', array_map(fn($x)=>$x ?: 'Worker', $wNames)) : '—';

      $payment = strtolower((string)($r['payment'] ?? ''));
      $payLine = ($payment === 'cash') ? ('Cash: AED '.number_format((float)$r['grand_total'],2)) : 'Online';

      $summary = "Job: " . ($r['client_name'] ?? 'Client');
      $desc = "Client: ".($r['client_name'] ?? '')
            . "\\nAddress: ".($r['client_address'] ?? '')
            . "\\nWorkers: ".$workers
            . "\\nTime: ".($r['s'] ?? '')."–".($r['e'] ?? '')
            . "\\nPayment: ".$payLine
            . "\\nStatus: ".($r['status'] ?? '');

      $events[] = [
        'uid'         => "order-{$r['id']}@yourapp",
        'summary'     => $summary,
        'description' => $desc,
        'location'    => (string)($r['client_address'] ?? ''),
        'dtstart'     => ics_local_dt($r['svc_date'], $r['s'] ?? '00:00'),
        'dtend'       => ics_local_dt($r['svc_date'], $r['e'] ?? '00:00'),
        'tzid'        => ICS_TZID,
        'lastmod'     => ics_lastmod($r['updated_at'] ?? null),
      ];
    }

    $ics = ics_build("Driver #$id — Jobs", $events);
    header('Content-Disposition: inline; filename="driver-'.$id.'.ics"');
    header('Cache-Control: public, max-age=300, must-revalidate');
    echo $ics;
    exit;
  }

  if ($type === 'worker') {
    $q = $conn->prepare("
      SELECT
        mo.id,
        COALESCE(mo.service_date, mo.`date`) AS svc_date,
        DATE_FORMAT(mo.start_time, '%H:%i') AS s,
        DATE_FORMAT(mo.end_time,   '%H:%i') AS e,
        mo.client_name,
        mo.address_o AS client_address,
        mo.payment,
        mo.grand_total,
        mo.status,
        mo.updated_at
      FROM make_order mo
      JOIN order_workers ow ON ow.order_id = mo.id
      WHERE ow.worker_id = :id
        AND mo.svc_date_calc BETWEEN :f AND :t
        AND COALESCE(mo.status,'') <> 'cancelled'
      ORDER BY mo.svc_date_calc, mo.start_time
    ");
    $q->execute([':id'=>$id, ':f'=>$from, ':t'=>$to]);

    $events = [];
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
      $payment = strtolower((string)($r['payment'] ?? ''));
      $payLine = ($payment === 'cash') ? ('Cash: AED '.number_format((float)$r['grand_total'],2)) : 'Online';

      $summary = "Job: " . ($r['client_name'] ?? 'Client');
      $desc = "Client: ".($r['client_name'] ?? '')
            . "\\nAddress: ".($r['client_address'] ?? '')
            . "\\nTime: ".($r['s'] ?? '')."–".($r['e'] ?? '')
            . "\\nPayment: ".$payLine
            . "\\nStatus: ".($r['status'] ?? '');

      $events[] = [
        'uid'         => "order-{$r['id']}@yourapp",
        'summary'     => $summary,
        'description' => $desc,
        'location'    => (string)($r['client_address'] ?? ''),
        'dtstart'     => ics_local_dt($r['svc_date'], $r['s'] ?? '00:00'),
        'dtend'       => ics_local_dt($r['svc_date'], $r['e'] ?? '00:00'),
        'tzid'        => ICS_TZID,
        'lastmod'     => ics_lastmod($r['updated_at'] ?? null),
      ];
    }

    $ics = ics_build("Worker #$id — Jobs", $events);
    header('Content-Disposition: attachment; filename="worker-'.$id.'.ics"');
    header('Cache-Control: public, max-age=300, must-revalidate');
    echo $ics;
    exit;
  }

  // Unexpected type
  http_response_code(400);
  echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Unsupported//EN\r\nEND:VCALENDAR";
} catch (Throwable $e) {
  http_response_code(500);
  echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Error//EN\r\nX-ERROR: ".str_replace("\n"," ",$e->getMessage())."\r\nEND:VCALENDAR";
}
