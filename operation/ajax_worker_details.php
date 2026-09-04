<?php
require_once __DIR__.'/../includes/db_connect.php';

$worker_id = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 15;

if (!$worker_id) {
    echo '<div class="alert alert-warning">Cleaner not found.</div>';
    exit;
}

// Load worker (full name + nickname)
$stmt = $conn->prepare("SELECT id, worker_name, nickname FROM workers WHERE id = ?");
$stmt->execute([$worker_id]);
$worker = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$worker) {
    echo '<div class="alert alert-warning">Cleaner not found.</div>';
    exit;
}
$full  = trim((string)$worker['worker_name']);
$nick  = trim((string)($worker['nickname'] ?? ''));

$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';

$dateExpr        = 'COALESCE(mo.service_date, mo.date)';
$hoursExprSql    = "COALESCE(mo.net_hours, CASE WHEN owc.cnt>0 THEN mo.hours/owc.cnt ELSE mo.hours END)";
$amountBaseSql   = "COALESCE(mo.grand_total, mo.total)";
$amountExprSql   = "COALESCE(mo.net_amount, CASE WHEN owc.cnt>0 THEN {$amountBaseSql}/owc.cnt ELSE {$amountBaseSql} END)";

// KPI range defaults to current month if filters empty
$summary_from = $date_from !== '' ? $date_from : date('Y-m-01');
$summary_to   = $date_to   !== '' ? $date_to   : date('Y-m-d');

$summarySql = "
  SELECT
    COUNT(*) AS orders_cnt,
    ROUND(SUM({$hoursExprSql}),2)   AS total_hours,
    ROUND(SUM({$amountExprSql}),2)  AS total_amount,
    MAX({$dateExpr})                AS last_job_date
  FROM order_workers ow
  JOIN make_order mo ON mo.id = ow.order_id
  JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id
  WHERE ow.worker_id = :wid
    AND mo.status IN ('confirmed','completed')
    AND {$dateExpr} BETWEEN :sf AND :st
";
$stmt = $conn->prepare($summarySql);
$stmt->execute([
  ':wid' => $worker_id,
  ':sf'  => $summary_from,
  ':st'  => $summary_to,
]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['orders_cnt'=>0,'total_hours'=>0,'total_amount'=>0,'last_job_date'=>null];

$orders_cnt  = (int)($summary['orders_cnt'] ?? 0);
$total_hours = (float)($summary['total_hours'] ?? 0);
$total_earn  = (float)($summary['total_amount'] ?? 0);
$last_booking = null;

if (!empty($summary['last_job_date'])) {
    $latestSql = "
      SELECT mo.id, {$dateExpr} AS job_date, mo.client_name
      FROM order_workers ow
      JOIN make_order mo ON mo.id = ow.order_id
      WHERE ow.worker_id = :wid
        AND mo.status IN ('confirmed','completed')
        AND {$dateExpr} = :last_date
      ORDER BY mo.id DESC
      LIMIT 1
    ";
    $stmt = $conn->prepare($latestSql);
    $stmt->execute([
      ':wid'       => $worker_id,
      ':last_date' => $summary['last_job_date'],
    ]);
    $last_booking = $stmt->fetch(PDO::FETCH_ASSOC);
}

$avg_hours = $orders_cnt ? round($total_hours / $orders_cnt, 2) : 0.0;

// List filters (optional date range)
$listWhere = ["ow.worker_id = :wid", "mo.status IN ('confirmed','completed')"];
$listParams = [':wid' => $worker_id];
if ($date_from !== '') { $listWhere[] = "{$dateExpr} >= :lf"; $listParams[':lf'] = $date_from; }
if ($date_to   !== '') { $listWhere[] = "{$dateExpr} <= :lt"; $listParams[':lt'] = $date_to; }
$listWhereSql = 'WHERE ' . implode(' AND ', $listWhere);

// Total count for pagination
$countSql = "
  SELECT COUNT(*)
  FROM order_workers ow
  JOIN make_order mo ON mo.id = ow.order_id
  {$listWhereSql}
";
$stmt = $conn->prepare($countSql);
$stmt->execute($listParams);
$total_orders = (int)$stmt->fetchColumn();
$total_pages  = max(1, (int)ceil($total_orders / $per_page));
$offset       = ($page - 1) * $per_page;

// Page rows
$listSql = "
  SELECT
    mo.id,
    {$dateExpr} AS job_date,
    mo.client_name,
    mo.status,
    mo.driver_name,
    mo.remark,
    ROUND({$hoursExprSql},2)  AS worker_hours,
    ROUND({$amountExprSql},2) AS worker_amount
  FROM order_workers ow
  JOIN make_order mo ON mo.id = ow.order_id
  JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id
  {$listWhereSql}
  ORDER BY job_date DESC, mo.id DESC
  LIMIT :lim OFFSET :off
";
$stmt = $conn->prepare($listSql);
foreach ($listParams as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fmt2 = fn($n)=> rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
?>
<div class="card" style="border-radius:18px;">
  <div class="worker-header d-flex justify-content-between align-items-center" style="background:#00a5df;color:#fff;border-radius:18px 18px 0 0;padding:18px 32px;">
    <span>
      <i class="bi bi-person-bounding-box" style="font-size:32px;margin-right:8px;"></i>
      <?= htmlspecialchars($full) ?>
      <?php if ($nick!==''): ?>
        <span class="text-light" style="font-size:14px;font-weight:400;">(<?= htmlspecialchars($nick) ?>)</span>
      <?php endif; ?>
    </span>
    <button class="btn btn-info btn-sm" onclick="alert('Assign booking coming soon!');">
      <i class="bi bi-calendar-plus"></i> Assign Booking
    </button>
  </div>

  <!-- KPIs (for selected range, or this month if none) -->
  <div class="row text-center py-3 px-4" style="background:#f5fbfd;">
    <div class="col-6 col-md-2 mb-2">
      <div class="small text-muted">Orders<?= ($date_from||$date_to)?' (range)':' (this month)' ?></div>
      <div class="h5 mb-0"><?= $orders_cnt ?></div>
    </div>
    <div class="col-6 col-md-2 mb-2">
      <div class="small text-muted">Total Hours</div>
      <div class="h5 mb-0"><?= $fmt2($total_hours) ?></div>
    </div>
    <div class="col-6 col-md-2 mb-2">
      <div class="small text-muted">Total Earnings</div>
      <div class="h5 mb-0">AED <?= $fmt2($total_earn) ?></div>
    </div>
    <div class="col-6 col-md-2 mb-2">
      <div class="small text-muted">Avg. Hours/Order</div>
      <div class="h5 mb-0"><?= $fmt2($avg_hours) ?></div>
    </div>
    <div class="col-6 col-md-4 mb-2">
      <div class="small text-muted">Last Booking</div>
      <div class="h6 mb-0">
        <?php if ($last_booking) {
          echo htmlspecialchars($last_booking['job_date'])
               . '<br><span class="small">' . htmlspecialchars($last_booking['client_name']) . '</span>';
        } else { echo '--'; } ?>
      </div>
    </div>
  </div>

  <div class="table-responsive p-4 pt-3">
    <h6 class="mb-2 fw-bold">Recent Orders</h6>

    <!-- Filter row -->
    <form class="row g-2 mb-3" id="filterForm">
      <input type="hidden" name="worker_id" value="<?= (int)$worker_id ?>">
      <div class="col-auto">
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>" placeholder="From">
      </div>
      <div class="col-auto">
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>" placeholder="To">
      </div>
      <div class="col-auto">
        <button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button>
      </div>
    </form>

    <?php if ($rows): ?>
    <table class="table table-bordered table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Date</th>
          <th>Client</th>
          <th>Hours</th>
          <th>Amount</th>
          <th>Driver</th>
          <th>Status</th>
          <th>Remark</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['job_date']) ?></td>
          <td><?= htmlspecialchars($r['client_name']) ?></td>
          <td><?= $fmt2($r['worker_hours'] ?? 0) ?></td>
          <td><?= $fmt2($r['worker_amount'] ?? 0) ?></td>
          <td><?= htmlspecialchars($r['driver_name']) ?></td>
          <td><?= htmlspecialchars(str_replace('_',' ', ucfirst($r['status'] ?? ''))) ?></td>
          <td><?= htmlspecialchars($r['remark']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <div class="mt-2" style="overflow-x:auto;">
      <nav aria-label="Orders pagination">
        <ul class="pagination pagination-sm justify-content-center flex-wrap mb-0" style="min-width:320px;">
          <li class="page-item <?= $page==1?'disabled':'' ?>">
            <a class="page-link" href="#" data-page="<?= $page-1 ?>">Prev</a>
          </li>
          <?php
          $win = 3; $start = max(1, $page-$win); $end = min($total_pages, $page+$win);
          if ($start > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          for ($i=$start;$i<=$end;$i++):
          ?>
            <li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a></li>
          <?php endfor;
          if ($end < $total_pages) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          ?>
          <li class="page-item <?= $page==$total_pages?'disabled':'' ?>">
            <a class="page-link" href="#" data-page="<?= $page+1 ?>">Next</a>
          </li>
        </ul>
      </nav>
    </div>

    <?php else: ?>
      <div class="py-4 text-center text-muted">
        <i class="bi bi-inboxes" style="font-size:54px"></i>
        <div class="mt-2">No orders found for this cleaner<?= ($date_from||$date_to)?' in selected range':'' ?>.</div>
      </div>
    <?php endif; ?>
  </div>
</div>
