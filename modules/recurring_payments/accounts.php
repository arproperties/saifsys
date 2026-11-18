<?php
/**
 * Recurring Payments — the module's own accounts: where the money is.
 * A free list ("Cash to Mr Tauqeer", "Bank"), used only here. Reem keeps it, adds up
 * what each account holds, and decides what is allowed.
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
$selfUrl = $rpayBase . '/accounts.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['account'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    try {
        if ($action === 'add') {
            $a = rpay_reem($code, 'POST', '/accounts', ['name' => $name]);
            binv_flash('"' . $a['name'] . '" added.');
        } elseif ($action === 'rename') {
            $a = rpay_reem($code, 'PUT', '/accounts/' . $id, ['name' => $name]);
            binv_flash('Renamed to "' . $a['name'] . '".');
        } elseif ($action === 'delete') {
            rpay_reem($code, 'DELETE', '/accounts/' . $id);
            binv_flash('Account deleted.');
        } elseif ($action === 'hide' || $action === 'show') {
            // Hidden in saifsys only; the account keeps its money and history in Reem.
            $acc = null;
            foreach (rpay_reem($code, 'GET', '/accounts')['accounts'] ?? [] as $x) {
                if ((int)$x['id'] === $id) {
                    $acc = $x;
                }
            }
            if (!$acc) {
                throw new BinvError('That account could not be found.', 404);
            }
            rpay_set_account_hidden($conn, $id, $action === 'hide', (int)current_user_id());
            binv_flash('"' . $acc['name'] . '" ' . ($action === 'hide' ? 'hidden. It is no longer offered when money comes in or is transferred; find it under "Hidden accounts".' : 'is shown again.'));
        }
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        error_log('recurrent income: account not hidden: ' . $e->getMessage());
        binv_flash('That could not be saved.', 'danger');
    }
    header('Location: ' . $selfUrl);
    exit;
}

try {
    $allAccounts = rpay_reem($code, 'GET', '/accounts')['accounts'] ?? [];
} catch (BinvError $e) {
    rpay_stop($e);
}
$hiddenMap = rpay_hidden_accounts($conn);
$accounts = rpay_visible_accounts($conn, $allAccounts);
$hiddenAccounts = array_values(array_filter($allAccounts, function ($a) use ($hiddenMap) { return isset($hiddenMap[(int)$a['id']]); }));

$pageTitle = 'Accounts';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <div class="page-header-label mb-1">Accounts</div>
    <p class="text-muted mb-0">Where the money is. Pick one when a payment is marked paid; move money between them with a transfer.</p>
  </div>
  <?php if (count($accounts) > 1): ?>
    <a href="<?= h($rpayBase) ?>/transfers.php" class="btn text-white" style="background:var(--primary)">
      <i class="bi bi-arrow-left-right"></i> Transfer funds
    </a>
  <?php endif; ?>
</div>

<div class="card card-round mb-3" style="max-width:820px">
  <div class="card-body">
    <form method="post" action="<?= h($selfUrl) ?>" class="d-flex gap-2">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="add">
      <input type="text" name="name" class="form-control" maxlength="<?= RPAY_NAME_MAX ?>" placeholder="Account name, e.g. Cash to Mr Tauqeer" required <?= $accounts ? '' : 'autofocus' ?>>
      <button class="btn text-white text-nowrap" style="background:var(--primary)"><i class="bi bi-plus-lg"></i> Add account</button>
    </form>
  </div>
</div>

<div class="card card-round" style="max-width:820px">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Account</th><th class="text-end">Holds</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$accounts): ?>
          <tr><td colspan="3" class="text-muted text-center py-5">No accounts yet. Add the first one above.</td></tr>
        <?php endif; ?>
        <?php foreach ($accounts as $a): ?>
          <tr>
            <td>
              <form method="post" action="<?= h($selfUrl) ?>" class="d-flex gap-2">
                <?php csrf_field(); ?>
                <input type="hidden" name="account" value="<?= (int)$a['id'] ?>">
                <input type="hidden" name="action" value="rename">
                <input type="text" name="name" class="form-control form-control-sm" maxlength="<?= RPAY_NAME_MAX ?>" value="<?= h($a['name']) ?>" required>
                <button class="btn btn-sm btn-light border text-nowrap">Save name</button>
              </form>
            </td>
            <td class="num text-end fw-semibold<?= (float)$a['balance'] < 0 ? ' text-danger' : '' ?>"><?= h(rpay_money($a['balance'] ?? 0)) ?></td>
            <td class="text-end text-nowrap">
              <a href="<?= h($rpayBase) ?>/account.php?id=<?= (int)$a['id'] ?>" class="btn btn-sm btn-light border">History</a>
              <?php $holds = abs((float)($a['balance'] ?? 0)) >= 0.005; ?>
              <form method="post" action="<?= h($selfUrl) ?>" class="d-inline"
                    onsubmit="return confirm(<?= h(json_encode('Hide "' . $a['name'] . '"?' . ($holds ? ' It still holds ' . rpay_money($a['balance']) . '.' : '') . ' It stops being offered when money comes in or is transferred. Its history stays, and you can show it again.')) ?>);">
                <?php csrf_field(); ?>
                <input type="hidden" name="account" value="<?= (int)$a['id'] ?>">
                <input type="hidden" name="action" value="hide">
                <button class="btn btn-sm btn-light border" title="Hide this account"><i class="bi bi-eye-slash"></i> Hide</button>
              </form>
              <?php if (empty($a['used'])): ?>
                <form method="post" action="<?= h($selfUrl) ?>" class="d-inline" onsubmit="return confirm('Delete this account?');">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="account" value="<?= (int)$a['id'] ?>">
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

<?php if ($hiddenAccounts): ?>
<details class="card card-round mt-3" style="max-width:820px">
  <summary class="card-body py-2 fw-semibold">Hidden accounts <span class="text-muted fw-normal">(<?= count($hiddenAccounts) ?>)</span></summary>
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <tbody>
        <?php foreach ($hiddenAccounts as $a): $hd = $hiddenMap[(int)$a['id']]; ?>
          <tr>
            <td>
              <div class="text-muted"><?= h($a['name']) ?></div>
              <div class="small text-muted">Hidden by <?= h($hd['by'] !== '' ? $hd['by'] : 'someone') ?> · <?= h(rpay_datetime($hd['at'])) ?></div>
            </td>
            <td class="num text-end text-muted"><?= h(rpay_money($a['balance'] ?? 0)) ?></td>
            <td class="text-end text-nowrap">
              <a href="<?= h($rpayBase) ?>/account.php?id=<?= (int)$a['id'] ?>" class="btn btn-sm btn-light border">History</a>
              <form method="post" action="<?= h($selfUrl) ?>" class="d-inline">
                <?php csrf_field(); ?>
                <input type="hidden" name="account" value="<?= (int)$a['id'] ?>">
                <input type="hidden" name="action" value="show">
                <button class="btn btn-sm btn-light border"><i class="bi bi-eye"></i> Show</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
