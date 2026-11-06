<?php
/**
 * ARS Move-Out for one booking (GET booking_id): Inspection Schedule -> Final
 * Inspection -> Key Return (checks the guest out) -> Inspection Report -> Damages &
 * Charges -> Deposit Settlement (breakdown; Accounts settles it) -> Completed
 * (the booking is completed).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
require_once __DIR__ . '/includes/ars_move_process.php';
require_once __DIR__ . '/includes/ars_move_page.php';

$arsCompanyId = arsPageAuth($conn);
$bookingId = (int)($_GET['booking_id'] ?? 0);
$b = amp_booking($conn, $arsCompanyId, $bookingId);
if (!$b) {
    http_response_code(404);
    exit('Booking not found');
}
$mo = amp_record($conn, $arsCompanyId, $bookingId, 'out');
$lastCancelled = !$mo ? amp_record($conn, $arsCompanyId, $bookingId, 'out', true) : null;
$canManage = amp_can_manage($conn);
$m2 = static fn($v) => number_format((float)$v, 2);
$today = amp_today();

ars_shell_begin([
    'title' => 'Move-Out',
    'subtitle' => $b['booking_number'] . ' · ' . amp_guest_name($b),
    'breadcrumbs' => [['label' => 'ARS', 'href' => 'index.php'], ['label' => 'Move-Out', 'href' => 'move_report.php?tab=out'], ['label' => $b['booking_number']]],
    'actions_html' => '<a class="no-underline inline-flex items-center gap-1 rounded-ars-md border border-ars-border px-3 py-2 text-ars-sm font-semibold text-ars-text hover:border-ars-ink/40" href="booking_view.php?id=' . $bookingId . '">'
        . ars_ui_icon('external-link', ['class' => 'h-4 w-4']) . ' Open booking</a>',
    'legacy_bootstrap' => true,
]);
echo amp_page_css();
?>
<div class="rm-page">
<?= amp_booking_strip($b) ?>

<?php if (!$mo): ?>
    <?php $block = amp_start_block($conn, $arsCompanyId, $b, 'out'); ?>
    <div class="amp-card"><div class="amp-b">
        <?php if ($lastCancelled): ?>
            <div class="alert alert-secondary py-2"><i class="bi bi-x-octagon"></i> The last move-out was cancelled on <?= h(date('d M Y H:i', strtotime($lastCancelled['cancelled_at']))) ?>: <?= h($lastCancelled['cancel_reason']) ?></div>
        <?php endif; ?>
        <?php if ($block): ?>
            <p class="mb-2"><?= h($block) ?></p>
            <?php if (in_array($b['status'], ['pending', 'confirmed'], true)): ?><a class="btn btn-outline-primary btn-sm" href="move_in_view.php?booking_id=<?= $bookingId ?>"><i class="bi bi-box-arrow-in-right"></i> Go to Move-In</a><?php endif; ?>
        <?php else: ?>
            <h5 class="mb-1">Start the move-out</h5>
            <p class="text-muted">Inspection schedule, final inspection, key return (the guest is checked out), inspection report, damages &amp; charges and the deposit settlement.</p>
            <?php $rule = amp_rule($conn, $arsCompanyId, $b); if (!$rule['on']): ?>
            <div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> Short stay: <?= (int)$rule['nights'] ?> night<?= $rule['nights'] == 1 ? '' : 's' ?>, under the <?= (int)$rule['min'] ?> nights that need the Move-Out process. Use <strong>Check Out</strong> on the booking, or start the process anyway.</div>
            <a class="btn btn-outline-secondary" href="booking_view.php?id=<?= $bookingId ?>"><i class="bi bi-arrow-left"></i> Back to booking (check out)</a>
            <button type="button" class="btn btn-primary" onclick="if(confirm('Start the Move-Out process for this short stay?')) ampPost('start', {}, this).then(r => { if (r.success) location.href = 'move_out_view.php?booking_id=<?= $bookingId ?>&ms=0'; })"><i class="bi bi-play-circle"></i> Start anyway</button>
            <?php else: ?>
            <button type="button" class="btn btn-primary" onclick="ampPost('start', {}, this).then(r => { if (r.success) location.href = 'move_out_view.php?booking_id=<?= $bookingId ?>&ms=0'; })"><i class="bi bi-play-circle"></i> Initiate Move-Out Process</button>
            <?php endif; ?>
        <?php endif; ?>
    </div></div>
<?php else:
    $st = amp_out_state($conn, $arsCompanyId, $b, $mo);
    $isDone = $mo['status'] === 'completed';
    $settled = !empty($mo['settlement_completed_at']);
    $keys = amp_keys($conn, $arsCompanyId, $bookingId);
    $ks = amp_keys_summary($keys);
    $dmg = amp_damages($conn, (int)$mo['id']);
    $charges = amp_charges($conn, (int)$mo['id']);
    $removed = amp_charges($conn, (int)$mo['id'], true);
    $fig = amp_figures($conn, $b, $mo);
    $inspected = $st['done'][2];
    $flags = [true, $st['done'][1], $st['done'][2], $st['done'][3], $st['done'][4], $st['done'][5], $st['done'][6], $isDone];
    $current = array_search(false, $flags, true);
    $state = static fn(int $n) => $flags[$n] ? 'done' : ($n === $current ? 'current' : 'todo');
    $items = [
        ['label' => 'Process Initiated', 'icon' => 'bi-flag', 'state' => 'done', 'sub' => date('M d', strtotime($mo['created_at']))],
        ['label' => 'Inspection Schedule', 'icon' => 'bi-calendar-event', 'state' => $state(1), 'sub' => $mo['inspection_date'] ? date('M d', strtotime($mo['inspection_date'])) . ($mo['inspection_time'] ? ' · ' . substr($mo['inspection_time'], 0, 5) : '') : 'Not scheduled'],
        ['label' => 'Final Inspection', 'icon' => 'bi-clipboard-check', 'state' => $state(2), 'sub' => ''],
        ['label' => 'Key Return', 'icon' => 'bi-key', 'state' => $state(3), 'optional' => true, 'sub' => $ks['missing'] ? implode(', ', $ks['missing']) . ' missing' : ''],
        ['label' => 'Inspection Report', 'icon' => 'bi-file-earmark-text', 'state' => $state(4), 'optional' => true, 'sub' => $st['signed'] ? 'Signed copy uploaded' : ''],
        ['label' => 'Damages & Charges', 'icon' => 'bi-exclamation-triangle', 'state' => $state(5), 'optional' => $st['done'][5], 'sub' => $dmg ? count($dmg) . ' damage(s)' : ''],
        ['label' => 'Deposit Settlement', 'icon' => 'bi-shield-check', 'state' => $state(6), 'sub' => ''],
        ['label' => 'Completed', 'icon' => 'bi-house-door', 'state' => $state(7), 'sub' => ''],
    ];
    $heads = [
        ['bi-flag', 'Move-Out Process Initiated', 'Booking, guest and stay details.'],
        ['bi-calendar-event', 'Inspection Schedule', 'When the final inspection is done with the guest.'],
        ['bi-clipboard-check', 'Final Inspection', 'Check the unit: checklist, remarks and photos.'],
        ['bi-key', 'Key Return', 'Check back the keys and cards handed over at move-in. Confirming checks the guest out.'],
        ['bi-file-earmark-text', 'Inspection Report', 'The guest signs the move-out inspection report: on screen (Digital Sign), on paper (upload the scan) or by email.'],
        ['bi-exclamation-triangle', 'Damages & Charges', 'Damages found and the standard move-out charges, with their amounts.'],
        ['bi-shield-check', 'Deposit Settlement', 'The deposit against the damages and charges. Accounts settles it; mark it complete here.'],
        ['bi-house-door', 'Completed', 'Close the move-out and the booking.'],
    ];
    $hints = [1 => 'Schedule the inspection to continue', 2 => 'Complete the final inspection to continue',
        5 => 'Enter an amount for every damage and charge (0 if none) to continue', 6 => 'Mark the deposit settlement as complete to continue'];
    $selected = $current === false ? 7 : (int)$current;
    // ?ms=N opens an earlier step (e.g. Process Initiated right after starting)
    if (isset($_GET['ms']) && ctype_digit((string)$_GET['ms']) && (int)$_GET['ms'] <= $selected) {
        $selected = (int)$_GET['ms'];
    }
    echo re_milestones_html($items, $selected, 'Move-out milestones');
    $reach = re_milestones_reach($items);
    foreach ($heads as $i => [$icon, $title, $text]) {
        echo re_milestone_head($i, count($heads), $icon, $title, $text, $items[$i]['state'], $reach, $hints[$i] ?? 'Finish this step to continue');
    }
    $dmgEditable = !$isDone && !$settled;
?>
    <!-- 0 Summary -->
    <div class="amp-card" data-ms="0 7"><div class="amp-h"><span><i class="bi bi-info-circle me-1"></i>Summary</span><?= re_move_ui_pill($isDone ? 'Completed' : 'In progress', $isDone ? 'success' : 'primary') ?></div>
        <div class="amp-b"><div class="amp-facts mb-3">
            <span><span class="k">Initiated</span><?= h(date('d M Y H:i', strtotime($mo['created_at']))) ?></span>
            <span><span class="k">Planned check-out</span><?= h(date('d M Y', strtotime($b['check_out']))) ?></span>
            <span><span class="k">Deposit</span><?= $fig['deposit'] > 0 ? h($m2($fig['deposit'])) . ' AED · ' . h(ucwords(str_replace('_', ' ', $fig['deposit_status']))) : 'None' ?></span>
        </div>
        <?= !$isDone ? amp_void_html('out', $canManage, !empty($mo['keys_returned']) ? 'The guest is checked out: the move-out can no longer be cancelled.' : null) : '' ?>
    </div></div>

    <!-- 1 Schedule -->
    <div class="amp-card" data-ms="1"><div class="amp-h"><span><i class="bi bi-calendar-event me-1"></i>Inspection Schedule</span><?= re_move_ui_pill($st['done'][1] ? 'Scheduled' : 'To do', $st['done'][1] ? 'success' : 'primary') ?></div><div class="amp-b">
        <form class="row g-2 align-items-end" onsubmit="event.preventDefault(); ampPostReload('schedule_save', {inspection_date: this.inspection_date.value, inspection_time: this.inspection_time.value, note: this.note.value}, this.querySelector('button'));">
            <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Inspection date</label><input type="date" name="inspection_date" class="form-control form-control-sm" required value="<?= h($mo['inspection_date'] ?? '') ?>" <?= $inspected ? 'disabled' : '' ?>></div>
            <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Time</label><input type="time" name="inspection_time" class="form-control form-control-sm" value="<?= h($mo['inspection_time'] ? substr($mo['inspection_time'], 0, 5) : '') ?>" <?= $inspected ? 'disabled' : '' ?>></div>
            <div class="col-sm-5"><label class="form-label small fw-semibold mb-1">Note</label><input type="text" name="note" maxlength="255" class="form-control form-control-sm" value="<?= h($mo['inspection_note'] ?? '') ?>" placeholder="e.g. Guest leaves the key at reception" <?= $inspected ? 'disabled' : '' ?>></div>
            <?php if (!$inspected): ?><div class="col-12"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-save"></i> Save schedule</button></div><?php endif; ?>
        </form>
    </div></div>

    <!-- 2 Final inspection -->
    <?= amp_inspection_section($conn, $arsCompanyId, $bookingId, 'out', 2, !$isDone && !$settled, $settled ? 'Locked: the deposit settlement is marked complete.' : '') ?>

    <!-- 3 Key return -->
    <div class="amp-card" data-ms="3"><div class="amp-h"><span><i class="bi bi-key me-1"></i>Key Return</span><span class="d-inline-flex align-items-center gap-2"><?php if (!$isDone && empty($mo['keys_returned']) && array_filter($keys, static fn($k) => (int)$k['quantity'] > 0)): ?><button type="button" class="btn btn-sm btn-outline-success" onclick="document.querySelectorAll('#ampReturnForm input[name^=returned]').forEach(i => { i.value = i.placeholder; });"><i class="bi bi-check2-all"></i> All returned as issued</button><?php endif; ?><?= re_move_ui_pill(!empty($mo['keys_returned']) ? 'Returned' : 'To do', !empty($mo['keys_returned']) ? 'success' : 'primary') ?></span></div><div class="amp-b">
        <?php $krEditable = !$isDone && empty($mo['keys_returned']); $issued = array_filter($keys, static fn($k) => (int)$k['quantity'] > 0);
            $keyShort = static fn(array $k): int => $k['returned_qty'] === null ? 0 : max(0, (int)$k['quantity'] - (int)$k['returned_qty']);
            $anyShort = (bool)array_filter($issued, static fn($k) => $keyShort($k) > 0 || !empty($k['damage_id']));
            $keyDmgEditable = !$isDone && !$settled; ?>
        <?php if (!$issued): ?>
            <div class="alert alert-warning small py-2">No keys or cards were recorded at move-in. Record them on the <a href="move_in_view.php?booking_id=<?= $bookingId ?>#step-3">move-in Key Handover</a> or enter them in the booking notes.</div>
        <?php else: ?>
        <form id="ampReturnForm" onsubmit="event.preventDefault(); const fd = new FormData(this); fd.append('action', 'keys_return_save'); fd.append('type', 'out'); ampFetch('ajax_move_process.php', fd).then(r => r.success ? ampReload() : alert(r.message));">
            <div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>Key / access</th><th style="width:80px">Issued</th><th style="width:110px">Returned</th><th>Note</th><?php if ($anyShort): ?><th style="width:1%">Missing</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($issued as $k): ?>
                <tr><td class="fw-semibold"><?= h($k['key_label']) ?><?= $k['card_numbers'] ? '<div class="small text-muted">' . h($k['card_numbers']) . '</div>' : '' ?></td><td><?= (int)$k['quantity'] ?></td>
                    <td><?php if ($krEditable): ?><input type="number" min="0" max="99" class="form-control form-control-sm" name="returned[<?= (int)$k['id'] ?>]" value="<?= $k['returned_qty'] === null ? '' : (int)$k['returned_qty'] ?>" placeholder="<?= (int)$k['quantity'] ?>"><?php else: ?><strong class="<?= (int)$k['returned_qty'] < (int)$k['quantity'] ? 'text-danger' : '' ?>"><?= $k['returned_qty'] === null ? '—' : (int)$k['returned_qty'] ?></strong><?php endif; ?></td>
                    <td><?php if ($krEditable): ?><input type="text" maxlength="255" class="form-control form-control-sm" name="note[<?= (int)$k['id'] ?>]" value="<?= h($k['return_note'] ?? '') ?>" placeholder="optional"><?php else: ?><span class="small"><?= h($k['return_note'] ?? '') ?></span><?php endif; ?></td>
                    <?php if ($anyShort): ?><td class="text-nowrap">
                        <?php if (!empty($k['damage_id'])): ?>
                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle" title="Listed in Damages &amp; Charges: enter the replacement cost there"><i class="bi bi-exclamation-triangle"></i> Charged as damage</span>
                            <?php if ($keyDmgEditable): ?><button type="button" class="btn btn-sm btn-link p-0 ms-1" onclick="if(confirm('Remove the damage charge for this key?')) ampPostReload('key_damage_undo', {type: 'out', key_id: <?= (int)$k['id'] ?>});"><i class="bi bi-arrow-counterclockwise"></i> Undo</button><?php endif; ?>
                        <?php elseif ($keyShort($k) > 0): ?>
                            <span class="small text-danger fw-semibold me-1"><?= $keyShort($k) ?> missing</span>
                            <?php if ($keyDmgEditable): ?><button type="button" class="btn btn-sm amp-dmg-btn" onclick="ampPostReload('key_damage', {type: 'out', key_id: <?= (int)$k['id'] ?>}, this);" title="Add to Damages &amp; Charges; the replacement cost is entered there"><i class="bi bi-exclamation-triangle"></i> Charge as damage</button><?php endif; ?>
                        <?php endif; ?>
                    </td><?php endif; ?></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php if ($krEditable): ?>
                <div class="d-flex flex-wrap gap-2 mb-2">                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-save"></i> Save</button></div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
        <div class="mt-2 pt-3 border-top">
            <?php if (!empty($mo['keys_returned'])): ?>
                <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill"></i> Keys returned <?= h(date('d M Y H:i', strtotime($mo['keys_returned_at']))) ?> · move-out date <?= h(date('d M Y', strtotime($mo['move_out_date']))) ?></span>
                <?php if ($canManage && !$isDone): ?>
                    <button type="button" class="btn btn-sm btn-link p-0 ms-2" onclick="const r = prompt('Undo the key return? The guest is set back to checked in, so the returned keys and the date can be changed; confirm the key return again afterwards.\n\nReason:'); if (r !== null) ampPostReload('keys_return_undo', {type: 'out', reason: r});"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>
                <?php endif; ?>
                <?php if ($ks['missing']): ?><div class="small text-danger mt-1">Outstanding: <?= h(implode(', ', $ks['missing'])) ?> (partial return acknowledged)<?php if (array_filter($issued, static fn($k) => $keyShort($k) > 0 && empty($k['damage_id'])) && $keyDmgEditable): ?> · use <strong>Charge as damage</strong> to recover the replacement cost in Damages &amp; Charges<?php endif; ?></div><?php endif; ?>
                <?php if ($b['status'] === 'checked_in'): ?>
                    <div class="alert alert-warning small py-2 mt-2 mb-0">The guest is not checked out yet. <button type="button" class="btn btn-sm btn-warning ms-1" onclick="this.disabled = true; ampBooking('checkout', {actual_check_out: <?= h(json_encode($mo['move_out_date'])) ?>}).then(ampReload).catch(e => { this.disabled = false; alert(e.message); })">Check guest out now</button></div>
                <?php endif; ?>
            <?php elseif (!$isDone): ?>
                <div class="row g-2 align-items-end">
                    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1" for="ampOutDate">Move-out date</label>
                        <input type="date" class="form-control form-control-sm" id="ampOutDate" min="<?= h($b['check_in']) ?>" max="<?= h($today) ?>" value="<?= h(min((string)($mo['move_out_date'] ?: $b['check_out']), $today)) ?>"></div>
                    <div class="col-sm-8"><button type="button" class="btn btn-success" id="ampReturnGo"><i class="bi bi-key-fill"></i> Keys &amp; Access Returned</button></div>
                </div>
                <div class="form-check mt-2 <?= $ks['missing'] ? '' : 'd-none' ?>" id="ampPartialBox"><input class="form-check-input" type="checkbox" id="ampPartial"><label class="form-check-label small" for="ampPartial">Acknowledge partial return: <span id="ampPartialList"><?= h(implode(', ', $ks['missing'])) ?></span> outstanding</label></div>
                <div class="small text-muted mt-1">The guest is checked out on the move-out date when the key return is confirmed.</div>
            <?php endif; ?>
        </div>
    </div></div>

    <!-- 4 Inspection report -->
    <?php $ip = $st['inspection'] ? count(ars_move_inspection_photos($conn, (int)$st['inspection']['id'])) : 0; ?>
    <?= amp_form_card($b, 'out', 4, $inspected, $st['signed'], $mo['report_sent_at'], $mo['report_sent_to'], $ip, amp_superseded_forms($conn, $arsCompanyId, $bookingId, 'out', $mo), $isDone) ?>

    <!-- 5 Damages & charges -->
    <div class="amp-card" data-ms="5"><div class="amp-h"><span><i class="bi bi-exclamation-triangle me-1"></i>Damages</span><span class="fw-bold text-danger"><?= $m2($fig['damage_total']) ?> AED</span></div><div class="amp-b">
        <?php if (!$inspected): ?><div class="alert alert-warning small py-2 mb-0">Complete the final inspection first.</div><?php else: ?>
        <?php if (!$dmg): ?><p class="text-muted small">No damages recorded.</p><?php endif; ?>
        <?php foreach ($dmg as $d): ?>
            <div class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom">
                <div class="flex-grow-1"><strong><?= h($d['description']) ?></strong><?= $d['area'] ? ' <span class="small text-muted">· ' . h($d['area']) . '</span>' : '' ?></div>
                <?php if ($dmgEditable): ?>
                    <?php $dLocked = $d['cost'] !== null; ?>
                    <form class="d-flex gap-1 amp-lockrow<?= $dLocked ? ' is-locked' : '' ?>" onsubmit="event.preventDefault(); ampSaveAmounts(this.querySelector('[data-save]'));">
                        <div class="input-group input-group-sm" style="width:170px"><input type="number" step="0.01" min="0" name="cost" data-dcost="<?= (int)$d['id'] ?>" <?= $dLocked ? 'readonly' : '' ?> class="form-control <?= $d['cost'] === null ? 'is-invalid' : '' ?>" value="<?= $d['cost'] === null ? '' : h(number_format((float)$d['cost'], 2, '.', '')) ?>" placeholder="Repair cost"><span class="input-group-text">AED</span></div>
                        <button class="btn btn-sm btn-outline-primary" type="submit" data-save title="Save all repair costs and charges"><i class="bi bi-check2"></i></button><button type="button" class="btn btn-sm btn-outline-secondary amp-edit-btn" title="Edit the repair cost" onclick="ampUnlock(this)"><i class="bi bi-pencil"></i></button></form>
                    <button type="button" class="btn btn-link btn-sm text-muted p-0" title="Remove" onclick="if (confirm('Remove this damage?')) ampPostReload('damage_delete', {type: 'out', damage_id: <?= (int)$d['id'] ?>})"><i class="bi bi-x-lg"></i></button>
                <?php else: ?><strong><?= $d['cost'] === null ? '—' : $m2($d['cost']) . ' AED' ?></strong><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($dmgEditable): ?>
            <form class="row g-2 mt-2" onsubmit="event.preventDefault(); ampPostReload('damage_add', {type: 'out', area: this.area.value, description: this.description.value, cost: this.cost.value}, this.querySelector('button'));">
                <div class="col-sm-3"><input type="text" name="area" maxlength="100" class="form-control form-control-sm" placeholder="Area, e.g. Kitchen"></div>
                <div class="col-sm-5"><input type="text" name="description" maxlength="500" class="form-control form-control-sm" placeholder="Damage, e.g. Broken glass" required></div>
                <div class="col-sm-2"><input type="number" step="0.01" min="0" name="cost" class="form-control form-control-sm" placeholder="Cost"></div>
                <div class="col-sm-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit"><i class="bi bi-plus"></i> Add</button></div></form>
        <?php endif; ?>
        <?php endif; ?>
    </div></div>
    <div class="amp-card" data-ms="5"><div class="amp-h"><span><i class="bi bi-brush me-1"></i>Standard Move-Out Charges</span><a href="move_settings.php" class="small fw-normal text-decoration-none"><i class="bi bi-gear"></i> Edit the standard list</a></div><div class="amp-b">
        <?php if ($charges): ?>
        <form id="ampChargesForm" onsubmit="event.preventDefault(); ampSaveAmounts(this.querySelector('button[type=submit]'));">
            <div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>Charge</th><th style="width:150px">Amount (AED)</th><th>Note</th><th></th></tr></thead><tbody>
            <?php foreach ($charges as $c): ?>
                <?php $cLocked = $c['amount'] !== null; ?>
                <tr class="amp-lockrow<?= $cLocked ? ' is-locked' : '' ?>"><td class="fw-semibold"><?= h($c['charge_name']) ?></td>
                    <td><?php if ($dmgEditable): ?><input type="number" step="0.01" min="0" <?= $cLocked ? 'readonly' : '' ?> class="form-control form-control-sm <?= $c['amount'] === null ? 'is-invalid' : '' ?>" name="amount[<?= (int)$c['id'] ?>]" value="<?= $c['amount'] === null ? '' : h(number_format((float)$c['amount'], 2, '.', '')) ?>" placeholder="0 if none"><?php else: ?><strong><?= $c['amount'] === null ? '—' : $m2($c['amount']) ?></strong><?php endif; ?></td>
                    <td><?php if ($dmgEditable): ?><input type="text" maxlength="255" <?= $cLocked ? 'readonly' : '' ?> class="form-control form-control-sm" name="note[<?= (int)$c['id'] ?>]" value="<?= h($c['note'] ?? '') ?>" placeholder="optional"><?php else: ?><span class="small"><?= h($c['note'] ?? '') ?></span><?php endif; ?></td>
                    <td class="text-end text-nowrap"><?php if ($dmgEditable): ?><button type="button" class="btn btn-sm btn-outline-secondary amp-edit-btn" title="Edit the amount" onclick="ampUnlock(this)"><i class="bi bi-pencil"></i></button> <button type="button" class="btn btn-link btn-sm text-muted p-0 ms-1" title="Remove from this move-out" onclick="ampPostReload('charges_remove', {type: 'out', charge_id: <?= (int)$c['id'] ?>})"><i class="bi bi-x-lg"></i></button><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody><tfoot><tr><th class="text-end">Total</th><th><?= $m2($fig['charge_total']) ?> AED</th><th colspan="2"></th></tr></tfoot></table></div>
            <?php if ($dmgEditable): ?><button class="btn btn-sm btn-primary" type="submit" id="ampSaveAll" <?= array_filter($charges, static fn($c) => $c['amount'] === null) ? '' : 'hidden' ?>><i class="bi bi-save"></i> Save damages &amp; charges</button><?php endif; ?>
        </form>
        <?php else: ?><p class="text-muted small">No standard charges on this move-out.</p><?php endif; ?>
        <?php if ($removed): ?>
            <div class="small text-muted mt-2">Removed from this move-out: <?php foreach ($removed as $rc): ?><span class="rm-pill rm-pill-secondary ms-1"><?= h($rc['charge_name']) ?><?php if ($dmgEditable): ?> <a href="#" class="text-decoration-none" title="Restore" onclick="event.preventDefault(); ampPostReload('charges_restore', {type: 'out', charge_id: <?= (int)$rc['id'] ?>})"><i class="bi bi-arrow-counterclockwise"></i></a><?php endif; ?></span><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($dmgEditable): ?>
            <form class="d-flex gap-2 mt-3" onsubmit="event.preventDefault(); ampPostReload('charges_add', {type: 'out', name: this.cname.value});">
                <input type="text" name="cname" maxlength="100" class="form-control form-control-sm" placeholder="Add a charge for this move-out only, e.g. Extra cleaning" required>
                <button class="btn btn-sm btn-outline-secondary text-nowrap" type="submit"><i class="bi bi-plus"></i> Add</button></form>
        <?php elseif ($settled): ?><div class="form-text mt-2"><i class="bi bi-lock"></i> Locked: the deposit settlement is marked complete.</div><?php endif; ?>
    </div></div>

    <!-- 6 Deposit settlement -->
    <div class="amp-card" data-ms="6"><div class="amp-h"><span><i class="bi bi-shield-check me-1"></i>Deposit Settlement</span><?= re_move_ui_pill($settled ? 'Complete' : 'Pending', $settled ? 'success' : 'warning') ?></div><div class="amp-b">
        <table class="amp-stmt mb-2">
            <tr><td>Security deposit</td><td><?= $m2($fig['deposit']) ?></td></tr>
            <tr><td>Deposit held now <span class="small text-muted">(<?= h(ucwords(str_replace('_', ' ', $fig['deposit_status']))) ?>)</span></td><td><?= $m2($fig['held']) ?></td></tr>
            <?php foreach ($fig['damages'] as [$label, $amt]): ?>
                <tr class="less"><td>Damage: <?= h($label) ?></td><td><?= $amt === null ? '<span class="badge bg-warning text-dark">not costed</span>' : '− ' . $m2($amt) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($fig['charges'] as [$label, $amt, $note]): ?>
                <tr class="less"><td><?= h($label) ?><?= $note !== '' ? ' <span class="small text-muted">(' . h($note) . ')</span>' : '' ?></td><td><?= $amt === null ? '<span class="badge bg-warning text-dark">not entered</span>' : '− ' . $m2($amt) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$fig['damages'] && !$fig['charges']): ?><tr class="sub"><td colspan="2" style="text-align:left">No damages or charges.</td></tr><?php endif; ?>
            <tr class="sub"><td>Total damages and charges</td><td><?= $m2($fig['total']) ?></td></tr>
            <tr class="total"><td>Refund due to the guest</td><td><?= $m2($fig['refund']) ?> AED</td></tr>
            <?php if ($fig['payable'] > 0): ?><tr class="excess"><td>Payable by the guest (not covered by the deposit)</td><td><?= $m2($fig['payable']) ?> AED</td></tr><?php endif; ?>
        </table>
        <?php $setUrl = 'move_form.php?booking_id=' . $bookingId . '&kind=settlement'; ?>
        <div class="amp-row"><span class="amp-n">1</span><div class="flex-grow-1">
            <div class="fw-semibold">Send the settlement statement to Accounts</div>
            <div class="small text-muted mb-2">Deposit Settlement Statement: the breakdown above, for Accounts to book the refund or recovery on the booking's Deposit tab.<?= $fig['pending'] ? ' <span class="text-warning-emphasis">Marked DRAFT until every damage and charge has an amount.</span>' : '' ?></div>
            <div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-primary" href="<?= h($setUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Preview</a>
                <a class="btn btn-sm btn-outline-primary" href="<?= h($setUrl . '&download=1') ?>"><i class="bi bi-download"></i> Download PDF</a></div>
        </div></div>
        <div class="amp-row"><span class="amp-n">2</span><div class="flex-grow-1">
            <div class="fw-semibold mb-2">Mark complete once Accounts confirms it is booked</div>
            <?php if ($settled): ?>
                <div class="alert alert-success d-flex justify-content-between align-items-start flex-wrap gap-2 mb-0"><div><i class="bi bi-check-circle-fill"></i> Settlement complete · <?= h(date('d M Y H:i', strtotime($mo['settlement_completed_at']))) ?>
                    <?php if (!empty($mo['settlement_note'])): ?><div class="small mt-1"><?= h($mo['settlement_note']) ?></div><?php endif; ?></div>
                    <?php if ($canManage && !$isDone): ?><button type="button" class="btn btn-sm btn-outline-secondary" onclick="const r = prompt('Reopen the deposit settlement? Damages and charges can be changed again.\n\nReason:'); if (r !== null) ampPostReload('settlement_reopen', {type: 'out', note: r});"><i class="bi bi-arrow-counterclockwise"></i> Reopen</button><?php endif; ?></div>
            <?php elseif (!$inspected): ?>
                <div class="alert alert-warning py-2 mb-0">Complete the final inspection first.</div>
            <?php elseif ($fig['pending']): ?>
                <div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle"></i> <?= (int)$fig['pending'] ?> damage(s) / charge(s) still need an amount (Damages &amp; Charges).</div>
            <?php elseif (!$isDone): ?>
                <form class="d-flex gap-2 flex-wrap" onsubmit="event.preventDefault(); if (confirm('Mark the deposit settlement as complete? Damages and charges are locked after this.')) ampPostReload('settlement_complete', {type: 'out', note: this.note.value});">
                    <input type="text" name="note" class="form-control form-control-sm" style="max-width:420px" maxlength="500" placeholder="Note (optional), e.g. Refund paid by Accounts, ref 1234">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2-circle"></i> Mark Settlement as Complete</button></form>
            <?php endif; ?>
        </div></div>
    </div></div>

    <!-- 7 Completed -->
    <div class="amp-card" data-ms="7"><div class="amp-b">
        <div class="rmm-finish-list">
            <?php foreach ([1 => 'Inspection Schedule', 2 => 'Final Inspection', 3 => 'Key Return', 4 => 'Inspection Report (signed)', 5 => 'Damages & Charges', 6 => 'Deposit Settlement'] as $n => $label): $ok = $st['done'][$n]; ?>
                <a href="#step-<?= $n ?>" class="rmm-finish-row <?= $ok ? 'ok' : '' ?>" onclick="event.preventDefault(); rmmSelect(<?= $n ?>); window.scrollTo({top: 0, behavior: 'smooth'});">
                    <i class="bi <?= $ok ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i><span><?= h($label) ?></span><i class="bi bi-chevron-right ms-auto"></i></a>
            <?php endforeach; ?>
        </div>
        <?php if ($isDone): ?>
            <div class="alert alert-success mt-3 mb-0"><i class="bi bi-check-circle"></i> Move-out completed <?= h(date('d M Y H:i', strtotime($mo['completed_at']))) ?>.
                <?php if ($b['status'] === 'checked_out'): ?><button type="button" class="btn btn-sm btn-outline-success ms-2" onclick="this.disabled = true; ampBooking('complete').then(ampReload).catch(e => { this.disabled = false; alert(e.message); })">Mark booking completed</button><?php endif; ?></div>
        <?php else: ?>
            <button class="btn btn-success btn-lg w-100 mt-3" id="ampCompleteOut" <?= $st['ready_to_complete'] && $b['status'] === 'checked_out' ? '' : 'disabled' ?>><i class="bi bi-check-circle"></i> Complete Move-Out</button>
            <div class="form-text text-center">Available when every step is done. The booking is marked completed.</div>
        <?php endif; ?>
    </div></div>
<?php endif; ?>
</div>
<?= amp_sig_modal_html() ?>
<?= $mo ? amp_damage_modal_html() : '' ?>
<?= amp_page_js($bookingId, 'out', amp_guest_name($b)) ?>
<style>
.amp-lockrow input[readonly] { background: #f8f6f1; border-color: transparent; font-weight: 600; color: #333; pointer-events: none; }
.amp-lockrow input[readonly] + .input-group-text { background: #f8f6f1; border-color: transparent; }
.amp-lockrow input[readonly]::placeholder { color: transparent; }
.amp-lockrow .amp-edit-btn { display: none; }
.amp-lockrow.is-locked .amp-edit-btn { display: inline-block; }
.amp-lockrow.is-locked [data-save] { display: none; }
</style>
<script>
// A saved amount is locked; the pencil opens it for editing
function ampUnlock(btn) {
    const row = btn.closest('.amp-lockrow');
    row.classList.remove('is-locked');
    row.querySelectorAll('input[readonly]').forEach(i => i.removeAttribute('readonly'));
    const all = document.getElementById('ampSaveAll');
    if (all && row.closest('#ampChargesForm')) all.hidden = false;
    const first = row.querySelector('input');
    if (first) { first.focus(); first.select && first.select(); }
}
function ampSaveAmounts(btn) {
    const cf = document.getElementById('ampChargesForm');
    const fd = cf ? new FormData(cf) : new FormData();
    document.querySelectorAll('input[data-dcost]').forEach(i => fd.append('dcost[' + i.dataset.dcost + ']', i.value.trim()));
    fd.append('action', 'charges_save'); fd.append('type', 'out');
    if (btn) btn.disabled = true;
    return ampFetch('ajax_move_process.php', fd).then(r => { if (r.success) ampReload(); else { if (btn) btn.disabled = false; alert(r.message); } });
}
(function () {
    // Partial return: shown live while the returned quantities are typed
    const form = document.getElementById('ampReturnForm'), box = document.getElementById('ampPartialBox'), list = document.getElementById('ampPartialList');
    if (form && box) form.addEventListener('input', () => {
        const miss = [];
        form.querySelectorAll('input[name^=returned]').forEach(i => {
            const issued = +i.placeholder, v = i.value === '' ? null : +i.value;
            if (v !== null && v < issued) miss.push((issued - v) + ' × ' + i.closest('tr').querySelector('td').childNodes[0].textContent.trim());
        });
        box.classList.toggle('d-none', !miss.length); list.textContent = miss.join(', ');
    });
    const go = document.getElementById('ampReturnGo');
    if (go) go.addEventListener('click', () => {
        const date = document.getElementById('ampOutDate').value;
        if (!confirm('Confirm the key return and check the guest out on ' + date + '?')) return;
        go.disabled = true;
        const save = form ? (() => { const fd = new FormData(form); fd.append('action', 'keys_return_save'); fd.append('type', 'out'); return ampFetch('ajax_move_process.php', fd); })() : Promise.resolve({ success: true });
        save.then(s => s.success ? ampPost('keys_return_confirm', { move_out_date: date, ack_partial: document.getElementById('ampPartial') && document.getElementById('ampPartial').checked ? 1 : 0 }) : (alert(s.message), { success: false }))
            .then(r => {
                if (!r.success) { go.disabled = false; return; }
                return ampBooking('checkout', { actual_check_out: date }).then(ampReload, e => { alert('Key return saved, but the guest was not checked out: ' + e.message); ampReload(); });
            });
    });
    const done = document.getElementById('ampCompleteOut');
    if (done) done.addEventListener('click', () => {
        if (!confirm('Complete the move-out and mark the booking completed?')) return;
        done.disabled = true;
        ampPost('complete', {}).then(r => {
            if (!r.success) { done.disabled = false; return; }
            return ampBooking('complete').then(ampReload, e => { alert('Move-out completed, but the booking was not marked completed: ' + e.message); ampReload(); });
        });
    });
})();
</script>
<?= re_milestones_js() ?>
<?= re_datepicker_assets() ?>
<?php ars_shell_end(); ?>
