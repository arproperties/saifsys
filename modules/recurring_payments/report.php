<?php
/**
 * Recurring Payments — income by building: under each building, what came from each
 * income head (Shop, DEWA, Washing Machine) over the months picked. Reem adds it up;
 * this page only shows it.
 *
 *   report.php                                       this month, every building
 *   report.php?from=2026-01&to=2026-10&building=3
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
foreach (['from', 'to', 'building'] as $k) {
    if (is_string($_GET[$k] ?? null) && $_GET[$k] !== '') {
        $filters[$k] = $_GET[$k];
    }
}

try {
    $data = rpay_reem($code, 'GET', '/report' . ($filters ? '?' . http_build_query($filters) : ''));
} catch (BinvError $e) {
    rpay_stop($e);
}
$from = (string)($data['from'] ?? date('Y-m'));
$to = (string)($data['to'] ?? $from);
$buildings = $data['buildings'] ?? [];
$rows = $data['rows'] ?? [];
$totals = $data['totals'] ?? [];
$period = $from === $to ? rpay_month_label($from) : rpay_month_label($from) . ' to ' . rpay_month_label($to);

$pageTitle = 'Income by Building';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<div class="mb-3">
  <div class="page-header-label mb-1">Income by Building</div>
  <p class="text-muted mb-0">Each building, and what came from each income head, for <?= h($period) ?>.</p>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card card-round h-100"><div class="card-body">
      <div class="small text-muted">Paid</div>
      <div class="rpay-total text-success"><?= h(rpay_money($totals['paid'] ?? 0)) ?></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-round h-100"><div class="card-body">
      <div class="small text-muted">Pending</div>
      <div class="rpay-total text-warning-emphasis"><?= h(rpay_money($totals['pending'] ?? 0)) ?></div>
    </div></div>
  </div>
  <div class="col-12 col-lg-3">
    <div class="card card-round h-100"><div class="card-body">
      <div class="small text-muted">Total</div>
      <div class="rpay-total"><?= h(rpay_money($totals['total'] ?? 0)) ?></div>
    </div></div>
  </div>
</div>

<div class="card card-round mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end" action="<?= h($rpayBase) ?>/report.php">
      <div class="col-6 col-md-3">
        <label class="form-label small fw-semibold mb-1">From month</label>
        <input type="month" name="from" class="form-control" value="<?= h($from) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small fw-semibold mb-1">To month</label>
        <input type="month" name="to" class="form-control" value="<?= h($to) ?>">
      </div>
      <div class="col-8 col-md-4">
        <label class="form-label small fw-semibold mb-1">Building</label>
        <select name="building" class="form-select" data-search>
          <option value="">All buildings</option>
          <?php foreach ($buildings as $b): ?>
            <option value="<?= (int)$b['id'] ?>"<?= (string)($filters['building'] ?? '') === (string)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-4 col-md-2">
        <button class="btn btn-light border w-100"><i class="bi bi-funnel"></i> Show</button>
      </div>
    </form>
  </div>
</div>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Building / income head</th><th class="text-end">Paid</th><th class="text-end">Pending</th><th class="text-end">Total</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="text-muted text-center py-5">No payments for <?= h($period) ?><?= !empty($filters['building']) ? ' in this building' : '' ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr class="table-light">
            <td class="fw-bold"><i class="bi bi-building"></i> <?= h($r['building']['name'] ?? '') ?></td>
            <td class="num text-end fw-bold"><?= h(rpay_money($r['paid'] ?? 0)) ?></td>
            <td class="num text-end fw-bold"><?= h(rpay_money($r['pending'] ?? 0)) ?></td>
            <td class="num text-end fw-bold"><?= h(rpay_money($r['total'] ?? 0)) ?></td>
          </tr>
          <?php foreach ($r['heads'] ?? [] as $x): ?>
            <tr>
              <td class="ps-5">
                <?php if (!empty($x['head'])): ?>
                  <?= h($x['head']['name'] ?? '') ?>
                <?php else: ?>
                  <span class="text-muted">No income head</span>
                  <a class="small text-decoration-none ms-1" href="<?= h($rpayBase) ?>/entries.php">set it on the entries</a>
                <?php endif; ?>
              </td>
              <td class="num text-end"><?= h(rpay_money($x['paid'] ?? 0)) ?></td>
              <td class="num text-end"><?= h(rpay_money($x['pending'] ?? 0)) ?></td>
              <td class="num text-end"><?= h(rpay_money($x['total'] ?? 0)) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
      <?php if ($rows): ?>
        <tfoot>
          <tr>
            <td class="fw-bold">All buildings shown</td>
            <td class="num text-end fw-bold"><?= h(rpay_money($totals['paid'] ?? 0)) ?></td>
            <td class="num text-end fw-bold"><?= h(rpay_money($totals['pending'] ?? 0)) ?></td>
            <td class="num text-end fw-bold"><?= h(rpay_money($totals['total'] ?? 0)) ?></td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
