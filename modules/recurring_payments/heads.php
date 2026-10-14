<?php
/**
 * Recurring Payments — the module's own income heads: add, rename, delete.
 * What a payment is for ("Shop", "DEWA", "Washing Machine"): one list for every building,
 * typed here and used only here. Reem keeps the list and decides what is allowed
 * (a name once, and no deleting a head that has entries).
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
$selfUrl = $rpayBase . '/heads.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['head'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    try {
        if ($action === 'add') {
            $head = rpay_reem($code, 'POST', '/heads', ['name' => $name]);
            binv_flash('"' . $head['name'] . '" added.');
        } elseif ($action === 'rename') {
            $head = rpay_reem($code, 'PUT', '/heads/' . $id, ['name' => $name]);
            binv_flash('Renamed to "' . $head['name'] . '".');
        } elseif ($action === 'delete') {
            rpay_reem($code, 'DELETE', '/heads/' . $id);
            binv_flash('Income head deleted.');
        }
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    }
    header('Location: ' . $selfUrl);
    exit;
}

try {
    $heads = rpay_reem($code, 'GET', '/heads')['heads'] ?? [];
} catch (BinvError $e) {
    rpay_stop($e);
}

$pageTitle = 'Income Heads';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<div class="mb-3">
  <div class="page-header-label mb-1">Income Heads</div>
  <p class="text-muted mb-0">What a payment is for, like Shop, DEWA or Washing Machine. One list for every building; pick the head on each entry.</p>
</div>

<div class="card card-round mb-3" style="max-width:680px">
  <div class="card-body">
    <form method="post" action="<?= h($selfUrl) ?>" class="d-flex gap-2">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="add">
      <input type="text" name="name" class="form-control" maxlength="<?= RPAY_NAME_MAX ?>" placeholder="Income head name" required <?= $heads ? '' : 'autofocus' ?>>
      <button class="btn text-white text-nowrap" style="background:var(--primary)"><i class="bi bi-plus-lg"></i> Add income head</button>
    </form>
  </div>
</div>

<div class="card card-round" style="max-width:680px">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Income head</th><th>Entries</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$heads): ?>
          <tr><td colspan="3" class="text-muted text-center py-5">No income heads yet. Add the first one above.</td></tr>
        <?php endif; ?>
        <?php foreach ($heads as $head): ?>
          <tr>
            <td>
              <form method="post" action="<?= h($selfUrl) ?>" class="d-flex gap-2">
                <?php csrf_field(); ?>
                <input type="hidden" name="head" value="<?= (int)$head['id'] ?>">
                <input type="hidden" name="action" value="rename">
                <input type="text" name="name" class="form-control form-control-sm" maxlength="<?= RPAY_NAME_MAX ?>" value="<?= h($head['name']) ?>" required>
                <button class="btn btn-sm btn-light border text-nowrap">Save name</button>
              </form>
            </td>
            <td class="num"><?= (int)($head['entries'] ?? 0) ?></td>
            <td class="text-end">
              <?php if ((int)($head['entries'] ?? 0) === 0): ?>
                <form method="post" action="<?= h($selfUrl) ?>" class="d-inline" onsubmit="return confirm('Delete this income head?');">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="head" value="<?= (int)$head['id'] ?>">
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
