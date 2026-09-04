<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$credit_note_id = (int)($_GET['id'] ?? 0);
if ($credit_note_id <= 0) {
  header("Location: ../account.php?tab=invoices");
  exit;
}

// Load credit note data
$stmt = $conn->prepare("
  SELECT cn.*, c.client_name, c.client_address, c.client_city, c.client_state, c.client_zip, c.client_country,
         c.client_phone, c.client_email, c.client_tax_number,
         i.invoice_no, i.invoice_date,
         cs.company_name, cs.company_address, cs.company_city, cs.company_state, cs.company_zip, cs.company_country,
         cs.company_phone, cs.company_email, cs.company_tax_number, cs.company_logo
  FROM credit_notes cn
  LEFT JOIN client c ON cn.client_id = c.id
  LEFT JOIN invoices i ON cn.invoice_id = i.id
  LEFT JOIN company_settings cs ON 1=1
  WHERE cn.id = ?
");
$stmt->execute([$credit_note_id]);
$credit_note = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$credit_note) {
  header("Location: ../account.php?tab=invoices");
  exit;
}

// Get credit note items
$stmt = $conn->prepare("SELECT * FROM credit_note_items WHERE credit_note_id = ? ORDER BY id");
$stmt->execute([$credit_note_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
function money($amount) { return number_format($amount, 2); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Credit Note <?= h($credit_note['credit_note_number']) ?></title>
  <style>
    body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #333; }
    .header { margin-bottom: 30px; }
    .company-info { float: left; width: 50%; }
    .credit-note-info { float: right; width: 45%; text-align: right; }
    .client-info { margin-bottom: 20px; }
    .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    .items-table th, .items-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .items-table th { background-color: #f5f5f5; font-weight: bold; }
    .items-table .text-right { text-align: right; }
    .totals { float: right; width: 300px; }
    .totals table { width: 100%; }
    .totals td { padding: 4px 8px; }
    .totals .total-row { font-weight: bold; border-top: 2px solid #333; }
    .footer { margin-top: 50px; font-size: 10px; color: #666; }
    .clear { clear: both; }
    @media print {
      body { margin: 0; }
      .no-print { display: none; }
    }
  </style>
</head>
<body>
  <div class="header">
    <div class="company-info">
      <h2><?= h($credit_note['company_name'] ?: 'Company Name') ?></h2>
      <?php if ($credit_note['company_address']): ?>
      <p><?= h($credit_note['company_address']) ?><br>
      <?= h($credit_note['company_city']) ?><?= $credit_note['company_state'] ? ', ' . h($credit_note['company_state']) : '' ?> <?= h($credit_note['company_zip']) ?><br>
      <?= h($credit_note['company_country']) ?></p>
      <?php endif; ?>
      <?php if ($credit_note['company_phone']): ?>
      <p>Phone: <?= h($credit_note['company_phone']) ?></p>
      <?php endif; ?>
      <?php if ($credit_note['company_email']): ?>
      <p>Email: <?= h($credit_note['company_email']) ?></p>
      <?php endif; ?>
      <?php if ($credit_note['company_tax_number']): ?>
      <p>Tax Number: <?= h($credit_note['company_tax_number']) ?></p>
      <?php endif; ?>
    </div>
    
    <div class="credit-note-info">
      <h1 style="color: #dc3545; margin: 0;">CREDIT NOTE</h1>
      <p><strong>Credit Note #:</strong> <?= h($credit_note['credit_note_number']) ?></p>
      <p><strong>Date:</strong> <?= date('M d, Y', strtotime($credit_note['credit_note_date'])) ?></p>
      <?php if ($credit_note['reference']): ?>
      <p><strong>Reference:</strong> <?= h($credit_note['reference']) ?></p>
      <?php endif; ?>
      <?php if ($credit_note['invoice_no']): ?>
      <p><strong>Original Invoice:</strong> <?= h($credit_note['invoice_no']) ?></p>
      <?php endif; ?>
    </div>
    <div class="clear"></div>
  </div>

  <div class="client-info">
    <h3>Bill To:</h3>
    <p><strong><?= h($credit_note['client_name']) ?></strong></p>
    <?php if ($credit_note['client_address']): ?>
    <p><?= h($credit_note['client_address']) ?><br>
    <?= h($credit_note['client_city']) ?><?= $credit_note['client_state'] ? ', ' . h($credit_note['client_state']) : '' ?> <?= h($credit_note['client_zip']) ?><br>
    <?= h($credit_note['client_country']) ?></p>
    <?php endif; ?>
    <?php if ($credit_note['client_phone']): ?>
    <p>Phone: <?= h($credit_note['client_phone']) ?></p>
    <?php endif; ?>
    <?php if ($credit_note['client_email']): ?>
    <p>Email: <?= h($credit_note['client_email']) ?></p>
    <?php endif; ?>
    <?php if ($credit_note['client_tax_number']): ?>
    <p>Tax Number: <?= h($credit_note['client_tax_number']) ?></p>
    <?php endif; ?>
  </div>

  <div class="reason-info">
    <h3>Credit Note Reason:</h3>
    <p><strong>Reason:</strong> <?= ucfirst($credit_note['reason']) ?></p>
    <?php if ($credit_note['reason_description']): ?>
    <p><strong>Description:</strong> <?= h($credit_note['reason_description']) ?></p>
    <?php endif; ?>
  </div>

  <table class="items-table">
    <thead>
      <tr>
        <th>Description</th>
        <th class="text-right">Qty</th>
        <th class="text-right">Unit Price</th>
        <th class="text-right">Tax %</th>
        <th class="text-right">Tax Amount</th>
        <th class="text-right">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr>
        <td><?= h($item['description']) ?></td>
        <td class="text-right"><?= number_format($item['quantity'], 3) ?></td>
        <td class="text-right">AED <?= money($item['unit_price']) ?></td>
        <td class="text-right"><?= money($item['tax_rate']) ?>%</td>
        <td class="text-right">AED <?= money($item['tax_amount']) ?></td>
        <td class="text-right">AED <?= money($item['line_total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="totals">
    <table>
      <tr>
        <td>Subtotal:</td>
        <td class="text-right">AED <?= money($credit_note['subtotal']) ?></td>
      </tr>
      <tr>
        <td>Tax:</td>
        <td class="text-right">AED <?= money($credit_note['tax_amount']) ?></td>
      </tr>
      <tr class="total-row">
        <td><strong>Total Credit:</strong></td>
        <td class="text-right"><strong>AED <?= money($credit_note['total_amount']) ?></strong></td>
      </tr>
    </table>
  </div>
  <div class="clear"></div>

  <?php if ($credit_note['notes']): ?>
  <div class="notes">
    <h3>Notes:</h3>
    <p><?= nl2br(h($credit_note['notes'])) ?></p>
  </div>
  <?php endif; ?>

  <div class="footer">
    <p>This credit note was generated on <?= date('M d, Y H:i') ?> by <?= h($_SESSION['user_name'] ?? 'System') ?></p>
    <p>Status: <?= strtoupper($credit_note['status']) ?></p>
  </div>

  <div class="no-print" style="margin-top: 20px; text-align: center;">
    <button onclick="window.print()" class="btn btn-primary">Print Credit Note</button>
    <button onclick="window.close()" class="btn btn-secondary">Close</button>
  </div>

  <script>
    // Auto-print when page loads
    window.onload = function() {
      setTimeout(function() {
        window.print();
      }, 500);
    };
  </script>
</body>
</html>
