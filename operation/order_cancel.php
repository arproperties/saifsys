<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/ar_helpers.php';   // for invoice + GL helpers
require_once __DIR__ . '/../includes/cleaning_order_cancellation_helper.php';
require_once __DIR__ . '/../includes/work_order_financial_guard.php';

cleaning_order_cancel_ensure_schema($conn);

function redirect_with_flash($type, $msg) {
  $qs = http_build_query(['tab'=>'workorder','flashType'=>$type,'flash'=>$msg]);
  header("Location: ../operation.php?$qs");
  exit;
}

$id     = (int)($_POST['id'] ?? 0);
$category = cleaning_order_cancel_normalize_category((string)($_POST['cancellation_category'] ?? ''));
$details = trim((string)($_POST['cancellation_details'] ?? ($_POST['reason'] ?? '')));
$reason = cleaning_order_cancel_summary($category, $details);

if ($id <= 0) redirect_with_flash('error', 'Missing order id.');
if ($category === '' || $details === '') redirect_with_flash('error', 'Cancellation category and details are required.');

try {
  $conn->beginTransaction();

  // Lock the order row
  $st = $conn->prepare("SELECT * FROM make_order WHERE id=? FOR UPDATE");
  $st->execute([$id]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  if (!$o) throw new RuntimeException('Order not found.');
  if (($o['status'] ?? '') === 'cancelled') {
    $conn->commit();
    redirect_with_flash('info', 'Order is already cancelled.');
  }

  [$canCancel, $cancelBlock] = wo_ops_can_direct_cancel($conn, $id);
  if (!$canCancel) {
    throw new RuntimeException($cancelBlock !== '' ? $cancelBlock : 'Direct cancel is not allowed for this work order.');
  }

  // Only non-activity invoices (e.g. draft) may be voided here
  $invSt = $conn->prepare("SELECT * FROM invoices WHERE order_id=? AND status <> 'void' LIMIT 1");
  $invSt->execute([$id]);
  $inv = $invSt->fetch(PDO::FETCH_ASSOC);

  if ($inv) {
    $inv_id = (int)$inv['id'];
    $inv_status = strtolower((string)($inv['status'] ?? ''));

    if (in_array($inv_status, ['issued', 'paid', 'partially_paid'], true)) {
      throw new RuntimeException("Invoice {$inv['invoice_no']} is {$inv_status}. Use Accounts void/credit/refund — not Operations cancel.");
    }

    $allocAmt = (float)$conn->query("SELECT COALESCE(SUM(amount_applied),0) FROM receipt_allocations WHERE invoice_id={$inv_id}")
                            ->fetchColumn();

    if ($allocAmt > 0) {
      throw new RuntimeException("Invoice {$inv['invoice_no']} has payments/allocations. Unallocate/refund first.");
    }

    $u = $conn->prepare("UPDATE invoices SET status='void', updated_at=NOW() WHERE id=?");
    $u->execute([$inv_id]);
    ar_post_or_repost_invoice($conn, $inv_id);
  }

  // Mark order as cancelled and save audit fields
  $upd = $conn->prepare("
    UPDATE make_order
       SET status='cancelled',
           cancel_reason  = :r,
           cancellation_category = :cat,
           cancellation_details = :details,
           cancelled_at   = NOW(),
           cancelled_by   = :u
     WHERE id = :id
  ");
  $upd->execute([
    ':r'  => ($reason !== '' ? $reason : null),
    ':cat' => $category,
    ':details' => $details,
    ':u'  => (int)($_SESSION['user']['id'] ?? 0),
    ':id' => $id,
  ]);

  if (function_exists('wo_sync_ops_status_column')) {
    wo_sync_ops_status_column($conn, $id, 'cancelled');
  }

  require_once __DIR__ . '/../includes/AuditService.php';
  AuditService::logStatusChange('make_order', $id, $o['status'] ?? 'confirmed', 'cancelled',
    "Cancelled order #{$id}" . ($reason ? " - Reason: {$reason}" : ""),
    current_user_id());

  $conn->commit();
  redirect_with_flash('success', "Order #$id cancelled.");
} catch (Throwable $e) {
  if ($conn->inTransaction()) $conn->rollBack();
  redirect_with_flash('error', 'Cancel failed: '.$e->getMessage());
}
