<?php
// operation/ajax_get_client.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  echo json_encode(['success' => false, 'error' => 'Missing or invalid client ID.']);
  exit;
}

try {
  // 1) Fetch client row (all useful columns)
  $st = $conn->prepare("
    SELECT
      id,
      client_name,
      email,
      mobile_num,
      cell_num,
      rate,
      payment,          -- legacy payment type (D/W/Bi-W/M)
      terms,            -- AR terms: cash, prepaid, 15d, 30d, ...
      credit_limit,
      is_active,
      address,
      key_le,
      trn,
      default_vat_rate,
      balance
    FROM client
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$id]);
  $client = $st->fetch(PDO::FETCH_ASSOC);

  if (!$client) {
    echo json_encode(['success' => false, 'error' => 'Client not found.']);
    exit;
  }

  // 2) Try to pull AR snapshot from a view if available (best)
  $ar = [
    'currency'       => 'AED',
    'open_balance'   => 0.00,  // outstanding invoices
    'total_invoiced' => 0.00,
    'amount_paid'    => 0.00,
  ];

  try {
    // If your DB has this view (we used it elsewhere), prefer it.
    $q = $conn->prepare("SELECT outstanding, total_invoiced, amount_paid, currency
                           FROM v_client_ar_balance
                          WHERE client_id = ?");
    $q->execute([$id]);
    if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
      $ar['open_balance']   = (float)($row['outstanding'] ?? 0);
      $ar['total_invoiced'] = (float)($row['total_invoiced'] ?? 0);
      $ar['amount_paid']    = (float)($row['amount_paid'] ?? 0);
      if (!empty($row['currency'])) $ar['currency'] = $row['currency'];
    } else {
      // Fallback compute if the view returns no row
      throw new RuntimeException('No AR view row');
    }
  } catch (Throwable $e) {
    // 3) Fallback: compute AR using invoices/receipts tables
    // Total invoiced for this client
    $ti = $conn->prepare("
      SELECT COALESCE(SUM(i.total),0)
        FROM invoices i
       WHERE i.client_id = ?
    ");
    $ti->execute([$id]);
    $ar['total_invoiced'] = (float)$ti->fetchColumn();

    // Amount paid via receipt allocations
    $tp = $conn->prepare("
      SELECT COALESCE(SUM(ra.amount_applied),0)
        FROM receipt_allocations ra
        JOIN invoices i ON i.id = ra.invoice_id
       WHERE i.client_id = ?
    ");
    $tp->execute([$id]);
    $ar['amount_paid'] = (float)$tp->fetchColumn();

    $ar['open_balance'] = max(0, $ar['total_invoiced'] - $ar['amount_paid']);
  }

  echo json_encode([
    'success' => true,
    'client'  => [
      'id'               => (int)$client['id'],
      'client_name'      => $client['client_name'],
      'email'            => $client['email'],
      'mobile_num'       => $client['mobile_num'],
      'cell_num'         => $client['cell_num'],
      'rate'             => (string)$client['rate'],
      'payment'          => $client['payment'],         // legacy payment code
      'terms'            => $client['terms'],           // AR terms for invoices
      'credit_limit'     => (string)$client['credit_limit'],
      'is_active'        => (int)$client['is_active'],
      'address'          => $client['address'],
      'key_le'           => $client['key_le'],
      'trn'              => $client['trn'],
      'default_vat_rate' => (string)$client['default_vat_rate'],
      'balance'          => (string)$client['balance'],
    ],
    'ar' => [
      'currency'       => $ar['currency'],
      'open_balance'   => round($ar['open_balance'], 2),
      'total_invoiced' => round($ar['total_invoiced'], 2),
      'amount_paid'    => round($ar['amount_paid'], 2),
    ]
  ]);

} catch (Throwable $e) {
  echo json_encode(['success' => false, 'error' => 'Server error fetching client.']);
}
