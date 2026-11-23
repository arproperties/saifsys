<?php
/**
 * Recurring Payments — put one payment line right: its due date, its amount and its file,
 * or delete it when it was put in by mistake. Only this line changes, never its entry.
 * Reem checks everything: a date in a month the entry already has a line for, an amount
 * below what came in, a delete with money on it - each is refused with a sentence.
 *
 *   due_edit.php?due=12                      from the month's list
 *   due_edit.php?due=12&month=2026-09&...    the list's filters ride along, to go back to
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
$listUrl = fn(array $f) => $rpayBase . '/index.php' . ($f ? '?' . http_build_query($f) : '');
$backUrl = $listUrl($filters);
$selfUrl = $rpayBase . '/due_edit.php?' . http_build_query($filters + ['due' => $dueId]);

rpay_too_big($selfUrl);

try {
    $data = rpay_reem($code, 'GET', '/dues/' . $dueId);
} catch (BinvError $e) {
    binv_flash($e->getMessage(), 'danger');
    header('Location: ' . $backUrl);
    exit;
}
$due = $data['due'];
$received = (float)($due['received'] ?? 0);
$title = (string)($due['title'] ?? 'Payment');
$form = [
    'due_date' => (string)($due['due_date'] ?? ''),
    'amount' => number_format((float)($due['amount'] ?? 0), 2, '.', ''),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        try {
            rpay_reem($code, 'DELETE', '/dues/' . $dueId);
            binv_flash('"' . $title . '" for ' . rpay_month_label((string)($due['month'] ?? '')) . ' is deleted.');
            header('Location: ' . $backUrl);
            exit;
        } catch (BinvError $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($action === 'remove_file') {
        try {
            rpay_reem($code, 'DELETE', '/dues/' . $dueId . '/attachment');
            binv_flash('The attachment is removed.');
        } catch (BinvError $e) {
            binv_flash($e->getMessage(), 'danger');
        }
        header('Location: ' . $selfUrl);
        exit;
    } else {
        $form['due_date'] = is_string($_POST['due_date'] ?? null) ? trim($_POST['due_date']) : '';
        $form['amount'] = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
        try {
            $saved = rpay_reem($code, 'PUT', '/dues/' . $dueId, ['due_date' => $form['due_date'], 'amount' => $form['amount']]);
            $said = '"' . $title . '" is saved: due ' . rpay_date($saved['due_date'] ?? '') . ', ' . rpay_money($saved['amount'] ?? 0) . '.';
            // The file comes second, and its failure is said out loud: the change is kept either way.
            $fileError = rpay_send_file($code, '/dues/' . $dueId . '/attachment', $_FILES['attachment'] ?? null);
            binv_flash($fileError !== null ? $said . ' But its attachment was not kept: ' . $fileError : $said, $fileError !== null ? 'warning' : 'success');
            // Moved to another month: go back to that month's list.
            $back = $filters;
            if (!empty($saved['month']) && isset($back['month']) && $back['month'] !== $saved['month']) {
                $back['month'] = $saved['month'];
            }
            header('Location: ' . $listUrl($back));
            exit;
        } catch (BinvError $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit payment';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<a href="<?= h($backUrl) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to payments</a>
<div class="page-header-label mt-2 mb-4">Edit payment</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="card card-round mb-4" style="max-width:680px">
  <div class="card-body">
    <div class="mb-3">
      <div class="fw-semibold fs-5"><?= h($title) ?></div>
      <div class="text-muted">
        <?= h($due['building']['name'] ?? '') ?><?= !empty($due['unit']) ? ' · ' . h($due['unit']) : '' ?> · <?= h(rpay_month_label((string)($due['month'] ?? ''))) ?>
      </div>
      <?php if ($received > 0): ?>
        <div class="small mt-1"><span class="text-success fw-semibold"><?= h(rpay_money($received)) ?> received</span> — the amount cannot go below that.</div>
      <?php endif; ?>
    </div>

    <form method="post" class="row g-3" action="<?= h($selfUrl) ?>" enctype="multipart/form-data">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="save">

      <div class="col-md-6">
        <label class="form-label fw-semibold">Due date</label>
        <input type="date" name="due_date" class="form-control" value="<?= h($form['due_date']) ?>" required>
        <div class="form-text">A date in another month moves this line to that month.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Amount (AED)</label>
        <input type="number" name="amount" class="form-control" value="<?= h($form['amount']) ?>" min="<?= h(number_format(max(0.01, $received), 2, '.', '')) ?>" step="0.01" required>
        <div class="form-text">This line only. The entry's amount stays as it is.</div>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Attachment <span class="text-muted fw-normal small">(optional)</span></label>
        <?php if (!empty($due['attachment'])): ?>
          <div class="small mb-2">
            <a target="_blank" rel="noopener" href="<?= h(rpay_file_url($rpayBase, 'due', $dueId)) ?>"><i class="bi bi-paperclip"></i> File kept with this payment</a>
            — a new file replaces it.
          </div>
        <?php endif; ?>
        <input type="file" name="attachment" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
        <div class="form-text">A picture or a PDF, 8 MB at most.</div>
      </div>

      <div class="col-12 d-flex gap-2">
        <button class="btn btn-lg text-white" style="background:var(--primary)"><i class="bi bi-check-lg"></i> Save</button>
        <a href="<?= h($backUrl) ?>" class="btn btn-lg btn-light">Cancel</a>
      </div>
    </form>

    <?php if (!empty($due['attachment'])): ?>
      <form method="post" action="<?= h($selfUrl) ?>" class="mt-3" onsubmit="return confirm('Remove the attachment of this payment?');">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="remove_file">
        <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i> Remove attachment</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card card-round border-danger-subtle" style="max-width:680px">
  <div class="card-body">
    <div class="fw-semibold text-danger mb-1">Delete this payment</div>
    <?php if ($received > 0): ?>
      <p class="small text-muted mb-0">
        Money came in on this payment, so it cannot be deleted.
        <a href="<?= h($rpayBase . '/pay.php?' . http_build_query($filters + ['due' => $dueId])) ?>">Delete its receipts</a> first.
      </p>
    <?php else: ?>
      <p class="small text-muted mb-2">For a line put in by mistake. It will not be made again for <?= h(rpay_month_label((string)($due['month'] ?? ''))) ?>; "Create now" on the entry brings it back.</p>
      <form method="post" action="<?= h($selfUrl) ?>" onsubmit="return confirm('Delete this payment line? Its attachment is deleted too.');">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="delete">
        <button class="btn btn-outline-danger"><i class="bi bi-trash"></i> Delete payment</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
