<?php
/**
 * Recurrent Income — the shared screen pieces, in one file. Set $rpayUi, then require this:
 *
 *   'rows'   payment rows like DEWA's (coloured by status, Due on / Paid on, amount, received
 *            into which account, Mark paid / Receipts / open, the bell), with "Move payments".
 *            Set: $rowsList (the payments), $grp (the group page to come back to), $moveRooms
 *            (room id => name), $hereRoom (the room these rows are in; 0: in no room),
 *            $movePostUrl (where Move posts; the page handles it with rpay_handle_move_post).
 *   'css'    the look of those rows (the group pages carry their own copy).
 *   'notes'  the notes box of one entry: add a note at any time, every note newest first,
 *            delete your own. Set: $notesEntry (the entry from Reem), $notesList
 *            (rpay_entry_notes($conn, [id])[id] ?? []), $notesPostUrl (where it posts; the
 *            page handles it with rpay_handle_note_post), and $notesDue (optional: this payment).
 */
$rpayUi = $rpayUi ?? '';
if ($rpayUi === 'rows'):
?>
<?php $mv = 'rpMove' . (int)$hereRoom; $anyMoved = (bool)array_filter($rowsList, function ($d) { return !empty($d['moved']); }); ?>
<!-- Move: hidden until "Move payments" is pressed; then tick the payments and pick the room. -->
<form method="post" action="<?= h($movePostUrl) ?>" id="<?= $mv ?>" class="rp-movebox mb-2" onsubmit="return this.querySelectorAll('input[name=&quot;due_ids[]&quot;]:checked').length > 0 || document.querySelectorAll('input[form=<?= $mv ?>]:checked').length > 0 || (alert('Tick the payments to move first.'), false);">
  <?php csrf_field(); ?>
  <input type="hidden" name="action" value="move_due">
  <div class="d-flex justify-content-end">
    <button type="button" class="btn btn-sm btn-light border rp-move-start"><i class="bi bi-arrow-left-right"></i> Move payments</button>
  </div>
  <div class="rp-move-bar d-none align-items-center flex-wrap gap-2 p-2 border rounded bg-body-tertiary">
    <span class="small fw-semibold"><span class="rp-move-n">0</span> ticked</span>
    <span class="small text-muted">Move to</span>
    <select name="to_room" class="form-select form-select-sm" style="width:auto" data-no-search required>
      <option value="">Pick the room…</option>
      <?php foreach ($moveRooms as $rid => $rname): if ((int)$rid === (int)$hereRoom) { continue; } ?>
        <option value="<?= (int)$rid ?>"><?= h($rname) ?></option>
      <?php endforeach; ?>
      <?php if ($anyMoved): ?><option value="0">Back to its own room</option><?php endif; ?>
    </select>
    <button class="btn btn-sm btn-primary">Move</button>
    <button type="button" class="btn btn-sm btn-light border rp-move-cancel">Cancel</button>
  </div>
</form>
<?php if (($rowsStyle ?? '') === 'table'):
  // The bedspace number, from the title ("Bedspace # 04 - ..."); a payment for the whole room has none.
  $bedOf = function (string $t): string { return preg_match('/bed\s*space\s*#?\s*0*(\d{1,2})/i', $t, $m) ? 'Bed ' . str_pad($m[1], 2, '0', STR_PAD_LEFT) : '—'; };
?>
<?php $modePicks = rpay_entry_mode_picks($conn);
  // Each payment's duration: the period typed on the payment's own page, else its tenant's start and end date
  // ($rowTenants, when the page gives them), else worked out from its due date.
  $rowSaved = rpay_saved_periods($conn, array_map(function ($d) { return (int)$d['id']; }, $rowsList));
  $rowEntries = $rowEntries ?? [];
  $rowTenants = $rowTenants ?? [];
?>
<div class="d-flex justify-content-end mb-2 no-print">
  <button type="button" class="btn btn-sm btn-light border" onclick="window.print()"><i class="bi bi-file-earmark-pdf"></i> Download PDF</button>
</div>
<div id="rpPrint">
<table class="table table-sm table-bordered align-middle rp-sheet rp-pay-grid" style="--bs-border-color:#c9ced6;display:table;width:100%" data-move="<?= $mv ?>">
  <thead class="text-dark"><tr><th style="width:28px"></th><th>Title</th><th>Bedspace</th><th>Due date</th><th>Duration</th><th>Paid on</th><th class="text-end">Amount</th><th>Received</th><th>Mode of payment</th><th>Status</th><th class="no-print">Actions</th></tr></thead>
  <tbody>
  <?php $lastHk = null; foreach ($rowsList as $d):
    // A heading row for each month (the payment's due month), like the group page.
    $hk = substr((string)($d['due_date'] ?? ''), 0, 7);
    if ($hk !== $lastHk): $lastHk = $hk; ?>
    <tr class="rp-headrow"><td colspan="11" class="text-center fw-bold">Received on <?= h(rpay_month_label($hk)) ?></td></tr>
    <?php endif;
    $paid = ($d['status'] ?? '') === 'paid';
    $partly = !$paid && (float)($d['received'] ?? 0) > 0;
    $openUrl = $rpayBase . '/due_detail.php?' . http_build_query(['grp' => $grp, 'due' => (int)$d['id']]);
    $payUrl = $rpayBase . '/pay.php?' . http_build_query(['grp' => $grp, 'due' => (int)$d['id']]); ?>
    <tr class="<?= $paid ? 'rp-paid' : ($partly ? 'rp-partly' : 'rp-pending') ?>">
      <td><input type="checkbox" class="form-check-input rp-move-tick d-none" name="due_ids[]" value="<?= (int)$d['id'] ?>" form="<?= $mv ?>" title="Tick to move"></td>
      <td>
        <a class="fw-semibold text-decoration-none text-body" href="<?= h($openUrl) ?>"><?= h($d['title'] ?? '') ?></a>
        <div class="tiny text-muted"><?= h(implode(' · ', array_filter([$d['building']['name'] ?? '', $d['unit'] ?? '']))) ?><?= !empty($d['moved']) ? ' · <span class="text-primary">moved here</span>' : '' ?></div>
      </td>
      <td class="text-nowrap"><?= h($bedOf((string)($d['title'] ?? ''))) ?></td>
      <td class="text-nowrap"><?= h(rpay_date($d['due_date'] ?? '')) ?></td>
      <?php $dur = rpay_line_period($d, $rowEntries[(int)($d['entry_id'] ?? 0)] ?? null, $rowSaved);
        if (!$dur['edited'] && ($tenantOfRow = rpay_payment_tenant($rowTenants, (string)($d['title'] ?? '')))) {
            $dur = rpay_stay_period($d['due_date'] ?? null, $tenantOfRow['start_date'] ?? null, $tenantOfRow['end_date'] ?? null) ?? $dur;
        } ?>
      <td class="text-nowrap"><?php if ($dur['from'] !== '' && $dur['to'] !== ''): ?><?= h(rpay_date($dur['from'])) ?><div class="tiny text-muted">to <?= h(rpay_date($dur['to'])) ?></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      <td class="text-nowrap"><?= ($paid || $partly) && !empty($d['paid_on']) ? h(rpay_date($d['paid_on'])) : '<span class="text-warning-emphasis fw-semibold">Not paid yet</span>' ?></td>
      <td class="text-end fw-bold text-nowrap"><?= h(rpay_money($d['amount'] ?? 0)) ?></td>
      <td>
        <?php if ($paid || $partly): ?>
          <div class="text-success fw-semibold text-nowrap"><?= h(rpay_money($d['received'] ?? 0)) ?></div>
          <?php if (!empty($d['account']['name'])): ?><div class="tiny text-muted"><?= h($d['account']['name']) ?></div><?php endif; ?>
          <?php if ($partly): ?><div class="tiny text-warning-emphasis fw-semibold"><?= h(rpay_money($d['left'] ?? 0)) ?> to come</div><?php endif; ?>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
      <td class="text-center"><?= h(RPAY_PAYMENT_MODES[rpay_entry_mode($modePicks, (int)($d['entry_id'] ?? 0), rpay_mode_text($d))]) ?></td>
      <td><span class="badge text-bg-<?= $paid ? 'success' : ($partly ? 'info' : 'warning') ?>"><?= $paid ? 'Paid' : ($partly ? 'Partly paid' : 'Pending') ?></span></td>
      <td class="text-nowrap no-print">
        <a href="<?= h($payUrl) ?>" class="btn btn-sm <?= $paid ? 'btn-light border' : 'btn-success' ?>"><?= $paid ? 'Receipts' : ($partly ? 'Add' : 'Mark paid') ?></a>
        <a href="<?= h($openUrl) ?>" class="btn btn-sm btn-light border" title="Open"><i class="bi bi-box-arrow-up-right"></i></a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
<div class="rp-pay-grid" data-move="<?= $mv ?>">
  <?php foreach ($rowsList as $d):
    $paid = ($d['status'] ?? '') === 'paid';
    $partly = !$paid && (float)($d['received'] ?? 0) > 0;
    $openUrl = $rpayBase . '/due_detail.php?' . http_build_query(['grp' => $grp, 'due' => (int)$d['id']]);
    $payUrl = $rpayBase . '/pay.php?' . http_build_query(['grp' => $grp, 'due' => (int)$d['id']]); ?>
    <div class="rp-pay <?= $paid ? 'rp-paid' : ($partly ? 'rp-partly' : 'rp-pending') ?>">
      <input type="checkbox" class="form-check-input rp-move-tick d-none" name="due_ids[]" value="<?= (int)$d['id'] ?>" form="<?= $mv ?>" title="Tick to move">
      <div class="rp-what">
        <a class="fw-semibold text-decoration-none text-body" href="<?= h($openUrl) ?>"><?= h($d['title'] ?? '') ?></a>
        <div class="tiny text-muted"><?= h(implode(' · ', array_filter([$d['building']['name'] ?? '', $d['unit'] ?? '']))) ?><?= !empty($d['moved']) ? ' · <span class="text-primary">moved here</span>' : '' ?></div>
      </div>
      <div class="rp-due">
        <div class="rp-date rp-date-due"><i class="bi bi-calendar-event"></i> Due on <b><?= h(rpay_date($d['due_date'] ?? '')) ?></b></div>
        <?php if (($paid || $partly) && !empty($d['paid_on'])): ?>
          <div class="rp-date rp-date-got mt-1"><i class="bi bi-check2"></i> Paid on <b><?= h(rpay_date($d['paid_on'])) ?></b></div>
        <?php else: ?>
          <div class="tiny text-warning-emphasis fw-semibold mt-1">Not paid yet</div>
        <?php endif; ?>
<?php if ($alert = rpay_due_alert($d['due_date'] ?? null, !$paid)): ?><div class="mt-1"><?= rpay_due_bell($alert) ?></div><?php endif; ?>
      </div>
      <div class="rp-amt fw-bold"><?= h(rpay_money($d['amount'] ?? 0)) ?></div>
      <div class="rp-st">
        <span class="badge text-bg-<?= $paid ? 'success' : ($partly ? 'info' : 'warning') ?>"><?= $paid ? 'Paid' : ($partly ? 'Partly paid' : 'Pending') ?></span>
        <?php if ($paid || $partly): ?>
          <div class="tiny text-success fw-semibold"><?= h(rpay_money($d['received'] ?? 0)) ?> received</div>
          <?php if (!empty($d['account']['name'])): ?><div class="tiny text-muted"><?= h($d['account']['name']) ?></div><?php endif; ?>
          <?php if ($partly): ?><div class="tiny text-warning-emphasis fw-semibold"><?= h(rpay_money($d['left'] ?? 0)) ?> to come</div><?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="rp-act">
        <a href="<?= h($payUrl) ?>" class="btn btn-sm <?= $paid ? 'btn-light border' : 'btn-success' ?>"><?= $paid ? 'Receipts' : ($partly ? '<i class="bi bi-plus-lg"></i> Add' : '<i class="bi bi-check-lg"></i> Mark paid') ?></a>
        <a href="<?= h($openUrl) ?>" class="btn btn-sm btn-light border" title="Open"><i class="bi bi-box-arrow-up-right"></i></a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script>
// "Move payments": shows a tick box on each payment and the bar to pick the room; Cancel hides them.
(function () {
  var form = document.getElementById(<?= json_encode($mv) ?>);
  if (!form || form.dataset.wired) { return; }
  form.dataset.wired = '1';
  var grid = document.querySelector('.rp-pay-grid[data-move="' + form.id + '"]');
  var bar = form.querySelector('.rp-move-bar'), start = form.querySelector('.rp-move-start'), n = form.querySelector('.rp-move-n');
  var ticks = grid ? grid.querySelectorAll('.rp-move-tick') : [];
  function count() { n.textContent = Array.prototype.filter.call(ticks, function (t) { return t.checked; }).length; }
  ticks.forEach(function (t) { t.addEventListener('change', count); });
  start.addEventListener('click', function () {
    start.classList.add('d-none'); bar.classList.remove('d-none'); bar.classList.add('d-flex');
    ticks.forEach(function (t) { t.classList.remove('d-none'); });
  });
  form.querySelector('.rp-move-cancel').addEventListener('click', function () {
    ticks.forEach(function (t) { t.checked = false; t.classList.add('d-none'); });
    bar.classList.add('d-none'); bar.classList.remove('d-flex'); start.classList.remove('d-none'); count();
  });
})();
</script>
<?php elseif ($rpayUi === 'css'): ?>
<style>
/* The payment rows above: the same look as the group pages. */
.rp-pay-grid{ display:flex; flex-direction:column; gap:8px; }
.rp-pay{ display:flex; align-items:center; gap:14px; border:1px solid #e3e6ea; border-radius:10px; padding:8px 12px; }
.rp-pay .rp-for{ width:150px; flex-shrink:0; }
.rp-pay .rp-what{ flex:1 1 220px; min-width:0; }
.rp-pay .rp-due{ width:190px; flex-shrink:0; }
.rp-pay .rp-due .rp-date{ display:block; width:max-content; }
.rp-pay .rp-amt{ width:120px; text-align:right; flex-shrink:0; }
.rp-pay .rp-st{ width:230px; flex-shrink:0; }
.rp-pay .rp-act{ display:flex; gap:6px; flex-shrink:0; }
.rp-pay .tiny{ font-size:.78rem; }
.rp-sheet tr.rp-paid td{ background:#eaf7ee; } .rp-sheet tr.rp-paid td:first-child{ box-shadow:inset 4px 0 0 #2e9e5b; }
.rp-sheet tr.rp-partly td{ background:#e8f3fc; } .rp-sheet tr.rp-partly td:first-child{ box-shadow:inset 4px 0 0 #2f80c4; }
.rp-sheet tr.rp-pending td{ background:#fff8e1; } .rp-sheet tr.rp-pending td:first-child{ box-shadow:inset 4px 0 0 #e0a800; }
.rp-sheet tbody tr:not(.rp-headrow) td{ border-color:#c9ced6 !important; }
.rp-sheet tr.rp-headrow td{ background:#dbeafe; color:#1e3a8a; font-weight:700; border-left:0 !important; border-right:0 !important; }
@media print {
  body * { visibility: hidden !important; }
  #rpPrint, #rpPrint * { visibility: visible !important; }
  #rpPrint { position: absolute; left: 0; top: 0; width: 100%; }
  .no-print { display: none !important; }
}
.rp-date{ display:inline-block; padding:1px 8px; border-radius:6px; font-size:.78rem; font-weight:600; background:#fff; border:1px solid #d6dbe2; color:#334155; white-space:nowrap; }
.rp-date-due{ background:#e8eefc; border-color:#c7d4f5; color:#1e3a8a; }
.rp-date-got{ background:#d8f2e1; border-color:#a9dcbc; color:#0f5132; }
body.dark-mode .rp-date{ background:#0f172a; border-color:#475569; color:#e2e8f0; }
body.dark-mode .rp-date-due{ background:#1e3a8a; border-color:#2f4370; color:#dbe4ff; }
body.dark-mode .rp-date-got{ background:#1d4d33; border-color:#2e7a52; color:#c9f2d8; }
.rp-pay.rp-paid{ background:#eaf7ee; border-color:#c3e6cf; border-left:4px solid #2e9e5b; }
.rp-pay.rp-pending{ background:#fff8e1; border-color:#f3e2a6; border-left:4px solid #e0a800; }
.rp-pay.rp-partly{ background:#e8f3fc; border-color:#c5def3; border-left:4px solid #2f80c4; }
body.dark-mode .rp-pay.rp-paid{ background:#15301f; border-color:#24513a; border-left-color:#3fbf73; }
body.dark-mode .rp-pay.rp-pending{ background:#33290f; border-color:#5a4919; border-left-color:#e0a800; }
body.dark-mode .rp-pay.rp-partly{ background:#142b3d; border-color:#23476a; border-left-color:#4b9fe0; }
@media (max-width:768px){ .rp-pay{ flex-wrap:wrap; gap:6px 12px; } .rp-pay .rp-for{ width:auto; } .rp-pay .rp-what{ flex-basis:100%; order:-1; } .rp-pay .rp-amt{ width:auto; } .rp-pay .rp-st{ width:auto; } .rp-pay .rp-due{ width:auto; } }
.rp-move .form-select{ max-width:160px; }
.rp-alert{ display:inline-flex; align-items:center; gap:4px; padding:1px 8px; border-radius:999px; font-size:.75rem; font-weight:700; white-space:nowrap; }
.rp-alert-overdue{ background:#fde2e4; color:#b02a37; border:1px solid #f5b5bb; }
.rp-alert-soon{ background:#fff3cd; color:#8a5300; border:1px solid #ffe08a; }
.rp-alert .bi-bell-fill{ animation:rpRing 1.6s ease-in-out infinite; transform-origin:top center; }
@keyframes rpRing{ 0%,60%,100%{ transform:rotate(0); } 10%{ transform:rotate(14deg); } 20%{ transform:rotate(-12deg); } 30%{ transform:rotate(8deg); } 40%{ transform:rotate(-6deg); } }
.rp-bell-count{ display:inline-flex; align-items:center; gap:3px; padding:1px 8px; border-radius:999px; background:#dc3545; color:#fff; font-size:.75rem; font-weight:700; vertical-align:middle; }
body.dark-mode .rp-alert-overdue{ background:#4a1a20; color:#ffb3ba; border-color:#7a2a33; }
body.dark-mode .rp-alert-soon{ background:#4a3b0b; color:#ffe2a0; border-color:#7a6213; }
</style>
<?php elseif ($rpayUi === 'notes'):
$notesMe = (int)current_user_id();
$notesBoss = function_exists('current_user_roles') && array_intersect(current_user_roles($conn), ['Owner', 'Admin']);
?>
<div class="rpay-notes">
  <details class="rpay-addnote">
    <summary class="d-flex align-items-center gap-2">
      <span class="fw-semibold">Notes</span>
      <span class="btn btn-sm btn-light border"><i class="bi bi-plus-lg"></i> Add note</span>
    </summary>
    <form method="post" action="<?= h($notesPostUrl) ?>" class="d-flex gap-1 mt-2">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="add_note">
      <input type="hidden" name="entry" value="<?= (int)$notesEntry['id'] ?>">
      <?php if (!empty($notesDue)): ?><input type="hidden" name="note_due" value="<?= (int)$notesDue ?>"><?php endif; ?>
      <input type="text" name="note" class="form-control form-control-sm" maxlength="<?= RPAY_ENTRY_NOTE_MAX ?>" placeholder="Type the note" required>
      <button class="btn btn-sm btn-success">Save</button>
    </form>
  </details>
  <style>.rpay-addnote > summary{ list-style:none; cursor:pointer; } .rpay-addnote > summary::-webkit-details-marker{ display:none; }</style>

  <ul class="list-unstyled mb-0">
    <?php foreach ($notesList as $n): ?>
      <li class="py-2 border-bottom d-flex gap-2">
        <div class="flex-grow-1">
          <div style="white-space:pre-wrap"><?= h($n['note']) ?></div>
          <div class="small text-muted"><?= h($n['by'] !== '' ? $n['by'] : 'Someone') ?> · <?= h(rpay_datetime($n['at'])) ?><?php if (!empty($n['due_id'])): ?> · <?= isset($notesDue) && (int)$notesDue === (int)$n['due_id'] ? 'on this payment' : 'on another payment' ?><?php endif; ?></div>
        </div>
        <?php if ($n['by_id'] === $notesMe || $notesBoss): ?>
          <form method="post" action="<?= h($notesPostUrl) ?>" class="m-0" onsubmit="return confirm('Delete this note?');">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="delete_note">
            <input type="hidden" name="entry" value="<?= (int)$notesEntry['id'] ?>">
            <input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">
            <button class="btn btn-sm btn-link text-danger p-0" title="Delete this note"><i class="bi bi-trash"></i></button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    <?php if (!empty($notesEntry['notes'])): ?>
      <li class="py-2">
        <div style="white-space:pre-wrap"><?= h($notesEntry['notes']) ?></div>
        <div class="small text-muted">Written on the entry</div>
      </li>
    <?php endif; ?>
  </ul>
</div>
<?php endif; ?>
