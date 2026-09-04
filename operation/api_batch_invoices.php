<?php
// operation/api_batch_invoices.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db_connect.php';
  require_once __DIR__ . '/../includes/ar_helpers.php';

  $method = $_SERVER['REQUEST_METHOD'];

  // ---------- GET: preview eligible orders ----------
  if ($method === 'GET' && ($_GET['action'] ?? '') === 'preview') {
    $clientId   = (int)($_GET['client_id'] ?? 0);
    $from       = $_GET['from'] ?? '';
    $to         = $_GET['to'] ?? '';

    if ($clientId <= 0) throw new Exception('client_id required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      throw new Exception('Bad date range.');
    }

    $st = $conn->prepare("
      SELECT id, svc_date_calc, start_time, end_time, worker_name,
             hours, hourly_rate, total, vat_amount, grand_total
      FROM make_order
      WHERE client_id = ?
        AND svc_date_calc BETWEEN ? AND ?
        AND invoice_id IS NULL
        AND COALESCE(status,'') <> 'cancelled'
      ORDER BY svc_date_calc, id
    ");
    $st->execute([$clientId, $from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $sum = ['hours'=>0.0,'sub'=>0.0,'vat'=>0.0,'tot'=>0.0,'count'=>count($rows)];
    foreach ($rows as $r) {
      $sum['hours'] += (float)$r['hours'];
      $sum['sub']   += (float)$r['total'];
      $sum['vat']   += (float)$r['vat_amount'];
      $sum['tot']   += (float)$r['grand_total'];
    }
    foreach ($sum as $k=>$v) if (is_float($v)) $sum[$k] = round($v,2);

    echo json_encode(['success'=>true,'items'=>$rows,'summary'=>$sum]); exit;
  }

  // ---------- POST: commit (create one invoice for many orders) ----------
  if ($method === 'POST' && ($_POST['action'] ?? '') === 'commit') {
    $clientId   = (int)($_POST['client_id'] ?? 0);
    $from       = $_POST['from'] ?? '';
    $to         = $_POST['to'] ?? '';
    $itemize    = $_POST['itemization'] ?? 'per_order'; // or 'single_line'
    $idsRaw     = trim((string)($_POST['order_ids_csv'] ?? ''));
    $ids        = array_filter(array_map('intval', $idsRaw ? explode(',', $idsRaw) : []));

    if ($clientId <= 0) throw new Exception('client_id required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      throw new Exception('Bad date range.');
    }

    $iid = ar_generate_batch_invoice(
      $conn, $clientId, $from, $to, $ids, $itemize, $_SESSION['user_id'] ?? null
    );

    echo json_encode(['success'=>true,'invoice_id'=>$iid]); exit;
  }

  // ---------- GET: orders inside an invoice ----------
  if ($method === 'GET' && ($_GET['action'] ?? '') === 'invoice_orders') {
    $iid = (int)($_GET['invoice_id'] ?? 0);
    if ($iid <= 0) throw new Exception('invoice_id required');

    $st = $conn->prepare("
      SELECT mo.id, mo.svc_date_calc, mo.start_time, mo.end_time, mo.worker_name,
             mo.hours, mo.hourly_rate, mo.total, mo.vat_amount, mo.grand_total
      FROM make_order mo
      WHERE mo.invoice_id = ?
      ORDER BY mo.svc_date_calc, mo.id
    ");
    $st->execute([$iid]);
    echo json_encode(['success'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
  }

  throw new Exception('Unsupported request.');

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success'=>false,'error'=>'Batch AR: '.$e->getMessage()]);
}
