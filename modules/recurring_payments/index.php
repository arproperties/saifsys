<?php
/**
 * Recurring Payments — one month's payments, and marking them paid.
 * Reem creates each line on its entry's day of the month; this page lists what Reem
 * has and passes on "paid" / "pending".
 *
 *   index.php                                  this month, every building
 *   index.php?month=2026-09&building=3&unit=Shop+3&status=pending
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/rpay_helper.php';

require_login(get_application_web_root() . '/login');
$code = rpay_boot($conn);

$rpayBase = get_application_web_root() . '/modules/recurring_payments';

// Only what Reem needs to hear; Reem decides what a wrong value means.
$filters = [];
foreach (['month', 'building', 'unit', 'status'] as $k) {
    if (is_string($_GET[$k] ?? null) && $_GET[$k] !== '') {
        $filters[$k] = $_GET[$k];
    }
}
$selfUrl = $rpayBase . '/index.php' . ($filters ? '?' . http_build_query($filters) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $dueId = (int)($_POST['due'] ?? 0);
    $to = ($_POST['action'] ?? '') === 'pending' ? 'pending' : 'paid';
    try {
        $due = rpay_reem($code, 'POST', '/dues/' . $dueId . '/' . $to);
        binv_flash('"' . ($due['title'] ?? 'Payment') . '" is now ' . $to . '.');
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    }
    header('Location: ' . $selfUrl);
    exit;
}

try {
    $data = rpay_reem($code, 'GET', $filters ? '?' . http_build_query($filters) : '');
} catch (BinvError $e) {
    rpay_stop($e);
}
$month = (string)($data['month'] ?? date('Y-m'));
$buildings = $data['buildings'] ?? [];
$units = $data['units'] ?? [];
$dues = $data['dues'] ?? [];
$totals = $data['totals'] ?? [];
$status = $filters['status'] ?? '';

$pageTitle = 'Payments';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <div class="page-header-label mb-1">Recurring Payments</div>
    <p class="text-muted mb-0">What should come in for <?= h(rpay_month_label($month)) ?>. Each line is created on its entry's day of the month.</p>
  </div>
  <a href="<?= h($rpayBase) ?>/entry_form.php" class="btn text-white" style="background:var(--primary)">
    <i class="bi bi-plus-lg"></i> Add entry
  </a>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card card-round h-100"><div class="card-body">
      <div class="small text-muted">Pending</div>
      <div class="rpay-total text-warning-emphasis"><?= h(rpay_money($totals['pending'] ?? 0)) ?></div>
      <div class="small text-muted"><?= (int)($totals['pending_count'] ?? 0) ?> payment<?= (int)($totals['pending_count'] ?? 0) === 1 ? '' : 's' ?></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-round h-100"><div class="card-body">
      <div class="small text-muted">Paid</div>
      <div class="rpay-total text-success"><?= h(rpay_money($totals['paid'] ?? 0)) ?></div>
      <div class="small text-muted"><?= (int)($totals['paid_count'] ?? 0) ?> payment<?= (int)($totals['paid_count'] ?? 0) === 1 ? '' : 's' ?></div>
    </div></div>
  </div>
</div>

<div class="card card-round mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end" action="<?= h($rpayBase) ?>/index.php">
      <div class="col-6 col-md-3">
        <label class="form-label small fw-semibold mb-1">Month</label>
        <input type="month" name="month" class="form-control" value="<?= h($month) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small fw-semibold mb-1">Building</label>
        <select name="building" class="form-select" data-search>
          <option value="">All buildings</option>
          <?php foreach ($buildings as $b): ?>
            <option value="<?= (int)$b['id'] ?>"<?= (string)($filters['building'] ?? '') === (string)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Shop / unit</label>
        <select name="unit" class="form-select" data-search>
          <option value="">All</option>
          <?php foreach ($units as $u): ?>
            <option value="<?= h($u) ?>"<?= strcasecmp((string)($filters['unit'] ?? ''), (string)$u) === 0 ? ' selected' : '' ?>><?= h($u) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select" data-no-search>
          <option value="">Pending and paid</option>
          <option value="pending"<?= $status === 'pending' ? ' selected' : '' ?>>Pending</option>
          <option value="paid"<?= $status === 'paid' ? ' selected' : '' ?>>Paid</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <button class="btn btn-light border w-100"><i class="bi bi-funnel"></i> Show</button>
      </div>
    </form>
  </div>
</div>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Title</th><th>Building</th><th>Shop / unit</th><th>Due</th><th class="text-end">Amount</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$dues): ?>
          <tr><td colspan="7" class="text-muted text-center py-5">
            No payments for <?= h(rpay_month_label($month)) ?><?= $status !== '' || !empty($filters['building']) || !empty($filters['unit']) ? ' with these filters' : '' ?>.
            A payment appears here on its entry's day of the month.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($dues as $d): ?>
          <?php $paid = ($d['status'] ?? '') === 'paid'; ?>
          <tr>
            <td class="fw-semibold"><?= h($d['title'] ?? '') ?></td>
            <td><?= h($d['building']['name'] ?? '') ?></td>
            <td><?= h($d['unit'] ?? '') ?></td>
            <td class="num"><?= h(rpay_date($d['due_date'] ?? '')) ?></td>
            <td class="num text-end fw-semibold"><?= h(rpay_money($d['amount'] ?? 0)) ?></td>
            <td>
              <span class="badge text-bg-<?= $paid ? 'success' : 'warning' ?>"><?= $paid ? 'Paid' : 'Pending' ?></span>
              <?php if ($paid && !empty($d['paid_at'])): ?>
                <div class="small text-muted"><?= h(binv_when($d['paid_at'])) ?><?= !empty($d['paid_by']) ? ' · ' . h($d['paid_by']) : '' ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <form method="post" action="<?= h($selfUrl) ?>" class="d-inline">
                <?php csrf_field(); ?>
                <input type="hidden" name="due" value="<?= (int)$d['id'] ?>">
                <?php if ($paid): ?>
                  <input type="hidden" name="action" value="pending">
                  <button class="btn btn-sm btn-light border text-nowrap">Set pending</button>
                <?php else: ?>
                  <input type="hidden" name="action" value="paid">
                  <button class="btn btn-sm btn-success text-nowrap"><i class="bi bi-check-lg"></i> Mark paid</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
