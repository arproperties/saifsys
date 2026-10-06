<?php
/**
 * Recurring Payments — transfer funds: money handed from one account to another, with
 * a file to keep with it, and every transfer made so far.
 *
 *   transfers.php            the form and the list
 *   transfers.php?from=2     the form with that account already picked
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
$selfUrl = $rpayBase . '/transfers.php';

rpay_too_big($selfUrl);

$errors = [];
$form = ['from_id' => (string)(int)($_GET['from'] ?? 0), 'to_id' => '', 'amount' => '', 'date' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'delete') {
        try {
            rpay_reem($code, 'DELETE', '/transfers/' . (int)($_POST['transfer'] ?? 0));
            binv_flash('Transfer deleted. Both accounts are as they were before it.');
        } catch (BinvError $e) {
            binv_flash($e->getMessage(), 'danger');
        }
        header('Location: ' . $selfUrl);
        exit;
    }
    foreach (array_keys($form) as $k) {
        $form[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    }
    try {
        $t = rpay_reem($code, 'POST', '/transfers', $form);
        // The file comes second, and its failure is said out loud: the transfer is made either way.
        $fileError = rpay_send_file($code, '/transfers/' . (int)$t['id'] . '/attachment', $_FILES['attachment'] ?? null);
        $done = rpay_money($t['amount']) . ' transferred from "' . $t['from']['name'] . '" to "' . $t['to']['name'] . '"';
        if ($fileError !== null) {
            binv_flash($done . ', but its attachment was not kept: ' . $fileError, 'warning');
        } else {
            binv_flash($done . '.');
        }
        header('Location: ' . $selfUrl);
        exit;
    } catch (BinvError $e) {
        $errors[] = $e->getMessage();
    }
}

try {
    $data = rpay_reem($code, 'GET', '/transfers');
} catch (BinvError $e) {
    rpay_stop($e);
}
$accounts = $data['accounts'] ?? [];
$transfers = $data['transfers'] ?? [];
if ($form['date'] === '') {
    $form['date'] = (string)($data['today'] ?? date('Y-m-d'));
}

$pageTitle = 'Transfers';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<div class="mb-3">
  <div class="page-header-label mb-1">Transfer funds</div>
  <p class="text-muted mb-0">When money is handed from one account to another, like cash given to Mr Amran.</p>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if (count($accounts) < 2): ?>
  <div class="alert alert-warning" style="max-width:680px">
    A transfer needs two accounts. <a href="<?= h($rpayBase) ?>/accounts.php">Add accounts</a> first.
  </div>
<?php else: ?>
<div class="card card-round mb-4" style="max-width:680px">
  <div class="card-body">
    <form method="post" class="row g-3" action="<?= h($selfUrl) ?>" enctype="multipart/form-data">
      <?php csrf_field(); ?>

      <div class="col-md-6">
        <label class="form-label fw-semibold">From</label>
        <select name="from_id" class="form-select" data-search data-placeholder="The account the money comes from" required>
          <option value=""></option>
          <?php foreach ($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>"<?= $form['from_id'] === (string)$a['id'] ? ' selected' : '' ?>><?= h($a['name']) ?> (<?= h(rpay_money($a['balance'] ?? 0)) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">To</label>
        <select name="to_id" class="form-select" data-search data-placeholder="The account the money goes to" required>
          <option value=""></option>
          <?php foreach ($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>"<?= $form['to_id'] === (string)$a['id'] ? ' selected' : '' ?>><?= h($a['name']) ?> (<?= h(rpay_money($a['balance'] ?? 0)) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Amount</label>
        <div class="input-group">
          <span class="input-group-text">AED</span>
          <input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="100000000" value="<?= h($form['amount']) ?>" required>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Date</label>
        <input type="date" name="date" class="form-control" value="<?= h($form['date']) ?>" required>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Notes <span class="text-muted fw-normal small">(optional)</span></label>
        <textarea name="notes" class="form-control" rows="2" maxlength="<?= RPAY_NOTES_MAX ?>" placeholder="What it was for, who handed it over"><?= h($form['notes']) ?></textarea>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Attachment <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="file" name="attachment" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
        <div class="form-text">A picture or a PDF, 8 MB at most.</div>
      </div>

      <div class="col-12">
        <button class="btn btn-lg text-white" style="background:var(--primary)"><i class="bi bi-arrow-left-right"></i> Transfer</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Date</th><th>From</th><th>To</th><th class="text-end">Amount</th><th>Notes</th><th>By</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$transfers): ?>
          <tr><td colspan="7" class="text-muted text-center py-5">No transfers yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($transfers as $t): ?>
          <tr>
            <td class="num"><?= h(rpay_date($t['date'] ?? '')) ?></td>
            <td><?= h($t['from']['name'] ?? '') ?></td>
            <td><?= h($t['to']['name'] ?? '') ?></td>
            <td class="num text-end fw-semibold"><?= h(rpay_money($t['amount'] ?? 0)) ?></td>
            <td><?= h($t['notes'] ?? '') ?></td>
            <td><?= h($t['by'] ?? '') ?></td>
            <td class="text-end text-nowrap">
              <?php if (!empty($t['attachment'])): ?>
                <a class="btn btn-sm btn-light border" target="_blank" rel="noopener" title="Attachment" href="<?= h(rpay_file_url($rpayBase, 'transfer', (int)$t['id'])) ?>"><i class="bi bi-paperclip"></i></a>
              <?php endif; ?>
              <form method="post" action="<?= h($selfUrl) ?>" class="d-inline" onsubmit="return confirm('Delete this transfer? Both accounts go back to what they held before it.');">
                <?php csrf_field(); ?>
                <input type="hidden" name="transfer" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
