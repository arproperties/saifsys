<?php
// accounts/invoice_print_pdf.php — Clean invoice HTML for PDF generation (no auth checks)
// Get connection from global scope
global $conn;
if (!isset($conn) && isset($GLOBALS['conn'])) {
    $conn = $GLOBALS['conn'];
}
if (!isset($conn)) {
    require_once __DIR__.'/../includes/db_connect.php';
}
require_once __DIR__.'/../includes/ar_helpers.php';

$invoice_id = (int)($_GET['id'] ?? 0);
if ($invoice_id <= 0) { http_response_code(404); echo "Invoice not found."; exit; }

/* ---------- Company (from company_settings) ---------- */
$co = $conn->query("SELECT * FROM company_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$logoUrl = null;
if (!empty($co['logo_path'])) {
  $p = trim($co['logo_path']);
  $logoUrl = preg_match('~^https?://~i', $p) ? $p : '../'.ltrim($p,'/');
}
// Convert logo to base64 data URI if exists
$logoDataUri = null;
if (!empty($co['logo_path'])) {
  $logoPath = realpath(__DIR__.'/../'.$co['logo_path']);
  if ($logoPath && file_exists($logoPath)) {
    $logoExt = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
    $logoContent = file_get_contents($logoPath);
    $logoDataUri = 'data:image/'.$logoExt.';base64,'.base64_encode($logoContent);
  }
}

$company = [
  'legal_name' => $co['legal_name']     ?? '',
  'trn'        => $co['trn']            ?? '',
  'currency'   => $co['currency_code']  ?? 'AED',
  'address1'   => $co['address_line1']  ?? '',
  'address2'   => $co['address_line2']  ?? '',
  'city'       => $co['city']           ?? '',
  'state'      => $co['state_region']   ?? '',
  'postcode'   => $co['postcode']       ?? '',
  'country'    => $co['country']        ?? '',
  'phone'      => $co['phone']          ?? '',
  'email'      => $co['email']          ?? '',
  'website'    => $co['website']        ?? '',
  'logo_url'   => $logoUrl,
  'logo_data'  => $logoDataUri,

  // optional banking/terms fields (add these columns to company_settings as needed)
  'bank_name'  => $co['bank_name']      ?? '',
  'bank_acct'  => $co['bank_account_no']?? '',
  'bank_iban'  => $co['bank_iban']      ?? '',
  'bank_swift' => $co['bank_swift']     ?? '',
  'terms'      => $co['terms']          ?? '',
];

/* ---------- Invoice Template Settings ---------- */
$template = $conn->query("SELECT * FROM invoice_templates WHERE is_default = 1 AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$template) {
  // Fallback to default template if none is set
  $template = [
    'name' => 'Default Template',
    'primary_color' => '#0b2a4a',
    'accent_color' => '#e53935',
    'background_color' => '#ffffff',
    'text_color' => '#333333',
    'border_color' => '#e6e7eb',
    'show_company_name' => 1,
    'show_logo' => 1,
    'header_layout' => 'logo_left',
    'invoice_title' => 'TAX INVOICE',
    'show_invoice_number' => 1,
    'show_invoice_date' => 1,
    'show_due_date' => 1,
    'show_bill_to' => 1,
    'show_company_info' => 1,
    'show_bank_details' => 1,
    'show_terms' => 1,
    'show_signature' => 1,
    'table_header_bg' => '#eef0f3',
    'table_stripe_bg' => '#f5f6f8',
    'table_border_color' => '#dee2e6',
    'footer_text' => 'Thank you for your business',
    'show_amount_in_words' => 1,
    'font_family' => 'system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, sans-serif',
    'font_size_base' => '14px',
    'font_size_title' => '24px',
    'font_size_header' => '18px',
    'page_margin' => '28px',
    'section_spacing' => '20px'
  ];
}

/* ---------- Invoice + items + payments ---------- */
$inv    = ar_get_invoice($conn, $invoice_id);                 // header + totals
if (!$inv) { http_response_code(404); echo "Invoice not found."; exit; }
$items  = ar_get_invoice_items($conn, $invoice_id);           // invoice_items
$pays   = ar_get_invoice_payments($conn, $invoice_id);        // optional (unused in print)
$amountPaid = isset($inv['amount_paid']) ? (float)$inv['amount_paid'] : 0.0;
$balanceDue = isset($inv['balance_due']) ? (float)$inv['balance_due'] : max(0.0, (float)$inv['total'] - $amountPaid);
$balanceDue = max(0.0, $balanceDue);

/* ---------- Pretty blocks ---------- */
$coLines = [];
if ($company['legal_name']) $coLines[] = $company['legal_name'];
$addrParts = array_filter([$company['address1'],$company['address2'],$company['state'],$company['postcode'],$company['country']]);
if ($addrParts) $coLines[] = implode(', ', $addrParts);
if ($company['phone'])   $coLines[] = "Tel: ".$company['phone'];
if ($company['email'])   $coLines[] = $company['email'];
//if ($company['website']) $coLines[] = $company['website'];
if ($company['trn'])     $coLines[] = "TRN: ".$company['trn'];
$coBlock = implode("\n", $coLines);

$clientLines = [];
if ($inv['client_name'])    $clientLines[] = $inv['client_name'];
if (!empty($inv['client_address'])) $clientLines[] = $inv['client_address'];
if (!empty($inv['client_phone']))   $clientLines[] = "Phone: ".$inv['client_phone'];
if (!empty($inv['client_email']))   $clientLines[] = $inv['client_email'];
if (!empty($inv['client_trn']))   $clientLines[] = "TRN: ".$inv['client_trn'];
$clientBlock = implode("\n", $clientLines);

/* ---------- Amount in words ---------- */
function number_to_words_en($num) {
  $num = (int)$num;
  if ($num === 0) return 'zero';
  $ones = ['','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
  $tens = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
  $scales = ['','thousand','million','billion'];
  $parts = [];
  $scale = 0;
  while ($num > 0) {
    $chunk = $num % 1000;
    if ($chunk) {
      $chunk_words = '';
      $hundreds = intdiv($chunk,100);
      $rest = $chunk % 100;
      if ($hundreds) $chunk_words .= $ones[$hundreds].' hundred';
      if ($rest) {
        if ($chunk_words) $chunk_words .= ' ';
        if ($rest < 20) {
          $chunk_words .= $ones[$rest];
        } else {
          $chunk_words .= $tens[intdiv($rest,10)];
          if ($rest % 10) $chunk_words .= '-'.$ones[$rest%10];
        }
      }
      if ($scales[$scale]) $chunk_words .= ' '.$scales[$scale];
      array_unshift($parts, $chunk_words);
    }
    $num = intdiv($num,1000);
    $scale++;
  }
  return implode(' ', $parts);
}
function amount_in_words_aed($amount) {
  $dirhams = floor($amount);
  $fils    = round(($amount - $dirhams) * 100);
  $text = ucfirst(number_to_words_en($dirhams)).' dirham'.($dirhams==1?'':'s');
  if ($fils > 0) $text .= ' and '.number_to_words_en($fils).' fil'.($fils==1?'':'s');
  return $text.' only';
}
$amountWords = amount_in_words_aed($balanceDue > 0 ? $balanceDue : (float)$inv['total']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Tax Invoice <?= h($inv['invoice_no']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --brand-dark: <?= h($template['primary_color']) ?>;
      --brand-accent: <?= h($template['accent_color']) ?>;
      --table-band: <?= h($template['table_stripe_bg']) ?>;
      --text-color: <?= h($template['text_color']) ?>;
      --border-color: <?= h($template['border_color']) ?>;
      --table-header-bg: <?= h($template['table_header_bg']) ?>;
      --table-border: <?= h($template['table_border_color']) ?>;
    }
    body { 
      background: <?= h($template['background_color']) ?>; 
      color: <?= h($template['text_color']) ?>;
      font-family: <?= h($template['font_family']) ?>;
      font-size: 10px;
    }
    .page { 
      max-width: 950px; 
      margin: 15px auto 15px; 
      padding: 10px;
    }
    .logo { 
      max-height: 90px; 
      max-width: 150px; 
      object-fit: contain; 
    }
    .bar-left { 
      background-color: #000 !important; 
      padding: 8px 12px !important; 
      font-size: 11px !important;
      color: #fff !important;
      text-align: left;
      font-weight: 600;
      vertical-align: middle;
      width: 35%;
    }
    .bar-mid { 
      background-color: #FF33B0 !important; 
      padding: 8px 12px !important; 
      text-align: center; 
      font-size: 11px !important;
      color: #fff !important;
      font-weight: 600;
      vertical-align: middle;
      width: 20%;
    }
    .bar-right{ 
      background-color: #000 !important; 
      padding: 8px 12px !important; 
      text-align: right; 
      font-size: 11px !important;
      color: #fff !important;
      font-weight: 600;
      vertical-align: middle;
      width: 45%;
    }
    .title { 
      text-align:center; 
      margin:8px 0 5px; 
      font-weight:700; 
      letter-spacing: .3px; 
      font-size: 20px;
    }
    pre { 
      white-space: pre-wrap; 
      margin:0; 
      font-family: <?= h($template['font_family']) ?>; 
      font-size: 10px;
      line-height: 1.3;
    }
    .panel { 
      border:1px solid var(--border-color); 
      border-radius:6px; 
      padding:8px; 
      font-size: 10px;
    }
    .table thead th { 
      background:rgb(177, 175, 175) !important; 
      padding: 6px 8px !important;
      font-size: 10px;
      font-weight: 600;
      border-bottom: 2px solid rgb(149, 148, 148) !important;
    }
    .table tbody td {
      padding: 4px 8px !important;
      font-size: 10px;
    }
    .table tbody tr:nth-child(even) { 
      background: var(--table-band); 
    }
    .table td, .table th {
      border-color: rgb(149, 148, 148) !important;
    }
    .totals-box { 
      width: 100%; 
      max-width: 300px;
      border:2px solid var(--brand-accent); 
      border-radius:6px; 
      overflow:hidden; 
      font-size: 10px;
      margin-left: auto;
    }
    .totals-box .row { 
      margin:0; 
    }
    .totals-box .cell { 
      padding:6px 10px; 
      border-bottom:1px solid rgba(0,0,0,.05);
      font-size: 10px;
    }
    .totals-box .label { 
      background: var(--brand-accent); 
      color:#fff; 
      font-weight:700;
      font-size: 11px;
    }
    .signature { 
      margin-top: 20px; 
      border-top:1px solid #ced4da; 
      width: 200px; 
    }
    .muted { 
      color:#6c757d; 
    }
    .mb-3 {
      margin-bottom: 0.5rem !important;
    }
    .mt-3 {
      margin-top: 0.5rem !important;
    }
    .mt-5 {
      margin-top: 0.5rem !important;
    }
    /* Layout table support */
    table.layout-table {
      width: 100%;
      border-collapse: collapse;
    }
    table.layout-table td {
      vertical-align: top;
    }
    @media print {
      .no-print { display:none !important; }
      .page { margin:0; padding: 5mm; }
      a[href]:after { content:""; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; margin: 0; padding: 0; }
      @page { margin: 8mm; }
    }
  </style>
</head>
<body>
<div class="page">

  <!-- Header row: logo left + invoice details right -->
  <table style="width: 100%; margin-bottom: 8px; border-collapse: separate;">
    <tr>
      <td style="width: 50%; vertical-align: middle;">
        <table style="border-collapse: collapse;">
          <tr>
            <td style="padding-right: 12px; vertical-align: middle;">
              <?php if ($template['show_logo'] && ($company['logo_data'] || $company['logo_url'])): ?>
                <img src="<?= h($company['logo_data'] ?: $company['logo_url']) ?>" class="logo" alt="Logo">
              <?php endif; ?>
            </td>
            <td style="font-size: 10px; vertical-align: middle;">
              <?php if ($template['show_company_name']): ?>
                <div class="fw-semibold"><?= h($company['legal_name']) ?></div>
              <?php endif; ?>
            </td>
          </tr>
        </table>
      </td>
      <td style="width: 50%; vertical-align: middle; text-align: right; font-size: 10px;">
        <?php if ($template['show_invoice_number']): ?>
          <div><span class="fw-semibold">Invoice #</span> <?= h($inv['invoice_no']) ?></div>
        <?php endif; ?>
        <?php if ($template['show_invoice_date']): ?>
          <div><span class="fw-semibold">Invoice Date</span> <?= h($inv['issue_date']) ?></div>
        <?php endif; ?>
        <?php if ($template['show_due_date'] && $inv['due_date']): ?>
          <div><span class="fw-semibold">Due Date</span> <?= h($inv['due_date']) ?></div>
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <!-- Pink Accent Bar - Single row with all info side-by-side -->
  <table style="width: 100%; margin-bottom: 8px; border-collapse: separate; border-spacing: 0;">
    <tr>
      <td class="bar-left" style="border-radius: 6px 0 0 6px; width: 35%;">Invoice # <?= h($inv['invoice_no']) ?></td>
      <td class="bar-mid" style="width: 20%;">Date</td>
      <td class="bar-right" style="border-radius: 0 6px 6px 0; width: 45%;"><?= h($inv['issue_date']) ?></td>
    </tr>
  </table>

  <!-- Title -->
  <h2 class="title"><?= h($template['invoice_title']) ?></h2>

  <!-- Bill to + Company (side by side using table) -->
  <table style="width: 100%; margin-bottom: 8px;">
    <tr>
      <?php if ($template['show_bill_to']): ?>
      <td style="width: 48%; vertical-align: top; padding-right: 5px;">
        <div class="panel">
          <div class="fw-semibold mb-1" style="font-size: 11px;">Bill to :</div>
          <pre><?= h($clientBlock ?: $inv['client_name']) ?></pre>
        </div>
      </td>
      <?php endif; ?>
      <?php if ($template['show_company_info']): ?>
      <td style="width: 48%; vertical-align: top; padding-left: 5px;">
        <div class="panel">
          <div class="fw-semibold mb-1" style="font-size: 11px;">Company</div>
          <pre><?= h($coBlock) ?></pre>
        </div>
      </td>
      <?php endif; ?>
    </tr>
  </table>

  <!-- Lines -->
  <div class="table-responsive">
    <table class="table align-middle">
      <thead>
        <tr>
          <th style="width:8%">No</th>
          <th>Description</th>
          <th class="text-end" style="width:16%">Price</th>
          <th class="text-end" style="width:12%">Quantity</th>
          <th class="text-end" style="width:16%">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $ix=>$it): ?>
          <tr>
            <td class="fw-semibold"><?= $ix+1 ?></td>
            <td><?= h($it['description']) ?></td>
            <td class="text-end"><?= money($it['unit_price']) ?></td>
            <td class="text-end"><?= rtrim(rtrim(number_format((float)$it['qty'],2,'.',''), '0'), '.') ?></td>
            <td class="text-end"><?= money($it['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Subtotal + VAT + Total / bank + terms -->
  <table style="width: 100%; margin-top: 8px;">
    <tr>
      <td style="width: 55%; vertical-align: top; padding-right: 10px;">
        <?php if ($template['show_bank_details'] && ($company['bank_name'] || $company['bank_acct'] || $company['bank_iban'] || $company['bank_swift'])): ?>
          <div style="margin-bottom: 6px;">
            <div class="fw-semibold" style="color:<?= h($template['accent_color']) ?>; font-size: 10px;">Bank Name: <?= h($company['bank_name']) ?></div>
            
            <div style="font-size: 9px;"><?php if ($company['bank_acct']): ?>Account No: <?= h($company['bank_acct']) ?><?php endif; ?></div>
            <div style="font-size: 9px;"><?php if ($company['bank_iban']): ?>IBAN: <?= h($company['bank_iban']) ?><?php endif; ?></div>
            <div style="font-size: 9px;"><?php if ($company['bank_swift']): ?>Swift Code: <?= h($company['bank_swift']) ?><?php endif; ?></div>
          </div>
        <?php endif; ?>

        <?php if ($template['show_terms'] && $company['terms']): ?>
          <div style="margin-top: 6px;">
            <div class="fw-semibold" style="color:<?= h($template['accent_color']) ?>; font-size: 10px;">Terms &amp; Conditions</div>
            <div style="font-size: 9px;"><?= nl2br(h($company['terms'])) ?></div>
          </div>
        <?php endif; ?>
      </td>
      <td style="width: 45%; vertical-align: top; padding-left: 10px;">
        <div class="totals-box">
          <div style="display: flex; border-bottom: 1px solid rgba(0,0,0,.05);">
            <div style="width: 60%; padding: 6px 10px;">Sub Total</div>
            <div style="width: 40%; padding: 6px 10px; text-align: right;"><?= money($inv['subtotal']) ?></div>
          </div>
          <div style="display: flex; border-bottom: 1px solid rgba(0,0,0,.05);">
            <div style="width: 60%; padding: 6px 10px;">Discount</div>
            <div style="width: 40%; padding: 6px 10px; text-align: right;"><?= money($inv['discount_amount']) ?></div>
          </div>
          <div style="display: flex; border-bottom: 1px solid rgba(0,0,0,.05); background: var(--brand-accent); color: #fff;">
            <div style="width: 60%; padding: 6px 10px; font-weight: 700;">VAT</div>
            <div style="width: 40%; padding: 6px 10px; text-align: right; font-weight: 700;"><?= number_format((float)$inv['vat_rate'],2) ?>%</div>
          </div>
          <div style="display: flex; border-bottom: 1px solid rgba(0,0,0,.05);">
            <div style="width: 60%; padding: 6px 10px;">VAT Amount</div>
            <div style="width: 40%; padding: 6px 10px; text-align: right;"><?= money($inv['vat_amount']) ?></div>
          </div>
          <div style="display: flex; border-bottom: 1px solid rgba(0,0,0,.05);">
            <div style="width: 60%; padding: 6px 10px; font-weight: 600;">Total</div>
            <div style="width: 40%; padding: 6px 10px; text-align: right; font-weight: 600;"><?= money($inv['total']) ?></div>
          </div>
          <?php if ($amountPaid > 0): ?>
          <div style="display: flex; border-bottom: 1px solid rgba(0,0,0,.05); color: #198754;">
            <div style="width: 60%; padding: 6px 10px; font-weight: 600;">Payments / Credits Applied</div>
            <div style="width: 40%; padding: 6px 10px; text-align: right; font-weight: 600;">-<?= money($amountPaid) ?></div>
          </div>
          <div style="display: flex; background: var(--brand-accent); color: #fff;">
            <div style="width: 60%; padding: 6px 10px; font-weight: 700;">Balance Due</div>
            <div style="width: 40%; padding: 6px 10px; text-align: right; font-weight: 700;"><?= money($balanceDue) ?></div>
          </div>
          <?php else: ?>
          <div style="display: flex; background: var(--brand-accent); color: #fff;">
            <div style="width: 60%; padding: 6px 10px; font-weight: 700;">Amount Due</div>
            <div style="width: 40%; padding: 6px 10px; text-align: right; font-weight: 700;"><?= money($balanceDue) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  </table>

  <!-- Amount in words -->
  <?php if ($template['show_amount_in_words']): ?>
  <div style="margin-top: 8px; font-size: 10px;"><strong>Amount in words:</strong> <?= h(ucfirst($amountWords)) ?></div>
  <?php endif; ?>

  <!-- Footer thank you + signature -->
  <table style="width: 100%; margin-top: 8px; border-collapse: collapse;">
    <tr>
      <td style="width: 50%; vertical-align: bottom; font-size: 10px; color: #6c757d;">
        <?= h($template['footer_text']) ?>
      </td>
      <?php if ($template['show_signature']): ?>
      <td style="width: 50%; vertical-align: bottom; text-align: right;">
        <?php 
        $signatureDataUri = null;
        if (!empty($template['signature_path'])) {
          $sigPath = realpath(__DIR__.'/../'.$template['signature_path']);
          if ($sigPath && file_exists($sigPath)) {
            $sigExt = strtolower(pathinfo($sigPath, PATHINFO_EXTENSION));
            $sigContent = file_get_contents($sigPath);
            $signatureDataUri = 'data:image/'.$sigExt.';base64,'.base64_encode($sigContent);
          }
        }
        ?>
        <?php if ($signatureDataUri): ?>
          <img src="<?= h($signatureDataUri) ?>" alt="Signature" style="max-width: 120px; max-height: 40px; object-fit: contain;">
          <br>
          <small style="font-size: 9px;">Signature</small>
        <?php else: ?>
          <div class="signature"></div>
          <br>
          <small style="font-size: 9px;">Signature</small>
        <?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
  </table>

</div>
</body>
</html>
