<?php
/**
 * Recurring Payments — the module's own buildings: add, rename, delete.
 * Typed here and used only here. Reem keeps the list and decides what is allowed
 * (a name once, and no deleting a building that has entries).
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
$selfUrl = $rpayBase . '/buildings.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['building'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    try {
        if ($action === 'add') {
            $b = rpay_reem($code, 'POST', '/buildings', ['name' => $name]);
            binv_flash('"' . $b['name'] . '" added.');
        } elseif ($action === 'rename') {
            $b = rpay_reem($code, 'PUT', '/buildings/' . $id, ['name' => $name]);
            binv_flash('Renamed to "' . $b['name'] . '".');
        } elseif ($action === 'delete') {
            rpay_reem($code, 'DELETE', '/buildings/' . $id);
            binv_flash('Building deleted.');
        }
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    }
    header('Location: ' . $selfUrl);
    exit;
}

try {
    $buildings = rpay_reem($code, 'GET', '/buildings')['buildings'] ?? [];
} catch (BinvError $e) {
    rpay_stop($e);
}

$pageTitle = 'Buildings';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<div class="mb-3">
  <div class="page-header-label mb-1">Buildings</div>
  <p class="text-muted mb-0">The buildings of Recurring Payments only. Add any building you collect a payment from.</p>
</div>

<div class="card card-round mb-3" style="max-width:680px">
  <div class="card-body">
    <form method="post" action="<?= h($selfUrl) ?>" class="d-flex gap-2">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="add">
      <input type="text" name="name" class="form-control" maxlength="<?= RPAY_NAME_MAX ?>" placeholder="Building name" required <?= $buildings ? '' : 'autofocus' ?>>
      <button class="btn text-white text-nowrap" style="background:var(--primary)"><i class="bi bi-plus-lg"></i> Add building</button>
    </form>
  </div>
</div>

<div class="card card-round" style="max-width:680px">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Building</th><th>Entries</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$buildings): ?>
          <tr><td colspan="3" class="text-muted text-center py-5">No buildings yet. Add the first one above.</td></tr>
        <?php endif; ?>
        <?php foreach ($buildings as $b): ?>
          <tr>
            <td>
              <form method="post" action="<?= h($selfUrl) ?>" class="d-flex gap-2">
                <?php csrf_field(); ?>
                <input type="hidden" name="building" value="<?= (int)$b['id'] ?>">
                <input type="hidden" name="action" value="rename">
                <input type="text" name="name" class="form-control form-control-sm" maxlength="<?= RPAY_NAME_MAX ?>" value="<?= h($b['name']) ?>" required>
                <button class="btn btn-sm btn-light border text-nowrap">Save name</button>
              </form>
            </td>
            <td class="num"><?= (int)($b['entries'] ?? 0) ?></td>
            <td class="text-end">
              <?php if ((int)($b['entries'] ?? 0) === 0): ?>
                <form method="post" action="<?= h($selfUrl) ?>" class="d-inline" onsubmit="return confirm('Delete this building?');">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="building" value="<?= (int)$b['id'] ?>">
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
