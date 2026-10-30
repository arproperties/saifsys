<?php
/**
 * ARS Move-In for one booking (GET booking_id): Payment -> Inspection
 * -> Key Handover (checks the guest in) -> Inspection Report -> Completed.
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
$mi = amp_record($conn, $arsCompanyId, $bookingId, 'in');
$lastCancelled = !$mi ? amp_record($conn, $arsCompanyId, $bookingId, 'in', true) : null;
$canManage = amp_can_manage($conn);
$money = static fn($v) => number_format((float)$v, 2) . ' AED';
$today = amp_today();

ars_shell_begin([
    'title' => 'Move-In',
    'subtitle' => $b['booking_number'] . ' · ' . amp_guest_name($b),
    'breadcrumbs' => [['label' => 'ARS', 'href' => 'index.php'], ['label' => 'Move-In', 'href' => 'move_report.php?tab=in'], ['label' => $b['booking_number']]],
    'actions_html' => '<a class="no-underline inline-flex items-center gap-1 rounded-ars-md border border-ars-border px-3 py-2 text-ars-sm font-semibold text-ars-text hover:border-ars-ink/40" href="booking_view.php?id=' . $bookingId . '">'
        . ars_ui_icon('external-link', ['class' => 'h-4 w-4']) . ' Open booking</a>',
    'legacy_bootstrap' => true,
]);
echo amp_page_css();
?>
<div class="rm-page">
<?= amp_booking_strip($b) ?>

<?php if (!$mi): ?>
    <?php $block = amp_start_block($conn, $arsCompanyId, $b, 'in'); ?>
    <div class="amp-card"><div class="amp-b">
        <?php if ($lastCancelled): ?>
            <div class="alert alert-secondary py-2"><i class="bi bi-x-octagon"></i> The last move-in was cancelled on <?= h(date('d M Y H:i', strtotime($lastCancelled['cancelled_at']))) ?>: <?= h($lastCancelled['cancel_reason']) ?></div>
        <?php endif; ?>
        <?php if ($block): ?>
            <p class="mb-2"><?= h($block) ?></p>
            <?php if ($b['status'] === 'checked_in'): ?><a class="btn btn-outline-primary btn-sm" href="move_out_view.php?booking_id=<?= $bookingId ?>"><i class="bi bi-box-arrow-right"></i> Go to Move-Out</a><?php endif; ?>
        <?php else: ?>
            <h5 class="mb-1">Start the move-in</h5>
            <p class="text-muted">Payment, inspection, key handover (the guest is checked in) and the signed inspection form.</p>
            <?php $rule = amp_rule($conn, $arsCompanyId, $b); if (!$rule['on']): ?>
            <div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> Short stay: <?= (int)$rule['nights'] ?> night<?= $rule['nights'] == 1 ? '' : 's' ?>, under the <?= (int)$rule['min'] ?> nights that need the Move-In process. Use <strong>Check In</strong> on the booking, or start the process anyway.</div>
            <a class="btn btn-outline-secondary" href="booking_view.php?id=<?= $bookingId ?>"><i class="bi bi-arrow-left"></i> Back to booking (check in)</a>
            <button type="button" class="btn btn-primary" onclick="if(confirm('Start the Move-In process for this short stay?')) ampPost('start', {}, this).then(r => { if (r.success) location.href = 'move_in_view.php?booking_id=<?= $bookingId ?>&ms=0'; })"><i class="bi bi-play-circle"></i> Start anyway</button>
            <?php else: ?>
            <button type="button" class="btn btn-primary" onclick="ampPost('start', {}, this).then(r => { if (r.success) location.href = 'move_in_view.php?booking_id=<?= $bookingId ?>&ms=0'; })"><i class="bi bi-play-circle"></i> Initiate Move-In Process</button>
            <?php endif; ?>
        <?php endif; ?>
    </div></div>
<?php else:
    $st = amp_in_state($conn, $arsCompanyId, $b, $mi);
    $isDone = $mi['status'] === 'completed';
    $keys = amp_keys($conn, $arsCompanyId, $bookingId);
    $ks = amp_keys_summary($keys);
    $pay = $st['payment'];
    $flags = [true, $st['done'][1], $st['done'][2], $st['done'][3], $st['done'][4], $isDone];
    $current = array_search(false, $flags, true);
    $state = static fn(int $n) => $flags[$n] ? 'done' : ($n === $current ? 'current' : 'todo');
    $items = [
        ['label' => 'Process Initiated', 'icon' => 'bi-flag', 'state' => 'done', 'sub' => date('M d', strtotime($mi['created_at']))],
        ['label' => 'Payment', 'icon' => 'bi-cash-coin', 'state' => $state(1), 'sub' => !empty($mi['payment_override']) && !$pay['auto_done'] ? 'Confirmed manually' : ''],
        ['label' => 'Inspection', 'icon' => 'bi-clipboard-check', 'state' => $state(2), 'sub' => ''],
        ['label' => 'Key Handover', 'icon' => 'bi-key', 'state' => $state(3), 'sub' => $ks['issued'] ? $ks['issued'] . ' key(s) / card(s)' : ''],
        ['label' => 'Inspection Report', 'icon' => 'bi-pen', 'state' => $state(4), 'sub' => $st['signed'] ? 'Signed copy uploaded' : ''],
        ['label' => 'Completed', 'icon' => 'bi-house-check', 'state' => $state(5), 'sub' => $isDone ? date('M d', strtotime($mi['completed_at'])) : ''],
    ];
    $heads = [
        ['bi-flag', 'Move-In Process Initiated', 'Booking, guest and stay details.'],
        ['bi-cash-coin', 'Payment', 'Security deposit and the first stay payment, read from the booking.'],
        ['bi-clipboard-check', 'Inspection', 'Check the unit with the guest: checklist, remarks and photos.'],
        ['bi-key', 'Key Handover', 'Keys and access cards for the guest. Handing them over checks the guest in.'],
        ['bi-pen', 'Inspection Report', 'The guest signs the move-in inspection report: on screen (Digital Sign), on paper (upload the scan) or by email.'],
        ['bi-house-check', 'Completed', 'Close the move-in.'],
    ];
    $hints = [1 => 'Payment must be received (or confirmed manually) to continue', 2 => 'Complete the inspection to continue',
        3 => 'Hand over the keys to continue', 4 => 'The guest signs the inspection report to continue'];
    $selected = $current === false ? 5 : (int)$current;
    // ?ms=N opens an earlier step (e.g. Process Initiated right after starting)
    if (isset($_GET['ms']) && ctype_digit((string)$_GET['ms']) && (int)$_GET['ms'] <= $selected) {
        $selected = (int)$_GET['ms'];
    }
    echo re_milestones_html($items, $selected, 'Move-in milestones');
    $reach = re_milestones_reach($items);
    foreach ($heads as $i => [$icon, $title, $text]) {
        echo re_milestone_head($i, count($heads), $icon, $title, $text, $items[$i]['state'], $reach, $hints[$i] ?? 'Finish this step to continue');
    }
    $locked = $isDone;
?>
    <!-- 0 Process initiated -->
    <div class="amp-card" data-ms="0 5"><div class="amp-h"><span><i class="bi bi-info-circle me-1"></i>Summary</span>
        <span><?= re_move_ui_pill($isDone ? 'Completed' : 'In progress', $isDone ? 'success' : 'primary') ?></span></div>
        <div class="amp-b"><div class="amp-facts mb-3">
            <span><span class="k">Initiated</span><?= h(date('d M Y H:i', strtotime($mi['created_at']))) ?></span>
            <span><span class="k">Booking check-in</span><?= h(date('d M Y', strtotime($b['check_in']))) ?></span>
            <span><span class="k">Move-in date</span><?= h(date('d M Y', strtotime($mi['key_handover_date'] ?: $b['check_in']))) ?></span>
            <span><span class="k">Deposit</span><?= $pay['deposit']['required'] ? h($money($pay['deposit']['amount'])) . ' · ' . h(ucwords(str_replace('_', ' ', $pay['deposit']['status']))) : 'None' ?></span>
            <span><span class="k">Stay total</span><?= h($money($pay['first']['total'])) ?> · paid <?= h($money($pay['first']['paid'])) ?></span>
        </div>
        <?php $miDate = (string)($mi['key_handover_date'] ?: $b['check_in']); $miNote = amp_move_in_date_note($b, $miDate); ?>
        <?php if (!$isDone): ?>
            <form class="row g-2 align-items-end mb-2" onsubmit="event.preventDefault(); ampPostReload('move_in_date', {move_in_date: this.move_in_date.value}, this.querySelector('button'));">
                <div class="col-sm-5 col-lg-4"><label class="form-label small fw-semibold mb-1" for="ampMiDate">Move-in date</label>
                    <input type="date" class="form-control form-control-sm" id="ampMiDate" name="move_in_date" required value="<?= h($miDate) ?>"
                           min="<?= h(date('Y-m-d', strtotime($b['check_in'] . ' -30 days'))) ?>" max="<?= h(!empty($mi['keys_handed_over']) ? min((string)$b['check_out'], $today) : $b['check_out']) ?>"></div>
                <div class="col-sm-3 col-lg-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit"><i class="bi bi-save"></i> Save date</button></div>
                <div class="col-12 small text-muted">The day the guest actually moves in and gets the keys. It can differ from the booking check-in (late arrival, or earlier by arrangement); the booking dates and charges stay as booked.</div>
            </form>
        <?php endif; ?>
        <?php if ($miNote !== ''): ?>
            <div class="alert alert-warning small py-2 mb-2"><i class="bi bi-calendar-event"></i> Move-in date is <?= h($miNote) ?>.<?= $miDate < (string)$b['check_in'] ? ' If the guest stays the extra night(s), change the stay dates on the booking.' : '' ?></div>
        <?php endif; ?>
        <?= !$isDone ? amp_void_html('in', $canManage, !empty($mi['keys_handed_over']) ? 'The guest is checked in: the move-in can no longer be cancelled.' : null) : '' ?>
    </div></div>

    <!-- 1 Payment -->
    <div class="amp-card" data-ms="1"><div class="amp-h"><span><i class="bi bi-cash-coin me-1"></i>Payment</span><?= re_move_ui_pill($st['done'][1] ? 'Done' : 'To do', $st['done'][1] ? 'success' : 'primary') ?></div><div class="amp-b">
        <div class="amp-row <?= $pay['deposit']['done'] ? 'ok' : '' ?>"><span class="ic"><i class="bi <?= $pay['deposit']['done'] ? 'bi-check-lg' : 'bi-shield-lock' ?>"></i></span>
            <div class="flex-grow-1"><div class="fw-semibold">Security deposit</div><div class="small text-muted"><?= $pay['deposit']['required'] ? h($money($pay['deposit']['amount'])) . ' · ' . h(ucwords(str_replace('_', ' ', $pay['deposit']['status']))) : 'No security deposit on this booking' ?></div></div>
            <?= re_move_ui_pill($pay['deposit']['done'] ? ($pay['deposit']['required'] ? 'Received' : 'Not required') : 'Not received', $pay['deposit']['done'] ? 'success' : 'warning') ?></div>
        <div class="amp-row <?= $pay['first']['done'] ? 'ok' : '' ?>"><span class="ic"><i class="bi <?= $pay['first']['done'] ? 'bi-check-lg' : 'bi-cash-coin' ?>"></i></span>
            <div class="flex-grow-1"><div class="fw-semibold">First stay payment</div><div class="small text-muted">Paid <?= h($money($pay['first']['paid'])) ?> of <?= h($money($pay['first']['total'])) ?> · balance <?= h($money($pay['first']['balance'])) ?></div></div>
            <?= re_move_ui_pill($pay['first']['done'] ? 'Received' : 'Not received', $pay['first']['done'] ? 'success' : 'warning') ?></div>
        <div class="small mb-2"><a href="booking_view.php?id=<?= $bookingId ?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Record the payment or the deposit on the booking</a></div>
        <?php if (!empty($mi['payment_override'])): ?>
            <?php if (!$pay['deposit']['done']): ?>
                <div class="alert alert-danger small py-2 mb-2"><i class="bi bi-exclamation-octagon-fill"></i> <strong>Security deposit outstanding: <?= h($money($pay['deposit']['amount'])) ?>.</strong> Payment was confirmed manually, so the keys can be handed over without the deposit. Collect it on the booking's Deposit tab.</div>
            <?php endif; ?>
            <div class="alert alert-info small py-2 mb-0"><i class="bi bi-person-check"></i> Confirmed manually <?= h(date('d M Y H:i', strtotime($mi['payment_override_at']))) ?>. Reason: <?= h($mi['payment_override_reason']) ?>
                <?php if ($canManage && !$locked && empty($mi['keys_handed_over'])): ?><button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="ampPostReload('payment_override', {value: 0})">Remove</button><?php endif; ?></div>
        <?php elseif (!$pay['auto_done'] && $canManage && !$locked): ?>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#ampOverride"><i class="bi bi-person-check"></i> Confirm manually</button>
            <form class="collapse mt-2" id="ampOverride" onsubmit="event.preventDefault(); ampPostReload('payment_override', {value: 1, reason: this.reason.value, ack_deposit: this.ack_deposit && this.ack_deposit.checked ? 1 : 0}, this.querySelector('button'));">
                <?php if (!$pay['deposit']['done']): ?>
                    <div class="alert alert-warning small py-2 mb-2"><i class="bi bi-exclamation-triangle-fill"></i> <strong>The security deposit (<?= h($money($pay['deposit']['amount'])) ?>) is not received.</strong> Once the keys are handed over it is much harder to collect, and nothing covers damages at move-out.
                        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="ack_deposit" value="1" id="ampAckDep" required><label class="form-check-label" for="ampAckDep">I confirm the keys may be handed over before the deposit is received.</label></div></div>
                <?php endif; ?>
                <div class="input-group"><input type="text" name="reason" class="form-control" maxlength="500" required placeholder="Reason, e.g. Paid in cash, receipt not entered yet"><button class="btn btn-primary" type="submit">Confirm payment</button></div>
            </form>
        <?php endif; ?>
    </div></div>

    <!-- 2 Inspection -->
    <?= amp_inspection_section($conn, $arsCompanyId, $bookingId, 'in', 2, !$locked, $locked ? 'Move-in completed.' : '') ?>

    <!-- 3 Key handover -->
    <div class="amp-card" data-ms="3"><div class="amp-h"><span><i class="bi bi-key me-1"></i>Keys &amp; Access</span><?= re_move_ui_pill(!empty($mi['keys_handed_over']) ? 'Handed over' : 'To do', !empty($mi['keys_handed_over']) ? 'success' : 'primary') ?></div><div class="amp-b">
        <?php $keysEditable = !$locked && empty($mi['keys_handed_over']); ?>
        <form id="ampKeysForm" onsubmit="event.preventDefault(); const fd = new FormData(this); fd.append('action', 'keys_save'); ampFetch('ajax_move_process.php', fd).then(r => r.success ? ampReload() : alert(r.message));">
            <div class="table-responsive"><table class="table table-sm align-middle mb-2">
                <thead><tr><th>Key / access</th><th style="width:110px">Quantity</th><th>Card / key no.</th><th></th></tr></thead><tbody>
                <?php foreach ($keys as $k): ?>
                    <tr><td class="fw-semibold"><?= h($k['key_label']) ?></td>
                        <td><?php if ($keysEditable): ?><input type="number" min="0" max="99" class="form-control form-control-sm" name="qty[<?= (int)$k['id'] ?>]" value="<?= (int)$k['quantity'] ?>"><?php else: ?><?= (int)$k['quantity'] ?><?php endif; ?></td>
                        <td><?php if ($keysEditable): ?><input type="text" maxlength="255" class="form-control form-control-sm" name="cards[<?= (int)$k['id'] ?>]" value="<?= h($k['card_numbers'] ?? '') ?>" placeholder="optional"><?php else: ?><?= h($k['card_numbers'] ?: '—') ?><?php endif; ?></td>
                        <td class="text-end"><?php if ($keysEditable): ?><button type="button" class="btn btn-link btn-sm text-muted p-0" title="Remove" onclick="if (confirm('Remove this row?')) ampPostReload('keys_remove', {key_id: <?= (int)$k['id'] ?>})"><i class="bi bi-x-lg"></i></button><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <div class="row g-2 align-items-end">
                <div class="col-sm-5"><label class="form-label small fw-semibold mb-1" for="ampKeyDate">Move-in date (key handover)</label>
                    <input type="date" class="form-control form-control-sm" id="ampKeyDate" name="key_handover_date" min="<?= h(date('Y-m-d', strtotime($b['check_in'] . ' -30 days'))) ?>" max="<?= h($b['check_out']) ?>" value="<?= h($mi['key_handover_date'] ?: $b['check_in']) ?>" <?= $keysEditable ? '' : 'disabled' ?>></div>
                <?php if ($keysEditable): ?><div class="col-sm-3"><button class="btn btn-sm btn-outline-primary w-100" type="submit"><i class="bi bi-save"></i> Save</button></div><?php endif; ?>
            </div>
        </form>
        <?php if ($keysEditable): ?>
            <form class="d-flex gap-2 mt-2" onsubmit="event.preventDefault(); ampPostReload('keys_add', {label: this.label.value});">
                <input type="text" name="label" class="form-control form-control-sm" maxlength="100" placeholder="Add a key or card, e.g. Mailbox key" required>
                <button class="btn btn-sm btn-outline-secondary text-nowrap" type="submit"><i class="bi bi-plus"></i> Add</button></form>
        <?php endif; ?>
        <div class="mt-3 pt-3 border-top">
            <?php if (!empty($mi['keys_handed_over'])): ?>
                <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill"></i> Keys &amp; access handed over <?= h(date('d M Y H:i', strtotime($mi['keys_handed_over_at']))) ?></span>
                <?php if ($canManage && !$locked): ?>
                    <button type="button" class="btn btn-sm btn-link p-0 ms-2" onclick="const r = prompt('Undo the key handover? The guest is set back to not checked in, so the keys and the date can be changed; hand the keys over again afterwards.\n\nReason:'); if (r !== null) ampPostReload('keys_undo', {reason: r});"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>
                <?php endif; ?>
                <?php if ($b['status'] === 'confirmed'): ?>
                    <div class="alert alert-warning small py-2 mt-2 mb-0">The guest is not checked in yet. <button type="button" class="btn btn-sm btn-warning ms-1" onclick="this.disabled = true; ampBooking('checkin').then(ampReload).catch(e => { this.disabled = false; alert(e.message); })">Check guest in now</button></div>
                <?php endif; ?>
            <?php elseif (!$locked): ?>
                <?php $preReady = $st['done'][1] && $st['done'][2]; $future = (string)($mi['key_handover_date'] ?: $b['check_in']) > $today; ?>
                <button type="button" class="btn btn-success" id="ampKeysGo" <?= $preReady && !$future ? '' : 'disabled' ?>><i class="bi bi-key-fill"></i> Keys &amp; Access Handed Over</button>
                <span class="small text-muted ms-2"><?= !$preReady ? 'Finish Payment and Inspection first.' : ($future ? 'Available on the move-in date (' . h(date('d M Y', strtotime($mi['key_handover_date'] ?: $b['check_in']))) . ').' : 'The guest is checked in when the keys are handed over.') ?></span>
            <?php endif; ?>
        </div>
    </div></div>

    <!-- 4 Inspection form -->
    <?php $ip = $st['inspection'] ? count(ars_move_inspection_photos($conn, (int)$st['inspection']['id'])) : 0; ?>
    <?= amp_form_card($b, 'in', 4, $st['done'][2], $st['signed'], $mi['form_sent_at'], $mi['form_sent_to'], $ip, amp_superseded_forms($conn, $arsCompanyId, $bookingId, 'in', $mi), $isDone) ?>

    <!-- 5 Completed -->
    <div class="amp-card" data-ms="5"><div class="amp-b">
        <div class="rmm-finish-list">
            <?php foreach ([1 => 'Payment', 2 => 'Inspection', 3 => 'Key Handover', 4 => 'Inspection Report (signed)'] as $n => $label): $ok = $st['done'][$n]; ?>
                <a href="#step-<?= $n ?>" class="rmm-finish-row <?= $ok ? 'ok' : '' ?>" onclick="event.preventDefault(); rmmSelect(<?= $n ?>); window.scrollTo({top: 0, behavior: 'smooth'});">
                    <i class="bi <?= $ok ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i><span><?= h($label) ?></span><i class="bi bi-chevron-right ms-auto"></i></a>
            <?php endforeach; ?>
        </div>
        <?php if ($isDone): ?>
            <div class="alert alert-success mt-3 mb-0"><i class="bi bi-check-circle"></i> Move-in completed <?= h(date('d M Y H:i', strtotime($mi['completed_at']))) ?>.</div>
        <?php else: ?>
            <button class="btn btn-success btn-lg w-100 mt-3" <?= $st['ready_to_complete'] && $b['status'] === 'checked_in' ? '' : 'disabled' ?> onclick="if (confirm('Complete the move-in?')) ampPostReload('complete', {}, this)"><i class="bi bi-check-circle"></i> Complete Move-In</button>
            <div class="form-text text-center">Available when every step is done.</div>
        <?php endif; ?>
    </div></div>
<?php endif; ?>
</div>
<?= amp_sig_modal_html() ?>
<?= amp_page_js($bookingId, 'in', amp_guest_name($b)) ?>
<script>
(function () {
    const go = document.getElementById('ampKeysGo');
    if (!go) return;
    go.addEventListener('click', () => {
        if (!confirm('Hand over the keys and check the guest in?')) return;
        go.disabled = true;
        const kf = new FormData(document.getElementById('ampKeysForm')); kf.append('action', 'keys_save');
        ampFetch('ajax_move_process.php', kf).then(s => s.success ? ampPost('keys_confirm', {}) : (alert(s.message), { success: false })).then(r => {
            if (!r.success) { go.disabled = false; return; }
            return ampBooking('checkin').then(ampReload, e => { alert('Keys saved, but the guest was not checked in: ' + e.message); ampReload(); });
        });
    });
})();
</script>
<?= re_milestones_js() ?>
<?= re_datepicker_assets() ?>
<?php ars_shell_end(); ?>
