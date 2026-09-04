<?php
// accounts/invoice_print.php — Styled TAX INVOICE with amount in words
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_role(['Owner','Admin','Account']);

$invoice_id = (int)($_GET['id'] ?? 0);
if ($invoice_id <= 0) { http_response_code(404); echo "Invoice not found."; exit; }

/* ---------- Company (from company_settings) ---------- */
$co = $conn->query("SELECT * FROM company_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$logoUrl = null;
if (!empty($co['logo_path'])) {
  $p = trim($co['logo_path']);
  $logoUrl = preg_match('~^https?://~i', $p) ? $p : '../'.ltrim($p,'/');
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
      font-size: <?= h($template['font_size_base']) ?>;
    }
    .page { 
      max-width: 1000px; 
      margin: <?= h($template['page_margin']) ?> auto 64px; 
    }
    .logo { 
      max-height: 90px; 
      max-width: 290px; 
      object-fit: contain; 
    }
    .bar-top { 
      display:flex; 
      justify-content:space-between; 
      align-items:center; 
      color:#fff; 
      margin-top: <?= h($template['section_spacing']) ?>; 
    }
    .bar-left { 
      background: var(--brand-dark); 
      padding:10px 16px; 
      border-radius:6px 0 0 6px; 
      min-width: 280px; 
    }
    .bar-mid { 
      background: var(--brand-accent); 
      padding:10px 16px; 
      flex:1; 
      text-align:center; 
    }
    .bar-right{ 
      background: var(--brand-dark); 
      padding:10px 16px; 
      border-radius:0 6px 6px 0; 
      min-width: 220px; 
      text-align:right; 
    }
    .title { 
      text-align:center; 
      margin:24px 0 10px; 
      font-weight:700; 
      letter-spacing: .5px; 
      font-size: <?= h($template['font_size_title']) ?>;
    }
    pre { 
      white-space: pre-wrap; 
      margin:0; 
      font-family: <?= h($template['font_family']) ?>; 
    }
    .panel { 
      border:1px solid var(--border-color); 
      border-radius:10px; 
      padding:16px; 
    }
    .table thead th { 
      background: var(--table-header-bg); 
    }
    .table tbody tr:nth-child(even) { 
      background: var(--table-band); 
    }
    .table td, .table th {
      border-color: var(--table-border);
    }
    .totals-box { 
      width: 320px; 
      border:2px solid var(--brand-accent); 
      border-radius:10px; 
      overflow:hidden; 
    }
    .totals-box .row { 
      margin:0; 
    }
    .totals-box .cell { 
      padding:12px 14px; 
      border-bottom:1px solid rgba(0,0,0,.05); 
    }
    .totals-box .label { 
      background: var(--brand-accent); 
      color:#fff; 
      font-weight:700; 
    }
    .signature { 
      margin-top: 60px; 
      border-top:1px solid #ced4da; 
      width: 280px; 
    }
    .muted { 
      color:#6c757d; 
    }
    @media print {
      .no-print { display:none !important; }
      .page { margin:0; }
      a[href]:after { content:""; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>
<div class="page">

  <!-- Header row: logo + company meta -->
  <div class="d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
      <?php if ($template['show_logo'] && $company['logo_url']): ?>
        <img src="<?= h($company['logo_url']) ?>" class="logo" alt="Logo">
      <?php endif; ?>
      <div class="small">
        <?php if ($template['show_company_name']): ?>
          <div class="fw-semibold"><?= h($company['legal_name']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="text-end small">
      <?php if ($template['show_invoice_number']): ?>
        <div><span class="fw-semibold">Invoice #</span> <?= h($inv['invoice_no']) ?></div>
      <?php endif; ?>
      <?php if ($template['show_invoice_date']): ?>
        <div><span class="fw-semibold">Invoice Date</span> <?= h($inv['issue_date']) ?></div>
      <?php endif; ?>
      <?php if ($template['show_due_date'] && $inv['due_date']): ?>
        <div><span class="fw-semibold">Due Date</span> <?= h($inv['due_date']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Accent bars (like sample) -->
  <div class="bar-top">
    <div class="bar-left">Invoice # <span class="fw-semibold"> <?= h($inv['invoice_no']) ?></span></div>
    <div class="bar-mid">Date</div>
    <div class="bar-right"><?= h($inv['issue_date']) ?></div>
  </div>

  <!-- Title -->
  <h2 class="title"><?= h($template['invoice_title']) ?></h2>

  <!-- Bill to + Company (right) -->
  <div class="row g-3 mb-3">
    <?php if ($template['show_bill_to']): ?>
    <div class="col-md-6">
      <div class="panel">
        <div class="fw-semibold mb-2">Bill to :</div>
        <pre><?= h($clientBlock ?: $inv['client_name']) ?></pre>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($template['show_company_info']): ?>
    <div class="col-md-6">
      <div class="panel">
        <div class="fw-semibold mb-2">Company</div>
        <pre><?= h($coBlock) ?></pre>
      </div>
    </div>
    <?php endif; ?>
  </div>

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
          <?php $lineTotal = isset($it['line_total']) ? (float)$it['line_total'] : ((float)$it['unit_price'] * (float)$it['qty']); ?>
          <tr>
            <td class="fw-semibold"><?= $ix+1 ?></td>
            <td><?= h($it['description']) ?></td>
            <td class="text-end"><?= money($it['unit_price']) ?></td>
            <td class="text-end"><?= rtrim(rtrim(number_format((float)$it['qty'],2,'.',''), '0'), '.') ?></td>
            <td class="text-end"><?= money($lineTotal) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Subtotal + VAT + Total / bank + terms -->
  <div class="row mt-3 align-items-start">
    <div class="col-md-6">
      <?php if ($template['show_bank_details'] && ($company['bank_name'] || $company['bank_acct'] || $company['bank_iban'] || $company['bank_swift'])): ?>
        <div class="mb-3">
          <div class="fw-semibold" style="color:<?= h($template['accent_color']) ?>">Bank Name: <?= h($company['bank_name']) ?></div>
          
          <?php if ($company['bank_acct']): ?><div>Account No: <?= h($company['bank_acct']) ?></div><?php endif; ?>
          <?php if ($company['bank_iban']): ?><div>IBAN: <?= h($company['bank_iban']) ?></div><?php endif; ?>
          <?php if ($company['bank_swift']): ?><div>Swift Code: <?= h($company['bank_swift']) ?></div><?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($template['show_terms'] && $company['terms']): ?>
        <div class="mt-2">
          <div class="fw-semibold" style="color:<?= h($template['accent_color']) ?>">Terms &amp; Conditions</div>
          <div><?= nl2br(h($company['terms'])) ?></div>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-md-6 d-flex justify-content-end">
      <div class="totals-box">
        <div class="row">
          <div class="col-6 cell">Sub Total</div>
          <div class="col-6 cell text-end"><?= money($inv['subtotal']) ?></div>
        </div>
        <div class="row">
          <div class="col-6 cell">Discount</div>
          <div class="col-6 cell text-end"><?= money($inv['discount_amount']) ?></div>
        </div>
        <div class="row">
          <div class="col-6 cell label">VAT</div>
          <div class="col-6 cell label text-end"><?= number_format((float)$inv['vat_rate'],2) ?>%</div>
        </div>
        <div class="row">
          <div class="col-6 cell">VAT Amount</div>
          <div class="col-6 cell text-end"><?= money($inv['vat_amount']) ?></div>
        </div>
        <div class="row">
          <div class="col-6 cell">Total</div>
          <div class="col-6 cell text-end"><?= money($inv['total']) ?></div>
        </div>
        <?php if ($amountPaid > 0): ?>
        <div class="row">
          <div class="col-6 cell text-success">Payments / Credits Applied</div>
          <div class="col-6 cell text-end text-success">-<?= money($amountPaid) ?></div>
        </div>
        <div class="row">
          <div class="col-6 cell label">Balance Due</div>
          <div class="col-6 cell label text-end"><?= money($balanceDue) ?></div>
        </div>
        <?php else: ?>
        <div class="row">
          <div class="col-6 cell label">Amount Due</div>
          <div class="col-6 cell label text-end"><?= money($balanceDue) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Amount in words -->
  <?php if ($template['show_amount_in_words']): ?>
  <div class="mt-3"><strong>Amount in words:</strong> <?= h(ucfirst($amountWords)) ?></div>
  <?php endif; ?>

  <!-- Footer thank you + signature -->
  <div class="d-flex justify-content-between align-items-end mt-5">
    <div class="text-muted"><?= h($template['footer_text']) ?></div>
    <?php if ($template['show_signature']): ?>
    <div class="text-center">
      <?php if (!empty($template['signature_path'])): ?>
        <img src="../<?= h($template['signature_path']) ?>" alt="Signature" style="max-width: 200px; max-height: 80px; object-fit: contain;">
        <br>
        <small>Signature</small>
      <?php else: ?>
        <div class="signature"></div>
        <br>
        <small>Signature</small>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="mt-3 no-print">
    <button class="btn btn-outline-secondary" onclick="history.back()">&laquo; Back</button>
    <button class="btn btn-primary" onclick="window.print()">Print</button>
  </div>

</div>
</body>
</html>
