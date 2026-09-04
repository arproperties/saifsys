<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';

function safe($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money2($n){ return number_format((float)$n, 2); }

// -------- Sidebar: clients --------
$clients = $conn->query("
  SELECT id, client_name, mobile_num
  FROM client
  ORDER BY client_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$client_id = (int)($_GET['client_id'] ?? ($clients[0]['id'] ?? 0));
$from      = $_GET['from'] ?? '';
$to        = $_GET['to']   ?? '';

// ---- Load selected client
$client = null;
if ($client_id) {
  $st = $conn->prepare("SELECT * FROM client WHERE id=?");
  $st->execute([$client_id]);
  $client = $st->fetch(PDO::FETCH_ASSOC);
}

// Credit/Credit details
$available_credit   = ar_client_available_credit($conn, (int)$client_id);
$unapplied_receipts = ar_client_unapplied_receipts($conn, (int)$client_id);
$open_invoices      = ar_client_open_invoices($conn, (int)$client_id);

// ---- Date filter helpers
$w = []; $args = [];
if ($client_id) { $w[]="mo.client_id=?";     $args[]=$client_id; }
if ($from!=='') { $w[]="mo.service_date>=?"; $args[]=$from; }
if ($to  !=='') { $w[]="mo.service_date<=?"; $args[]=$to; }
$whereOrders = $w ? ('WHERE '.implode(' AND ',$w)) : '';

// Orders (join invoice if exists)
$orders = [];
if ($client_id) {
  $sql = "
    SELECT mo.*,
           i.id AS invoice_id, i.invoice_no, i.status AS invoice_status, i.total AS invoice_total
      FROM make_order mo
 LEFT JOIN invoices i ON i.order_id = mo.id
    $whereOrders
  ORDER BY mo.service_date DESC, mo.id DESC
     LIMIT 500
  ";
  $st=$conn->prepare($sql); $st->execute($args);
  $orders=$st->fetchAll(PDO::FETCH_ASSOC);
}

// ---- Invoices (date filter uses issue_date)
$wI=[]; $aI=[];
if ($client_id) { $wI[]="i.client_id=?";   $aI[]=$client_id; }
if ($from!==''){ $wI[]="i.issue_date>=?";  $aI[]=$from; }
if ($to  !==''){ $wI[]="i.issue_date<=?";  $aI[]=$to; }
$whereInv = $wI ? ('WHERE '.implode(' AND ',$wI)) : '';

$invoices=[];
if ($client_id) {
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
}

// ---- Payments (receipts) for this client
$wP=[]; $aP=[];
if ($client_id) { $wP[]="r.client_id=?";     $aP[]=$client_id; }
if ($from!==''){ $wP[]="r.receipt_date>=?";  $aP[]=$from; }
if ($to  !==''){ $wP[]="r.receipt_date<=?";  $aP[]=$to; }
$wherePay = $wP ? ('WHERE '.implode(' AND ',$wP)) : '';

$payments=[];
if ($client_id) {
  $sql = "
    SELECT r.*,
           GROUP_CONCAT(CONCAT(i.invoice_no,' (',FORMAT(ra.amount_applied,2),')')
                        ORDER BY ra.id SEPARATOR ', ') AS allocations
      FROM receipts r
 LEFT JOIN receipt_allocations ra ON ra.receipt_id=r.id
 LEFT JOIN invoices i            ON i.id=ra.invoice_id
    $wherePay
  GROUP BY r.id
  ORDER BY r.receipt_date DESC, r.id DESC
     LIMIT 500
  ";
  $st=$conn->prepare($sql); $st->execute($aP);
  $payments=$st->fetchAll(PDO::FETCH_ASSOC);
}

// ---- KPIs / Summary
$total_hours   = 0.0;
$orders_amount = 0.0;
foreach ($orders as $o) {
  $total_hours   += (float)($o['hours'] ?? 0);
  $orders_amount += (float)($o['invoice_total'] ?? ($o['grand_total'] ?? $o['total'] ?? 0));
}

// AR summary from invoices
$sum_total = $sum_paid = $sum_open = 0.0;
foreach ($invoices as $iv) {
  $sum_total += (float)$iv['total'];
  $sum_paid  += (float)$iv['paid'];
  $sum_open  += max(0, (float)$iv['balance']);
}

// last payment
$last_payment = null;
if ($client_id) {
  $st=$conn->prepare("SELECT * FROM receipts WHERE client_id=? ORDER BY receipt_date DESC, id DESC LIMIT 1");
  $st->execute([$client_id]);
  $last_payment = $st->fetch(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Clients | Operation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f6f8fb}
    .panel{background:#fff;border-radius:16px;box-shadow:0 8px 30px #00000012}
    .sidebar .list-group-item{border:0;border-radius:10px;margin-bottom:6px}
    .sidebar .list-group-item.active{background:#0d6efd;color:#fff}
    .kpi{border-radius:14px;background:#0d6efd;color:#fff;padding:18px}
    .kpi .val{font-size:24px;font-weight:800}
    .kpi.muted{background:#6c757d}
    .kpi.green{background:#20c997}
    .kpi.orange{background:#fd7e14}
    .tag{padding:.15rem .5rem;border-radius:999px;font-size:.75rem}
    .table>thead th{white-space:nowrap}
    .btn-outline-primary-soft{border-color:#0d6efd33;color:#0d6efd;background:#0d6efd0d}
    .btn-outline-primary-soft:hover{background:#0d6efd;color:#fff}
    .toast.success { background:#198754; color:#fff; }
    .toast.error   { background:#dc3545; color:#fff; }
    .toast.info    { background:#0d6efd; color:#fff; }
    .toast.warn    { background:#ffc107; color:#212529; }
  </style>
</head>
<body>
<div class="container-fluid py-4">
  <div class="row g-4">
    <!-- Sidebar -->
    <div class="col-xl-3 col-lg-4">
      <div class="panel p-3 sidebar">
        <div class="d-flex align-items-center mb-2">
          <h5 class="mb-0">Clients</h5>
          <button class="btn btn-sm btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#addClientModal">
            <i class="bi bi-plus-lg"></i> Add
          </button>
        </div>
        <input id="clientFilter" class="form-control form-control-sm mb-2" placeholder="Search name or phone">
        <div class="list-group" id="clientList" style="max-height:66vh;overflow:auto">
          <?php foreach($clients as $c): ?>
            <a href="?tab=clients&client_id=<?= (int)$c['id'] ?>"
               class="list-group-item list-group-item-action<?= $client_id===$c['id']?' active':'' ?>"
               data-client-id="<?= (int)$c['id'] ?>"
               data-name="<?= safe($c['client_name']) ?>"
               data-phone="<?= safe($c['mobile_num']) ?>">
              <div class="fw-semibold"><?= safe($c['client_name']) ?></div>
              <small class="text-muted"><?= safe($c['mobile_num']) ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Main -->
    <div class="col-xl-9 col-lg-8">
      <div class="panel p-0">
        <?php if($client): ?>
        <div class="p-3 border-bottom d-flex align-items-center">
          <div>
            <h5 class="mb-0"><?= safe($client['client_name']) ?></h5>
            <div class="text-muted small">
              <?= safe($client['email'] ?? '-') ?> • <?= safe($client['mobile_num'] ?? '-') ?> • TRN: <?= safe($client['trn'] ?? '-') ?>
            </div>
          </div>
          <div class="ms-auto d-flex gap-2">
            <a class="btn btn-outline-primary-soft btn-sm" href="operation/order_add.php?client_id=<?= (int)$client_id ?>">
              <i class="bi bi-briefcase"></i> New Work Order
            </a>
            <button class="btn btn-secondary" onclick="showEditClientModal(<?= (int)$client['id'] ?>)">Edit</button>
            <button class="btn btn-danger" onclick="confirmDeleteClient(<?= (int)$client['id'] ?>)">Delete</button>
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
                <div class="val"><?= number_format($total_hours,2) ?></div>
                <div class="small">Value: AED <?= money2($orders_amount) ?></div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="card border-0 shadow-sm">
                <div class="kpi green">
                  <div class="text-muted small mb-1">Available Credit</div>
                  <div class="h4 mb-0">AED <?= money($available_credit) ?></div>
                  <div class="small text-muted">Unapplied receipts</div>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="kpi muted">
                <div class="small text-white-50">Credit Limit</div>
                <div class="val">AED <?= money2((float)($client['credit_limit'] ?? 0)) ?></div>
                <div class="small">Terms: <?= safe($client['terms'] ?? 'cash') ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters -->
        <form class="px-3 pb-3" method="get">
          <input type="hidden" name="tab" value="clients">
          <input type="hidden" name="client_id" value="<?= (int)$client_id ?>">
          <div class="row g-2 align-items-end">
            <div class="col-md-3">
              <label class="form-label small text-muted mb-1">From</label>
              <input type="date" class="form-control" name="from" value="<?= safe($from) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small text-muted mb-1">To</label>
              <input type="date" class="form-control" name="to" value="<?= safe($to) ?>">
            </div>
            <div class="col-md-6 text-md-end">
              <button class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button>
              <a class="btn btn-outline-secondary" href="?tab=clients&client_id=<?= (int)$client_id ?>">Clear</a>
            </div>
          </div>
        </form>

        <!-- Tabs -->
        <ul class="nav nav-tabs px-3" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-orders"      type="button">Orders</button></li>
          <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#tab-invoices"    type="button">Invoices</button></li>
          <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#tab-payments"    type="button">Payments</button></li>
          <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#tab-advbalance"  type="button">Advanced Balance</button></li>
        </ul>

        <div class="tab-content p-3">
          <!-- Orders -->
          <div class="tab-pane fade show active" id="tab-orders">
            <?php if($orders): ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>ID</th><th>Date</th><th>Time</th><th>Workers</th>
                    <th class="text-end">Hours</th><th class="text-end">Value</th>
                    <th>Driver</th><th>Invoice</th><th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($orders as $o): ?>
                  <tr>
                    <td><?= (int)$o['id'] ?></td>
                    <td><?= safe($o['service_date'] ?: $o['date']) ?></td>
                    <td><?= safe($o['time']) ?></td>
                    <td><?= safe($o['worker_name']) ?></td>
                    <td class="text-end"><?= money2($o['hours']) ?></td>
                    <td class="text-end">AED <?= money2($o['invoice_total'] ?? ($o['grand_total'] ?? $o['total'] ?? 0)) ?></td>
                    <td><?= safe($o['driver_name']) ?></td>
                    <td>
                      <?php if($o['invoice_id']): ?>
                        <a class="btn btn-sm btn-outline-primary" target="_blank" href="accounts/invoice_view.php?id=<?= (int)$o['invoice_id'] ?>">
                          <?= safe($o['invoice_no']) ?>
                        </a>
                        <?php if($o['invoice_status']): ?>
                          <span class="tag bg-light text-dark border"><?= safe(str_replace('_',' ',$o['invoice_status'])) ?></span>
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
                  <tr id="payment-row-<?= (int)$o['id'] ?>" class="payment-row" style="display:none;">
                    <td colspan="9"><div id="payments-container-<?= (int)$o['id'] ?>"></div></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
              <div class="text-muted">No orders<?= ($from||$to)?' in selected range':'' ?>.</div>
            <?php endif; ?>
          </div>

          <!-- Invoices -->
          <div class="tab-pane fade" id="tab-invoices">
            <?php if($invoices): ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Invoice #</th><th>Date</th>
                    <th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th>
                    <th>Status</th><th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($invoices as $iv): ?>
                  <tr>
                    <td><?= safe($iv['invoice_no']) ?></td>
                    <td><?= safe($iv['issue_date']) ?></td>
                    <td class="text-end"><?= money2($iv['total']) ?></td>
                    <td class="text-end"><?= money2($iv['paid']) ?></td>
                    <td class="text-end"><?= money2(max(0,$iv['balance'])) ?></td>
                    <td>
                      <span class="badge text-bg-<?= ($iv['status']==='paid'?'success':($iv['status']==='partially_paid'?'warning':($iv['status']==='void'?'secondary':'info'))) ?>">
                        <?= safe(str_replace('_',' ',$iv['status'])) ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" target="_blank" href="accounts/invoice_view.php?id=<?= (int)$iv['id'] ?>">Open</a>
                    </td>
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
          <div class="tab-pane fade" id="tab-payments">
            <?php if($payments): ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Date</th><th>Receipt #</th><th>Method</th>
                    <th class="text-end">Amount</th><th>Allocated To</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($payments as $p): ?>
                  <tr>
                    <td><?= safe($p['receipt_date']) ?></td>
                    <td><?= safe($p['receipt_no']) ?></td>
                    <td><?= safe($p['method']) ?></td>
                    <td class="text-end"><?= money2($p['amount']) ?></td>
                    <td><?= safe($p['allocations'] ?: '—') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
              <div class="text-muted">No payments<?= ($from||$to)?' in selected range':'' ?>.</div>
            <?php endif; ?>
            <?php if($last_payment): ?>
              <div class="small text-muted mt-2">Last payment: <?= safe($last_payment['receipt_date']) ?> • <?= safe($last_payment['method']) ?> • AED <?= money2($last_payment['amount']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Advanced Balance (Unapplied Receipts) -->
          <div class="tab-pane fade" id="tab-advbalance">
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-light d-flex align-items-center">
                <strong>Unapplied Receipts</strong>
                <span class="badge text-bg-info ms-2">AED <?= money($available_credit) ?> available</span>
              </div>
              <div class="card-body">
                <?php if (!$unapplied_receipts): ?>
                  <div class="text-muted">No unapplied receipts.</div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead class="table-light">
                        <tr>
                          <th>Date</th><th>Receipt</th><th>Method</th>
                          <th class="text-end">Remaining</th>
                          <th style="width:26rem">Apply to Invoice</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($unapplied_receipts as $r): ?>
                          <tr>
                            <td><?= h($r['receipt_date']) ?></td>
                            <td>
                              <a class="btn btn-sm btn-outline-secondary"
                                 href="accounts/receipt_view.php?id=<?= (int)$r['id'] ?>" target="_blank">
                                <?= h($r['receipt_no']) ?>
                              </a>
                            </td>
                            <td><?= h($r['method']) ?></td>
                            <td class="text-end"><?= money($r['remaining']) ?></td>
                            <td>
                              <?php if (!$open_invoices): ?>
                                <span class="text-muted">No open invoices.</span>
                              <?php else: ?>
                                <form class="d-flex gap-2 align-items-center"
                                      action="accounts/invoice_view.php" method="get" target="_blank">
                                  <input type="hidden" name="applyCredit" value="1">
                                  <select class="form-select form-select-sm" name="id" required style="max-width:260px">
                                    <option value="">Select invoice…</option>
                                    <?php foreach ($open_invoices as $inv): ?>
                                      <option value="<?= (int)$inv['id'] ?>">
                                        <?= h($inv['invoice_no']) ?> — AED <?= money($inv['balance']) ?>
                                      </option>
                                    <?php endforeach; ?>
                                  </select>
                                  <input type="hidden" name="credit_receipt_id" value="<?= (int)$r['id'] ?>">
                                  <button class="btn btn-sm btn-primary">Apply</button>
                                </form>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div> <!-- /#tab-advbalance -->
        </div> <!-- /.tab-content -->

        <?php else: ?>
          <div class="p-5 text-center text-muted">
            <i class="bi bi-person-circle" style="font-size:64px"></i>
            <div class="mt-2">Select a client to view details.</div>
          </div>
        <?php endif; ?> <!-- CLOSE if($client) -->
      </div> <!-- /.panel -->
    </div> <!-- /.col -->
  </div> <!-- /.row -->
</div> <!-- /.container-fluid -->

<!-- Add Client Modal -->
<div class="modal fade" id="addClientModal" tabindex="-1" aria-labelledby="addClientModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="add-client-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addClientModalLabel">Add New Client</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- Core -->
        <div class="mb-2">
          <label class="form-label">Client Name <span class="text-danger">*</span></label>
          <input type="text" name="client_name" class="form-control" required>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
            <input type="text" name="mobile_num" class="form-control" required>
          </div>
        </div>

        <!-- Ops cadence -->
        <div class="mb-2">
          <label class="form-label">Payment Type (Ops cadence) <span class="text-danger">*</span></label>
          <select name="payment" class="form-select" required>
            <option value="">-- Choose --</option>
            <option value="D">D (Daily)</option>
            <option value="W">W (Weekly)</option>
            <option value="Bi-W">Bi-W (Bi-Weekly)</option>
            <option value="M">M (Monthly)</option>
          </select>
        </div>

        <!-- AR terms & finance -->
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">AR Terms</label>
            <select name="terms" class="form-select">
              <option value="cash">Cash</option>
              <option value="prepaid">Prepaid</option>
              <option value="15d">15 days</option>
              <option value="30d" selected>30 days</option>
              <option value="45d">45 days</option>
              <option value="60d">60 days</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Default VAT %</label>
            <input type="number" step="0.01" name="default_vat_rate" class="form-control" value="5.00">
          </div>
          <div class="col-md-3">
            <label class="form-label">Credit Limit (AED)</label>
            <input type="number" step="0.01" name="credit_limit" class="form-control" placeholder="0.00">
          </div>
        </div>

        <!-- Extras -->
        <div class="row g-2 mt-1">
          <div class="col-md-6">
            <label class="form-label">Landline</label>
            <input type="text" name="cell_num" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Default Hourly Rate (AED)</label>
            <input type="number" step="0.01" name="rate" class="form-control">
          </div>
        </div>
        <div class="mb-2 mt-1">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control">
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">TRN Number</label>
            <input type="text" name="trn" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Opening Balance</label>
            <input type="number" name="balance" step="0.01" class="form-control" placeholder="0.00">
          </div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="key_le" name="key_le" value="Key">
          <label class="form-check-label" for="key_le">Leave key with us</label>
        </div>

        <div id="add-client-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">Save Client</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1" aria-labelledby="editClientModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="edit-client-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editClientModalLabel">Edit Client</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="edit-client-id">
        <div class="mb-2">
          <label class="form-label">Client Name <span class="text-danger">*</span></label>
          <input type="text" name="client_name" id="edit-client-name" class="form-control" required>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" id="edit-client-email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
            <input type="text" name="mobile_num" id="edit-client-mobile" class="form-control" required>
          </div>
        </div>

        <div class="mb-2">
          <label class="form-label">Payment Type (Ops cadence) <span class="text-danger">*</span></label>
          <select name="payment" id="edit-client-payment" class="form-select" required>
            <option value="">-- Choose --</option>
            <option value="D">D (Daily)</option>
            <option value="W">W (Weekly)</option>
            <option value="Bi-W">Bi-W (Bi-Weekly)</option>
            <option value="M">M (Monthly)</option>
          </select>
        </div>

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">AR Terms</label>
            <select name="terms" id="edit-client-terms" class="form-select">
              <option value="cash">Cash</option>
              <option value="prepaid">Prepaid</option>
              <option value="15d">15 days</option>
              <option value="30d">30 days</option>
              <option value="45d">45 days</option>
              <option value="60d">60 days</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Default VAT %</label>
            <input type="number" step="0.01" name="default_vat_rate" id="edit-client-default-vat" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Credit Limit (AED)</label>
            <input type="number" step="0.01" name="credit_limit" id="edit-client-credit-limit" class="form-control">
          </div>
        </div>

        <div class="row g-2 mt-1">
          <div class="col-md-6">
            <label class="form-label">Landline</label>
            <input type="text" name="cell_num" id="edit-client-cell" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Default Hourly Rate (AED)</label>
            <input type="number" step="0.01" name="rate" id="edit-client-rate" class="form-control">
          </div>
        </div>
        <div class="mb-2 mt-1">
          <label class="form-label">Address</label>
          <input type="text" name="address" id="edit-client-address" class="form-control">
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">TRN Number</label>
            <input type="text" name="trn" id="edit-client-trn" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Balance</label>
            <input type="number" name="balance" id="edit-client-balance" step="0.01" class="form-control">
          </div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="edit-client-key_le" name="key_le" value="Key">
          <label class="form-check-label" for="edit-client-key_le">Leave key with us</label>
        </div>

        <div id="edit-client-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">Save Changes</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar search
document.getElementById('clientFilter')?.addEventListener('input', function(){
  const q=this.value.toLowerCase();
  document.querySelectorAll('#clientList a').forEach(a=>{
    const name=a.dataset.name?.toLowerCase()||'';
    const phone=a.dataset.phone?.toLowerCase()||'';
    a.style.display = (name.includes(q)||phone.includes(q)) ? '' : 'none';
  });
});

// Toggle payments for an order (loads ajax_get_order_payments.php)
function togglePayments(btn, orderId){
  const row=document.getElementById('payment-row-'+orderId);
  const icon=btn.querySelector('i');
  if(row.style.display==='none'){
    row.style.display='';
    icon.classList.replace('bi-chevron-down','bi-chevron-up');
    const box=document.getElementById('payments-container-'+orderId);
    box.innerHTML='<div class="spinner-border text-primary"></div>';
    fetch('operation/ajax_get_order_payments.php?order_id='+orderId)
      .then(r=>r.text()).then(html=>box.innerHTML=html)
      .catch(()=>box.innerHTML='<div class="text-danger">Failed to load</div>');
  }else{
    row.style.display='none';
    icon.classList.replace('bi-chevron-up','bi-chevron-down');
  }
}

// Add client (uses your endpoint)
document.getElementById('add-client-form')?.addEventListener('submit',function(e){
  e.preventDefault();
  const fd=new FormData(this);
  if(!fd.get('key_le')) fd.set('key_le','No key');
  fetch('operation/ajax_add_client.php',{method:'POST',body:fd})
    .then(r=>r.json()).then(j=>{
      if(j.success){ location.href='?tab=clients&client_id='+j.client.id; }
      else{
        const el=document.getElementById('add-client-error'); el.textContent=j.error||'Error'; el.style.display='';
      }
    }).catch(()=>{
      const el=document.getElementById('add-client-error'); el.textContent='Server error'; el.style.display='';
    });
});

// Load client into edit modal
function showEditClientModal(clientId) {
  if (!clientId) { alert('Client ID missing!'); return; }
  fetch('operation/ajax_get_client.php?id=' + clientId)
    .then(res => res.json())
    .then(resp => {
      if (!resp.success || !resp.client) { alert('Client not found'); return; }
      var client = resp.client;
      document.getElementById('edit-client-id').value        = client.id || '';
      document.getElementById('edit-client-name').value      = client.client_name || '';
      document.getElementById('edit-client-email').value     = client.email || '';
      document.getElementById('edit-client-payment').value   = client.payment || '';
      document.getElementById('edit-client-mobile').value    = client.mobile_num || '';
      document.getElementById('edit-client-cell').value      = client.cell_num || '';
      document.getElementById('edit-client-rate').value      = client.rate || '';
      document.getElementById('edit-client-address').value   = client.address || '';
      document.getElementById('edit-client-trn').value       = client.trn || '';
      document.getElementById('edit-client-balance').value   = client.balance || '';
      document.getElementById('edit-client-key_le').checked  = client.key_le === 'Key';
      new bootstrap.Modal(document.getElementById('editClientModal')).show();
    })
    .catch(() => alert('Error loading client details!'));
}

// Save edit
document.getElementById('edit-client-form')?.addEventListener('submit',function(e){
  e.preventDefault();
  const fd=new FormData(this);
  if(!fd.get('key_le')) fd.set('key_le','No key');
  fetch('operation/ajax_update_client.php',{method:'POST',body:fd})
    .then(r=>r.json()).then(j=>{
      if(j.success){ location.href='?tab=clients&client_id='+j.client.id; }
      else{
        const el=document.getElementById('edit-client-error'); el.textContent=j.error||'Error'; el.style.display='';
      }
    }).catch(()=>{
      const el=document.getElementById('edit-client-error'); el.textContent='Server error'; el.style.display='';
    });
});

// Delete client
async function confirmDeleteClient(clientId) {
  try {
    if (!clientId) {
      const active = document.querySelector('#clientList .list-group-item.active');
      if (!active) { alert('No client selected!'); return; }
      clientId = active.getAttribute('data-client-id');
    }
    if (!confirm('Delete this client? If they have history, they will be marked inactive.')) return;

    const res  = await fetch('operation/ajax_delete_client.php', {
      method: 'POST',
      body: new URLSearchParams({ client_id: clientId })
    });

    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch { data = null; }
    if (!res.ok || !data) { alert((data && data.error) || text || 'Server error.'); return; }
    if (data.success !== true) { alert(data.error || 'Delete failed.'); return; }

    if (data.soft_deleted) {
      alert('Client has related data and was marked inactive.');
      const item = document.querySelector(`#clientList .list-group-item[data-client-id="${clientId}"]`);
      if (item) item.classList.add('text-muted');
      location.href='?tab=clients&client_id='+encodeURIComponent(clientId);
      return;
    }

    // Hard deleted
    alert('Client deleted.');
    const item = document.querySelector(`#clientList .list-group-item[data-client-id="${clientId}"]`);
    if (item) item.remove();

    const first = document.querySelector('#clientList .list-group-item');
    if (first) {
      location.href='?tab=clients&client_id='+encodeURIComponent(first.getAttribute('data-client-id'));
    } else {
      // no more clients—reload list
      location.href='?tab=clients';
    }
  } catch (err) {
    alert(err?.message || 'Server error.');
  }
}

// Toast helper
function showToast(type, msg) {
  const toastEl = document.getElementById('appToast');
  const toastBody = document.getElementById('appToastBody');
  if (!toastEl || !toastBody) { console.log(type?.toUpperCase()+':', msg); return; }
  toastEl.classList.remove('success','error','info','warn');
  toastEl.classList.add(type || 'info');
  toastBody.innerHTML = msg;
  bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 3000 }).show();
}
</script>

<!-- Toasts -->
<div aria-live="polite" aria-atomic="true" class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1080">
  <div id="appToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div id="appToastBody" class="toast-body"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>
</body>
</html>
