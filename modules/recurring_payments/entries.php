<?php
/**
 * Recurring Payments — the entries: what repeats, where, how much, on which day.
 * Pause, resume, create this month's payment by hand, and delete are passed to Reem,
 * which decides whether each is allowed.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/rpay_helper.php';

require_login(get_application_web_root() . '/login');
$code = binv_boot($conn)['code'];

$rpayBase = get_application_web_root() . '/modules/recurring_payments';
$selfUrl = $rpayBase . '/entries.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['entry'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'pause' || $action === 'resume') {
            $entry = rpay_reem($code, 'POST', '/entries/' . $id . '/active', ['active' => $action === 'resume']);
            binv_flash('"' . $entry['title'] . '" ' . ($action === 'resume' ? 'resumed.' : 'paused. No new payments will be created for it.'));
        } elseif ($action === 'create') {
            $due = rpay_reem($code, 'POST', '/entries/' . $id . '/dues');
            binv_flash('Payment for ' . rpay_month_label((string)($due['month'] ?? '')) . ' created for "' . ($due['title'] ?? '') . '".');
        } elseif ($action === 'delete') {
            rpay_reem($code, 'DELETE', '/entries/' . $id);
            binv_flash('Entry deleted.');
        }
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    }
    header('Location: ' . $selfUrl);
    exit;
}

try {
    $data = rpay_reem($code, 'GET', '/entries');
} catch (BinvError $e) {
    rpay_stop($e);
}
$entries = $data['entries'] ?? [];
if (!($data['buildings'] ?? [])) {
    rpay_stop(new BinvError('No building is yours to keep payments for. In Reem, the master names the administrator of each building.', 403));
}

$pageTitle = 'Entries';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <div class="page-header-label mb-1">Entries</div>
    <p class="text-muted mb-0">Add each thing once. Its payment is created every month on the day you pick.</p>
  </div>
  <a href="<?= h($rpayBase) ?>/entry_form.php" class="btn text-white" style="background:var(--primary)">
    <i class="bi bi-plus-lg"></i> Add entry
  </a>
</div>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Title</th><th>Building</th><th>Shop / unit</th><th class="text-end">Amount</th><th>Created on</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$entries): ?>
          <tr><td colspan="7" class="text-muted text-center py-5">No entries yet. Add the first one.</td></tr>
        <?php endif; ?>
        <?php foreach ($entries as $e): ?>
          <?php $active = !empty($e['active']); $auto = !empty($e['auto_create']); ?>
          <tr>
            <td>
              <a class="fw-semibold text-decoration-none" href="<?= h($rpayBase) ?>/entry_form.php?id=<?= (int)$e['id'] ?>"><?= h($e['title'] ?? '') ?></a>
              <?php if (!empty($e['notes'])): ?><div class="small text-muted"><?= h($e['notes']) ?></div><?php endif; ?>
            </td>
            <td><?= h($e['building']['name'] ?? '') ?></td>
            <td><?= h($e['unit'] ?? '') ?></td>
            <td class="num text-end fw-semibold"><?= h(rpay_money($e['amount'] ?? 0)) ?></td>
            <td>
              <?php if ($auto): ?>
                <?= h(rpay_day_label((int)($e['day'] ?? 1))) ?> of each month
              <?php else: ?>
                <span class="text-muted">By hand</span>
              <?php endif; ?>
            </td>
            <td><span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?>"><?= $active ? 'Active' : 'Paused' ?></span></td>
            <td class="text-end text-nowrap">
              <?php if ($active && empty($e['has_this_month'])): ?>
                <form method="post" action="<?= h($selfUrl) ?>" class="d-inline">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="entry" value="<?= (int)$e['id'] ?>">
                  <input type="hidden" name="action" value="create">
                  <button class="btn btn-sm btn-light border" title="Create this month's payment now">Create now</button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= h($selfUrl) ?>" class="d-inline">
                <?php csrf_field(); ?>
                <input type="hidden" name="entry" value="<?= (int)$e['id'] ?>">
                <input type="hidden" name="action" value="<?= $active ? 'pause' : 'resume' ?>">
                <button class="btn btn-sm btn-light border"><?= $active ? 'Pause' : 'Resume' ?></button>
              </form>
              <?php if ((int)($e['payments'] ?? 0) === 0): ?>
                <form method="post" action="<?= h($selfUrl) ?>" class="d-inline" onsubmit="return confirm('Delete this entry? This cannot be undone.');">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="entry" value="<?= (int)$e['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
