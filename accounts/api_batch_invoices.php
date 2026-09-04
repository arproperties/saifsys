<?php
// accounts/api_batch_invoices.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db_connect.php';
  require_once __DIR__ . '/../includes/ar_helpers.php';
  require_once __DIR__ . '/../includes/work_order_batch_invoice_service.php';

  /* -------------------------- helpers: signatures -------------------------- */

  /** Try hard to turn a DB-stored path into an absolute, existing file. */
  function resolve_abs_path(string $relOrAbs): ?string {
    $p = trim($relOrAbs);
    if ($p === '') return null;

    // If it's already absolute and exists, use it.
    if ($p[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $p)) {
      return is_file($p) ? $p : null;
    }

    // Likely relative → try several project roots
    $appRoot   = realpath(dirname(__DIR__));             // .../bmsystem-web
    $accounts  = realpath(__DIR__);                      // .../bmsystem-web/accounts
    $candidates = [];
    if ($appRoot)   $candidates[] = $appRoot . DIRECTORY_SEPARATOR . ltrim($p, '/');
    if ($accounts)  $candidates[] = $accounts . DIRECTORY_SEPARATOR . ltrim($p, '/');
    // common alt folders
    if ($appRoot) {
      $candidates[] = $appRoot . DIRECTORY_SEPARATOR . 'public'   . DIRECTORY_SEPARATOR . ltrim($p,'/');
      $candidates[] = $appRoot . DIRECTORY_SEPARATOR . 'assets'   . DIRECTORY_SEPARATOR . ltrim($p,'/');
      $candidates[] = $appRoot . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . ltrim($p,'/');
    }

    foreach ($candidates as $c) {
      if (is_file($c)) return $c;
    }
    // last resort: remove leading "../" segments safely
    $clean = preg_replace('~^(?:\.\./)+~', '', $p);
    if ($appRoot) {
      $fallback = $appRoot . DIRECTORY_SEPARATOR . $clean;
      if (is_file($fallback)) return $fallback;
    }
    return null;
  }

    /** Read current user’s name + signature image path (robust). */
    function resolve_preparer(PDO $conn): array {
      // Best-effort name from session
      $name = null;
      foreach (['user_fullname','full_name','fullname','display_name','username','email'] as $k) {
        if (!empty($_SESSION[$k])) { $name = (string)$_SESSION[$k]; break; }
      }

      $uid = $_SESSION['user_id'] ?? null;
      $sigAbs = null;

      if ($uid) {
        // Your table is literally named `user` and has: fullname, username, email, signature_path
        $cols = ['fullname','username','email'];   // <-- removed non-existent `name`
        $sel  = implode(',', array_map(fn($c)=>"COALESCE($c,'') AS $c", $cols));
        $q = $conn->prepare("SELECT $sel, signature_path FROM `user` WHERE id=? LIMIT 1");
        $q->execute([$uid]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
          if (!$name) {
            foreach ($cols as $c) { if (!empty($row[$c])) { $name = (string)$row[$c]; break; } }
          }
          $stored = (string)($row['signature_path'] ?? '');
          if ($stored !== '') {
            $sigAbs = resolve_abs_path($stored);
            error_log('[ROV] signature_path='.$stored.' -> resolved='.($sigAbs ?: 'NULL'));
          }
        }
      }

      return ['fullname' => $name ?: '—', 'sig_abs' => $sigAbs];
    }

  /** Turn absolute image file into base64 <img>; on failure returns empty string. */
  function embed_img_base64(?string $abs, string $heightCss='34px'): string {
    if (!$abs || !is_file($abs)) return '';
    $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION) ?: 'png');
    $data = @file_get_contents($abs);
    if ($data === false) return '';
    return '<img alt="" src="data:image/'.$ext.';base64,'.base64_encode($data).'" style="height:'.$heightCss.'">';
  }

  /* ------------------------ company/logo helpers --------------------------- */

  function load_company_settings(PDO $conn): array {
    $co = $conn->query("SELECT * FROM company_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $logoUrl = null; $logoAbs = null;
    if (!empty($co['logo_path'])) {
      $p = trim($co['logo_path']);
      if (preg_match('~^https?://~i', $p)) {
        $logoUrl = $p;
      } else {
        $logoUrl = '../' . ltrim($p, '/'); // for browser/html link
        $logoAbs = realpath(__DIR__ . '/../' . ltrim($p, '/'));
      }
    }
    return [
      'legal_name' => $co['legal_name']        ?? '',
      'trn'        => $co['trn']               ?? '',
      'currency'   => $co['currency_code']     ?? 'AED',
      'address1'   => $co['address_line1']     ?? '',
      'address2'   => $co['address_line2']     ?? '',
      'city'       => $co['city']              ?? '',
      'state'      => $co['state_region']      ?? '',
      'postcode'   => $co['postcode']          ?? '',
      'country'    => $co['country']           ?? '',
      'phone'      => $co['phone']             ?? '',
      'email'      => $co['email']             ?? '',
      'website'    => $co['website']           ?? '',
      'logo_url'   => $logoUrl,
      'logo_abs'   => $logoAbs,
    ];
  }

  function company_logo_html(array $company): string {
    $abs = $company['logo_abs'] ?? null;
    if ($abs && is_file($abs)) {
      $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION) ?: 'png');
      $data = base64_encode(@file_get_contents($abs));
      if ($data) return '<img alt="" src="data:image/'.$ext.';base64,'.$data.'" style="height:90px">';
    }
    return '';
  }

  /* --------------------------- ROV HTML builder ---------------------------- */

  function build_rov_html(array $company, array $client, array $orders, string $from, string $to): string {
    $clientName = htmlspecialchars($client['client_name'] ?? 'Client', ENT_QUOTES, 'UTF-8');
    $trnClient  = htmlspecialchars((string)($client['trn'] ?? ''), ENT_QUOTES, 'UTF-8');

    $coName   = htmlspecialchars($company['legal_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $coTRN    = htmlspecialchars($company['trn'] ?? '', ENT_QUOTES, 'UTF-8');
    $coPhone  = htmlspecialchars($company['phone'] ?? '', ENT_QUOTES, 'UTF-8');
    $coEmail  = htmlspecialchars($company['email'] ?? '', ENT_QUOTES, 'UTF-8');
    $coSite   = htmlspecialchars($company['website'] ?? '', ENT_QUOTES, 'UTF-8');
    $currency = htmlspecialchars($company['currency'] ?? 'AED', ENT_QUOTES, 'UTF-8');

    $coAddrParts = array_filter([
      $company['address1'] ?? '',
      $company['address2'] ?? '',
      $company['city']     ?? '',
      $company['state']    ?? '',
      $company['country']  ?? '',
      $company['postcode'] ?? '',
    ]);
    $coAddr = htmlspecialchars(implode(', ', $coAddrParts), ENT_QUOTES, 'UTF-8');

    $preparedBy     = htmlspecialchars($company['prepared_by'] ?? '', ENT_QUOTES, 'UTF-8');
    $preparedSigImg = embed_img_base64($company['prepared_sig_abs'] ?? null, '40px');

    $generatedAt = date('Y-m-d H:i');
    $docNo       = 'ROV-' . strtoupper(bin2hex(random_bytes(4)));
    $period      = htmlspecialchars("$from → $to", ENT_QUOTES, 'UTF-8');

    $fmt = fn($n) => number_format((float)$n, 2, '.', ',');
    $logoHtml = company_logo_html($company);

    $brand = preg_match('/^#?[0-9a-f]{6}$/i', (string)($company['brand_color'] ?? ''))
             ? '#' . ltrim($company['brand_color'], '#')
             : '#c53030';

    $sumH=$sumSub=$sumVat=$sumTot=0; $rows='';
    foreach ($orders as $r) {
      $sumH   += (float)$r['hours'];
      $sumSub += (float)$r['total'];
      $sumVat += (float)$r['vat_amount'];
      $sumTot += (float)$r['grand_total'];
      $rate    = isset($r['hourly_rate']) ? (float)$r['hourly_rate'] : (isset($r['rate']) ? (float)$r['rate'] : 0);

      $rows .= sprintf(
        '<tr>
           <td class="mono">%d</td>
           <td>%s</td>
           <td class="mono">%s–%s</td>
           <td>%s</td>
           <td class="num">%s</td>
           <td class="num">%s</td>
           <td class="num">%s</td>
           <td class="num">%s</td>
           <td class="num">%s</td>
         </tr>',
        (int)$r['id'],
        htmlspecialchars($r['svc_date_calc'], ENT_QUOTES, 'UTF-8'),
        substr((string)$r['start_time'],0,5),
        substr((string)$r['end_time'],0,5),
        htmlspecialchars((string)$r['worker_name'], ENT_QUOTES, 'UTF-8'),
        $fmt($r['hours']),
        $fmt($rate),
        $fmt($r['total']),
        $fmt($r['vat_amount']),
        $fmt($r['grand_total'])
      );
    }

    $sumRow = sprintf(
      '<tr class="tot">
         <td colspan="4" class="right">Totals</td>
         <td class="num">%s</td>
         <td></td>
         <td class="num">%s</td>
         <td class="num">%s</td>
         <td class="num">%s</td>
       </tr>',
      $fmt($sumH), $fmt($sumSub), $fmt($sumVat), $fmt($sumTot)
    );

    return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Record of Visits — {$clientName}</title>
<style>
  :root { --brand: {$brand}; --ink:#0f172a; --muted:#64748b; --edge:#e5e7eb; --bg:#f8fafc; }
  @page { margin: 14mm 13mm 18mm 13mm; }
  body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color:var(--ink); font-size:12px; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
  .muted{ color:var(--muted); }
  .num  { text-align:right; }
  .right{ text-align:right; }

  .brand { display: table; width: 100%; margin-bottom: 6px; }
  .brand .cell { display: table-cell; vertical-align: middle; }
  .brand .logo { width: 60%; }
  .brand .info { width: 60%; text-align: right; }
  .brand .logo img { height: 90px; }
  h1 { margin: 0 0 4px; font-size: 18px; letter-spacing:.2px; }
  .period { margin: 0 0 8px; }

  .cards { width:100%; border-collapse:separate; border-spacing: 6px; }
  .card  { border:1px solid var(--edge); border-radius:8px; }
  .card .title { background:linear-gradient(#ffffff,#fbfdff); border-bottom:1px solid var(--edge); padding:6px 10px; font-weight:600; }
  .card .body { padding:10px; line-height:1.45; }

  .sumtbl { width:100%; border-collapse:collapse; }
  .sumtbl td { padding:2px 0; }
  .sumtbl td.lbl { color:#475569; }
  .sum-total { border-top:1px solid var(--edge); margin-top:6px; padding-top:6px; font-weight:700; font-size:14px; }

  table.tbl { width:100%; border-collapse:collapse; margin-top:8px; }
  .tbl th, .tbl td { padding:8px 8px; border-bottom:1px solid #eef2f7; }
  .tbl thead th { background:#ffffff; border-bottom:2px solid var(--edge); font-weight:600; }
  .tbl thead th:first-child { border-left: 3px solid var(--brand); }
  .tbl tbody tr:nth-child(even) { background:#fcfdff; }
  .tbl .tot td { font-weight:700; border-top:2px solid #cbd5e1; background:#fafafa; }

  .signs { display: table; width:100%; margin-top:14px; }
  .sign  { display: table-cell; width:50%; padding-right:16px; vertical-align:bottom; }
  .sigline { margin-top:8px; border-top:1px solid var(--edge); height:1px; }
</style>
</head>
<body>

<div class="brand">
  <div class="cell logo">{$logoHtml}</div>
  <div class="cell info">
    <div style="font-size:12px;"><strong>{$coName}</strong></div>
    <div class="muted">TRN: {$coTRN}</div>
    <div class="muted">{$coAddr}</div>
    <div class="muted">{$coPhone} • {$coEmail}</div>
  </div>
</div>

<h1>Record of Visits (ROV)</h1>
<div class="muted period">Period: {$period}</div>

<table class="cards">
  <tr>
    <td class="card" style="width:33%">
      <div class="title">Client</div>
      <div class="body">
        <div><strong>{$clientName}</strong></div>
        <div class="muted">Client TRN: {$trnClient}</div>
      </div>
    </td>
    <td class="card" style="width:33%">
      <div class="title">Document</div>
      <div class="body">
        <div>Document #: {$docNo}</div>
        <div>Generated: {$generatedAt}</div>
      </div>
    </td>
    <td class="card" style="width:34%">
      <div class="title">Summary</div>
      <div class="body">
        <table class="sumtbl">
          <tr><td class="lbl">Hours</td><td class="num">{$fmt($sumH)}</td></tr>
          <tr><td class="lbl">Subtotal</td><td class="num">{$currency} {$fmt($sumSub)}</td></tr>
          <tr><td class="lbl">VAT</td><td class="num">{$currency} {$fmt($sumVat)}</td></tr>
        </table>
        <div class="sum-total">
          <table class="sumtbl">
            <tr><td>Total</td><td class="num">{$currency} {$fmt($sumTot)}</td></tr>
          </table>
        </div>
      </div>
    </td>
  </tr>
</table>

<table class="tbl">
  <thead>
    <tr>
      <th style="width:80px;">Order #</th>
      <th style="width:110px;">Date</th>
      <th style="width:120px;">Time</th>
      <th>Workers</th>
      <th class="num" style="width:70px;">Hours</th>
      <th class="num" style="width:85px;">Rate</th>
      <th class="num" style="width:105px;">Subtotal</th>
      <th class="num" style="width:85px;">VAT</th>
      <th class="num" style="width:110px;">Total</th>
    </tr>
  </thead>
  <tbody>
    {$rows}
    {$sumRow}
  </tbody>
</table>

<div class="signs">
  <div class="sign">
    <div class="muted">Prepared by</div>
    <div style="height:45px;display:flex;align-items:flex-end;">{$preparedSigImg}</div>
    <div class="sigline"></div>
    
  </div>
  
</div>

<div style="position: fixed; bottom: -12mm; left: 0; right: 0; height: 12mm; color:#64748b; font-size:10px;">
  <div style="border-top:1px solid var(--edge); padding-top:4px; display:flex; justify-content:space-between;">
    <div>{$coSite} • {$coEmail}</div>
    
  </div>
</div>

</body>
</html>
HTML;
  }

  /* ------------------------------- ROUTES ---------------------------------- */

  $method = $_SERVER['REQUEST_METHOD'];

  // GET: preview
  if ($method === 'GET' && ($_GET['action'] ?? '') === 'preview') {
    $clientId = (int)($_GET['client_id'] ?? 0);
    $from     = $_GET['from'] ?? '';
    $to       = $_GET['to']   ?? '';
    $showInvoiced = !empty($_GET['show_invoiced']); // true if checkbox is checked
    
    if ($clientId <= 0) throw new Exception('client_id required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      throw new Exception('Bad date range.');
    }

    // Build conditional WHERE clause for invoice_id
    $showInvoiced = !empty($_GET['show_invoiced']); // true if checkbox is checked

    if (sm_hybrid_batch_enabled($conn)) {
      $rows = sm_fetch_batch_eligible_orders($conn, $clientId, $from, $to, [], $showInvoiced);
      $sum = ['hours'=>0.0,'sub'=>0.0,'vat'=>0.0,'tot'=>0.0,'count'=>count($rows)];
      foreach ($rows as &$r) {
        $r['vat_amount'] = (float)($r['child_vat'] ?? $r['vat_amount']);
        $r['grand_total'] = (float)($r['child_total'] ?? $r['grand_total']);
        $r['batch_status'] = $showInvoiced ? 'included' : 'ready';
        $sum['hours'] += (float)$r['hours'];
        $sum['sub']   += (float)($r['child_subtotal'] ?? $r['total']);
        $sum['vat']   += $r['vat_amount'];
        $sum['tot']   += $r['grand_total'];
      }
      unset($r);
      foreach ($sum as $k=>$v) if (is_float($v)) $sum[$k] = round($v,2);
      echo json_encode(['success'=>true,'items'=>$rows,'summary'=>$sum,'mode'=>'hybrid']); exit;
    }

    $invoiceCondition = $showInvoiced ? '' : 'AND COALESCE(invoice_id,0) = 0';

    $st = $conn->prepare("
      SELECT
        id, svc_date_calc, start_time, end_time, worker_name,
        hours, hourly_rate, total, invoice_id,
        COALESCE(vat_amount, ROUND(COALESCE(total,0) * COALESCE(vat_rate,5.00)/100, 2)) AS vat_amount_calc,
        COALESCE(grand_total, ROUND(COALESCE(total,0) + COALESCE(vat_amount,
              ROUND(COALESCE(total,0) * COALESCE(vat_rate,5.00)/100, 2)), 2)) AS grand_total_calc
      FROM make_order
      WHERE client_id = ?
        AND svc_date_calc BETWEEN ? AND ?
        $invoiceCondition
        AND COALESCE(status,'') <> 'cancelled'
      ORDER BY svc_date_calc, id
    ");
    $st->execute([$clientId, $from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $sum = ['hours'=>0.0,'sub'=>0.0,'vat'=>0.0,'tot'=>0.0,'count'=>count($rows)];
    foreach ($rows as &$r) {
      $r['vat_amount']  = (float)$r['vat_amount_calc'];
      $r['grand_total'] = (float)$r['grand_total_calc'];
      unset($r['vat_amount_calc'], $r['grand_total_calc']);
      $sum['hours'] += (float)$r['hours'];
      $sum['sub']   += (float)$r['total'];
      $sum['vat']   += (float)$r['vat_amount'];
      $sum['tot']   += (float)$r['grand_total'];
    }
    foreach ($sum as $k=>$v) if (is_float($v)) $sum[$k] = round($v,2);

    echo json_encode(['success'=>true,'items'=>$rows,'summary'=>$sum]); exit;
  }

  // POST: commit
  if ($method === 'POST' && ($_POST['action'] ?? '') === 'commit') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $from     = $_POST['from'] ?? '';
    $to       = $_POST['to']   ?? '';
    $itemize  = $_POST['itemization'] ?? 'single_line'; // or 'per_order'
    $idsRaw   = trim((string)($_POST['order_ids_csv'] ?? ''));
    $ids      = array_filter(array_map('intval', $idsRaw ? explode(',', $idsRaw) : []));

    if ($clientId <= 0) throw new Exception('client_id required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      throw new Exception('Bad date range.');
    }

    $iid = ar_generate_batch_invoice(
      $conn, $clientId, $from, $to, $ids, $itemize, $_SESSION['user_id'] ?? null
    );

    echo json_encode(['success'=>true,'invoice_id'=>$iid,'mode'=> sm_hybrid_batch_enabled($conn) ? 'hybrid' : 'legacy']); exit;
  }

  // GET: ROV PDF
  if ($method === 'GET' && ($_GET['action'] ?? '') === 'rov_pdf') {
    $clientId = (int)($_GET['client_id'] ?? 0);
    $from     = $_GET['from'] ?? '';
    $to       = $_GET['to']   ?? '';
    $showInvoiced = !empty($_GET['show_invoiced']); // true if checkbox is checked
    
    if ($clientId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      throw new Exception('Bad client or date range.');
    }

    $company = load_company_settings($conn);

    $cst = $conn->prepare("SELECT id, client_name, address, email, mobile_num, trn FROM client WHERE id=?");
    $cst->execute([$clientId]);
    $client = $cst->fetch(PDO::FETCH_ASSOC);
    if (!$client) throw new Exception('Client not found');

    // Build conditional WHERE clause for invoice_id
    $invoiceCondition = $showInvoiced ? '' : 'AND (invoice_id IS NULL OR invoice_id = 0)';

    $ost = $conn->prepare("
      SELECT
        id, svc_date_calc, start_time, end_time, worker_name,
        hours, hourly_rate AS rate, total,
        COALESCE(vat_amount, ROUND(COALESCE(total,0) * COALESCE(vat_rate,5.00)/100, 2)) AS vat_amount,
        COALESCE(grand_total, ROUND(COALESCE(total,0) + COALESCE(vat_amount,
               ROUND(COALESCE(total,0) * COALESCE(vat_rate,5.00)/100, 2)), 2)) AS grand_total
      FROM make_order
      WHERE client_id = ?
        AND svc_date_calc BETWEEN ? AND ?
        $invoiceCondition
        AND COALESCE(status,'') <> 'cancelled'
      ORDER BY svc_date_calc, id
    ");
    $ost->execute([$clientId, $from, $to]);
    $orders = $ost->fetchAll(PDO::FETCH_ASSOC);
    if (!$orders) throw new Exception('No orders in this period.');

    // attach preparer (name + resolved signature)
    $prep = resolve_preparer($conn);
    $company['prepared_by']      = $prep['fullname'];
    $company['prepared_sig_abs'] = $prep['sig_abs'];

    $html = build_rov_html($company, $client, $orders, $from, $to);

    $outDir = __DIR__ . '/storage/rov';
    if (!is_dir($outDir)) { @mkdir($outDir, 0777, true); }
    if (!is_dir($outDir) || !is_writable($outDir)) {
      throw new Exception("ROV output directory is not writable: $outDir");
    }

    $base        = 'ROV-' . preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', ($client['client_name'] ?? 'Client')) . "-$from-$to";
    $outDirReal  = realpath($outDir) ?: $outDir;
    $pdfPath     = $outDirReal . DIRECTORY_SEPARATOR . $base . '.pdf';
    $htmlPath    = $outDirReal . DIRECTORY_SEPARATOR . $base . '.html';

    error_log("ROV DEBUG outDirReal=$outDirReal base=$base pdfPath=$pdfPath");

    require_once __DIR__ . '/../includes/composer_autoload_safe.php';
    herosysgro_composer_autoload_safe();

    $fileUrl = null;
    if (class_exists('\Dompdf\Dompdf')) {
      // use a dedicated, writable temp/cache for dompdf
      $tmpDir = __DIR__ . '/storage/dompdf_tmp';
      if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); }
      if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
        throw new Exception("Dompdf temp directory is not writable: $tmpDir");
      }

      $options = new \Dompdf\Options();
      $options->set('isHtml5ParserEnabled', true);
      $options->set('isRemoteEnabled', true);
      $options->set('tempDir',  $tmpDir);
      $options->set('fontCache', $tmpDir);

      $dompdf = new \Dompdf\Dompdf($options);
      $dompdf->loadHtml($html, 'UTF-8');
      $dompdf->setPaper('A4', 'portrait');
      $dompdf->render();

      $canvas  = $dompdf->getCanvas();
      $metrics = $dompdf->getFontMetrics();
      $font    = $metrics->getFont('DejaVu Sans', 'normal');
      $canvas->page_text(
        $canvas->get_width() - 120,
        $canvas->get_height() - 28,
        "Page {PAGE_NUM} / {PAGE_COUNT}",
        $font, 9, [0,0,0]
      );

      file_put_contents($pdfPath, $dompdf->output());
      $fileUrl = 'accounts/storage/rov/' . basename($pdfPath);
    } else {
      file_put_contents($htmlPath, $html);
      $fileUrl = 'accounts/storage/rov/' . basename($htmlPath);
    }

    echo json_encode(['success' => true, 'file_url' => $fileUrl]);
    exit;
  }

  // GET: orders inside an invoice
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
