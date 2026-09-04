<?php
// operation/ajax_client_ar.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/ar_helpers.php'; // <-- use AR helpers already used elsewhere

header('Content-Type: application/json; charset=utf-8');

$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
if ($clientId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Missing client_id']);
  exit;
}

try {
  // 1) Credit limit from client
  $st = $conn->prepare("SELECT COALESCE(credit_limit,0) FROM client WHERE id = ?");
  $st->execute([$clientId]);
  $creditLimit = (float)($st->fetchColumn() ?: 0.0);

  // 2) Outstanding = sum of OPEN invoice balances (not total face values)
  //    We use the same helpers your backend uses so numbers match everywhere.
  //    ar_client_open_invoices() should return rows with a 'balance' field.
  $open = ar_client_open_invoices($conn, $clientId);
  $rawOutstanding = 0.0;
  foreach ($open as $r) {
    $rawOutstanding += (float)($r['balance'] ?? 0);
  }

  // 2b) Add UNINVOICED CONFIRMED/COMPLETED ORDERS to outstanding
  //     For clients with weekly/bi-weekly/monthly terms, orders accumulate before invoicing
  //     We need to track this for credit control purposes
  $pendingSql = "
    SELECT COALESCE(SUM(grand_total), 0) as pending_total
    FROM make_order
    WHERE client_id = ?
    AND status IN ('confirmed', 'completed')
    AND (invoice_id IS NULL OR invoice_id = 0)
  ";
  $pendingStmt = $conn->prepare($pendingSql);
  $pendingStmt->execute([$clientId]);
  $pendingInvoicing = (float)($pendingStmt->fetchColumn() ?: 0.0);

  // Total outstanding for credit control = invoiced + pending invoicing
  $rawOutstanding += $pendingInvoicing;

  // 3) Unapplied = total unapplied receipts/credits for the client
  $unapplied = (float)ar_client_available_credit($conn, $clientId);

  // 4) Available = limit - outstanding + unapplied
  $available = round($creditLimit - $rawOutstanding + $unapplied, 2);

  // IMPORTANT for backward-compat with existing JS:
  // Your front-end currently computes: projected = outstanding + order_total
  // and compares that against credit_limit.
  //
  // To make that logic equivalent to checking order_total against "available",
  // we return a NET outstanding (outstanding - unapplied).
  // Then: projected = (outstanding - unapplied) + order_total
  // Compare to credit_limit  <=>  order_total > credit_limit - outstanding + unapplied = available
  $netOutstanding = max(0.0, $rawOutstanding - $unapplied);

  echo json_encode([
    'ok'              => true,
    // keeps existing fields used by UI
    'credit_limit'    => round($creditLimit, 2),
    'outstanding'     => round($netOutstanding, 2),  // <-- backward-compatible "net" outstanding
    'currency'        => 'AED',
    // extra, harmless fields (useful if you later want to show them)
    'raw_outstanding' => round($rawOutstanding, 2),
    'unapplied'       => round($unapplied, 2),
    'available'       => $available,
    'pending_invoicing' => round($pendingInvoicing, 2),  // NEW: uninvoiced confirmed/completed orders
    'invoiced_balance' => round($rawOutstanding - $pendingInvoicing, 2),  // Breakdown: just invoices
  ]);
} catch (Throwable $e) {
  // Avoid leaking details
  echo json_encode(['ok' => false, 'error' => 'Server error']);
}
