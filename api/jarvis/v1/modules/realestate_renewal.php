<?php
/**
 * Jarvis API v1 — a lease renewal run from Reem by the person who has it, step by step.
 *
 * Loaded only by ../act.php, after the action key has been checked and the acting user
 * found; opened directly it does nothing. $conn, $in (the JSON body), $action, $actorId,
 * $actorName and the jarvis_api_* helpers come from there.
 *
 * The renewal is saifsys's own workflow (re_lease_renewal_workflows) from the first offer,
 * so saifsys's renewal screens show where it is. Every action runs the renewal pages' own
 * code (modules/realestate/includes/renewal_service.php) and is logged on the workflow as
 * the person acting, "through Reem".
 *
 *   quote    what the renewal at this rent would be: the cheques one by one, as saifsys
 *            would make them on convert, and the charges. Saves nothing.
 *            Body: lease_id, end_date, rent, cheques.
 *   start    the offer went to the tenant: the workflow and its draft lease, made at this
 *            rent and marked notice_sent. The old Ejari number is not carried over. Safe
 *            to call again (Reem's open workflow for the lease is returned). A renewal the
 *            staff started themselves is left to them.
 *            Body: lease_id, end_date, rent, cheques.
 *   rent     a new rent on the open workflow (a counter-offer, the RERA index rent, or the
 *            rent agreed). Body: workflow_id, rent, cheques, note.
 *   status   the workflow moved on: negotiation, accepted or rejected.
 *            Body: workflow_id, status, note.
 *   note     a line on the workflow's log for an email out or in (Body: workflow_id, message),
 *            with the email itself added to its negotiation notes (text).
 *   file     a paper the tenant emailed (signed agreement, cheque photo), kept with the
 *            renewal's tenant uploads. Body: workflow_id, name, mime, data (base64).
 *   convert  Approve & Convert: the new lease, its installments, obligations and invoice
 *            candidates. Safe to call again. Body: workflow_id, expected_total (the cheques'
 *            total the tenant agreed to: a schedule that has changed since is refused).
 */

declare(strict_types=1);

if (!defined('JARVIS_ACT')) {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 4);
require_once $root . '/modules/realestate/includes/renewal_service.php';

const JARVIS_RN_EVENT = 'reem_started';

function jarvis_rn_fail(string $code, string $message, int $status = 400, array $extra = []): void
{
    jarvis_api_error($code, $message, $status, $extra);
}

function jarvis_rn_log(PDO $conn, int $workflowId, int $companyId, int $actorId, string $type, string $message): void
{
    renewal_wf_log_admin_event($conn, $workflowId, $companyId, $actorId, $type, $message . ' (through Reem)');
}

/** The lease, still ending when Reem thinks it does. */
function jarvis_rn_lease(PDO $conn, array $in): array
{
    $st = $conn->prepare("SELECT id, company_id, status, end_date, has_additional_parking, additional_parking_fee,
            has_additional_store, additional_store_fee FROM re_leases WHERE id = ? LIMIT 1");
    $st->execute([(int)($in['lease_id'] ?? 0)]);
    $lease = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lease) {
        jarvis_rn_fail('no_lease', 'That lease is not in saifsys.', 404);
    }
    if ((string)$lease['end_date'] !== (string)($in['end_date'] ?? '')) {
        jarvis_rn_fail('lease_changed', "The lease's end date in saifsys is {$lease['end_date']}, not " . ($in['end_date'] ?? '?') . '.', 409);
    }
    return $lease;
}

function jarvis_rn_rent(array $in): array
{
    $rent = round((float)($in['rent'] ?? 0), 2);
    $cheques = (int)($in['cheques'] ?? 0);
    if ($rent <= 0) {
        jarvis_rn_fail('bad_rent', 'rent must be the new annual rent.');
    }
    if ($cheques < 1 || $cheques > 12) {
        jarvis_rn_fail('bad_cheques', 'cheques must be 1 to 12.');
    }
    return [$rent, $cheques];
}

/** What re_renewal_create() is given: the rent and cheques, the old lease's extras, no old Ejari. */
function jarvis_rn_terms(array $lease, float $rent, int $cheques): array
{
    $parking = !empty($lease['has_additional_parking']) && (float)$lease['additional_parking_fee'] > 0;
    $store = !empty($lease['has_additional_store']) && (float)$lease['additional_store_fee'] > 0;
    return [
        'increase_type' => 'fixed',
        'increase_value' => 0,
        'proposed_rent' => $rent,
        'number_of_cheques' => $cheques,
        'additional_parking_enabled' => $parking,
        'additional_parking_fee' => $parking ? (float)$lease['additional_parking_fee'] : 0,
        'small_store_enabled' => $store,
        'small_store_fee' => $store ? (float)$lease['additional_store_fee'] : 0,
        'carry_ejari' => false,
    ];
}

/** The newest open workflow for a lease, and whether Reem started it. */
function jarvis_rn_open_for_lease(PDO $conn, int $leaseId): ?array
{
    $st = $conn->prepare("SELECT rw.id, rw.status, rw.new_lease_id,
            EXISTS (SELECT 1 FROM re_renewal_portal_events e WHERE e.workflow_id = rw.id AND e.event_type = ?) AS reem
        FROM re_lease_renewal_workflows rw
        WHERE rw.lease_id = ? AND rw.status <> 'rejected'
        ORDER BY rw.id DESC LIMIT 1");
    $st->execute([JARVIS_RN_EVENT, $leaseId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A workflow Reem started. */
function jarvis_rn_workflow(PDO $conn, array $in): array
{
    $st = $conn->prepare("SELECT rw.*, l.company_id,
            EXISTS (SELECT 1 FROM re_renewal_portal_events e WHERE e.workflow_id = rw.id AND e.event_type = ?) AS reem
        FROM re_lease_renewal_workflows rw JOIN re_leases l ON l.id = rw.lease_id WHERE rw.id = ? LIMIT 1");
    $st->execute([JARVIS_RN_EVENT, (int)($in['workflow_id'] ?? 0)]);
    $wf = $st->fetch(PDO::FETCH_ASSOC);
    if (!$wf) {
        jarvis_rn_fail('no_workflow', 'That renewal is not in saifsys.', 404);
    }
    if (!(int)$wf['reem']) {
        jarvis_rn_fail('staff_renewal', "Renewal #{$wf['id']} was started by the staff in saifsys. Leave it to them.", 409);
    }
    return $wf;
}

$note = mb_substr(trim((string)($in['note'] ?? '')), 0, 2000);

if ($action === 'quote' || $action === 'start') {
    $lease = jarvis_rn_lease($conn, $in);
    $companyId = (int)$lease['company_id'];
    [$rent, $cheques] = jarvis_rn_rent($in);
    $terms = jarvis_rn_terms($lease, $rent, $cheques);
    if ((string)$lease['status'] !== 'active') {
        jarvis_rn_fail('lease_not_active', 'That lease is no longer active in saifsys.', 409);
    }

    if ($action === 'quote') {
        try {
            $quote = re_renewal_quote($conn, $companyId, $actorId, (int)$lease['id'], $terms);
        } catch (Throwable $e) {
            jarvis_rn_fail('quote_failed', $e->getMessage(), 422);
        }
        jarvis_api_send(['ok' => true, 'quote' => $quote]);
    }

    $open = jarvis_rn_open_for_lease($conn, (int)$lease['id']);
    if ($open && !(int)$open['reem']) {
        jarvis_rn_fail('staff_renewal', "The staff already have a renewal for this lease in saifsys (#{$open['id']}, {$open['status']}). Leave it to them.", 409);
    }
    if ($open && $open['status'] !== 'converted') {
        jarvis_api_send(['ok' => true, 'renewal' => ['workflow_id' => (int)$open['id'], 'status' => $open['status'], 'already' => true]]);
    }

    $conn->beginTransaction();
    try {
        $made = re_renewal_create($conn, $companyId, $actorId, (int)$lease['id'], $terms);
        $conn->prepare("UPDATE re_lease_renewal_workflows SET status = 'notice_sent', notice_sent_at = NOW(), notice_sent_by = ? WHERE id = ?")
            ->execute([$actorId, $made['workflow_id']]);
        jarvis_rn_log($conn, $made['workflow_id'], $companyId, $actorId, JARVIS_RN_EVENT,
            "Renewal offer emailed to the tenant at AED {$made['proposed_rent']} a year, {$made['number_of_cheques']} cheques");
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        jarvis_rn_fail('start_failed', $e->getMessage(), 422);
    }
    jarvis_api_send(['ok' => true, 'renewal' => ['workflow_id' => (int)$made['workflow_id'], 'status' => 'notice_sent']]);
}

$wf = jarvis_rn_workflow($conn, $in);
$workflowId = (int)$wf['id'];
$companyId = (int)$wf['company_id'];

if ($action === 'rent') {
    [$rent, $cheques] = jarvis_rn_rent($in);
    try {
        $done = re_renewal_set_rent($conn, $companyId, $actorId, $workflowId, $rent, $cheques, $note);
    } catch (Throwable $e) {
        jarvis_rn_fail('rent_failed', $e->getMessage(), 422);
    }
    jarvis_rn_log($conn, $workflowId, $companyId, $actorId, 'reem_rent', "Rent now AED $rent a year, $cheques cheques" . ($note !== '' ? ": $note" : ''));
    jarvis_api_send(['ok' => true, 'renewal' => ['workflow_id' => $workflowId] + $done]);
}

if ($action === 'status') {
    $to = (string)($in['status'] ?? '');
    $from = (string)$wf['status'];
    if (renewal_wf_is_terminal($from) || $from === 'completed') {
        if ($from === $to) {
            jarvis_api_send(['ok' => true, 'renewal' => ['workflow_id' => $workflowId, 'status' => $to, 'already' => true]]);
        }
        jarvis_rn_fail('closed', "The renewal is already $from in saifsys.", 409);
    }
    if ($to === 'negotiation') {
        $conn->prepare("UPDATE re_lease_renewal_workflows SET status = 'negotiation' WHERE id = ?")->execute([$workflowId]);
    } elseif ($to === 'accepted') {
        $conn->prepare("UPDATE re_lease_renewal_workflows
            SET status = 'accepted', workflow_step = 'terms_negotiated', tenant_response = 'Accepted by email (Reem)', tenant_response_date = CURDATE()
            WHERE id = ?")->execute([$workflowId]);
    } elseif ($to === 'rejected') {
        $conn->prepare("UPDATE re_lease_renewal_workflows SET status = 'rejected', rejected_by = ?, workflow_step = 'cancelled' WHERE id = ?")
            ->execute([$actorId, $workflowId]);
    } else {
        jarvis_rn_fail('bad_status', 'status must be negotiation, accepted or rejected.');
    }
    if ($note !== '') {
        $notes = trim((string)($wf['negotiation_notes'] ?? '') . "\n" . date('Y-m-d') . ' (Reem): ' . $note);
        $conn->prepare("UPDATE re_lease_renewal_workflows SET negotiation_notes = ? WHERE id = ?")->execute([mb_substr($notes, -8000), $workflowId]);
    }
    jarvis_rn_log($conn, $workflowId, $companyId, $actorId, 'reem_' . $to, ucfirst($to) . ($note !== '' ? ": $note" : ''));
    jarvis_api_send(['ok' => true, 'renewal' => ['workflow_id' => $workflowId, 'status' => $to]]);
}

if ($action === 'convert') {
    if ($wf['status'] === 'converted') {
        jarvis_api_send(['ok' => true, 'renewal' => ['workflow_id' => $workflowId, 'new_lease_id' => (int)$wf['new_lease_id'], 'already' => true]]);
    }
    $expected = round((float)($in['expected_total'] ?? 0), 2);
    if ($expected <= 0) {
        jarvis_rn_fail('no_expected_total', 'expected_total — the cheques total the tenant agreed to — is required.');
    }
    // The schedule as it would be made now, compared before anything is kept.
    $conn->beginTransaction();
    try {
        $cheques = re_renewal_schedule($conn, $companyId, (int)$wf['new_lease_id'], $actorId);
    } finally {
        $conn->rollBack();
    }
    $total = round(array_sum(array_column($cheques, 'amount')), 2);
    if (abs($total - $expected) > 0.05) {
        jarvis_rn_fail('total_changed', "saifsys now makes the cheques AED $total in all, not AED $expected.", 409, ['cheques_total' => $total]);
    }
    try {
        $done = re_renewal_convert($conn, $companyId, $actorId, $workflowId, false);
    } catch (Throwable $e) {
        jarvis_rn_fail('convert_failed', $e->getMessage(), 422, ['workflow_id' => $workflowId]);
    }
    jarvis_rn_log($conn, $workflowId, $companyId, $actorId, 'reem_converted', 'Approved and converted');
    try {
        require_once $root . '/includes/audit_bridge.php';
        audit_bridge_re_ops($conn, $companyId, 'renewal_converted', 're_lease_renewal_workflows', $workflowId,
            'Renewal #' . $workflowId, 'Reem: ' . $done['message'], null,
            ['old_lease_id' => $done['old_lease_id'], 'new_lease_id' => $done['new_lease_id'], 'activate_now' => $done['activate_now'], 'by' => 'jarvis'],
            $actorId);
    } catch (Throwable $e) {
        error_log('jarvis renewal audit: ' . $e->getMessage());
    }
    jarvis_api_send(['ok' => true, 'renewal' => ['workflow_id' => $workflowId] + $done]);
}

if ($action === 'note') {
    $message = mb_substr(trim((string)($in['message'] ?? '')), 0, 500);
    if ($message === '') {
        jarvis_rn_fail('no_message', 'message is required.');
    }
    $text = mb_substr(trim((string)($in['text'] ?? '')), 0, 6000);
    if ($text !== '') {
        $notes = trim((string)($wf['negotiation_notes'] ?? '') . "\n\n--- " . date('Y-m-d H:i') . " · $message (Reem) ---\n" . $text);
        $conn->prepare("UPDATE re_lease_renewal_workflows SET negotiation_notes = ? WHERE id = ?")->execute([mb_substr($notes, -60000), $workflowId]);
    }
    jarvis_rn_log($conn, $workflowId, $companyId, $actorId, 'reem_email', $message);
    jarvis_api_send(['ok' => true]);
}

if ($action === 'file') {
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $mime = (string)($in['mime'] ?? '');
    $bytes = base64_decode((string)($in['data'] ?? ''), true);
    if (!isset($types[$mime]) || $bytes === false || $bytes === '' || strlen($bytes) > 10 * 1024 * 1024) {
        jarvis_rn_fail('bad_file', 'A JPG, PNG, WEBP or PDF of up to 10 MB is required.');
    }
    $orig = mb_substr(basename((string)($in['name'] ?? 'file')), 0, 200);
    $rel = 'uploads/renewal_tenant_docs/' . $companyId . '/' . $workflowId . '/reem_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $types[$mime];
    $dir = dirname($root . '/' . $rel);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        jarvis_rn_fail('no_folder', 'saifsys could not make the folder for the file.', 500);
    }
    if (file_put_contents($root . '/' . $rel, $bytes) === false) {
        jarvis_rn_fail('not_saved', 'saifsys could not save the file.', 500);
    }
    $conn->prepare("INSERT INTO re_renewal_tenant_uploads (workflow_id, company_id, legacy_user_id, document_type, original_filename, stored_path, mime_type, size_bytes)
        VALUES (?, ?, ?, 'other', ?, ?, ?, ?)")
        ->execute([$workflowId, $companyId, $actorId, $orig, $rel, $mime, strlen($bytes)]);
    jarvis_rn_log($conn, $workflowId, $companyId, $actorId, 'reem_file', "The tenant emailed $orig");
    jarvis_api_send(['ok' => true, 'path' => $rel]);
}

jarvis_rn_fail('not_found', 'Unknown action.', 404);
