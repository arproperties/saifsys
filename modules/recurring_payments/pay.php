<?php
/**
 * Recurring Payments — mark one payment paid: the account the money went into, and a
 * file to keep with it (a receipt). Also changes either on a payment already paid.
 *
 *   pay.php?due=12                      from the month's list
 *   pay.php?due=12&month=2026-09&...    the list's filters ride along, to go back to
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
$dueId = (int)($_GET['due'] ?? 0);
$filters = [];
foreach (['month', 'building', 'unit', 'status'] as $k) {
    if (is_string($_GET[$k] ?? null) && $_GET[$k] !== '') {
        $filters[$k] = $_GET[$k];
    }
}
$backUrl = $rpayBase . '/index.php' . ($filters ? '?' . http_build_query($filters) : '');
$selfUrl = $rpayBase . '/pay.php?' . http_build_query($filters + ['due' => $dueId]);

rpay_too_big($selfUrl);

try {
    $data = rpay_reem($code, 'GET', '/dues/' . $dueId);
} catch (BinvError $e) {
    binv_flash($e->getMessage(), 'danger');
    header('Location: ' . $backUrl);
    exit;
}
$due = $data['due'];
$accounts = $data['accounts'] ?? [];
$paid = ($due['status'] ?? '') === 'paid';
$picked = (string)($due['account']['id'] ?? '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $picked = is_string($_POST['account_id'] ?? null) ? $_POST['account_id'] : '';
    try {
        rpay_reem($code, 'POST', '/dues/' . $dueId . '/paid', ['account_id' => $picked]);
        // The file comes second, and its failure is said out loud: the payment is paid either way.
        $fileError = rpay_send_file($code, '/dues/' . $dueId . '/attachment', $_FILES['attachment'] ?? null);
        if ($fileError !== null) {
            binv_flash('"' . $due['title'] . '" is paid, but its attachment was not kept: ' . $fileError, 'warning');
        } else {
            binv_flash('"' . $due['title'] . '" is paid.');
        }
        header('Location: ' . $backUrl);
        exit;
    } catch (BinvError $e) {
        $errors[] = $e->getMessage();
    }
}

$pageTitle = $paid ? 'Change payment' : 'Mark paid';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<a href="<?= h($backUrl) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to payments</a>
<div class="page-header-label mt-2 mb-4"><?= $paid ? 'Change payment' : 'Mark paid' ?></div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="card card-round mb-4" style="max-width:680px">
  <div class="card-body">
    <div class="mb-3">
      <div class="fw-semibold fs-5"><?= h($due['title'] ?? '') ?></div>
      <div class="text-muted">
        <?= h($due['building']['name'] ?? '') ?><?= !empty($due['unit']) ? ' · ' . h($due['unit']) : '' ?> · <?= h(rpay_month_label((string)($due['month'] ?? ''))) ?>
      </div>
      <div class="rpay-total mt-1"><?= h(rpay_money($due['amount'] ?? 0)) ?></div>
    </div>

    <?php if (!$accounts): ?>
      <div class="alert alert-warning mb-0">
        There is no account yet to receive the money. <a href="<?= h($rpayBase) ?>/accounts.php">Add an account</a> first, like "Cash to Mr Tauqeer".
      </div>
    <?php else: ?>
      <form method="post" class="row g-3" action="<?= h($selfUrl) ?>" enctype="multipart/form-data">
        <?php csrf_field(); ?>

        <div class="col-12">
          <label class="form-label fw-semibold">Received in</label>
          <select name="account_id" class="form-select" data-search data-placeholder="Pick the account the money went into" required>
            <option value=""></option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>"<?= $picked === (string)$a['id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold">Attachment <span class="text-muted fw-normal small">(optional)</span></label>
          <?php if (!empty($due['attachment'])): ?>
            <div class="mb-2">
              <a target="_blank" rel="noopener" href="<?= h(rpay_file_url($rpayBase, 'due', $dueId)) ?>"><i class="bi bi-paperclip"></i> See the one kept now</a>
            </div>
          <?php endif; ?>
          <input type="file" name="attachment" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
          <div class="form-text">A picture or a PDF, 8 MB at most.<?= !empty($due['attachment']) ? ' Choosing a new one replaces the one kept now.' : '' ?></div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-lg btn-success"><i class="bi bi-check-lg"></i> <?= $paid ? 'Save' : 'Mark paid' ?></button>
          <a href="<?= h($backUrl) ?>" class="btn btn-lg btn-light">Cancel</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
