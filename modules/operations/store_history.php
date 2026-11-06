<?php
/**
 * Operations → Store → History: every goods in, goods out, move and count
 * correction, newest first. Open one to change or delete it.
 */
require_once __DIR__ . '/includes/ops_store_page.php';

$pageTitle = 'Store history';
$f = [
    'from' => $_GET['from'] ?? null,
    'to' => $_GET['to'] ?? null,
    'store' => (int)($_GET['store'] ?? 0),
    'item' => (int)($_GET['item'] ?? 0),
    'person' => (int)($_GET['person'] ?? 0),
    'kind' => (string)($_GET['kind'] ?? ''),
];
$data = $stReady ? st_list_all($conn, $f) : ['from' => date('Y-m-d', strtotime('-30 days')), 'to' => date('Y-m-d'), 'moves' => [], 'people' => []];
$stores = $stReady ? st_stores($conn, false) : [];
$items = $stReady ? st_items($conn, '', false) : [];

require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="mb-3">
  <div class="page-header-label">Store</div>
  <div class="text-muted small">Everything the store keepers recorded. A store keeper can change his own entry on the day he made it; after that, change it here.</div>
</div>

<?= st_page_tabs($opsBase, 'store_history.php') ?>
<?php if (!$stReady): ?><?= st_not_ready_alert() ?><?php endif; ?>

<form method="get" class="card card-round mb-3">
  <div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">From</label>
      <input type="date" name="from" value="<?= h($data['from']) ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">To</label>
      <input type="date" name="to" value="<?= h($data['to']) ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Store</label>
      <select name="store" class="form-select form-select-sm"><option value="">All</option>
        <?php foreach ($stores as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $f['store'] === $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Item</label>
      <select name="item" class="form-select form-select-sm"><option value="">All</option>
        <?php foreach ($items as $it): ?><option value="<?= (int)$it['id'] ?>" <?= $f['item'] === $it['id'] ? 'selected' : '' ?>><?= h($it['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Store keeper</label>
      <select name="person" class="form-select form-select-sm"><option value="">Everyone</option>
        <?php foreach ($data['people'] as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $f['person'] === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-1"><label class="form-label small text-muted mb-1">What</label>
      <select name="kind" class="form-select form-select-sm"><option value="">All</option>
        <?php foreach (ST_KINDS as $k => $label): ?><option value="<?= h($k) ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12 col-md-1"><button class="btn btn-sm btn-primary w-100">Show</button></div>
  </div>
</form>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table ops-table align-middle">
      <thead><tr><th>When</th><th>What</th><th>Store</th><th>Items</th><th>Details</th><th>By</th><th></th></tr></thead>
      <tbody>
        <?php if (!$data['moves']): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">Nothing recorded in this range.</td></tr>
        <?php endif; ?>
        <?php foreach ($data['moves'] as $m): ?>
          <?php
          $details = $m['kind'] === 'out' ? trim(($m['given_to']['name'] ?? '') . ($m['for_note'] ? ' · ' . $m['for_note'] : ''))
              : ($m['kind'] === 'in' ? (string)$m['source'] : ($m['kind'] === 'count' ? (ST_REASONS[$m['reason']] ?? '') : ''));
          ?>
          <tr>
            <td class="text-nowrap"><?= h(date('d M Y H:i', strtotime($m['created_at']))) ?></td>
            <td><?= st_kind_badge($m['kind']) ?></td>
            <td class="text-nowrap"><?= h($m['store']['name']) ?><?php if ($m['to_store']): ?> <i class="bi bi-arrow-right"></i> <?= h($m['to_store']['name']) ?><?php endif; ?></td>
            <td class="small">
              <?php foreach ($m['lines'] as $l): ?>
                <div><?= h($l['name']) ?>:
                  <strong><?= $m['kind'] === 'count' ? '= ' . h(st_qty($l['counted'])) . ' (' . ($l['qty'] > 0 ? '+' : '') . h(st_qty($l['qty'])) . ')' : h(st_qty($l['qty'])) ?></strong>
                  <?= $l['unit'] ? h($l['unit']) : '' ?></div>
              <?php endforeach; ?>
            </td>
            <td class="small"><?= h($details) ?><?php if ($m['photo']): ?> <i class="bi bi-image text-muted"></i><?php endif; ?></td>
            <td class="small"><?= h($m['by']['name']) ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="<?= h($opsBase) ?>/store_entry.php?id=<?= (int)$m['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
