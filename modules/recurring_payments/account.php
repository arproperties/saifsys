<?php
/**
 * Recurring Payments — one account: what it holds and everything that went through it.
 *
 *   account.php?id=2
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
$id = (int)($_GET['id'] ?? 0);
$backUrl = $rpayBase . '/accounts.php';

try {
    $data = rpay_reem($code, 'GET', '/accounts/' . $id);
} catch (BinvError $e) {
    binv_flash($e->getMessage(), 'danger');
    header('Location: ' . $backUrl);
    exit;
}
$account = $data['account'];
$movements = $data['movements'] ?? [];

$pageTitle = $account['name'];
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<a href="<?= h($backUrl) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to accounts</a>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mt-2 mb-3">
  <div>
    <div class="page-header-label mb-1"><?= h($account['name']) ?></div>
    <div class="rpay-total<?= (float)$account['balance'] < 0 ? ' text-danger' : '' ?>"><?= h(rpay_money($account['balance'] ?? 0)) ?></div>
  </div>
  <a href="<?= h($rpayBase) ?>/transfers.php?from=<?= (int)$account['id'] ?>" class="btn text-white" style="background:var(--primary)">
    <i class="bi bi-arrow-left-right"></i> Transfer funds
  </a>
</div>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Date</th><th>What</th><th>By</th><th class="text-end">In</th><th class="text-end">Out</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$movements): ?>
          <tr><td colspan="6" class="text-muted text-center py-5">Nothing has gone through this account yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($movements as $m): ?>
          <?php $amount = (float)($m['amount'] ?? 0); $kind = ($m['kind'] ?? '') === 'transfer' ? 'transfer' : 'due'; ?>
          <tr>
            <td class="num"><?= h(rpay_date($m['date'] ?? '')) ?></td>
            <td><?= h($m['text'] ?? '') ?></td>
            <td><?= h($m['by'] ?? '') ?></td>
            <td class="num text-end text-success"><?= $amount > 0 ? h(rpay_money($amount)) : '' ?></td>
            <td class="num text-end text-danger"><?= $amount < 0 ? h(rpay_money(-$amount)) : '' ?></td>
            <td class="text-end">
              <?php if (!empty($m['attachment'])): ?>
                <a target="_blank" rel="noopener" title="Attachment" href="<?= h(rpay_file_url($rpayBase, $kind, (int)$m['id'])) ?>"><i class="bi bi-paperclip"></i></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
