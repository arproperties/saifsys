<?php
// operation/ajax_client_details.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money2($n){ return number_format((float)$n, 2); }

// ---- Inputs (support old and new names)
$client_id = (int)($_GET['client_id'] ?? 0);
$from      = $_GET['from']      ?? ($_GET['date_from'] ?? '');
$to        = $_GET['to']        ?? ($_GET['date_to']   ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$page_size = 20;
$offset    = ($page - 1) * $page_size;

// If no client selected
if (!$client_id) {
  echo '<div class="p-5 text-center text-muted"><i class="bi bi-person-circle" style="font-size:64px"></i><div class="mt-2">Select a client to view details.</div></div>';
  exit;
}

// ---- Load client
$st = $conn->prepare("SELECT * FROM client WHERE id=?");
$st->execute([$client_id]);
$client = $st->fetch(PDO::FETCH_ASSOC);

if (!$client) {
  echo '<div class="p-5 text-center text-muted"><i class="bi bi-person-x" style="font-size:64px"></i><div class="mt-2">Client not found.</div></div>';
  exit;
}

/* -----------------------------------------------------------
   ORDERS (joined to invoices if any) + totals + pagination
----------------------------------------------------------- */
$wO = []; $aO = [];
$wO[] = "mo.client_id = ?"; $aO[] = $client_id;
if ($from !== '') { $wO[] = "mo.service_date >= ?"; $aO[] = $from; }
if ($to   !== '') { $wO[] = "mo.service_date <= ?"; $aO[] = $to; }
$whereOrders = 'WHERE '.implode(' AND ', $wO);

// totals (not paginated)
$sumHours = 0.0; $sumOrdersValue = 0.0;
$sql = "
  SELECT
    COALESCE(SUM(mo.hours),0) AS sh,
    COALESCE(SUM(COALESCE(i.total, COALESCE(mo.grand_total, mo.total))),0) AS sv
  FROM make_order mo
  LEFT JOIN invoices i ON i.order_id = mo.id
  $whereOrders
";
$st = $conn->prepare($sql); $st->execute($aO);
$rt = $st->fetch(PDO::FETCH_ASSOC);
$sumHours       = (float)($rt['sh'] ?? 0);
$sumOrdersValue = (float)($rt['sv'] ?? 0);

// count for pagination
$st = $conn->prepare("SELECT COUNT(*) FROM make_order mo $whereOrders");
$st->execute($aO);
$total_rows  = (int)$st->fetchColumn();
$total_pages = (int)ceil($total_rows / $page_size);

// fetch page
$sql = "
  SELECT mo.*,
         i.id AS invoice_id, i.invoice_no, i.status AS invoice_status, i.total AS invoice_total
  FROM make_order mo
  LEFT JOIN invoices i ON i.order_id = mo.id
  $whereOrders
  ORDER BY mo.service_date DESC, mo.id DESC
  LIMIT $page_size OFFSET $offset
";
$st = $conn->prepare($sql); $st->execute($aO);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------------------------------------
   INVOICES (with paid/balance)
----------------------------------------------------------- */
$wI=[]; $aI=[];
$wI[]="i.client_id=?"; $aI[]=$client_id;
if ($from!==''){ $wI[]="i.issue_date>=?"; $aI[]=$from; }
if ($to  !==''){ $wI[]="i.issue_date<=?"; $aI[]=$to; }
$whereInv = 'WHERE '.implode(' AND ',$wI);

$sql = "
  SELECT i.*,
         COALESCE(SUM(ra.amount_applied),0) AS paid,
         (i.total-COALESCE(SUM(ra.amount_applied),0)) AS balance
  FROM invoices i
  LEFT JOIN receipt_allocations ra ON ra.invoice_id=i.id
  $whereInv
  GROUP BY i.id
  ORDER BY i.issue_date DESC, i.id DESC
  LIMIT 500
";
$st=$conn->prepare($sql); $st->execute($aI);
$invoices=$st->fetchAll(PDO::FETCH_ASSOC);

// AR Summary
$sum_total=0.0; $sum_paid=0.0; $sum_open=0.0;
foreach ($invoices as $iv) {
  $sum_total += (float)$iv['total'];
  $sum_paid  += (float)$iv['paid'];
  $sum_open  += max(0, (float)$iv['balance']);
}

/* -----------------------------------------------------------
   PAYMENTS (receipts) with allocations
----------------------------------------------------------- */
$wP=[]; $aP=[];
$wP[]="r.client_id=?"; $aP[]=$client_id;
if ($from!==''){ $wP[]="r.receipt_date>=?"; $aP[]=$from; }
if ($to  !==''){ $wP[]="r.receipt_date<=?"; $aP[]=$to; }
$wherePay = 'WHERE '.implode(' AND ',$wP);

$sql = "
  SELECT r.*,
         GROUP_CONCAT(CONCAT(i.invoice_no,' (',FORMAT(ra.amount_applied,2),')')
                      ORDER BY ra.id SEPARATOR ', ') AS allocations
  FROM receipts r
  LEFT JOIN receipt_allocations ra ON ra.receipt_id=r.id
  LEFT JOIN invoices i ON i.id=ra.invoice_id
  $wherePay
  GROUP BY r.id
  ORDER BY r.receipt_date DESC, r.id DESC
  LIMIT 500
";
$st=$conn->prepare($sql); $st->execute($aP);
$payments=$st->fetchAll(PDO::FETCH_ASSOC);

// last payment
$st=$conn->prepare("SELECT * FROM receipts WHERE client_id=? ORDER BY receipt_date DESC, id DESC LIMIT 1");
$st->execute([$client_id]);
$last_payment=$st->fetch(PDO::FETCH_ASSOC);

?>
<div class="p-3 border-bottom d-flex align-items-center">
  <div>
    <h5 class="mb-0">Client: <strong><?= h($client['client_name']) ?></strong></h5>
    <div class="text-muted small">
      <?= h($client['email'] ?? '-') ?> • <?= h($client['mobile_num'] ?? '-') ?> • TRN: <?= h($client['trn'] ?? '-') ?>
    </div>
  </div>
  <div class="ms-auto d-flex gap-2">
    <a class="btn btn-outline-primary btn-sm" href="operation/order_add.php?client_id=<?= (int)$client_id ?>"><i class="bi bi-briefcase"></i> New Work Order</a>
    <button class="btn btn-outline-secondary btn-sm" onclick="showEditClientModal()"><i class="bi bi-pencil"></i> Edit</button>
    <button class="btn btn-outline-danger btn-sm" onclick="confirmDeleteClient()"><i class="bi bi-trash"></i> Delete</button>
    <button class="btn btn-outline-dark btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>
</div>

<!-- KPIs -->
<div class="p-3">
  <div class="row g-3">
    <div class="col-md-3">
      <div class="kpi">
        <div class="small text-white-50">Open Balance</div>
        <div class="val">AED <?= money2($sum_open) ?></div>
        <div class="small"><?= $sum_open>0?'Outstanding invoices':'No dues' ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="kpi green">
        <div class="small text-white-50">Total Invoiced<?= ($from||$to)?' (range)':'' ?></div>
        <div class="val">AED <?= money2($sum_total) ?></div>
        <div class="small">Paid: AED <?= money2($sum_paid) ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="kpi orange">
        <div class="small text-white-50">Hours (orders<?= ($from||$to)?' in range':'' ?>)</div>
        <div class="val"><?= number_format($sumHours,2) ?></div>
        <div class="small">Value est.: AED <?= money2($sumOrdersValue) ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="kpi muted">
        <div class="small text-white-50">Credit Limit</div>
        <div class="val">AED <?= money2((float)($client['credit_limit'] ?? 0)) ?></div>
        <div class="small">Terms: <?= h($client['terms'] ?? 'cash') ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Date filter (re-uses existing JS hook) -->
<form method="get" class="px-3 pb-3 filter-row" onsubmit="return filterClientOrders(event, <?= (int)$client_id ?>);">
  <input type="hidden" name="client_id" value="<?= (int)$client_id ?>">
  <input type="date" name="date_from" class="form-control" value="<?= h($from) ?>" placeholder="From">
  <span class="mx-1">to</span>
  <input type="date" name="date_to" class="form-control" value="<?= h($to) ?>" placeholder="To">
  <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-funnel"></i> Filter</button>
</form>

<!-- Tabs -->
<ul class="nav nav-tabs px-3" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#cl-orders">Orders</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#cl-invoices">Invoices</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#cl-payments">Payments</button></li>
</ul>

<div class="tab-content p-3">
  <!-- Orders -->
  <div class="tab-pane fade show active" id="cl-orders">
    <?php if ($orders): ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Date</th>
            <th>Time</th>
            <th>Workers</th>
            <th class="text-end">Hours</th>
            <th class="text-end">Value</th>
            <th>Driver</th>
            <th>Invoice</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><?= (int)$o['id'] ?></td>
            <td><?= h($o['service_date'] ?: $o['date']) ?></td>
            <td><?= h($o['time']) ?></td>
            <td><?= h($o['worker_name']) ?></td>
            <td class="text-end"><?= money2($o['hours']) ?></td>
            <td class="text-end">AED <?= money2($o['invoice_total'] ?? ($o['grand_total'] ?? $o['total'] ?? 0)) ?></td>
            <td><?= h($o['driver_name']) ?></td>
            <td>
              <?php if ($o['invoice_id']): ?>
                <a class="btn btn-sm btn-outline-primary" target="_blank" href="accounts/invoice_view.php?id=<?= (int)$o['invoice_id'] ?>">
                  <?= h($o['invoice_no']) ?>
                </a>
                <?php if ($o['invoice_status']): ?>
                  <span class="badge text-bg-<?= ($o['invoice_status']==='paid'?'success':($o['invoice_status']==='partially_paid'?'warning':($o['invoice_status']==='void'?'secondary':'info'))) ?>">
                    <?= h(str_replace('_',' ',$o['invoice_status'])) ?>
                  </span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn btn-sm btn-link" onclick="togglePayments(this, <?= (int)$o['id'] ?>)">
                <i class="bi bi-chevron-down"></i> Details
              </button>
            </td>
          </tr>
          <tr class="payment-row" id="payment-row-<?= (int)$o['id'] ?>" style="display:none;">
            <td colspan="9"><div id="payments-container-<?= (int)$o['id'] ?>"></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($total_pages > 1): ?>
      <nav>
        <ul class="pagination justify-content-center flex-wrap">
          <?php
          $window = 2;
          for ($i=1; $i <= $total_pages; $i++) {
            if ($i==1 || $i==$total_pages || ($i >= $page-$window && $i <= $page+$window)) {
              $active = $i==$page ? ' active' : '';
              echo '<li class="page-item'.$active.'">
                      <a class="page-link" href="#" onclick="gotoOrdersPage(event, '.(int)$client_id.', '.$i.')">'.$i.'</a>
                    </li>';
            } elseif ($i == $page-$window-1 || $i == $page+$window+1) {
              echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
          }
          ?>
        </ul>
      </nav>
    <?php endif; ?>

    <?php else: ?>
      <div class="text-muted">No orders<?= ($from||$to)?' in selected range':'' ?>.</div>
    <?php endif; ?>
  </div>

  <!-- Invoices -->
  <div class="tab-pane fade" id="cl-invoices">
    <?php if ($invoices): ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>Invoice #</th>
            <th>Date</th>
            <th class="text-end">Total</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($invoices as $iv): ?>
          <tr>
            <td><?= h($iv['invoice_no']) ?></td>
            <td><?= h($iv['issue_date']) ?></td>
            <td class="text-end"><?= money2($iv['total']) ?></td>
            <td class="text-end"><?= money2($iv['paid']) ?></td>
            <td class="text-end"><?= money2(max(0,$iv['balance'])) ?></td>
            <td>
              <span class="badge text-bg-<?= ($iv['status']==='paid'?'success':($iv['status']==='partially_paid'?'warning':($iv['status']==='void'?'secondary':'info'))) ?>">
                <?= h(str_replace('_',' ',$iv['status'])) ?>
              </span>
            </td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" target="_blank" href="accounts/invoice_view.php?id=<?= (int)$iv['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="text-muted">No invoices<?= ($from||$to)?' in selected range':'' ?>.</div>
    <?php endif; ?>
  </div>

  <!-- Payments -->
  <div class="tab-pane fade" id="cl-payments">
    <?php if ($payments): ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Receipt #</th>
            <th>Method</th>
            <th class="text-end">Amount</th>
            <th>Allocated To</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td><?= h($p['receipt_date']) ?></td>
            <td><?= h($p['receipt_no']) ?></td>
            <td><?= h($p['method']) ?></td>
            <td class="text-end"><?= money2($p['amount']) ?></td>
            <td><?= h($p['allocations'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="text-muted">No payments<?= ($from||$to)?' in selected range':'' ?>.</div>
    <?php endif; ?>

    <?php if ($last_payment): ?>
      <div class="small text-muted mt-2">Last payment: <?= h($last_payment['receipt_date']) ?> • <?= h($last_payment['method']) ?> • AED <?= money2($last_payment['amount']) ?></div>
    <?php endif; ?>
  </div>
</div>

<!-- Footer actions -->
<div class="d-flex justify-content-end gap-2 pb-4 px-4">
  <button class="btn btn-secondary" onclick="showEditClientModal(<?= (int)$client['id'] ?>)">Edit</button>
  <button class="btn btn-danger" onclick="confirmDeleteClient(<?= (int)$client['id'] ?>)">Delete</button>
</div>
