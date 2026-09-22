<?php
// operation/workorder_list.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/url_helper.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../includes/work_order_financial_guard.php';
require_once __DIR__ . '/../includes/work_order_adjustment_service.php';
require_once __DIR__ . '/../includes/cleaning_order_cancellation_helper.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
cleaning_order_cancel_ensure_schema($conn);
$woCancelCategories = cleaning_order_cancel_categories();
$canRequestAdjUser = sm_user_can_request_adjustment($conn);

// Get current company context
$currentCompanyId = current_company_id($conn) ?: 1; // Fallback to 1 for backward compatibility

// --- Guarded helpers to avoid "Cannot redeclare" fatals ---
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
  function money($n){ return number_format((float)$n, 2); }
}
if (!function_exists('status_badge_class')) {
  function status_badge_class(string $st): string {
    switch ($st) {
      case 'completed':   return 'success';
      case 'in_progress': return 'primary';
      case 'scheduled':   return 'info';
      case 'confirmed':   return 'secondary';
      case 'invoiced':    return 'dark';
      case 'paid':        return 'success';
      case 'partially_paid': return 'warning';
      case 'cancelled':   return 'secondary';
      case 'draft':       return 'warning';
      default:            return 'light';
    }
  }
}
if (!function_exists('ops_badge_class')) {
  function ops_badge_class(string $ops): string {
    switch (strtolower(trim($ops))) {
      case 'open':       return 'text-bg-info';
      case 'completed':  return 'text-bg-success';
      case 'cancelled':  return 'text-bg-secondary';
      default:           return 'text-bg-light text-dark border';
    }
  }
}

$canFinalizeUser = sm_user_can_finalize($conn);
$canMarkCompleteUser = sm_user_can_mark_complete($conn);

echo "<!-- workorder loaded -->"; // quick sanity check in View Source

// ---------------- Inputs / filters - Merge GET with session (backward compatibility) ----------------
$filters = merge_get_with_session('operation_workorder');
$search     = trim($filters['search'] ?? '');
$date       = trim($filters['date'] ?? ''); // legacy single date
$date_from  = trim($filters['date_from'] ?? '');
$date_to    = trim($filters['date_to'] ?? '');
$status_filter = trim($filters['status_filter'] ?? '');
$ops_filter = trim($filters['ops_filter'] ?? '');
$worker_filter = trim($filters['worker_filter'] ?? '');
$worker_filter_id = null;
if ($worker_filter !== '') {
  try {
    $wf = $conn->prepare("SELECT id FROM workers WHERE worker_name = ? OR nickname = ? LIMIT 1");
    $wf->execute([$worker_filter, $worker_filter]);
    $worker_filter_id = (int)$wf->fetchColumn() ?: null;
    if (!$worker_filter_id) {
      $wf = $conn->prepare("SELECT id FROM workers WHERE worker_name LIKE ? OR nickname LIKE ? LIMIT 1");
      $wf->execute(["%{$worker_filter}%", "%{$worker_filter}%"]);
      $worker_filter_id = (int)$wf->fetchColumn() ?: null;
    }
  } catch (\Throwable $e) {
    $worker_filter_id = null;
  }
}
$invoice_filter = trim($filters['invoice_filter'] ?? '');
$page       = max(1, (int)($filters['page'] ?? 1));
$limit      = 20;
$offset     = ($page - 1) * $limit;

// Default to today's date if no date filters are provided
if ($date_from === '' && $date_to === '' && $date === '') {
  $date_from = date('Y-m-d');
  $date_to   = date('Y-m-d');
}

$where  = [];
$params = [];

// Company filter (always apply)
$where[] = "mo.company_id = :company_id";
$params[':company_id'] = $currentCompanyId;

// text search
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

// dates — prefer service_date; fallback to legacy date
if ($date_from !== '' && $date_to !== '') {
  $where[] = "(mo.service_date BETWEEN :df AND :dt OR (mo.service_date IS NULL AND mo.`date` BETWEEN :df AND :dt))";
  $params[':df'] = $date_from;
  $params[':dt'] = $date_to;
} elseif ($date !== '') {
  $where[] = "(mo.service_date = :d OR (mo.service_date IS NULL AND mo.`date` = :d))";
  $params[':d'] = $date;
}

// status filter (legacy detailed status)
if ($status_filter !== '') {
  $where[] = "COALESCE(mo.status,'') = :status";
  $params[':status'] = $status_filter;
}

// simplified ops filter (Phase 3)
if ($ops_filter !== '' && wo_column_exists($conn, 'ops_status')) {
  if ($ops_filter === 'open') {
    $where[] = "COALESCE(mo.is_finalized,0) = 0";
    $where[] = "COALESCE(mo.status,'') <> 'cancelled'";
    $where[] = "(mo.ops_status = 'open' OR COALESCE(mo.status,'') IN ('draft','scheduled','confirmed','in_progress'))";
  } elseif ($ops_filter === 'completed') {
    $where[] = "COALESCE(mo.is_finalized,0) = 0";
    $where[] = "COALESCE(mo.status,'') <> 'cancelled'";
    $where[] = "(mo.ops_status = 'completed' OR COALESCE(mo.status,'') IN ('completed','invoiced'))";
  } elseif ($ops_filter === 'cancelled') {
    $where[] = "COALESCE(mo.status,'') = 'cancelled'";
  } elseif ($ops_filter === 'finalized') {
    $where[] = "COALESCE(mo.is_finalized,0) = 1";
  }
} elseif ($ops_filter !== '') {
  if ($ops_filter === 'open') {
    $where[] = "COALESCE(mo.status,'') NOT IN ('completed','invoiced','cancelled')";
  } elseif ($ops_filter === 'completed') {
    $where[] = "COALESCE(mo.status,'') IN ('completed','invoiced')";
  } elseif ($ops_filter === 'cancelled') {
    $where[] = "COALESCE(mo.status,'') = 'cancelled'";
  }
}

// worker filter
if ($worker_filter_id) {
  $params[':worker_filter_id'] = $worker_filter_id;
} elseif ($worker_filter !== '') {
  $where[] = "mo.worker_name LIKE :worker";
  $params[':worker'] = "%{$worker_filter}%";
}

// invoice filter
if ($invoice_filter === 'invoiced') {
  $where[] = "i.id IS NOT NULL";
} elseif ($invoice_filter === 'not_invoiced') {
  $where[] = "i.id IS NULL";
}

// base WHERE (from filters)
$where_sql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

// active WHERE (exclude cancelled everywhere totals/count/list)
$where_sql_active = $where_sql
  ? $where_sql . " AND COALESCE(mo.status,'') <> 'cancelled'"
  : "WHERE COALESCE(mo.status,'') <> 'cancelled'";

$joinWorkerFilter = '';
$joinWorkerCountTotals = '';
$hoursExprPerWorker = '';
$amountExprPerWorker = '';

$orderAmountSql = wo_order_customer_total_sql($conn);

if ($worker_filter_id) {
  $joinWorkerFilter = "JOIN order_workers owf ON owf.order_id = mo.id AND owf.worker_id = :worker_filter_id";
  $joinWorkerCountTotals = "JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id";
  $hoursExprPerWorker = "COALESCE(mo.net_hours, CASE WHEN owc.cnt>0 THEN mo.hours/owc.cnt ELSE mo.hours END)";
  $amountBaseExpr = $orderAmountSql;
  $amountExprPerWorker = "COALESCE(mo.net_amount, CASE WHEN owc.cnt>0 THEN {$amountBaseExpr}/owc.cnt ELSE {$amountBaseExpr} END)";
}

try {
  // ---------------- Totals (filtered, not paged) ----------------
  $totHoursSelect = "COALESCE(SUM(mo.hours),0)  AS tot_hours";
  $totAmountSelect = "COALESCE(SUM({$orderAmountSql}),0) AS tot_amount";
  if ($worker_filter_id && $hoursExprPerWorker && $amountExprPerWorker) {
    $totHoursSelect = "COALESCE(SUM({$hoursExprPerWorker}),0) AS tot_hours";
    $totAmountSelect = "COALESCE(SUM({$amountExprPerWorker}),0) AS tot_amount";
  }
  $sql_totals = "
    SELECT
      {$totHoursSelect},
      {$totAmountSelect},
      COUNT(*)                   AS tot_duty
    FROM make_order mo
    {$joinWorkerFilter}
    {$joinWorkerCountTotals}
    LEFT JOIN client c ON c.id = mo.client_id
    $where_sql_active
  ";
  $st = $conn->prepare($sql_totals);
  $st->execute($params);
  $tot = $st->fetch(PDO::FETCH_ASSOC) ?: ['tot_hours'=>0,'tot_amount'=>0,'tot_duty'=>0];

  // ---------------- KPI Stats for Dashboard ----------------
  // Today's orders
  $today_sql = "
    SELECT COUNT(*) as count, COALESCE(SUM({$orderAmountSql}),0) as revenue
    FROM make_order mo
    WHERE DATE(COALESCE(mo.service_date, mo.`date`)) = CURDATE()
    AND COALESCE(mo.status,'') <> 'cancelled'
  ";
  $st_today = $conn->query($today_sql);
  $today_stats = $st_today->fetch(PDO::FETCH_ASSOC) ?: ['count'=>0,'revenue'=>0];
  
  // This week's orders  
  $week_sql = "
    SELECT COUNT(*) as count, COALESCE(SUM({$orderAmountSql}),0) as revenue
    FROM make_order mo
    WHERE YEARWEEK(COALESCE(mo.service_date, mo.`date`), 1) = YEARWEEK(CURDATE(), 1)
    AND COALESCE(mo.status,'') <> 'cancelled'
  ";
  $st_week = $conn->query($week_sql);
  $week_stats = $st_week->fetch(PDO::FETCH_ASSOC) ?: ['count'=>0,'revenue'=>0];
  
  // Pending confirmations (draft or scheduled status)
  $pending_sql = "
    SELECT COUNT(*) as count
    FROM make_order mo
    WHERE COALESCE(mo.status,'') IN ('draft', 'scheduled')
    AND COALESCE(mo.service_date, mo.`date`) >= CURDATE()
  ";
  $st_pending = $conn->query($pending_sql);
  $pending_count = (int)$st_pending->fetchColumn();
  
  // Active workers (assigned to orders within filtered date range)
  $workers_where = ["COALESCE(mo.status,'') NOT IN ('cancelled', 'completed')"];
  $workers_params = [];
  
  // Apply same date filters as main query
  if ($date_from !== '' && $date_to !== '') {
    $workers_where[] = "(mo.service_date BETWEEN :wdf AND :wdt OR (mo.service_date IS NULL AND mo.`date` BETWEEN :wdf AND :wdt))";
    $workers_params[':wdf'] = $date_from;
    $workers_params[':wdt'] = $date_to;
  } elseif ($date !== '') {
    $workers_where[] = "(mo.service_date = :wd OR (mo.service_date IS NULL AND mo.`date` = :wd))";
    $workers_params[':wd'] = $date;
  } else {
    // No date filter applied - show today onwards as default
    $workers_where[] = "COALESCE(mo.service_date, mo.`date`) >= CURDATE()";
  }
  
  $workers_where_sql = implode(" AND ", $workers_where);
  
  $workers_sql = "
    SELECT COUNT(DISTINCT worker_id) as count
    FROM order_workers ow
    INNER JOIN make_order mo ON mo.id = ow.order_id
    WHERE $workers_where_sql
  ";
  try {
    $st_workers = $conn->prepare($workers_sql);
    $st_workers->execute($workers_params);
    $active_workers = (int)$st_workers->fetchColumn();
  } catch (\Throwable $e) {
    $active_workers = 0; // table might not exist
  }

  // ---------------- Count ----------------
  $sql_count = "SELECT COUNT(*) FROM make_order mo {$joinWorkerFilter} LEFT JOIN client c ON c.id = mo.client_id $where_sql_active";
  $st = $conn->prepare($sql_count);
  $st->execute($params);
  $total_rows  = (int)$st->fetchColumn();
  $total_pages = max(1, (int)ceil($total_rows / $limit));

  // ---------------- Paged rows ----------------
  $sql_rows = "
    SELECT
      mo.*,
      COALESCE(c.client_name, mo.client_name) AS client_name_display,
      i.id AS invoice_id,
      i.invoice_no,
      i.status AS invoice_status,
      i.total AS invoice_total
    FROM make_order mo
    {$joinWorkerFilter}
    LEFT JOIN client   c ON c.id = mo.client_id
    LEFT JOIN invoices i ON i.order_id = mo.id AND i.status NOT IN ('void', 'draft')
    $where_sql
    ORDER BY COALESCE(mo.service_date, mo.`date`) DESC, mo.id DESC
    LIMIT :lim OFFSET :off
  ";
  $st = $conn->prepare($sql_rows);
  foreach ($params as $k=>$v) $st->bindValue($k, $v);
  $st->bindValue(':lim', $limit, PDO::PARAM_INT);
  $st->bindValue(':off', $offset, PDO::PARAM_INT);
  $st->execute();
  $orders = $st->fetchAll(PDO::FETCH_ASSOC);

  // ---------------- Workers per order ----------------
  $order_ids = array_column($orders, 'id');
  $workers_by_order = [];
  if ($order_ids) {
    $in = implode(',', array_fill(0, count($order_ids), '?'));

    // nickname map (optional; ignore errors if table/cols differ)
    $worker_map = [];
    try {
      $wm = $conn->query("SELECT id, nickname FROM workers");
      while ($r = $wm->fetch(PDO::FETCH_ASSOC)) {
        $worker_map[(int)$r['id']] = $r['nickname'] ?: ('#'.$r['id']);
      }
    } catch (\Throwable $e) {
      // workers table not critical; leave empty
    }

    try {
      $q = $conn->prepare("SELECT order_id, worker_id FROM order_workers WHERE order_id IN ($in)");
      $q->execute($order_ids);
      while ($ow = $q->fetch(PDO::FETCH_ASSOC)) {
        $oid = (int)$ow['order_id'];
        $wid = (int)$ow['worker_id'];
        $workers_by_order[$oid][] = $worker_map[$wid] ?? ('#'.$wid);
      }
    } catch (\Throwable $e) {
      // if this join doesn't exist yet, just skip
    }
  }

} catch (\Throwable $e) {
  echo '<div class="alert alert-danger">Failed to load work orders: '
       . h($e->getMessage()) . '</div>';
  // stop rendering further if queries failed
  return;
}

$flashType = $_GET['flashType'] ?? '';
$flash     = $_GET['flash'] ?? '';
if ($flash !== '') {
  $map = ['success'=>'success','error'=>'danger','warn'=>'warning','info'=>'info'];
  $cls = $map[$flashType] ?? 'info';
  echo '<div class="alert alert-'.$cls.' alert-dismissible fade show" role="alert">'
     . h($flash)
     . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
     . '</div>';
}
?>

<!-- KPI Dashboard -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="kpi-card kpi-primary">
      <div class="kpi-icon"><i class="bi bi-calendar-check"></i></div>
      <div class="kpi-content">
        <div class="kpi-label">Today's Orders</div>
        <div class="kpi-value"><?= (int)$today_stats['count'] ?></div>
        <div class="kpi-subtitle">AED <?= money($today_stats['revenue']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="kpi-card kpi-success">
      <div class="kpi-icon"><i class="bi bi-graph-up-arrow"></i></div>
      <div class="kpi-content">
        <div class="kpi-label">This Week</div>
        <div class="kpi-value"><?= (int)$week_stats['count'] ?></div>
        <div class="kpi-subtitle">AED <?= money($week_stats['revenue']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="kpi-card kpi-warning">
      <div class="kpi-icon"><i class="bi bi-clock-history"></i></div>
      <div class="kpi-content">
        <div class="kpi-label">Pending</div>
        <div class="kpi-value"><?= $pending_count ?></div>
        <div class="kpi-subtitle">Need Confirmation</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="kpi-card kpi-info">
      <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
      <div class="kpi-content">
        <div class="kpi-label">Active Workers</div>
        <div class="kpi-value"><?= $active_workers ?></div>
        <div class="kpi-subtitle"><?= ($date_from && $date_to) ? 'In Date Range' : (($date) ? 'For Selected Date' : 'Today Onwards') ?></div>
      </div>
    </div>
  </div>
</div>

<style>
.kpi-card {
  background: #fff;
  border-radius: 12px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  transition: all 0.3s ease;
  border-left: 4px solid;
}
.kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}
.kpi-primary { border-left-color: #0d6efd; }
.kpi-success { border-left-color: #198754; }
.kpi-warning { border-left-color: #ffc107; }
.kpi-info { border-left-color: #0dcaf0; }

.kpi-icon {
  width: 56px;
  height: 56px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  flex-shrink: 0;
}
.kpi-primary .kpi-icon { background: #0d6efd15; color: #0d6efd; }
.kpi-success .kpi-icon { background: #19875415; color: #198754; }
.kpi-warning .kpi-icon { background: #ffc10715; color: #ffc107; }
.kpi-info .kpi-icon { background: #0dcaf015; color: #0dcaf0; }

.kpi-content {
  flex: 1;
}
.kpi-label {
  font-size: 0.85rem;
  color: #6c757d;
  font-weight: 500;
  margin-bottom: 4px;
}
.kpi-value {
  font-size: 2rem;
  font-weight: 700;
  color: #212529;
  line-height: 1;
  margin-bottom: 4px;
}
.kpi-subtitle {
  font-size: 0.875rem;
  color: #6c757d;
}

/* Table Column Optimization */
#orders-table td {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 0;
}
#orders-table td:nth-child(10) {
  /* Remark column - allow text wrapping */
  white-space: normal;
  word-wrap: break-word;
  font-size: 0.9rem;
}
#orders-table td:nth-child(3),
#orders-table td:nth-child(5),
#orders-table td:nth-child(6) {
  /* Client, Service Date, Time - allow overflow visible */
  white-space: normal;
}

@media (max-width: 768px) {
  .kpi-card { padding: 16px; }
  .kpi-icon { width: 48px; height: 48px; font-size: 20px; }
  .kpi-value { font-size: 1.5rem; }
}
</style>

<!-- Filters -->
<div class="mb-3">
  <form class="row g-2" method="get" action="" id="searchForm">
    <input type="hidden" name="tab" value="workorder">
    <div class="col-lg-3 col-md-6">
      <input type="text" name="search" class="form-control" placeholder="Search by client, worker, remark…" value="<?=h($search)?>">
    </div>
    <div class="col-lg-2 col-md-3">
      <input type="date" name="date_from" class="form-control" placeholder="From" value="<?=h($date_from)?>">
    </div>
    <div class="col-lg-2 col-md-3">
      <input type="date" name="date_to" class="form-control" placeholder="To" value="<?=h($date_to)?>">
    </div>
    <div class="col-auto">
      <button class="btn btn-primary" type="submit" title="Filter"><i class="bi bi-search"></i> Search</button>
      <a href="javascript:void(0)" class="btn btn-secondary" title="Reset to Today" data-reset-filters="true"><i class="bi bi-arrow-clockwise"></i></a>
      <button type="button" class="btn btn-outline-secondary" onclick="toggleAdvancedFilters()">
        <i class="bi bi-sliders"></i> Advanced
      </button>
      <div class="btn-group" role="group" aria-label="Export work orders">
        <button type="button" class="btn btn-outline-success" id="wo-export-csv" title="Export filtered orders as CSV">
          <i class="bi bi-filetype-csv"></i> CSV
        </button>
        <button type="button" class="btn btn-outline-success" id="wo-export-excel" title="Export filtered orders as Excel">
          <i class="bi bi-file-earmark-excel"></i> Excel
        </button>
      </div>
    </div>
    
    <!-- Advanced Filters (Initially Hidden) -->
    <div id="advanced-filters" class="col-12 mt-2" style="display:<?= ($status_filter || $worker_filter || $invoice_filter) ? 'block' : 'none' ?>">
      <div class="card">
        <div class="card-body">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label small">Worker</label>
              <input type="text" name="worker_filter" class="form-control form-control-sm" placeholder="Worker name" value="<?=h($worker_filter)?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small">Invoice Status</label>
              <select name="invoice_filter" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="invoiced" <?= $invoice_filter==='invoiced'?'selected':'' ?>>Invoiced</option>
                <option value="not_invoiced" <?= $invoice_filter==='not_invoiced'?'selected':'' ?>>Not Invoiced</option>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- Ops status filter (simplified) -->
<div class="mb-2">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <small class="text-muted fw-bold">View:</small>
    <?php
      $opsStatuses = [
        '' => ['All', 'secondary'],
        'open' => ['Open', 'info'],
        'completed' => ['Completed', 'success'],
        'cancelled' => ['Cancelled', 'secondary'],
        'finalized' => ['Finalized', 'dark'],
      ];
      // How many completed orders still need Finalize — finished app jobs land
      // here and are only invoiced once someone presses it.
      $awaitingFinalizeCount = 0;
      try {
        $afStmt = $conn->prepare("
          SELECT COUNT(*) FROM make_order mo
          WHERE mo.company_id = ?
            AND COALESCE(mo.is_finalized,0) = 0
            AND COALESCE(mo.status,'') = 'completed'
        ");
        $afStmt->execute([$currentCompanyId]);
        $awaitingFinalizeCount = (int)$afStmt->fetchColumn();
      } catch (Throwable $e) {
        $awaitingFinalizeCount = 0;
      }
      foreach ($opsStatuses as $key => $data):
        $active = ($ops_filter === $key) ? ' active' : '';
    ?>
      <a href="javascript:void(0)" class="status-chip status-chip-<?= $data[1] ?><?= $active ?>" data-ops-filter="<?= h($key) ?>">
        <?= h($data[0]) ?>
        <?php if ($key === 'completed' && $awaitingFinalizeCount > 0): ?>
          <span class="badge rounded-pill text-bg-warning ms-1" title="Completed, not finalized yet"><?= $awaitingFinalizeCount ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Legacy status filter chips (advanced) -->
<div class="mb-3" id="legacy-status-chips" style="display:none">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <small class="text-muted fw-bold">Legacy status:</small>
    <?php
      $statuses = [
        '' => ['All', 'secondary'],
        'draft' => ['Draft', 'warning'],
        'scheduled' => ['Scheduled', 'info'],
        'confirmed' => ['Confirmed', 'secondary'],
        'in_progress' => ['In Progress', 'primary'],
        'completed' => ['Completed', 'success'],
        'invoiced' => ['Invoiced', 'dark'],
        'cancelled' => ['Cancelled', 'secondary']
      ];
      foreach ($statuses as $key => $data):
        $active = ($status_filter === $key) ? ' active' : '';
    ?>
      <a href="javascript:void(0)" class="status-chip status-chip-<?= $data[1] ?><?= $active ?>" data-status-filter="<?= h($key) ?>">
        <?= h($data[0]) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<style>
.status-chip {
  display: inline-flex;
  align-items: center;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 0.875rem;
  font-weight: 500;
  text-decoration: none;
  transition: all 0.2s ease;
  border: 1px solid transparent;
  background: #f8f9fa;
  color: #6c757d;
}
.status-chip:hover {
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.status-chip.active {
  font-weight: 600;
  box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.status-chip-secondary.active { background: #6c757d; color: #fff; border-color: #6c757d; }
.status-chip-warning.active { background: #ffc107; color: #000; border-color: #ffc107; }
.status-chip-info.active { background: #0dcaf0; color: #000; border-color: #0dcaf0; }
.status-chip-primary.active { background: #0d6efd; color: #fff; border-color: #0d6efd; }
.status-chip-success.active { background: #198754; color: #fff; border-color: #198754; }
.status-chip-dark.active { background: #212529; color: #fff; border-color: #212529; }

/* Work order table readability */
#orders-table {
  font-size: 0.92rem;
}
#orders-table th {
  white-space: nowrap;
  vertical-align: middle;
}
#orders-table td {
  vertical-align: middle;
}
#orders-table .wo-status-cell {
  min-width: 200px;
}
#orders-table .wo-status-cell .badge {
  font-size: 0.75rem;
  letter-spacing: 0.02em;
}
#orders-table .wo-detail-sub {
  font-size: 0.8rem;
  color: #6c757d;
}
#orders-table .btn-wo-finalize {
  font-size: 0.78rem;
  font-weight: 600;
}

/* Fix dropdown z-index issue in table */
#orders-table tbody tr {
  position: relative;
}

#orders-table .btn-group {
  position: static;
}

#orders-table .dropdown-menu {
  z-index: 1050 !important;
  position: absolute !important;
}

/* When dropdown is open, give the row higher z-index */
#orders-table tbody tr:has(.dropdown.show) {
  position: relative;
  z-index: 1040;
}

/* Alternative for browsers that don't support :has() */
#orders-table tbody tr.dropdown-open {
  position: relative;
  z-index: 1040;
}
</style>

<script>
function toggleAdvancedFilters() {
  const filters = document.getElementById('advanced-filters');
  const legacy = document.getElementById('legacy-status-chips');
  const show = filters.style.display === 'none' ? 'block' : 'none';
  filters.style.display = show;
  if (legacy) legacy.style.display = show;
}
</script>

<form id="workorder-actions-form" method="post">
  <input type="hidden" name="selected_id" id="selected_id" value="">
  <div class="table-responsive mb-3">
    <table class="table table-bordered table-hover align-middle" id="orders-table">
      <thead class="table-dark">
        <tr>
          <th style="min-width:40px">
            <input type="checkbox" id="select-all-orders" class="form-check-input">
          </th>
          <th style="min-width:60px">ID</th>
          <th style="min-width:180px">Client</th>
          <th style="min-width:140px">Workers</th>
          <th style="min-width:120px">Service Date</th>
          <th style="min-width:130px">Time</th>
          <th style="min-width:70px">Hours</th>
          <th style="min-width:110px">Total (incl. VAT)</th>
          <th style="min-width:220px">Status / Workflow</th>
          <th style="min-width:140px">Remark</th>
          <th style="min-width:100px">Driver</th>
          <th style="min-width:150px">Invoice</th>
          <th style="min-width:120px">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($orders): foreach ($orders as $row): ?>
        <?php
          $oid = (int)$row['id'];
          $svcDate = $row['service_date'] ?: $row['date'];
          $worker_names = $workers_by_order[$oid] ?? [];
          $workers_str  = $worker_names ? implode(', ', array_map(function($x){ return h($x); }, $worker_names))
                                        : h($row['worker_name'] ?? '');
          $is_cancelled = ($row['status'] === 'cancelled');
          $is_finalized_row = wo_column_exists($conn, 'is_finalized') && (int)($row['is_finalized'] ?? 0) === 1;
          $ops_label = wo_column_exists($conn, 'ops_status')
            ? strtoupper((string)($row['ops_status'] ?? wo_map_status_to_ops((string)$row['status'])))
            : strtoupper((string)($row['status'] ?? ''));
          [$finalizeReady, $finalizeReason] = wo_can_finalize($conn, $row);
          $legacy_status = strtolower((string)($row['status'] ?? ''));
          $display_legacy_status = wo_display_workflow_status($row);
          $is_ops_locked_row = $is_finalized_row;
          $is_financial_locked_row = wo_financial_is_locked($conn, $oid);
          [$canDirectCancelRow, $directCancelBlockReason] = wo_ops_can_direct_cancel($conn, $oid);
          $ops_lock_reason = $is_ops_locked_row ? wo_ops_lock_reason($conn, $row) : '';
          $financial_lock_reason = (!$is_ops_locked_row && $is_financial_locked_row)
            ? wo_financial_lock_reason($conn, $oid)
            : '';
          $showFinalizeBtn = !$is_ops_locked_row && !$is_cancelled
            && $legacy_status === 'completed';
          $showStatusDropdown = !$is_cancelled && !$is_ops_locked_row;
          $pendingAdjForCancel = sm_pending_adjustment_for_order($conn, $oid);
          $canRequestCancelRow = !$is_cancelled
            && $canRequestAdjUser
            && !$pendingAdjForCancel
            && ($is_finalized_row || $is_financial_locked_row);
          $cancelAction = 'none';
          if ($is_cancelled) {
            $cancelAction = 'none';
          } elseif ($pendingAdjForCancel) {
            $cancelAction = 'pending';
          } elseif ($canDirectCancelRow) {
            $cancelAction = 'direct';
          } elseif ($canRequestCancelRow) {
            $cancelAction = 'request';
          }
          $legacy_badge = str_replace('_', ' ', $display_legacy_status);
          $worker_count = max(1, count($worker_names));
          $order_grand_total = wo_order_customer_total($row);
          if ($worker_filter_id) {
            $display_hours = ($row['net_hours'] !== null && $row['net_hours'] !== '')
              ? (float)$row['net_hours']
              : ((float)($row['hours'] ?? 0) / $worker_count);
            $display_amount = ($row['net_amount'] !== null && $row['net_amount'] !== '')
              ? (float)$row['net_amount']
              : ($order_grand_total / $worker_count);
          } else {
            $display_hours = (float)($row['hours'] ?? 0);
            $display_amount = $order_grand_total;
          }
          $woStoredGrand = round((float)($row['frozen_grand_total'] ?? $row['grand_total'] ?? 0), 2);
          $invoiceTotalRow = round((float)($row['invoice_total'] ?? 0), 2);
          $woInvoiceMismatchRow = $is_finalized_row && !empty($row['invoice_id'])
            && $invoiceTotalRow > 0 && abs($woStoredGrand - $invoiceTotalRow) > 0.02;
          $pendingAdjRow = $woInvoiceMismatchRow ? sm_pending_adjustment_for_order($conn, $oid) : null;
          $showSyncFromInvoice = $woInvoiceMismatchRow && $canFinalizeUser && !$pendingAdjRow;
        ?>
        <tr class="<?= $is_cancelled ? 'opacity-50' : '' ?> order-row"
            data-row-id="<?=$oid?>"
            data-ops-locked="<?= $is_ops_locked_row ? '1' : '0' ?>"
            data-can-direct-cancel="<?= (!$is_cancelled && $canDirectCancelRow) ? '1' : '0' ?>"
            data-cancel-block="<?= h($directCancelBlockReason) ?>"
            data-client-email="<?=h($row['email_o'] ?? '')?>"
            data-client-mobile="<?=h($row['mobile_num_o'] ?? '')?>"
            data-address="<?=h($row['address_o'] ?? '')?>">
          <td>
            <input type="checkbox" class="form-check-input order-checkbox" value="<?=$oid?>">
          </td>
          <td><strong>#<?=h($row['id'])?></strong></td>
          <td>
            <div class="fw-semibold"><?=h($row['client_name_display'] ?? '')?></div>
          </td>
          <td><?=$workers_str?></td>
          <td><?=h($svcDate)?></td>
          <td><span class="text-nowrap"><?=h($row['time'] ?? '')?></span></td>
          <td class="text-end"><?=h($worker_filter_id ? number_format($display_hours, 2) : $row['hours'])?></td>
          <td class="text-end fw-semibold">
            <?=money($display_amount)?>
            <?php if ($woInvoiceMismatchRow): ?>
              <div class="small text-warning" title="Invoice shows AED <?= money($invoiceTotalRow) ?>">≠ invoice</div>
            <?php endif; ?>
          </td>
          <td>
            <div class="wo-status-cell">
              <div class="d-flex flex-wrap gap-1 mb-1">
                <span class="badge <?= ops_badge_class($ops_label) ?>"><?= h($ops_label) ?></span>
                <?php if ($is_finalized_row): ?>
                  <span class="badge text-bg-warning text-dark">FINALIZED</span>
                <?php endif; ?>
                <?php if (!$is_cancelled): ?>
                  <span class="badge text-bg-<?= status_badge_class($display_legacy_status) ?>"><?= h(ucwords($legacy_badge)) ?></span>
                <?php endif; ?>
              </div>
              <?php if (!$is_cancelled && $showStatusDropdown): ?>
                <select class="form-select form-select-sm status-dropdown" data-order-id="<?=$oid?>" data-original="<?=h($row['status'])?>">
                  <option value="draft" <?= $row['status']==='draft'?'selected':'' ?>>Draft</option>
                  <option value="scheduled" <?= $row['status']==='scheduled'?'selected':'' ?>>Scheduled</option>
                  <option value="confirmed" <?= $row['status']==='confirmed'?'selected':'' ?>>Confirmed</option>
                  <option value="in_progress" <?= $row['status']==='in_progress'?'selected':'' ?>>In Progress</option>
                  <option value="completed" <?= $row['status']==='completed'?'selected':'' ?>>Completed</option>
                </select>
                <?php if ($is_financial_locked_row && $financial_lock_reason !== ''): ?>
                  <div class="wo-detail-sub mt-1 text-warning"><?= h($financial_lock_reason) ?></div>
                <?php endif; ?>
                <?php if ($showFinalizeBtn): ?>
                  <button type="button"
                    class="btn btn-sm btn-warning mt-1 w-100 btn-wo-finalize"
                    data-order-id="<?=$oid?>"
                    <?= $finalizeReady ? '' : 'disabled title="'.h($finalizeReason).'"' ?>>
                    Finalize &amp; Invoice
                  </button>
                  <?php if (!$finalizeReady): ?>
                    <div class="wo-detail-sub mt-1"><?= h($finalizeReason) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              <?php elseif (!$is_cancelled && $is_ops_locked_row): ?>
                <?php if ($woInvoiceMismatchRow): ?>
                  <div class="alert alert-warning py-1 px-2 small mb-1 mt-1">
                    <div><strong>Total mismatch</strong></div>
                    <div>WO: AED <?= money($woStoredGrand) ?> · Invoice: AED <?= money($invoiceTotalRow) ?></div>
                    <?php if ($showSyncFromInvoice): ?>
                      <button type="button" class="btn btn-sm btn-outline-warning w-100 mt-1 btn-wo-sync-invoice" data-order-id="<?= $oid ?>">
                        Sync WO from invoice
                      </button>
                      <div class="wo-detail-sub mt-1">One-time fix — does not change invoice or add AR.</div>
                    <?php elseif ($pendingAdjRow): ?>
                      <div class="wo-detail-sub mt-1">Adjustment #<?= (int)$pendingAdjRow['id'] ?> pending.</div>
                    <?php else: ?>
                      <div class="wo-detail-sub mt-1">Ask Admin/Accountant to sync from invoice.</div>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="wo-detail-sub mt-1"><?= h($ops_lock_reason !== '' ? $ops_lock_reason : 'Finalized — locked. Use Accounts for adjustments.') ?></div>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge text-bg-secondary">Cancelled</span>
                <?php if (!empty($row['cancel_reason'])): ?>
                  <small class="text-muted d-block mt-1">Reason: <?=h($row['cancel_reason'])?></small>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
          <td><span class="text-break"><?=h($row['remark'] ?? '')?></span></td>
          <td><?=h($row['driver_name'] ?? '')?></td>
          <td>
            <?php if (!empty($row['invoice_id'])): ?>
              <span class="badge text-bg-secondary"><?=h($row['invoice_status'])?></span>
              <a href="accounts/invoice_view.php?id=<?= (int)$row['invoice_id']?>" class="ms-1">#<?=h($row['invoice_no'])?></a>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex flex-wrap gap-1">
              <a href="operation/worker_availability?day=<?= urlencode((string)$svcDate) ?>" class="btn btn-outline-primary btn-sm" title="Open in calendar">
                <i class="bi bi-calendar3"></i>
              </a>
              <?php if (!empty($row['mobile_num_o']) || !empty($row['email_o'])): ?>
                <div class="btn-group btn-group-sm" role="group">
                  <button type="button" class="btn btn-outline-info dropdown-toggle" data-bs-toggle="dropdown" title="Contact">
                    <i class="bi bi-telephone"></i>
                  </button>
                  <ul class="dropdown-menu">
                    <?php if (!empty($row['mobile_num_o'])): ?>
                      <li><a class="dropdown-item" href="tel:<?=h($row['mobile_num_o'])?>"><i class="bi bi-telephone me-2"></i>Call</a></li>
                      <li><a class="dropdown-item" href="sms:<?=h($row['mobile_num_o'])?>"><i class="bi bi-chat-dots me-2"></i>SMS</a></li>
                    <?php endif; ?>
                    <?php if (!empty($row['email_o'])): ?>
                      <li><a class="dropdown-item" href="mailto:<?=h($row['email_o'])?>"><i class="bi bi-envelope me-2"></i>Email</a></li>
                    <?php endif; ?>
                  </ul>
                </div>
              <?php endif; ?>
              <?php if ($cancelAction === 'direct'): ?>
                <button type="button"
                  class="btn btn-outline-danger btn-sm btn-wo-cancel"
                  title="Cancel job"
                  data-cancel-mode="direct"
                  data-order-id="<?= $oid ?>"
                  data-order-label="#<?= $oid ?>">
                  <i class="bi bi-x-circle"></i>
                </button>
              <?php elseif ($cancelAction === 'request'): ?>
                <button type="button"
                  class="btn btn-outline-danger btn-sm btn-wo-cancel"
                  title="Request cancellation (Accounts)"
                  data-cancel-mode="request"
                  data-order-id="<?= $oid ?>"
                  data-order-label="#<?= $oid ?>"
                  data-frozen-grand="<?= h(number_format((float)($row['frozen_grand_total'] ?? $row['grand_total'] ?? 0), 2, '.', '')) ?>">
                  <i class="bi bi-x-circle"></i>
                </button>
              <?php elseif ($cancelAction === 'pending'): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" disabled
                  title="Adjustment #<?= (int)$pendingAdjForCancel['id'] ?> pending — review in Accounts">
                  <i class="bi bi-hourglass-split"></i>
                </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="13" class="text-center">No work orders found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</form>

<!-- Bulk Actions Bar -->
<div id="bulk-actions-bar" class="alert alert-info d-none align-items-center" style="position:sticky;top:0;z-index:100">
  <div class="d-flex justify-content-between align-items-center w-100">
    <div>
      <strong id="selected-count">0</strong> orders selected
    </div>
    <div class="btn-group" role="group">
      <select id="bulk-status-select" class="form-select form-select-sm me-2" style="width:auto">
        <option value="">Change Status...</option>
        <option value="draft">Draft</option>
        <option value="scheduled">Scheduled</option>
        <option value="confirmed">Confirmed</option>
        <option value="in_progress">In Progress</option>
        <option value="completed">Completed</option>
      </select>
      <button type="button" class="btn btn-sm btn-primary" onclick="applyBulkStatus()">
        <i class="bi bi-check-circle"></i> Apply Status
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">
        <i class="bi bi-x-circle"></i> Clear
      </button>
    </div>
  </div>
</div>

<!-- Pagination -->
<?php
  // show pagination only when we actually have rows or more than 1 page
  if ($total_pages > 1):
?>
<nav>
  <ul class="pagination">
    <?php if ($page > 1): ?>
      <li class="page-item">
        <a class="page-link" href="javascript:void(0)" data-page="<?= $page-1 ?>">&laquo; Prev</a>
      </li>
    <?php endif; ?>

    <?php
      $range = 2; $dots = false;
      for ($p=1; $p<=$total_pages; $p++) {
        if ($p<=2 || $p>$total_pages-2 || ($p>=$page-$range && $p<=$page+$range)) {
          $active = ($p==$page)?'active':'';
          echo '<li class="page-item '.$active.'"><a class="page-link" href="javascript:void(0)" data-page="'.$p.'">'.$p.'</a></li>';
          $dots = true;
        } elseif ($dots) {
          echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          $dots = false;
        }
      }
    ?>

    <?php if ($page < $total_pages): ?>
      <li class="page-item">
        <a class="page-link" href="javascript:void(0)" data-page="<?= $page+1 ?>">Next &raquo;</a>
      </li>
    <?php endif; ?>
  </ul>
</nav>
<?php endif; ?>

<!-- Totals -->
<?php
  $hoursLabel = $worker_filter_id ? 'Worker Hours' : 'Total Hours';
  $amountLabel = $worker_filter_id ? 'Worker Amount (incl. VAT)' : 'Total Amount (incl. VAT)';
?>
<div class="d-flex flex-wrap align-items-center gap-3 justify-content-between border rounded-3 p-3 mb-3 bg-light">
  <div>
    <span class="fw-bold"><?= h($hoursLabel) ?>:</span>
    <input type="text" class="form-control d-inline-block w-auto" value="<?=money($tot['tot_hours'])?>" readonly style="max-width:90px;">
    <span class="fw-bold ms-3"><?= h($amountLabel) ?>:</span>
    <input type="text" class="form-control d-inline-block w-auto" value="<?=money($tot['tot_amount'])?>" readonly style="max-width:120px;">
    <span class="fw-bold ms-3">Total Duty:</span>
    <input type="text" class="form-control d-inline-block w-auto" value="<?= (int)$tot['tot_duty'] ?>" readonly style="max-width:80px;">
  </div>
  <div class="text-end d-flex flex-wrap gap-2 justify-content-end">
    <a href="operation/print_report" class="btn btn-outline-secondary" data-print-filters="true">
      <i class="bi bi-printer"></i> Print
    </a>
    <div class="btn-group" role="group" aria-label="Export work orders">
      <button type="button" class="btn btn-outline-success" data-wo-export="csv">
        <i class="bi bi-filetype-csv"></i> CSV
      </button>
      <button type="button" class="btn btn-outline-success" data-wo-export="excel">
        <i class="bi bi-file-earmark-excel"></i> Excel
      </button>
    </div>
  </div>
</div>

<!-- Direct cancel modal (no invoice accounting activity) -->
<div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="operation/order_cancel.php" id="wo-direct-cancel-form">
      <div class="modal-header">
        <h5 class="modal-title">Cancel Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="cancel-order-id">
        <div class="mb-2">
          <label class="form-label">Category *</label>
          <select class="form-select" name="cancellation_category" id="cancel-category" required>
            <option value="">— Select —</option>
            <?php foreach ($woCancelCategories as $cat): ?>
              <option value="<?= h($cat) ?>"><?= h($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Details *</label>
          <textarea class="form-control" name="cancellation_details" id="cancel-details" rows="3" required
            placeholder="Why is this job being cancelled?"></textarea>
        </div>
        <div class="alert alert-warning small mb-0">
          This cancels the work order directly. Use only when there is <strong>no issued/paid invoice</strong>.
        </div>
        <div id="cancel-direct-error" class="text-danger small mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-danger">Confirm Cancel</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

<!-- Request cancellation via Accounts (issued / finalized / paid) -->
<div class="modal fade" id="woRequestCancelModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="wo-req-cancel-title">Request Cancellation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="wo-req-cancel-order-id" value="">
        <div class="alert alert-info small">
          This job has invoice/accounting activity (or is finalized). Operations cannot cancel it directly.
          Submitting sends a <strong>cancellation request</strong> to Accounts. On approval they will
          <strong>void</strong> an unpaid invoice or issue a <strong>credit note</strong> if paid/allocated.
        </div>
        <p class="small text-muted mb-2">Current total: <strong id="wo-req-cancel-frozen">AED 0.00</strong></p>
        <div class="mb-2">
          <label class="form-label">Category *</label>
          <select class="form-select" id="wo-req-cancel-category" required>
            <option value="">— Select —</option>
            <?php foreach ($woCancelCategories as $cat): ?>
              <option value="<?= h($cat) ?>"><?= h($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Details *</label>
          <textarea class="form-control" id="wo-req-cancel-details" rows="3" required
            placeholder="Why is this job being cancelled?"></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label">Extra notes for Accounts <span class="text-muted">(optional)</span></label>
          <textarea class="form-control" id="wo-req-cancel-notes" rows="2"
            placeholder="Anything else the accountant should know"></textarea>
        </div>
        <div id="wo-req-cancel-error" class="text-danger small" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-danger" id="wo-req-cancel-submit">Submit to Accounts</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
const WO_CSRF = <?= json_encode(csrf_token()) ?>;

// ========== EXISTING FUNCTIONALITY (PRESERVED) ==========
let selectedRowId = null;
document.querySelectorAll('#orders-table tbody tr.order-row').forEach(function(row){
  row.addEventListener('click', function(e){
    // Don't trigger if clicking on checkbox, select, button, or link
    if (e.target.closest('input, select, button, a, .btn-group')) return;
    
    document.querySelectorAll('#orders-table tbody tr').forEach(r => r.classList.remove('table-primary'));
    this.classList.add('table-primary');
    selectedRowId = this.getAttribute('data-row-id');
    document.getElementById('selected_id').value = selectedRowId;
    const editBtn = document.getElementById('edit-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    if (editBtn) editBtn.disabled = false;
    if (cancelBtn) cancelBtn.disabled = false;
  });
});

document.getElementById('edit-btn')?.addEventListener('click', function(){
  if (selectedRowId) window.location.href = "operation/order_edit?id=" + encodeURIComponent(selectedRowId);
});

function openWoDirectCancelModal(orderId, orderLabel) {
  const form = document.getElementById('wo-direct-cancel-form');
  const err = document.getElementById('cancel-direct-error');
  if (err) { err.style.display = 'none'; err.textContent = ''; }
  document.getElementById('cancel-order-id').value = orderId;
  document.getElementById('cancel-category').value = '';
  document.getElementById('cancel-details').value = '';
  const title = document.querySelector('#cancelOrderModal .modal-title');
  if (title) title.textContent = 'Cancel Order ' + (orderLabel || ('#' + orderId));
  bootstrap.Modal.getOrCreateInstance(document.getElementById('cancelOrderModal')).show();
}

function openWoRequestCancelModal(orderId, orderLabel, frozenGrand) {
  document.getElementById('wo-req-cancel-order-id').value = orderId;
  document.getElementById('wo-req-cancel-category').value = '';
  document.getElementById('wo-req-cancel-details').value = '';
  document.getElementById('wo-req-cancel-notes').value = '';
  const err = document.getElementById('wo-req-cancel-error');
  if (err) { err.style.display = 'none'; err.textContent = ''; }
  document.getElementById('wo-req-cancel-title').textContent = 'Request Cancellation — ' + (orderLabel || ('#' + orderId));
  const g = parseFloat(frozenGrand || '0') || 0;
  document.getElementById('wo-req-cancel-frozen').textContent = 'AED ' + g.toFixed(2);
  bootstrap.Modal.getOrCreateInstance(document.getElementById('woRequestCancelModal')).show();
}

document.querySelectorAll('.btn-wo-cancel').forEach(function(btn){
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    const mode = btn.dataset.cancelMode;
    const orderId = btn.dataset.orderId;
    const label = btn.dataset.orderLabel || ('#' + orderId);
    if (mode === 'direct') {
      openWoDirectCancelModal(orderId, label);
    } else if (mode === 'request') {
      openWoRequestCancelModal(orderId, label, btn.dataset.frozenGrand || '0');
    }
  });
});

document.getElementById('cancel-btn')?.addEventListener('click', function(){
  if (!selectedRowId) return;
  const row = document.querySelector('#orders-table tr.table-primary');
  const rowBtn = row?.querySelector('.btn-wo-cancel');
  if (rowBtn) {
    rowBtn.click();
    return;
  }
  if (row?.dataset?.opsLocked === '1' || row?.dataset?.canDirectCancel !== '1') {
    alert(row?.dataset?.cancelBlock || 'Use the Cancellation button on the row, or Accounts → Adjustment Requests.');
    return;
  }
  openWoDirectCancelModal(selectedRowId, '#' + selectedRowId);
});

document.getElementById('wo-req-cancel-submit')?.addEventListener('click', async function(){
  const orderId = document.getElementById('wo-req-cancel-order-id').value;
  const category = (document.getElementById('wo-req-cancel-category').value || '').trim();
  const details = (document.getElementById('wo-req-cancel-details').value || '').trim();
  const extraNotes = (document.getElementById('wo-req-cancel-notes').value || '').trim();
  const err = document.getElementById('wo-req-cancel-error');
  if (!category) {
    err.textContent = 'Please select a category (Cleaner, Driver, Management, or Client).';
    err.style.display = '';
    return;
  }
  if (!details) {
    err.textContent = 'Details are required.';
    err.style.display = '';
    return;
  }
  // Same summary format as direct cancel — visible to Accounts on the adjustment request.
  const reason = (category + ': ' + details).substring(0, 500);
  const notes = extraNotes
    ? ('Cancellation category: ' + category + '\nDetails: ' + details + '\n\n' + extraNotes)
    : ('Cancellation category: ' + category + '\nDetails: ' + details);
  const btn = this;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('order_id', orderId);
  fd.append('request_type', 'cancellation');
  fd.append('reason', reason);
  fd.append('notes', notes);
  fd.append('_csrf', WO_CSRF);
  try {
    const res = await fetch('operation/ajax_request_adjustment.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json();
    if (data.success) {
      bootstrap.Modal.getInstance(document.getElementById('woRequestCancelModal'))?.hide();
      alert(data.message || 'Cancellation request submitted to Accounts.');
      location.reload();
    } else {
      err.textContent = data.message || 'Request failed';
      err.style.display = '';
      btn.disabled = false;
    }
  } catch (e) {
    err.textContent = 'Request failed';
    err.style.display = '';
    btn.disabled = false;
  }
});

// ========== NEW FEATURES ==========

// 1. INLINE STATUS CHANGE
document.querySelectorAll('.status-dropdown').forEach(dropdown => {
  dropdown.addEventListener('change', function(e){
    e.stopPropagation();
    const orderId = this.getAttribute('data-order-id');
    const newStatus = this.value;
    const originalStatus = this.dataset.original || this.value;
    this.dataset.original = originalStatus;
    
    if (confirm(`Change order #${orderId} status to "${newStatus}"?`)) {
      updateOrderStatus(orderId, newStatus, this);
    } else {
      this.value = originalStatus;
    }
  });
});

document.querySelectorAll('.btn-wo-finalize').forEach(btn => {
  btn.addEventListener('click', async function(e) {
    e.stopPropagation();
    const orderId = this.getAttribute('data-order-id');
    if (!orderId) return;
    if (!confirm('Finalize work order #' + orderId + '? This will lock financial fields and create/post the invoice.')) return;
    this.disabled = true;
    const fd = new FormData();
    fd.append('order_id', orderId);
    fd.append('_csrf', WO_CSRF);
    try {
      const res = await fetch('operation/ajax_finalize_order.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.success) {
        showToast('Work order finalized — invoice created.', 'success');
        setTimeout(() => location.reload(), 800);
      } else {
        alert(data.message || 'Finalize failed');
        this.disabled = false;
      }
    } catch (err) {
      alert('Finalize request failed');
      this.disabled = false;
    }
  });
});

document.querySelectorAll('.btn-wo-sync-invoice').forEach(btn => {
  btn.addEventListener('click', async function(e) {
    e.stopPropagation();
    const orderId = this.getAttribute('data-order-id');
    if (!orderId) return;
    if (!confirm('Sync work order #' + orderId + ' totals from its invoice?\n\nThis updates the WO row only. The invoice and GL are not changed.')) return;
    this.disabled = true;
    const fd = new FormData();
    fd.append('order_id', orderId);
    fd.append('_csrf', WO_CSRF);
    try {
      const res = await fetch('operation/ajax_sync_wo_invoice_totals.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.success) {
        showToast(data.message || 'Work order synced.', 'success');
        setTimeout(() => location.reload(), 800);
      } else {
        alert(data.message || 'Sync failed');
        this.disabled = false;
      }
    } catch (err) {
      alert('Sync request failed');
      this.disabled = false;
    }
  });
});

function updateOrderStatus(orderId, newStatus, dropdown) {
  fetch('operation/ajax_update_order_status.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `order_id=${orderId}&status=${newStatus}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      dropdown.dataset.original = newStatus;
      showToast('Status updated successfully!', 'success');
    } else {
      alert('Error: ' + (data.error || 'Failed to update status'));
      dropdown.value = dropdown.dataset.original;
    }
  })
  .catch(err => {
    alert('Network error: ' + err.message);
    dropdown.value = dropdown.dataset.original;
  });
}

// 2. BULK SELECTION
const selectAllCheckbox = document.getElementById('select-all-orders');
const orderCheckboxes = document.querySelectorAll('.order-checkbox');
const bulkActionsBar = document.getElementById('bulk-actions-bar');
const selectedCountSpan = document.getElementById('selected-count');

selectAllCheckbox?.addEventListener('change', function(){
  orderCheckboxes.forEach(cb => cb.checked = this.checked);
  updateBulkActionsBar();
});

orderCheckboxes.forEach(cb => {
  cb.addEventListener('change', function(e){
    e.stopPropagation();
    updateBulkActionsBar();
  });
});

function updateBulkActionsBar() {
  const selected = Array.from(orderCheckboxes).filter(cb => cb.checked);
  selectedCountSpan.textContent = selected.length;
  
  if (selected.length > 0) {
    bulkActionsBar.classList.remove('d-none');
    bulkActionsBar.classList.add('d-flex');
  } else {
    bulkActionsBar.classList.add('d-none');
    bulkActionsBar.classList.remove('d-flex');
  }
  
  selectAllCheckbox.checked = selected.length === orderCheckboxes.length;
}

function applyBulkStatus() {
  const newStatus = document.getElementById('bulk-status-select').value;
  if (!newStatus) {
    alert('Please select a status first');
    return;
  }
  
  const selected = Array.from(orderCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
  if (selected.length === 0) return;
  
  if (confirm(`Change ${selected.length} order(s) to status "${newStatus}"?`)) {
    fetch('operation/ajax_bulk_update_status.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({order_ids: selected, status: newStatus})
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(`Updated ${data.updated} order(s) successfully!`, 'success');
        setTimeout(() => location.reload(), 1000);
      } else {
        alert('Error: ' + (data.error || 'Failed'));
      }
    })
    .catch(err => alert('Network error: ' + err.message));
  }
}

function clearSelection() {
  orderCheckboxes.forEach(cb => cb.checked = false);
  selectAllCheckbox.checked = false;
  updateBulkActionsBar();
}

// 3. QUICK ACTIONS
function viewOrderDetails(orderId) {
  // Redirect to order_edit page in view mode or open modal
  window.location.href = `operation/order_edit?id=${orderId}`;
}

function duplicateOrder(orderId) {
  if (confirm('Create a duplicate of this order?')) {
    window.location.href = `operation/order_add?duplicate=${orderId}`;
  }
}

// 4. TOAST NOTIFICATION
function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `alert alert-${type} position-fixed top-0 end-0 m-3`;
  toast.style.zIndex = '9999';
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

// Fix dropdown z-index in table rows
document.addEventListener('shown.bs.dropdown', function (event) {
  // Find the closest table row
  const row = event.target.closest('tr');
  if (row && row.closest('#orders-table')) {
    row.classList.add('dropdown-open');
  }
});

document.addEventListener('hidden.bs.dropdown', function (event) {
  // Find the closest table row
  const row = event.target.closest('tr');
  if (row && row.closest('#orders-table')) {
    row.classList.remove('dropdown-open');
  }
});

// Clean URL handlers for filters and pagination
(function() {
  // Store filters in session and submit form
  function submitWithCleanUrl(filters) {
    // Store filters in session
    fetch('accounts/ajax/store_filters.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        page: 'operation_workorder',
        filters: filters
      })
    }).then(() => {
      // Submit form via POST to maintain clean URL
      const form = document.getElementById('searchForm');
      if (form) {
        const formData = new FormData(form);
        for (let key in filters) {
          if (filters[key] !== null && filters[key] !== '') {
            formData.set(key, filters[key]);
          }
        }
        formData.set('tab', 'workorder');
        
        const submitForm = document.createElement('form');
        submitForm.method = 'POST';
        // Since workorder_list.php is included in operation.php, submit to current page
        // Get clean URL path (remove .php extension)
        let actionPath = window.location.pathname.replace(/\.php$/, '');
        // Remove trailing slash
        if (actionPath.endsWith('/') && actionPath !== '/') {
          actionPath = actionPath.slice(0, -1);
        }
        submitForm.action = actionPath || 'operation';
        for (let [key, value] of formData.entries()) {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = value;
          submitForm.appendChild(input);
        }
        document.body.appendChild(submitForm);
        submitForm.submit();
      }
    });
  }

  // Ops filter chips (Phase 3)
  document.querySelectorAll('.status-chip[data-ops-filter]').forEach(chip => {
    chip.addEventListener('click', function(e) {
      e.preventDefault();
      const opsFilter = this.getAttribute('data-ops-filter');
      const form = document.getElementById('searchForm');
      const filters = {};
      if (form) {
        const formData = new FormData(form);
        for (let [key, value] of formData.entries()) {
          filters[key] = value;
        }
      }
      filters.ops_filter = opsFilter;
      filters.status_filter = '';
      filters.page = 1;
      submitWithCleanUrl(filters);
    });
  });

  // Status filter chips
  document.querySelectorAll('.status-chip[data-status-filter]').forEach(chip => {
    chip.addEventListener('click', function(e) {
      e.preventDefault();
      const statusFilter = this.getAttribute('data-status-filter');
      const form = document.getElementById('searchForm');
      const filters = {};
      
      if (form) {
        const formData = new FormData(form);
        for (let [key, value] of formData.entries()) {
          filters[key] = value;
        }
      }
      filters.status_filter = statusFilter;
      filters.page = 1;
      submitWithCleanUrl(filters);
    });
  });

  // Pagination links
  document.querySelectorAll('.pagination a[data-page]').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const page = parseInt(this.getAttribute('data-page'), 10);
      const form = document.getElementById('searchForm');
      const filters = {};
      
      if (form) {
        const formData = new FormData(form);
        for (let [key, value] of formData.entries()) {
          filters[key] = value;
        }
      }
      filters.page = page;
      submitWithCleanUrl(filters);
    });
  });

    // Reset button
    document.querySelector('a[data-reset-filters="true"]')?.addEventListener('click', function(e) {
      e.preventDefault();
      // Clear filters and redirect
      fetch('../accounts/ajax/store_filters.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          page: 'operation_workorder',
          filters: {page: 1}
        })
      }).then(() => {
        // Get correct path for operation (clean URL)
        let opPath = window.location.pathname.replace(/\.php$/, '');
        // Remove trailing slash
        if (opPath.endsWith('/') && opPath !== '/') {
          opPath = opPath.slice(0, -1);
        }
        // If path doesn't end with 'operation', navigate to operation
        if (!opPath.endsWith('operation') && !opPath.endsWith('/operation')) {
          opPath = 'operation';
        }
        window.location.href = opPath;
      });
    });

  function collectWorkorderFilters() {
    const filters = {};
    const form = document.getElementById('searchForm');
    if (form) {
      const formData = new FormData(form);
      for (let [key, value] of formData.entries()) {
        filters[key] = value;
      }
    }
    const activeOps = document.querySelector('.status-chip[data-ops-filter].active');
    if (activeOps) {
      filters.ops_filter = activeOps.getAttribute('data-ops-filter') || '';
    }
    const activeStatus = document.querySelector('.status-chip[data-status-filter].active');
    if (activeStatus) {
      filters.status_filter = activeStatus.getAttribute('data-status-filter') || '';
    }
    return filters;
  }

  function openWorkorderExport(format) {
    const filters = collectWorkorderFilters();
    delete filters.page;
    filters.format = format;
    const params = new URLSearchParams();
    for (let key in filters) {
      if (filters[key] !== null && filters[key] !== undefined) {
        params.set(key, filters[key]);
      }
    }
    window.location.href = 'operation/workorder_export.php?' + params.toString();
  }

  document.querySelectorAll('[data-wo-export], #wo-export-csv, #wo-export-excel').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      let format = this.getAttribute('data-wo-export');
      if (!format) {
        format = this.id === 'wo-export-excel' ? 'excel' : 'csv';
      }
      openWorkorderExport(format);
    });
  });

  // Print button - preserve filters but use clean URL
  document.querySelector('a[data-print-filters="true"]')?.addEventListener('click', function(e) {
    e.preventDefault();
    const params = new URLSearchParams(collectWorkorderFilters());
    window.open('operation/print_report?' + params.toString(), '_blank');
  });

  // Form submission - store filters and submit
  document.getElementById('searchForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const filters = {};
    for (let [key, value] of formData.entries()) {
      filters[key] = value;
    }
    filters.page = 1;
    submitWithCleanUrl(filters);
  });

  // Update URL to clean format (remove .php and query parameters)
  if (window.history && window.history.replaceState) {
    let cleanUrl = window.location.pathname.replace(/\.php$/, '');
    // Remove trailing slash if present (except for root)
    if (cleanUrl.endsWith('/') && cleanUrl !== '/') {
      cleanUrl = cleanUrl.slice(0, -1);
    }
    // Always update if URL contains .php or has query parameters
    if (window.location.search || window.location.pathname.endsWith('.php') || window.location.pathname !== cleanUrl) {
      window.history.replaceState({}, document.title, cleanUrl);
    }
  }
})();
</script>
