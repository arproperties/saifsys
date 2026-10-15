<?php
/**
 * Recurring Payments — money received for one payment: all of it, or a part at a time
 * (partial payments). Each receipt has its amount, the account the money went into, the
 * day it was received, and a file to keep with it. The payment is paid once its receipts
 * add up to its amount. A receipt written by mistake is deleted here.
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
foreach (['month', 'building', 'head', 'unit', 'status'] as $k) {
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
$receipts = $data['receipts'] ?? [];
$accounts = $data['accounts'] ?? [];
$left = (float)($due['left'] ?? 0);
$received = (float)($due['received'] ?? 0);
$picked = '';
$paidOn = (string)($data['today'] ?? date('Y-m-d'));
$amount = number_format($left, 2, '.', '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'delete') {
        // A receipt written by mistake: its money is out of its account, and the payment owes it again.
        try {
            rpay_reem($code, 'DELETE', '/receipts/' . (int)($_POST['receipt'] ?? 0));
            binv_flash('The receipt is deleted. "' . $due['title'] . '" owes that amount again.');
        } catch (BinvError $e) {
            binv_flash($e->getMessage(), 'danger');
        }
        header('Location: ' . $selfUrl);
        exit;
    }
    $picked = is_string($_POST['account_id'] ?? null) ? $_POST['account_id'] : '';
    $paidOn = is_string($_POST['paid_on'] ?? null) ? $_POST['paid_on'] : '';
    $amount = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
    try {
        $saved = rpay_reem($code, 'POST', '/dues/' . $dueId . '/receipts', ['account_id' => $picked, 'paid_on' => $paidOn, 'amount' => $amount]);
        $said = ($saved['status'] ?? '') === 'paid'
            ? '"' . $due['title'] . '" is paid in full.'
            : rpay_money($saved['received'] ?? 0) . ' of "' . $due['title'] . '" received. ' . rpay_money($saved['left'] ?? 0) . ' is still to come.';
        // The file comes second, and its failure is said out loud: the money is received either way.
        $fileError = rpay_send_file($code, '/receipts/' . (int)($saved['receipt_id'] ?? 0) . '/attachment', $_FILES['attachment'] ?? null);
        if ($fileError !== null) {
            binv_flash($said . ' But its attachment was not kept: ' . $fileError, 'warning');
        } else {
            binv_flash($said);
        }
        header('Location: ' . $backUrl);
        exit;
    } catch (BinvError $e) {
        $errors[] = $e->getMessage();
    }
}

$pageTitle = $left > 0 ? 'Receive payment' : 'Payment received';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<a href="<?= h($backUrl) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to payments</a>
<div class="page-header-label mt-2 mb-4"><?= h($pageTitle) ?></div>

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
      <?php if ($received > 0): ?>
        <div class="small mt-1">
          <span class="text-success fw-semibold"><?= h(rpay_money($received)) ?> received</span>
          <?php if ($left > 0): ?> · <span class="text-warning-emphasis fw-semibold"><?= h(rpay_money($left)) ?> left</span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($receipts): ?>
      <div class="table-responsive mb-3">
        <table class="table rpay-table align-middle small">
          <thead><tr><th>Received on</th><th>Account</th><th>By</th><th class="text-end">Amount</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($receipts as $r): ?>
              <tr>
                <td class="num"><?= h(rpay_date($r['paid_on'] ?? '')) ?></td>
                <td><?= h($r['account']['name'] ?? '—') ?></td>
                <td><?= h($r['by'] ?? '') ?></td>
                <td class="num text-end fw-semibold"><?= h(rpay_money($r['amount'] ?? 0)) ?></td>
                <td class="text-end text-nowrap">
                  <?php if (!empty($r['attachment'])): ?>
                    <a target="_blank" rel="noopener" title="Attachment" href="<?= h(rpay_file_url($rpayBase, 'receipt', (int)$r['id'])) ?>"><i class="bi bi-paperclip"></i></a>
                  <?php endif; ?>
                  <form method="post" action="<?= h($selfUrl) ?>" class="d-inline" onsubmit="return confirm('Delete this receipt? Its money comes out of its account, and its file is deleted.');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="receipt" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-link text-danger p-0 ms-2" title="Delete"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (!empty($due['attachment'])): ?>
      <div class="mb-3 small">
        <a target="_blank" rel="noopener" href="<?= h(rpay_file_url($rpayBase, 'due', $dueId)) ?>"><i class="bi bi-paperclip"></i> File kept with this payment</a>
      </div>
    <?php endif; ?>

    <?php if ($left <= 0): ?>
      <div class="alert alert-success mb-0"><i class="bi bi-check-circle"></i> Paid in full. To change a receipt, delete it and add it again.</div>
    <?php elseif (!$accounts): ?>
      <div class="alert alert-warning mb-0">
        There is no account yet to receive the money. <a href="<?= h($rpayBase) ?>/accounts.php">Add an account</a> first, like "Cash to Mr Tauqeer".
      </div>
    <?php else: ?>
      <?php if ($receipts): ?><div class="fw-semibold mb-2">Add a payment</div><?php endif; ?>
      <form method="post" class="row g-3" action="<?= h($selfUrl) ?>" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="add">

        <div class="col-md-4">
          <label class="form-label fw-semibold">Amount received</label>
          <input type="number" name="amount" class="form-control" value="<?= h($amount) ?>" min="0.01" max="<?= h(number_format($left, 2, '.', '')) ?>" step="0.01" required>
          <div class="form-text">Less than <?= h(rpay_money($left)) ?> for a part payment.</div>
        </div>

        <div class="col-md-8">
          <label class="form-label fw-semibold">Received in</label>
          <select name="account_id" class="form-select" data-search data-placeholder="Pick the account the money went into" required>
            <option value=""></option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>"<?= $picked === (string)$a['id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold">Received on</label>
          <input type="date" name="paid_on" class="form-control" value="<?= h($paidOn) ?>" required>
        </div>

        <div class="col-md-8">
          <label class="form-label fw-semibold">Attachment <span class="text-muted fw-normal small">(optional)</span></label>
          <input type="file" name="attachment" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
          <div class="form-text">A picture or a PDF, 8 MB at most.</div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-lg btn-success"><i class="bi bi-check-lg"></i> Save payment</button>
          <a href="<?= h($backUrl) ?>" class="btn btn-lg btn-light">Cancel</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
