<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$refund_id = (int)($_GET['id'] ?? 0);
if ($refund_id <= 0) {
  header("Location: refunds.php");
  exit;
}

// Load refund data
$stmt = $conn->prepare("
  SELECT r.*, cn.credit_note_number, cn.credit_note_date, cn.total_amount as credit_note_total,
         cn.reason, cn.reason_description,
         c.client_name, c.client_address, c.client_city, c.client_state, c.client_zip, c.client_country,
         c.client_phone, c.client_email, c.client_tax_number,
         cs.company_name, cs.company_address, cs.company_city, cs.company_state, cs.company_zip, cs.company_country,
         cs.company_phone, cs.company_email, cs.company_tax_number, cs.company_logo
  FROM refunds r
  LEFT JOIN credit_notes cn ON r.credit_note_id = cn.id
  LEFT JOIN client c ON cn.client_id = c.id
  LEFT JOIN company_settings cs ON 1=1
  WHERE r.id = ?
");
$stmt->execute([$refund_id]);
$refund = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$refund) {
  header("Location: refunds.php");
  exit;
}

function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
function money($amount) { return number_format($amount, 2); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Refund Receipt <?= h($refund['refund_number']) ?></title>
  <style>
    body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #333; }
    .header { margin-bottom: 30px; }
    .company-info { float: left; width: 50%; }
    .refund-info { float: right; width: 45%; text-align: right; }
    .client-info { margin-bottom: 20px; }
    .refund-details { margin-bottom: 20px; }
    .totals { float: right; width: 300px; }
    .totals table { width: 100%; }
    .totals td { padding: 4px 8px; }
    .totals .total-row { font-weight: bold; border-top: 2px solid #333; }
    .footer { margin-top: 50px; font-size: 10px; color: #666; }
    .clear { clear: both; }
    .status-badge { 
      display: inline-block; 
      padding: 4px 8px; 
      border-radius: 4px; 
      font-size: 10px; 
      font-weight: bold; 
      text-transform: uppercase;
    }
    .status-processed { background-color: #d4edda; color: #155724; }
    .status-pending { background-color: #fff3cd; color: #856404; }
    .status-cancelled { background-color: #f8d7da; color: #721c24; }
    @media print {
      body { margin: 0; }
      .no-print { display: none; }
    }
  </style>
</head>
<body>
  <div class="header">
    <div class="company-info">
      <h2><?= h($refund['company_name'] ?: 'Company Name') ?></h2>
      <?php if ($refund['company_address']): ?>
      <p><?= h($refund['company_address']) ?><br>
      <?= h($refund['company_city']) ?><?= $refund['company_state'] ? ', ' . h($refund['company_state']) : '' ?> <?= h($refund['company_zip']) ?><br>
      <?= h($refund['company_country']) ?></p>
      <?php endif; ?>
      <?php if ($refund['company_phone']): ?>
      <p>Phone: <?= h($refund['company_phone']) ?></p>
      <?php endif; ?>
      <?php if ($refund['company_email']): ?>
      <p>Email: <?= h($refund['company_email']) ?></p>
      <?php endif; ?>
      <?php if ($refund['company_tax_number']): ?>
      <p>Tax Number: <?= h($refund['company_tax_number']) ?></p>
      <?php endif; ?>
    </div>
    
    <div class="refund-info">
      <h1 style="color: #28a745; margin: 0;">REFUND RECEIPT</h1>
      <p><strong>Receipt #:</strong> <?= h($refund['refund_number']) ?></p>
      <p><strong>Date:</strong> <?= date('M d, Y', strtotime($refund['refund_date'])) ?></p>
      <p><strong>Status:</strong> 
        <span class="status-badge status-<?= $refund['status'] ?>">
          <?= strtoupper($refund['status']) ?>
        </span>
      </p>
      <?php if ($refund['refund_reference']): ?>
      <p><strong>Reference:</strong> <?= h($refund['refund_reference']) ?></p>
      <?php endif; ?>
      <?php if ($refund['processed_at']): ?>
      <p><strong>Processed:</strong> <?= date('M d, Y H:i', strtotime($refund['processed_at'])) ?></p>
      <?php endif; ?>
    </div>
    <div class="clear"></div>
  </div>

  <div class="client-info">
    <h3>Refund To:</h3>
    <p><strong><?= h($refund['client_name']) ?></strong></p>
    <?php if ($refund['client_address']): ?>
    <p><?= h($refund['client_address']) ?><br>
    <?= h($refund['client_city']) ?><?= $refund['client_state'] ? ', ' . h($refund['client_state']) : '' ?> <?= h($refund['client_zip']) ?><br>
    <?= h($refund['client_country']) ?></p>
    <?php endif; ?>
    <?php if ($refund['client_phone']): ?>
    <p>Phone: <?= h($refund['client_phone']) ?></p>
    <?php endif; ?>
    <?php if ($refund['client_email']): ?>
    <p>Email: <?= h($refund['client_email']) ?></p>
    <?php endif; ?>
    <?php if ($refund['client_tax_number']): ?>
    <p>Tax Number: <?= h($refund['client_tax_number']) ?></p>
    <?php endif; ?>
  </div>

  <div class="refund-details">
    <h3>Refund Details:</h3>
    <p><strong>Credit Note:</strong> <?= h($refund['credit_note_number']) ?></p>
    <p><strong>Credit Note Date:</strong> <?= date('M d, Y', strtotime($refund['credit_note_date'])) ?></p>
    <p><strong>Credit Note Total:</strong> AED <?= money($refund['credit_note_total']) ?></p>
    <p><strong>Refund Method:</strong> <?= ucfirst(str_replace('_', ' ', $refund['refund_method'])) ?></p>
    <p><strong>Reason:</strong> <?= ucfirst($refund['reason']) ?></p>
    <?php if ($refund['reason_description']): ?>
    <p><strong>Description:</strong> <?= h($refund['reason_description']) ?></p>
    <?php endif; ?>
  </div>

  <div class="totals">
    <table>
      <tr>
        <td>Credit Note Total:</td>
        <td class="text-right">AED <?= money($refund['credit_note_total']) ?></td>
      </tr>
      <tr>
        <td>Refund Amount:</td>
        <td class="text-right"><strong>AED <?= money($refund['refund_amount']) ?></strong></td>
      </tr>
      <tr class="total-row">
        <td><strong>Remaining Credit:</strong></td>
        <td class="text-right"><strong>AED <?= money($refund['credit_note_total'] - $refund['refund_amount']) ?></strong></td>
      </tr>
    </table>
  </div>
  <div class="clear"></div>

  <?php if ($refund['notes']): ?>
  <div class="notes">
    <h3>Notes:</h3>
    <p><?= nl2br(h($refund['notes'])) ?></p>
  </div>
  <?php endif; ?>

  <div class="footer">
    <p>This refund receipt was generated on <?= date('M d, Y H:i') ?> by <?= h($_SESSION['user_name'] ?? 'System') ?></p>
    <p>Status: <?= strtoupper($refund['status']) ?></p>
    <?php if ($refund['status'] === 'processed'): ?>
    <p><strong>This refund has been processed and the amount has been paid.</strong></p>
    <?php endif; ?>
  </div>

  <div class="no-print" style="margin-top: 20px; text-align: center;">
    <button onclick="window.print()" class="btn btn-primary">Print Receipt</button>
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
