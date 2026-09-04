<?php
// operation/cancellations.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../includes/cleaning_order_cancellation_helper.php';
require_once __DIR__ . '/../includes/work_order_adjustment_service.php';

cleaning_order_cancel_ensure_schema($conn);
// Repair WOs cancelled via adjustment approval before category/details were persisted.
sm_backfill_cancellation_meta_from_adjustments($conn);

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
  function money($n){ return number_format((float)$n, 2); }
}

$currentCompanyId = current_company_id($conn) ?: 1;
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
$category = cleaning_order_cancel_normalize_category((string)($_GET['category'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = ["mo.company_id = ?", "COALESCE(mo.status, '') = 'cancelled'"];
$params = [$currentCompanyId];

if ($dateFrom !== '') {
  $where[] = "COALESCE(mo.service_date, mo.`date`) >= ?";
  $params[] = $dateFrom;
}
if ($dateTo !== '') {
  $where[] = "COALESCE(mo.service_date, mo.`date`) <= ?";
  $params[] = $dateTo;
}
if ($category !== '') {
  $where[] = "mo.cancellation_category = ?";
  $params[] = $category;
}
if ($q !== '') {
  $where[] = "(COALESCE(c.client_name, mo.client_name) LIKE ? OR mo.worker_name LIKE ? OR mo.cancel_reason LIKE ? OR mo.cancellation_details LIKE ? OR CAST(mo.id AS CHAR) LIKE ?)";
  $term = '%' . $q . '%';
  array_push($params, $term, $term, $term, $term, $term);
}

$whereSql = implode(' AND ', $where);

$summaryStmt = $conn->prepare("
  SELECT COUNT(*) AS cnt,
         COALESCE(SUM(mo.hours), 0) AS hours_total,
         COALESCE(SUM(mo.total), 0) AS net_total,
         COALESCE(SUM(COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0))), 0) AS gross_total
  FROM make_order mo
  LEFT JOIN client c ON c.id = mo.client_id
  WHERE {$whereSql}
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalRows = (int)($summary['cnt'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$byCategoryStmt = $conn->prepare("
  SELECT COALESCE(NULLIF(mo.cancellation_category, ''), 'Unspecified') AS category,
         COUNT(*) AS cnt,
         COALESCE(SUM(COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0))), 0) AS gross_total
  FROM make_order mo
  LEFT JOIN client c ON c.id = mo.client_id
  WHERE {$whereSql}
  GROUP BY COALESCE(NULLIF(mo.cancellation_category, ''), 'Unspecified')
  ORDER BY cnt DESC, category
");
$byCategoryStmt->execute($params);
$byCategory = $byCategoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rowsStmt = $conn->prepare("
  SELECT mo.id,
         COALESCE(mo.service_date, mo.`date`) AS service_date,
         mo.start_time,
         mo.end_time,
         COALESCE(c.client_name, mo.client_name) AS client_name,
         mo.worker_name,
         mo.driver_name,
         mo.hours,
         mo.total,
         COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0)) AS grand_total,
         mo.cancellation_category,
         mo.cancellation_details,
         mo.cancel_reason,
         mo.cancelled_at,
         COALESCE(u.fullname, u.username) AS cancelled_by_name,
         i.invoice_no,
         i.status AS invoice_status
  FROM make_order mo
  LEFT JOIN client c ON c.id = mo.client_id
  LEFT JOIN `user` u ON u.id = mo.cancelled_by
  LEFT JOIN invoices i ON i.order_id = mo.id
  WHERE {$whereSql}
  ORDER BY COALESCE(mo.cancelled_at, mo.updated_at, mo.created_at) DESC, mo.id DESC
  LIMIT {$perPage} OFFSET {$offset}
");
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>

<div class="d-flex align-items-center mb-3">
  <div>
    <h5 class="mb-0">Cancelled Orders</h5>
    <div class="text-muted small">Internal cancellation tracking by category and detailed reason.</div>
  </div>
  <div class="ms-auto">
    <a href="/operation/worker_availability" class="btn btn-outline-primary btn-sm">Worker Availability</a>
  </div>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
  <input type="hidden" name="tab" value="cancellations">
  <div class="col-md-2">
    <label class="form-label">From</label>
    <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">To</label>
    <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">Category</label>
    <select name="category" class="form-select">
      <option value="">All Categories</option>
      <?php foreach (cleaning_order_cancel_categories() as $cat): ?>
        <option value="<?= h($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <label class="form-label">Search</label>
    <input type="text" name="q" class="form-control" placeholder="Client, worker, order ID, reason..." value="<?= h($q) ?>">
  </div>
  <div class="col-md-2">
    <button class="btn btn-primary w-100">Filter</button>
  </div>
</form>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="text-muted small">Cancelled Orders</div><div class="fs-4 fw-bold"><?= number_format($totalRows) ?></div></div></div>
  <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="text-muted small">Hours Cancelled</div><div class="fs-4 fw-bold"><?= money($summary['hours_total'] ?? 0) ?></div></div></div>
  <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="text-muted small">Net Cancelled</div><div class="fs-4 fw-bold">AED <?= money($summary['net_total'] ?? 0) ?></div></div></div>
  <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="text-muted small">Gross Cancelled</div><div class="fs-4 fw-bold">AED <?= money($summary['gross_total'] ?? 0) ?></div></div></div>
</div>

<?php if ($byCategory): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach ($byCategory as $row): ?>
    <span class="badge text-bg-secondary"><?= h($row['category']) ?>: <?= (int)$row['cnt'] ?> / AED <?= money($row['gross_total']) ?></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-sm table-striped align-middle">
    <thead class="table-light">
      <tr>
        <th>Order</th>
        <th>Service Date</th>
        <th>Client</th>
        <th>Worker / Driver</th>
        <th class="text-end">Hours</th>
        <th class="text-end">Gross</th>
        <th>Category</th>
        <th>Details</th>
        <th>Invoice</th>
        <th>Cancelled By</th>
        <th>Cancelled At</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
      <tr>
        <td><code>#<?= (int)$row['id'] ?></code></td>
        <td><?= h($row['service_date']) ?><br><small class="text-muted"><?= h(substr((string)$row['start_time'],0,5) . ' - ' . substr((string)$row['end_time'],0,5)) ?></small></td>
        <td><?= h($row['client_name']) ?></td>
        <td><?= h($row['worker_name']) ?><br><small class="text-muted"><?= h($row['driver_name'] ?: '-') ?></small></td>
        <td class="text-end"><?= money($row['hours']) ?></td>
        <td class="text-end">AED <?= money($row['grand_total']) ?></td>
        <td><span class="badge text-bg-warning"><?= h($row['cancellation_category'] ?: 'Unspecified') ?></span></td>
        <td><?= h($row['cancellation_details'] ?: $row['cancel_reason']) ?></td>
        <td>
          <?php if (!empty($row['invoice_no'])): ?>
            <?= h($row['invoice_no']) ?><br><small class="text-muted"><?= h($row['invoice_status']) ?></small>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= h($row['cancelled_by_name'] ?: '—') ?></td>
        <td><?= h($row['cancelled_at'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="11" class="text-center text-muted py-4">No cancelled orders found for this filter.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
<nav>
  <ul class="pagination pagination-sm mb-0">
    <?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['tab'=>'cancellations', 'page'=>$page-1])) ?>">&laquo; Previous</a></li><?php endif; ?>
    <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['tab'=>'cancellations', 'page'=>$p])) ?>"><?= $p ?></a></li>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['tab'=>'cancellations', 'page'=>$page+1])) ?>">Next &raquo;</a></li><?php endif; ?>
  </ul>
</nav>
<?php endif; ?>
