<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sm_prepaid_service.php';
require_once __DIR__ . '/../includes/sm_expense_service.php';
require_once __DIR__ . '/../includes/work_order_financial_guard.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($n) { return number_format((float)$n, 2); }
}

$msg = $_GET['msg'] ?? '';
$period = trim($_GET['period'] ?? date('Y-m'));
$currentMonth = date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_amort'])) {
    csrf_verify();
    if (!sm_user_can_finalize($conn)) {
        $msg = 'Only Admin or Accountant can run amortization.';
    } else {
        $result = sm_prepaid_run_amortization($conn, $_POST['period'] ?? date('Y-m'), $_SESSION['user_id'] ?? null);
        $msg = $result['message'];
        if (!empty($result['errors'])) {
            $msg .= ' ' . implode('; ', $result['errors']);
        }
    }
}

$schedules = [];
if (sm_prepaid_table_exists($conn)) {
    $st = $conn->query("
        SELECT s.*, v.name AS vendor_name,
               (SELECT COUNT(*) FROM sm_prepaid_amortization a WHERE a.schedule_id = s.id AND a.status = 'pending') AS pending_cnt
        FROM sm_prepaid_schedules s
        LEFT JOIN vendors v ON v.id = s.vendor_id
        ORDER BY s.id DESC
        LIMIT 200
    ");
    $schedules = $st->fetchAll(PDO::FETCH_ASSOC);
}

$dueThisMonth = sm_prepaid_table_exists($conn)
    ? sm_prepaid_due_summary($conn, $currentMonth)
    : ['period' => $currentMonth, 'count' => 0, 'total' => 0.0, 'items' => []];
$duePeriodLabel = date('F Y', strtotime($currentMonth . '-01'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Prepaid Schedules</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid my-4">
  <div class="d-flex align-items-center mb-3">
    <div>
      <h3 class="mb-0">Prepaid Expense Schedules</h3>
      <div class="text-muted">Monthly amortization from prepaid asset to expense</div>
    </div>
    <div class="ms-auto">
      <a href="../account.php" class="btn btn-outline-secondary btn-sm">Back to Accounts</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-info"><?= h($msg) ?></div><?php endif; ?>

  <div class="alert alert-secondary py-2 mb-3 small">
    <i class="bi bi-info-circle me-1"></i>
    Each prepaid expense can use its own asset account (rent, insurance, deposits, etc.).
    Default for new expenses: <strong><?= h(sm_prepaid_asset_account_label($conn)) ?></strong>
    — change it in <a href="../settings.php?tab=accounting">Settings → Accounting</a>,
    or pick a different asset when creating the expense.
    Monthly amortization moves the balance to the expense account you choose (e.g. 5250 Visa fees).
  </div>

  <?php if (sm_prepaid_table_exists($conn) && $dueThisMonth['count'] > 0): ?>
    <div class="alert alert-warning border-warning d-flex flex-column flex-md-row align-items-md-center gap-2 mb-3">
      <div class="flex-grow-1">
        <strong><i class="bi bi-exclamation-triangle me-1"></i>Due this month — <?= h($duePeriodLabel) ?></strong>
        <div class="small mt-1">
          <?= (int)$dueThisMonth['count'] ?> amortization<?= $dueThisMonth['count'] === 1 ? '' : 's' ?>
          pending (total <strong><?= money($dueThisMonth['total']) ?> AED</strong>).
          Run amortization below to post to the GL.
        </div>
        <ul class="small mb-0 mt-2 ps-3">
          <?php foreach ($dueThisMonth['items'] as $dueItem): ?>
            <li>
              Schedule #<?= (int)$dueItem['schedule_id'] ?>:
              <?= h($dueItem['description']) ?>
              — <?= money($dueItem['amount']) ?> → <?= h($dueItem['expense_account_no']) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php if (sm_user_can_finalize($conn)): ?>
        <a href="#run-amortization" class="btn btn-warning text-dark btn-sm flex-shrink-0">
          <i class="bi bi-play-fill me-1"></i>Run for <?= h($currentMonth) ?>
        </a>
      <?php endif; ?>
    </div>
  <?php elseif (sm_prepaid_table_exists($conn) && !empty($schedules)): ?>
    <?php
      $hasActive = false;
      foreach ($schedules as $s) {
          if (($s['status'] ?? '') === 'active') {
              $hasActive = true;
              break;
          }
      }
    ?>
    <?php if ($hasActive): ?>
    <div class="alert alert-success py-2 mb-3">
      <i class="bi bi-check-circle me-1"></i>
      <strong>All caught up for <?= h($duePeriodLabel) ?></strong>
      <span class="small"> — no pending amortization for this month.</span>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!sm_prepaid_table_exists($conn)): ?>
    <div class="alert alert-warning">Run <code>php tools/sm_apply_phase7_schema.php</code> first.</div>
  <?php else: ?>

  <?php if (sm_user_can_finalize($conn)): ?>
  <div class="card mb-3" id="run-amortization">
    <div class="card-body">
      <form method="post" class="row g-2 align-items-end" onsubmit="return confirm('Post pending amortization for this period?')">
        <?php csrf_field(); ?>
        <div class="col-md-3">
          <label class="form-label">Period (YYYY-MM)</label>
          <input class="form-control" name="period" value="<?= h($period) ?>" pattern="\d{4}-\d{2}" required>
          <?php if ($dueThisMonth['count'] > 0 && $period !== $currentMonth): ?>
            <div class="form-text text-warning">Current month (<?= h($currentMonth) ?>) has pending items.</div>
          <?php endif; ?>
        </div>
        <div class="col-md-3">
          <button name="run_amort" value="1" class="btn btn-primary"><i class="bi bi-play-fill me-1"></i>Run Amortization</button>
        </div>
        <div class="col-md-6 text-muted small">Or schedule monthly: <code>php tools/sm_run_prepaid_cron.php</code></div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>#</th><th>Description</th><th>Vendor</th><th>Start</th>
            <th class="text-end">Total</th><th class="text-end">Monthly</th>
            <th>Asset / Expense</th><th>Status</th><th>Pending</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($schedules as $s): ?>
          <tr>
            <td><?= (int)$s['id'] ?></td>
            <td><?= h($s['description']) ?></td>
            <td><?= h($s['vendor_name'] ?: '—') ?></td>
            <td><?= h($s['start_date']) ?> (<?= (int)$s['months'] ?> mo)</td>
            <td class="text-end"><?= money($s['total_amount']) ?></td>
            <td class="text-end"><?= money($s['monthly_amount']) ?></td>
            <td><small><?= h($s['prepaid_account_no']) ?> → <?= h($s['expense_account_no']) ?></small></td>
            <td><span class="badge bg-<?= $s['status']==='active'?'primary':($s['status']==='completed'?'success':'secondary') ?>"><?= h($s['status']) ?></span></td>
            <td><?= (int)$s['pending_cnt'] ?></td>
          </tr>
        <?php endforeach; if (!$schedules): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No prepaid schedules. Create a <strong>Prepaid</strong> expense to add one.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
