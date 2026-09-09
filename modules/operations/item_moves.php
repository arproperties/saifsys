<?php
/**
 * Operations — one stock item's history.
 *
 * The Stock page answers "what do we hold"; this answers "where did it go".
 * Every row is a movement, and the balance column is what was on the shelf
 * after it — so a wrong number can be traced back to the movement that made it.
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

$itemId = (int)($_GET['id'] ?? 0);
$itemStmt = $conn->prepare("SELECT * FROM ops_items WHERE id = ? AND company_id = ? LIMIT 1");
$itemStmt->execute([$itemId, $companyId]);
$item = $itemStmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$item) {
    ops_flash('That item could not be found.', 'danger');
    header('Location: ' . $opsBase . '/stock.php');
    exit;
}

/** Accept only a real Y-m-d — anything else is treated as "not set". */
$validDate = static function (string $d): string {
    $d = trim($d);
    $dt = $d !== '' ? DateTime::createFromFormat('Y-m-d', $d) : false;
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$from = $validDate((string)($_GET['from'] ?? ''));
$to   = $validDate((string)($_GET['to'] ?? ''));

// Backwards dates are a typo, not an empty result — swap them and carry on.
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}

$where  = ['m.item_id = ?', 'm.company_id = ?'];
$params = [$itemId, $companyId];
$toExclusive = null;
if ($from !== '') {
    $where[]  = 'm.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    // Inclusive of the whole closing day, whatever time of day it was recorded.
    $toExclusive = (new DateTime($to . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');
    $where[]  = 'm.created_at < ?';
    $params[] = $toExclusive;
}
$filtered = $from !== '' || $to !== '';

$movesStmt = $conn->prepare("
    SELECT m.*, COALESCE(NULLIF(u.fullname, ''), u.username) AS person,
           j.title AS job_title, j.location AS job_location, j.scheduled_date AS job_date
    FROM ops_stock_moves m
    LEFT JOIN user u ON u.id = m.created_by
    LEFT JOIN ops_jobs j ON j.id = m.job_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY m.created_at ASC, m.id ASC
    LIMIT 1000
");
$movesStmt->execute($params);
$moves = $movesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// What was on the shelf before the first row on screen. Without it a filtered
// range would count up from zero and every balance would be wrong.
$opening = 0.0;
if ($from !== '') {
    $openStmt = $conn->prepare("
        SELECT COALESCE(SUM(qty_change), 0) FROM ops_stock_moves
        WHERE item_id = ? AND company_id = ? AND created_at < ?
    ");
    $openStmt->execute([$itemId, $companyId, $from . ' 00:00:00']);
    $opening = (float)$openStmt->fetchColumn();
}

// Balance forward, then show newest first — the ledger is built in the order it
// happened and read in the order people look at it.
$balance = $opening;
$totalIn = $totalOut = 0.0;
foreach ($moves as $i => $m) {
    $change = (float)$m['qty_change'];
    $balance += $change;
    $moves[$i]['balance_after'] = $balance;
    if ($change > 0) {
        $totalIn += $change;
    } else {
        $totalOut += abs($change);
    }
}
$closing = $balance;
$moves = array_reverse($moves);

$unit = (string)($item['unit'] ?? '');
$pageTitle = $item['name'] . ' — history';
require __DIR__ . '/includes/ops_layout_header.php';
?>

<a href="<?= h($opsBase) ?>/stock.php" class="text-decoration-none small">
  <i class="bi bi-arrow-left"></i> Back to stock
</a>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mt-2 mb-4">
  <div>
    <div class="page-header-label"><?= h($item['name']) ?></div>
    <div class="text-muted">
      <?php if ((int)$item['is_active'] === 0): ?>
        <span class="badge bg-light text-dark border">Hidden</span>
      <?php endif; ?>
      <?php if (!empty($item['notes'])): ?><?= h($item['notes']) ?><?php endif; ?>
    </div>
  </div>
  <a href="<?= h($opsBase) ?>/item_form.php?id=<?= (int)$item['id'] ?>" class="btn btn-outline-secondary">
    <i class="bi bi-pencil"></i> Edit item
  </a>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card card-round h-100"><div class="card-body">
      <div class="stat-label">In stock now</div>
      <div class="fs-4 fw-bold <?= (float)$item['current_qty'] <= (float)$item['min_qty'] ? 'text-danger' : '' ?>">
        <?= h(ops_qty($item['current_qty'])) ?> <span class="text-muted fs-6 fw-normal"><?= h($unit) ?></span>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card card-round h-100"><div class="card-body">
      <div class="stat-label">Taken out<?= $filtered ? ' in this range' : '' ?></div>
      <div class="fs-4 fw-bold text-danger">−<?= h(ops_qty($totalOut)) ?></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card card-round h-100"><div class="card-body">
      <div class="stat-label">Added<?= $filtered ? ' in this range' : '' ?></div>
      <div class="fs-4 fw-bold text-success">+<?= h(ops_qty($totalIn)) ?></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card card-round h-100"><div class="card-body">
      <div class="stat-label"><?= $filtered ? 'Balance at the end' : 'Movements' ?></div>
      <div class="fs-4 fw-bold">
        <?= $filtered ? h(ops_qty($closing)) : count($moves) ?>
      </div>
    </div></div>
  </div>
</div>

<?php // Filtering is a GET so the view can be linked, bookmarked and sent to
      // somebody else — "what did we get through in August" is a question you
      // ask twice. ?>
<form method="get" class="row g-2 align-items-end mb-3">
  <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
  <div class="col-md-3">
    <label class="form-label small fw-semibold mb-1">From</label>
    <input type="date" name="from" value="<?= h($from) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label small fw-semibold mb-1">To</label>
    <input type="date" name="to" value="<?= h($to) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3 d-flex gap-1">
    <button class="btn btn-sm btn-outline-secondary flex-fill"><i class="bi bi-funnel"></i> Filter</button>
    <?php if ($filtered): ?>
      <a href="?id=<?= (int)$item['id'] ?>" class="btn btn-sm btn-light">Clear</a>
    <?php endif; ?>
  </div>
  <div class="col-md-3 text-md-end">
    <?php // The three ranges anybody actually asks for, one press each. ?>
    <?php
      $today = new DateTime('today');
      $quick = [
          'This month' => [(clone $today)->modify('first day of this month'), $today],
          'Last 30 days' => [(clone $today)->modify('-29 days'), $today],
      ];
    ?>
    <?php foreach ($quick as $label => [$qFrom, $qTo]): ?>
      <a class="btn btn-sm btn-light"
         href="?id=<?= (int)$item['id'] ?>&amp;from=<?= h($qFrom->format('Y-m-d')) ?>&amp;to=<?= h($qTo->format('Y-m-d')) ?>">
        <?= h($label) ?>
      </a>
    <?php endforeach; ?>
  </div>
</form>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table ops-table align-middle">
      <thead>
        <tr><th>When</th><th>Change</th><th>Left after</th><th>Why</th><th>By</th></tr>
      </thead>
      <tbody>
        <?php if (!$moves): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">
            <?= $filtered ? 'Nothing moved in this date range.' : 'No movements yet.' ?>
          </td></tr>
        <?php endif; ?>

        <?php foreach ($moves as $m): ?>
          <?php $in = (float)$m['qty_change'] > 0; ?>
          <tr>
            <td class="small text-muted text-nowrap"><?= h(date('d M Y, g:i A', strtotime($m['created_at']))) ?></td>
            <td class="text-nowrap">
              <span class="ops-pill <?= $in ? 'ops-pill-in' : 'ops-pill-out' ?>">
                <?= $in ? '+' : '−' ?><?= h(ops_qty(abs((float)$m['qty_change']))) ?><?= $unit !== '' ? ' ' . h($unit) : '' ?>
              </span>
            </td>
            <td class="text-nowrap fw-semibold num"><?= h(ops_qty($m['balance_after'])) ?></td>
            <td class="small">
              <?php if (!empty($m['job_id']) && !empty($m['job_title'])): ?>
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

        <?php // Only a start date leaves something behind it worth stating. A
              // range with no start already begins at zero. ?>
        <?php if ($from !== '' && $moves): ?>
          <tr class="table-light">
            <td class="small text-muted">Before <?= h(date('d M Y', strtotime($from))) ?></td>
            <td class="small text-muted">Opening</td>
            <td class="fw-semibold"><?= h(ops_qty($opening)) ?></td>
            <td colspan="2"></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
