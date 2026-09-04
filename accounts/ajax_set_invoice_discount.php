<?php
// accounts/ajax_set_invoice_discount.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';

header('Content-Type: application/json');
require_role(['Owner','Admin','Account'], $conn);

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
$discount   = (float)($_POST['discount_amount'] ?? 0);

try {
  if ($invoice_id <= 0) {
    throw new Exception('Missing or invalid invoice id.');
  }
  if ($discount < 0) {
    throw new Exception('Discount cannot be negative.');
  }

  // Get old invoice data for audit log
  $old_invoice = ar_get_invoice($conn, $invoice_id);
  
  // Save + recalc + repost to GL (handled inside the helper)
  if (!ar_set_invoice_discount($conn, $invoice_id, $discount)) {
    throw new Exception('Failed to save discount.');
  }

  // Audit Log: Track invoice discount change
  require_once __DIR__ . '/../includes/AuditService.php';
  AuditService::logUpdate('invoices', $invoice_id, $old_invoice, [
    'discount_amount' => $discount,
    'subtotal' => $old_invoice['subtotal'] ?? 0,
    'total' => $old_invoice['total'] ?? 0
  ], "Set discount of " . number_format($discount, 2) . " AED on invoice #{$invoice_id}");

  // Return fresh numbers so the UI could update without reload (optional)
  $inv = ar_get_invoice($conn, $invoice_id);
  if (!$inv) {
    echo json_encode(['success'=>true]); // saved ok; view can reload
    exit;
  }

  echo json_encode([
    'success' => true,
    'invoice' => [
      'id'               => (int)$inv['id'],
      'subtotal'         => (float)$inv['subtotal'],
      'discount_amount'  => (float)$inv['discount_amount'],
      'vat_rate'         => (float)$inv['vat_rate'],
      'vat_amount'       => (float)$inv['vat_amount'],
      'total'            => (float)$inv['total'],
      'status'           => (string)$inv['status'],
      'amount_paid'      => (float)$inv['amount_paid'],
      'balance_due'      => (float)$inv['balance_due'],
      'gl_journal_id'    => isset($inv['gl_journal_id']) ? (int)$inv['gl_journal_id'] : null,
      'posted_at'        => $inv['posted_at'] ?? null,
    ]
  ]);
} catch (Throwable $e) {
  echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
