<?php
/**
 * Real Estate Module - Move-Out View & Workflow
 * Complete move-out process with inspection, damage assessment, and deposit calculation
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/security_deposit_helper.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';
require_once __DIR__ . '/includes/move_out_inspection_helper.php';
require_once __DIR__ . '/includes/move_out_keys_helper.php';
require_once __DIR__ . '/includes/re_digital_signature.php';
require_once __DIR__ . '/includes/move_out_charges_helper.php';
require_once __DIR__ . '/includes/move_out_recovery_helper.php';
require_once __DIR__ . '/includes/move_out_settlement_helper.php';
require_once __DIR__ . '/includes/move_out_appointment_helper.php';
require_once __DIR__ . '/includes/move_void_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

$moveOutId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$moveOutId) {
    header('Location: move_out.php');
    exit;
}

// Get move-out details
$moveOut = $conn->prepare("
    SELECT 
        mo.*,
        mon.notice_date,
        mon.intended_move_out_date,
        mon.notice_type,
        mon.notice_reason,
        l.lease_number,
        l.status as lease_status,
        l.start_date as lease_start,
        l.end_date as lease_end,
        l.monthly_rent,
        l.security_deposit,
        u.unit_number,
        u.unit_type,
        u.area_sqm,
        b.name as building_name,
        b.address as building_address,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        t.id_number,
        u2.username as created_by_name,
        u3.username as approved_by_name
    FROM re_move_outs mo
    JOIN re_leases l ON l.id = mo.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_move_out_notices mon ON mon.id = mo.move_out_notice_id
    LEFT JOIN user u2 ON u2.id = mo.created_by
    LEFT JOIN user u3 ON u3.id = mo.approved_by
    WHERE mo.id = ? AND mo.company_id = ?
");
$moveOut->execute([$moveOutId, $currentCompanyId]);
$moveOut = $moveOut->fetch(PDO::FETCH_ASSOC);

if (!$moveOut) {
    header('Location: move_out.php');
    exit;
}

// Inspection checklist: copied from the inspection template while the
// inspection is still open (move-outs inspected before it existed have none)
$moiReady = rmoi_ready($conn);
$moiLocked = !empty($moveOut['inspection_completed']) || in_array($moveOut['status'], ['completed', 'cancelled'], true);
if ($moiReady && !$moiLocked) {
    try {
        rmoi_create_for_move_out($conn, (int)$currentCompanyId, $moveOutId, $currentUserId ? (int)$currentUserId : null);
        // "Photos taken" cannot stay OK without a photo (e.g. photos removed while editing)
        rmoi_sync_photo_items($conn, (int)$currentCompanyId, $moveOutId);
    } catch (Throwable $e) {
        error_log('Move-out checklist copy failed (move-out ' . $moveOutId . '): ' . $e->getMessage());
    }
}

// Key return: the keys handed over at move-in, copied once. Move-outs whose
// keys were already ticked as returned (old checkbox) keep that.
$mokReady = rmok_ready($conn);
$mokClosed = in_array($moveOut['status'], ['completed', 'cancelled'], true);
if ($mokReady && !$mokClosed && empty($moveOut['keys_returned'])) {
    try {
        rmok_create_for_move_out($conn, (int)$currentCompanyId, $moveOutId, (int)$moveOut['lease_id'], $currentUserId ? (int)$currentUserId : null);
    } catch (Throwable $e) {
        error_log('Move-out key list copy failed (move-out ' . $moveOutId . '): ' . $e->getMessage());
    }
}
$mokKeys = $mokReady ? rmok_keys($conn, $moveOutId) : [];

// Standard move-out charges: copied once, and only while the deposit
// settlement is still open (a draft or not prepared yet)
$mocReady = rmoc_ready($conn);
// Locked once the deposit settlement is marked complete
$settlementDone = rmst_done($moveOut);
$mocLocked = in_array($moveOut['status'], ['completed', 'cancelled'], true) || $settlementDone;
if ($mocReady && !$mocLocked) {
    try {
        rmoc_create_for_move_out($conn, (int)$currentCompanyId, $moveOutId, $currentUserId ? (int)$currentUserId : null);
    } catch (Throwable $e) {
        error_log('Move-out charges copy failed (move-out ' . $moveOutId . '): ' . $e->getMessage());
    }
}
$mocCharges = $mocReady ? rmoc_charges($conn, $moveOutId) : [];
$mocRemoved = $mocReady ? rmoc_charges($conn, $moveOutId, true) : [];
$mocSum = rmoc_summary($mocCharges);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    csrf_verify();
    
    $action = $_POST['action'];
    // Every damage needs its repair cost before the settlement is prepared or approved
    $uncostedDamagesMsg = static function () use ($conn, $moveOutId, $currentCompanyId): ?string {
        $st = $conn->prepare("SELECT COUNT(*) FROM re_move_out_damages WHERE move_out_id = ? AND company_id = ? AND repair_cost IS NULL");
        $st->execute([$moveOutId, $currentCompanyId]);
        $n = (int)$st->fetchColumn();
        if ($n) {
            return $n . ' damage(s) have no repair cost yet. Add the costs in Damages & Report first.';
        }
        $pending = rmoc_summary(rmoc_charges($conn, $moveOutId))['pending'];
        return $pending ? $pending . ' standard move-out charge(s) have no amount yet. Enter them in Standard Move-Out Charges (0 if not needed).' : null;
    };
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($action) {
            case 'appt_save':
            case 'appt_missed':
            case 'appt_email':
            case 'appt_email_preview':
            case 'appt_tpl_save':
            case 'appt_tpl_reset':
                if (!rmoa_ready($conn)) { $response = ['success' => false, 'message' => 'Run migrations/re_move_out_appointment.sql first.']; break; }
                if (!empty($moveOut['inspection_completed']) || in_array($moveOut['status'], ['completed', 'cancelled'], true)) {
                    $response = ['success' => false, 'message' => 'The final inspection is already done.'];
                    break;
                }
                $uid = $currentUserId ? (int)$currentUserId : null;
                if ($action === 'appt_email_preview') {
                    // The email as it would go out, for the review pop-up
                    $type = ($_POST['type'] ?? '') === 'confirmation' ? 'confirmation' : 'reminder';
                    $pm = rmoa_load($conn, (int)$currentCompanyId, $moveOutId);
                    if (!$pm || empty($pm['inspection_scheduled_date'])) { $response = ['success' => false, 'message' => 'Schedule the inspection first.']; break; }
                    $pTpl = rmoa_template_get($conn, (int)$currentCompanyId, $type);
                    [$pSubject, $pHtml] = rmoa_email($pm, $type, $pTpl);
                    $response = ['success' => true, 'to' => (string)($pm['tenant_email'] ?? ''), 'subject' => $pSubject, 'html' => $pHtml,
                        'attachment' => $type === 'confirmation', 'custom' => (bool)$pTpl, 'can_template' => rmoa_template_ready($conn)];
                    break;
                }
                if ($action === 'appt_tpl_save' || $action === 'appt_tpl_reset') {
                    // Saved for every later email of this type (all move-outs of the company)
                    if (!rmoa_template_ready($conn)) { $response = ['success' => false, 'message' => 'Run migrations/re_move_out_appointment.sql first.']; break; }
                    $type = ($_POST['type'] ?? '') === 'confirmation' ? 'confirmation' : 'reminder';
                    if ($action === 'appt_tpl_reset') {
                        rmoa_template_delete($conn, (int)$currentCompanyId, $type);
                        $response = ['success' => true, 'message' => 'The default wording is back for this email.', 'reload' => false];
                        break;
                    }
                    $pm = rmoa_load($conn, (int)$currentCompanyId, $moveOutId);
                    try {
                        rmoa_template_save($conn, (int)$currentCompanyId, $type, $pm, (string)($_POST['subject'] ?? ''), (string)($_POST['html'] ?? ''), $uid);
                        $response = ['success' => true, 'message' => 'Template saved. The tenant name, date, time, unit, address, lease and note are filled in for each email.', 'reload' => false];
                    } catch (Throwable $e) {
                        $response = ['success' => false, 'message' => $e->getMessage()];
                    }
                    break;
                }
                if ($action === 'appt_email') {
                    $type = ($_POST['type'] ?? '') === 'confirmation' ? 'confirmation' : 'reminder';
                    // Subject and message as reviewed / edited in the pop-up
                    $res = rmoa_send($conn, (int)$currentCompanyId, $moveOutId, $type, (string)($_POST['to'] ?? ''), $uid,
                        ['subject' => (string)($_POST['subject'] ?? ''), 'html' => (string)($_POST['html'] ?? '')]);
                    $response = $res['ok'] ? ['success' => true, 'message' => RMOA_EMAIL_TYPES[$type] . ' sent to ' . trim((string)$_POST['to']) . '.']
                        : ['success' => false, 'message' => 'The email could not be sent: ' . $res['error']];
                    break;
                }
                if ($action === 'appt_missed') {
                    if (empty($moveOut['inspection_scheduled_date'])) { $response = ['success' => false, 'message' => 'No appointment to mark as missed.']; break; }
                    $conn->prepare("UPDATE re_move_outs SET inspection_appt_status = 'missed' WHERE id = ? AND company_id = ?")->execute([$moveOutId, $currentCompanyId]);
                    rmoa_log($conn, (int)$currentCompanyId, $moveOutId, 'missed', $moveOut, trim((string)($_POST['reason'] ?? '')), $uid);
                    $response = ['success' => true, 'message' => 'Marked as missed. Reschedule the inspection.'];
                    break;
                }
                // Book or reschedule: on the move-out day, or another date
                $mode = ($_POST['mode'] ?? '') === 'scheduled' ? 'scheduled' : 'move_out_day';
                $date = $mode === 'move_out_day' ? (string)$moveOut['actual_move_out_date'] : trim((string)($_POST['inspection_date'] ?? ''));
                $d = DateTime::createFromFormat('Y-m-d', $date);
                if (!$d || $d->format('Y-m-d') !== $date) { $response = ['success' => false, 'message' => 'Choose the inspection date.']; break; }
                $tf = trim((string)($_POST['time_from'] ?? ''));
                $tt = trim((string)($_POST['time_to'] ?? ''));
                $validTime = static fn(string $t) => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
                if ($tf === '' || $tt === '' || !$validTime($tf) || !$validTime($tt)) { $response = ['success' => false, 'message' => 'Enter the time window, e.g. 10:00 to 12:00.']; break; }
                if ($tt <= $tf) { $response = ['success' => false, 'message' => 'The end of the time window must be after its start.']; break; }
                $isReschedule = !empty($moveOut['inspection_scheduled_date']);
                $reason = trim((string)($_POST['reason'] ?? ''));
                if ($isReschedule && $reason === '') { $response = ['success' => false, 'message' => 'Enter the reason for rescheduling.']; break; }
                $note = trim((string)($_POST['access_note'] ?? ''));
                $conn->prepare("
                    UPDATE re_move_outs
                    SET inspection_scheduled_date = ?, inspection_mode = ?, inspection_time_from = ?, inspection_time_to = ?,
                        inspection_access_note = ?, inspection_auto_remind = ?, inspection_appt_status = ?,
                        status = IF(status = 'pending', 'inspection_scheduled', status)
                    WHERE id = ? AND company_id = ?
                ")->execute([$date, $mode, $tf . ':00', $tt . ':00', $note !== '' ? mb_substr($note, 0, 255) : null, !empty($_POST['auto_remind']) ? 1 : 0,
                    $isReschedule ? 'rescheduled' : 'scheduled', $moveOutId, $currentCompanyId]);
                $fresh = rmoa_load($conn, (int)$currentCompanyId, $moveOutId);
                rmoa_log($conn, (int)$currentCompanyId, $moveOutId, $isReschedule ? 'rescheduled' : 'scheduled', $fresh, $isReschedule ? $reason : ($mode === 'move_out_day' ? 'On the move-out day' : null), $uid);
                $msg = ($isReschedule ? 'Inspection rescheduled to ' : 'Inspection booked for ') . rmoa_when_text($fresh) . '.';
                if (!empty($_POST['send_email'])) {
                    $res = rmoa_send($conn, (int)$currentCompanyId, $moveOutId, 'confirmation', (string)($_POST['to'] ?? ''), $uid);
                    $msg .= $res['ok'] ? ' Confirmation emailed to ' . trim((string)$_POST['to']) . '.' : ' The email could not be sent: ' . $res['error'];
                }
                $response = ['success' => true, 'message' => $msg];
                break;

            case 'schedule_inspection':
                if (!empty($moveOut['inspection_completed']) || in_array($moveOut['status'], ['completed', 'cancelled', 'deposit_processing'], true)) {
                    $response = ['success' => false, 'message' => 'The final inspection is already done.'];
                    break;
                }
                $inspectionDate = $_POST['inspection_date'] ?? '';
                if ($inspectionDate) {
                    $stmt = $conn->prepare("
                        UPDATE re_move_outs
                        SET inspection_scheduled_date = ?,
                            status = 'inspection_scheduled'
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$inspectionDate, $moveOutId, $currentCompanyId]);
                    $response = ['success' => true, 'message' => 'Inspection scheduled'];
                }
                break;
                
            case 'moi_status':
            case 'moi_section_ok':
            case 'moi_remark':
            case 'moi_add_item':
                if (!$moiReady) { $response = ['success' => false, 'message' => 'Run migrations/re_move_out_inspection.sql first.']; break; }
                if ($moiLocked) { $response = ['success' => false, 'message' => 'The final inspection is completed; the checklist can no longer be changed.']; break; }
                $uid = $currentUserId ? (int)$currentUserId : null;
                if ($action === 'moi_status') {
                    $err = rmoi_set_status($conn, (int)$currentCompanyId, $moveOutId, (int)($_POST['item_id'] ?? 0), (string)($_POST['status'] ?? ''), $uid);
                    $response = $err ? ['success' => false, 'message' => $err] : ['success' => true, 'message' => 'Saved'];
                } elseif ($action === 'moi_section_ok') {
                    // Mark every open item of the section OK (Damaged / N/A items are kept)
                    // "Photos taken" is left open until a photo is uploaded
                    $open = $conn->prepare("SELECT id, item_text FROM re_move_out_inspection_items WHERE move_out_id = ? AND company_id = ? AND section_name = ? AND status = 'open'");
                    $open->execute([$moveOutId, $currentCompanyId, (string)($_POST['section_name'] ?? '')]);
                    $upd = $conn->prepare("UPDATE re_move_out_inspection_items SET status = 'ok', checked_by = ?, checked_at = NOW() WHERE id = ?");
                    $hasPhotos = rmoi_photo_count($conn, $moveOutId) > 0;
                    $changed = 0;
                    $skippedPhotos = false;
                    foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        if (!$hasPhotos && rms_item_kind((string)$row['item_text']) === 'photos') { $skippedPhotos = true; continue; }
                        $upd->execute([$uid, (int)$row['id']]);
                        $changed++;
                    }
                    $response = ['success' => true, 'message' => $changed . ' item(s) marked OK.' . ($skippedPhotos ? ' "Photos taken" was left: upload a photo first.' : '')];
                } elseif ($action === 'moi_remark') {
                    $remark = trim((string)($_POST['remark'] ?? ''));
                    $conn->prepare("UPDATE re_move_out_inspection_items SET remark = ? WHERE id = ? AND move_out_id = ? AND company_id = ?")
                        ->execute([$remark !== '' ? mb_substr($remark, 0, 500) : null, (int)($_POST['item_id'] ?? 0), $moveOutId, $currentCompanyId]);
                    $response = ['success' => true, 'message' => $remark !== '' ? 'Remark saved.' : 'Remark removed.'];
                } else {
                    $section = trim((string)($_POST['section_name'] ?? ''));
                    $text = trim((string)($_POST['item_text'] ?? ''));
                    if ($section === '' || $text === '') { $response = ['success' => false, 'message' => 'Enter the checklist item.']; break; }
                    $ord = $conn->prepare("SELECT MAX(section_order) so, MAX(CASE WHEN section_name = ? THEN section_order END) this_so, MAX(CASE WHEN section_name = ? THEN item_order END) io, MAX(CASE WHEN section_name = ? THEN is_walkthrough END) wt FROM re_move_out_inspection_items WHERE move_out_id = ?");
                    $ord->execute([$section, $section, $section, $moveOutId]);
                    $o = $ord->fetch(PDO::FETCH_ASSOC);
                    $conn->prepare("INSERT INTO re_move_out_inspection_items (company_id, move_out_id, section_name, section_order, item_text, item_order, is_custom, is_walkthrough, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)")
                        ->execute([$currentCompanyId, $moveOutId, mb_substr($section, 0, 150), $o['this_so'] !== null ? (int)$o['this_so'] : ((int)$o['so'] + 1), mb_substr($text, 0, 255), (int)$o['io'] + 1, (int)$o['wt'], $uid]);
                    $response = ['success' => true, 'message' => 'Item added.'];
                }
                break;

            case 'charges_save':
            case 'charges_add':
            case 'charges_remove':
            case 'charges_restore':
                if (!$mocReady) { $response = ['success' => false, 'message' => 'Run migrations/re_move_out_charges.sql first.']; break; }
                if ($mocLocked) { $response = ['success' => false, 'message' => 'The deposit settlement is marked as complete (or the move-out is closed); charges can no longer be changed. Reopen the settlement first.']; break; }
                if ($action === 'charges_add') {
                    $name = trim((string)($_POST['charge_name'] ?? ''));
                    if ($name === '') { $response = ['success' => false, 'message' => 'Enter the charge, e.g. AC servicing.']; break; }
                    $conn->prepare("INSERT INTO re_move_out_charges (company_id, move_out_id, charge_name, is_custom, display_order, updated_by) SELECT ?, ?, ?, 1, COALESCE(MAX(display_order), 0) + 1, ? FROM re_move_out_charges WHERE move_out_id = ?")
                        ->execute([$currentCompanyId, $moveOutId, mb_substr($name, 0, 100), $currentUserId, $moveOutId]);
                    $response = ['success' => true, 'message' => 'Charge added. Enter its amount.'];
                    break;
                }
                // Removed from this move-out only (the standard list is not touched); can be restored
                if ($action === 'charges_remove' || $action === 'charges_restore') {
                    $upd = $conn->prepare("UPDATE re_move_out_charges SET is_removed = ?, updated_by = ? WHERE id = ? AND move_out_id = ? AND company_id = ?");
                    $upd->execute([$action === 'charges_remove' ? 1 : 0, $currentUserId, (int)($_POST['charge_id'] ?? 0), $moveOutId, $currentCompanyId]);
                    $response = $upd->rowCount() ? ['success' => true, 'message' => $action === 'charges_remove' ? 'Charge removed from this move-out.' : 'Charge restored.'] : ['success' => false, 'message' => 'Charge not found.'];
                    break;
                }
                $amounts = (array)($_POST['amount'] ?? []);
                $notes = (array)($_POST['note'] ?? []);
                $upd = $conn->prepare("UPDATE re_move_out_charges SET amount = ?, note = ?, updated_by = ? WHERE id = ? AND move_out_id = ? AND company_id = ?");
                foreach ($mocCharges as $c) {
                    $cid = (int)$c['id'];
                    if (!array_key_exists($cid, $amounts)) continue;
                    $raw = trim((string)$amounts[$cid]);
                    if ($raw !== '' && (!is_numeric($raw) || (float)$raw < 0)) { $response = ['success' => false, 'message' => 'Enter a valid amount for ' . $c['charge_name'] . '.']; break 2; }
                    $note = trim((string)($notes[$cid] ?? ''));
                    $upd->execute([$raw === '' ? null : round((float)$raw, 2), $note !== '' ? mb_substr($note, 0, 255) : null, $currentUserId, $cid, $moveOutId, $currentCompanyId]);
                }
                $response = ['success' => true, 'message' => 'Charges saved.'];
                break;

            case 'keys_save':
            case 'keys_add':
            case 'keys_remove':
            case 'keys_confirm':
            case 'keys_undo':
                if (!$mokReady) { $response = ['success' => false, 'message' => 'Run migrations/re_move_out_keys.sql first.']; break; }
                if ($mokClosed) { $response = ['success' => false, 'message' => 'This move-out is ' . $moveOut['status'] . ' and can no longer be changed.']; break; }
                if ($action === 'keys_undo') {
                    $conn->prepare("UPDATE re_move_outs SET keys_returned = 0, keys_returned_by = NULL, keys_returned_at = NULL WHERE id = ? AND company_id = ?")
                        ->execute([$moveOutId, $currentCompanyId]);
                    $response = ['success' => true, 'message' => 'Key return reopened.'];
                    break;
                }
                if (!empty($moveOut['keys_returned'])) { $response = ['success' => false, 'message' => 'The key return is confirmed. Reopen it to make changes.']; break; }
                $uid = $currentUserId ? (int)$currentUserId : null;
                if ($action === 'keys_add') {
                    $label = trim((string)($_POST['key_label'] ?? ''));
                    if ($label === '') { $response = ['success' => false, 'message' => 'Enter the key or access card.']; break; }
                    $conn->prepare("INSERT INTO re_move_out_keys (company_id, move_out_id, key_label, is_custom, display_order, updated_by) SELECT ?, ?, ?, 1, COALESCE(MAX(display_order), 0) + 1, ? FROM re_move_out_keys WHERE move_out_id = ?")
                        ->execute([$currentCompanyId, $moveOutId, mb_substr($label, 0, 100), $uid, $moveOutId]);
                    $response = ['success' => true, 'message' => 'Added.'];
                    break;
                }
                if ($action === 'keys_remove') {
                    $del = $conn->prepare("DELETE FROM re_move_out_keys WHERE id = ? AND move_out_id = ? AND company_id = ? AND is_custom = 1");
                    $del->execute([(int)($_POST['key_id'] ?? 0), $moveOutId, $currentCompanyId]);
                    $response = $del->rowCount() ? ['success' => true, 'message' => 'Removed.'] : ['success' => false, 'message' => 'Only rows added here can be removed.'];
                    break;
                }
                // Save the returned quantities and notes (also before confirming)
                $returned = (array)($_POST['returned'] ?? []);
                $notes = (array)($_POST['note'] ?? []);
                $upd = $conn->prepare("UPDATE re_move_out_keys SET returned_qty = ?, note = ?, updated_by = ? WHERE id = ? AND move_out_id = ? AND company_id = ?");
                foreach ($mokKeys as $k) {
                    $kid = (int)$k['id'];
                    if (!array_key_exists($kid, $returned) && !array_key_exists($kid, $notes)) continue;
                    $raw = trim((string)($returned[$kid] ?? ''));
                    if ($raw !== '' && (!ctype_digit($raw) || (int)$raw > 99)) { $response = ['success' => false, 'message' => 'Enter a whole number of keys for ' . $k['key_label'] . '.']; break 2; }
                    $note = trim((string)($notes[$kid] ?? ''));
                    $upd->execute([$raw === '' ? null : (int)$raw, $note !== '' ? mb_substr($note, 0, 255) : null, $uid, $kid, $moveOutId, $currentCompanyId]);
                }
                if ($action === 'keys_save') {
                    $response = ['success' => true, 'message' => 'Saved.'];
                    break;
                }
                $sum = rmok_summary(rmok_keys($conn, $moveOutId));
                if ($sum['pending']) { $response = ['success' => false, 'message' => 'Enter how many came back for every row (0 if none) before confirming.']; break; }
                if ($sum['missing'] && empty($_POST['ack_missing'])) {
                    $response = ['success' => false, 'message' => 'Partial return: ' . rmok_missing_text($sum['missing']) . ' outstanding. Tick "Acknowledge partial return" to confirm the key return.'];
                    break;
                }
                $conn->prepare("UPDATE re_move_outs SET keys_returned = 1, keys_returned_by = ?, keys_returned_at = NOW() WHERE id = ? AND company_id = ?")
                    ->execute([$uid, $moveOutId, $currentCompanyId]);
                $response = ['success' => true, 'message' => 'Key return confirmed.'];
                break;

            case 'inspection_reopen':
                // Edit the final inspection after it was completed, while the deposit
                // settlement is still open (not approved): the deductions follow it
                if (empty($moveOut['inspection_completed'])) { $response = ['success' => false, 'message' => 'The final inspection is not completed yet.']; break; }
                if (in_array($moveOut['status'], ['completed', 'cancelled'], true)) { $response = ['success' => false, 'message' => 'This move-out is ' . $moveOut['status'] . '.']; break; }
                if ($settlementDone) { $response = ['success' => false, 'message' => 'The deposit settlement is marked as complete, so the inspection can no longer be changed. Reopen the settlement first.']; break; }
                $roReason = trim((string)($_POST['reason'] ?? ''));
                if ($roReason === '') { $response = ['success' => false, 'message' => 'Enter the reason for changing the inspection.']; break; }
                $conn->beginTransaction();
                $conn->prepare("
                    UPDATE re_move_outs
                    SET inspection_completed = 0, inspection_completed_by = NULL, inspection_completed_at = NULL,
                        status = IF(status IN ('inspection_completed', 'deposit_processing'), 'inspection_scheduled', status)
                    WHERE id = ? AND company_id = ?
                ")->execute([$moveOutId, $currentCompanyId]);
                // The tenant signed the report before this change: keep that copy as a
                // previous version and ask for a new signature
                $roSigned = 0;
                if (rmo_signed_ready($conn)) {
                    $sup = $conn->prepare("UPDATE re_move_out_photos SET file_type = 'inspection_report_superseded' WHERE move_out_id = ? AND company_id = ? AND file_type = 'inspection_report'");
                    $sup->execute([$moveOutId, $currentCompanyId]);
                    $roSigned = $sup->rowCount();
                    if ($roSigned && re_sig_ready($conn, 're_move_outs')) {
                        $conn->prepare("UPDATE re_move_outs SET tenant_signature_path = NULL, tenant_signed_name = NULL, tenant_signed_at = NULL WHERE id = ? AND company_id = ?")
                            ->execute([$moveOutId, $currentCompanyId]);
                    }
                }
                if (rmoa_ready($conn)) {
                    rmoa_log($conn, (int)$currentCompanyId, $moveOutId, 'inspection_reopened', $moveOut, $roReason . ($roSigned ? ' (signed report kept as a previous copy)' : ''), $currentUserId ? (int)$currentUserId : null);
                }
                $conn->commit();
                re_lease_lifecycle_audit('move_out_inspection_reopened', (int)$moveOut['lease_id'], ['company_id' => $currentCompanyId, 'lease_number' => $moveOut['lease_number']],
                    ['move_out_id' => $moveOutId, 'signed_reports_superseded' => $roSigned], 'Final inspection of move-out #' . $moveOutId . ' reopened: ' . $roReason);
                $response = ['success' => true, 'message' => 'The inspection is open for changes. Complete it again when done.' . ($roSigned ? ' The tenant will need to sign the inspection report again.' : '')];
                break;

            case 'complete_inspection':
                if (!empty($moveOut['inspection_completed']) || in_array($moveOut['status'], ['completed', 'cancelled'], true)) {
                    $response = ['success' => false, 'message' => 'The final inspection is already completed.'];
                    break;
                }
                if ($moiReady && !$moiLocked) {
                    if (rmoi_sync_photo_items($conn, (int)$currentCompanyId, $moveOutId) > 0) {
                        $response = ['success' => false, 'message' => 'No photos are uploaded, so "Photos taken" is back to not checked. Upload a photo in Move-Out Photos, then mark it OK.'];
                        break;
                    }
                    $openCount = rmoi_counts(rmoi_items($conn, $moveOutId))['open'];
                    if ($openCount > 0) {
                        $response = ['success' => false, 'message' => $openCount . ' checklist item(s) are not checked yet. Mark every item OK, Damaged or N/A first.'];
                        break;
                    }
                }
                $inspectionNotes = $_POST['inspection_notes'] ?? '';
                // Keys are returned in their own step (Key Return)
                $stmt = $conn->prepare("
                    UPDATE re_move_outs
                    SET inspection_completed = 1,
                        inspection_completed_by = ?,
                        inspection_completed_at = NOW(),
                        final_inspection_notes = ?,
                        status = 'inspection_completed'
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $currentUserId,
                    $inspectionNotes,
                    $moveOutId,
                    $currentCompanyId
                ]);
                $response = ['success' => true, 'message' => 'Inspection completed'];
                break;
                
            case 'add_damage':
                // The checklist prefills "item - "; drop a trailing dash left empty
                $damageDescription = rtrim(trim((string)($_POST['damage_description'] ?? '')), " -");
                $roomArea = $_POST['room_area'] ?? '';
                $damageType = $_POST['damage_type'] ?? 'minor';
                // Optional: a damage marked on the checklist is costed later in Damages & Report
                $costRaw = trim((string)($_POST['repair_cost'] ?? ''));
                if ($costRaw !== '' && (!is_numeric($costRaw) || (float)$costRaw < 0)) {
                    $response = ['success' => false, 'message' => 'Enter a valid repair cost, or leave it empty to add it later.'];
                    break;
                }
                $repairCost = $costRaw === '' ? null : round((float)$costRaw, 2);
                $notes = $_POST['notes'] ?? '';
                if (in_array($moveOut['status'], ['completed', 'cancelled'], true) || $settlementDone) {
                    $response = ['success' => false, 'message' => 'The deposit settlement is marked as complete (or the move-out is closed); damages can no longer be added. Reopen the settlement first.'];
                    break;
                }
                // Recorded from a checklist item: the item becomes Damaged
                $inspItemId = (int)($_POST['inspection_item_id'] ?? 0);
                if ($inspItemId) {
                    $chk = $conn->prepare("SELECT status, item_text, is_walkthrough FROM re_move_out_inspection_items WHERE id = ? AND move_out_id = ? AND company_id = ?");
                    $chk->execute([$inspItemId, $moveOutId, $currentCompanyId]);
                    $itemRow = $chk->fetch(PDO::FETCH_ASSOC);
                    $itemStatus = $itemRow ? $itemRow['status'] : false;
                    if ($itemStatus === false || $moiLocked) { $response = ['success' => false, 'message' => 'This checklist item can no longer be changed.']; break; }
                    if (!rmoi_item_can_damage($itemRow)) { $response = ['success' => false, 'message' => 'This item is marked OK or N/A, not Damaged.']; break; }
                    if ($itemStatus === 'damaged') { $response = ['success' => false, 'message' => 'A damage is already recorded for this item.']; break; }
                }

                if ($damageDescription === '') {
                    $response = ['success' => false, 'message' => 'Describe the damage.'];
                    break;
                }
                $stmt = $conn->prepare("
                    INSERT INTO re_move_out_damages
                    (company_id, move_out_id, damage_description, room_area, damage_type, repair_cost, notes, assessed_by, assessed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $currentCompanyId,
                    $moveOutId,
                    $damageDescription,
                    $roomArea,
                    $damageType,
                    $repairCost,
                    $notes,
                    $currentUserId
                ]);
                $damageId = (int)$conn->lastInsertId();
                
                rmoi_recalc_damage_total($conn, $moveOutId);
                
                if ($inspItemId && $damageId) {
                    $conn->prepare("UPDATE re_move_out_damages SET inspection_item_id = ? WHERE id = ?")->execute([$inspItemId, $damageId]);
                    $conn->prepare("UPDATE re_move_out_inspection_items SET status = 'damaged', damage_id = ?, checked_by = ?, checked_at = NOW() WHERE id = ?")
                        ->execute([$damageId, $currentUserId ?: null, $inspItemId]);
                }
                $response = ['success' => true, 'message' => 'Damage added', 'damage_id' => $damageId];
                break;
                
            case 'update_damage_cost':
                if ($moveOut['status'] === 'completed' || $moveOut['status'] === 'cancelled') { $response = ['success' => false, 'message' => 'This move-out is ' . $moveOut['status'] . '.']; break; }
                if ($settlementDone) { $response = ['success' => false, 'message' => 'The deposit settlement is marked as complete; repair costs can no longer be changed. Reopen the settlement first.']; break; }
                $costRaw = trim((string)($_POST['repair_cost'] ?? ''));
                if ($costRaw === '' || !is_numeric($costRaw) || (float)$costRaw < 0) { $response = ['success' => false, 'message' => 'Enter the repair cost (0 if there is no charge).']; break; }
                $upd = $conn->prepare("UPDATE re_move_out_damages SET repair_cost = ?, assessed_by = ?, assessed_at = NOW() WHERE id = ? AND move_out_id = ? AND company_id = ?");
                $upd->execute([round((float)$costRaw, 2), $currentUserId, (int)($_POST['damage_id'] ?? 0), $moveOutId, $currentCompanyId]);
                rmoi_recalc_damage_total($conn, $moveOutId);
                $response = ['success' => true, 'message' => 'Repair cost saved.'];
                break;

            case 'upload_photos':
                if (in_array($moveOut['status'], ['completed', 'cancelled'], true)) {
                    $response = ['success' => false, 'message' => 'Photos cannot be added after the move-out is ' . $moveOut['status'] . '.'];
                    break;
                }
                $roomArea = trim((string)($_POST['room_area'] ?? ''));
                $photoNote = trim((string)($_POST['photo_description'] ?? ''));
                // A damage photo belongs to one recorded damage of this move-out
                $damageId = (int)($_POST['damage_id'] ?? 0);
                if ($damageId) {
                    $chk = $conn->prepare("SELECT room_area FROM re_move_out_damages WHERE id = ? AND move_out_id = ? AND company_id = ?");
                    $chk->execute([$damageId, $moveOutId, $currentCompanyId]);
                    $damageArea = $chk->fetchColumn();
                    if ($damageArea === false) { $response = ['success' => false, 'message' => 'Damage not found.']; break; }
                    if ($roomArea === '') { $roomArea = (string)$damageArea; }
                }
                $allowedMimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
                $maxSize = 10 * 1024 * 1024; // 10MB
                $files = $_FILES['files'] ?? null;
                if (!$files || !is_array($files['name'])) {
                    $response = ['success' => false, 'message' => 'Please choose a photo to upload.'];
                    break;
                }
                $uploadDir = __DIR__ . '/../../uploads/realestate/move_outs/' . $moveOutId;
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }
                if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                    error_log('Move-out upload: folder not writable: ' . $uploadDir);
                    $response = ['success' => false, 'message' => 'The server cannot save files to uploads/realestate/move_outs. Ask your administrator to make that folder writable.'];
                    break;
                }
                $insert = $conn->prepare("
                    INSERT INTO re_move_out_photos
                    (company_id, move_out_id, photo_path, photo_description, room_area, is_damage, damage_id, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $saved = 0;
                $errors = [];
                foreach ($files['name'] as $i => $originalName) {
                    if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                        $errors[] = $originalName . ': upload failed';
                        continue;
                    }
                    if ($files['size'][$i] > $maxSize) {
                        $errors[] = $originalName . ': file too large (max 10MB)';
                        continue;
                    }
                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    $mime = function_exists('mime_content_type') ? mime_content_type($files['tmp_name'][$i]) : ($allowedMimes[$ext] ?? '');
                    if (!isset($allowedMimes[$ext]) || !in_array($mime, $allowedMimes, true)) {
                        $errors[] = $originalName . ': file type not allowed';
                        continue;
                    }
                    $fileName = uniqid() . '_' . time() . '.' . $ext;
                    if (!move_uploaded_file($files['tmp_name'][$i], $uploadDir . '/' . $fileName)) {
                        $errors[] = $originalName . ': could not be saved';
                        continue;
                    }
                    $insert->execute([
                        $currentCompanyId,
                        $moveOutId,
                        'uploads/realestate/move_outs/' . $moveOutId . '/' . $fileName,
                        mb_substr($photoNote !== '' ? $photoNote : basename($originalName), 0, 255),
                        $roomArea !== '' ? mb_substr($roomArea, 0, 100) : null,
                        $damageId ? 1 : 0,
                        $damageId ?: null,
                        $currentUserId,
                    ]);
                    $saved++;
                }
                if ($saved > 0) {
                    $response = ['success' => true, 'message' => $saved . ' photo(s) uploaded' . ($errors ? '. Skipped: ' . implode('; ', $errors) : '')];
                } else {
                    $response = ['success' => false, 'message' => $errors ? implode('; ', $errors) : 'Please choose a photo to upload.'];
                }
                break;

            case 'upload_signed_report':
                if (!rmo_signed_ready($conn)) { $response = ['success' => false, 'message' => 'Run migrations/re_move_out_signed_forms.sql first.']; break; }
                if ($moveOut['status'] === 'completed' || $moveOut['status'] === 'cancelled') { $response = ['success' => false, 'message' => 'This move-out is ' . $moveOut['status'] . '.']; break; }
                if (empty($moveOut['inspection_completed'])) { $response = ['success' => false, 'message' => 'Complete the final inspection first.']; break; }
                $file = $_FILES['file'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { $response = ['success' => false, 'message' => 'Choose the signed report to upload.']; break; }
                if ($file['error'] !== UPLOAD_ERR_OK) { $response = ['success' => false, 'message' => 'Upload failed.']; break; }
                if ($file['size'] > 10 * 1024 * 1024) { $response = ['success' => false, 'message' => 'File too large (max 10MB).']; break; }
                $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
                $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : ($allowed[$ext] ?? '');
                if (!isset($allowed[$ext]) || !in_array($mime, $allowed, true)) { $response = ['success' => false, 'message' => 'Upload a PDF, JPG, PNG or WEBP file.']; break; }
                $uploadDir = __DIR__ . '/../../uploads/realestate/move_outs/' . $moveOutId;
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
                if (!is_dir($uploadDir) || !is_writable($uploadDir)) { $response = ['success' => false, 'message' => 'The server cannot save files to uploads/realestate/move_outs. Ask your administrator to make that folder writable.']; break; }
                $fileName = 'signed_report_' . uniqid() . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) { $response = ['success' => false, 'message' => 'The file could not be saved.']; break; }
                $conn->prepare("INSERT INTO re_move_out_photos (company_id, move_out_id, file_type, photo_path, photo_description, uploaded_by) VALUES (?, ?, 'inspection_report', ?, ?, ?)")
                    ->execute([$currentCompanyId, $moveOutId, 'uploads/realestate/move_outs/' . $moveOutId . '/' . $fileName, mb_substr(basename((string)$file['name']), 0, 255), $currentUserId]);
                $response = ['success' => true, 'message' => 'Signed report uploaded.'];
                break;

            case 'delete_photo':
                if (in_array($moveOut['status'], ['completed', 'cancelled'], true)) {
                    $response = ['success' => false, 'message' => 'Photos cannot be removed after the move-out is ' . $moveOut['status'] . '.'];
                    break;
                }
                $photoId = (int)($_POST['photo_id'] ?? 0);
                $stmt = $conn->prepare("SELECT photo_path" . (rmo_signed_ready($conn) ? ", file_type" : ", 'photo' AS file_type") . " FROM re_move_out_photos WHERE id = ? AND move_out_id = ? AND company_id = ?");
                $stmt->execute([$photoId, $moveOutId, $currentCompanyId]);
                $photoRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$photoRow) {
                    $response = ['success' => false, 'message' => 'Photo not found.'];
                    break;
                }
                $path = (string)$photoRow['photo_path'];
                $isPhoto = $photoRow['file_type'] === 'photo';
                // The completed inspection relies on at least one photo ("Photos taken" is OK)
                if ($isPhoto && !empty($moveOut['inspection_completed']) && rmoi_photo_count($conn, $moveOutId) <= 1) {
                    $response = ['success' => false, 'message' => 'This is the last photo and the completed inspection says photos were taken. Click Edit inspection first, then remove it (the Photos taken item goes back to not checked).'];
                    break;
                }
                $conn->prepare("DELETE FROM re_move_out_photos WHERE id = ? AND move_out_id = ? AND company_id = ?")
                    ->execute([$photoId, $moveOutId, $currentCompanyId]);
                $fullPath = __DIR__ . '/../../' . $path;
                if (strpos($path, 'uploads/realestate/move_outs/' . $moveOutId . '/') === 0 && is_file($fullPath)) {
                    @unlink($fullPath);
                }
                // Last signed report removed: the digital signature goes with it
                if (re_sig_ready($conn, 're_move_outs') && rmo_signed_ready($conn) && !rmo_signed_reports($conn, $moveOutId)) {
                    $conn->prepare("UPDATE re_move_outs SET tenant_signature_path = NULL, tenant_signed_name = NULL, tenant_signed_at = NULL WHERE id = ? AND company_id = ?")
                        ->execute([$moveOutId, $currentCompanyId]);
                }
                // No photo left: "Photos taken" goes back to not checked
                $photoItemsReset = $isPhoto ? rmoi_sync_photo_items($conn, (int)$currentCompanyId, $moveOutId) : 0;
                $response = ['success' => true, 'message' => 'Photo removed' . ($photoItemsReset ? '. No photos are left, so "Photos taken" is back to not checked.' : '')];
                break;

            case 'sign_report':
                // Tenant signs Form 1 on screen: the signature goes into the form,
                // and the signed PDF is filed as the signed copy
                if (!rmo_signed_ready($conn) || !re_sig_ready($conn, 're_move_outs')) { $response = ['success' => false, 'message' => 'Run migrations/re_move_out_signed_forms.sql and migrations/re_tenant_signatures.sql first.']; break; }
                if (in_array($moveOut['status'], ['completed', 'cancelled'], true)) { $response = ['success' => false, 'message' => 'This move-out is ' . $moveOut['status'] . '.']; break; }
                if (empty($moveOut['inspection_completed'])) { $response = ['success' => false, 'message' => 'Complete the final inspection first.']; break; }
                try {
                    require_once __DIR__ . '/includes/move_out_form_helper.php';
                    $sigPath = re_sig_save_png((string)($_POST['signature'] ?? ''), 'realestate/move_outs/' . $moveOutId);
                    $signer = re_sig_name((string)($_POST['signer_name'] ?? ''), trim($moveOut['first_name'] . ' ' . $moveOut['last_name']));
                    $conn->prepare("UPDATE re_move_outs SET tenant_signature_path = ?, tenant_signed_name = ?, tenant_signed_at = NOW() WHERE id = ? AND company_id = ?")
                        ->execute([$sigPath, $signer, $moveOutId, $currentCompanyId]);
                    $formData = rmof_load($conn, (int)$currentCompanyId, $moveOutId);
                    $pdf = rmof_pdf($conn, $formData, !empty($_POST['include_photos']), ['meters' => !empty($_POST['include_meters']), 'with_signature' => true]);
                    $pdfRel = 'uploads/realestate/move_outs/' . $moveOutId . '/signed_report_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
                    if (file_put_contents(__DIR__ . '/../../' . $pdfRel, $pdf) === false) {
                        throw new RuntimeException('The signed form could not be saved.');
                    }
                    $conn->prepare("INSERT INTO re_move_out_photos (company_id, move_out_id, file_type, photo_path, photo_description, uploaded_by) VALUES (?, ?, 'inspection_report', ?, ?, ?)")
                        ->execute([$currentCompanyId, $moveOutId, $pdfRel, mb_substr('Signed digitally by ' . $signer, 0, 255), $currentUserId]);
                    $response = ['success' => true, 'message' => 'Signed by ' . $signer . '. The signed report is saved.'];
                } catch (Throwable $e) {
                    $response = ['success' => false, 'message' => $e->getMessage()];
                }
                break;

            case 'settlement_complete':
            case 'settlement_reopen':
                // Operations only: Accounts settles the deposit (refund / recovery) manually
                if ($action === 'settlement_complete' && empty($moveOut['inspection_completed'])) { $response = ['success' => false, 'message' => 'Complete the final inspection first.']; break; }
                if ($action === 'settlement_reopen' && !rmv_can_manage($conn)) { $response = ['success' => false, 'message' => 'Only Owner or Admin can reopen the settlement.']; break; }
                $sr = rmst_set($conn, (int)$currentCompanyId, $moveOut, $action === 'settlement_complete', $currentUserId ? (int)$currentUserId : null, trim((string)($_POST['note'] ?? '')));
                $response = $sr['ok']
                    ? ['success' => true, 'message' => $action === 'settlement_complete' ? 'Deposit settlement marked as complete.' : 'Settlement reopened: damages and charges can be changed again.']
                    : ['success' => false, 'message' => $sr['error']];
                break;

            case 'void_move_out':
                if (!rmv_can_manage($conn)) {
                    $response = ['success' => false, 'message' => 'Only Owner or Admin can cancel a move-out.'];
                    break;
                }
                $vr = rmv_void_move_out($conn, (int)$currentCompanyId, $moveOutId, $currentUserId ? (int)$currentUserId : null,
                    (string)($_POST['reason'] ?? ''), true);
                $response = $vr['ok']
                    ? ['success' => true, 'message' => $moveOut['status'] === 'completed' ? 'Move-out voided.' : 'Move-out cancelled.']
                    : ['success' => false, 'message' => $vr['error']];
                break;

            case 'complete_move_out':
                // Lease status after move-out. Only an active lease is changed:
                //  - expired:    lease ran to its end date -> set to expired here
                //  - terminated: tenant left early -> must go through Terminate Lease
                //                first (cheques, invoices, revenue), then complete here
                // Any other lease status (already expired/terminated/...) is kept.
                $leaseStatusBefore = (string)$moveOut['lease_status'];
                $finalStatus = (string)($_POST['lease_final_status'] ?? '');
                $newLeaseStatus = null;

                if (!$moveOut['inspection_completed']) {
                    $response = ['success' => false, 'message' => 'Please complete the final inspection first.'];
                    break;
                }
                if ($moveOut['status'] === 'completed' || $moveOut['status'] === 'cancelled') {
                    $response = ['success' => false, 'message' => 'This move-out is already ' . $moveOut['status'] . '.'];
                    break;
                }
                // Every damage and standard charge priced, and the deposit settled
                // (the button is hidden otherwise; the server checks it too)
                if ($uncostedMsg = $uncostedDamagesMsg()) {
                    $response = ['success' => false, 'message' => $uncostedMsg];
                    break;
                }
                if (!$settlementDone) {
                    $response = ['success' => false, 'message' => 'Mark the deposit settlement as complete first (Deposit Settlement).'];
                    break;
                }
                if ($mokKeys && empty($moveOut['keys_returned'])) {
                    $response = ['success' => false, 'message' => 'Confirm the key return first (Key Return).'];
                    break;
                }
                if (rmo_signed_ready($conn) && !rmo_signed_reports($conn, $moveOutId)) {
                    $response = ['success' => false, 'message' => 'Upload the signed inspection report first (Inspection Report).'];
                    break;
                }
                if ($leaseStatusBefore === 'active') {
                    $moDateCheck = (string)($moveOut['actual_move_out_date'] ?? '');
                    $leftBeforeEnd = $moDateCheck !== '' && !empty($moveOut['lease_end']) && $moDateCheck < $moveOut['lease_end'];
                    if ($finalStatus === 'expired' && $leftBeforeEnd) {
                        // Leaving early must go through Terminate Lease (cheques, invoices, revenue)
                        $response = ['success' => false, 'message' => 'The tenant moved out on ' . $moDateCheck . ', before the lease end date (' . $moveOut['lease_end'] . '). Terminate the lease first using Terminate Lease, then complete the move-out.'];
                        break;
                    }
                    if ($finalStatus === 'expired') {
                        $newLeaseStatus = 'expired';
                    } elseif ($finalStatus === 'terminated') {
                        $response = ['success' => false, 'message' => 'Terminate the lease first using Terminate Lease, then complete the move-out.'];
                        break;
                    } else {
                        $response = ['success' => false, 'message' => 'Please choose the lease status: Expired or Terminated.'];
                        break;
                    }
                }

                $conn->beginTransaction();

                // Update move-out status
                $stmt = $conn->prepare("
                    UPDATE re_move_outs
                    SET status = 'completed',
                        approved_by = ?,
                        approved_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$currentUserId, $moveOutId, $currentCompanyId]);

                // Update lease
                if ($newLeaseStatus) {
                    $conn->prepare("
                        UPDATE re_leases
                        SET move_out_completed = 1,
                            status = ?
                        WHERE id = ? AND company_id = ?
                    ")->execute([$newLeaseStatus, $moveOut['lease_id'], $currentCompanyId]);
                } else {
                    $conn->prepare("
                        UPDATE re_leases SET move_out_completed = 1 WHERE id = ? AND company_id = ?
                    ")->execute([$moveOut['lease_id'], $currentCompanyId]);
                }

                // Every unit of the lease (incl. multi-unit) becomes vacant unless
                // another active lease now holds it
                re_lease_units_set_vacant($conn, (int)$currentCompanyId, (int)$moveOut['lease_id']);

                $conn->commit();

                $leaseStatusAfter = $newLeaseStatus ?: $leaseStatusBefore;
                re_lease_lifecycle_audit(
                    'move_out_completed',
                    (int)$moveOut['lease_id'],
                    ['company_id' => $currentCompanyId, 'lease_number' => $moveOut['lease_number'], 'status' => $leaseStatusBefore],
                    ['move_out_id' => $moveOutId, 'status' => $leaseStatusAfter, 'move_out_completed' => 1],
                    'Move-out #' . $moveOutId . ' completed. Lease status ' . ($newLeaseStatus ? ('changed from ' . $leaseStatusBefore . ' to ' . $newLeaseStatus) : ('kept as ' . $leaseStatusBefore)) . '.'
                );

                $response = ['success' => true, 'message' => 'Move-out completed. Lease status: ' . ucwords(str_replace('_', ' ', $leaseStatusAfter)) . '.', 'redirect' => 'move_out.php'];
                break;

            case 'save_meter_reading':
                $meterType = $_POST['meter_type'] ?? '';
                $readingValue = $_POST['reading_value'] ?? '';
                $meterNumber = $_POST['meter_number'] ?? '';
                $notes = $_POST['notes'] ?? '';
                
                if ($meterType && $readingValue) {
                    $stmt = $conn->prepare("
                        INSERT INTO re_meter_readings
                        (company_id, lease_id, reading_type, meter_type, reading_value, reading_date, meter_number, notes, taken_by)
                        VALUES (?, ?, 'move_out', ?, ?, CURDATE(), ?, ?, ?)
                    ");
                    $stmt->execute([$currentCompanyId, $moveOut['lease_id'], $meterType, $readingValue, $meterNumber, $notes, $currentUserId]);
                    $response = ['success' => true, 'message' => 'Meter reading saved'];
                }
                break;
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// Get damages
$damages = $conn->prepare("
    SELECT * FROM re_move_out_damages
    WHERE move_out_id = ?
    ORDER BY assessed_at DESC
");
$damages->execute([$moveOutId]);
$damages = $damages->fetchAll(PDO::FETCH_ASSOC);

$moiItems = $moiReady ? rmoi_items($conn, $moveOutId) : [];
$moiCounts = rmoi_counts($moiItems);

// Get meter readings
$meterReadings = $conn->prepare("
    SELECT * FROM re_meter_readings
    WHERE lease_id = ? AND reading_type = 'move_out'
    ORDER BY meter_type, reading_date DESC
");
$meterReadings->execute([$moveOut['lease_id']]);
$meterReadings = $meterReadings->fetchAll(PDO::FETCH_ASSOC);

// Get photos
$photos = $conn->prepare("
    SELECT * FROM re_move_out_photos
    WHERE move_out_id = ?" . rmo_photo_only_sql($conn) . "
    ORDER BY uploaded_at DESC
");
$photos->execute([$moveOutId]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);
// Damage photos per damage; the rest show the unit's condition
$photosByDamage = [];
$conditionPhotos = [];
foreach ($photos as $p) {
    if (!empty($p['damage_id'])) {
        $photosByDamage[(int)$p['damage_id']][] = $p;
    } else {
        $conditionPhotos[] = $p;
    }
}
$photosLocked = in_array($moveOut['status'], ['completed', 'cancelled'], true);
// Repair costs: entered in Damages & Report until the deposit settlement is marked complete
$uncostedCount = count(array_filter($damages, static fn($d) => $d['repair_cost'] === null));
// Key return step: done once confirmed. A move-out without a key list (closed
// before the step existed, or migration not run) is not held up by it.
$mokSum = rmok_summary($mokKeys);
$mokSource = $mokKeys ? rmok_source_move_in($conn, (int)$currentCompanyId, (int)$moveOut['lease_id']) : null;
$moKeysDone = !empty($moveOut['keys_returned']) || !$mokKeys;
// Inspection Appointment step: booked (and not missed); an inspection already
// completed (also before the step existed) counts as done
$moApptMissed = ($moveOut['inspection_appt_status'] ?? null) === 'missed';
$moApptDone = !empty($moveOut['inspection_completed']) || (!empty($moveOut['inspection_scheduled_date']) && !$moApptMissed);
// Inspection Report step: done once the tenant-signed Form 1 is uploaded. Not
// held up where uploads are not set up yet, or for a move-out already completed.
$signedReports = rmo_signed_reports($conn, $moveOutId);
$moReportDone = (bool)$signedReports || !rmo_signed_ready($conn) || $moveOut['status'] === 'completed';

// Deposit settlement: breakdown only; Accounts settles it, staff mark it complete
$securityDeposit = (float)$moveOut['security_deposit'];
$damageTotal = (float)$moveOut['damage_assessment_total'];
$stFig = rmst_figures($conn, (int)$currentCompanyId, $moveOut);
$costEditable = !$photosLocked && !$settlementDone;
$canAddCharge = has_role('Owner', $conn) || has_role('Admin', $conn);
$stByName = null;
if (!empty($moveOut['settlement_completed_by'])) {
    $u = $conn->prepare("SELECT username FROM user WHERE id = ?");
    $u->execute([(int)$moveOut['settlement_completed_by']]);
    $stByName = $u->fetchColumn() ?: null;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Lease status choice offered when completing the move-out (active leases only).
$leaseIsActive = ($moveOut['lease_status'] ?? '') === 'active';
$moveOutDate = (string)($moveOut['actual_move_out_date'] ?? '');
$leaseEnd = (string)($moveOut['lease_end'] ?? '');
$leftEarly = $moveOutDate && $leaseEnd && $moveOutDate < $leaseEnd;
// Terminate Lease rejects dates outside the lease period, so clamp the suggestion
$terminationDate = $moveOutDate ?: date('Y-m-d');
if ($leaseEnd && $terminationDate > $leaseEnd) $terminationDate = $leaseEnd;
if (!empty($moveOut['lease_start']) && $terminationDate < $moveOut['lease_start']) $terminationDate = $moveOut['lease_start'];
$terminateUrl = 'lease_terminate.php?' . http_build_query([
    'lease_id' => (int)$moveOut['lease_id'],
    'termination_date' => $terminationDate,
    'return_move_out' => $moveOutId,
]);
$canTerminate = has_role('Owner', $conn) || has_role('Admin', $conn);

// Set page title and include layout
$pageTitle = 'Move-Out Details';
$pageStyles = '
    .damage-item { border-left: 4px solid #dc3545; padding: 15px; margin-bottom: 10px; background: #f8f9fa; }
    .status-badge { font-size: 0.9rem; }
    /* Steps without the Actions / Summary column use the full width */
    [data-rmm-ready] .row:has(> .mo-side:not(.rmm-on)) > .mo-main { flex: 0 0 100%; max-width: 100%; width: 100%; }
    .appt-summary { display: flex; gap: 1rem; align-items: center; padding: .9rem 1rem; border-radius: 14px; border: 1px solid var(--rm-border); background: linear-gradient(120deg, rgba(37,99,235,.07), var(--rm-surface) 65%); }
    .appt-summary.is-missed { background: linear-gradient(120deg, rgba(220,53,69,.08), var(--rm-surface) 65%); border-color: rgba(220,53,69,.3); }
    .appt-cal { width: 68px; flex-shrink: 0; border-radius: 14px; overflow: hidden; text-align: center; background: var(--rm-surface); box-shadow: 0 6px 16px -8px rgba(15,23,42,.35); border: 1px solid var(--rm-border); }
    .appt-cal-m { display: block; background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; font-size: .7rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; padding: .2rem 0; }
    .appt-cal-d { display: block; font-size: 1.6rem; font-weight: 800; line-height: 1.15; padding-top: .15rem; }
    .appt-cal-w { display: block; font-size: .68rem; color: var(--rm-muted); padding-bottom: .3rem; }
    .appt-when { font-size: 1.08rem; font-weight: 750; }
    .appt-meta { display: flex; flex-wrap: wrap; gap: .3rem 1rem; font-size: .8rem; color: var(--rm-muted); margin-top: .2rem; }
    .appt-note { font-size: .82rem; margin-top: .35rem; }
    .appt-choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .6rem; }
    .appt-choice { position: relative; cursor: pointer; margin: 0; }
    .appt-choice input { position: absolute; opacity: 0; pointer-events: none; }
    .appt-choice-body { display: grid; grid-template-columns: auto 1fr; grid-template-rows: auto auto; column-gap: .65rem; align-items: center; padding: .8rem .9rem; border-radius: 12px; border: 1.5px solid var(--rm-border); background: var(--rm-surface); transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease; }
    .appt-choice-body i { grid-row: span 2; font-size: 1.35rem; color: var(--rm-muted); }
    .appt-choice-body small { color: var(--rm-muted); }
    .appt-choice:hover .appt-choice-body { transform: translateY(-1px); }
    .appt-choice input:checked + .appt-choice-body { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
    .appt-choice input:checked + .appt-choice-body i { color: #4f46e5; }
    .appt-choice input:focus-visible + .appt-choice-body { outline: 2px solid #4f46e5; outline-offset: 2px; }
    .appt-email-panel { padding: 1rem; border: 1px solid rgba(79,70,229,.25); border-radius: 14px; background: linear-gradient(180deg, rgba(79,70,229,.05), var(--rm-surface) 120px); box-shadow: 0 10px 24px -18px rgba(15,23,42,.45); animation: appt-panel-in .25s ease both; scroll-margin-top: 1rem; }
    .appt-email-panel[hidden] { display: none !important; }
    @keyframes appt-panel-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
    .appt-email-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .6rem; }
    .appt-email-title { font-weight: 700; display: flex; align-items: center; gap: .45rem; min-width: 0; }
    .appt-email-body { min-height: 220px; max-height: 60vh; overflow: auto; -webkit-overflow-scrolling: touch; padding: 1rem 1.1rem; border: 1px solid var(--rm-border); border-radius: 10px; background: #fff; color: #1f2937; font-size: 14px; line-height: 1.55; word-wrap: break-word; overflow-wrap: anywhere; }
    .appt-email-body table { max-width: 100%; }
    .dmg-cost-view[hidden], .dmg-cost-form[hidden], .moc-view[hidden], .moc-edit[hidden] { display: none !important; }
    .dmg-cost-edit { padding: .1rem .45rem; }
    .appt-email-tpl { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .6rem; padding-top: .6rem; border-top: 1px dashed var(--rm-border); }
    .appt-email-tpl-state { font-size: .82rem; color: var(--rm-muted); margin-right: auto; }
    .appt-email-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; margin-top: .75rem; }
    .appt-email-actions-r { display: flex; gap: .5rem; flex-wrap: wrap; }
    @media (max-width: 575.98px) {
      .appt-email-panel { padding: .75rem; border-radius: 12px; }
      .appt-email-body { min-height: 180px; max-height: 50vh; padding: .75rem; font-size: 13.5px; }
      .appt-email-actions { flex-direction: column-reverse; align-items: stretch; }
      .appt-email-actions-r { width: 100%; }
      .appt-email-actions-r .btn { flex: 1 1 0; }
    }
    .appt-email-body:focus { outline: 2px solid rgba(79,70,229,.35); outline-offset: 2px; }
    .appt-history > summary { cursor: pointer; color: var(--rm-muted); font-size: .9rem; }
    .appt-h-row { display: flex; gap: .6rem; padding: .4rem 0; border-top: 1px dashed var(--rm-border); font-size: .88rem; }
    .appt-h-row:first-child { border-top: 0; }
    .moi-sec { border: 1px solid var(--rm-border); border-radius: 12px; margin-bottom: .65rem; background: var(--rm-surface); }
    .moi-sec > summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: .6rem; padding: .7rem .9rem; font-weight: 650; }
    .moi-sec > summary::-webkit-details-marker { display: none; }
    .moi-sec > summary .chev { margin-left: auto; transition: transform .15s ease; color: var(--rm-muted); }
    .moi-sec[open] > summary .chev { transform: rotate(90deg); }
    .moi-sec.complete > summary { color: #166534; }
    .moi-sec-body { padding: 0 .9rem .8rem; }
    .moi-sec-tools { display: flex; justify-content: flex-end; padding: .1rem 0 .35rem; }
    .moi-item { display: flex; align-items: flex-start; gap: .7rem; padding: .55rem .25rem; border-top: 1px dashed var(--rm-border); flex-wrap: wrap; }
    .moi-item-text { flex: 1 1 260px; min-width: 0; }
    .moi-item-meta { font-size: .72rem; color: var(--rm-muted); }
    .moi-item.st-damaged { background: rgba(220,53,69,.06); border-radius: 8px; }
    .moi-btns { display: flex; gap: .3rem; flex-shrink: 0; align-items: center; }
    .moi-btns .btn { font-size: .75rem; padding: .15rem .55rem; }
    .moi-remark-form { display: flex; gap: .35rem; margin-top: .3rem; }
    .moi-remark-form[hidden] { display: none; }
    .moi-damage { font-size: .8rem; color: #b02a37; margin-top: .15rem; }
    .moi-add { display: flex; gap: .4rem; margin-top: .6rem; }
    .mo-photo img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 10px; border: 1px solid var(--rm-border); background: var(--rm-surface-2); }
    .mo-photo.is-damage img { border: 2px solid #dc3545; }
    .mo-photo-cap { font-size: .75rem; color: var(--rm-muted); min-width: 0; }
    .mo-thumbs { display: flex; gap: .4rem; flex-wrap: wrap; margin-top: .5rem; }
    .dep-step { display: flex; gap: .75rem; align-items: flex-start; padding: .85rem 0; border-top: 1px dashed var(--rm-border); }
    .dep-step-n { flex: 0 0 auto; width: 1.7rem; height: 1.7rem; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: .85rem; background: var(--rm-soft, #eef2ff); color: var(--primary, #1e3a8a); }
    .dep-facts { display: flex; flex-wrap: wrap; gap: .4rem 1.5rem; align-items: baseline; }
    .dep-recovery { max-width: 640px; border: 1px solid var(--bs-border-color, #dee2e6); border-left: 3px solid #dc3545; border-radius: .5rem; padding: .6rem .8rem; background: rgba(220, 53, 69, .03); }
    .dep-stmt { width: 100%; max-width: 640px; border-collapse: collapse; font-size: .95rem; }
    .dep-stmt td { padding: .45rem .25rem; border-bottom: 1px dashed var(--rm-border); vertical-align: top; }
    .dep-stmt td:last-child { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; width: 1%; padding-left: 1rem; }
    .dep-stmt tr.less td { color: var(--rm-text); }
    .dep-stmt tr.less td:last-child { color: #b02a37; }
    .dep-stmt tr.total td { border-top: 2px solid var(--rm-text); border-bottom: 0; font-weight: 700; font-size: 1.05rem; padding-top: .6rem; }
    .dep-stmt tr.total td:last-child { color: #198754; }
    .dep-stmt tr.sub td { border-bottom: 0; color: var(--rm-muted); font-size: .88rem; padding-top: 0; }
    .dep-stmt tr.excess td { border: 0; background: rgba(220,53,69,.07); color: #b02a37; font-weight: 600; border-radius: 6px; }
    .dep-lines > summary { cursor: pointer; color: var(--rm-muted); font-size: .9rem; }
    .mo-thumbs img { width: 72px; height: 54px; object-fit: cover; border-radius: 6px; border: 1px solid var(--rm-border); }
    @media (max-width: 575.98px) { .moi-btns { width: 100%; } .moi-btns .btn { flex: 1; } }
';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles .= re_move_ui_css() . re_milestones_css() . re_form_card_css() . '
';
require_once __DIR__ . '/includes/re_layout_header.php';

?>
<div class="rm-page">
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show"><?= h($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?= h($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Header -->
        <a href="move_out.php" class="rm-back"><i class="bi bi-arrow-left"></i> Move-Out Management</a>
        <div class="rm-hero">
            <div>
                <h1><i class="bi bi-box-arrow-right"></i>Move-Out #<?= $moveOutId ?></h1>
                <div class="rm-meta">
                    <?= re_move_ui_pill(ucfirst(str_replace('_', ' ', $moveOut['status'])), re_move_ui_tone($moveOut['status'])) ?>
                    <span><i class="bi bi-calendar-event"></i> <?= date('M d, Y', strtotime($moveOut['actual_move_out_date'])) ?></span>
                    <span><i class="bi bi-building"></i> <?= h($moveOut['building_name']) ?> · <?= h($moveOut['unit_number']) ?></span>
                    <span><i class="bi bi-person"></i> <?= h($moveOut['first_name'] . ' ' . $moveOut['last_name']) ?></span>
                </div>
            </div>
            <div class="rm-actions">
                <a href="lease_view.php?id=<?= $moveOut['lease_id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-file-text me-1"></i> View Lease
                </a>
                <?php
                $voidCheck = ($moveOut['status'] !== 'cancelled' && rmv_can_manage($conn)) ? rmv_move_out_check($conn, (int)$currentCompanyId, $moveOut) : null;
                $voidLabel = $moveOut['status'] === 'completed' ? 'Void Move-Out' : 'Cancel Move-Out';
                if ($voidCheck): ?>
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidMoveOutModal">
                        <i class="bi bi-x-octagon me-1"></i> <?= $voidLabel ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($moveOut['status'] === 'cancelled'):
            $cby = '';
            if (!empty($moveOut['cancelled_by'])) {
                $cu = $conn->prepare("SELECT username FROM user WHERE id = ?");
                $cu->execute([(int)$moveOut['cancelled_by']]);
                $cby = (string)$cu->fetchColumn();
            } ?>
            <div class="alert alert-danger d-flex gap-2 align-items-start">
                <i class="bi bi-x-octagon-fill fs-5"></i>
                <div><strong><?= ($moveOut['cancelled_from_status'] ?? '') === 'completed' ? 'This completed move-out was voided' : 'This move-out was cancelled' ?></strong>
                    <?= !empty($moveOut['cancelled_at']) ? ' on ' . date('M d, Y H:i', strtotime($moveOut['cancelled_at'])) : '' ?><?= $cby !== '' ? ' by ' . h($cby) : '' ?>.
                    <?php if (!empty($moveOut['cancel_reason'])): ?><div class="small">Reason: <?= h($moveOut['cancel_reason']) ?></div><?php endif; ?>
                    <div class="small">Kept for the record. A new move-out can be created from the lease.</div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($voidCheck)): ?>
        <div class="modal fade" id="voidMoveOutModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" onsubmit="event.preventDefault(); if (confirm('This cannot be undone. Continue?')) { const b = this.querySelector('button[type=submit]'); b.disabled = true; postAction({action: 'void_move_out', reason: this.reason.value}, 'Could not cancel the move-out').finally(() => { b.disabled = false; }); }">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-x-octagon text-danger"></i> <?= $voidLabel ?> #<?= $moveOutId ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($voidCheck['blockers']): ?>
                            <div class="alert alert-warning small mb-0"><b>It cannot be cancelled yet:</b>
                                <ul class="mb-0 ps-3"><?php foreach ($voidCheck['blockers'] as $b): ?><li><?= h($b) ?></li><?php endforeach; ?></ul></div>
                        <?php else: ?>
                            <p class="small mb-2">Use this when the move-out was made by mistake. It will:</p>
                            <ul class="small ps-3"><?php foreach ($voidCheck['effects'] as $ef): ?><li><?= h($ef) ?></li><?php endforeach; ?></ul>
                            <label class="form-label" for="voidMoReason">Reason <span class="text-danger">*</span></label>
                            <textarea id="voidMoReason" name="reason" class="form-control" rows="2" maxlength="500" required placeholder="e.g. Tenant renewed instead of moving out"></textarea>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <?php if (!$voidCheck['blockers']): ?>
                            <button type="submit" class="btn btn-danger"><i class="bi bi-x-octagon"></i> <?= $voidLabel ?></button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php
        // Milestones: Started -> Inspection Appointment -> Final Inspection -> Key Return -> Inspection Report
        //             -> Damages & Report -> Deposit -> Completed.
        // Sections carry data-ms="<index>".
        $moDone = $moveOut['status'] === 'completed';
        $moInspected = !empty($moveOut['inspection_completed']);
        $moDamagesDone = $moInspected && ((!$uncostedCount && !$mocSum['pending']) || $moDone);
        $moDepositDone = $moInspected && $settlementDone;
        $moFlags = [true, $moApptDone, $moInspected, $moKeysDone, $moReportDone, $moDamagesDone, $moDepositDone, $moDone];
        $moCurrent = array_search(false, $moFlags, true);
        $moState = static fn(int $n) => $moFlags[$n] ? 'done' : ($n === $moCurrent && $moveOut['status'] !== 'cancelled' ? 'current' : 'todo');
        $moItems = [
            ['label' => 'Process Initiated', 'icon' => 'bi-flag', 'state' => 'done', 'sub' => !empty($moveOut['notice_date']) ? 'Notice ' . date('M d', strtotime($moveOut['notice_date'])) : date('M d', strtotime($moveOut['created_at']))],
            ['label' => 'Inspection Schedule', 'icon' => 'bi-calendar-event', 'state' => $moApptMissed && !$moInspected ? 'warn' : $moState(1), 'sub' => $moApptMissed && !$moInspected ? 'Missed: reschedule' : (!empty($moveOut['inspection_scheduled_date']) ? date('M d', strtotime($moveOut['inspection_scheduled_date'])) . ($moveOut['inspection_time_from'] ?? null ? ' · ' . date('H:i', strtotime($moveOut['inspection_time_from'])) : '') : 'Not scheduled')],
            ['label' => 'Final Inspection', 'icon' => 'bi-clipboard-check', 'state' => $moState(2), 'sub' => $moInspected ? '' : ($moiItems ? ($moiCounts['total'] - $moiCounts['open']) . '/' . $moiCounts['total'] . ' checked' : '')],
            ['label' => 'Key Return', 'icon' => 'bi-key', 'state' => $moState(3), 'optional' => true, 'sub' => $mokKeys ? ($mokSum['missing'] ? rmok_missing_text($mokSum['missing']) . ' missing' : ($moKeysDone ? '' : ($mokSum['rows'] - $mokSum['pending']) . '/' . $mokSum['rows'] . ' entered')) : ''],
            ['label' => 'Inspection Report', 'icon' => 'bi-file-earmark-text', 'state' => $moState(4), 'optional' => true, 'sub' => $signedReports ? 'Signed copy uploaded' : ''],
            ['label' => 'Damages & Report', 'icon' => 'bi-exclamation-triangle', 'state' => $moState(5), 'optional' => $moInspected && !$uncostedCount && empty($mocSum['pending']), 'sub' => count($damages) ? count($damages) . ' damage(s)' : ''],
            ['label' => 'Deposit Settlement', 'icon' => 'bi-shield-check', 'state' => $moState(6), 'sub' => ''],
            ['label' => 'Completed', 'icon' => 'bi-house-door', 'state' => $moState(7), 'sub' => ''],
        ];
        $moSelected = $moveOut['status'] === 'cancelled' ? 0 : ($moCurrent === false ? 7 : (int)$moCurrent);
        $moHeads = [
            ['bi-flag', 'Process Initiated', 'Lease, tenant and notice details.'],
            ['bi-calendar-event', 'Inspection Schedule', 'Schedule the final inspection with the tenant: on the move-out day or another date. Send the tenant a reminder when needed.'],
            ['bi-clipboard-check', 'Final Inspection', 'Check every item of the checklist with the tenant.'],
            ['bi-key', 'Key Return', 'Check back the keys and access cards handed over at move-in.'],
            ['bi-file-earmark-text', 'Inspection Report', 'Form 1: the inspection report for the tenant to sign; upload the signed copy.'],
            ['bi-exclamation-triangle', 'Damages & Report', 'Damages, final meter readings and the move-out inspection form.'],
            ['bi-shield-check', 'Deposit Settlement', 'The deposit against the damages and charges. Accounts settles it; mark it complete here.'],
            ['bi-house-door', 'Completed', 'Close the move-out and the lease.'],
        ];
        echo re_milestones_html($moItems, $moSelected, 'Move-out milestones');
        // Next opens the following step only when this one does not hold it up:
        //  - Final Inspection gates everything after it (those sections need it)
        //  - Key Return and the signed Inspection Report can be done in any order (needed only to complete)
        //  - Damages & Report: the deposit cannot be prepared while a damage or charge has no cost
        //  - Deposit Settlement must be settled before the move-out can be completed
        $moReach = re_milestones_reach($moItems);
        $moHints = [
            1 => 'Schedule the inspection to continue',
            2 => 'Complete the final inspection to continue',
            5 => 'Enter a cost for every damage and charge (0 if none) to continue',
            6 => 'Mark the deposit settlement as complete to continue',
        ];
        foreach ($moHeads as $i => [$icon, $title, $text]) {
            echo re_milestone_head($i, count($moHeads), $icon, $title, $text, $moItems[$i]['state'], $moReach, $moHints[$i] ?? 'Finish this step to continue');
        }
        ?>

        <div class="row g-4">
            <!-- Left Column: Main Workflow -->
            <div class="col-lg-8 mo-main">
                <!-- Lease Information -->
                <div class="card mb-4" data-ms="0">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-text"></i> Lease Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <dl class="rm-dl">
                                    <dt>Lease Number</dt><dd><?= h($moveOut['lease_number']) ?></dd>
                                    <dt>Building</dt><dd><?= h($moveOut['building_name']) ?></dd>
                                    <dt>Unit</dt><dd><?= h($moveOut['unit_number']) ?> <span class="rm-cell-sub">(<?= h($moveOut['unit_type']) ?>)</span></dd>
                                    <dt>Monthly Rent</dt><dd><?= number_format($moveOut['monthly_rent'], 2) ?> AED</dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="rm-dl">
                                    <dt>Tenant</dt><dd><?= h($moveOut['first_name'] . ' ' . $moveOut['last_name']) ?></dd>
                                    <dt>Phone</dt><dd><?= h($moveOut['phone']) ?></dd>
                                    <dt>Email</dt><dd><?= h($moveOut['email']) ?></dd>
                                    <dt>Security Deposit</dt><dd><?= number_format($securityDeposit, 2) ?> AED</dd>
                                </dl>
                            </div>
                        </div>
                        <?php if ($moveOut['notice_date']): ?>
                            <hr>
                            <p class="rm-section-title">Notice</p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <dl class="rm-dl">
                                        <dt>Notice Date</dt><dd><?= date('M d, Y', strtotime($moveOut['notice_date'])) ?></dd>
                                        <dt>Notice Type</dt><dd><?= ucfirst($moveOut['notice_type']) ?></dd>
                                    </dl>
                                </div>
                                <div class="col-md-6">
                                    <dl class="rm-dl">
                                        <dt>Intended Move-Out</dt><dd><?= date('M d, Y', strtotime($moveOut['intended_move_out_date'])) ?></dd>
                                        <?php if ($moveOut['notice_reason']): ?>
                                            <dt>Reason</dt><dd><?= h($moveOut['notice_reason']) ?></dd>
                                        <?php endif; ?>
                                    </dl>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Inspection Appointment: on the move-out day or another date, emails and reminders -->
                <?php
                $apptReady = rmoa_ready($conn);
                $apptSet = !empty($moveOut['inspection_scheduled_date']);
                $apptLocked = !empty($moveOut['inspection_completed']) || in_array($moveOut['status'], ['completed', 'cancelled'], true);
                $apptStatus = $moveOut['inspection_appt_status'] ?? null;
                [$apptLabel, $apptTone] = $moveOut['inspection_completed'] ? ['Inspection done', 'success']
                    : (!$apptSet ? ['Not scheduled', 'secondary'] : ($apptStatus === 'missed' ? ['Missed', 'danger'] : ($apptStatus === 'rescheduled' ? ['Rescheduled', 'info'] : ['Scheduled', 'primary'])));
                $tenantEmail = trim((string)($moveOut['email'] ?? ''));
                $moDateTxt = !empty($moveOut['actual_move_out_date']) ? date('D d M Y', strtotime($moveOut['actual_move_out_date'])) : '';
                $apptHistory = $apptReady ? rmoa_history($conn, $moveOutId) : [];
                ?>
                <div class="card mb-4" id="moAppt" data-ms="1">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="bi bi-calendar-event"></i> Inspection Schedule</h5>
                        <?= re_move_ui_pill($apptLabel, $apptTone) ?>
                    </div>
                    <div class="card-body">
                        <?php if (!$apptReady): ?>
                            <div class="alert alert-warning py-2">Run <code>migrations/re_move_out_appointment.sql</code> to book the inspection with time window, emails and reminders.</div>
                            <?php if (!$apptSet && !$apptLocked): ?>
                                <form id="scheduleInspectionForm" class="d-flex gap-2 flex-wrap">
                                    <input type="date" name="inspection_date" class="form-control" style="max-width:220px" value="<?= h($moveOut['actual_move_out_date'] ?? '') ?>" required>
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check"></i> Schedule Inspection</button>
                                </form>
                            <?php elseif ($apptSet): ?>
                                <p class="mb-0"><strong>Scheduled:</strong> <?= date('M d, Y', strtotime($moveOut['inspection_scheduled_date'])) ?></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($apptSet): ?>
                                <div class="appt-summary <?= $apptStatus === 'missed' && !$apptLocked ? 'is-missed' : '' ?>">
                                    <div class="appt-cal" aria-hidden="true">
                                        <span class="appt-cal-m"><?= date('M', strtotime($moveOut['inspection_scheduled_date'])) ?></span>
                                        <span class="appt-cal-d"><?= date('d', strtotime($moveOut['inspection_scheduled_date'])) ?></span>
                                        <span class="appt-cal-w"><?= date('D', strtotime($moveOut['inspection_scheduled_date'])) ?></span>
                                    </div>
                                    <div class="appt-info">
                                        <div class="appt-when"><?= h(rmoa_when_text($moveOut)) ?></div>
                                        <div class="appt-meta">
                                            <span><i class="bi <?= ($moveOut['inspection_mode'] ?? '') === 'scheduled' ? 'bi-calendar2-week' : 'bi-box-arrow-right' ?>"></i> <?= ($moveOut['inspection_mode'] ?? '') === 'scheduled' ? 'Scheduled date' : 'On the move-out day' ?></span>
                                            <?php if ($tenantEmail !== ''): ?><span><i class="bi bi-envelope"></i> <?= h($tenantEmail) ?></span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($moveOut['inspection_access_note'])): ?><div class="appt-note"><i class="bi bi-info-circle"></i> <?= h($moveOut['inspection_access_note']) ?></div><?php endif; ?>
                                        <?php if ($apptStatus === 'missed' && !$apptLocked): ?><div class="appt-note text-danger"><i class="bi bi-exclamation-triangle"></i> The tenant missed this inspection. Reschedule it.</div><?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!$apptLocked): ?>
                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                        <?php if ($apptStatus !== 'missed'): ?>
                                            <button type="button" class="btn btn-sm btn-primary" onclick="apptEmailOpen('reminder', this)"><i class="bi bi-send"></i> Send reminder</button>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="apptEmailOpen('confirmation', this)"><i class="bi bi-calendar-plus"></i> Send schedule + calendar invite</button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#apptFormWrap"><i class="bi bi-arrow-repeat"></i> Reschedule</button>
                                        <?php if ($apptStatus !== 'missed'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="apptMissed()"><i class="bi bi-person-x"></i> Mark as missed</button>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Review the email before it goes out (inline): recipient, subject and message can be edited -->
                                    <div id="apptEmailPanel" class="appt-email-panel mt-3" hidden>
                                        <div class="appt-email-head">
                                            <div class="appt-email-title"><i class="bi bi-envelope-paper"></i> <span id="apptEmailTitle">Review the email</span></div>
                                            <button type="button" class="btn-close" aria-label="Close" onclick="apptEmailClose()"></button>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-12 col-md-5">
                                                <label class="form-label small mb-1" for="apptEmailTo">To</label>
                                                <input type="email" id="apptEmailTo" class="form-control" inputmode="email" autocomplete="email" required>
                                            </div>
                                            <div class="col-12 col-md-7">
                                                <label class="form-label small mb-1" for="apptEmailSubject">Subject</label>
                                                <input type="text" id="apptEmailSubject" class="form-control" maxlength="250">
                                            </div>
                                        </div>
                                        <label class="form-label small mb-1 mt-2">Message <span class="text-muted">· tap in the message to edit it</span></label>
                                        <div id="apptEmailBody" class="appt-email-body" contenteditable="true" spellcheck="true"></div>
                                        <div class="form-text" id="apptEmailAttach" hidden><i class="bi bi-paperclip"></i> The calendar invite (move-out-inspection.ics) is attached.</div>
                                        <div class="appt-email-tpl" id="apptEmailTpl" hidden>
                                            <span class="appt-email-tpl-state" id="apptEmailTplState"></span>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="apptTplSave(this)" title="Use this subject and message for every later email of this kind. Tenant name, date, time, unit, address, lease and note are filled in automatically.">
                                                <i class="bi bi-bookmark-plus"></i> Save as template
                                            </button>
                                            <button type="button" class="btn btn-sm btn-link text-decoration-none" id="apptTplResetBtn" onclick="apptTplReset(this)" hidden><i class="bi bi-arrow-counterclockwise"></i> Restore default template</button>
                                        </div>
                                        <div class="appt-email-actions">
                                            <button type="button" class="btn btn-link text-decoration-none px-0" onclick="apptEmailReset()"><i class="bi bi-arrow-counterclockwise"></i> Undo my edits</button>
                                            <div class="appt-email-actions-r">
                                                <button type="button" class="btn btn-outline-secondary" onclick="apptEmailClose()">Cancel</button>
                                                <button type="button" class="btn btn-success" id="apptEmailSend"><i class="bi bi-send-check"></i> Send email</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!$apptLocked): ?>
                                <div id="apptFormWrap" class="<?= $apptSet && $apptStatus !== 'missed' ? 'collapse' : '' ?> mt-3">
                                    <form id="apptForm" class="appt-form" onsubmit="event.preventDefault(); apptSave(this);">
                                        <div class="appt-choices">
                                            <label class="appt-choice">
                                                <input type="radio" name="mode" value="move_out_day" <?= ($moveOut['inspection_mode'] ?? 'move_out_day') !== 'scheduled' ? 'checked' : '' ?>>
                                                <span class="appt-choice-body">
                                                    <i class="bi bi-box-arrow-right"></i>
                                                    <strong>On the move-out day</strong>
                                                    <small><?= h($moDateTxt ?: 'The move-out date') ?> · usual, at handover</small>
                                                </span>
                                            </label>
                                            <label class="appt-choice">
                                                <input type="radio" name="mode" value="scheduled" <?= ($moveOut['inspection_mode'] ?? '') === 'scheduled' ? 'checked' : '' ?>>
                                                <span class="appt-choice-body">
                                                    <i class="bi bi-calendar2-week"></i>
                                                    <strong>Schedule a different date</strong>
                                                    <small>Pre-inspection or tenant request</small>
                                                </span>
                                            </label>
                                        </div>
                                        <div class="row g-2 mt-1">
                                            <div class="col-12 col-md-4 appt-date-field">
                                                <label class="form-label small mb-1">Inspection date</label>
                                                <input type="date" name="inspection_date" class="form-control" value="<?= h($moveOut['inspection_scheduled_date'] ?? $moveOut['actual_move_out_date'] ?? '') ?>">
                                            </div>
                                            <div class="col-6 col-md-4">
                                                <label class="form-label small mb-1">From</label>
                                                <input type="time" name="time_from" class="form-control" value="<?= h(!empty($moveOut['inspection_time_from']) ? date('H:i', strtotime($moveOut['inspection_time_from'])) : '10:00') ?>" required>
                                            </div>
                                            <div class="col-6 col-md-4">
                                                <label class="form-label small mb-1">To</label>
                                                <input type="time" name="time_to" class="form-control" value="<?= h(!empty($moveOut['inspection_time_to']) ? date('H:i', strtotime($moveOut['inspection_time_to'])) : '12:00') ?>" required>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small mb-1">Note for the tenant <span class="text-muted">(optional)</span></label>
                                                <input type="text" name="access_note" class="form-control" maxlength="255" value="<?= h($moveOut['inspection_access_note'] ?? '') ?>" placeholder="e.g. Please meet our team at the unit; bring all access cards">
                                            </div>
                                            <?php if ($apptSet): ?>
                                                <div class="col-12">
                                                    <label class="form-label small mb-1">Reason for rescheduling *</label>
                                                    <input type="text" name="reason" class="form-control" maxlength="300" placeholder="e.g. Tenant asked to move it to the afternoon" required>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-calendar-check"></i> <?= $apptSet ? 'Reschedule inspection' : 'Schedule inspection' ?></button>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <?php if ($apptHistory): ?>
                                <details class="appt-history mt-3">
                                    <summary><i class="bi bi-clock-history"></i> History (<?= count($apptHistory) ?>)</summary>
                                    <ul class="list-unstyled mb-0 mt-2">
                                        <?php foreach ($apptHistory as $hRow):
                                            $act = (string)$hRow['action'];
                                            $isEmail = strpos($act, 'email_') === 0;
                                            $label = $isEmail ? (RMOA_EMAIL_TYPES[substr($act, 6)] ?? 'Email') . ' to ' . $hRow['email_to'] . ((int)$hRow['email_ok'] === 1 ? '' : ' · failed')
                                                : ['scheduled' => 'Scheduled', 'rescheduled' => 'Rescheduled', 'missed' => 'Marked as missed', 'inspection_reopened' => 'Inspection reopened for changes'][$act] ?? $act;
                                            $whenTxt = $hRow['appt_date'] ? rmoa_when_text(['inspection_scheduled_date' => $hRow['appt_date'], 'inspection_time_from' => $hRow['time_from'], 'inspection_time_to' => $hRow['time_to']]) : ''; ?>
                                            <li class="appt-h-row">
                                                <i class="bi <?= $isEmail ? ((int)$hRow['email_ok'] === 1 ? 'bi-envelope-check text-success' : 'bi-envelope-x text-danger') : ($act === 'missed' ? 'bi-person-x text-danger' : 'bi-calendar-check text-primary') ?>"></i>
                                                <div>
                                                    <div><?= h($label) ?><?= !$isEmail && $whenTxt !== '' ? ' · ' . h($whenTxt) : '' ?></div>
                                                    <div class="rm-cell-sub"><?= date('M d, Y H:i', strtotime($hRow['created_at'])) ?><?= $hRow['by_name'] ? ' · ' . h($hRow['by_name']) : ' · automatic' ?><?= $hRow['note'] ? ' · ' . h($hRow['note']) : '' ?></div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Final Inspection -->
                <div class="card mb-4" data-ms="2">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-clipboard-check"></i> Final Inspection</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($moveOut['inspection_scheduled_date']): ?>
                            <p class="mb-2"><i class="bi bi-calendar-event text-primary"></i> <strong>Inspection schedule:</strong>
                                <?= h($apptReady ? rmoa_when_text($moveOut) : date('M d, Y', strtotime($moveOut['inspection_scheduled_date']))) ?></p>
                        <?php endif; ?>
                        
                        <?php if ($moiItems):
                            $moiOpen = $moiCounts['open']; ?>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2 mt-1">
                                <strong><i class="bi bi-list-check"></i> Inspection Checklist</strong>
                                <?= re_move_ui_pill(($moiCounts['total'] - $moiOpen) . '/' . $moiCounts['total'] . ' checked', $moiOpen ? 'warning' : 'success') ?>
                                <?php if ($moiCounts['damaged']): ?><?= re_move_ui_pill($moiCounts['damaged'] . ' damaged', 'danger') ?><?php endif; ?>
                                <?php if ($moiCounts['na']): ?><?= re_move_ui_pill($moiCounts['na'] . ' N/A', 'secondary') ?><?php endif; ?>
                            </div>
                            <?php if (!$moiLocked): ?>
                                <p class="rm-cell-sub mb-2">Mark every item <strong>OK</strong>, <strong>Damaged</strong> or <strong>N/A</strong>. Damaged records the damage and its repair cost in the Damage Assessment.</p>
                            <?php endif; ?>
                            <?php foreach ($moiItems as $secName => $sec):
                                $secTotal = count($sec['items']);
                                $secOpen = count(array_filter($sec['items'], fn($i) => $i['status'] === 'open'));
                                $secDamaged = count(array_filter($sec['items'], fn($i) => $i['status'] === 'damaged'));
                                $secComplete = $secOpen === 0; ?>
                                <details class="moi-sec <?= $secComplete ? 'complete' : '' ?>" <?= (!$secComplete && !$moiLocked) ? 'open' : '' ?>>
                                    <summary>
                                        <i class="bi <?= $secComplete ? 'bi-check-circle-fill text-success' : 'bi-clipboard-check' ?>"></i>
                                        <span><?= h($secName) ?></span>
                                        <?= re_move_ui_pill(($secTotal - $secOpen) . '/' . $secTotal, $secComplete ? 'success' : (($secTotal - $secOpen) ? 'info' : 'secondary')) ?>
                                        <?php if ($secDamaged): ?><?= re_move_ui_pill($secDamaged . ' damaged', 'danger') ?><?php endif; ?>
                                        <i class="bi bi-chevron-right chev"></i>
                                    </summary>
                                    <div class="moi-sec-body">
                                        <?php if (!$moiLocked && $secOpen): ?>
                                            <div class="moi-sec-tools">
                                                <button type="button" class="btn btn-sm btn-outline-success" onclick="moiPost('moi_section_ok', {section_name: <?= h(json_encode($secName)) ?>}, this)"><i class="bi bi-check2-all"></i> Mark the rest OK</button>
                                            </div>
                                        <?php endif; ?>
                                        <?php foreach ($sec['items'] as $it): $stt = $it['status']; $rem = (string)($it['remark'] ?? ''); ?>
                                            <div class="moi-item st-<?= h($stt) ?>">
                                                <div class="moi-item-text">
                                                    <div><?= h($it['item_text']) ?><?php if ((int)$it['is_custom'] === 1): ?> <span class="rm-pill rm-pill-secondary">Added</span><?php endif; ?></div>
                                                    <?php if ($stt === 'damaged' && $it['damage_id']): $dPhotos = count($photosByDamage[(int)$it['damage_id']] ?? []); ?>
                                                        <div class="moi-damage"><i class="bi bi-exclamation-triangle"></i> <?= h($it['damage_description']) ?> · <?= h(ucfirst((string)$it['damage_severity'])) ?> · <?= $it['damage_cost'] === null ? 'cost to be added in Damages &amp; Report' : number_format((float)$it['damage_cost'], 2) . ' AED' ?></div>
                                                        <?php if ($dPhotos || !$photosLocked): ?>
                                                            <a href="#moPhotos" class="moi-item-meta text-decoration-none" <?= $photosLocked ? '' : 'onclick="moPhotoFor(' . (int)$it['damage_id'] . ')"' ?>><i class="bi bi-camera"></i> <?= $dPhotos ? $dPhotos . ' photo(s)' : 'No photo yet' ?><?= $photosLocked ? '' : ' · Add photo' ?></a>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if (rms_item_kind((string)$it['item_text']) === 'photos'): ?>
                                                        <a href="#moPhotos" class="moi-item-meta text-decoration-none d-block"><i class="bi bi-camera"></i> <?= count($photos) ? count($photos) . ' photo(s) uploaded · view or add more' : 'Upload photos first' ?></a>
                                                    <?php endif; ?>
                                                    <?php if ($stt !== 'open'): ?>
                                                        <div class="moi-item-meta"><?= h(RMOI_STATUS_LABELS[$stt]) ?><?= !empty($it['checked_by_name']) ? ' · ' . h($it['checked_by_name']) : '' ?><?= $it['checked_at'] ? ' · ' . date('M d, Y H:i', strtotime($it['checked_at'])) : '' ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($rem !== ''): ?>
                                                        <div class="moi-item-meta"><i class="bi bi-chat-left-text"></i> <?= h($rem) ?>
                                                            <?php if (!$moiLocked): ?><button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="moiRemarkEdit(this)">Edit</button><?php endif; ?></div>
                                                    <?php elseif (!$moiLocked): ?>
                                                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" style="font-size:.78rem" onclick="moiRemarkEdit(this)"><i class="bi bi-plus"></i> Remark</button>
                                                    <?php endif; ?>
                                                    <?php if (!$moiLocked): ?>
                                                        <form class="moi-remark-form" hidden onsubmit="event.preventDefault(); moiPost('moi_remark', {item_id: <?= (int)$it['id'] ?>, remark: this.remark.value}, this.querySelector('button'));">
                                                            <input type="text" name="remark" class="form-control form-control-sm" maxlength="500" value="<?= h($rem) ?>" placeholder="Remark (optional)">
                                                            <button class="btn btn-sm btn-primary" type="submit">Save</button>
                                                            <button class="btn btn-sm btn-link text-muted" type="button" onclick="this.form.hidden = true">Cancel</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($moiLocked): ?>
                                                    <?= re_move_ui_pill(RMOI_STATUS_LABELS[$stt], ['ok' => 'success', 'damaged' => 'danger', 'na' => 'secondary', 'open' => 'warning'][$stt]) ?>
                                                <?php elseif ($stt === 'open'): ?>
                                                    <div class="moi-btns">
                                                        <button type="button" class="btn btn-outline-success" onclick="moiPost('moi_status', {item_id: <?= (int)$it['id'] ?>, status: 'ok'}, this)"><i class="bi bi-check-lg"></i> OK</button>
                                                        <?php if (rmoi_item_can_damage($it)): // walkthrough and photo items are OK or N/A only ?>
                                                        <button type="button" class="btn btn-outline-danger" onclick="moiDamage(<?= (int)$it['id'] ?>, <?= h(json_encode($secName)) ?>, <?= h(json_encode($it['item_text'])) ?>)"><i class="bi bi-exclamation-triangle"></i> Damaged</button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-outline-secondary" title="Not applicable to this unit" onclick="moiPost('moi_status', {item_id: <?= (int)$it['id'] ?>, status: 'na'}, this)">N/A</button>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="moi-btns">
                                                        <?= re_move_ui_pill(RMOI_STATUS_LABELS[$stt], ['ok' => 'success', 'damaged' => 'danger', 'na' => 'secondary'][$stt]) ?>
                                                        <button type="button" class="btn btn-link text-muted text-decoration-none p-0" title="Set back to not checked"
                                                            onclick="<?= $stt === 'damaged' ? "if (!confirm('Undo? The damage recorded for this item is removed from the Damage Assessment.')) return; " : '' ?>moiPost('moi_status', {item_id: <?= (int)$it['id'] ?>, status: 'open'}, this)"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (!$moiLocked): ?>
                                            <form class="moi-add" onsubmit="event.preventDefault(); moiPost('moi_add_item', {section_name: <?= h(json_encode($secName)) ?>, item_text: this.item_text.value}, this.querySelector('button'));">
                                                <input type="text" name="item_text" class="form-control form-control-sm" maxlength="255" placeholder="Add a checklist item to <?= h($secName) ?>" required>
                                                <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit"><i class="bi bi-plus"></i> Add item</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        <?php elseif (!$moiReady && !$moiLocked): ?>
                            <div class="alert alert-warning py-2">Run <code>migrations/re_move_out_inspection.sql</code> to use the inspection checklist.</div>
                        <?php endif; ?>

                        <?php if ($moveOut['inspection_scheduled_date'] && !$moveOut['inspection_completed']): ?>
                            <form id="completeInspectionForm" class="mt-3">
                                <div class="mb-3">
                                    <label class="form-label">Inspection Notes</label>
                                    <textarea name="inspection_notes" class="form-control" rows="4" 
                                              placeholder="Record any observations, damages, or issues found during inspection"><?= h($moveOut['final_inspection_notes'] ?? '') ?></textarea>
                                </div>
                                <button type="submit" class="btn btn-success" <?= ($moiItems && $moiCounts['open']) ? 'disabled' : '' ?>>
                                    <i class="bi bi-check-circle"></i> Complete Inspection
                                </button>
                                <?php if ($moiItems && $moiCounts['open']): ?>
                                    <div class="form-text"><?= (int)$moiCounts['open'] ?> checklist item(s) still to check.</div>
                                <?php endif; ?>
                            </form>
                        <?php elseif ($moveOut['inspection_completed']): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle"></i> Inspection completed on <?= date('M d, Y H:i', strtotime($moveOut['inspection_completed_at'])) ?>
                            </div>
                            <?php
                            $canReopenInsp = !in_array($moveOut['status'], ['completed', 'cancelled'], true) && !$settlementDone;
                            ?>
                            <?php if ($canReopenInsp): ?>
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="moInspReopen(<?= $signedReports ? 'true' : 'false' ?>)"><i class="bi bi-pencil-square"></i> Edit inspection</button>
                                    <span class="rm-cell-sub">Change checklist items, damages found or notes. Possible until the deposit settlement is marked complete.</span>
                                </div>
                            <?php elseif ($settlementDone && $moveOut['status'] !== 'completed'): ?>
                                <div class="rm-cell-sub mb-2"><i class="bi bi-lock"></i> Locked: the deposit settlement is marked complete.</div>
                            <?php endif; ?>
                            <?php if ($moveOut['final_inspection_notes']): ?>
                                <div class="mt-3">
                                    <strong>Inspection Notes:</strong>
                                    <p><?= nl2br(h($moveOut['final_inspection_notes'])) ?></p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Key Return: keys and access cards handed over at move-in -->
                <div class="card mb-4" id="moKeys" data-ms="3">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="bi bi-key"></i> Key Return</h5>
                        <?php if ($moKeysDone && !empty($moveOut['keys_returned_at'])): ?>
                            <?= re_move_ui_pill('Returned ' . date('M d, Y', strtotime($moveOut['keys_returned_at'])), 'success') ?>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!$mokReady): ?>
                            <div class="alert alert-warning py-2 mb-0">Run <code>migrations/re_move_out_keys.sql</code> to use the key return.</div>
                        <?php elseif (!$mokKeys): ?>
                            <?php if (!empty($moveOut['keys_returned'])): ?>
                                <p class="mb-0"><i class="bi bi-check-circle text-success"></i> Keys returned on <?= date('M d, Y H:i', strtotime($moveOut['keys_returned_at'])) ?>.</p>
                            <?php else: ?>
                                <p class="text-muted mb-0">No key list for this move-out.</p>
                            <?php endif; ?>
                        <?php else:
                            $mokEditable = !$moKeysDone && !$mokClosed; ?>
                            <p class="rm-cell-sub mb-2">
                                <?php if ($mokSource && array_filter($mokKeys, fn($k) => $k['issued_qty'] !== null)): ?>
                                    <i class="bi bi-box-arrow-in-right"></i> Handed over at move-in<?= !empty($mokSource['move_in_date']) ? ' on ' . date('M d, Y', strtotime($mokSource['move_in_date'])) : '' ?>
                                    (<a href="move_in_view.php?id=<?= (int)$mokSource['id'] ?>" target="_blank">Move-In #<?= (int)$mokSource['id'] ?></a><?= $mokSource['carried'] ? ', lease ' . h($mokSource['lease_number']) . ' — renewed since' : '' ?>).
                                <?php else: ?>
                                    <i class="bi bi-info-circle"></i> No keys were recorded at move-in. Enter what the tenant hands back.
                                <?php endif; ?>
                            </p>
                            <form id="moKeysForm">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle rm-table-stack mb-2">
                                        <thead><tr><th>Key / access</th><th class="text-center">Issued</th><th>Card / key no.</th><th style="width:110px">Returned</th><th>Note</th><th></th></tr></thead>
                                        <tbody>
                                        <?php foreach ($mokKeys as $k):
                                            $iss = $k['issued_qty'] === null ? null : (int)$k['issued_qty'];
                                            $ret = $k['returned_qty'] === null ? null : (int)$k['returned_qty'];
                                            $short = $iss !== null && $ret !== null && $ret < $iss; ?>
                                            <tr class="<?= $short ? 'table-danger' : '' ?>">
                                                <td data-label="Key / access"><strong><?= h($k['key_label']) ?></strong><?php if ((int)$k['is_custom'] === 1): ?> <span class="rm-pill rm-pill-secondary">Added</span><?php endif; ?></td>
                                                <td data-label="Issued" class="text-center"><?= $iss === null ? '<span class="rm-cell-sub">—</span>' : $iss ?></td>
                                                <td data-label="Card / key no."><span class="rm-cell-sub"><?= h($k['card_numbers'] ?: '—') ?></span></td>
                                                <td data-label="Returned">
                                                    <?php if ($mokEditable): ?>
                                                        <input type="number" min="0" max="99" step="1" class="form-control form-control-sm" name="returned[<?= (int)$k['id'] ?>]" data-label="<?= h($k['key_label']) ?>" value="<?= $ret === null ? '' : $ret ?>" placeholder="<?= $iss !== null ? $iss : '0' ?>">
                                                    <?php else: ?>
                                                        <strong class="<?= $short ? 'text-danger' : '' ?>"><?= $ret === null ? '—' : $ret ?></strong>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Note">
                                                    <?php if ($mokEditable): ?>
                                                        <input type="text" class="form-control form-control-sm" name="note[<?= (int)$k['id'] ?>]" value="<?= h($k['note'] ?? '') ?>" maxlength="255" placeholder="<?= $short ? 'e.g. tenant lost it' : 'optional' ?>">
                                                    <?php else: ?>
                                                        <span class="rm-cell-sub"><?= h($k['note'] ?: '') ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($mokEditable && (int)$k['is_custom'] === 1): ?>
                                                        <button type="button" class="btn btn-sm btn-link text-muted p-0" title="Remove" onclick="if (confirm('Remove this row?')) postAction({action: 'keys_remove', key_id: <?= (int)$k['id'] ?>}, 'Could not remove')"><i class="bi bi-x-lg"></i></button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ($mokEditable): ?>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <button type="button" class="btn btn-sm btn-outline-success" onclick="moKeysAllReturned()"><i class="bi bi-check2-all"></i> All returned as issued</button>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="moKeysSubmit('keys_save', this)"><i class="bi bi-save"></i> Save</button>
                                    </div>
                                    <div class="form-check mb-2" id="moKeysAckWrap" <?= $mokSum['missing'] ? '' : 'hidden' ?>>
                                        <input class="form-check-input" type="checkbox" name="ack_missing" value="1" id="moKeysAck">
                                        <label class="form-check-label" for="moKeysAck"><b>Acknowledge partial return</b><span id="moKeysAckOut"><?= $mokSum['missing'] ? ': ' . h(rmok_missing_text($mokSum['missing'])) . ' outstanding' : '' ?></span></label>
                                    </div>
                                    <button type="button" class="btn btn-success" onclick="moKeysSubmit('keys_confirm', this)"><i class="bi bi-check-circle"></i> Confirm Key Return</button>
                                <?php endif; ?>
                            </form>
                            <?php if ($mokEditable): ?>
                                <form class="d-flex gap-2 mt-3" onsubmit="event.preventDefault(); postAction({action: 'keys_add', key_label: this.key_label.value}, 'Could not add');">
                                    <input type="text" name="key_label" class="form-control form-control-sm" maxlength="100" placeholder="Add a key or access card, e.g. Mailbox Key" required>
                                    <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit"><i class="bi bi-plus"></i> Add</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($mokSum['missing'] && !$mokClosed): ?>
                                <div class="alert alert-danger py-2 mt-3 mb-0 d-flex flex-wrap align-items-center gap-2">
                                    <span><i class="bi bi-exclamation-triangle"></i> Missing: <strong><?= h(rmok_missing_text($mokSum['missing'])) ?></strong></span>
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-auto" onclick="moKeysCharge(<?= h(json_encode(rmok_missing_text($mokSum['missing']))) ?>)"><i class="bi bi-plus"></i> Charge as damage</button>
                                </div>
                            <?php endif; ?>
                            <?php if ($moKeysDone && !$mokClosed): ?>
                                <div class="mt-3"><button type="button" class="btn btn-sm btn-link text-muted p-0" onclick="if (confirm('Reopen the key return to change it?')) postAction({action: 'keys_undo'}, 'Could not reopen')"><i class="bi bi-arrow-counterclockwise"></i> Reopen key return</button></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Damage Assessment -->
                <?php if (!$moveOut['inspection_completed']): ?>
                    <div class="card mb-4" data-ms="4 5 6"><div class="card-body rm-cell-sub"><i class="bi bi-lock"></i> Complete the final inspection first (Final Inspection).</div></div>
                <?php endif; ?>
                <?php if ($moveOut['inspection_completed']): ?>
                <div class="card mb-4" data-ms="5">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Damage Assessment</h5>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addDamageModal" <?= $costEditable ? '' : 'disabled title="Locked: the deposit settlement is marked complete"' ?>>
                            <i class="bi bi-plus"></i> Add Damage
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($damages)): ?>
                            <p class="text-muted">No damages recorded.</p>
                        <?php else: ?>
                            <?php if ($uncostedCount): ?>
                                <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> <strong><?= $uncostedCount ?> damage(s) need a repair cost.</strong> Enter each cost below (0 if there is no charge). The deposit settlement can be prepared once every damage is costed.</div>
                            <?php endif; ?>
                            <?php foreach ($damages as $damage): $costPending = $damage['repair_cost'] === null; ?>
                                <div class="damage-item">
                                    <div class="d-flex justify-content-between flex-wrap gap-2">
                                        <div>
                                            <strong><?= h($damage['damage_description']) ?></strong>
                                            <?php if ($damage['room_area']): ?>
                                                <span class="badge bg-secondary ms-2"><?= h($damage['room_area']) ?></span>
                                            <?php endif; ?>
                                            <span class="badge bg-<?= 
                                                $damage['damage_type'] === 'severe' ? 'danger' : 
                                                ($damage['damage_type'] === 'major' ? 'warning' : 
                                                ($damage['damage_type'] === 'moderate' ? 'info' : 'secondary')) 
                                            ?> ms-2">
                                                <?= ucfirst($damage['damage_type']) ?>
                                            </span>
                                        </div>
                                        <?php if ($costEditable): ?>
                                            <div class="dmg-cost" data-damage="<?= (int)$damage['id'] ?>">
                                                <?php if (!$costPending): ?>
                                                    <!-- Saved: locked until the pencil is clicked, so it is not changed by accident -->
                                                    <div class="dmg-cost-view d-flex align-items-center gap-2">
                                                        <strong class="text-danger"><?= number_format((float)$damage['repair_cost'], 2) ?> AED</strong>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary dmg-cost-edit" title="Edit repair cost" aria-label="Edit repair cost" onclick="dmgCostEdit(this)"><i class="bi bi-pencil"></i></button>
                                                    </div>
                                                <?php endif; ?>
                                                <form class="dmg-cost-form d-flex align-items-center gap-1" <?= $costPending ? '' : 'hidden' ?> onsubmit="event.preventDefault(); postAction({action: 'update_damage_cost', damage_id: <?= (int)$damage['id'] ?>, repair_cost: this.repair_cost.value}, 'Could not save the cost');">
                                                    <div class="input-group input-group-sm" style="width:170px">
                                                        <input type="number" step="0.01" min="0" name="repair_cost" class="form-control <?= $costPending ? 'is-invalid' : '' ?>" value="<?= $costPending ? '' : h(number_format((float)$damage['repair_cost'], 2, '.', '')) ?>" data-saved="<?= $costPending ? '' : h(number_format((float)$damage['repair_cost'], 2, '.', '')) ?>" placeholder="Repair cost" required>
                                                        <span class="input-group-text">AED</span>
                                                    </div>
                                                    <button type="submit" class="btn btn-sm btn-primary" title="Save repair cost" aria-label="Save repair cost"><i class="bi bi-check2"></i></button>
                                                    <?php if (!$costPending): ?>
                                                        <button type="button" class="btn btn-sm btn-link text-muted px-1" title="Cancel" aria-label="Cancel" onclick="dmgCostCancel(this)"><i class="bi bi-x-lg"></i></button>
                                                    <?php endif; ?>
                                                </form>
                                            </div>
                                        <?php elseif ($costPending): ?>
                                            <span class="badge bg-warning text-dark">Cost needed</span>
                                        <?php else: ?>
                                            <strong class="text-danger"><?= number_format((float)$damage['repair_cost'], 2) ?> AED</strong>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($damage['notes']): ?>
                                        <p class="mb-0 mt-2"><small><?= h($damage['notes']) ?></small></p>
                                    <?php endif; ?>
                                    <?php $dPh = $photosByDamage[(int)$damage['id']] ?? []; ?>
                                    <div class="mo-thumbs">
                                        <?php foreach ($dPh as $p): ?>
                                            <a href="../../<?= h($p['photo_path']) ?>" target="_blank"><img src="../../<?= h($p['photo_path']) ?>" alt="Damage photo" loading="lazy"></a>
                                        <?php endforeach; ?>
                                        <?php if (!$photosLocked): ?>
                                            <a href="#moPhotos" class="btn btn-sm btn-outline-secondary align-self-center" onclick="moPhotoFor(<?= (int)$damage['id'] ?>)"><i class="bi bi-camera"></i> <?= $dPh ? 'Add photo' : 'Add a photo' ?></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <strong>Total Damage Assessment:</strong>
                                <strong class="text-danger"><?= number_format($damageTotal, 2) ?> AED<?= $uncostedCount ? ' <small class="text-muted fw-normal">(+ ' . $uncostedCount . ' not costed)</small>' : '' ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Deposit Settlement: breakdown + confirmation (Accounts settles it manually; nothing is posted from here) -->
                <?php if ($moveOut['inspection_completed']):
                    $stMoney = static fn($v) => number_format((float)$v, 2); ?>
                <div class="card mb-4" data-ms="6">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="bi bi-shield-check"></i> Deposit Settlement</h5>
                        <?= $settlementDone ? re_move_ui_pill('Complete', 'success') : re_move_ui_pill('Pending', 'warning') ?>
                    </div>
                    <div class="card-body">
                        <?php if ($stFig['deposit_lease_id'] !== (int)$moveOut['lease_id']):
                            $dl = re_sd_load_lease($conn, (int)$currentCompanyId, $stFig['deposit_lease_id']); ?>
                            <div class="rm-cell-sub mb-2"><i class="bi bi-arrow-repeat"></i> Renewed tenancy: the deposit is held on the first lease
                                <a href="lease_view.php?id=<?= (int)$stFig['deposit_lease_id'] ?>" target="_blank"><?= h($dl['lease_number'] ?? ('#' . $stFig['deposit_lease_id'])) ?></a></div>
                        <?php endif; ?>
                        <table class="dep-stmt mb-2">
                            <tr><td>Security deposit per lease</td><td><?= $stMoney($stFig['expected']) ?></td></tr>
                            <tr><td>Security deposit received</td><td><?= $stMoney($stFig['received']) ?></td></tr>
                            <?php foreach ($stFig['damages'] as [$label, $amt, $type]): ?>
                                <tr class="less"><td>Damage: <?= h($label) ?> <span class="rm-cell-sub">(<?= h($type) ?>)</span></td><td><?= $amt === null ? '<span class="badge bg-warning text-dark">not costed</span>' : '− ' . $stMoney($amt) ?></td></tr>
                            <?php endforeach; ?>
                            <?php foreach ($stFig['charges'] as [$label, $amt, $note]): ?>
                                <tr class="less"><td><?= h($label) ?><?= $note !== '' ? ' <span class="rm-cell-sub">(' . h($note) . ')</span>' : '' ?></td><td><?= $amt === null ? '<span class="badge bg-warning text-dark">not entered</span>' : '− ' . $stMoney($amt) ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (!$stFig['damages'] && !$stFig['charges']): ?>
                                <tr class="sub"><td colspan="2" style="text-align:left">No damages or charges.</td></tr>
                            <?php endif; ?>
                            <tr class="sub"><td>Total damages and charges</td><td><?= $stMoney($stFig['total']) ?></td></tr>
                            <tr class="total"><td>Refund due to the tenant</td><td><?= $stMoney($stFig['refund']) ?> AED</td></tr>
                            <?php if ($stFig['payable'] > 0): ?>
                                <tr class="excess"><td>Payable by the tenant (not covered by the deposit)</td><td><?= $stMoney($stFig['payable']) ?> AED</td></tr>
                            <?php endif; ?>
                        </table>
                        <?php $moSetUrl = 'move_out_settlement_form.php?id=' . $moveOutId; ?>
                        <div class="dep-step">
                            <span class="dep-step-n">1</span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">Send the settlement statement to Accounts</div>
                                <div class="rm-cell-sub mb-2">Form 2 · Deposit Settlement Statement: the breakdown above, for Accounts to book the refund or recovery.<?= $stFig['pending'] ? ' <span class="text-warning-emphasis">Marked DRAFT until every damage and charge has an amount.</span>' : '' ?></div>
                                <div class="d-flex flex-wrap gap-2">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= h($moSetUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Preview</a>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= h($moSetUrl . '&download=1') ?>"><i class="bi bi-download"></i> Download PDF</a>
                                </div>
                            </div>
                        </div>
                        <div class="dep-step">
                            <span class="dep-step-n">2</span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold mb-2">Mark complete once Accounts confirms it is booked</div>

                        <?php if (!rmst_ready($conn)): ?>
                            <div class="alert alert-warning py-2 mb-0">Run <code>migrations/re_move_out_settlement_confirm.sql</code> to mark the settlement as complete.</div>
                        <?php elseif ($settlementDone && !empty($moveOut['settlement_completed_at'])): ?>
                            <div class="alert alert-success d-flex justify-content-between align-items-start flex-wrap gap-2 mb-0">
                                <div><i class="bi bi-check-circle-fill"></i> Settlement complete<?= ' · ' . date('M d, Y H:i', strtotime($moveOut['settlement_completed_at'])) ?><?= $stByName ? ' by ' . h($stByName) : '' ?>
                                    <?php if (!empty($moveOut['settlement_note'])): ?><div class="small mt-1"><?= h($moveOut['settlement_note']) ?></div><?php endif; ?></div>
                                <?php if (rmv_can_manage($conn) && !in_array($moveOut['status'], ['completed', 'cancelled'], true)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="settlementReopen()"><i class="bi bi-arrow-counterclockwise"></i> Reopen</button>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($settlementDone): ?>
                            <div class="alert alert-success mb-0"><i class="bi bi-check-circle-fill"></i> Move-out completed.</div>
                        <?php elseif ($stFig['pending']): ?>
                            <div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle"></i> <?= (int)$stFig['pending'] ?> damage(s) / charge(s) still need an amount (Damages &amp; Report) before the settlement can be marked complete.</div>
                        <?php elseif (!in_array($moveOut['status'], ['completed', 'cancelled'], true)): ?>
                            <form id="settlementForm" class="d-flex gap-2 flex-wrap" onsubmit="event.preventDefault(); if (confirm('Mark the deposit settlement as complete? Damages and charges are locked after this.')) postAction({action: 'settlement_complete', note: this.note.value}, 'Could not save');">
                                <input type="text" name="note" class="form-control" style="max-width:420px" maxlength="500" placeholder="Note (optional), e.g. Refund paid by Accounts, ref 1234">
                                <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle"></i> Mark Settlement as Complete</button>
                            </form>
                        <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <!-- Standard Move-Out Charges: cleaning, painting, pest control, ... deducted from the deposit -->
                <?php if ($moveOut['inspection_completed']): ?>
                <div class="card mb-4" id="moCharges" data-ms="5">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="bi bi-brush"></i> Standard Move-Out Charges</h5>
                        <a href="move_out_charge_types.php" class="small text-decoration-none"><i class="bi bi-gear"></i> Edit the standard list</a>
                    </div>
                    <div class="card-body">
                        <?php if (!$mocReady): ?>
                            <div class="alert alert-warning py-2 mb-0">Run <code>migrations/re_move_out_charges.sql</code> to use standard move-out charges.</div>
                        <?php elseif (!$mocCharges && !$mocRemoved): ?>
                            <p class="text-muted mb-0">No standard charges for this move-out.</p>
                        <?php else: ?>
                            <p class="rm-cell-sub mb-2">Charges every move-out usually needs, deducted from the security deposit with the damages. Enter the amount for this unit, or remove a charge this unit does not need (only from this move-out).</p>
                            <?php if ($mocSum['pending'] && !$mocLocked): ?>
                                <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> <?= (int)$mocSum['pending'] ?> charge(s) still need an amount before the deposit settlement can be prepared.</div>
                            <?php endif; ?>
                            <form id="moChargesForm" oninput="if (event.target.matches('input[type=number]')) event.target.classList.toggle('is-invalid', event.target.value === '');" onsubmit="event.preventDefault(); const fd = new FormData(this); fd.append('action', 'charges_save'); postAction(fd, 'Could not save the charges');">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle rm-table-stack mb-2">
                                        <thead><tr><th>Charge</th><th style="width:170px">Amount (AED)</th><th>Note</th><th></th></tr></thead>
                                        <tbody>
                                        <?php foreach ($mocCharges as $c): $pending = $c['amount'] === null; ?>
                                            <tr>
                                                <td data-label="Charge"><strong><?= h($c['charge_name']) ?></strong><?php if ((int)$c['is_custom'] === 1): ?> <span class="rm-pill rm-pill-secondary">This move-out</span><?php endif; ?></td>
                                                <td data-label="Amount (AED)">
                                                    <?php if ($mocLocked): ?>
                                                        <strong><?= $pending ? '—' : number_format((float)$c['amount'], 2) ?></strong>
                                                    <?php elseif ($pending): ?>
                                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm is-invalid" name="amount[<?= (int)$c['id'] ?>]" value="" placeholder="Amount">
                                                    <?php else: ?>
                                                        <!-- Saved: locked until the pencil is clicked, so it is not changed by accident -->
                                                        <div class="moc-view d-flex align-items-center gap-2">
                                                            <strong><?= number_format((float)$c['amount'], 2) ?></strong>
                                                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit amount" aria-label="Edit <?= h($c['charge_name']) ?> amount" onclick="mocEdit(this)"><i class="bi bi-pencil"></i></button>
                                                        </div>
                                                        <div class="moc-edit d-flex align-items-center gap-1" hidden>
                                                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="amount[<?= (int)$c['id'] ?>]" value="<?= h(number_format((float)$c['amount'], 2, '.', '')) ?>" placeholder="Amount" disabled>
                                                            <button type="button" class="btn btn-sm btn-link text-muted px-1" title="Cancel" aria-label="Cancel" onclick="mocCancel(this)"><i class="bi bi-x-lg"></i></button>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Note">
                                                    <?php if ($mocLocked): ?>
                                                        <span class="rm-cell-sub"><?= h($c['note'] ?? '') ?></span>
                                                    <?php else: ?>
                                                        <?php if (!$pending): ?><span class="moc-view rm-cell-sub"><?= h($c['note'] ?? '') ?></span><?php endif; ?>
                                                        <input type="text" class="form-control form-control-sm <?= $pending ? '' : 'moc-edit' ?>" name="note[<?= (int)$c['id'] ?>]" value="<?= h($c['note'] ?? '') ?>" maxlength="255" placeholder="optional, e.g. 2 bedrooms + hall" <?= $pending ? '' : 'hidden disabled' ?>>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if (!$mocLocked): ?>
                                                        <button type="button" class="btn btn-sm btn-link text-muted p-0" title="Remove from this move-out only" onclick="if (confirm(<?= h(json_encode('Remove ' . $c['charge_name'] . ' from this move-out? The standard list is not changed.')) ?>)) postAction({action: 'charges_remove', charge_id: <?= (int)$c['id'] ?>}, 'Could not remove')"><i class="bi bi-x-lg"></i></button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                        <tfoot><tr><th class="text-end">Total</th><th><?= number_format($mocSum['total'], 2) ?> AED<?= $mocSum['pending'] ? ' <small class="text-muted fw-normal">(+ ' . (int)$mocSum['pending'] . ' not entered)</small>' : '' ?></th><th colspan="2"></th></tr></tfoot>
                                    </table>
                                </div>
                                <?php if (!$mocLocked && $mocCharges): ?>
                                    <button type="submit" id="mocSaveBtn" class="btn btn-sm btn-primary" <?= $mocSum['pending'] ? '' : 'hidden' ?>><i class="bi bi-save"></i> Save charges</button>
                                <?php endif; ?>
                            </form>
                            <?php if ($mocRemoved): ?>
                                <div class="rm-cell-sub mt-2"><i class="bi bi-dash-circle"></i> Removed from this move-out:
                                    <?php foreach ($mocRemoved as $rc): ?>
                                        <span class="rm-pill rm-pill-secondary ms-1"><?= h($rc['charge_name']) ?>
                                            <?php if (!$mocLocked): ?><a href="#" class="text-decoration-none ms-1" title="Restore" onclick="event.preventDefault(); postAction({action: 'charges_restore', charge_id: <?= (int)$rc['id'] ?>}, 'Could not restore')"><i class="bi bi-arrow-counterclockwise"></i></a><?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!$mocLocked): ?>
                                <form class="d-flex gap-2 mt-3" onsubmit="event.preventDefault(); postAction({action: 'charges_add', charge_name: this.charge_name.value}, 'Could not add');">
                                    <input type="text" name="charge_name" class="form-control form-control-sm" maxlength="100" placeholder="Add a charge for this move-out only, e.g. AC servicing" required>
                                    <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit"><i class="bi bi-plus"></i> Add</button>
                                </form>
                            <?php else: ?>
                                <div class="form-text mt-2"><i class="bi bi-lock"></i> Locked: the deposit settlement is marked complete.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Move-Out Inspection Form -->
                <?php
                $moFormPhotos = count($photos) + count(array_filter($damages, fn($d) => !empty($d['photo_path'])));
                $moFormUrl = 'move_out_form.php?id=' . $moveOutId;
                ?>
                <?php if ($moveOut['inspection_completed']): ?>
                <div class="card mb-4" data-ms="4">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Form 1 · Move-Out Inspection Report</h5>
                        <?= $signedReports ? re_move_ui_pill('Signed', 'success') : re_move_ui_pill('Waiting for signature', 'warning') ?>
                    </div>
                    <div class="card-body">
                        <?php
                        $moQ = '&photos=' . ($moFormPhotos ? 1 : 0) . '&meters=0';
                        $moSigReady = re_sig_ready($conn, 're_move_outs');
                        $moSignedOk = rmo_signed_ready($conn);
                        $latestReport = $signedReports[0] ?? null;
                        $supersededReports = $moSignedOk ? rmo_superseded_reports($conn, $moveOutId) : [];
                        // Include chips + Preview / Download
                        $moPrepare = static function () use ($moFormPhotos, $meterReadings, $moFormUrl, $moQ, $moveOutId) { ?>
                            <div class="rmf-chips" aria-label="Include in the report">
                                <label class="rmf-chip <?= $moFormPhotos ? '' : 'is-off' ?>"><input type="checkbox" class="mo-form-opt" id="moFormPhotos" data-opt="photos" <?= $moFormPhotos ? 'checked' : 'disabled' ?> onchange="moFormLinks()"><i class="bi bi-check-lg"></i>Photos<?= $moFormPhotos ? ' <em>' . (int)$moFormPhotos . '</em>' : '' ?></label>
                                <label class="rmf-chip <?= $meterReadings ? '' : 'is-off' ?>"><input type="checkbox" class="mo-form-opt" id="moFormMeters" data-opt="meters" <?= $meterReadings ? '' : 'disabled' ?> onchange="moFormLinks()"><i class="bi bi-check-lg"></i>Meter readings<?= $meterReadings ? ' <em>' . count($meterReadings) . '</em>' : '' ?></label>
                            </div>
                            <div class="rmf-row">
                                <a class="btn btn-sm btn-outline-primary" id="moFormPreview" data-base="<?= h($moFormUrl) ?>" href="<?= h($moFormUrl . $moQ) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Preview</a>
                                <a class="btn btn-sm btn-outline-primary" id="moFormDownload" data-base="<?= h($moFormUrl) ?>&download=1" href="<?= h($moFormUrl . '&download=1' . $moQ) ?>"><i class="bi bi-download"></i> Download</a>
                                <a href="form_letterhead.php?back=<?= urlencode('move_out_view.php?id=' . $moveOutId) ?>" class="rmf-link ms-auto"><i class="bi bi-layout-text-window"></i> Header &amp; footer</a>
                            </div>
                        <?php };
                        // Paper-signed scan upload (collapsed when digital signing is available)
                        $moUpload = static function () use ($moSigReady) { ?>
                            <div id="moSignedUpload" class="<?= $moSigReady ? 'collapse' : '' ?>">
                                <form id="moSignedForm" class="d-flex flex-wrap gap-2 mt-2">
                                    <input type="file" name="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
                                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-upload"></i> Upload scan</button>
                                </form>
                                <div class="form-text">PDF or a photo/scan of the paper-signed report, up to 10MB.</div>
                            </div>
                        <?php };
                        ?>

                        <?php if (!$moSignedOk): ?>
                            <div class="alert alert-warning py-2 mb-3">Run <code>migrations/re_move_out_signed_forms.sql</code> to keep the signed report.</div>
                        <?php endif; ?>
                        <?php if ($supersededReports && !$signedReports): ?>
                            <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle"></i> The inspection was changed after the tenant signed. Ask the tenant to sign the updated report.</div>
                        <?php endif; ?>

                        <?php if ($latestReport): ?>
                            <!-- Signed: the signed copy first, everything else folded away -->
                            <div class="rmf-signed">
                                <div class="rmf-signed-ic"><i class="bi bi-patch-check-fill"></i></div>
                                <div class="rmf-signed-txt">
                                    <div class="rmf-signed-t"><?= h($latestReport['photo_description'] ?: 'Signed report') ?></div>
                                    <div class="rm-cell-sub"><?= date('M d, Y · H:i', strtotime($latestReport['uploaded_at'])) ?><?= !empty($latestReport['uploaded_by_name']) ? ' · ' . h($latestReport['uploaded_by_name']) : '' ?></div>
                                </div>
                                <div class="rmf-row ms-auto">
                                    <a class="btn btn-sm btn-success" href="../../<?= h($latestReport['photo_path']) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> View</a>
                                    <a class="btn btn-sm btn-outline-success" href="../../<?= h($latestReport['photo_path']) ?>" download title="Download"><i class="bi bi-download"></i></a>
                                </div>
                            </div>
                            <p class="rm-cell-sub mt-2 mb-0">Costs and the deposit are in Form 2 (Completed step).</p>

                            <details class="rmf-more">
                                <summary><i class="bi bi-sliders"></i> Change the report or sign again <i class="bi bi-chevron-down ms-auto"></i></summary>
                                <div class="rmf-more-b">
                                    <div class="rmf-sub">Report contents</div>
                                    <?php $moPrepare(); ?>
                                    <?php if (!$photosLocked): ?>
                                        <div class="rmf-sub">Signature</div>
                                        <div class="rmf-row">
                                            <?php if ($moSigReady): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" onclick="moSign(this)"><i class="bi bi-pen"></i> Sign again</button>
                                            <?php endif; ?>
                                            <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#moSignedUpload" role="button"><i class="bi bi-upload"></i> Upload paper scan</a>
                                        </div>
                                        <?php $moUpload(); ?>
                                    <?php endif; ?>
                                    <div class="rmf-sub">Signed copies</div>
                                    <?php foreach ($signedReports as $sr): ?>
                                        <div class="rmf-file">
                                            <i class="bi bi-file-earmark-check"></i>
                                            <a href="../../<?= h($sr['photo_path']) ?>" target="_blank" rel="noopener"><?= h($sr['photo_description'] ?: 'Signed report') ?></a>
                                            <span class="rm-cell-sub"><?= date('M d, Y H:i', strtotime($sr['uploaded_at'])) ?></span>
                                            <?php if (!$photosLocked): ?>
                                                <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-auto" title="Remove" onclick="if (confirm('Remove this signed copy?')) postAction({action: 'delete_photo', photo_id: <?= (int)$sr['id'] ?>}, 'Could not remove')"><i class="bi bi-trash"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php foreach ($supersededReports as $sr): ?>
                                        <div class="rmf-file text-muted" title="Signed before the inspection was changed">
                                            <i class="bi bi-file-earmark-x text-muted"></i>
                                            <a href="../../<?= h($sr['photo_path']) ?>" target="_blank" rel="noopener" class="text-muted"><?= h($sr['photo_description'] ?: 'Signed report') ?></a>
                                            <span class="rm-cell-sub">Previous · <?= date('M d, Y H:i', strtotime($sr['uploaded_at'])) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php else: ?>
                            <!-- Not signed yet: prepare -> sign -->
                            <p class="rm-cell-sub mb-3">Keys returned, the inspection checklist, the damages found (no costs) and photos, for the tenant to sign after the inspection. Costs and the deposit are in Form 2 (Completed step).</p>
                            <div class="rmf-step">
                                <div class="rmf-step-n">1</div>
                                <div class="rmf-step-b">
                                    <div class="rmf-step-t">Prepare the report</div>
                                    <?php $moPrepare(); ?>
                                </div>
                            </div>
                            <div class="rmf-step">
                                <div class="rmf-step-n">2</div>
                                <div class="rmf-step-b">
                                    <div class="rmf-step-t">Tenant signs</div>
                                    <?php if (!$photosLocked && $moSignedOk): ?>
                                        <div class="rmf-row">
                                            <?php if ($moSigReady): ?>
                                                <button type="button" class="btn btn-success" onclick="moSign(this)"><i class="bi bi-pen"></i> Digital Sign</button>
                                            <?php else: ?>
                                                <span class="rm-cell-sub">Run <code>migrations/re_tenant_signatures.sql</code> to sign digitally.</span>
                                            <?php endif; ?>
                                            <a class="rmf-link" data-bs-toggle="collapse" href="#moSignedUpload" role="button"><i class="bi bi-upload"></i> Signed on paper? Upload the scan</a>
                                        </div>
                                        <?php $moUpload(); ?>
                                        <div class="form-text">Signing completes this step.</div>
                                    <?php else: ?>
                                        <span class="rm-cell-sub"><i class="bi bi-lock"></i> Signing is closed for this move-out.</span>
                                    <?php endif; ?>
                                    <?php foreach ($supersededReports as $sr): ?>
                                        <div class="rmf-file text-muted" title="Signed before the inspection was changed">
                                            <i class="bi bi-file-earmark-x text-muted"></i>
                                            <a href="../../<?= h($sr['photo_path']) ?>" target="_blank" rel="noopener" class="text-muted"><?= h($sr['photo_description'] ?: 'Signed report') ?></a>
                                            <span class="rm-cell-sub">Previous · <?= date('M d, Y H:i', strtotime($sr['uploaded_at'])) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Meter Readings -->
                <div class="card mb-4" data-ms="5">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-speedometer"></i> Final Meter Readings</h5>
                    </div>
                    <div class="card-body">
                        <form id="meterReadingForm" class="mb-3">
                            <div class="row g-2">
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <select name="meter_type" class="form-select form-select-sm" required>
                                        <option value="">Meter Type</option>
                                        <option value="electricity">Electricity</option>
                                        <option value="water">Water</option>
                                        <option value="gas">Gas</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-6 col-lg-2">
                                    <input type="number" step="0.01" name="reading_value" class="form-control form-control-sm" placeholder="Reading" required>
                                </div>
                                <div class="col-6 col-sm-6 col-lg-2">
                                    <input type="text" name="meter_number" class="form-control form-control-sm" placeholder="Meter #">
                                </div>
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes">
                                </div>
                                <div class="col-12 col-sm-6 col-lg-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <?php if (empty($meterReadings)): ?>
                            <p class="text-muted">No meter readings recorded yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                            <table class="table table-sm rm-table-stack">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Reading</th>
                                        <th>Meter #</th>
                                        <th>Date</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($meterReadings as $reading): ?>
                                        <tr>
                                            <td data-label="Type"><span class="rm-cell-main"><?= ucfirst($reading['meter_type']) ?></span></td>
                                            <td data-label="Reading"><?= number_format($reading['reading_value'], 2) ?></td>
                                            <td data-label="Meter #"><?= h($reading['meter_number'] ?: '-') ?></td>
                                            <td data-label="Date"><?= date('M d, Y', strtotime($reading['reading_date'])) ?></td>
                                            <td data-label="Notes"><?= h($reading['notes'] ?: '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Move-Out Photos: current condition and damages (last card of Damages & Report) -->
                <div class="card mb-4" id="moPhotos" data-ms="2 5">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="bi bi-camera"></i> Move-Out Photos</h5>
                        <span class="rm-cell-sub"><?= count($conditionPhotos) ?> condition · <?= count($photos) - count($conditionPhotos) ?> damage</span>
                    </div>
                    <div class="card-body">
                        <?php if (!$photosLocked): ?>
                            <form id="moPhotoForm" class="mb-3">
                                <div class="row g-2">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label small mb-1">Photos *</label>
                                        <input type="file" name="files[]" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp" multiple required>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label small mb-1">Photo of</label>
                                        <select name="damage_id" class="form-select form-select-sm">
                                            <option value="">Current condition</option>
                                            <?php foreach ($damages as $d): ?>
                                                <option value="<?= (int)$d['id'] ?>">Damage: <?= h(mb_strimwidth(($d['room_area'] ? $d['room_area'] . ' - ' : '') . $d['damage_description'], 0, 70, '…')) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label small mb-1">Room / area</label>
                                        <input type="text" name="room_area" class="form-control form-control-sm" maxlength="100" placeholder="e.g. Kitchen">
                                    </div>
                                    <div class="col-12 col-md-9">
                                        <input type="text" name="photo_description" class="form-control form-control-sm" maxlength="255" placeholder="Note (optional), e.g. wall behind the sofa">
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-upload"></i> Upload</button>
                                    </div>
                                </div>
                                <div class="form-text">JPG, PNG or WEBP, up to 10MB each. Several photos can be selected at once. Damage photos are shown with their damage and in the inspection report.</div>
                            </form>
                        <?php endif; ?>

                        <?php if (!$photos): ?>
                            <p class="text-muted mb-0">No photos uploaded yet.</p>
                        <?php else: ?>
                            <?php foreach (['Damage photos' => array_values(array_filter($photos, fn($p) => !empty($p['damage_id']))), 'Current condition' => $conditionPhotos] as $groupLabel => $group): if (!$group) continue; ?>
                                <h6 class="mt-2"><?= h($groupLabel) ?> <span class="rm-pill rm-pill-secondary"><?= count($group) ?></span></h6>
                                <div class="row g-2 mb-2">
                                    <?php foreach ($group as $photo):
                                        $dmg = null;
                                        foreach ($damages as $d) { if ((int)$d['id'] === (int)$photo['damage_id']) { $dmg = $d; break; } }
                                        $cap = trim(($photo['room_area'] ?: '') . ($dmg ? ' · ' . $dmg['damage_description'] : ''), ' ·'); ?>
                                        <div class="col-6 col-md-3">
                                            <div class="mo-photo <?= $dmg ? 'is-damage' : '' ?>">
                                                <a href="../../<?= h($photo['photo_path']) ?>" target="_blank">
                                                    <img src="../../<?= h($photo['photo_path']) ?>" alt="<?= h($photo['photo_description'] ?: 'Move-out photo') ?>" loading="lazy">
                                                </a>
                                                <div class="d-flex justify-content-between align-items-start gap-1 mt-1">
                                                    <div class="mo-photo-cap">
                                                        <div class="text-truncate"><?= h($cap !== '' ? $cap : date('M d, Y', strtotime($photo['uploaded_at']))) ?></div>
                                                        <?php if ($photo['photo_description'] && $photo['photo_description'] !== basename($photo['photo_path'])): ?><div class="text-truncate"><?= h($photo['photo_description']) ?></div><?php endif; ?>
                                                    </div>
                                                    <?php if (!$photosLocked): ?>
                                                        <button type="button" class="btn btn-sm btn-link text-danger p-0" title="Remove" onclick="if (confirm('Remove this photo?')) postAction({action: 'delete_photo', photo_id: <?= (int)$photo['id'] ?>}, 'Could not remove the photo')"><i class="bi bi-trash"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Milestone: Move-Out Completed -->
                <div class="card mb-4" data-ms="7">
                    <div class="card-body">
                        <div class="rmm-finish-list">
                            <?php foreach ([1 => 'Inspection Schedule', 2 => 'Final Inspection', 3 => 'Key Return', 4 => 'Inspection Report (signed)', 5 => 'Damages & Report', 6 => 'Deposit Settlement'] as $n => $label): $ok = $moFlags[$n]; ?>
                                <a href="#step-<?= $n ?>" class="rmm-finish-row <?= $ok ? 'ok' : '' ?>" onclick="event.preventDefault(); rmmSelect(<?= $n ?>); window.scrollTo({top: 0, behavior: 'smooth'});">
                                    <i class="bi <?= $ok ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i><span><?= h($label) ?></span><i class="bi bi-chevron-right ms-auto"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($moDone): ?>
                            <div class="alert alert-success mt-3 mb-0"><i class="bi bi-check-circle"></i> Move-out completed<?= !empty($moveOut['approved_at']) ? ' on ' . date('M d, Y H:i', strtotime($moveOut['approved_at'])) : '' ?><?= !empty($moveOut['approved_by_name']) ? ' by ' . h($moveOut['approved_by_name']) : '' ?>.</div>
                        <?php elseif ($moveOut['inspection_completed'] && $moKeysDone && $moReportDone && $settlementDone && !$uncostedCount && !$mocSum['pending']): ?>
                            <button class="btn btn-success btn-lg w-100 mt-3" data-bs-toggle="modal" data-bs-target="#completeMoveOutModal"><i class="bi bi-check-circle"></i> Complete Move-Out</button>
                        <?php else: ?>
                            <button class="btn btn-success btn-lg w-100 mt-3" disabled><i class="bi bi-check-circle"></i> Complete Move-Out</button>
                            <div class="form-text text-center">Available when the inspection is done, the keys are returned, the signed inspection report is uploaded, every damage and charge has an amount and the deposit settlement is marked complete.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Actions & Summary (Process Initiated and Completed steps only) -->
            <div class="col-lg-4 mo-side" data-ms="0 7">
              <div class="rm-sticky">
                <!-- Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-gear"></i> Actions</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($moveOut['status'] !== 'completed' && $moveOut['inspection_completed'] && $moKeysDone && $moReportDone && $settlementDone && !$uncostedCount && !$mocSum['pending']): ?>
                            <button class="btn btn-success w-100 mb-2" data-bs-toggle="modal" data-bs-target="#completeMoveOutModal">
                                <i class="bi bi-check-circle"></i> Complete Move-Out
                            </button>
                        <?php endif; ?>
                        <a href="lease_view.php?id=<?= $moveOut['lease_id'] ?>" class="btn btn-outline-primary w-100 mb-2">
                            <i class="bi bi-file-text"></i> View Lease
                        </a>
                        <a href="move_out.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>

                <!-- Summary -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Summary</h5>
                    </div>
                    <div class="card-body">
                        <dl class="rm-dl">
                            <dt>Created</dt><dd><?= date('M d, Y H:i', strtotime($moveOut['created_at'])) ?></dd>
                            <?php if ($moveOut['created_by_name']): ?>
                                <dt>Created By</dt><dd><?= h($moveOut['created_by_name']) ?></dd>
                            <?php endif; ?>
                            <?php if ($moveOut['approved_by_name']): ?>
                                <dt>Approved By</dt><dd><?= h($moveOut['approved_by_name']) ?></dd>
                                <dt>Approved At</dt><dd><?= date('M d, Y H:i', strtotime($moveOut['approved_at'])) ?></dd>
                            <?php endif; ?>
                        </dl>
                        <?php if ($moveOut['notes']): ?>
                            <hr>
                            <p class="rm-section-title">Notes</p>
                            <p class="mb-0"><?= nl2br(h($moveOut['notes'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
              </div>
            </div>
        </div>
</div>
    </div>

    <!-- Add Damage Modal -->
    <div class="modal fade" id="addDamageModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Damage</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addDamageForm">
                    <input type="hidden" name="inspection_item_id" value="">
                    <div class="modal-body">
                        <div class="alert alert-light border py-2 small" id="damageFromItem" hidden></div>
                        <div class="mb-3">
                            <label class="form-label">Damage Description *</label>
                            <textarea name="damage_description" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Room/Area</label>
                            <input type="text" name="room_area" class="form-control" placeholder="e.g., Living Room, Kitchen">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Damage Type *</label>
                            <select name="damage_type" class="form-select" required>
                                <option value="minor">Minor</option>
                                <option value="moderate">Moderate</option>
                                <option value="major">Major</option>
                                <option value="severe">Severe</option>
                            </select>
                        </div>
                        <div class="mb-3" id="damageCostWrap">
                            <label class="form-label">Repair Cost (AED)</label>
                            <input type="number" step="0.01" min="0" name="repair_cost" class="form-control">
                            <div class="form-text">Optional now: it can be entered later in Damages &amp; Report.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Damage</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Complete Move-Out: choose lease status -->
    <div class="modal fade" id="completeMoveOutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check-circle"></i> Complete Move-Out</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Lease <strong><?= h($moveOut['lease_number']) ?></strong>
                        · ends <?= $leaseEnd ? date('M d, Y', strtotime($leaseEnd)) : '-' ?>
                        · move-out <?= $moveOutDate ? date('M d, Y', strtotime($moveOutDate)) : '-' ?>
                    </p>
                    <?php if ($leaseIsActive): ?>
                        <label class="form-label fw-semibold">Lease status after move-out <span class="text-danger">*</span></label>
                        <div class="form-check border rounded p-2 ps-5 mb-2">
                            <input class="form-check-input" type="radio" name="lease_final_status" id="lfsExpired" value="expired" <?= $leftEarly ? 'disabled' : 'checked' ?>>
                            <label class="form-check-label" for="lfsExpired">
                                <strong>Expired</strong><br>
                                <small class="text-muted">The lease ran to its end date.</small>
                            </label>
                        </div>
                        <div class="form-check border rounded p-2 ps-5 mb-2">
                            <input class="form-check-input" type="radio" name="lease_final_status" id="lfsTerminated" value="terminated" <?= $leftEarly ? 'checked' : '' ?>>
                            <label class="form-check-label" for="lfsTerminated">
                                <strong>Terminated</strong><br>
                                <small class="text-muted">The tenant left before the end date.</small>
                            </label>
                        </div>
                        <?php if ($leftEarly): ?>
                            <div class="small text-muted mb-2"><i class="bi bi-lightbulb"></i> The move-out date is before the lease end date, so the lease must be terminated (Expired is not available).</div>
                        <?php endif; ?>
                        <div id="terminateFirstBox" class="alert alert-warning small mb-0 d-none">
                            A lease must be terminated through <strong>Terminate Lease</strong> first, so that returned cheques, invoices and revenue are handled.
                            After terminating, you will come back here to complete the move-out.
                            <?php if ($canTerminate): ?>
                                <div class="mt-2">
                                    <a href="<?= h($terminateUrl) ?>" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-x-octagon"></i> Open Terminate Lease (date <?= h(date('M d, Y', strtotime($terminationDate))) ?>)
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="mt-2">Only Owner or Admin can terminate a lease. Ask them to terminate it first.</div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info small mb-0">
                            The lease is already <strong><?= h(ucwords(str_replace('_', ' ', (string)$moveOut['lease_status']))) ?></strong>, so its status will not be changed.
                        </div>
                    <?php endif; ?>
                    <p class="small text-muted mt-3 mb-0">The unit will be set to <strong>Vacant</strong> (unless another active lease holds it).</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="btnConfirmCompleteMoveOut" onclick="completeMoveOut()">
                        <i class="bi bi-check2"></i> Complete Move-Out
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?= re_milestones_js() ?>
    <?= re_sig_modal_html() ?>
    <script>
        function moFormLinks() {
            let q = '';
            document.querySelectorAll('.mo-form-opt').forEach(cb => { q += '&' + cb.dataset.opt + '=' + (cb.checked ? 1 : 0); });
            ['moFormPreview', 'moFormDownload'].forEach(id => {
                const a = document.getElementById(id);
                if (a) a.href = a.dataset.base + q;
            });
        }

        const csrfToken = '<?= h(csrf_token()) ?>';
        const moveOutId = <?= $moveOutId ?>;

        // POST an action to this page. Shows the server's message on failure
        // (including non-JSON errors such as an expired session).
        function postAction(body, fallbackMsg, onSuccess) {
            if (!(body instanceof FormData)) {
                const fd = new FormData();
                Object.entries(body).forEach(([k, v]) => fd.append(k, v));
                body = fd;
            }
            body.append('_csrf', csrfToken);
            return fetch('', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(r => r.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error(text.trim() ? text.trim().slice(0, 200) : ('HTTP ' + r.status));
                    }
                }))
                .then(data => {
                    if (data.success) {
                        (onSuccess || (() => location.reload()))(data);
                    } else {
                        alert(data.message || fallbackMsg);
                    }
                })
                .catch(err => alert(fallbackMsg + ': ' + err.message));
        }

        function bindForm(id, action, fallbackMsg) {
            document.getElementById(id)?.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('action', action);
                postAction(formData, fallbackMsg);
            });
        }

        bindForm('scheduleInspectionForm', 'schedule_inspection', 'Error scheduling inspection');
        bindForm('completeInspectionForm', 'complete_inspection', 'Error completing inspection');
        bindForm('addDamageForm', 'add_damage', 'Error adding damage');

        // Inspection appointment
        function apptToggleDate() {
            const f = document.getElementById('apptForm');
            if (!f) return;
            const other = f.querySelector('input[name="mode"][value="scheduled"]').checked;
            const field = f.querySelector('.appt-date-field');
            field.style.display = other ? '' : 'none';
            f.inspection_date.required = other;
        }
        document.querySelectorAll('#apptForm input[name="mode"]').forEach(r => r.addEventListener('change', apptToggleDate));
        apptToggleDate();
        function apptSave(form) {
            const fd = new FormData(form);
            fd.append('action', 'appt_save');
            const btn = form.querySelector('button[type=submit]');
            btn.disabled = true;
            postAction(fd, 'Could not save the appointment').finally(() => { btn.disabled = false; });
        }
        // Review pop-up: load the email, let staff edit it, then send the edited version
        let apptEmailType = 'reminder', apptEmailOriginal = '';
        function apptEmailOpen(type, btn) {
            apptEmailType = type;
            const fd = new FormData();
            fd.append('action', 'appt_email_preview'); fd.append('type', type); fd.append('_csrf', csrfToken);
            if (btn) btn.disabled = true;
            fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) { alert(res.message || 'Could not prepare the email.'); return; }
                    document.getElementById('apptEmailTitle').textContent = type === 'confirmation' ? 'Review: inspection schedule with calendar invite' : 'Review: inspection reminder';
                    document.getElementById('apptEmailTo').value = res.to || '';
                    document.getElementById('apptEmailSubject').value = res.subject || '';
                    apptEmailOriginal = res.html || '';
                    document.getElementById('apptEmailBody').innerHTML = apptEmailOriginal;
                    document.getElementById('apptEmailAttach').hidden = !res.attachment;
                    apptTplShow(res.can_template, res.custom);
                    const panel = document.getElementById('apptEmailPanel');
                    panel.hidden = false;
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                })
                .catch(err => alert('Could not prepare the email: ' + err.message))
                .finally(() => { if (btn) btn.disabled = false; });
        }
        function apptTplShow(can, custom) {
            document.getElementById('apptEmailTpl').hidden = !can;
            document.getElementById('apptEmailTplState').innerHTML = custom
                ? '<i class="bi bi-bookmark-check-fill text-success"></i> Using your saved template'
                : '<i class="bi bi-bookmark"></i> Default wording';
            document.getElementById('apptTplResetBtn').hidden = !custom;
        }
        // Template calls stay on the page (no reload), so the edits in the panel are kept
        function apptTplPost(data, btn, onOk) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v));
            fd.append('_csrf', csrfToken);
            if (btn) btn.disabled = true;
            fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(res => { alert(res.message || (res.success ? 'Saved.' : 'Could not save.')); if (res.success && onOk) onOk(); })
                .catch(err => alert('Could not save: ' + err.message))
                .finally(() => { if (btn) btn.disabled = false; });
        }
        function apptTplSave(btn) {
            const body = document.getElementById('apptEmailBody');
            if (!body.textContent.trim()) { alert('The message is empty.'); return; }
            if (!confirm('Save this subject and message as the template for every later ' + (apptEmailType === 'confirmation' ? 'schedule email (with calendar invite)' : 'reminder email') + '?')) return;
            apptTplPost({ action: 'appt_tpl_save', type: apptEmailType, subject: document.getElementById('apptEmailSubject').value, html: body.innerHTML }, btn,
                () => { apptEmailOriginal = body.innerHTML; apptTplShow(true, true); });
        }
        function apptTplReset(btn) {
            if (!confirm('Remove your saved template and go back to the default wording for this email?')) return;
            apptTplPost({ action: 'appt_tpl_reset', type: apptEmailType }, btn, () => apptEmailOpen(apptEmailType));
        }
        function apptEmailClose() {
            document.getElementById('apptEmailPanel').hidden = true;
        }
        function apptEmailReset() {
            document.getElementById('apptEmailBody').innerHTML = apptEmailOriginal;
        }
        document.getElementById('apptEmailSend')?.addEventListener('click', function () {
            const to = document.getElementById('apptEmailTo').value.trim();
            if (!to) { alert('Enter the tenant email.'); return; }
            const body = document.getElementById('apptEmailBody');
            if (!body.textContent.trim()) { alert('The message is empty.'); return; }
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending';
            postAction({ action: 'appt_email', type: apptEmailType, to: to, subject: document.getElementById('apptEmailSubject').value, html: body.innerHTML }, 'Could not send the email')
                .finally(() => { this.disabled = false; this.innerHTML = '<i class="bi bi-send-check"></i> Send email'; });
        });
        function apptMissed() {
            const reason = prompt('The tenant missed the inspection appointment. Note (optional):', '');
            if (reason === null) return;
            postAction({ action: 'appt_missed', reason: reason }, 'Could not update the appointment');
        }

        // Key return
        function moKeysSubmit(action, btn) {
            const fd = new FormData(document.getElementById('moKeysForm'));
            fd.append('action', action);
            if (btn) btn.disabled = true;
            postAction(fd, 'Could not save the key return').finally(() => { if (btn) btn.disabled = false; });
        }
        // Fill every empty "Returned" with what was issued
        function moKeysAllReturned() {
            document.querySelectorAll('#moKeysForm input[name^="returned["]').forEach(inp => {
                if (inp.value === '' && inp.placeholder !== '') inp.value = inp.placeholder;
            });
        }
        // "Acknowledge partial return": shown, with what is outstanding, as soon as fewer come back than were issued
        document.getElementById('moKeysForm')?.addEventListener('input', () => {
            const out = [];
            document.querySelectorAll('#moKeysForm input[name^="returned["]').forEach(inp => {
                const issued = parseInt(inp.placeholder, 10);
                const back = parseInt(inp.value, 10);
                if (inp.value !== '' && !isNaN(issued) && back < issued) out.push((issued - back) + ' × ' + (inp.dataset.label || 'key'));
            });
            const wrap = document.getElementById('moKeysAckWrap');
            if (!wrap) return;
            wrap.hidden = out.length === 0;
            const txt = document.getElementById('moKeysAckOut');
            if (txt) txt.textContent = out.length ? ': ' + out.join(', ') + ' outstanding' : '';
            if (!out.length) { const cb = document.getElementById('moKeysAck'); if (cb) cb.checked = false; }
        });
        // Missing keys charged through Add Damage
        function moKeysCharge(missingText) {
            const form = document.getElementById('addDamageForm');
            form.reset();
            form.inspection_item_id.value = '';
            form.room_area.value = 'Keys & Access';
            form.damage_description.value = 'Keys / access cards not returned: ' + missingText;
            // Only the details here: the cost is priced in Damages & Report
            const note = document.getElementById('damageFromItem');
            note.textContent = 'Key return: ' + missingText + ' outstanding. The repair cost is added later in Damages & Report.';
            note.hidden = false;
            document.getElementById('damageCostWrap').hidden = true;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('addDamageModal')).show();
        }

        // Digital Sign (Form 1): the tenant signs in the pop-up; the signed report is generated and saved
        function moSign() {
            reSigOpen({
                title: 'Tenant signature · Move-Out Inspection Report',
                name: <?= json_encode(trim($moveOut['first_name'] . ' ' . $moveOut['last_name'])) ?>,
                onSave: (dataUrl, name, btn) => {
                    const fd = new FormData();
                    fd.append('action', 'sign_report');
                    fd.append('signature', dataUrl);
                    fd.append('signer_name', name);
                    fd.append('include_photos', document.getElementById('moFormPhotos')?.checked ? 1 : 0);
                    fd.append('include_meters', document.getElementById('moFormMeters')?.checked ? 1 : 0);
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving';
                    postAction(fd, 'Could not save the signature').finally(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle"></i> Sign &amp; save'; });
                }
            });
        }

        // Signed inspection report (Form 1)
        document.getElementById('moSignedForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'upload_signed_report');
            const btn = this.querySelector('button[type=submit]');
            btn.disabled = true;
            postAction(fd, 'Could not upload').finally(() => { btn.disabled = false; });
        });

        // Move-out photos
        document.getElementById('moPhotoForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'upload_photos');
            const btn = this.querySelector('button[type=submit]');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Uploading';
            postAction(fd, 'Could not upload').finally(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-upload"></i> Upload'; });
        });
        // "Add photo" on a damage: open the photos card with that damage chosen
        function moPhotoFor(damageId) {
            const form = document.getElementById('moPhotoForm');
            if (!form) return;
            form.damage_id.value = String(damageId);
            setTimeout(() => { document.getElementById('moPhotos').scrollIntoView({ behavior: 'smooth', block: 'start' }); form.querySelector('input[type=file]').focus(); }, 50);
        }

        // Reopen the completed final inspection for changes
        function moInspReopen(signed) {
            const msg = 'Reopen the final inspection to change it?'
                + (signed ? '\n\nThe tenant already signed the inspection report. That signed copy is kept as a previous version, and the tenant must sign the report again after the changes.' : '')
                + '\n\nReason for the change:';
            const reason = prompt(msg, '');
            if (reason === null) return;
            if (!reason.trim()) { alert('Enter the reason for changing the inspection.'); return; }
            postAction({ action: 'inspection_reopen', reason: reason.trim() }, 'Could not reopen the inspection');
        }

        // Damage repair cost: a saved cost is locked until the pencil is clicked
        function dmgCostEdit(btn) {
            const box = btn.closest('.dmg-cost');
            box.querySelector('.dmg-cost-view').hidden = true;
            const form = box.querySelector('.dmg-cost-form');
            form.hidden = false;
            form.repair_cost.focus();
            form.repair_cost.select();
        }
        function dmgCostCancel(btn) {
            const box = btn.closest('.dmg-cost');
            const form = box.querySelector('.dmg-cost-form');
            form.repair_cost.value = form.repair_cost.dataset.saved;
            form.hidden = true;
            box.querySelector('.dmg-cost-view').hidden = false;
        }

        // Standard charges: a saved amount is locked until the pencil is clicked
        function mocToggle(tr, editing) {
            tr.querySelectorAll('.moc-view').forEach(el => el.hidden = editing);
            tr.querySelectorAll('.moc-edit').forEach(el => {
                el.hidden = !editing;
                el.querySelectorAll('input').forEach(i => i.disabled = !editing);
                if (el.matches('input')) el.disabled = !editing;
            });
            const form = document.getElementById('moChargesForm');
            const save = document.getElementById('mocSaveBtn');
            if (save) save.hidden = !form.querySelector('input[type=number]:not([disabled])');
        }
        function mocEdit(btn) {
            const tr = btn.closest('tr');
            mocToggle(tr, true);
            const amt = tr.querySelector('.moc-edit input[type=number]');
            amt.focus();
            amt.select();
        }
        function mocCancel(btn) {
            const tr = btn.closest('tr');
            tr.querySelectorAll('.moc-edit input, input.moc-edit').forEach(i => { i.value = i.defaultValue; i.classList.remove('is-invalid'); });
            mocToggle(tr, false);
        }

        // Inspection checklist
        function moiPost(action, data, btn) {
            if (btn) btn.disabled = true;
            postAction(Object.assign({ action: action }, data), 'Could not save').finally(() => { if (btn) btn.disabled = false; });
        }
        function moiRemarkEdit(btn) {
            const form = btn.closest('.moi-item-text').querySelector('.moi-remark-form');
            if (form) { form.hidden = false; form.remark.focus(); }
        }
        // "Damaged" opens Add Damage for the item; saving it marks the item Damaged
        function moiDamage(itemId, section, text) {
            const form = document.getElementById('addDamageForm');
            form.reset();
            form.inspection_item_id.value = itemId;
            form.room_area.value = section;
            // The staff member describes the damage; the checklist item is shown above for reference
            form.damage_description.value = '';
            form.damage_description.placeholder = 'Describe the damage, e.g. hole in the bedroom wall near the window';
            const note = document.getElementById('damageFromItem');
            note.textContent = 'Checklist item: ' + section + ' - ' + text + '. The repair cost is added later in Damages & Report.';
            note.hidden = false;
            document.getElementById('damageCostWrap').hidden = true;
            const modalEl = document.getElementById('addDamageModal');
            modalEl.addEventListener('shown.bs.modal', () => form.damage_description.focus(), { once: true });
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
        document.getElementById('addDamageModal')?.addEventListener('hidden.bs.modal', () => {
            const form = document.getElementById('addDamageForm');
            form.inspection_item_id.value = '';
            document.getElementById('damageFromItem').hidden = true;
            document.getElementById('damageCostWrap').hidden = false;
        });
        bindForm('meterReadingForm', 'save_meter_reading', 'Error saving meter reading');

        function settlementReopen() {
            const reason = prompt('Reopen the deposit settlement? Damages and charges can be changed again.\n\nReason:');
            if (reason === null) return;
            postAction({ action: 'settlement_reopen', note: reason }, 'Could not reopen the settlement');
        }

        // Complete Move-Out modal: "Terminated" needs Terminate Lease first
        function syncFinalStatus() {
            const picked = document.querySelector('input[name="lease_final_status"]:checked');
            const isTerminated = picked && picked.value === 'terminated';
            document.getElementById('terminateFirstBox')?.classList.toggle('d-none', !isTerminated);
            const btn = document.getElementById('btnConfirmCompleteMoveOut');
            if (btn) btn.disabled = !!isTerminated;
        }
        document.querySelectorAll('input[name="lease_final_status"]').forEach(r => r.addEventListener('change', syncFinalStatus));
        syncFinalStatus();

        function completeMoveOut() {
            const picked = document.querySelector('input[name="lease_final_status"]:checked');
            const btn = document.getElementById('btnConfirmCompleteMoveOut');
            if (btn) btn.disabled = true;
            postAction(
                { action: 'complete_move_out', lease_final_status: picked ? picked.value : '' },
                'Error completing move-out',
                data => {
                    alert(data.message);
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        location.reload();
                    }
                }
            ).finally(() => { if (btn) syncFinalStatus(); });
        }
    </script>

<?= re_datepicker_assets() ?>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

