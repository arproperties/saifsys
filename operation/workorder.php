<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/ar_helpers.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2); }

/* -------------------------------------------------------
   Inputs / filters
------------------------------------------------------- */
$search     = trim($_GET['search'] ?? '');
$date       = trim($_GET['date'] ?? '');        // legacy single date
$date_from  = trim($_GET['date_from'] ?? '');
$date_to    = trim($_GET['date_to'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 20;
$offset     = ($page - 1) * $limit;

/* -------------------------------------------------------
   WHERE (prefer service_date; fallback to legacy date)
------------------------------------------------------- */
$where  = [];
$params = [];

if ($search !== '') {
  $where[] = "("
    ."COALESCE(c.client_name, mo.client_name) LIKE :q OR "
    ."mo.worker_name LIKE :q OR "
    ."mo.remark LIKE :q OR "
    ."mo.Notes LIKE :q OR "
    ."mo.time LIKE :q"
    .")";
  $params[':q'] = "%{$search}%";
}
if ($date_from !== '' && $date_to !== '') {
  $where[] = "(mo.service_date BETWEEN :df AND :dt OR (mo.service_date IS NULL AND mo.`date` BETWEEN :df AND :dt))";
  $params[':df'] = $date_from;
  $params[':dt'] = $date_to;
} elseif ($date !== '') {
  $where[] = "(mo.service_date = :d OR (mo.service_date IS NULL AND mo.`date` = :d))";
  $params[':d'] = $date;
}

$where_sql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Exclude cancelled for totals / counts
$where_sql_active = $where_sql
  ? $where_sql . " AND COALESCE(mo.status,'') <> 'cancelled'"
  : "WHERE COALESCE(mo.status,'') <> 'cancelled'";

/* -------------------------------------------------------
   Totals (filtered set, not paged) — excludes cancelled
------------------------------------------------------- */
$sql_totals = "
  SELECT
    COALESCE(SUM(mo.hours),0)  AS tot_hours,
    COALESCE(SUM(mo.total),0)  AS tot_amount,
    COUNT(*)                   AS tot_duty
  FROM make_order mo
  LEFT JOIN client c ON c.id = mo.client_id
  $where_sql_active
";
$st = $conn->prepare($sql_totals);
$st->execute($params);
$tot = $st->fetch(PDO::FETCH_ASSOC) ?: ['tot_hours'=>0,'tot_amount'=>0,'tot_duty'=>0];

/* -------------------------------------------------------
   Count (excludes cancelled) + paged rows (show all)
------------------------------------------------------- */
$sql_count = "SELECT COUNT(*) FROM make_order mo LEFT JOIN client c ON c.id = mo.client_id $where_sql_active";
$st = $conn->prepare($sql_count);
$st->execute($params);
$total_rows  = (int)$st->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

$sql_rows = "
  SELECT
    mo.*,
    COALESCE(c.client_name, mo.client_name) AS client_name_display,
    i.id AS invoice_id,
    i.invoice_no,
    i.status AS invoice_status
  FROM make_order mo
  LEFT JOIN client   c ON c.id = mo.client_id
  LEFT JOIN invoices i ON i.order_id = mo.id
  $where_sql
  ORDER BY COALESCE(mo.service_date, mo.`date`) DESC, mo.id DESC
  LIMIT :lim OFFSET :off
";
$st = $conn->prepare($sql_rows);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $limit, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$orders = $st->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------------
   Workers per order (1 round trip)
------------------------------------------------------- */
$order_ids = array_column($orders, 'id');
$workers_by_order = [];
if ($order_ids) {
  $in = implode(',', array_fill(0, count($order_ids), '?'));

  // nickname map
  $worker_map = [];
  $wm = $conn->query("SELECT id, nickname FROM workers");
  while ($r = $wm->fetch(PDO::FETCH_ASSOC)) {
    $worker_map[(int)$r['id']] = $r['nickname'] ?: ('#'.$r['id']);
  }

  $q = $conn->prepare("SELECT order_id, worker_id FROM order_workers WHERE order_id IN ($in)");
  $q->execute($order_ids);
  while ($ow = $q->fetch(PDO::FETCH_ASSOC)) {
    $oid = (int)$ow['order_id'];
    $wid = (int)$ow['worker_id'];
    $workers_by_order[$oid][] = $worker_map[$wid] ?? ('#'.$wid);
  }
}

/* -------------------------------------------------------
   Helpers
------------------------------------------------------- */
function status_badge_class(string $st): string {
  switch ($st) {
    case 'completed':      return 'success';
    case 'in_progress':    return 'primary';
    case 'scheduled':      return 'info';
    case 'confirmed':      return 'secondary';
    case 'invoiced':       return 'dark';
    case 'cancelled':      return 'secondary';
    case 'draft':          return 'warning';
    default:               return 'light';
  }
}
?>
<!-- Search + Filters -->
<div class="mb-4">
  <form class="row g-2" method="get" action="">
    <div class="col-lg-4 col-md-6">
      <input type="text" name="search" class="form-control" placeholder="Search by client, worker, remark, note…" value="<?=h($search)?>">
    </div>
    <div class="col-lg-2 col-md-3">
      <input type="date" name="date" class="form-control" value="<?=h($date)?>" title="(legacy) exact date">
    </div>
    <div class="col-lg-2 col-md-3">
      <input type="date" name="date_from" class="form-control" value="<?=h($date_from)?>">
    </div>
    <div class="col-lg-2 col-md-3">
      <input type="date" name="date_to" class="form-control" value="<?=h($date_to)?>">
    </div>
    <div class="col-auto">
      <button class="btn btn-primary" type="submit" title="Filter"><i class="bi bi-search"></i> Search</button>
      <a href="operation.php?tab=workorder" class="btn btn-secondary" title="Reset"><i class="bi bi-arrow-clockwise"></i></a>
    </div>
  </form>
</div>

<form id="workorder-actions-form" method="post">
  <input type="hidden" name="selected_id" id="selected_id" value="">

  <div class="table-responsive mb-3">
    <table class="table table-bordered table-hover align-middle" id="orders-table">
      <thead class="table-dark">
        <tr>
          <th>ID</th>
          <th>Client</th>
          <th>Workers</th>
          <th>Service Date</th>
          <th>Time</th>
          <th>Hours</th>
          <th>Total</th>
          <th>Status</th>
          <th>Remark</th>
          <th>Note</th>
          <th>Driver</th>
          <th>Invoice</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($orders): foreach ($orders as $row): ?>
        <?php
          $oid = (int)$row['id'];
          $svcDate = $row['service_date'] ?: $row['date'];
          $worker_names = $workers_by_order[$oid] ?? [];
          $workers_str  = $worker_names ? implode(', ', array_map('h', $worker_names))
                                        : h($row['worker_name'] ?? '');
          $is_cancelled = (($row['status'] ?? '') === 'cancelled');
        ?>
        <tr
          class="<?= $is_cancelled ? 'opacity-50' : '' ?>"
          data-row-id="<?=$oid?>"
          data-status="<?=h($row['status'] ?? '')?>"
          data-email="<?=h($row['email_o'] ?? '')?>"
          data-address="<?=h($row['address_o'] ?? '')?>"
          data-mobilenum="<?=h($row['mobile_num_o'] ?? '')?>"
          data-feecharge="<?=h($row['fee_charged'] ?? '')?>"
          data-payment="<?=h($row['payment'] ?? '')?>"
        >
          <td><?=h($row['id'])?></td>
          <td><?=h($row['client_name_display'] ?? '')?></td>
          <td><?=$workers_str?></td>
          <td><?=h($svcDate)?></td>
          <td><?=h($row['time'] ?? '')?></td>
          <td><?=h($row['hours'])?></td>
          <td><?=money($row['total'])?></td>
          <td>
            <span class="badge text-bg-<?= status_badge_class((string)($row['status'] ?? '')) ?>">
              <?= h(str_replace('_',' ', (string)($row['status'] ?? ''))) ?>
            </span>
            <?php if (!empty($row['cancel_reason']) && ($row['status'] ?? '')==='cancelled'): ?>
              <small class="text-muted d-block">Reason: <?=h($row['cancel_reason'])?></small>
            <?php endif; ?>
          </td>
          <td><?=h($row['remark'] ?? '')?></td>
          <td><?=h($row['Notes'] ?? '')?></td>
          <td><?=h($row['driver_name'] ?? '')?></td>
          <td>
            <?php if (!empty($row['invoice_id'])): ?>
              <span class="badge bg-secondary"><?=h($row['invoice_status'] ?? '')?></span>
              <a href="accounts/invoice_view.php?id=<?= (int)$row['invoice_id']?>" class="ms-1">#<?=h($row['invoice_no'] ?? '')?></a>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="12" class="text-center">No work orders found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</form>

<!-- Pagination -->
<nav>
  <ul class="pagination">
    <?php if ($page > 1): ?>
      <li class="page-item">
        <a class="page-link" href="?<?=h(http_build_query(array_merge($_GET, ['page'=>$page-1])))?>">&laquo; Prev</a>
      </li>
    <?php endif; ?>

    <?php
      $range = 2; $dots = false;
      for ($p=1; $p<=$total_pages; $p++) {
        if ($p<=2 || $p>$total_pages-2 || ($p>=$page-$range && $p<=$page+$range)) {
          $active = ($p==$page)?'active':'';
          echo '<li class="page-item '.$active.'"><a class="page-link" href="?'.h(http_build_query(array_merge($_GET, ['page'=>$p]))).'">'.$p.'</a></li>';
          $dots = true;
        } elseif ($dots) {
          echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          $dots = false;
        }
      }
    ?>

    <?php if ($page < $total_pages): ?>
      <li class="page-item">
        <a class="page-link" href="?<?=h(http_build_query(array_merge($_GET, ['page'=>$page+1])))?>">Next &raquo;</a>
      </li>
    <?php endif; ?>
  </ul>
</nav>

<!-- Totals + Actions -->
<div class="d-flex flex-wrap align-items-center gap-3 justify-content-between border rounded-3 p-3 mb-3 bg-light">
  <div>
    <span class="fw-bold">Total Hours:</span>
    <input type="text" class="form-control d-inline-block w-auto" value="<?=number_format((float)$tot['tot_hours'],2)?>" readonly style="max-width:90px;">
    <span class="fw-bold ms-3">Total Amount:</span>
    <input type="text" class="form-control d-inline-block w-auto" value="<?=number_format((float)$tot['tot_amount'],2)?>" readonly style="max-width:120px;">
    <span class="fw-bold ms-3">Total Duty:</span>
    <input type="text" class="form-control d-inline-block w-auto" value="<?=(int)$tot['tot_duty']?>" readonly style="max-width:80px;">
  </div>
  <div class="text-end">
    <a href="operation/order_add.php" class="btn btn-success me-2">Add New</a>
    <button type="button" class="btn btn-warning me-2" id="edit-btn" disabled>Edit</button>

    <!-- Cancel selected (opens modal) -->
    <button type="button" class="btn btn-outline-danger me-2" id="cancel-btn" disabled
            data-bs-toggle="modal" data-bs-target="#cancelOrderModal">
      Cancel
    </button>

    <a href="operation/print_report.php?<?=h(http_build_query($_GET))?>" class="btn btn-outline-secondary">
      <i class="bi bi-printer"></i> Print
    </a>
  </div>
</div>

<!-- Cancel modal -->
<div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="operation/order_cancel.php">
      <div class="modal-header">
        <h5 class="modal-title">Cancel Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="cancel-order-id">
        <div class="mb-2">
          <label class="form-label">Reason (optional)</label>
          <input type="text" class="form-control" name="reason" placeholder="e.g. client rescheduled / duplicate">
        </div>
        <div class="alert alert-warning small mb-0">
          Cancelling marks the order as <strong>cancelled</strong>. If an unpaid invoice exists, it will be
          <strong>voided</strong> and GL postings will be reversed. If receipts were allocated, unallocate first.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger">Confirm Cancel</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

<script>
let selectedRowId = null;

function updateToolbarForRow(row) {
  const cancelBtn = document.getElementById('cancel-btn');
  const editBtn   = document.getElementById('edit-btn');

  if (!row) {
    cancelBtn.disabled = true;
    editBtn.disabled   = true;
    return;
  }

  const status = row.getAttribute('data-status') || '';
  // Disable "Cancel" if already cancelled
  cancelBtn.disabled = (status === 'cancelled');
  editBtn.disabled   = false;
}

document.querySelectorAll('#orders-table tbody tr[data-row-id]').forEach(function(row){
  row.addEventListener('click', function(){
    document.querySelectorAll('#orders-table tbody tr').forEach(r => r.classList.remove('table-primary'));
    this.classList.add('table-primary');

    selectedRowId = this.getAttribute('data-row-id');
    document.getElementById('selected_id').value = selectedRowId;
    updateToolbarForRow(this);
  });
});

document.getElementById('edit-btn')?.addEventListener('click', function(){
  if (selectedRowId) {
    window.location.href = "operation/order_edit.php?id=" + encodeURIComponent(selectedRowId);
  }
});

// Wire selected id into cancel modal
document.getElementById('cancel-btn')?.addEventListener('click', function(){
  if (!selectedRowId) return;
  document.getElementById('cancel-order-id').value = selectedRowId;
  const row = document.querySelector('#orders-table tr.table-primary');
  const number = row ? row.querySelector('td:first-child')?.textContent?.trim() : selectedRowId;
  const title = document.querySelector('#cancelOrderModal .modal-title');
  if (title) title.textContent = 'Cancel Order #' + number;
});
</script>
