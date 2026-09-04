<?php
// accounts/expenses.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/sm_expense_service.php';

// Check permission (backward compatible: if no permission but has role, allow)
if (!has_permission('expenses.view', MODULE_FINANCE, $conn)) {
    require_role(['Owner','Admin','Account'], $conn);
}

$q      = trim($_GET['q'] ?? '');
$vId    = (int)($_GET['vendor_id'] ?? 0);
$dFrom  = $_GET['from'] ?? '';
$dTo    = $_GET['to']   ?? '';
$status = $_GET['status'] ?? '';
$ok = $_GET['ok'] ?? '';
$canApprove = sm_user_can_approve_expense($conn);

// Default to current month if no dates specified
if (!$dFrom && !$dTo) {
    $dFrom = date('Y-m-01');
    $dTo = date('Y-m-d');
}

$vendors = $conn->query("SELECT id,name FROM vendors WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Build WHERE clause for filtered expenses
// Always exclude voided expenses from the main list unless explicitly requested
$where = ["e.status != 'void'"]; $args=[];
if ($q!==''){ $where[]="(e.reference_no LIKE ? OR v.name LIKE ? OR e.notes LIKE ?)"; $args[]="%$q%"; $args[]="%$q%"; $args[]="%$q%"; }
if ($vId>0){ $where[]="e.vendor_id=?"; $args[]=$vId; }
if ($dFrom!==''){ $where[]="e.expense_date >= ?"; $args[]=$dFrom; }
if ($dTo!==''){ $where[]="e.expense_date <= ?"; $args[]=$dTo; }
// Only add status filter if it's not 'void' (voided expenses should never show in main list)
if ($status!=='' && $status!=='void'){ $where[]="e.status=?"; $args[]=$status; }

// Get filtered expenses
$sql = "
  SELECT e.*, v.name AS vendor_name
  FROM expenses e
  LEFT JOIN vendors v ON v.id=e.vendor_id
  WHERE ".implode(' AND ',$where)."
  ORDER BY e.expense_date DESC, e.id DESC
  LIMIT 500
";
$st=$conn->prepare($sql); $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$totalPeriod = 0;
$countPeriod = count($rows);
foreach ($rows as $r) {
    $totalPeriod += (float)$r['total'];
}
$avgPeriod = $countPeriod > 0 ? $totalPeriod / $countPeriod : 0;

// Current month stats
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-d');
$stmt = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total), 0) as tot FROM expenses WHERE expense_date >= ? AND expense_date <= ? AND status != 'void'");
$stmt->execute([$currentMonthStart, $currentMonthEnd]);
$currentMonth = $stmt->fetch(PDO::FETCH_ASSOC);

// Last month stats
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
$stmt->execute([$lastMonthStart, $lastMonthEnd]);
$lastMonth = $stmt->fetch(PDO::FETCH_ASSOC);

// Monthly breakdown (last 6 months)
$monthlyBreakdown = [];
for ($i = 5; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd = date('Y-m-t', strtotime("-$i months"));
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) as tot FROM expenses WHERE expense_date >= ? AND expense_date <= ? AND status != 'void'");
    $stmt->execute([$monthStart, $monthEnd]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $monthlyBreakdown[] = [
        'month' => date('M Y', strtotime($monthStart)),
        'total' => (float)$result['tot']
    ];
}

// Top suppliers
$stmt = $conn->prepare("
  SELECT v.name, COUNT(e.id) as cnt, COALESCE(SUM(e.total), 0) as tot
  FROM expenses e
  LEFT JOIN vendors v ON v.id = e.vendor_id
  WHERE e.expense_date >= ? AND e.expense_date <= ? AND e.status != 'void'
  GROUP BY v.id, v.name
  ORDER BY tot DESC
  LIMIT 5
");
$stmt->execute([$dFrom ?: $currentMonthStart, $dTo ?: $currentMonthEnd]);
$topSuppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Expenses</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .table {background: white;}
  .table thead th {border-bottom: 2px solid #dee2e6; font-weight: 600; background: #f8f9fa;}
  .table tbody tr {transition: background-color 0.2s;}
  .table tbody tr:hover {background-color: #f8f9fa;}
  .card {border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.2s, box-shadow 0.2s;}
  .card:hover {transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.12);}
  .stat-card {border-left: 4px solid;}
  .stat-card.primary {border-left-color: #0d6efd;}
  .stat-card.success {border-left-color: #198754;}
  .stat-card.info {border-left-color: #0dcaf0;}
  .stat-card.warning {border-left-color: #ffc107;}
  .stat-card.danger {border-left-color: #dc3545;}
  .form-control:focus, .form-select:focus {border-color: #0d6efd; box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);}
  .month-bar {height: 100px; display: flex; align-items: flex-end; gap: 8px;}
  .month-bar-item {flex: 1; background: linear-gradient(to top, #0d6efd, #0dcaf0); border-radius: 4px 4px 0 0; position: relative; cursor: pointer; transition: opacity 0.2s;}
  .month-bar-item:hover {opacity: 0.8;}
  .month-bar-item::after {content: attr(data-value); position: absolute; top: -25px; left: 50%; transform: translateX(-50%); font-size: 11px; font-weight: 600; white-space: nowrap;}
  .month-label {font-size: 11px; text-align: center; margin-top: 4px; color: #6c757d;}
</style>
</head>
<body class="bg-light">
<div class="container-fluid my-4">
  <!-- Header -->
  <div class="d-flex align-items-center mb-4">
    <a href="../account.php" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <h3 class="mb-0 me-auto"><i class="bi bi-receipt-cutoff me-2"></i>Expenses</h3>
    <a href="expense_add.php" class="btn btn-primary">
      <i class="bi bi-plus-circle me-1"></i>Add Expense
    </a>
  </div>

  <?php if ($ok === '1'): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Saved &amp; posted.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
  <?php if ($ok === 'draft'): ?><div class="alert alert-info alert-dismissible fade show">Expense saved as draft.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
  <?php if ($ok === 'submitted'): ?><div class="alert alert-warning alert-dismissible fade show">Expense submitted for approval.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
  <?php if ($ok === 'approved'): ?><div class="alert alert-success alert-dismissible fade show">Expense approved and posted.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
  <?php 
  if(isset($_GET['rev'])): 
    if($_GET['rev'] == '1'): 
      $msg = $_GET['msg'] ?? 'Expense voided successfully.';
  ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php 
    else: 
      $msg = $_GET['msg'] ?? 'Failed to void expense.';
  ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($msg) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; endif; ?>

  <!-- Statistics Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="card stat-card primary h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-2">Selected Period</h6>
              <h3 class="mb-0"><?= money($totalPeriod) ?> <small class="text-muted fs-6">AED</small></h3>
              <small class="text-muted"><?= $countPeriod ?> expense<?= $countPeriod != 1 ? 's' : '' ?></small>
            </div>
            <div class="text-primary fs-1 opacity-25">
              <i class="bi bi-cash-stack"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stat-card success h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-2">This Month</h6>
              <h3 class="mb-0"><?= money($currentMonth['tot']) ?> <small class="text-muted fs-6">AED</small></h3>
              <small class="text-muted"><?= (int)$currentMonth['cnt'] ?> expense<?= $currentMonth['cnt'] != 1 ? 's' : '' ?></small>
            </div>
            <div class="text-success fs-1 opacity-25">
              <i class="bi bi-calendar-month"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stat-card info h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-2">Last Month</h6>
              <h3 class="mb-0"><?= money($lastMonth['tot']) ?> <small class="text-muted fs-6">AED</small></h3>
              <small class="text-muted"><?= (int)$lastMonth['cnt'] ?> expense<?= $lastMonth['cnt'] != 1 ? 's' : '' ?></small>
            </div>
            <div class="text-info fs-1 opacity-25">
              <i class="bi bi-calendar-check"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stat-card warning h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-2">Average</h6>
              <h3 class="mb-0"><?= money($avgPeriod) ?> <small class="text-muted fs-6">AED</small></h3>
              <small class="text-muted">Per expense</small>
            </div>
            <div class="text-warning fs-1 opacity-25">
              <i class="bi bi-graph-up"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Monthly Breakdown & Top Suppliers -->
  <div class="row g-3 mb-4">
    <div class="col-md-8">
      <div class="card h-100">
        <div class="card-header bg-white border-bottom">
          <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Monthly Breakdown (Last 6 Months)</h5>
        </div>
        <div class="card-body">
          <?php
          $maxValue = max(array_column($monthlyBreakdown, 'total'));
          if ($maxValue > 0):
          ?>
          <div class="month-bar">
            <?php foreach ($monthlyBreakdown as $month): 
              $height = $maxValue > 0 ? ($month['total'] / $maxValue * 100) : 0;
            ?>
              <div class="month-bar-item" style="height: <?= $height ?>%" data-value="<?= money($month['total']) ?> AED" title="<?= $month['month'] ?>: <?= money($month['total']) ?> AED"></div>
            <?php endforeach; ?>
          </div>
          <div class="d-flex justify-content-between mt-2">
            <?php foreach ($monthlyBreakdown as $month): ?>
              <div class="month-label"><?= $month['month'] ?></div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
            <div class="text-center text-muted py-4">No data available</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-header bg-white border-bottom">
          <h5 class="mb-0"><i class="bi bi-building me-2"></i>Top Suppliers</h5>
        </div>
        <div class="card-body">
          <?php if (!empty($topSuppliers)): ?>
            <div class="list-group list-group-flush">
              <?php foreach ($topSuppliers as $idx => $supplier): ?>
                <div class="list-group-item px-0 border-0">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <div class="fw-semibold"><?= $idx + 1 ?>. <?= h($supplier['name'] ?: 'Unknown') ?></div>
                      <small class="text-muted"><?= (int)$supplier['cnt'] ?> expense<?= $supplier['cnt'] != 1 ? 's' : '' ?></small>
                    </div>
                    <div class="text-end">
                      <div class="fw-bold text-primary"><?= money($supplier['tot']) ?></div>
                      <small class="text-muted">AED</small>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-center text-muted py-4">No suppliers found</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card mb-4">
    <div class="card-header bg-white">
      <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h5>
    </div>
    <div class="card-body">
      <form class="row g-2" method="GET">
        <div class="col-md-3">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Text, ref, supplier...">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">Supplier</label>
          <select class="form-select" name="vendor_id">
            <option value="">All Suppliers</option>
            <?php foreach($vendors as $v): ?>
              <option value="<?= (int)$v['id'] ?>" <?= $vId===(int)$v['id']?'selected':'' ?>><?= h($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">From Date</label>
          <input type="date" class="form-control" name="from" value="<?= h($dFrom) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">To Date</label>
          <input type="date" class="form-control" name="to" value="<?= h($dTo) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">Status</label>
          <select class="form-select" name="status">
            <option value="">All Status</option>
            <?php foreach(['posted','pending_approval','draft'] as $s): ?>
              <option <?= $status===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit">
            <i class="bi bi-search"></i>
          </button>
        </div>
      </form>
      <?php if ($q || $vId || $dFrom || $dTo || $status): ?>
        <div class="mt-2">
          <a href="expenses.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-x-circle me-1"></i>Clear Filters
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Expenses Table -->
  <div class="card">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Expenses List</h5>
      <span class="badge bg-secondary"><?= $countPeriod ?> record<?= $countPeriod != 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Date</th>
              <th>Supplier</th>
              <th>Reference</th>
              <th>Paid Via</th>
              <th class="text-end">Total</th>
              <th>Status</th>
              <th>Type</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><strong><?= h($r['expense_date']) ?></strong></td>
                <td><?= h($r['vendor_name'] ?: '—') ?></td>
                <td><code class="text-muted"><?= h($r['reference_no'] ?: '—') ?></code></td>
                <td>
                  <span class="badge bg-light text-dark">
                    <i class="bi bi-<?= $r['paid_via']==='bank'?'bank':($r['paid_via']==='cash'?'cash-coin':'credit-card') ?> me-1"></i>
                    <?= h(strtoupper($r['paid_via'])) ?>
                  </span>
                  <?php if ($r['pay_account_no']): ?>
                    <small class="text-muted">• <?= h($r['pay_account_no']) ?></small>
                  <?php endif; ?>
                </td>
                <td class="text-end"><strong class="text-primary"><?= money($r['total']) ?> AED</strong></td>
                <td>
                  <span class="badge text-bg-<?= $r['status']==='posted'?'success':($r['status']==='void'?'secondary':($r['status']==='pending_approval'?'warning':'info')) ?>">
                    <?= h(str_replace('_', ' ', $r['status'])) ?>
                  </span>
                </td>
                <td><span class="badge bg-light text-dark"><?= h($r['expense_type'] ?? 'operating') ?></span></td>
                <td class="text-end">
                  <?php if ($canApprove && in_array($r['status'], ['draft','pending_approval'], true)): ?>
                    <form action="ajax/expense_approve.php" method="post" class="d-inline" onsubmit="return confirm('Approve and post this expense to GL?')">
                      <?php csrf_field(); ?>
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i>Approve</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($r['status'] === 'posted'): ?>
                  <a class="btn btn-sm btn-outline-primary" href="expense_edit.php?id=<?= (int)$r['id'] ?>">
                    <i class="bi bi-pencil me-1"></i>Open
                  </a>
                  <form action="expense_delete.php" method="post" class="d-inline" onsubmit="return confirm('Reverse & void this expense?')">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">
                      <i class="bi bi-x-circle me-1"></i>Void
                    </button>
                  </form>
                  <?php elseif (in_array($r['status'], ['draft','pending_approval'], true)): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="expense_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; if (!$rows): ?>
              <tr>
                <td colspan="8" class="text-center text-muted py-5">
                  <i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>
                  No expenses found. <a href="expense_add.php">Add your first expense</a>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
