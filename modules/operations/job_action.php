<?php
/**
 * Operations — single POST handler for every job action.
 * One entry point keeps the CSRF + scope + permission checks in one place.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_billing.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $opsBase . '/index.php');
    exit;
}

// An over-sized POST is not rejected by PHP — it is discarded. $_POST and
// $_FILES come back empty, which means the next line would fail its CSRF check
// and blame the wrong thing entirely. A message carrying a video is the way
// somebody actually gets here, so say what happened.
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if (!$_POST && !$_FILES && $contentLength > 0) {
    $limit = ops_max_request_bytes();
    ops_flash(
        'That message was too large for the server to accept'
        . ($limit > 0 ? ' (the limit is ' . (int)floor($limit / 1048576) . ' MB per message)' : '')
        . '. Send fewer or smaller attachments.',
        'danger'
    );
    header('Location: ' . $opsBase . '/index.php');
    exit;
}

csrf_verify();

$companyId = ops_company_id($conn);
$userId = (int)current_user_id();

$action = $_POST['action'] ?? '';
$jobId = (int)($_POST['job_id'] ?? 0);
$returnTo = (string)($_POST['return_to'] ?? '');

/** Only ever redirect inside this module. */
$redirect = function (string $fallback) use ($returnTo, $opsBase): void {
    $target = $fallback;
    if ($returnTo !== '' && strpos($returnTo, '/') === 0 && strpos($returnTo, '//') !== 0) {
        $target = $returnTo;
    }
    header('Location: ' . $target);
    exit;
};

$job = $jobId > 0 ? ops_load_job($conn, $jobId, $companyId) : null;
if (!$job) {
    ops_flash('That job could not be found.', 'danger');
    $redirect($opsBase . '/index.php');
}

$jobUrl = $opsBase . '/job_view.php?id=' . $jobId;

// The job's own company, for everything written to the job itself. Usually the
// selected company; not for a customer booking, which every company can open
// (ops_job_open_to_all_sql). Stock still moves from the selected company's store.
$jobCompanyId = (int)$job['company_id'];

switch ($action) {

    // -----------------------------------------------------------------------
    // Supervisor-only status changes
    // -----------------------------------------------------------------------
    // -----------------------------------------------------------------------
    // Invoice a finished job that did not invoice itself
    // -----------------------------------------------------------------------
    // The only billing button in the module, and it is a retry, not a step:
    // normally Finish on the phone already did this. It exists for the job
    // that finished before its building had a client, or hit an error.
    case 'retry_billing':
        $result = ops_bill_finished_job($conn, $jobId);
        if ($result['status'] === 'billed') {
            ops_flash('Invoice created.');
        } else {
            ops_flash($result['note'] ?: 'The invoice was not created.', $result['status'] === 'not_billable' ? 'info' : 'warning');
        }
        $redirect($jobUrl);
        break;

    case 'set_status':
        $status = (string)($_POST['status'] ?? '');
        if (!array_key_exists($status, ops_statuses())) {
            ops_flash('Unknown status.', 'danger');
            $redirect($jobUrl);
        }
        $stmt = $conn->prepare("
            UPDATE ops_jobs
            SET status = ?,
                finished_at = CASE WHEN ? = 'done' THEN COALESCE(finished_at, ?) ELSE finished_at END
            WHERE id = ? AND company_id = ?
        ");
        // PHP's clock — the live MySQL runs on UTC.
        $stmt->execute([$status, $status, date('Y-m-d H:i:s'), $jobId, $jobCompanyId]);
        // A pause only means something on a running job. Any other status the
        // office sets ends it, so the board never shows a closed job as paused.
        if ($status !== 'in_progress' && !empty($job['paused_at'])) {
            ops_job_close_pause($conn, $jobId, date('Y-m-d H:i:s'));
        }
        ops_flash('Status changed to "' . ops_status_label($status) . '".');
        $redirect($jobUrl);
        break;

    // No 'assign' action. Nobody in the office hands out work: a staff member
    // raises their own job or takes one from the Requests pool in the app.

    case 'delete_job':
        $stmt = $conn->prepare("DELETE FROM ops_jobs WHERE id = ? AND company_id = ?");
        $stmt->execute([$jobId, $jobCompanyId]);
        ops_flash('Job deleted.');
        $redirect($opsBase . '/index.php');
        break;

    // Photos belong to the field record: they are uploaded from the app and the
    // office cannot remove them, so what the staff member saw stays on the job.

    // -----------------------------------------------------------------------
    // Materials handed out for this job.
    //
    // There is no material request table and no approval step — the ask comes
    // in the conversation and this is the office answering it. One stock
    // movement carries the whole story: what left the store, for which job,
    // with the note the person types here.
    // -----------------------------------------------------------------------
    case 'use_stock':
        $itemId = (int)($_POST['item_id'] ?? 0);
        $qty    = abs((float)($_POST['qty'] ?? 0));
        $note   = trim((string)($_POST['note'] ?? '')) ?: null;

        // Photos or a clip of the handover, if any were attached. Voice is not
        // offered here — see ops_store_stock_move_media().
        $shots = ops_collect_comment_uploads($_FILES['media'] ?? null);

        if ($itemId <= 0) {
            ops_flash('Pick an item first.', 'warning');
            $redirect($jobUrl);
        }
        if (count($shots) > OPS_STOCK_MOVE_MAX_ATTACHMENTS) {
            ops_flash(
                'A movement can carry at most ' . OPS_STOCK_MOVE_MAX_ATTACHMENTS . ' photos or clips.',
                'warning'
            );
            $redirect($jobUrl);
        }

        $res = ops_move_stock($conn, $companyId, $itemId, -$qty, 'out', $jobId, $note, $userId);
        if (empty($res['ok'])) {
            ops_flash($res['error'], 'danger');
            $redirect($jobUrl);
        }

        // The movement is the record and it is already written. A photo that
        // will not save must not undo it — the material genuinely left the
        // shelf — so each file is stored on its own and whatever failed is
        // named, rather than the whole press being thrown away.
        $shotFailures = [];
        foreach ($shots as $file) {
            $kind = ops_comment_media_kind_for_extension(
                (string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)
            );
            if ($kind !== 'photo' && $kind !== 'video') {
                $shotFailures[] = ($file['name'] ?: 'A file') . ' — only photos and video can be attached here.';
                continue;
            }
            $saved = ops_store_stock_move_media(
                $conn, $companyId, (int)$res['move_id'], $jobId, $file, $kind, $userId
            );
            if (empty($saved['ok'])) {
                $shotFailures[] = ($file['name'] ?: 'A file') . ' — ' . ($saved['error'] ?? 'could not be saved.');
            }
        }

        // Handing the thing over is dealing with the ask, the same way a reply
        // is — so the job comes off the materials waiting list.
        $stmt = $conn->prepare("
            UPDATE ops_jobs SET needs_materials = 0
            WHERE id = ? AND company_id = ? AND needs_materials = 1
        ");
        $stmt->execute([$jobId, $jobCompanyId]);

        $done = $stmt->rowCount() > 0
            ? 'Taken out of stock for this job. The job is off the materials waiting list.'
            : 'Taken out of stock for this job.';

        if ($shotFailures) {
            ops_flash($done . ' Some attachments did not go: ' . implode(' ', $shotFailures), 'warning');
        } else {
            ops_flash($done);
        }
        $redirect($jobUrl);
        break;

    // -----------------------------------------------------------------------
    // Comments
    // -----------------------------------------------------------------------
    case 'add_comment':
        $comment = trim((string)($_POST['comment'] ?? ''));

        // Attachments, if any. The office sends the whole message in one
        // request — it is on a desk, on a wired line — where the field app
        // sends one file per request and ties them together with a group id.
        // Same table, same folder, same rules; only the delivery differs.
        $uploads = ops_collect_comment_uploads($_FILES['media'] ?? null);
        $durations = (array)($_POST['media_duration'] ?? []);

        if ($comment === '' && !$uploads) {
            ops_flash('Type a message or attach a file first.', 'warning');
            $redirect($jobUrl);
        }
        if (count($uploads) > OPS_COMMENT_MAX_ATTACHMENTS) {
            ops_flash('A message can carry at most ' . OPS_COMMENT_MAX_ATTACHMENTS . ' attachments.', 'warning');
            $redirect($jobUrl);
        }

        $stmt = $conn->prepare("
            INSERT INTO ops_job_comments (job_id, company_id, user_id, comment)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$jobId, $jobCompanyId, $userId, $comment]);
        $commentId = (int)$conn->lastInsertId();

        // One file failing does not lose the others or the words: each is
        // stored on its own and whatever did not make it is named in the flash,
        // so nobody is left guessing which of four photos went missing.
        $stored = 0;
        $failures = [];
        foreach ($uploads as $i => $file) {
            $kind = ops_comment_media_kind_for_extension(
                (string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)
            );
            if ($kind === null) {
                $failures[] = ($file['name'] ?: 'A file') . ' — that kind of file cannot be sent here.';
                continue;
            }
            // Posted alongside the file, so it is checked like any other input.
            $seconds = isset($durations[$i]) && is_scalar($durations[$i]) && $durations[$i] !== ''
                ? (int)$durations[$i]
                : null;
            $result = ops_store_comment_media(
                $conn, $jobCompanyId, $jobId, $commentId, $file, $kind, $userId, $seconds
            );
            if (!empty($result['ok'])) {
                $stored++;
            } else {
                $failures[] = ($file['name'] ?: 'A file') . ' — ' . ($result['error'] ?? 'could not be saved.');
            }
        }

        // A message that was nothing but its attachments, all of which failed,
        // is an empty row nobody wants in the thread.
        if ($comment === '' && $stored === 0) {
            $conn->prepare("DELETE FROM ops_job_comments WHERE id = ? AND company_id = ?")
                 ->execute([$commentId, $jobCompanyId]);
            ops_flash('Nothing was sent. ' . implode(' ', $failures), 'danger');
            $redirect($jobUrl);
        }

        // Someone on site asks for what they need in the conversation, which
        // raises ops_jobs.needs_materials and puts the job on the waiting list.
        // The office answering here is the act of dealing with it, so the reply
        // takes the flag back down — no separate "mark as sorted" step. The
        // thing itself still comes off the Stock page by hand; that movement is
        // the record. Only office replies land here: the field app posts its
        // messages through the API, so a technician cannot clear their own ask.
        $stmt = $conn->prepare("
            UPDATE ops_jobs SET needs_materials = 0
            WHERE id = ? AND company_id = ? AND needs_materials = 1
        ");
        $stmt->execute([$jobId, $jobCompanyId]);
        $wasWaiting = $stmt->rowCount() > 0;

        if ($failures) {
            ops_flash(
                'Message sent, but some attachments did not go: ' . implode(' ', $failures),
                'warning'
            );
        } else {
            ops_flash($wasWaiting
                ? 'Message sent. The job is off the materials waiting list.'
                : 'Message sent.');
        }
        $redirect($jobUrl);
        break;

    default:
        ops_flash('Unknown action.', 'danger');
        $redirect($opsBase . '/index.php');
}
