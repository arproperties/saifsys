<?php
/**
 * ARS Move-In / Move-Out process actions (move_in_view.php, move_out_view.php).
 * POST booking_id, type (in|out), action, _csrf. JSON reply.
 * The checklist itself is saved through ajax_move_inspection.php; the check-in /
 * check-out / complete of the booking through ajax_booking_actions.php.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json');
ini_set('display_errors', '0');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_move_process.php';

$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();
$userId = current_user_id();
$userId = $userId ? (int)$userId : null;

$reply = static function (bool $ok, string $message = '', array $extra = []): void {
    echo json_encode(['success' => $ok, 'message' => $message, 'error' => $ok ? null : $message] + $extra);
    exit;
};

$bookingId = (int)($_POST['booking_id'] ?? 0);
$type = ($_POST['type'] ?? '') === 'out' ? 'out' : 'in';
$action = (string)($_POST['action'] ?? '');
$booking = amp_booking($conn, $arsCompanyId, $bookingId);
if (!$booking) {
    $reply(false, 'Booking not found.');
}
$rec = amp_record($conn, $arsCompanyId, $bookingId, $type);
$post = static fn(string $k): string => trim((string)($_POST[$k] ?? ''));
$validDate = static function (string $d): bool {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
};

try {
    if ($action === 'start') {
        $r = amp_start($conn, $arsCompanyId, $booking, $type, $userId);
        $r['ok'] ? $reply(true, ($type === 'out' ? 'Move-out' : 'Move-in') . ' process initiated.') : $reply(false, $r['error']);
    }
    if (!$rec) {
        $reply(false, 'Start the ' . ($type === 'out' ? 'move-out' : 'move-in') . ' first.');
    }
    if ($rec['status'] !== 'in_progress') {
        $reply(false, 'This ' . ($type === 'out' ? 'move-out' : 'move-in') . ' is ' . $rec['status'] . '.');
    }
    $recId = (int)$rec['id'];
    $table = $type === 'out' ? 'ars_move_outs' : 'ars_move_ins';
    $upd = static function (string $set, array $params) use ($conn, $table, $recId, $arsCompanyId): void {
        $conn->prepare("UPDATE $table SET $set WHERE id = ? AND company_id = ?")->execute(array_merge($params, [$recId, $arsCompanyId]));
    };

    switch ($action) {
        case 'void':
            $r = amp_void($conn, $arsCompanyId, $booking, $rec, $type, $userId, $post('reason'));
            $r['ok'] ? $reply(true, 'Cancelled.') : $reply(false, $r['error']);

        case 'insp_reopen':
            // Change the checklist again: before the move-in is completed, or the move-out settlement
            if ($type === 'out' && !empty($rec['settlement_completed_at'])) {
                $reply(false, 'The deposit settlement is marked complete. Reopen the settlement first.');
            }
            if ($post('reason') === '') {
                $reply(false, 'Enter the reason for changing the inspection.');
            }
            $conn->prepare("UPDATE ars_move_inspections SET status = 'in_progress', completed_at = NULL, completed_by = NULL WHERE company_id = ? AND booking_id = ? AND move_type = ?")
                ->execute([$arsCompanyId, $bookingId, $type]);
            // A copy signed before this change no longer matches the report: it has to be signed again
            $wasSigned = (bool)amp_signed_forms($conn, $arsCompanyId, $bookingId, $type, $rec);
            if ($wasSigned) {
                $upd('signed_superseded_at = ?', [amp_now()]);
            }
            amp_log($conn, $arsCompanyId, $booking, 'move_' . $type . '_inspection_reopened', ($type === 'out' ? 'Move-out' : 'Move-in') . ' inspection reopened', $post('reason'), $userId);
            $reply(true, 'Inspection reopened for changes.' . ($wasSigned ? ' The signed inspection report no longer counts: once the inspection is completed again, have the guest sign the updated report.' : ''));

        case 'form_send':
            $r = amp_email_form($conn, $arsCompanyId, $booking, $type === 'out' && $post('kind') === 'settlement' ? 'settlement' : $type,
                ['photos' => $post('photos') === '1', 'remarks' => $post('remarks') === '1', 'unsigned' => $post('send_what') === 'unsigned'], $post('email'));
            if (!$r['ok']) {
                $reply(false, $r['error']);
            }
            if ($post('kind') !== 'settlement') {
                $type === 'out'
                    ? $upd('report_sent_at = NOW(), report_sent_to = ?, report_sent_by = ?', [$r['to'], $userId])
                    : $upd('form_sent_at = NOW(), form_sent_to = ?, form_sent_by = ?', [$r['to'], $userId]);
            }
            amp_log($conn, $arsCompanyId, $booking, 'document_sent', ($post('kind') === 'settlement' ? 'Deposit settlement statement' : ($type === 'out' ? 'Move-out report' : 'Move-in form')) . ' emailed', $r['to'], $userId);
            $reply(true, 'Sent to ' . $r['to'] . (!empty($r['signed']) ? ' (signed copy).' : '.'));

        case 'form_sign':
            // The guest signs on screen; the signed PDF becomes the signed copy
            $insp = ars_move_inspection_find($conn, $arsCompanyId, $bookingId, $type);
            if (!$insp || $insp['status'] !== 'completed') {
                $reply(false, 'Complete the inspection first.');
            }
            $sr = amp_sign_form($conn, $arsCompanyId, $booking, $rec, $type, (string)($_POST['signature'] ?? ''), $post('signer_name'),
                ['photos' => $post('photos') === '1', 'remarks' => $post('remarks') === '1'], $userId);
            $sr['ok'] ? $reply(true, 'Signed by ' . $sr['name'] . '. The signed ' . ($type === 'out' ? 'report' : 'form') . ' is saved to the booking documents.') : $reply(false, $sr['error']);

        case 'signed_upload':
            if (empty($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $reply(false, 'Choose the signed file (PDF or photo).');
            }
            $insp = ars_move_inspection_find($conn, $arsCompanyId, $bookingId, $type);
            if (!$insp || $insp['status'] !== 'completed') {
                $reply(false, 'Complete the inspection first.');
            }
            $u = ars_booking_attachment_upload($conn, $arsCompanyId, $bookingId, $_FILES['file'], $userId, $type === 'out' ? 'move_out_report' : 'move_in_form');
            if (empty($u['success'])) {
                $reply(false, (string)$u['error']);
            }
            amp_log($conn, $arsCompanyId, $booking, 'move_' . $type . '_signed', 'Signed ' . ($type === 'out' ? 'move-out report' : 'move-in form') . ' uploaded', '', $userId);
            $reply(true, 'Signed copy saved to the booking documents.');
    }

    if ($type === 'in') {
        $st = amp_in_state($conn, $arsCompanyId, $booking, $rec);
        switch ($action) {
            case 'payment_override':
                if (!amp_can_manage($conn)) {
                    $reply(false, 'Only Owner, Admin or ARS core staff can confirm payment manually.');
                }
                if ($post('value') === '1') {
                    if ($post('reason') === '') {
                        $reply(false, 'Enter the reason for confirming the payment manually.');
                    }
                    if (!$st['payment']['deposit']['done'] && $post('ack_deposit') !== '1') {
                        $reply(false, 'The security deposit is not received. Tick the confirmation that the keys may be released without it.');
                    }
                    $upd('payment_override = 1, payment_override_by = ?, payment_override_at = NOW(), payment_override_reason = ?', [$userId, mb_substr($post('reason'), 0, 500)]);
                    amp_log($conn, $arsCompanyId, $booking, 'move_in_payment_override', 'Move-in payment confirmed manually', $post('reason'), $userId);
                    $reply(true, 'Payment confirmed manually.');
                }
                if (!empty($rec['keys_handed_over'])) {
                    $reply(false, 'The keys are already handed over.');
                }
                $upd('payment_override = 0, payment_override_by = NULL, payment_override_at = NULL, payment_override_reason = NULL', []);
                $reply(true, 'Manual payment confirmation removed.');

            case 'keys_save':
            case 'keys_add':
            case 'keys_remove':
                if (!empty($rec['keys_handed_over'])) {
                    $reply(false, 'The keys are already handed over.');
                }
                if ($action === 'keys_add') {
                    if ($post('label') === '') {
                        $reply(false, 'Enter the key or card, e.g. Mailbox key.');
                    }
                    $conn->prepare("INSERT INTO ars_move_keys (company_id, booking_id, key_label, quantity, display_order) SELECT ?, ?, ?, 1, COALESCE(MAX(display_order), 0) + 1 FROM ars_move_keys WHERE company_id = ? AND booking_id = ?")
                        ->execute([$arsCompanyId, $bookingId, mb_substr($post('label'), 0, 100), $arsCompanyId, $bookingId]);
                    $reply(true, 'Added.');
                }
                if ($action === 'keys_remove') {
                    $conn->prepare("DELETE FROM ars_move_keys WHERE id = ? AND company_id = ? AND booking_id = ?")->execute([(int)$post('key_id'), $arsCompanyId, $bookingId]);
                    $reply(true, 'Removed.');
                }
                $date = $post('key_handover_date');
                if ($date !== '' && ($err = amp_move_in_date_error($booking, $date))) {
                    $reply(false, $err);
                }
                $q = (array)($_POST['qty'] ?? []);
                $cards = (array)($_POST['cards'] ?? []);
                $u = $conn->prepare("UPDATE ars_move_keys SET quantity = ?, card_numbers = ? WHERE id = ? AND company_id = ? AND booking_id = ?");
                foreach ($q as $kid => $qty) {
                    if (!is_numeric($qty) || (int)$qty < 0 || (int)$qty > 99) {
                        $reply(false, 'Enter a quantity from 0 to 99.');
                    }
                    $c = trim((string)($cards[$kid] ?? ''));
                    $u->execute([(int)$qty, $c !== '' ? mb_substr($c, 0, 255) : null, (int)$kid, $arsCompanyId, $bookingId]);
                }
                $upd('key_handover_date = ?', [$date !== '' ? $date : $booking['check_in']]);
                $reply(true, 'Saved.');

            case 'move_in_date':
                // The day the guest actually moves in (keys handed over), when not the booking check-in
                $date = $post('move_in_date');
                if ($err = amp_move_in_date_error($booking, $date)) {
                    $reply(false, $err);
                }
                if (!empty($rec['keys_handed_over']) && $date > amp_today()) {
                    $reply(false, 'The keys are already handed over, so the move-in date cannot be in the future.');
                }
                $upd('key_handover_date = ?', [$date]);
                amp_log($conn, $arsCompanyId, $booking, 'move_in_date', 'Move-in date set to ' . date('d M Y', strtotime($date)), amp_move_in_date_note($booking, $date), $userId);
                $reply(true, 'Move-in date saved.');

            case 'keys_confirm':
                // Keys handed over = the guest moves in: the page then checks the guest in
                foreach ([1 => 'Payment', 2 => 'Inspection'] as $n => $label) {
                    if (!$st['done'][$n]) {
                        $reply(false, 'Finish the ' . $label . ' step first.');
                    }
                }
                if ($booking['status'] !== 'confirmed') {
                    $reply(false, $booking['status'] === 'pending' ? 'Confirm the booking first (booking page), then hand over the keys.' : 'The booking is ' . str_replace('_', ' ', $booking['status']) . '.');
                }
                if ((string)($rec['key_handover_date'] ?: $booking['check_in']) > amp_today()) {
                    $reply(false, 'The move-in date (' . date('d M Y', strtotime($rec['key_handover_date'] ?: $booking['check_in'])) . ') is in the future. Hand the keys over on that day, or change the move-in date.');
                }
                if (!amp_keys_summary(amp_keys($conn, $arsCompanyId, $bookingId))['rows']) {
                    $reply(false, 'Enter at least one key or access card handed over (quantity above 0).');
                }
                $upd('keys_handed_over = 1, keys_handed_over_at = NOW(), keys_handed_over_by = ?', [$userId]);
                amp_log($conn, $arsCompanyId, $booking, 'move_in_keys', 'Keys & access handed over', '', $userId);
                $reply(true, 'Keys & access handed over.', ['checkin' => true]);

            case 'keys_undo':
                // Undo the key handover, e.g. to correct the keys: the check-in it did is undone too
                if (!amp_can_manage($conn)) {
                    $reply(false, 'Only Owner, Admin or ARS core staff can undo the key handover.');
                }
                if (empty($rec['keys_handed_over'])) {
                    $reply(false, 'The keys are not handed over.');
                }
                if ($post('reason') === '') {
                    $reply(false, 'Enter the reason for undoing the key handover.');
                }
                if (amp_record($conn, $arsCompanyId, $bookingId, 'out')) {
                    $reply(false, 'A move-out is already started for this booking. Cancel the move-out first.');
                }
                if (!in_array($booking['status'], ['checked_in', 'confirmed'], true)) {
                    $reply(false, 'The booking is ' . str_replace('_', ' ', $booking['status']) . ', so the key handover cannot be undone.');
                }
                $conn->beginTransaction();
                $upd('keys_handed_over = 0, keys_handed_over_at = NULL, keys_handed_over_by = NULL', []);
                if ($booking['status'] === 'checked_in') {
                    $conn->prepare("UPDATE ars_bookings SET status = 'confirmed', updated_at = NOW() WHERE id = ? AND company_id = ? AND status = 'checked_in'")
                        ->execute([$bookingId, $arsCompanyId]);
                }
                $conn->commit();
                amp_log($conn, $arsCompanyId, $booking, 'check_in_undone', 'Key handover undone; check-in reversed', $post('reason'), $userId);
                $reply(true, 'Key handover undone. The guest is set back to not checked in; hand the keys over again to check in.');

            case 'complete':
                if (!$st['ready_to_complete']) {
                    $reply(false, 'Finish every step first.');
                }
                if ($booking['status'] !== 'checked_in') {
                    $reply(false, 'The guest is not checked in yet (Key Handover).');
                }
                $upd("status = 'completed', completed_at = NOW(), completed_by = ?", [$userId]);
                amp_log($conn, $arsCompanyId, $booking, 'move_in_completed', 'Move-in completed', '', $userId);
                $reply(true, 'Move-in completed.');
        }
    } else {
        $settled = !empty($rec['settlement_completed_at']);
        $st = amp_out_state($conn, $arsCompanyId, $booking, $rec);
        switch ($action) {
            case 'schedule_save':
                $d = $post('inspection_date');
                $t = $post('inspection_time');
                if (!$validDate($d)) {
                    $reply(false, 'Choose the inspection date.');
                }
                if ($t !== '' && !preg_match('/^\d{2}:\d{2}$/', $t)) {
                    $reply(false, 'Choose a valid time.');
                }
                $upd('inspection_date = ?, inspection_time = ?, inspection_note = ?', [$d, $t !== '' ? $t : null, $post('note') !== '' ? mb_substr($post('note'), 0, 255) : null]);
                $reply(true, 'Inspection scheduled.');

            case 'keys_return_save':
                if (!empty($rec['keys_returned'])) {
                    $reply(false, 'The key return is already confirmed.');
                }
                $ret = (array)($_POST['returned'] ?? []);
                $notes = (array)($_POST['note'] ?? []);
                $u = $conn->prepare("UPDATE ars_move_keys SET returned_qty = ?, return_note = ? WHERE id = ? AND company_id = ? AND booking_id = ?");
                foreach ($ret as $kid => $v) {
                    $v = trim((string)$v);
                    if ($v !== '' && (!is_numeric($v) || (int)$v < 0 || (int)$v > 99)) {
                        $reply(false, 'Enter a returned quantity from 0 to 99.');
                    }
                    $n = trim((string)($notes[$kid] ?? ''));
                    $u->execute([$v === '' ? null : (int)$v, $n !== '' ? mb_substr($n, 0, 255) : null, (int)$kid, $arsCompanyId, $bookingId]);
                    // Everything returned now: a damage charged for the missing key is removed
                    $kd = $conn->prepare("SELECT damage_id, quantity FROM ars_move_keys WHERE id = ? AND booking_id = ?");
                    $kd->execute([(int)$kid, $bookingId]);
                    $krow = $kd->fetch(PDO::FETCH_ASSOC);
                    if ($krow && !empty($krow['damage_id']) && $v !== '' && (int)$v >= (int)$krow['quantity'] && !$settled) {
                        $conn->prepare("DELETE FROM ars_move_out_damages WHERE id = ? AND move_out_id = ?")->execute([(int)$krow['damage_id'], $recId]);
                        $conn->prepare("UPDATE ars_move_keys SET damage_id = NULL WHERE id = ?")->execute([(int)$kid]);
                    }
                }
                $reply(true, 'Saved.');

            case 'keys_return_confirm':
                // Keys back = the guest has left: the page then checks the guest out on the move-out date
                $d = $post('move_out_date');
                if (!$validDate($d) || $d > amp_today() || $d < (string)$booking['check_in']) {
                    $reply(false, 'The move-out date must be between check-in (' . $booking['check_in'] . ') and today.');
                }
                $ks = amp_keys_summary(amp_keys($conn, $arsCompanyId, $bookingId));
                if ($ks['pending']) {
                    $reply(false, 'Enter the returned quantity for every key and card (0 if not returned).');
                }
                if ($ks['missing'] && $post('ack_partial') !== '1') {
                    $reply(false, 'Not everything was returned (' . implode(', ', $ks['missing']) . '). Tick "Acknowledge partial return" to continue.');
                }
                if ($booking['status'] !== 'checked_in') {
                    $reply(false, 'The booking is ' . str_replace('_', ' ', $booking['status']) . '.');
                }
                $upd('keys_returned = 1, keys_returned_at = NOW(), keys_returned_by = ?, keys_partial_ack = ?, move_out_date = ?', [$userId, $ks['missing'] ? 1 : 0, $d]);
                amp_log($conn, $arsCompanyId, $booking, 'move_out_keys', 'Keys & access returned', $ks['missing'] ? 'Outstanding: ' . implode(', ', $ks['missing']) : '', $userId);
                $reply(true, 'Key return confirmed.', ['checkout' => true, 'move_out_date' => $d]);

            case 'keys_return_undo':
                // Undo the key return, e.g. to correct the returned keys: the check-out it did is undone too
                if (!amp_can_manage($conn)) {
                    $reply(false, 'Only Owner, Admin or ARS core staff can undo the key return.');
                }
                if (empty($rec['keys_returned'])) {
                    $reply(false, 'The key return is not confirmed.');
                }
                if ($post('reason') === '') {
                    $reply(false, 'Enter the reason for undoing the key return.');
                }
                if (!in_array($booking['status'], ['checked_out', 'checked_in'], true)) {
                    $reply(false, 'The booking is ' . str_replace('_', ' ', $booking['status']) . ', so the key return cannot be undone.');
                }
                $conn->beginTransaction();
                $upd('keys_returned = 0, keys_returned_at = NULL, keys_returned_by = NULL, keys_partial_ack = 0', []);
                if ($booking['status'] === 'checked_out') {
                    $conn->prepare("UPDATE ars_bookings SET status = 'checked_in', actual_check_out = NULL, is_early_checkout = 0, updated_at = NOW() WHERE id = ? AND company_id = ? AND status = 'checked_out'")
                        ->execute([$bookingId, $arsCompanyId]);
                }
                $conn->commit();
                amp_log($conn, $arsCompanyId, $booking, 'check_out_undone', 'Key return undone; check-out reversed', $post('reason'), $userId);
                $reply(true, 'Key return undone. The guest is set back to checked in; confirm the key return again to check out. A cleaning work order made at check-out is not removed.');

            case 'key_damage':
            case 'key_damage_undo':
                // A key / card not returned: charged as a damage, costed later in Damages & Charges
                if ($settled) {
                    $reply(false, 'The deposit settlement is marked complete. Reopen the settlement first.');
                }
                $kq = $conn->prepare("SELECT * FROM ars_move_keys WHERE id = ? AND company_id = ? AND booking_id = ?");
                $kq->execute([(int)$post('key_id'), $arsCompanyId, $bookingId]);
                $key = $kq->fetch(PDO::FETCH_ASSOC);
                if (!$key) {
                    $reply(false, 'Key not found.');
                }
                if ($action === 'key_damage_undo') {
                    if (!empty($key['damage_id'])) {
                        $conn->prepare("DELETE FROM ars_move_out_damages WHERE id = ? AND move_out_id = ?")->execute([(int)$key['damage_id'], $recId]);
                    }
                    $conn->prepare("UPDATE ars_move_keys SET damage_id = NULL WHERE id = ?")->execute([(int)$key['id']]);
                    $reply(true, 'No longer charged as a damage.');
                }
                if (!empty($key['damage_id'])) {
                    $reply(false, 'This key is already charged as a damage.');
                }
                $missing = $key['returned_qty'] === null ? 0 : (int)$key['quantity'] - (int)$key['returned_qty'];
                if ($missing <= 0) {
                    $reply(false, $key['returned_qty'] === null ? 'Enter and save the returned quantity first.' : 'Nothing is missing for this key.');
                }
                $conn->beginTransaction();
                $conn->prepare("INSERT INTO ars_move_out_damages (company_id, move_out_id, area, description, cost, created_by) VALUES (?, ?, 'Keys & Access', ?, NULL, ?)")
                    ->execute([$arsCompanyId, $recId, mb_substr($missing . ' × ' . $key['key_label'] . ' not returned' . ($key['card_numbers'] ? ' (' . $key['card_numbers'] . ')' : '') . ($key['return_note'] ? ' - ' . $key['return_note'] : ''), 0, 500), $userId]);
                $conn->prepare("UPDATE ars_move_keys SET damage_id = ? WHERE id = ?")->execute([(int)$conn->lastInsertId(), (int)$key['id']]);
                $conn->commit();
                $reply(true, 'Charged as a damage. Enter the replacement cost in Damages & Charges.');

            case 'item_damage':
            case 'item_damage_undo':
                // A final-inspection checklist item found damaged: recorded in Damages, the item marked Damaged
                if ($settled) {
                    $reply(false, 'The deposit settlement is marked complete. Reopen the settlement first.');
                }
                $insp = ars_move_inspection_find($conn, $arsCompanyId, $bookingId, 'out');
                if (!$insp || $insp['status'] !== 'in_progress') {
                    $reply(false, 'The final inspection is completed. Use Edit inspection to change it, or add the damage in Damages & Charges.');
                }
                $it = $conn->prepare("SELECT * FROM ars_move_inspection_items WHERE id = ? AND inspection_id = ?");
                $it->execute([(int)$post('item_id'), (int)$insp['id']]);
                $item = $it->fetch(PDO::FETCH_ASSOC);
                if (!$item) {
                    $reply(false, 'Checklist item not found.');
                }
                if ($action === 'item_damage_undo') {
                    if (!empty($item['damage_id'])) {
                        $conn->prepare("DELETE FROM ars_move_out_damages WHERE id = ? AND move_out_id = ?")->execute([(int)$item['damage_id'], $recId]);
                    }
                    $conn->prepare("UPDATE ars_move_inspection_items SET is_damaged = 0, damage_id = NULL, is_completed = 0, completed_at = NULL, completed_by = NULL WHERE id = ?")
                        ->execute([(int)$item['id']]);
                    $reply(true, 'Damage removed; the item is open again.');
                }
                if (!empty($item['is_damaged'])) {
                    $reply(false, 'A damage is already recorded for this item.');
                }
                // The repair cost is entered later, in Damages & Charges
                if ($post('description') === '') {
                    $reply(false, 'Describe what is damaged.');
                }
                $desc = mb_substr($post('description'), 0, 500);
                $conn->beginTransaction();
                $conn->prepare("INSERT INTO ars_move_out_damages (company_id, move_out_id, area, description, cost, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$arsCompanyId, $recId, mb_substr($post('area') !== '' ? $post('area') : (string)($item['section_name'] ?? ''), 0, 100) ?: null, $desc, null, $userId]);
                $did = (int)$conn->lastInsertId();
                $conn->prepare("UPDATE ars_move_inspection_items SET is_damaged = 1, damage_id = ?, is_completed = 1, completed_at = ?, completed_by = ? WHERE id = ?")
                    ->execute([$did, amp_now(), $userId, (int)$item['id']]);
                $conn->commit();
                $reply(true, 'Damage recorded. Enter its repair cost in Damages & Charges.');

            case 'damage_add':
            case 'damage_cost':
            case 'damage_delete':
            case 'charges_save':
            case 'charges_add':
            case 'charges_remove':
            case 'charges_restore':
                if ($settled) {
                    $reply(false, 'The deposit settlement is marked complete. Reopen the settlement first.');
                }
                $cost = static function (string $v) use ($reply): ?float {
                    if ($v === '') {
                        return null;
                    }
                    if (!is_numeric($v) || (float)$v < 0) {
                        $reply(false, 'Enter a valid amount (0 or more).');
                    }
                    return round((float)$v, 2);
                };
                if ($action === 'damage_add') {
                    if ($post('description') === '') {
                        $reply(false, 'Describe the damage.');
                    }
                    $conn->prepare("INSERT INTO ars_move_out_damages (company_id, move_out_id, area, description, cost, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$arsCompanyId, $recId, $post('area') !== '' ? mb_substr($post('area'), 0, 100) : null, mb_substr($post('description'), 0, 500), $cost($post('cost')), $userId]);
                    $reply(true, 'Damage added.');
                }
                if ($action === 'damage_cost') {
                    $c = $cost($post('cost'));
                    if ($c === null) {
                        $reply(false, 'Enter the repair cost (0 if no charge).');
                    }
                    $conn->prepare("UPDATE ars_move_out_damages SET cost = ? WHERE id = ? AND move_out_id = ?")->execute([$c, (int)$post('damage_id'), $recId]);
                    $reply(true, 'Saved.');
                }
                if ($action === 'damage_delete') {
                    $conn->prepare("DELETE FROM ars_move_out_damages WHERE id = ? AND move_out_id = ?")->execute([(int)$post('damage_id'), $recId]);
                    // A damage recorded from a checklist item: the item is no longer Damaged
                    $conn->prepare("UPDATE ars_move_inspection_items SET is_damaged = 0, damage_id = NULL WHERE damage_id = ?")->execute([(int)$post('damage_id')]);
                    $conn->prepare("UPDATE ars_move_keys SET damage_id = NULL WHERE damage_id = ? AND booking_id = ?")->execute([(int)$post('damage_id'), $bookingId]);
                    $reply(true, 'Removed.');
                }
                if ($action === 'charges_add') {
                    if ($post('name') === '') {
                        $reply(false, 'Enter the charge, e.g. Extra cleaning.');
                    }
                    $conn->prepare("INSERT INTO ars_move_out_charges (company_id, move_out_id, charge_name, display_order) SELECT ?, ?, ?, COALESCE(MAX(display_order), 0) + 1 FROM ars_move_out_charges WHERE move_out_id = ?")
                        ->execute([$arsCompanyId, $recId, mb_substr($post('name'), 0, 100), $recId]);
                    $reply(true, 'Charge added. Enter its amount.');
                }
                if ($action === 'charges_remove' || $action === 'charges_restore') {
                    $conn->prepare("UPDATE ars_move_out_charges SET is_removed = ? WHERE id = ? AND move_out_id = ?")->execute([$action === 'charges_remove' ? 1 : 0, (int)$post('charge_id'), $recId]);
                    $reply(true, $action === 'charges_remove' ? 'Removed from this move-out.' : 'Restored.');
                }
                $amounts = (array)($_POST['amount'] ?? []);
                $notes = (array)($_POST['note'] ?? []);
                $u = $conn->prepare("UPDATE ars_move_out_charges SET amount = ?, note = ? WHERE id = ? AND move_out_id = ?");
                foreach ($amounts as $cid => $v) {
                    $n = trim((string)($notes[$cid] ?? ''));
                    $u->execute([$cost(trim((string)$v)), $n !== '' ? mb_substr($n, 0, 255) : null, (int)$cid, $recId]);
                }
                // Repair costs of the damages, saved with the charges (an empty box keeps the damage uncosted)
                $du = $conn->prepare("UPDATE ars_move_out_damages SET cost = ? WHERE id = ? AND move_out_id = ?");
                foreach ((array)($_POST['dcost'] ?? []) as $did => $v) {
                    $du->execute([$cost(trim((string)$v)), (int)$did, $recId]);
                }
                $reply(true, 'Damages and charges saved.');

            case 'settlement_complete':
                if ($settled) {
                    $reply(false, 'Already marked complete.');
                }
                if (!$st['done'][2]) {
                    $reply(false, 'Complete the final inspection first.');
                }
                if ($st['pending']) {
                    $reply(false, 'Enter an amount for every damage and charge first (0 if none).');
                }
                $upd('settlement_completed_at = NOW(), settlement_completed_by = ?, settlement_note = ?', [$userId, $post('note') !== '' ? mb_substr($post('note'), 0, 500) : null]);
                amp_log($conn, $arsCompanyId, $booking, 'move_out_settlement', 'Deposit settlement marked complete', $post('note'), $userId);
                $reply(true, 'Deposit settlement marked complete.');

            case 'settlement_reopen':
                if (!amp_can_manage($conn)) {
                    $reply(false, 'Only Owner, Admin or ARS core staff can reopen the settlement.');
                }
                if (!$settled) {
                    $reply(false, 'The settlement is not marked complete.');
                }
                if ($post('note') === '') {
                    $reply(false, 'Enter the reason for reopening.');
                }
                $upd('settlement_completed_at = NULL, settlement_completed_by = NULL, settlement_note = ?', [mb_substr('Reopened: ' . $post('note'), 0, 500)]);
                amp_log($conn, $arsCompanyId, $booking, 'move_out_settlement_reopened', 'Deposit settlement reopened', $post('note'), $userId);
                $reply(true, 'Settlement reopened.');

            case 'complete':
                if (!$st['ready_to_complete']) {
                    $reply(false, 'Finish every step first.');
                }
                if ($booking['status'] !== 'checked_out') {
                    $reply(false, 'The guest is not checked out yet (Key Return).');
                }
                $upd("status = 'completed', completed_at = NOW(), completed_by = ?", [$userId]);
                amp_log($conn, $arsCompanyId, $booking, 'move_out_completed', 'Move-out completed', '', $userId);
                $reply(true, 'Move-out completed.', ['complete_booking' => true]);
        }
    }
    $reply(false, 'Unknown action.');
} catch (Throwable $e) {
    error_log('ARS move process ' . $action . ': ' . $e->getMessage());
    $reply(false, 'Could not save: ' . $e->getMessage());
}
