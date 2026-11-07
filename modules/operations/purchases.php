<?php
/**
 * Operations — Purchases.
 *
 * What the buyers recorded in the staff app (Purchase): cash at a shop, or
 * ordered from a supplier and received later, each with the photo of its bill
 * or delivery note. Filter by date, buyer, supplier and status; the total is
 * what was bought in the range. A record only — nothing here is in the accounts.
 * Download (Excel) gives what is shown, one row per item (?download=1).
 * Who may record: HR → employee → Mobile App PIN → Purchase.
 * Rules: includes/ops_purchases.php. Tables: migrations/staff_purchases.sql.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_purchases.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$pageTitle = 'Purchases';

$ready = pur_tables_ready($conn);
$canInactive = ops_can_inactivate($conn);
$showInactive = $canInactive && ($_GET['tab'] ?? '') === 'inactive';
$f = [
    'from' => $_GET['from'] ?? null,
    'to' => $_GET['to'] ?? null,
    'person' => (int)($_GET['person'] ?? 0),
    'supplier' => (int)($_GET['supplier'] ?? 0),
    'kind' => (string)($_GET['kind'] ?? ''),
    'status' => (string)($_GET['status'] ?? ''),
    'q' => (string)($_GET['q'] ?? ''),
    'inactive' => $showInactive,
];
$data = $ready ? pur_list_all($conn, $f) : ['from' => date('Y-m-01'), 'to' => date('Y-m-d'), 'purchases' => [], 'total' => 0, 'inactive_count' => 0, 'people' => [], 'suppliers' => []];
$rows = $data['purchases'];
$cashTotal = array_sum(array_map(static fn(array $p): float => $p['kind'] === 'cash' ? $p['total'] : 0.0, $rows));
$waiting = count(array_filter($rows, static fn(array $p): bool => $p['status'] === 'ordered'));

if ($ready && ($_GET['download'] ?? '') === '1') {
    $csv = [];
    foreach ($rows as $p) {
        foreach ($p['lines'] as $l) {
            $row = [
                $p['bought_on'], date('Y-m-d H:i', strtotime($p['created_at'])), $p['id'], $p['by']['name'], $p['supplier']['name'],
                PUR_KINDS[$p['kind']] ?? $p['kind'], PUR_STATUSES[$p['status']] ?? $p['status'], $p['received_on'] ?? '',
                $l['name'], $l['qty'], $l['unit'] ?? '', $l['price'], $l['amount'], $l['note'] ?? '',
                $p['outsource'] ? 'Yes' : '', $p['outsource']['note'] ?? '', $p['notes'] ?? '', $p['total'],
            ];
            if ($showInactive) {
                $row[] = $p['inactive']['reason'] ?? '';
            }
            $csv[] = $row;
        }
    }
    ops_send_csv('purchases_' . $data['from'] . ($data['to'] !== $data['from'] ? '_to_' . $data['to'] : '') . ($showInactive ? '_inactive' : '') . '.csv',
        array_merge(['Date bought', 'Added at', 'Purchase #', 'Bought by', 'Shop / supplier', 'Type', 'Status', 'Received on',
            'Item', 'Qty', 'Unit', 'Price for one', 'Amount', 'Item note', 'For outsource', 'Outsource: who / what for', 'Purchase note', 'Purchase total'],
            $showInactive ? ['Inactive reason'] : []),
        $csv);
}

$money = static fn($n): string => 'AED ' . number_format((float)$n, 2);
$statusBadge = static function (string $status): string {
    $cls = ['paid' => 'bg-secondary-subtle text-secondary-emphasis', 'ordered' => 'bg-warning-subtle text-warning-emphasis', 'received' => 'bg-success-subtle text-success-emphasis'][$status] ?? 'bg-light text-dark';
    return '<span class="badge ' . $cls . '">' . h(PUR_STATUSES[$status] ?? $status) . '</span>';
};

require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="mb-4">
  <div class="page-header-label">Purchases</div>
  <div class="text-muted small">
    What the buyers recorded in the staff app — paid cash at a shop, or ordered from a supplier.
    A record only: nothing here is posted to the accounts.
    Give someone the Purchase button in HR → employee → Mobile App PIN.
  </div>
</div>

<?php if (!$ready): ?>
  <div class="alert alert-warning">The purchase tables are not set up yet. Run migrations/staff_purchases.sql.</div>
<?php endif; ?>

<?php if ($canInactive): ?>
  <?php $tabQuery = static fn(string $tab): string => http_build_query(array_merge(array_diff_key($_GET, ['tab' => 1]), $tab === 'inactive' ? ['tab' => 'inactive'] : [])); ?>
  <ul class="nav ops-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $showInactive ? '' : 'active' ?>" href="?<?= h($tabQuery('active')) ?>">Active</a></li>
    <li class="nav-item"><a class="nav-link <?= $showInactive ? 'active' : '' ?>" href="?<?= h($tabQuery('inactive')) ?>">Inactive <span class="badge bg-secondary-subtle text-secondary-emphasis"><?= (int)$data['inactive_count'] ?></span></a></li>
  </ul>
<?php endif; ?>

<form method="get" id="pur-filter" class="card card-round mb-3">
  <div class="card-body row g-2 align-items-end">
    <?php if ($showInactive): ?><input type="hidden" name="tab" value="inactive"><?php endif; ?>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted mb-1">From</label>
      <input type="date" name="from" value="<?= h($data['from']) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted mb-1">To</label>
      <input type="date" name="to" value="<?= h($data['to']) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted mb-1">Buyer</label>
      <select name="person" class="form-select form-select-sm">
        <option value="">Everyone</option>
        <?php foreach ($data['people'] as $pp): ?>
          <option value="<?= (int)$pp['id'] ?>" <?= $f['person'] === (int)$pp['id'] ? 'selected' : '' ?>><?= h($pp['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted mb-1">Shop / supplier</label>
      <select name="supplier" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($data['suppliers'] as $sp): ?>
          <option value="<?= (int)$sp['id'] ?>" <?= $f['supplier'] === (int)$sp['id'] ? 'selected' : '' ?>><?= h($sp['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (PUR_STATUSES as $k => $label): ?>
          <option value="<?= h($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted mb-1">Search</label>
      <input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Item, shop, buyer" class="form-control form-control-sm">
    </div>
    <div class="col-12 col-md-2 ms-auto">
      <button class="btn btn-sm btn-primary w-100">Show</button>
    </div>
  </div>
</form>

<div class="d-flex justify-content-end mb-2">
  <button form="pur-filter" name="download" value="1" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Download (Excel)</button>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="stat-tile"><div class="stat-value"><?= h($money($data['total'])) ?></div><div class="stat-label">Total bought</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-tile"><div class="stat-value"><?= h($money($cashTotal)) ?></div><div class="stat-label">Paid cash</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-tile"><div class="stat-value"><?= count($rows) ?></div><div class="stat-label">Purchases</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-tile"><div class="stat-value <?= $waiting ? 'text-warning' : '' ?>"><?= $waiting ?></div><div class="stat-label">Orders waiting to arrive</div></div></div>
</div>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table ops-table align-middle">
      <thead>
        <tr>
          <th>Date</th>
          <th>Shop / supplier</th>
          <th>Items</th>
          <th>Buyer</th>
          <th>Added on</th>
          <?php if ($showInactive): ?><th>Inactivated by</th><th>Reason</th><?php endif; ?>
          <th>Status</th>
          <th class="text-end">Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= $showInactive ? 10 : 8 ?>" class="text-center text-muted py-5"><?= $showInactive ? 'No inactive purchases in this range.' : 'No purchases in this range.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $p): ?>
          <?php
          $names = array_column($p['lines'], 'name');
          $itemText = implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' +' . (count($names) - 3) . ' more' : '');
          ?>
          <tr>
            <td class="text-nowrap"><?= h(date('d M Y', strtotime($p['bought_on']))) ?></td>
            <td class="fw-semibold">
              <i class="bi <?= $p['kind'] === 'cash' ? 'bi-cash-coin' : 'bi-truck' ?> text-muted me-1" title="<?= h(PUR_KINDS[$p['kind']]) ?>"></i><?= h($p['supplier']['name']) ?>
            </td>
            <td class="small"><?= h($itemText) ?></td>
            <td><?= h($p['by']['name']) ?></td>
            <td class="small text-nowrap"><?= h(date('d M Y H:i', strtotime($p['created_at']))) ?></td>
            <?php if ($showInactive): ?>
              <td class="small text-nowrap"><?= h($p['inactive']['by'] ?? '') ?><div class="text-muted"><?= $p['inactive'] ? h(date('d M Y H:i', strtotime($p['inactive']['at']))) : '' ?></div></td>
              <td class="small"><?= h($p['inactive']['reason'] ?? '') ?></td>
            <?php endif; ?>
            <td><?= $statusBadge($p['status']) ?><?php if ($p['received_on']): ?><div class="small text-muted"><?= h(date('d M', strtotime($p['received_on']))) ?></div><?php endif; ?></td>
            <td class="num text-end fw-bold text-nowrap"><?= h($money($p['total'])) ?></td>
            <td class="text-nowrap">
              <?php if ($p['files']): ?><i class="bi bi-image text-muted me-2" title="<?= count($p['files']) ?> photo(s)"></i><?php endif; ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= h($opsBase) ?>/purchase_view.php?id=<?= (int)$p['id'] ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
