<?php
// accounts/receipt_view.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/ar_helpers.php'; // h(), money(), single_val()

$rid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($rid <= 0) { http_response_code(404); echo "Receipt not found."; exit; }

/* -----------------------------------------------------------
   Company settings (for header + logo)
----------------------------------------------------------- */
$co = $conn->query("SELECT * FROM company_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

// Build a logo URL the browser can load
$logoUrl = null;
if (!empty($co['logo_path'])) {
    $p = trim($co['logo_path']);
    $logoUrl = preg_match('~^https?://~i', $p) ? $p : '../' . ltrim($p, '/');
}

$company = [
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
];

/* -----------------------------------------------------------
   Load receipt + client
----------------------------------------------------------- */
$sql = "
  SELECT r.*, c.client_name, c.email, c.mobile_num, c.address
  FROM receipts r
  LEFT JOIN client c ON c.id = r.client_id
  WHERE r.id = ?
";
$st = $conn->prepare($sql);
$st->execute([$rid]);
$receipt = $st->fetch(PDO::FETCH_ASSOC);
if (!$receipt) { http_response_code(404); echo "Receipt not found."; exit; }

$depositDisplay = '';
if (!empty($receipt['deposit_account_no'])) {
    $dn = $conn->prepare("SELECT name FROM chart_of_accounts WHERE account_no = ? LIMIT 1");
    $dn->execute([$receipt['deposit_account_no']]);
    $nm = $dn->fetchColumn();
    $depositDisplay = $receipt['deposit_account_no'] . ($nm ? ' – ' . $nm : '');
} else {
    $m = strtolower((string)($receipt['method'] ?? ''));
    $depositDisplay = 'Legacy (inferred ' . ($m === 'cash' ? '1010' : '1020') . ')';
}

/* -----------------------------------------------------------
   Allocations for this receipt (receipt -> invoices)
----------------------------------------------------------- */
$alloc = $conn->prepare("
  SELECT ra.id,
         ra.amount_applied,
         i.id                AS invoice_id,
         i.invoice_no,
         DATE(i.issue_date)  AS invoice_date,
         i.total             AS invoice_total
  FROM receipt_allocations ra
  LEFT JOIN invoices i ON i.id = ra.invoice_id
  WHERE ra.receipt_id = ?
  ORDER BY ra.id ASC
");
$alloc->execute([$rid]);
$allocations = $alloc->fetchAll(PDO::FETCH_ASSOC);

// Sum allocated for this receipt
$allocated = 0.0;
foreach ($allocations as $a) $allocated += (float)$a['amount_applied'];
$unallocated = max(0, (float)$receipt['amount'] - $allocated);

// Currency
$currency = $company['currency'] ?: 'AED';

/* -----------------------------------------------------------
   Compute "Balance Remaining" per invoice (overall),
   not just relative to this receipt.
----------------------------------------------------------- */
$balanceMap = []; // invoice_id => remaining balance
if ($allocations) {
    $invoiceIds = array_column($allocations, 'invoice_id');
    $invoiceIds = array_values(array_unique(array_filter($invoiceIds)));

    if ($invoiceIds) {
        // Build IN (?, ?, ...)
        $ph = implode(',', array_fill(0, count($invoiceIds), '?'));

        // Fetch total applied to each invoice across ALL receipts
        $stBal = $conn->prepare("
          SELECT ra.invoice_id, COALESCE(SUM(ra.amount_applied),0) AS applied_total
          FROM receipt_allocations ra
          WHERE ra.invoice_id IN ($ph)
          GROUP BY ra.invoice_id
        ");
        $stBal->execute($invoiceIds);
        $appliedTotals = $stBal->fetchAll(PDO::FETCH_KEY_PAIR); // invoice_id => applied_total

        // Build balance map using invoice totals from our allocations list
        $totalsByInvoice = [];
        foreach ($allocations as $a) {
            $totalsByInvoice[$a['invoice_id']] = (float)$a['invoice_total'];
        }

        foreach ($invoiceIds as $iid) {
            $invTotal   = $totalsByInvoice[$iid] ?? 0.0;
            $appliedAll = (float)($appliedTotals[$iid] ?? 0.0);
            $balanceMap[$iid] = max(0, round($invTotal - $appliedAll, 2));
        }
    }
}

/* -----------------------------------------------------------
   Pretty text blocks
----------------------------------------------------------- */
// Client block
$clientLines = [];
if (!empty($receipt['client_name'])) $clientLines[] = $receipt['client_name'];
if (!empty($receipt['address']))     $clientLines[] = $receipt['address'];
if (!empty($receipt['mobile_num']))  $clientLines[] = "Phone: " . $receipt['mobile_num'];
if (!empty($receipt['email']))       $clientLines[] = $receipt['email'];
$clientBlock = implode("\n", $clientLines);

// Company block (legal name only, as requested)
$coLines = [];
if (!empty($company['legal_name'])) $coLines[] = $company['legal_name'];
$addrParts = array_filter([
    $company['address1'],
    $company['address2'],
    $company['state'],
    $company['country'],
]);
if ($addrParts)                     $coLines[] = implode(', ', $addrParts);
if (!empty($company['phone']))      $coLines[] = $company['phone'];
if (!empty($company['email']))      $coLines[] = $company['email'];
//if (!empty($company['website']))    $coLines[] = $company['website'];
if (!empty($company['trn']))        $coLines[] = "TRN: " . $company['trn'];
$coBlock = implode("\n", $coLines);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Receipt <?= h($receipt['receipt_no']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#fff; }
    .page { max-width: 1000px; margin: 28px auto 64px; }
    .print-btn { position: absolute; right: 24px; top: 18px; }
    .header-wrap { display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px; align-items:start; }
    .co-box pre, .client-box pre { white-space:pre-wrap; margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial, 'Noto Sans', 'Liberation Sans', sans-serif; }
    .logo { max-height: 90px; max-width: 250px; object-fit: contain; }
    .hcard { border:1px solid #e7e7e7; border-radius:10px; }
    @media print {
      .no-print, .print-btn { display:none !important; }
      .page { margin:0; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      a[href]:after { content: ""; }
    }
    .table tfoot td { font-weight:600; }
    .muted { color:#6c757d; }
  </style>
</head>
<body>
<div class="page position-relative">

  <button class="btn btn-outline-secondary print-btn no-print" onclick="window.print()">Print</button>

  <!-- Top header: Company (left) • Title+No (center) • spacer (right) -->
  <div class="header-wrap mb-4">
    <!-- Left: logo + company (legal only) -->
    <div class="co-box">
      <?php if (!empty($company['logo_url'])): ?>
        <img src="<?= h($company['logo_url']) ?>" alt="Logo" class="logo mb-2">
      <?php endif; ?>
      <pre class="mb-0"><?= h($coBlock) ?></pre>
    </div>

    <!-- Center: Receipt title + number -->
    <div class="text-center">
      <h2 class="mb-1">Receipt</h2>
      <div class="muted"># <?= h($receipt['receipt_no']) ?></div>
    </div>

    <!-- Right: spacer to balance the grid -->
    <div></div>
  </div>

  <!-- Cards row -->
  <div class="row g-3 mb-4">
    <div class="col-md-7">
      <div class="p-3 hcard">
        <div class="fw-semibold mb-2">Received From</div>
        <pre class="mb-0 client-box"><?= h($clientBlock) ?></pre>
      </div>
    </div>
    <div class="col-md-5">
      <div class="p-3 hcard">
        <div class="row">
          <div class="col-5 muted">Date</div>
          <div class="col-7 text-end"><?= h($receipt['receipt_date']) ?></div>
        </div>
        <div class="row mt-1">
          <div class="col-5 muted">Method</div>
          <div class="col-7 text-end"><?= h(ucfirst($receipt['method'] ?? '')) ?></div>
        </div>
        <div class="row mt-1">
          <div class="col-5 muted">Deposit a/c</div>
          <div class="col-7 text-end small"><?= h($depositDisplay) ?></div>
        </div>
        <hr class="my-2">
        <div class="row">
          <div class="col-6 fw-semibold">Total Received</div>
          <div class="col-6 text-end fw-semibold"><?= h($currency) ?> <?= money($receipt['amount']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Allocations -->
  <div class="hcard p-0">
    <table class="table mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:26%">Invoice</th>
          <th style="width:16%">Date</th>
          <th class="text-end" style="width:19%">Invoice Total</th>
          <th class="text-end" style="width:19%">Amount Applied</th>
          <th class="text-end" style="width:20%">Balance Remaining</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($allocations): foreach ($allocations as $a):
              $remain = $balanceMap[$a['invoice_id']] ?? max(0, (float)$a['invoice_total'] - (float)$a['amount_applied']);
        ?>
          <tr>
            <td><?= h($a['invoice_no']) ?></td>
            <td><?= h($a['invoice_date']) ?></td>
            <td class="text-end"><?= h($currency) ?> <?= money($a['invoice_total']) ?></td>
            <td class="text-end"><?= h($currency) ?> <?= money($a['amount_applied']) ?></td>
            <td class="text-end"><?= h($currency) ?> <?= money($remain) ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No allocations yet.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" class="text-end">Allocated Total</td>
          <td class="text-end"><?= h($currency) ?> <?= money($allocated) ?></td>
        </tr>
        <tr>
          <td colspan="4" class="text-end">Unallocated</td>
          <td class="text-end"><?= h($currency) ?> <?= money($unallocated) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="text-center mt-4 text-muted">
    Thank you for your payment.
  </div>

  <div class="mt-3">
    <a href="../account.php?tab=payments" class="btn btn-link no-print">&laquo; Back</a>
  </div>

</div>
</body>
</html>
