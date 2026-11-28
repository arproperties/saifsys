<?php
/**
 * Company's post-dated cheques — a register of the cheques the company gives (issued) or
 * receives, shown under Recurring Payments. Owner, Admin and HR (same as the loans pages).
 * Separate from Real Estate's tenant cheques (re_post_dated_cheques).
 *
 * saifsys only draws the screens. The cheques, their status history, the alert list, the
 * alert emails and the copies of the cheques are kept in the Reem app (server/companyPdc.js
 * there), one list per saifsys company. Every read and every change on these pages is one
 * call to Reem, through its door for saifsys: /api/from-saifsys/company-pdc. What Reem
 * refuses, it refuses with a sentence, and that sentence is what the page shows.
 *
 * Nothing is stored in the saifsys database any more: the old tables (lt_pdc, lt_pdc_history,
 * lt_pdc_alert_recipients, lt_pdc_alert_log, lt_pdc_alert_runs) are no longer read or written.
 *
 * The line to Reem, the employee-code lookup and the error class are Building Inventory's;
 * this module only names its own route.
 */

require_once __DIR__ . '/lt_helper.php';
require_once dirname(__DIR__, 2) . '/building_inventory/includes/binv_helper.php';

const LT_PDC_REEM_PATH = '/api/from-saifsys/company-pdc';
const LT_PDC_NAME = "Company's Post Dated Cheques";
const LT_PDC_FILE_MAX = 8 * 1024 * 1024; // Reem's own limit for a cheque copy

function lt_pdc_directions(): array
{
    return [
        'issued'   => ['label' => 'Issued',   'long' => 'Issued by us',  'party' => 'Paid to',       'icon' => 'bi-box-arrow-up-right'],
        'received' => ['label' => 'Received', 'long' => 'Received by us', 'party' => 'Received from', 'icon' => 'bi-box-arrow-in-down-left'],
    ];
}

/** Each status: label and badge colours. The list itself is Reem's; these are how saifsys shows it. */
function lt_pdc_statuses(): array
{
    return [
        'pending'   => ['label' => 'Pending',   'bg' => '#fef3c7', 'fg' => '#92400e'],
        'deposited' => ['label' => 'Deposited', 'bg' => '#dbeafe', 'fg' => '#1e40af'],
        'cleared'   => ['label' => 'Cleared',   'bg' => '#dcfce7', 'fg' => '#166534'],
        'on_hold'   => ['label' => 'On hold',   'bg' => '#ede9fe', 'fg' => '#5b21b6'],
        'bounced'   => ['label' => 'Bounced',   'bg' => '#fee2e2', 'fg' => '#991b1b'],
        'replaced'  => ['label' => 'Replaced',  'bg' => '#f3f4f6', 'fg' => '#374151'],
        'cancelled' => ['label' => 'Cancelled', 'bg' => '#f3f4f6', 'fg' => '#6b7280'],
        'other'     => ['label' => 'Other',     'bg' => '#e0f2fe', 'fg' => '#075985'],
    ];
}

/** A status as words: "Cleared", or the name typed for "Other" ("With legal"). */
function lt_pdc_label(string $status, ?string $other = null): string
{
    if ($status === 'other' && trim((string)$other) !== '') {
        return trim((string)$other);
    }
    return lt_pdc_statuses()[$status]['label'] ?? $status;
}

function lt_pdc_badge(string $status, ?string $other = null): string
{
    $s = lt_pdc_statuses()[$status] ?? ['bg' => '#f3f4f6', 'fg' => '#374151'];
    return '<span class="lt-badge" style="background:' . $s['bg'] . ';color:' . $s['fg'] . '">' . h(lt_pdc_label($status, $other)) . '</span>';
}

/**
 * The status dropdown: the usual statuses, the "Other" names used before (value "custom:Name"),
 * then "Other (type a new one)…" (value "other"), which shows the box named "{$name}_other".
 * Reem reads the same two values.
 */
function lt_pdc_status_options(array $customs, string $selected = '', ?string $selectedOther = null): string
{
    $html = '';
    foreach (lt_pdc_statuses() as $k => $st) {
        if ($k === 'other') { continue; }
        $html .= '<option value="' . h($k) . '"' . ($selected === $k ? ' selected' : '') . '>' . h($st['label']) . '</option>';
    }
    $found = false; // a name used before, picked again
    if ($customs) {
        $html .= '<optgroup label="Added before">';
        foreach ($customs as $c) {
            $on = $selected === 'other' && $selectedOther !== null && strcasecmp($selectedOther, $c) === 0;
            $found = $found || $on;
            $html .= '<option value="custom:' . h($c) . '"' . ($on ? ' selected' : '') . '>' . h($c) . '</option>';
        }
        $html .= '</optgroup>';
    }
    // "Other" stays picked when the form comes back with an error, so the name typed for it is not lost.
    return $html . '<option value="other"' . ($selected === 'other' && !$found ? ' selected' : '') . '>Other (type a new one)…</option>';
}

/** Today as the office has it (Dubai), whatever the server's clock is set to: Reem counts its days the same way. */
function lt_pdc_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
}

/** "in 3 days", "today", "5 days ago" for a pending cheque's date. */
function lt_pdc_when(string $date): array
{
    $days = (int)round((strtotime($date) - strtotime(lt_pdc_today())) / 86400);
    if ($days === 0) { return ['text' => 'today', 'class' => 'soon']; }
    if ($days < 0) { return ['text' => abs($days) . ' day' . ($days === -1 ? '' : 's') . ' ago', 'class' => 'late']; }
    if ($days <= 7) { return ['text' => 'in ' . $days . ' day' . ($days === 1 ? '' : 's'), 'class' => 'soon']; }
    return ['text' => '', 'class' => ''];
}

/* ---------------------------------------------------------------- The line to Reem */

/**
 * Every cheque page starts here, after the roles check: who is at the screen (their HR
 * employee code, which is how Reem knows them) and which company's list this is.
 *
 * @return array{company_id:int,company_name:string,code:string}
 */
function lt_pdc_boot(PDO $conn): array
{
    $companyId = lt_boot($conn);
    $q = $conn->prepare('SELECT name FROM companies WHERE id = ?');
    $q->execute([$companyId]);
    return [
        'company_id' => $companyId,
        'company_name' => trim((string)($q->fetchColumn() ?: '')),
        'code' => binv_employee_code($conn, (int)current_user_id()),
    ];
}

/**
 * One call to Reem's company cheques, for this company, as the person with this employee code.
 *
 * @param string     $path  '' for the list, '/cheques/7', '/cheques/7/status', '/alerts' ...
 * @param array|null $json  sent as the JSON body
 * @param array      $query extra query values (the list's filters)
 */
function lt_pdc_reem(array $who, string $method, string $path = '', ?array $json = null, array $query = [], int $timeout = 20): array
{
    $qs = http_build_query(['company' => (int)$who['company_id']] + $query);
    try {
        return binv_reem((string)$who['code'], $method, $path . '?' . $qs, $json, null, false, $timeout, LT_PDC_REEM_PATH);
    } catch (BinvError $e) {
        // Building Inventory's wording for a 404 is about buildings somebody keeps; here it is a cheque.
        if ($e->status === 404) {
            throw new BinvError('That cheque was not found. It may have been deleted.', 404);
        }
        // A Reem that does not have this feature yet answers "Please sign in": its door for these
        // cheques is not there, so the call lands on Reem's own screens. Say what is really wrong.
        if ($e->status === 401) {
            throw new BinvError("Reem did not let saifsys in for Company's Post Dated Cheques. Reem has to be updated with this feature first; if it has been, the door key (REEM_DOOR_KEY) does not match.", 401);
        }
        throw $e;
    }
}

/** A page that cannot go on: Reem's reason, in a plain block, and stop. */
function lt_pdc_stop(BinvError $e): void
{
    http_response_code(in_array($e->status, [401, 403, 404], true) ? 403 : 503);
    $back = function_exists('get_application_web_root') ? get_application_web_root() . '/modules/loan_tracker/index.php' : 'javascript:history.back()';
    echo '<div style="font-family:system-ui;padding:32px;max-width:640px">
            <h3>' . h(LT_PDC_NAME) . '</h3>
            <p>' . h($e->getMessage()) . '</p>
            <p><a href="' . h($back) . '">Back to Loan Tracker</a></p>
          </div>';
    exit;
}

/**
 * Give the cheque copy chosen on the form to a cheque in Reem. A picture is made ready the
 * way Building Inventory's photos are; a PDF goes as it is. A new copy replaces the old.
 *
 * @param array|null $file the $_FILES entry
 * @return string|null what went wrong, in a sentence, or null when it was kept (or none was chosen)
 */
function lt_pdc_send_file(array $who, int $id, ?array $file): ?string
{
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return in_array((int)$file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The cheque copy is bigger than the server allows.'
            : 'The cheque copy did not upload. Please attach it again.';
    }
    $src = (string)$file['tmp_name'];
    $send = null;
    try {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($src);
        if ($mime === 'application/pdf') {
            if ((int)filesize($src) > LT_PDC_FILE_MAX) {
                return 'The cheque copy must be 8 MB or smaller.';
            }
            $send = ['path' => $src, 'mime' => $mime, 'temp' => false];
        } elseif (isset(LT_FILE_TYPES[$mime])) {
            $send = binv_photo_prepare($src);
        } else {
            return 'The cheque copy must be a picture (JPG, PNG) or a PDF.';
        }
        $qs = http_build_query(['company' => (int)$who['company_id'], 'name' => mb_substr(basename((string)($file['name'] ?? '')), 0, 255)]);
        binv_reem((string)$who['code'], 'POST', '/cheques/' . $id . '/attachment?' . $qs, null, $send, false, 60, LT_PDC_REEM_PATH);
        return null;
    } catch (BinvError $e) {
        return $e->getMessage();
    } finally {
        if ($send && $send['temp']) {
            @unlink($send['path']);
        }
    }
}

/**
 * The copy of a cheque, as Reem keeps it: [the bytes, its type], or null when there is none
 * (or it is not one of the types a cheque copy can be).
 */
function lt_pdc_file(array $who, int $id): ?array
{
    try {
        [$bytes, $type] = binv_reem((string)$who['code'], 'GET', '/cheques/' . $id . '/attachment?company=' . (int)$who['company_id'],
            null, null, true, 30, LT_PDC_REEM_PATH);
    } catch (BinvError $e) {
        return null;
    }
    return isset(LT_FILE_TYPES[$type]) && $bytes !== '' ? [$bytes, $type] : null;
}
