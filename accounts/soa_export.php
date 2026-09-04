<?php
// accounts/soa_export.php
declare(strict_types=1);

try {
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db_connect.php';
  require_once __DIR__ . '/../includes/ar_helpers.php';
  require_once __DIR__ . '/../includes/composer_autoload_safe.php';
  herosysgro_composer_autoload_safe();
  if (!class_exists('\Dompdf\Dompdf')) {
    throw new Exception('PDF export requires Dompdf. On this server PHP may be below the Composer platform requirement; use CSV export or upgrade PHP.');
  }

  // ---- Inputs
  $clientId = (int)($_GET['client_id'] ?? 0);
  $from     = $_GET['from'] ?? '';
  $to       = $_GET['to']   ?? '';
  $view     = $_GET['view'] ?? 'open'; // not used for filtering here, but left for future options

  if ($clientId <= 0) throw new Exception('client_id required');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    throw new Exception('Bad date range.');
  }

  // ---- Helpers
  $fmt2 = fn($n) => number_format((float)$n, 2, '.', ',');
  $money = function(float $n, string $cur): string {
    return $cur . ' ' . number_format($n, 2, '.', ',');
  };

  // ---- Company (adjust to your schema if needed)
  $co = $conn->query("SELECT * FROM company_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
  $coName   = (string)($co['legal_name'] ?? 'Company Name');
  $coTRN    = (string)($co['trn'] ?? '');
  $coPhone  = (string)($co['phone'] ?? '');
  $coEmail  = (string)($co['email'] ?? '');
  $currency = (string)($co['currency_code'] ?? 'AED');
  $coAddr   = trim(implode(', ', array_filter([
    $co['address_line1'] ?? '', $co['address_line2'] ?? '',
    $co['city'] ?? '', $co['state_region'] ?? '',
    $co['postcode'] ?? '', $co['country'] ?? ''
  ])));

  // Optional logo (embedded)
  $logoHtml = '';
  if (!empty($co['logo_path'])) {
    $abs = realpath(__DIR__ . '/../' . ltrim((string)$co['logo_path'], '/'));
    if ($abs && is_file($abs)) {
      $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION) ?: 'png');
      $data = @file_get_contents($abs);
      if ($data !== false) {
        $logoHtml = '<img alt="" src="data:image/'.$ext.';base64,'.base64_encode($data).'" style="height:32px">';
      }
    }
  }

  // ---- Client
  $st = $conn->prepare("SELECT id, client_name, address, email, mobile_num, trn FROM client WHERE id=?");
  $st->execute([$clientId]);
  $client = $st->fetch(PDO::FETCH_ASSOC);
  if (!$client) throw new Exception('Client not found');

  $clientName = (string)($client['client_name'] ?? 'Client');
  $clientTRN  = (string)($client['trn'] ?? '');
  $clientAddr = (string)($client['address'] ?? '');

  // ---- Opening balance as of the day before $from
  // Invoices before range
  $st = $conn->prepare("
    SELECT COALESCE(SUM(i.total),0) AS inv_sum
    FROM invoices i
    WHERE i.client_id=? AND i.issue_date < ? AND i.status <> 'void'
      AND " . ar_collectible_invoice_sql('i') . "
  ");
  $st->execute([$clientId, $from]);
  $invSum = (float)($st->fetchColumn() ?: 0);

  // Payments applied before range
  $st = $conn->prepare("
    SELECT COALESCE(SUM(ra.amount_applied),0) AS pay_sum
    FROM receipt_allocations ra
    JOIN receipts r ON r.id = ra.receipt_id
    JOIN invoices  i ON i.id = ra.invoice_id AND " . ar_collectible_invoice_sql('i') . "
    WHERE i.client_id=? AND r.receipt_date < ?
  ");
  $st->execute([$clientId, $from]);
  $paySum = (float)($st->fetchColumn() ?: 0);

  $openingBalance = round($invSum - $paySum, 2);

  // ---- Transactions in [from..to]
  // Invoices (positive)
  $sqlInv = "
    SELECT i.issue_date AS tdate,
           i.invoice_no AS ref,
           'Invoice'     AS ttype,
           i.total       AS amount,
           0.00          AS payment,
           CONCAT('Invoice ', i.invoice_no) AS descr
    FROM invoices i
    WHERE i.client_id=? AND i.issue_date BETWEEN ? AND ? AND i.status <> 'void'
      AND " . ar_collectible_invoice_sql('i') . "
  ";
  // Payments (via allocations)
  $sqlPay = "
    SELECT r.receipt_date AS tdate,
           r.receipt_no   AS ref,
           'Payment'      AS ttype,
           0.00              AS amount,
           ra.amount_applied AS payment,
           CONCAT('Payment ', r.receipt_no) AS descr
    FROM receipt_allocations ra
    JOIN receipts r ON r.id = ra.receipt_id
    JOIN invoices  i ON i.id = ra.invoice_id AND " . ar_collectible_invoice_sql('i') . "
    WHERE i.client_id=? AND r.receipt_date BETWEEN ? AND ?
  ";

  $tx = [];
  $st = $conn->prepare($sqlInv); $st->execute([$clientId, $from, $to]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $tx[] = $r;
  $st = $conn->prepare($sqlPay); $st->execute([$clientId, $from, $to]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $tx[] = $r;

  // Sort by date, then by type (Invoice before Payment for same date)
  usort($tx, function($a,$b){
    if ($a['tdate'] === $b['tdate']) return strcmp($a['ttype'], $b['ttype']);
    return strcmp($a['tdate'], $b['tdate']);
  });

  // KPIs
  $invoicesInRange = 0;
  $lastPaymentDate = null;
  $invoicesSum = 0.0;
  $paymentsSum = 0.0;

  // Build ledger rows + running balance
  $running  = $openingBalance;
  $rowsHtml = '';

  // Opening balance row (as of $from)
  $rowsHtml .= sprintf(
    '<tr>
       <td class="wrap">%s</td>
       <td class="wrap">—</td>
       <td class="wrap">Opening Balance</td>
       <td class="num"></td>
       <td class="num"></td>
       <td class="num"><strong>%s</strong></td>
     </tr>',
    htmlspecialchars($from, ENT_QUOTES, 'UTF-8'),
    $money($running, $currency)
  );

  foreach ($tx as $t) {
    $isInv = ($t['ttype'] === 'Invoice');
    $amt   = (float)$t['amount'];
    $pay   = (float)$t['payment'];

    if ($isInv) { $invoicesInRange++; $invoicesSum += $amt;  $running += $amt; }
    else        { $paymentsSum += $pay;                       $running -= $pay; }

    if (!$isInv) { // track last payment date
      if (!$lastPaymentDate || strcmp($t['tdate'], $lastPaymentDate) > 0) {
        $lastPaymentDate = $t['tdate'];
      }
    }

    $rowsHtml .= sprintf(
      '<tr>
         <td class="wrap">%s</td>
         <td class="wrap">%s</td>
         <td class="wrap">%s</td>
         <td class="num">%s</td>
         <td class="num">%s</td>
         <td class="num"><strong>%s</strong></td>
       </tr>',
      htmlspecialchars($t['tdate'], ENT_QUOTES, 'UTF-8'),
      htmlspecialchars($t['ref'], ENT_QUOTES, 'UTF-8'),
      htmlspecialchars($t['descr'], ENT_QUOTES, 'UTF-8'),
      $isInv ? $money($amt, $currency) : '',
      !$isInv ? $money($pay, $currency) : '',
      $money($running, $currency)
    );
  }

  $finalDue = $running;

  // ---- HTML
  $safeCo   = htmlspecialchars($coName, ENT_QUOTES, 'UTF-8');
  $safeCli  = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
  $safeAddr = htmlspecialchars($clientAddr, ENT_QUOTES, 'UTF-8');
  $periodText = htmlspecialchars("$from → $to", ENT_QUOTES, 'UTF-8');
  $title = 'Statement — ' . $clientName;

  ob_start(); // ensure nothing is sent before Dompdf streams
  ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= $title ?></title>
<style>
  :root{
    --ink:#0f172a; --muted:#6b7280; --edge:#e6eaf0; --bg:#f8fafc;
    --brand:#1e3a8a; --brand-ink:#ffffff; --bad:#b91c1c;
  }
  @page { margin: 12mm 10mm 14mm 10mm; }
  body  { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color:var(--ink); font-size:11px; }

  .mast { background:var(--ink); color:#fff; border-radius:5px; padding:8px 10px; }
  .mast-row { display:flex; justify-content:space-between; gap:12px; }
  .mast .co { font-weight:700; font-size:14px; letter-spacing:.1px; }
  .mast small { color:#dbe3ef; font-size:9px; }

  h1 { margin:8px 0 6px; font-size:16px; letter-spacing:.1px; }

  .meta { display:grid; grid-template-columns:1fr 1fr; gap:6px; border:1px solid var(--edge);
          border-radius:6px; padding:6px 8px; background:#fff; }
  .meta .row { display:grid; grid-template-columns:130px 1fr; gap:6px; }
  .lbl { color:var(--muted); font-size:9px; }
  .val { font-weight:600; font-size:10px; }

  .pills { width:100%; border-collapse:separate; border-spacing:6px; margin:6px 0 8px; }
  .pill { width:25%; border:1px solid var(--edge); border-radius:6px; padding:6px 8px; background:#fff; vertical-align:top; }
  .pill .lbl { font-size:9px; color:var(--muted); line-height:1.2; margin-bottom:1px; }
  .pill .val { font-weight:700; margin-top:1px; font-size:11px; line-height:1.2; color:var(--ink); }
  .pill--brand { background:linear-gradient(#f7f9ff,#eef3ff); border-color:#d7e1ff; }

  table.ledger { width:99%; table-layout:fixed; border-collapse:collapse; margin-top:4px; }
  .ledger th, .ledger td { border:1px solid var(--edge); padding:4px 5px; font-size:9px; vertical-align:top; }
  .ledger thead th { background:#203244; color:#fff; font-weight:700; font-size:9px; }
  .ledger tbody tr:nth-child(4n+1) { background:#fbfdff; }
  .num { text-align:right; }
  .wrap { word-wrap:break-word; overflow-wrap:break-word; word-break:break-word; white-space:normal; }
  .neg { color:var(--bad); }

  .totals { display:flex; justify-content:flex-end; margin-top:4px; }
  .totals .box { min-width:320px; border:1px solid var(--edge); border-radius:6px; padding:5px 8px; background:#fff; }
  .totals .row { display:flex; justify-content:space-between; margin:1px 0; font-size:10px; }
  .totals .row strong { font-weight:700; }

  .remit { display:flex; gap:10px; margin-top:8px; }
  .remit .card { flex:1; border:1px solid var(--edge); border-radius:6px; padding:8px; background:#fff; }
  .remit .title { font-weight:700; margin-bottom:3px; font-size:10px; }
  .small { font-size:9px; color:var(--muted); }
</style>
</head>
<body>

<!-- Masthead -->
<div class="mast">
  <div class="mast-row">
    <div>
      <div class="co"><?= $safeCo ?></div>
      <small>TRN: <?= htmlspecialchars($coTRN, ENT_QUOTES, 'UTF-8') ?></small>
    </div>
    <div style="text-align:right">
      <div><?= $logoHtml ?></div>
      <small><?= htmlspecialchars($coAddr, ENT_QUOTES, 'UTF-8') ?></small><br>
      <small>Phone: <?= htmlspecialchars($coPhone, ENT_QUOTES, 'UTF-8') ?> &nbsp;&bull;&nbsp; E-mail: <?= htmlspecialchars($coEmail, ENT_QUOTES, 'UTF-8') ?></small>
    </div>
  </div>
</div>

<h1>Statement</h1>

<!-- Meta -->
<div class="meta">
  <div class="row"><div class="lbl">Statement #:</div><div class="val">—</div></div>
  <div class="row"><div class="lbl">Bill To:</div><div class="val"><?= $safeCli ?></div></div>

  <div class="row"><div class="lbl">Date:</div><div class="val"><?= date('Y-m-d') ?></div></div>
  <div class="row"><div class="lbl">Address:</div><div class="val"><?= $safeAddr ?></div></div>

  <div class="row"><div class="lbl">Customer ID:</div><div class="val"><?= (int)$clientId ?></div></div>
  <div class="row"><div class="lbl">Period:</div><div class="val"><?= $periodText ?></div></div>
</div>

<!-- KPI pills -->
<table class="pills">
  <tr>
    <td class="pill"><div class="lbl">Open Balance</div><div class="val"><?= $money($openingBalance, $currency) ?></div></td>
    <td class="pill pill--brand"><div class="lbl">Final Balance</div><div class="val"><?= $money($finalDue, $currency) ?></div></td>
    <td class="pill"><div class="lbl">Invoices in Range</div><div class="val"><?= $invoicesInRange ?></div></td>
    <td class="pill"><div class="lbl">Last Payment</div><div class="val"><?= htmlspecialchars($lastPaymentDate ?? '—', ENT_QUOTES, 'UTF-8') ?></div></td>
  </tr>
</table>

<!-- Ledger -->
<table class="ledger">
  <thead>
    <tr>
      <th style="width:12%;">Date</th>
      <th style="width:22%;">Invoice # / Ref</th>
      <th style="width:33%;">Description</th>
      <th style="width:11%;" class="num">Amount</th>
      <th style="width:10%;" class="num">Payment</th>
      <th style="width:12%;" class="num">Balance</th>
    </tr>
  </thead>
  <tbody>
    <?= $rowsHtml ?>
  </tbody>
</table>

<!-- Totals -->
<div class="totals">
  <div class="box">
    <div class="row"><span>Invoices (period)</span><span class="num"><?= $money($invoicesSum, $currency) ?></span></div>
    <div class="row"><span>Payments (period)</span><span class="num"><?= $money($paymentsSum, $currency) ?></span></div>
    <div class="row"><strong>Amount Due</strong><strong class="num"><?= $money($finalDue, $currency) ?></strong></div>
  </div>
</div>

<!-- Remittance -->
<div class="remit">
  <div class="card">
    <div class="title">REMITTANCE</div>
    <div class="small">Please include the statement date in your payment reference.</div>
    <div style="margin-top:6px;">
      <div><span class="lbl">Customer Name:</span> <span class="val"><?= $safeCli ?></span></div>
      <div><span class="lbl">Customer ID:</span> <span class="val"><?= (int)$clientId ?></span></div>
      <div><span class="lbl">Statement #:</span> <span class="val">—</span></div>
      <div><span class="lbl">Date:</span> <span class="val"><?= date('Y-m-d') ?></span></div>
      <div><span class="lbl">Amount Due:</span> <span class="val"><?= $money($finalDue, $currency) ?></span></div>
      <div><span class="lbl">Amount Enclosed:</span> <span class="val">__________</span></div>
    </div>
  </div>
  <div class="card">
    <div class="title">Please make payments payable to</div>
    <div><strong><?= $safeCo ?></strong></div>
    <div class="small"><?= htmlspecialchars($coAddr, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="small">Phone: <?= htmlspecialchars($coPhone, ENT_QUOTES, 'UTF-8') ?> &nbsp; • &nbsp; Email: <?= htmlspecialchars($coEmail, ENT_QUOTES, 'UTF-8') ?></div>
  </div>
</div>



</body>
</html>
<?php
  $html = ob_get_clean();

  // ---- Render
  $options = new \Dompdf\Options();
  $options->set('isHtml5ParserEnabled', true);
  $options->set('isRemoteEnabled', true);

  $tmp = __DIR__ . '/storage/dompdf_tmp';
  if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
  $options->set('tempDir', $tmp);
  $options->set('fontCache', $tmp);

  $dompdf = new \Dompdf\Dompdf($options);
  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  // Optional: draw footer page numbers via canvas (kept simple since we have fixed footer)
  // $canvas  = $dompdf->getCanvas();
  // $metrics = $dompdf->getFontMetrics();
  // $font    = $metrics->getFont('DejaVu Sans', 'normal');
  // $canvas->page_text($canvas->get_width()-120, $canvas->get_height()-28, "Page {PAGE_NUM} / {PAGE_COUNT}", $font, 9, [0,0,0]);

  $safeName = preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', $clientName);
  $dompdf->stream("SOA-{$safeName}-{$from}-{$to}.pdf", ['Attachment' => false]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  // minimal error page (no prior output!)
  echo "<h3>SOA Export Error</h3><pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
}
