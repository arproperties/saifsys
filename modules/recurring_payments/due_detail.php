<?php
/**
 * Recurrent Income — one payment, on its own page: the period it is for, the money
 * received for it (a receipt written by mistake is deleted here), and the entry's other
 * months. Opened by double-clicking a row on the month's list.
 *
 *   due_detail.php?due=12                     from the month's list
 *   due_detail.php?due=12&month=2026-09&...   the list's filters ride along, to go back to
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
foreach (['month', 'building', 'head', 'unit', 'status', 'grp'] as $k) {
    if (is_string($_GET[$k] ?? null) && $_GET[$k] !== '') {
        $filters[$k] = $_GET[$k];
    }
}
$backUrl = $rpayBase . '/index.php' . ($filters ? '?' . http_build_query($filters) : '');
$selfUrl = $rpayBase . '/due_detail.php?' . http_build_query($filters + ['due' => $dueId]);
$linkTo = function (int $id) use ($rpayBase, $filters) {
    return $rpayBase . '/due_detail.php?' . http_build_query($filters + ['due' => $id]);
};

const RPAY_HISTORY_MONTHS = 12; // the entry's other months: this many back at most

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rpay_too_big($selfUrl);
    csrf_verify();
    if (rpay_handle_note_post($conn, $code)) {
        // a note added or deleted (saifsys's own table)
    } elseif (($_POST['action'] ?? '') === 'delete_receipt') {
        // A receipt written by mistake: its money comes out of its account, and the payment owes it again.
        try {
            rpay_reem($code, 'DELETE', '/receipts/' . (int)($_POST['receipt'] ?? 0));
            binv_flash('The receipt is deleted. That amount is owed again.');
        } catch (BinvError $e) {
            binv_flash($e->getMessage(), 'danger');
        }
    } elseif (($_POST['action'] ?? '') === 'add_file') {
        // One more file for a receipt: Reem keeps every file of it.
        if (!isset($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            binv_flash('Choose a file first.', 'warning');
        } else {
            $error = rpay_send_file($code, '/receipts/' . (int)($_POST['receipt'] ?? 0) . '/files', $_FILES['file']);
            binv_flash($error ?? 'File added.', $error ? 'danger' : 'success');
        }
    } else {
        $_POST['due'] = $dueId; // this page's own payment
        rpay_handle_period_post($conn, $code);
    }
    header('Location: ' . $selfUrl);
    exit;
}

try {
    $data = rpay_reem($code, 'GET', '/dues/' . $dueId);
    $entries = rpay_reem($code, 'GET', '/entries')['entries'] ?? [];
} catch (BinvError $e) {
    binv_flash($e->getMessage(), 'danger');
    header('Location: ' . $backUrl);
    exit;
}
$due = $data['due'];
$receipts = $data['receipts'] ?? [];

$entry = null;
foreach ($entries as $row) {
    if ((int)$row['id'] === (int)($due['entry_id'] ?? 0)) {
        $entry = $row;
        break;
    }
}

// The entry's other months: one read per month, from next month going back, until all of
// them are found (Reem says how many it has) or 12 months back. Going by the entry's first
// month is not enough: a line made by hand with an older date sits before it.
$history = [];
if ($entry && (int)($entry['payments'] ?? 0) > 1) {
    $want = (int)$entry['payments'];
    $m = new DateTime('first day of next month');
    try {
        for ($i = 0; $i <= RPAY_HISTORY_MONTHS && count($history) < $want; $i++, $m->modify('-1 month')) {
            $list = rpay_reem($code, 'GET', '?' . http_build_query(['month' => $m->format('Y-m'), 'building' => $entry['building']['id'] ?? '']));
            foreach ($list['dues'] ?? [] as $d) {
                if ((int)($d['entry_id'] ?? 0) === (int)$entry['id']) {
                    $history[] = $d;
                }
            }
        }
        usort($history, function ($a, $b) {
            return strcmp((string)$b['due_date'], (string)$a['due_date']); // latest first
        });
    } catch (BinvError $e) {
        $history = null;
    }
}

$month = (string)($due['month'] ?? '');
// Rental periods typed by hand (saifsys's own table) for this line and its other months.
$saved = rpay_saved_periods($conn, array_merge([$dueId], array_map(function ($d) { return (int)$d['id']; }, $history ?: [])));
$period = rpay_line_period($due, $entry, $saved);
$editing = isset($_GET['edit_period']);
$left = (float)($due['left'] ?? 0);
$where = array_filter([$due['building']['name'] ?? '', $due['unit'] ?? '']);
// The group: everything in this building with the same unit ("G03") in its title or unit.
// Its room (G03, Partition 01 ...), when it has one: a link to the room's page.
$dueRoom = rpay_room_of_due($conn, $due);
$roomUrl = $dueRoom ? $rpayBase . '/room.php?' . http_build_query(['id' => $dueRoom['id'], 'grp' => (int)$dueRoom['group_id']]) : '';

$pageTitle = 'Payment';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<a href="<?= h($backUrl) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to payments</a>

<div class="card card-round mt-2" style="max-width:640px">
  <div class="card-body">

    <div class="d-flex justify-content-between align-items-start gap-2">
      <div>
        <div class="fw-semibold fs-5"><?= h($due['title'] ?? '') ?></div>
        <div class="text-muted small">
          <?= h(implode(' · ', $where)) ?>
          <?php if ($roomUrl): ?> · <a class="text-decoration-none" href="<?= h($roomUrl) ?>">Open <?= h($dueRoom['name']) ?></a><?php endif; ?>
        </div>
      </div>
      <?= rpay_badge($due) ?>
    </div>

    <dl class="row mt-3 mb-0">
      <dt class="col-5 col-sm-4 fw-normal text-muted">Due on</dt>
      <dd class="col-7 col-sm-8"><?= h(rpay_date($due['due_date'] ?? '')) ?></dd>

      <dt class="col-5 col-sm-4 fw-normal text-muted">Amount</dt>
      <dd class="col-7 col-sm-8">
        <?= h(rpay_money($due['amount'] ?? 0)) ?>
        <?php if ($left > 0 && (float)($due['received'] ?? 0) > 0): ?>
          <span class="text-warning-emphasis small">· <?= h(rpay_money($left)) ?> left</span>
        <?php endif; ?>
      </dd>

      <?php if ($period['edited'] && !$editing): ?>
        <dt class="col-5 col-sm-4 fw-normal text-muted"><?= h(rpay_period_label($conn, (int)($due['head']['id'] ?? 0))) ?></dt>
        <dd class="col-7 col-sm-8">
          <?= h($period['text']) ?>
          <a class="small ms-2 text-decoration-none" href="<?= h($selfUrl . '&edit_period=1') ?>" title="Change the period"><i class="bi bi-pencil"></i></a>
        </dd>
      <?php endif; ?>
    </dl>

    <?php if ($editing): ?>
      <form method="post" action="<?= h($selfUrl) ?>" class="d-flex flex-wrap align-items-center gap-2 mt-2">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save_period">
        <input type="date" name="period_from" class="form-control form-control-sm" style="width:auto" value="<?= $period['edited'] ? h($period['from']) : '' ?>" required>
        <span>to</span>
        <input type="date" name="period_to" class="form-control form-control-sm" style="width:auto" value="<?= $period['edited'] ? h($period['to']) : '' ?>" required>
        <button class="btn btn-sm btn-success">Save</button>
        <a class="btn btn-sm btn-light border" href="<?= h($selfUrl) ?>">Cancel</a>
        <?php if ($period['edited']): ?>
          <button class="btn btn-sm btn-link p-0 text-decoration-none text-danger" name="action" value="reset_period" formnovalidate>Remove</button>
        <?php endif; ?>
      </form>
    <?php elseif (!$period['edited']): ?>
      <a class="btn btn-sm btn-light border mt-2" href="<?= h($selfUrl . '&edit_period=1') ?>"><i class="bi bi-calendar-plus"></i> Add period</a>
    <?php endif; ?>

    <hr>

    <div class="fw-semibold mb-2">Received</div>
    <?php if (!$receipts): ?>
      <p class="text-muted mb-0">Nothing received yet.</p>
    <?php else: ?>
      <ul class="list-unstyled mb-0">
        <?php foreach ($receipts as $r): ?>
          <li class="d-flex align-items-center gap-2 py-2 border-bottom">
            <div class="flex-grow-1">
              <div><span class="fw-semibold"><?= h(rpay_money($r['amount'] ?? 0)) ?></span> on <?= h(rpay_date($r['paid_on'] ?? '')) ?></div>
              <div class="small text-muted"><?= h($r['account']['name'] ?? '') ?></div>
            </div>
            <?php if (!empty($r['attachment'])): ?>
              <a class="btn btn-sm btn-light border" target="_blank" rel="noopener" title="File" href="<?= h(rpay_file_url($rpayBase, 'receipt', (int)$r['id'])) ?>"><i class="bi bi-paperclip"></i></a>
            <?php endif; ?>
            <form method="post" action="<?= h($selfUrl) ?>" enctype="multipart/form-data" class="m-0">
              <?php csrf_field(); ?>
              <input type="hidden" name="action" value="add_file">
              <input type="hidden" name="receipt" value="<?= (int)$r['id'] ?>">
              <label class="btn btn-sm btn-light border mb-0" title="Add another file to this receipt"><i class="bi bi-plus-lg"></i>
                <input type="file" name="file" class="d-none" accept="image/jpeg,image/png,image/webp,application/pdf" onchange="this.form.submit()">
              </label>
            </form>
            <form method="post" action="<?= h($selfUrl) ?>" class="m-0" onsubmit="return confirm('Delete this receipt of <?= h(rpay_money($r['amount'] ?? 0)) ?>? Its money comes out of its account, its file is deleted, and the amount is owed again.');">
              <?php csrf_field(); ?>
              <input type="hidden" name="action" value="delete_receipt">
              <input type="hidden" name="receipt" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Delete this receipt"><i class="bi bi-trash"></i></button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php $others = is_array($history) ? count(array_filter($history, function ($d) use ($dueId) { return (int)$d['id'] !== $dueId; })) : 0; ?>
    <?php if ($history !== null && $others === 0): ?>
      <hr>
      <div class="fw-semibold mb-1">History</div>
      <p class="text-muted small mb-0">No other payments yet. This is the first one.</p>
    <?php elseif ($history): ?>
      <hr>
      <div class="fw-semibold mb-2">History</div>
      <ul class="list-unstyled mb-0">
        <?php foreach ($history as $d): $here = (int)$d['id'] === $dueId; $got = (float)($d['received'] ?? 0); ?>
          <li class="d-flex align-items-center gap-2 py-1 px-2 rounded<?= $here ? ' bg-body-secondary' : '' ?>">
            <?php
              // How it was paid: on which day, into which account, collected by whom.
              $how = $got > 0
                  ? 'Paid' . (($d['status'] ?? '') === 'paid' ? '' : ' ' . rpay_money($got)) . (!empty($d['paid_on']) ? ' on ' . rpay_date($d['paid_on']) : '')
                    . (!empty($d['account']['name']) ? ' · into ' . $d['account']['name'] : '')
                    . (!empty($d['paid_by']) ? ' · collected by ' . $d['paid_by'] : '')
                  : 'Not paid yet · due ' . rpay_date($d['due_date'] ?? '');
            ?>
            <div class="flex-grow-1">
              <?php if ($here): ?>
                <span class="fw-semibold"><?= h($how) ?></span> <span class="small text-muted">(this one)</span>
              <?php else: ?>
                <a class="text-decoration-none" href="<?= h($linkTo((int)$d['id'])) ?>"><?= h($how) ?></a>
              <?php endif; ?>
              <?php if ($got > 0 && ($d['status'] ?? '') !== 'paid'): ?>
                <div class="small text-warning-emphasis"><?= h(rpay_money($d['left'] ?? 0)) ?> still to come · due <?= h(rpay_date($d['due_date'] ?? '')) ?></div>
              <?php endif; ?>
            </div>
            <span class="small"><?= h(rpay_money($d['amount'] ?? 0)) ?></span>
            <?= rpay_badge($d) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php elseif ($history === null): ?>
      <hr>
      <p class="text-muted small mb-0">The history could not be read just now.</p>
    <?php endif; ?>

    <?php if ($left > 0): ?>
      <div class="mt-3">
        <a href="<?= h($rpayBase . '/pay.php?' . http_build_query($filters + ['due' => $dueId])) ?>" class="btn btn-success"><i class="bi bi-plus-lg"></i> Add payment</a>
      </div>
    <?php endif; ?>

    <?php if ($entry): ?>
      <hr>
      <?php
        $notesEntry = $entry;
        $notesList = rpay_entry_notes($conn, [(int)$entry['id']])[(int)$entry['id']] ?? [];
        $notesPostUrl = $selfUrl;
        $notesDue = $dueId; // to tell this payment's notes from other months'
        $rpayUi = 'notes';
        require __DIR__ . '/includes/rpay_ui.php';
      ?>
    <?php endif; ?>

  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
