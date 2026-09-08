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
    header('Location: ' . $opsBase . '/stock.php' . (($_POST['show'] ?? '') === 'archived' ? '?show=archived' : ''));
    exit;
}

$showArchived = ($_GET['show'] ?? '') === 'archived';
$items = ops_stock_items($conn, $companyId, !$showArchived);
if ($showArchived) {
    $items = array_values(array_filter($items, static fn($i) => (int)$i['is_active'] === 0));
}
$lowCount = ops_low_stock_count($conn, $companyId);

// Recent movements, so you can see where things went.
$movesStmt = $conn->prepare("
    SELECT m.*, i.name AS item_name, i.unit,
           COALESCE(NULLIF(u.fullname, ''), u.username) AS person,
           j.title AS job_title
    FROM ops_stock_moves m
    JOIN ops_items i ON i.id = m.item_id
    LEFT JOIN user u ON u.id = m.created_by
    LEFT JOIN ops_jobs j ON j.id = m.job_id
    WHERE m.company_id = ?
    ORDER BY m.created_at DESC
    LIMIT 40
");
$movesStmt->execute([$companyId]);
$moves = $movesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?= !$showArchived ? 'active' : '' ?>" href="?">In use</a></li>
  <li class="nav-item"><a class="nav-link <?= $showArchived ? 'active' : '' ?>" href="?show=archived">Hidden</a></li>
</ul>

<div class="card card-round mb-4">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
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
              No items yet. <a href="<?= h($opsBase) ?>/item_form.php">Add your first one</a> — for example "Floor cleaner", unit "litre".
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
              <a href="<?= h($opsBase) ?>/item_form.php?id=<?= (int)$it['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
              <form method="post" class="d-inline">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="<?= (int)$it['is_active'] === 1 ? 'archive' : 'restore' ?>">
                <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                <input type="hidden" name="show" value="<?= $showArchived ? 'archived' : '' ?>">
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

<h6 class="fw-bold mb-2">Recent movements</h6>
<div class="card card-round">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>When</th><th>Item</th><th>Change</th><th>Why</th><th>By</th></tr>
      </thead>
      <tbody>
        <?php if (!$moves): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No movements yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($moves as $m): ?>
          <?php $in = (float)$m['qty_change'] > 0; ?>
          <tr>
            <td class="small text-muted"><?= h(date('d M, g:i A', strtotime($m['created_at']))) ?></td>
            <td><?= h($m['item_name']) ?></td>
            <td class="fw-semibold <?= $in ? 'text-success' : 'text-danger' ?>">
              <?= $in ? '+' : '−' ?><?= h(ops_qty(abs((float)$m['qty_change']))) ?>
              <span class="text-muted fw-normal small"><?= h($m['unit'] ?: '') ?></span>
            </td>
            <td class="small">
              <?php if (!empty($m['job_id']) && !empty($m['job_title'])): ?>
                Used on <a href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$m['job_id'] ?>"><?= h($m['job_title']) ?></a>
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
