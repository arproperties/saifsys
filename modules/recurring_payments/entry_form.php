<?php
/**
 * Recurring Payments — add or edit one entry.
 * The form only collects; Reem checks every field and keeps the entry.
 *
 *   entry_form.php          a new entry
 *   entry_form.php?id=7     edit entry 7
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
// From a group page (grp): the form starts with that group's income head, and goes back there.
$fromGrp = (int)($_GET['grp'] ?? 0);
$backUrl = $fromGrp ? $rpayBase . '/group_view.php?grp=' . $fromGrp : $rpayBase . '/entries.php';
$selfUrl = $rpayBase . '/entry_form.php?' . http_build_query(array_filter(['id' => $id, 'grp' => $fromGrp]));

try {
    $data = rpay_reem($code, 'GET', '/entries');
} catch (BinvError $e) {
    rpay_stop($e);
}
$buildings = $data['buildings'] ?? [];
$heads = $data['heads'] ?? [];

$entry = null;
if ($id > 0) {
    foreach ($data['entries'] ?? [] as $row) {
        if ((int)$row['id'] === $id) {
            $entry = $row;
            break;
        }
    }
    if (!$entry) {
        binv_flash('That entry could not be found.', 'danger');
        header('Location: ' . $backUrl);
        exit;
    }
}

// Heads deleted in saifsys are not offered; an entry already under one keeps it.
$heads = rpay_live_heads($conn, $heads, (int)($entry['head']['id'] ?? 0));
$errors = [];
// What the form shows: the saved entry, or what was just typed if Reem refused it.
$form = [
    'title' => $entry['title'] ?? '',
    'building_id' => $entry ? (string)($entry['building']['id'] ?? '') : (string)((int)($_GET['building'] ?? 0) ?: ''),
    'new_building' => '',
    'head_id' => $entry ? (string)($entry['head']['id'] ?? '') : (string)((int)($_GET['head'] ?? 0) ?: ''),
    'new_head' => '',
    'unit' => $entry['unit'] ?? '',
    'amount' => $entry ? number_format((float)($entry['amount'] ?? 0), 2, '.', '') : '',
    'day' => $entry ? (string)($entry['day'] ?? 1) : '1',
    'auto_create' => $entry ? !empty($entry['auto_create']) : true,
    'first_month' => $entry['first_month'] ?? ($data['month'] ?? date('Y-m')),
    'notes' => $entry['notes'] ?? '',
];
// The mode of payment (saifsys's own): cash unless one was picked for this entry.
$form['mode'] = $entry ? rpay_entry_mode(rpay_entry_mode_picks($conn), (int)$entry['id'], rpay_mode_text($entry)) : 'cash';
// The created date: the one Reem keeps (its created_at, when it sends it), else the day saifsys first saved it.
$dubai = new DateTimeZone('Asia/Dubai');
$madeMs = (int)($entry['created_at'] ?? 0);
$savedPick = $entry ? (string)(rpay_entry_saved_picks($conn)[(int)$entry['id']] ?? '') : '';
// Locked once the system has the date (Reem's, or the day it was first saved, or a new entry). An old entry with none can be given one, once.
$form['created_locked'] = !$entry || $madeMs > 0 || $savedPick !== '';
if ($madeMs > 0) {
    $form['created'] = rpay_reem_date($madeMs);
} elseif ($savedPick !== '') {
    $form['created'] = substr($savedPick, 0, 10);
} else {
    $form['created'] = (new DateTime('now', $dubai))->format('Y-m-d');
}
// The group of the income head and the room of the entry (saifsys's own): shown filled in,
// changed if needed. The group belongs to the income head (every entry with that head);
// the room belongs to this entry.
$allGroups = rpay_all_groups($conn);
$headGroupMap = rpay_head_group_map($conn);
$allRoomsList = rpay_rooms($conn);
$entryRoomPick = $entry ? (rpay_entry_room_picks($conn)[(int)$entry['id']] ?? 0) : 0;
$form['group_id'] = (string)($headGroupMap[(int)$form['head_id']]['id'] ?? ($fromGrp ?: ''));
$form['room_id'] = $entryRoomPick ? (string)$entryRoomPick : '';
/** Keep the group of the head and the room of the entry; returns a sentence about what was kept. */
$saveLocal = function (int $entryId, int $headId, string $groupIn, string $roomIn) use ($conn, $allGroups, $allRoomsList, $headGroupMap): string {
    $said = [];
    $gid = (int)$groupIn;
    if ($headId > 0 && $groupIn !== '' && ($gid === 0 || isset($allGroups[$gid])) && (int)($headGroupMap[$headId]['id'] ?? 0) !== $gid) {
        rpay_save_head_groups($conn, [$headId => $gid], [$headId => true]);
        $said[] = $gid ? 'its income head is now in ' . $allGroups[$gid]['name'] : 'its income head is now in no group';
    }
    $rid = (int)$roomIn;
    if ($entryId > 0 && ($rid === 0 || isset($allRoomsList[$rid]))) {
        rpay_set_entry_room($conn, $entryId, $rid, (int)current_user_id());
        if ($rid) {
            $said[] = 'its payments go to ' . $allRoomsList[$rid]['name'];
        }
    }
    return $said ? ucfirst(implode(', and ', $said)) . '.' : '';
};

// The form asks for one start date; Reem keeps it as a first month and a day of the month.
$savedStart = rpay_start_date((string)$form['first_month'], (int)$form['day']);
$form['start_date'] = $savedStart;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (['title', 'building_id', 'new_building', 'head_id', 'new_head', 'unit', 'amount', 'start_date', 'notes'] as $k) {
        $form[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    }
    $form['auto_create'] = !empty($_POST['auto_create']);
    $form['group_id'] = is_string($_POST['group_id'] ?? null) ? $_POST['group_id'] : '';
    $form['room_id'] = is_string($_POST['room_id'] ?? null) ? $_POST['room_id'] : '';
    $form['mode'] = is_string($_POST['mode'] ?? null) && isset(RPAY_PAYMENT_MODES[$_POST['mode']]) ? $_POST['mode'] : 'cash';
    $form['created'] = is_string($_POST['created'] ?? null) ? $_POST['created'] : '';
    // A start date left as it was keeps the entry's own day: one made for the 31st and
    // shown as 28 February must not quietly become the 28th.
    $start = DateTime::createFromFormat('!Y-m-d', $form['start_date']);
    if ($start && $start->format('Y-m-d') === $form['start_date'] && $form['start_date'] !== $savedStart) {
        $form['first_month'] = $start->format('Y-m');
        $form['day'] = $start->format('j');
    }

    try {
        if (!$start || $start->format('Y-m-d') !== $form['start_date']) {
            throw new BinvError('Pick the start date');
        }
        $reemForm = $form;
        unset($reemForm['group_id'], $reemForm['room_id'], $reemForm['mode'], $reemForm['created'], $reemForm['created_locked']); // saifsys's own, not Reem's
        $saved = rpay_reem($code, $entry ? 'PUT' : 'POST', '/entries' . ($entry ? '/' . $id : ''), $reemForm);
        $kept = $saveLocal((int)($saved['id'] ?? 0), (int)($saved['head']['id'] ?? 0), $form['group_id'], $form['room_id']);
        rpay_set_entry_mode($conn, (int)($saved['id'] ?? 0), $form['mode'], (int)current_user_id());
        rpay_record_entry_saved($conn, (int)($saved['id'] ?? 0), (int)current_user_id());
        // An old entry with no created date yet can be given one, once. After that, editing never changes it.
        $dateOk = DateTime::createFromFormat('!Y-m-d', $form['created']);
        if ($entry && !$form['created_locked'] && $dateOk && $dateOk->format('Y-m-d') === $form['created']) {
            rpay_set_entry_saved_date($conn, (int)($saved['id'] ?? 0), $form['created'], (int)current_user_id());
        }
        binv_flash('"' . $saved['title'] . '" ' . ($entry ? 'saved.' : 'added.') . ($kept !== '' ? ' ' . $kept : ''));
        header('Location: ' . $backUrl);
        exit;
    } catch (BinvError $e) {
        $errors[] = $e->getMessage();
        // Reem refused the entry (on local it only reads): the group and the room are saifsys's
        // own, so for an entry that is already there they are kept anyway.
        if ($entry && trim($form['new_head']) === '') {
            try {
                $kept = $saveLocal((int)$entry['id'], (int)$form['head_id'], $form['group_id'], $form['room_id']);
                if ($kept !== '') {
                    $errors[] = 'Kept in saifsys all the same: ' . lcfirst($kept);
                }
            } catch (PDOException $pe) {
                error_log('recurrent income: entry group / room not kept: ' . $pe->getMessage());
            }
        }
    }
}

$pageTitle = $entry ? 'Edit entry' : 'Add entry';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<a href="<?= h($backUrl) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to <?= $fromGrp ? 'the group' : 'entries' ?></a>
<div class="page-header-label mt-2 mb-4"><?= $entry ? 'Edit entry' : 'Add entry' ?></div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="card card-round mb-4" style="max-width:680px">
  <div class="card-body">
    <form method="post" class="row g-3" action="<?= h($selfUrl) ?>">
      <?php csrf_field(); ?>

      <div class="col-12">
        <label class="form-label fw-semibold">Title</label>
        <input type="text" name="title" class="form-control form-control-lg" maxlength="<?= RPAY_TITLE_MAX ?>"
               value="<?= h($form['title']) ?>" placeholder="e.g. Washing machine rent" required <?= $entry ? '' : 'autofocus' ?>>
        <?php if (!$entry): ?><div class="form-text">Filled in from the income head. Change it if needed.</div><?php endif; ?>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Building</label>
        <select name="building_id" class="form-select" data-search data-placeholder="Pick a building">
          <option value=""></option>
          <?php foreach ($buildings as $b): ?>
            <option value="<?= (int)$b['id'] ?>"<?= (string)$form['building_id'] === (string)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Or a new building <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="text" name="new_building" class="form-control" maxlength="<?= RPAY_NAME_MAX ?>"
               value="<?= h($form['new_building']) ?>" placeholder="Type its name to add it">
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Income head</label>
        <select name="head_id" class="form-select" data-search data-placeholder="Pick an income head">
          <option value=""></option>
          <?php foreach ($heads as $head): ?>
            <option value="<?= (int)$head['id'] ?>"<?= (string)$form['head_id'] === (string)$head['id'] ? ' selected' : '' ?>><?= h($head['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">What the payment is for. The report adds up each building by this.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Or a new income head <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="text" name="new_head" class="form-control" maxlength="<?= RPAY_NAME_MAX ?>"
               value="<?= h($form['new_head']) ?>" placeholder="e.g. Shop, DEWA, Washing Machine">
      </div>

      <!-- Where it goes in saifsys: the head's group, and (for groups with rooms) the room -->
      <div class="col-12">
        <div class="rp-where p-3 rounded-3 border">
          <div class="row g-3 align-items-end">
            <div class="col-md-6">
              <label class="form-label fw-semibold mb-1"><i class="bi bi-grid-3x3-gap"></i> Group</label>
              <select name="group_id" id="rpGroup" class="form-select" data-no-search>
                <option value="0">Not in a group</option>
                <?php foreach ($allGroups as $gid => $g): ?>
                  <option value="<?= (int)$gid ?>" data-rooms="<?= !empty($g['has_rooms']) ? 1 : 0 ?>"<?= $form['group_id'] === (string)$gid ? ' selected' : '' ?>><?= h($g['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="rpGroupHint">Filled in from the income head. Changing it changes the group of every entry with this head.</div>
            </div>
            <div class="col-md-6" id="rpRoomBox">
              <label class="form-label fw-semibold mb-1"><i class="bi bi-door-open"></i> Room</label>
              <select name="room_id" id="rpRoom" class="form-select" data-no-search>
                <option value="">No room</option>
                <?php foreach ($allRoomsList as $rm): ?>
                  <option value="<?= (int)$rm['id'] ?>" data-group="<?= (int)$rm['group_id'] ?>" data-key="<?= h(rpay_room_key((string)$rm['name'])) ?>"<?= $form['room_id'] === (string)$rm['id'] ? ' selected' : '' ?>><?= h($rm['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="rpRoomHint"></div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Shop / unit / other <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="text" name="unit" class="form-control" maxlength="<?= RPAY_UNIT_MAX ?>"
               value="<?= h($form['unit']) ?>" placeholder="e.g. Shop 3, Laundry room">
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Amount</label>
        <div class="input-group">
          <span class="input-group-text">AED</span>
          <input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="100000000"
                 value="<?= h($form['amount']) ?>" required>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Start date</label>
        <input type="date" name="start_date" class="form-control" value="<?= h($form['start_date']) ?>" required>
        <div class="form-text">The first payment is on this date, then on the same day every month. An old date is fine: the payments since then are created at once.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Mode of payment</label>
        <select name="mode" class="form-select" data-no-search>
          <?php foreach (RPAY_PAYMENT_MODES as $mk => $ml): ?>
            <option value="<?= h($mk) ?>"<?= $form['mode'] === $mk ? ' selected' : '' ?>><?= h($ml) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Created on</label>
        <input type="date" name="created" class="form-control" value="<?= h($form['created']) ?>"<?= $form['created_locked'] ? ' disabled' : '' ?>>
        <div class="form-text"><?= $form['created_locked'] ? 'The date the entry was created. It does not change when the entry is edited.' : 'No date is recorded for this entry yet. Pick the day it was created, once.' ?></div>
      </div>

      <div class="col-md-6 d-flex align-items-center">
        <div class="form-check form-switch mt-md-3">
          <input class="form-check-input" type="checkbox" role="switch" name="auto_create" value="1" id="rpayAuto"<?= $form['auto_create'] ? ' checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="rpayAuto">Create the payment automatically</label>
          <div class="form-text">Off: nothing is created until you press "Create now".</div>
        </div>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Notes <span class="text-muted fw-normal small">(optional)</span></label>
        <textarea name="notes" class="form-control" rows="2" maxlength="<?= RPAY_NOTES_MAX ?>"
                  placeholder="Who pays, how they pay"><?= h($form['notes']) ?></textarea>
      </div>

      <div class="col-12">
        <div class="form-text mb-2">Every new payment starts as <strong>Pending</strong>. Changing the amount here also changes the payments still pending with nothing received; paid or part-paid ones keep theirs.</div>
        <div class="d-flex gap-2">
          <button class="btn btn-lg text-white" style="background:var(--primary)">
            <i class="bi bi-check-lg"></i> <?= $entry ? 'Save changes' : 'Add entry' ?>
          </button>
          <a href="<?= h($backUrl) ?>" class="btn btn-lg btn-light">Cancel</a>
        </div>
      </div>
    </form>
  </div>
</div>

<?php if ($entry): ?>
  <div class="card card-round mt-4 border-danger-subtle" style="max-width:640px">
    <div class="card-body">
      <div class="fw-semibold text-danger mb-1">Delete this entry</div>
      <?php if ((int)($entry['payments'] ?? 0) === 0): ?>
        <div class="small text-muted mb-2">For an entry put in by mistake. Its payments are removed with it. This cannot be undone.</div>
        <form method="post" action="<?= h($rpayBase) ?>/entries.php" onsubmit="return confirm('Delete this entry? This cannot be undone.');">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="entry" value="<?= (int)$entry['id'] ?>">
          <button class="btn btn-outline-danger"><i class="bi bi-trash"></i> Delete entry</button>
        </form>
      <?php else: ?>
        <?php $nPay = (int)$entry['payments']; ?>
        <div class="small text-muted mb-2">This entry has <?= $nPay ?> payment<?= $nPay === 1 ? '' : 's' ?>. Deleting it also deletes all of them, with their receipts and files. Pausing it keeps them.</div>
        <form method="post" action="<?= h($rpayBase) ?>/entries.php" onsubmit="return confirm('Delete this entry AND its <?= $nPay ?> payment<?= $nPay === 1 ? '' : 's' ?>? All receipts and files of those payments are deleted too. This cannot be undone.');">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="entry" value="<?= (int)$entry['id'] ?>">
          <input type="hidden" name="with_payments" value="1">
          <button class="btn btn-outline-danger"><i class="bi bi-trash"></i> Delete entry and its payments</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php
  // For the Group / Room boxes: each head's group, and the words that point a new head to a group.
  $jsHeadGroup = [];
  foreach ($headGroupMap as $hid => $g) { $jsHeadGroup[$hid] = $g['id']; }
?>
<style>.rp-where{ background:#f6f8fc; } body.dark-mode .rp-where{ background:#1e293b; border-color:#334155 !important; }</style>
<script>
(function () {
  var headGroup = <?= json_encode($jsHeadGroup) ?>;
  var groupNames = <?= json_encode(array_map(function ($g) { return $g['name']; }, $allGroups)) ?>;
  var form = document.getElementById('rpGroup').form;
  var group = document.getElementById('rpGroup'), room = document.getElementById('rpRoom');
  var roomBox = document.getElementById('rpRoomBox'), roomHint = document.getElementById('rpRoomHint');
  var head = form.querySelector('[name=head_id]'), newHead = form.querySelector('[name=new_head]');
  var unit = form.querySelector('[name=unit]'), title = form.querySelector('[name=title]');
  var touched = { group: false, room: room.value !== '' }; // a room already kept for this entry stays

  // A new head's name points to a group (a word of the group's name in it), else Other.
  function suggest(name) {
    var n = ' ' + name.toLowerCase().replace(/[^a-z0-9]+/g, ' ') + ' ', best = '', bestScore = 0, other = '';
    var same = { bedspace: ['bed space', 'bedspaces', 'room'], commission: ['recharge'], deposit: ['deposits'], washing: ['laundry'] };
    Object.keys(groupNames).forEach(function (id) {
      var gn = groupNames[id].toLowerCase(), score = 0;
      if (gn === 'other' || gn === 'others') { other = id; }
      gn.split(/[^a-z0-9]+/).forEach(function (w) {
        if (w.length < 3 || ['and', 'the', 'for'].indexOf(w) !== -1) { return; }
        if ([w].concat(same[w] || []).some(function (x) { return n.indexOf(' ' + x) !== -1; })) { score++; }
      });
      if (score > bestScore) { best = id; bestScore = score; }
    });
    return best || other;
  }
  function roomKey(s) {
    var m = /\bpartition\s*#?\s*0*(\d{1,2})\b/i.exec(s);
    if (m) { return 'P' + parseInt(m[1], 10); }
    m = /\bG\s*0*(\d{1,2})\b/i.exec(s);
    return m ? 'G' + parseInt(m[1], 10) : '';
  }
  // Rooms of the chosen group only; the box shows only for a group with rooms.
  function rooms() {
    var opt = group.options[group.selectedIndex], g = group.value, has = opt && opt.dataset.rooms === '1';
    roomBox.style.display = has ? '' : 'none';
    Array.prototype.forEach.call(room.options, function (o) { if (o.value) { o.hidden = o.dataset.group !== g; } });
    if (room.value && room.options[room.selectedIndex].hidden) { room.value = ''; touched.room = false; }
    // Not picked by hand: fill it in from the room number in the income head, the unit or the title.
    if (!touched.room) {
      var headName = newHead.value.trim() !== '' ? newHead.value : (head.selectedIndex > 0 ? head.options[head.selectedIndex].textContent : '');
      var k = roomKey(headName) || roomKey(unit.value) || roomKey(title.value), found = '';
      if (k) {
        Array.prototype.forEach.call(room.options, function (o) { if (o.value && !o.hidden && o.dataset.key === k) { found = o.value; } });
      }
      room.value = found;
      roomHint.textContent = found ? 'Filled in from the name. Change it if needed.' : 'No room in the name: pick one, or its payments show as "Not in a room".';
    } else {
      roomHint.textContent = room.value ? 'Its payments go to ' + room.options[room.selectedIndex].textContent + '.' : 'Its payments go to the room in the unit or title.';
    }
  }
  // A new entry's title follows the income head, until the title is typed by hand (cleared: it follows again).
  var titleAuto = <?= $entry ? 'false' : 'true' ?> && title.value.trim() === '';
  title.addEventListener('input', function () { titleAuto = title.value.trim() === ''; });
  function titleFromHead() {
    if (!titleAuto) { return; }
    title.value = newHead.value.trim() !== '' ? newHead.value.trim() : (head.selectedIndex > 0 ? head.options[head.selectedIndex].textContent.trim() : '');
  }
  function fromHead() {
    titleFromHead();
    if (touched.group) { return; }
    var g = '';
    if (newHead.value.trim() !== '') { g = suggest(newHead.value); }
    else if (head.value && headGroup[head.value]) { g = String(headGroup[head.value]); }
    if (g) { group.value = g; }
    rooms();
  }
  group.addEventListener('change', function () { touched.group = true; rooms(); });
  room.addEventListener('change', function () { touched.room = room.value !== ''; rooms(); });
  [head, newHead].forEach(function (el) { el.addEventListener('input', fromHead); el.addEventListener('change', fromHead); });
  if (window.jQuery) { jQuery(head).on('change', fromHead); }
  [unit, title].forEach(function (el) { el.addEventListener('input', rooms); });
  titleFromHead(); // a head already picked (Add entry from a group) fills the title at once
  rooms();
})();
</script>
<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
