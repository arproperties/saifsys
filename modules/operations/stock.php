<?php
/**
 * Operations — stock. What you hold, and moving it in or out.
 * Standalone: nothing here touches the Inventory module.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$companyId = ops_company_id($conn);
$userId = (int)current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $itemId = (int)($_POST['item_id'] ?? 0);

    if ($action === 'move') {
        $qty = (float)($_POST['qty'] ?? 0);
        $direction = ($_POST['direction'] ?? 'in') === 'out' ? 'out' : 'in';
        $note = trim((string)($_POST['note'] ?? '')) ?: null;
        $change = $direction === 'out' ? -abs($qty) : abs($qty);

        $res = ops_move_stock($conn, $companyId, $itemId, $change, $direction, null, $note, $userId);
        ops_flash(
            $res['ok'] ? 'Stock updated.' : $res['error'],
            $res['ok'] ? 'success' : 'danger'
        );
    } elseif ($action === 'archive') {
        $conn->prepare("UPDATE ops_items SET is_active = 0 WHERE id = ? AND company_id = ?")
             ->execute([$itemId, $companyId]);
        ops_flash('Item hidden. Its history is kept.');
    } elseif ($action === 'restore') {
        $conn->prepare("UPDATE ops_items SET is_active = 1 WHERE id = ? AND company_id = ?")
             ->execute([$itemId, $companyId]);
        ops_flash('Item restored.');
    }
    // Come back to the same view — the tab AND whatever the movement list was
    // filtered to, or a correction made while looking at one item throws you
    // back to the unfiltered list.
    $keep = array_filter([
        'show' => ($_POST['show'] ?? '') === 'archived' ? 'archived' : '',
        'item' => (string)($_POST['f_item'] ?? ''),
        'from' => (string)($_POST['f_from'] ?? ''),
        'to'   => (string)($_POST['f_to'] ?? ''),
    ], static fn($v) => $v !== '' && $v !== '0');
    header('Location: ' . $opsBase . '/stock.php' . ($keep ? '?' . http_build_query($keep) : ''));
    exit;
}

$showArchived = ($_GET['show'] ?? '') === 'archived';
$items = ops_stock_items($conn, $companyId, !$showArchived);
if ($showArchived) {
    $items = array_values(array_filter($items, static fn($i) => (int)$i['is_active'] === 0));
}
$lowCount = ops_low_stock_count($conn, $companyId);

// Movements, so you can see where things went. Unfiltered it is the recent
// forty; narrowed to an item or a date range it is the answer to "how much of
// this did we get through", so it runs deeper and totals what it found.
$filterItem = (int)($_GET['item'] ?? 0);

/** Accept only a real Y-m-d — anything else is treated as "not set". */
$validDate = static function (string $d): string {
    $d = trim($d);
    $dt = $d !== '' ? DateTime::createFromFormat('Y-m-d', $d) : false;
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$filterFrom = $validDate((string)($_GET['from'] ?? ''));
$filterTo   = $validDate((string)($_GET['to'] ?? ''));

// Backwards dates are a typo, not an empty result — swap them and carry on.
if ($filterFrom !== '' && $filterTo !== '' && $filterFrom > $filterTo) {
    [$filterFrom, $filterTo] = [$filterTo, $filterFrom];
}

$moveWhere  = ['m.company_id = ?'];
$moveParams = [$companyId];
if ($filterItem > 0) {
    $moveWhere[]  = 'm.item_id = ?';
    $moveParams[] = $filterItem;
}
if ($filterFrom !== '') {
    $moveWhere[]  = 'm.created_at >= ?';
    $moveParams[] = $filterFrom . ' 00:00:00';
}
if ($filterTo !== '') {
    // Inclusive of the whole closing day, whatever time of day it was recorded.
    $moveWhere[]  = 'm.created_at < ?';
    $moveParams[] = (new DateTime($filterTo . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');
}
$moveFiltered = $filterItem > 0 || $filterFrom !== '' || $filterTo !== '';

$movesStmt = $conn->prepare("
    SELECT m.*, i.name AS item_name, i.unit,
           COALESCE(NULLIF(u.fullname, ''), u.username) AS person,
           j.title AS job_title, j.location AS job_location, j.scheduled_date AS job_date
    FROM ops_stock_moves m
    JOIN ops_items i ON i.id = m.item_id
    LEFT JOIN user u ON u.id = m.created_by
    LEFT JOIN ops_jobs j ON j.id = m.job_id
    WHERE " . implode(' AND ', $moveWhere) . "
    ORDER BY m.created_at DESC
    LIMIT " . ($moveFiltered ? 500 : 40)
);
$movesStmt->execute($moveParams);
$moves = $movesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$movedIn = $movedOut = 0.0;
foreach ($moves as $m) {
    $change = (float)$m['qty_change'];
    if ($change > 0) {
        $movedIn += $change;
    } else {
        $movedOut += abs($change);
    }
}

// Every item ever moved can be filtered on, hidden ones included — their
// history is kept, so it must stay reachable.
$filterItems = ops_stock_items($conn, $companyId, false);

/** Re-post the movement filter so a move or a hide comes back to the same list. */
function ops_keep_move_filter(int $item, string $from, string $to): void {
    echo '<input type="hidden" name="f_item" value="' . ($item > 0 ? (int)$item : '') . '">'
       . '<input type="hidden" name="f_from" value="' . h($from) . '">'
       . '<input type="hidden" name="f_to" value="' . h($to) . '">';
}

$pageTitle = 'Stock';
require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <div class="page-header-label">Stock</div>
  <a href="<?= h($opsBase) ?>/item_form.php" class="btn btn-lg text-white" style="background:var(--primary)">
    <i class="bi bi-plus-lg"></i> New item
  </a>
</div>
<p class="text-muted mb-3">Cleaning supplies and spare parts. When you hand something to a staff member, take it out here.</p>

<?php if ($lowCount > 0 && !$showArchived): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <strong><?= (int)$lowCount ?></strong> item<?= $lowCount > 1 ? 's are' : ' is' ?> at or below the warning level — time to reorder.
  </div>
<?php endif; ?>

<?php // The In use / Hidden switch is off the page. The list still answers to
      // ?show=archived, so hidden items stay reachable by link. ?>
<?php if ($showArchived): ?>
  <div class="mb-3"><a class="btn btn-sm btn-outline-secondary" href="?"><i class="bi bi-arrow-left"></i> Back to items in use</a></div>
<?php endif; ?>

<div class="card card-round mb-4">
  <div class="table-responsive">
    <table class="table ops-table table-hover align-middle">
      <thead>
        <tr>
          <th>Item</th>
          <th>In stock</th>
          <th>Warn at</th>
          <th style="min-width:320px">Add or take out</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="5" class="text-center text-muted py-5">
            <?php if ($showArchived): ?>
              Nothing hidden.
            <?php else: ?>
              No items yet. <a href="<?= h($opsBase) ?>/item_form.php">Add your first one</a> — for example "Floor cleaner".
            <?php endif; ?>
          </td></tr>
        <?php endif; ?>

        <?php foreach ($items as $it): ?>
          <?php $isLow = (float)$it['current_qty'] <= (float)$it['min_qty']; ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= h($it['name']) ?></div>
              <?php if (!empty($it['notes'])): ?>
                <div class="small text-muted"><?= h($it['notes']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="fs-5 fw-bold <?= $isLow ? 'text-danger' : '' ?>"><?= h(ops_qty($it['current_qty'])) ?></span>
              <span class="text-muted small"><?= h($it['unit'] ?: '') ?></span>
              <?php if ($isLow): ?>
                <div><span class="badge bg-danger">Low</span></div>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= h(ops_qty($it['min_qty'])) ?></td>
            <td>
              <?php if ((int)$it['is_active'] === 1): ?>
              <form method="post" class="row g-1 align-items-center">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                <?php ops_keep_move_filter($filterItem, $filterFrom, $filterTo); ?>
                <div class="col-4">
                  <input type="number" step="0.001" min="0" name="qty" class="form-control form-control-sm" placeholder="Qty" required>
                </div>
                <div class="col-4">
                  <input type="text" name="note" class="form-control form-control-sm" placeholder="Note (optional)">
                </div>
                <div class="col-4 d-flex gap-1">
                  <button name="direction" value="in" class="btn btn-sm btn-success flex-fill" title="Add to stock">
                    <i class="bi bi-plus-lg"></i> In
                  </button>
                  <button name="direction" value="out" class="btn btn-sm btn-outline-danger flex-fill" title="Take out of stock">
                    <i class="bi bi-dash-lg"></i> Out
                  </button>
                </div>
              </form>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <a href="<?= h($opsBase) ?>/item_moves.php?id=<?= (int)$it['id'] ?>"
                 class="btn btn-sm btn-outline-primary" title="Every movement of this item, with a date filter">
                <i class="bi bi-clock-history"></i> History
              </a>
              <a href="<?= h($opsBase) ?>/item_form.php?id=<?= (int)$it['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
              <form method="post" class="d-inline">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="<?= (int)$it['is_active'] === 1 ? 'archive' : 'restore' ?>">
                <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                <input type="hidden" name="show" value="<?= $showArchived ? 'archived' : '' ?>">
                <?php ops_keep_move_filter($filterItem, $filterFrom, $filterTo); ?>
                <button class="btn btn-sm btn-outline-<?= (int)$it['is_active'] === 1 ? 'danger' : 'success' ?>">
                  <?= (int)$it['is_active'] === 1 ? 'Hide' : 'Restore' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">
  <h6 class="fw-bold mb-0"><?= $moveFiltered ? 'Movements' : 'Recent movements' ?></h6>
  <?php if ($moves): ?>
    <div class="small text-muted">
      <?= count($moves) ?> movement<?= count($moves) === 1 ? '' : 's' ?>
      <?php if ($movedOut > 0): ?>
        · <span class="text-danger fw-semibold">−<?= h(ops_qty($movedOut)) ?></span> out
      <?php endif; ?>
      <?php if ($movedIn > 0): ?>
        · <span class="text-success fw-semibold">+<?= h(ops_qty($movedIn)) ?></span> in
      <?php endif; ?>
      <?php if ($filterItem <= 0 && ($movedIn > 0 || $movedOut > 0)): ?>
        <span class="text-muted">(all items together)</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php // Filtering is a GET so the view can be linked, bookmarked and sent to
      // somebody else — "what did we get through in August" is a question you
      // ask twice. ?>
<form method="get" class="row g-2 align-items-end mb-3">
  <?php if ($showArchived): ?><input type="hidden" name="show" value="archived"><?php endif; ?>
  <div class="col-md-4">
    <label class="form-label small fw-semibold mb-1">Item</label>
    <select name="item" class="form-select form-select-sm" data-search>
      <option value="">All items</option>
      <?php foreach ($filterItems as $fi): ?>
        <option value="<?= (int)$fi['id'] ?>"<?= $filterItem === (int)$fi['id'] ? ' selected' : '' ?>>
          <?= h($fi['name']) ?><?= (int)$fi['is_active'] === 0 ? ' (hidden)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label small fw-semibold mb-1">From</label>
    <input type="date" name="from" value="<?= h($filterFrom) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label small fw-semibold mb-1">To</label>
    <input type="date" name="to" value="<?= h($filterTo) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-2 d-flex gap-1">
    <button class="btn btn-sm btn-outline-secondary flex-fill"><i class="bi bi-funnel"></i> Filter</button>
    <?php if ($moveFiltered): ?>
      <a href="?<?= $showArchived ? 'show=archived' : '' ?>" class="btn btn-sm btn-light" title="Clear the filter">Clear</a>
    <?php endif; ?>
  </div>
</form>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table ops-table align-middle">
      <thead>
        <tr><th>When</th><th>Item</th><th>Change</th><th>Why</th><th>By</th></tr>
      </thead>
      <tbody>
        <?php if (!$moves): ?>
          <tr><td colspan="5" class="text-center text-muted py-4"><?= $moveFiltered ? 'Nothing moved that matches this filter.' : 'No movements yet.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($moves as $m): ?>
          <?php $in = (float)$m['qty_change'] > 0; ?>
          <tr>
            <td class="small text-muted"><?= h(date('d M, g:i A', strtotime($m['created_at']))) ?></td>
            <td>
              <a href="<?= h($opsBase) ?>/item_moves.php?id=<?= (int)$m['item_id'] ?>"
                 class="text-decoration-none"><?= h($m['item_name']) ?></a>
            </td>
            <td class="text-nowrap">
              <span class="ops-pill <?= $in ? 'ops-pill-in' : 'ops-pill-out' ?>">
                <?= $in ? '+' : '−' ?><?= h(ops_qty(abs((float)$m['qty_change']))) ?><?= $m['unit'] ? ' ' . h($m['unit']) : '' ?>
              </span>
            </td>
            <td class="small">
              <?php if (!empty($m['job_id']) && !empty($m['job_title'])): ?>
                <?php // Nine jobs a day can share one title, so the link has to
                      // say which one or it sends you to the wrong job. ?>
                Used on <a href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$m['job_id'] ?>"><?= h($m['job_title']) ?></a>
                <span class="text-muted"><?= h(ops_job_where($m)) ?></span>
              <?php elseif ($m['reason'] === 'in'): ?>
                Added to stock
              <?php elseif ($m['reason'] === 'out'): ?>
                Taken out
              <?php else: ?>
                Correction
              <?php endif; ?>
              <?php if (!empty($m['note'])): ?>
                <span class="text-muted">— <?= h($m['note']) ?></span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= h($m['person'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
