<?php
/**
 * Recurring Payments — shared helpers.
 *
 * saifsys only draws the screens. The buildings and the accounts (the module's own
 * lists, nothing to do with any other building list or chart of accounts), the entries,
 * the monthly payments, the transfers and their files all live in the Reem app. Every read and every change on these pages is
 * one call to Reem, through its door for saifsys: /api/from-saifsys/recurring-payments.
 *
 * Nothing is stored in the saifsys database. Who may open the module is a role tick
 * here (it is the accountants'); everyone let in sees all of it. Every other rule is
 * Reem's: what it refuses, it refuses with a sentence, and that sentence is what the
 * page shows.
 *
 * The line to Reem, the employee-code lookup, the error class and the flash message
 * are Building Inventory's; this module only names its own route.
 */

require_once dirname(__DIR__, 2) . '/building_inventory/includes/binv_helper.php';

const RPAY_REEM_PATH = '/api/from-saifsys/recurring-payments';
const RPAY_NAME_MAX = 80;
const RPAY_TITLE_MAX = 120; // input lengths only, so the box stops where Reem would cut
const RPAY_UNIT_MAX = 80;
const RPAY_NOTES_MAX = 500;
const RPAY_FILE_MAX_BYTES = 8 * 1024 * 1024;
const RPAY_FILE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

/**
 * One call to Reem's recurring payments, as the person with this employee code.
 *
 * @param string     $path '' for a month's payments, '/entries', '/dues/7/paid' ...
 * @param array|null $json sent as the JSON body
 */
function rpay_reem(string $code, string $method, string $path = '', ?array $json = null, int $timeout = 20): array
{
    if (!binv_reem_configured()) {
        throw new BinvError('Recurrent Income is not connected to Reem yet: REEM_URL and REEM_DOOR_KEY are missing from config.php.', 503);
    }
    try {
        return binv_reem($code, $method, $path, $json, null, false, $timeout, RPAY_REEM_PATH);
    } catch (BinvError $e) {
        // Building Inventory's wording for a 404 is about buildings somebody keeps; here it is just gone.
        throw $e->status === 404 ? new BinvError('That could not be found. It may have been deleted.', 404) : $e;
    }
}

/**
 * Every page starts here: only a login whose role has Recurring Payments ticked goes on.
 * Returns the employee code Reem knows the person by.
 */
function rpay_boot(PDO $conn): string
{
    require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
    require_department_access(MODULE_RECURRING_PAYMENTS, DEPT_RECURRING_PAYMENTS, $conn);
    return binv_boot($conn)['code'];
}

/** A page that cannot go on: Reem's reason, in a plain block, and stop. */
function rpay_stop(BinvError $e): void
{
    http_response_code(in_array($e->status, [401, 403, 404], true) ? 403 : 503);
    $back = function_exists('get_application_web_root') ? get_application_web_root() . '/select-module.php' : 'javascript:history.back()';
    // Loan Tracker sits inside Recurring Payments but does not need Reem: keep it reachable.
    $loans = '';
    global $conn;
    if (function_exists('get_application_web_root') && function_exists('current_user_roles')
        && array_intersect(current_user_roles($conn instanceof PDO ? $conn : null), ['Owner', 'Admin', 'HR'])) {
        $loans = ' · <a href="' . h(get_application_web_root() . '/modules/loan_tracker/index.php') . '">Open Loan Tracker</a>';
    }
    echo '<div style="font-family:system-ui;padding:32px;max-width:640px">
            <h3>Recurrent Income</h3>
            <p>' . h($e->getMessage()) . '</p>
            <p><a href="' . h($back) . '">Back to modules</a>' . $loans . '</p>
          </div>';
    exit;
}

/**
 * A page with a file box was posted bigger than the server takes: PHP hands over nothing
 * at all, so say that instead of failing as a missing CSRF token.
 */
function rpay_too_big(string $backUrl): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        binv_flash('That attachment is bigger than the server allows. Nothing was saved.', 'danger');
        header('Location: ' . $backUrl);
        exit;
    }
}

/**
 * Give the file chosen on a form to a paid line or a transfer in Reem.
 * A picture is made ready the way Building Inventory's photos are; a PDF goes as it is.
 *
 * @param string     $path '/dues/7/attachment' or '/transfers/3/attachment'
 * @param array|null $file the $_FILES entry
 * @return string|null what went wrong, in a sentence; null when it was kept or no file was chosen
 */
function rpay_send_file(string $code, string $path, ?array $file): ?string
{
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return in_array((int)$file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The attachment is bigger than the server allows.'
            : 'The attachment did not upload. Please try again.';
    }
    $src = (string)$file['tmp_name'];
    $send = null;
    try {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($src);
        if ($mime === 'application/pdf') {
            if ((int)filesize($src) > RPAY_FILE_MAX_BYTES) {
                return 'The attachment must be 8 MB or smaller.';
            }
            $send = ['path' => $src, 'mime' => $mime, 'temp' => false];
        } elseif (in_array($mime, RPAY_FILE_TYPES, true)) {
            $send = binv_photo_prepare($src);
        } else {
            return 'The attachment must be a picture (JPG, PNG) or a PDF.';
        }
        binv_reem($code, 'POST', $path, null, $send, false, 60, RPAY_REEM_PATH);
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
 * The files of an upload box that takes several (name="files[]"), each as its own
 * single-file array, the way rpay_send_file() wants it. Empty boxes are left out.
 */
function rpay_upload_list(?array $files): array
{
    if (!$files || !isset($files['name'])) {
        return [];
    }
    if (!is_array($files['name'])) {
        return (int)($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
    }
    $out = [];
    foreach ($files['name'] as $i => $name) {
        if ((int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => $name, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '',
                  'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0];
    }
    return $out;
}

/**
 * Keep several files with one receipt. Reem gives a receipt one attachment of its own and
 * any number of further files: the first file here becomes its own when it has none yet
 * ($hasOwn false), every other one is added beside it. Nothing is replaced.
 * Returns [how many were kept, what was said about each one that was not].
 */
function rpay_send_receipt_files(string $code, int $receiptId, bool $hasOwn, array $files): array
{
    $kept = 0;
    $errors = [];
    foreach ($files as $file) {
        $error = rpay_send_file($code, '/receipts/' . $receiptId . ($hasOwn ? '/files' : '/attachment'), $file);
        if ($error === null) {
            $kept++;
            $hasOwn = true;
        } else {
            $errors[] = (string)($file['name'] ?? 'File') . ': ' . $error;
        }
    }
    return [$kept, $errors];
}

/** The link that shows the file of a paid line ('due') or of a transfer. */
function rpay_file_url(string $rpayBase, string $kind, int $id): string
{
    return $rpayBase . '/file.php?kind=' . $kind . '&id=' . $id;
}

/**
 * An entry's start as one date: its first month and its day ("2026-10", 5 is 2026-10-05).
 * The 29th to the 31st in a shorter month is that month's last day, as Reem has it.
 */
function rpay_start_date(string $month, int $day): string
{
    $d = DateTime::createFromFormat('!Y-m', $month);
    return $d ? $month . '-' . str_pad((string)max(1, min($day, (int)$d->format('t'))), 2, '0', STR_PAD_LEFT) : '';
}

/** 150 shows as "AED 150.00". */
function rpay_money($amount): string
{
    return 'AED ' . number_format((float)$amount, 2);
}

/** "2026-10" as "October 2026". Anything else comes back as it is. */
function rpay_month_label(string $month): string
{
    $d = DateTime::createFromFormat('!Y-m', $month);
    return $d ? $d->format('F Y') : $month;
}

/** "2026-10-05" as "05 Oct 2026". */
function rpay_date(?string $date): string
{
    $d = $date ? DateTime::createFromFormat('!Y-m-d', $date) : false;
    return $d ? $d->format('d M Y') : (string)$date;
}

/** The badge of a line: paid, partly paid or pending. */
function rpay_badge(array $d): string
{
    $paid = ($d['status'] ?? '') === 'paid';
    $partly = !$paid && (float)($d['received'] ?? 0) > 0;
    return '<span class="badge text-bg-' . ($paid ? 'success' : ($partly ? 'info' : 'warning')) . '">'
        . ($paid ? 'Paid' : ($partly ? 'Partly paid' : 'Pending')) . '</span>';
}

/**
 * The rental period a line pays for: from its due date to the day before the next month's
 * due date ("01 Nov 2026 – 30 Nov 2026", "30 Nov 2026 – 29 Dec 2026"). The next due date
 * uses $day, so an entry for the 31st runs 31 Jan – 27 Feb, then 28 Feb – 30 Mar.
 */
function rpay_rental_period(?string $dueDate, int $day): string
{
    $from = $dueDate ? DateTime::createFromFormat('!Y-m-d', $dueDate) : false;
    if (!$from) {
        return '';
    }
    $next = DateTime::createFromFormat('!Y-m-d', rpay_start_date((clone $from)->modify('first day of next month')->format('Y-m'), $day));
    $to = $next ? $next->modify('-1 day') : false;
    return rpay_date($from->format('Y-m-d')) . ' – ' . ($to ? rpay_date($to->format('Y-m-d')) : '');
}

/** The day a line's rental period runs from: an automatic entry's own day; a line made by hand, its own date. */
function rpay_period_day(array $due, ?array $entry): int
{
    return !empty($entry['auto_create']) && (int)($entry['day'] ?? 0) > 0
        ? (int)$entry['day']
        : (int)substr((string)($due['due_date'] ?? ''), 8, 2);
}

/**
 * The rental periods typed by hand for these lines, from saifsys's own table
 * (migrations/rpay_due_periods.sql): due id => ['from' => 'Y-m-d', 'to' => 'Y-m-d'].
 * The only thing this module keeps in saifsys; the lines themselves are Reem's.
 */
function rpay_saved_periods(PDO $conn, array $dueIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $dueIds))));
    if (!$ids) {
        return [];
    }
    try {
        $stmt = $conn->prepare('SELECT due_id, period_from, period_to FROM rpay_due_periods WHERE due_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
    } catch (PDOException $e) {
        error_log('recurrent income: rpay_due_periods could not be read: ' . $e->getMessage());
        return []; // no table yet: every period is worked out, as before
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['due_id']] = ['from' => $r['period_from'], 'to' => $r['period_to']];
    }
    return $out;
}

/** Keep a line's rental period, or forget it ($from and $to null) so it is worked out again. */
function rpay_save_period(PDO $conn, int $dueId, ?string $from, ?string $to, int $userId): void
{
    if ($from === null || $to === null) {
        $conn->prepare('DELETE FROM rpay_due_periods WHERE due_id = ?')->execute([$dueId]);
        return;
    }
    $conn->prepare('INSERT INTO rpay_due_periods (due_id, period_from, period_to, updated_by) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE period_from = VALUES(period_from), period_to = VALUES(period_to), updated_by = VALUES(updated_by)')
        ->execute([$dueId, $from, $to, $userId ?: null]);
}

/**
 * A line's rental period to show: the one typed by hand if there is one, else worked out.
 * Returns ['text' => '01 Nov 2026 – 30 Nov 2026', 'from' => ..., 'to' => ..., 'edited' => bool].
 */
function rpay_line_period(array $due, ?array $entry, array $saved): array
{
    $id = (int)($due['id'] ?? 0);
    if (isset($saved[$id])) {
        $s = $saved[$id];
        return ['text' => rpay_date($s['from']) . ' – ' . rpay_date($s['to']), 'from' => $s['from'], 'to' => $s['to'], 'edited' => true];
    }
    $text = rpay_rental_period($due['due_date'] ?? null, rpay_period_day($due, $entry));
    $from = (string)($due['due_date'] ?? '');
    $to = '';
    $parts = explode(' – ', $text);
    if (count($parts) === 2 && ($d = DateTime::createFromFormat('!d M Y', $parts[1]))) {
        $to = $d->format('Y-m-d');
    }
    return ['text' => $text, 'from' => $from, 'to' => $to, 'edited' => false];
}

const RPAY_ENTRY_NOTE_MAX = 2000;

/**
 * The notes kept in saifsys for these entries (migrations/rpay_entry_notes.sql), newest
 * first: entry id => [['id', 'note', 'by', 'by_id', 'at'], ...]. No table yet: none.
 */
function rpay_entry_notes(PDO $conn, array $entryIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
    if (!$ids) {
        return [];
    }
    try {
        $stmt = $conn->prepare('SELECT n.id, n.entry_id, n.due_id, n.note, n.created_by, n.created_at, u.fullname
            FROM rpay_entry_notes n LEFT JOIN `user` u ON u.id = n.created_by
            WHERE n.entry_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY n.created_at DESC, n.id DESC');
        $stmt->execute($ids);
    } catch (PDOException $e) {
        error_log('recurrent income: rpay_entry_notes could not be read: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['entry_id']][] = [
            'id' => (int)$r['id'], 'due_id' => $r['due_id'] !== null ? (int)$r['due_id'] : null, 'note' => (string)$r['note'], 'by' => (string)($r['fullname'] ?? ''),
            'by_id' => (int)$r['created_by'], 'at' => (string)$r['created_at'],
        ];
    }
    return $out;
}

/** Add a note to an entry. Returns what went wrong, or null when it was kept. */
function rpay_add_entry_note(PDO $conn, int $entryId, string $note, int $userId, ?int $dueId = null): ?string
{
    $note = trim(str_replace("\r\n", "\n", $note));
    if ($note === '') {
        return 'Type the note first.';
    }
    if (mb_strlen($note) > RPAY_ENTRY_NOTE_MAX) {
        return 'A note can be ' . RPAY_ENTRY_NOTE_MAX . ' letters at most.';
    }
    $conn->prepare('INSERT INTO rpay_entry_notes (entry_id, due_id, note, created_by) VALUES (?, ?, ?, ?)')->execute([$entryId, $dueId ?: null, $note, $userId ?: null]);
    return null;
}

/** Delete one note of an entry; only the person who wrote it, or an Owner or Admin. */
function rpay_delete_entry_note(PDO $conn, int $entryId, int $noteId, int $userId): ?string
{
    $stmt = $conn->prepare('SELECT created_by FROM rpay_entry_notes WHERE id = ? AND entry_id = ?');
    $stmt->execute([$noteId, $entryId]);
    $by = $stmt->fetchColumn();
    if ($by === false) {
        return 'That note could not be found.';
    }
    $boss = function_exists('current_user_roles') && array_intersect(current_user_roles($conn), ['Owner', 'Admin']);
    if ((int)$by !== $userId && !$boss) {
        return 'Only the person who wrote a note can delete it.';
    }
    $conn->prepare('DELETE FROM rpay_entry_notes WHERE id = ?')->execute([$noteId]);
    return null;
}

/**
 * A posted "add_note" or "delete_note" (from the notes box in includes/rpay_ui.php): done, said in
 * a flash. Returns true when the post was one of those. Only an entry Reem has gets a note.
 */
function rpay_handle_note_post(PDO $conn, string $code): bool
{
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'add_note' && $action !== 'delete_note') {
        return false;
    }
    $entryId = (int)($_POST['entry'] ?? 0);
    try {
        $known = false;
        foreach (rpay_reem($code, 'GET', '/entries')['entries'] ?? [] as $e) {
            $known = $known || (int)$e['id'] === $entryId;
        }
        if (!$known) {
            throw new BinvError('That entry could not be found.', 404);
        }
        // A note written on one payment row: that payment must be the entry's own.
        $noteDue = (int)($_POST['note_due'] ?? 0) ?: null;
        if ($action === 'add_note' && $noteDue !== null
            && (int)(rpay_reem($code, 'GET', '/dues/' . $noteDue)['due']['entry_id'] ?? 0) !== $entryId) {
            throw new BinvError('That payment is not part of this entry.', 400);
        }
        $error = $action === 'add_note'
            ? rpay_add_entry_note($conn, $entryId, is_string($_POST['note'] ?? null) ? $_POST['note'] : '', (int)current_user_id(), $noteDue)
            : rpay_delete_entry_note($conn, $entryId, (int)($_POST['note_id'] ?? 0), (int)current_user_id());
        binv_flash($error ?? ($action === 'add_note' ? 'Note added.' : 'Note deleted.'), $error ? 'danger' : 'success');
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        error_log('recurrent income: note not saved: ' . $e->getMessage());
        binv_flash('The note could not be saved.', 'danger');
    }
    return true;
}

const RPAY_REASON_MAX = 500;

/**
 * The income heads deleted in saifsys (migrations/rpay_head_log.sql): head id =>
 * ['name', 'reason', 'by', 'at'] of the delete. A head whose last log row is a restore
 * is not here. No table yet: none are deleted.
 */
function rpay_deleted_heads(PDO $conn, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    try {
        $rows = $conn->query('SELECT l.head_id, l.head_name, l.action, l.reason, l.created_at, u.fullname
            FROM rpay_head_log l LEFT JOIN `user` u ON u.id = l.created_by
            WHERE l.id IN (SELECT MAX(id) FROM rpay_head_log GROUP BY head_id)')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $cache = [];
    }
    $cache = [];
    foreach ($rows as $r) {
        if ($r['action'] === 'deleted') {
            $cache[(int)$r['head_id']] = ['name' => $r['head_name'], 'reason' => $r['reason'], 'by' => (string)($r['fullname'] ?? ''), 'at' => $r['created_at']];
        }
    }
    return $cache;
}

/** The heads still in use: Reem's list without the ones deleted in saifsys. $keep: a head id to keep anyway (an entry's own). */
function rpay_live_heads(PDO $conn, array $heads, int $keep = 0): array
{
    $gone = rpay_deleted_heads($conn);
    return array_values(array_filter($heads, function ($hd) use ($gone, $keep) {
        return !isset($gone[(int)$hd['id']]) || (int)$hd['id'] === $keep;
    }));
}

/** Delete ('deleted') or restore ('restored') a head in saifsys, with a reason. Returns what went wrong, or null. */
function rpay_log_head(PDO $conn, int $headId, string $headName, string $action, string $reason, int $userId): ?string
{
    $reason = trim(preg_replace('/\s+/u', ' ', $reason));
    if ($reason === '') {
        return $action === 'deleted' ? 'Give a reason for deleting it.' : 'Give a reason for restoring it.';
    }
    if (mb_strlen($reason) > RPAY_REASON_MAX) {
        return 'The reason can be ' . RPAY_REASON_MAX . ' letters at most.';
    }
    $isDeleted = isset(rpay_deleted_heads($conn, true)[$headId]); // fresh: never a stale answer when deciding
    if ($action === 'deleted' && $isDeleted) {
        return 'That income head is already deleted.';
    }
    if ($action === 'restored' && !$isDeleted) {
        return 'That income head is not deleted.';
    }
    $conn->prepare('INSERT INTO rpay_head_log (head_id, head_name, action, reason, created_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$headId, mb_substr($headName, 0, 80), $action, $reason, $userId ?: null]);
    return null;
}

/** Every delete and restore of the heads, newest first. */
function rpay_head_history(PDO $conn, int $limit = 50): array
{
    try {
        $stmt = $conn->prepare('SELECT l.head_id, l.head_name, l.action, l.reason, l.created_at, u.fullname
            FROM rpay_head_log l LEFT JOIN `user` u ON u.id = l.created_by ORDER BY l.id DESC LIMIT ' . max(1, $limit));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * What a line's period is called: "Rental period" only when its income head is in a Fixed
 * group (rent, partition, bedspace); "Period" for everything else (washing machine coins,
 * DEWA, commission), and when the head is in no group.
 */
function rpay_period_label(PDO $conn, int $headId): string
{
    if ($headId <= 0) {
        return 'Period';
    }
    try {
        $stmt = $conn->prepare('SELECT g.kind FROM rpay_head_group_members m JOIN rpay_head_groups g ON g.id = m.group_id WHERE m.head_id = ?');
        $stmt->execute([$headId]);
        return $stmt->fetchColumn() === 'fixed' ? 'Rental period' : 'Period';
    } catch (PDOException $e) {
        return 'Period';
    }
}

/** Each income head's group (rpay_head_groups): head id => ['id', 'name', 'kind']. No tables yet: none. */
function rpay_head_group_map(PDO $conn): array
{
    try {
        $rows = $conn->query('SELECT m.head_id, g.* FROM rpay_head_group_members m JOIN rpay_head_groups g ON g.id = m.group_id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['head_id']] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'kind' => $r['kind'] === 'dynamic' ? 'dynamic' : 'fixed', 'period_rule' => (string)($r['period_rule'] ?? 'none')];
    }
    return $out;
}

/** Every group (rpay_head_groups), even an empty one: id => ['name', 'kind']. */
function rpay_all_groups(PDO $conn): array
{
    try {
        $rows = $conn->query('SELECT * FROM rpay_head_groups ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['id']] = ['name' => (string)$r['name'], 'kind' => $r['kind'] === 'dynamic' ? 'dynamic' : 'fixed', 'period_rule' => (string)($r['period_rule'] ?? 'none'), 'has_rooms' => !empty($r['has_rooms']), 'space_word' => (string)($r['space_word'] ?? 'Bedspace'), 'allows_staff' => (int)($r['allows_staff'] ?? 1) === 1];
    }
    return $out;
}

/**
 * Put income heads in groups, all at once: head id => group id (0 = in no group).
 * Only the heads given are touched. Returns what went wrong, or null.
 */
function rpay_save_head_groups(PDO $conn, array $map, array $knownHeads): ?string
{
    $groups = rpay_all_groups($conn);
    $clean = [];
    foreach ($map as $hid => $gid) {
        $hid = (int)$hid;
        $gid = (int)$gid;
        if (!isset($knownHeads[$hid])) {
            return 'One of those income heads could not be found. Reload the page and try again.';
        }
        if ($gid !== 0 && !isset($groups[$gid])) {
            return 'One of those groups could not be found. Reload the page and try again.';
        }
        $clean[$hid] = $gid;
    }
    if (!$clean) {
        return 'Nothing to save.';
    }
    $conn->beginTransaction();
    try {
        $del = $conn->prepare('DELETE FROM rpay_head_group_members WHERE head_id = ?');
        $put = $conn->prepare('INSERT INTO rpay_head_group_members (head_id, group_id) VALUES (?, ?)');
        $seen = $conn->prepare('INSERT IGNORE INTO rpay_head_seen (head_id) VALUES (?)');
        foreach ($clean as $hid => $gid) {
            $del->execute([$hid]);
            if ($gid) {
                $put->execute([$hid, $gid]);
            }
            $seen->execute([$hid]); // placed by hand: never moved on its own again
        }
        $conn->commit();
    } catch (PDOException $e) {
        $conn->rollBack();
        throw $e;
    }
    return null;
}

/**
 * The group an income head's name points to, or 0: a word of the group's name (3 letters
 * or more) in the head's name - "DEWA - Shop 5" goes to DEWA, "Washing Machine Ayla" to
 * Washing Machine. A few words mean the same as a group's own. When two groups match
 * equally, none is picked.
 */
function rpay_suggest_group(string $headName, array $groups): int
{
    $same = ['bedspace' => ['bed space', 'bedspaces', 'room'], 'commission' => ['recharge'], 'deposit' => ['deposits'], 'washing' => ['laundry']];
    $name = ' ' . strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $headName)) . ' ';
    $best = 0;
    $bestScore = 0;
    $tie = false;
    foreach ($groups as $gid => $g) {
        $score = 0;
        foreach (preg_split('/[^a-z0-9]+/', strtolower($g['name']), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (strlen($w) < 3 || in_array($w, ['and', 'the', 'for'], true)) {
                continue;
            }
            $words = array_merge([$w], $same[$w] ?? []);
            foreach ($words as $x) {
                if (strpos($name, ' ' . $x . ' ') !== false || strpos($name, ' ' . $x) !== false) {
                    $score++;
                    break;
                }
            }
        }
        if ($score > $bestScore) {
            [$best, $bestScore, $tie] = [(int)$gid, $score, false];
        } elseif ($score > 0 && $score === $bestScore) {
            $tie = true;
        }
    }
    return $tie ? 0 : $best;
}

/**
 * New income heads (in no group and never placed) go to the group their name points to.
 * Every head looked at is then counted as placed, so it is never moved on its own again.
 * Returns what was done: [['head' => name, 'group' => name], ...].
 */
function rpay_auto_group_new_heads(PDO $conn, array $heads): array
{
    try {
        $seen = array_flip(array_map('intval', $conn->query('SELECT head_id FROM rpay_head_seen')->fetchAll(PDO::FETCH_COLUMN)));
    } catch (PDOException $e) {
        return []; // no table yet: nothing is moved
    }
    $member = rpay_head_group_map($conn);
    $groups = rpay_all_groups($conn);
    $done = [];
    foreach ($heads as $hd) {
        $hid = (int)$hd['id'];
        if (isset($seen[$hid])) {
            continue;
        }
        if (!isset($member[$hid])) {
            // The group its name points to; no match: the "Other" group (made as "Others" if there is none).
            $gid = $groups ? rpay_suggest_group((string)$hd['name'], $groups) : 0;
            if (!$gid) {
                foreach ($groups as $id => $g) {
                    if (in_array(strtolower(trim($g['name'])), ['other', 'others'], true)) {
                        $gid = (int)$id;
                    }
                }
                if (!$gid) {
                    $conn->prepare("INSERT INTO rpay_head_groups (name, kind) VALUES ('Others', 'dynamic')")->execute();
                    $gid = (int)$conn->lastInsertId();
                    $groups[$gid] = ['name' => 'Others', 'kind' => 'dynamic'];
                }
            }
            $conn->prepare('INSERT IGNORE INTO rpay_head_group_members (head_id, group_id) VALUES (?, ?)')->execute([$hid, $gid]);
            $done[] = ['head' => (string)$hd['name'], 'group' => $groups[$gid]['name']];
        }
        $conn->prepare('INSERT IGNORE INTO rpay_head_seen (head_id) VALUES (?)')->execute([$hid]);
    }
    return $done;
}

/**
 * Groups made in the ⓘ box: [['key' => 'n1', 'name' => 'Shop', 'kind' => 'fixed'], ...].
 * Returns key => new group id, or a sentence when a name is empty or taken.
 *
 * @return array|string
 */
function rpay_make_groups(PDO $conn, array $new, int $userId)
{
    $made = [];
    $taken = array_map('strtolower', array_column(rpay_all_groups($conn), 'name'));
    foreach ($new as $g) {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($g['name'] ?? '')));
        $key = (string)($g['key'] ?? '');
        if ($name === '' || mb_strlen($name) > 80) {
            return 'Give each new group a name (up to 80 letters).';
        }
        if (in_array(strtolower($name), $taken, true)) {
            return 'There is already a group called "' . $name . '".';
        }
        $conn->prepare('INSERT INTO rpay_head_groups (name, kind, created_by) VALUES (?, ?, ?)')
            ->execute([$name, ($g['kind'] ?? '') === 'dynamic' ? 'dynamic' : 'fixed', $userId ?: null]);
        $made[$key] = (int)$conn->lastInsertId();
        $taken[] = strtolower($name);
    }
    return $made;
}

/** The period rules a group can have, as people read them. */
const RPAY_PERIOD_RULES = [
    'none' => 'No period (only one typed by hand)',
    'prev_month' => 'The month before the due date (like DEWA: due 1 Nov = October)',
];

/**
 * What a payment is for, by its group's rule; a period typed by hand always wins.
 * Returns null (nothing to show) or ['title' => 'October 2026', 'range' => '01 Oct 2026 – 31 Oct 2026', 'typed' => bool].
 */
function rpay_group_period(array $due, string $rule, array $saved): ?array
{
    $id = (int)($due['id'] ?? 0);
    if (isset($saved[$id])) {
        return ['title' => rpay_date($saved[$id]['from']) . ' – ' . rpay_date($saved[$id]['to']), 'range' => '', 'typed' => true];
    }
    $d = DateTime::createFromFormat('!Y-m-d', (string)($due['due_date'] ?? ''));
    if ($rule === 'prev_month' && $d) {
        $from = (clone $d)->modify('first day of last month');
        $to = (clone $from)->modify('last day of this month');
        return ['title' => $from->format('F Y'), 'range' => rpay_date($from->format('Y-m-d')) . ' – ' . rpay_date($to->format('Y-m-d')), 'typed' => false];
    }
    return null;
}

// ---------- rooms and bedspaces (saifsys's own: migrations/rpay_rooms.sql) ----------

/** The rooms (of one Reem building, or all), each with its bed counts: id => [id, building_id, name, note, beds, occupied, vacant]. */
function rpay_rooms(PDO $conn, int $buildingId = 0): array
{
    try {
        $stmt = $conn->prepare('SELECT r.id, r.building_id, r.group_id, r.name, r.note,
                COUNT(b.id) AS beds, COALESCE(SUM(b.status = \'occupied\'), 0) AS occupied,
                COALESCE(SUM(b.status = \'occupied\' AND b.occupant_type = \'staff\'), 0) AS staff,
                COALESCE(SUM(b.status = \'occupied\' AND b.occupant_type = \'outsider\'), 0) AS outsiders,
                COALESCE(SUM(CASE WHEN b.status = \'occupied\' AND b.occupant_type = \'outsider\' THEN b.rent END), 0) AS rent
            FROM rpay_rooms r LEFT JOIN rpay_room_beds b ON b.room_id = r.id
            WHERE (? = 0 OR r.building_id = ?)
            GROUP BY r.id ORDER BY r.building_id, r.sort, r.name');
        $stmt->execute([$buildingId, $buildingId]);
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['id'] = (int)$r['id'];
        $r['building_id'] = (int)$r['building_id'];
        $r['beds'] = (int)$r['beds'];
        $r['occupied'] = (int)$r['occupied'];
        $r['vacant'] = $r['beds'] - $r['occupied'];
        $r['staff'] = (int)$r['staff'];
        $r['outsiders'] = (int)$r['outsiders'];
        $r['rent'] = (float)$r['rent'];
        $out[$r['id']] = $r;
    }
    return $out;
}

/** One room's beds, in bed-number order. */
function rpay_room_beds(PDO $conn, int $roomId): array
{
    $stmt = $conn->prepare('SELECT b.*, u.fullname AS by_name FROM rpay_room_beds b LEFT JOIN `user` u ON u.id = b.updated_by
        WHERE b.room_id = ? ORDER BY LENGTH(b.bed_no), b.bed_no');
    $stmt->execute([$roomId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Add $count beds to a room, numbered after the last one ("1", "2", ...), all $status. */
function rpay_add_beds(PDO $conn, int $roomId, int $count, string $status, int $userId): void
{
    $stmt = $conn->prepare('SELECT bed_no FROM rpay_room_beds WHERE room_id = ?');
    $stmt->execute([$roomId]);
    $last = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $no) {
        $last = max($last, (int)$no);
    }
    $put = $conn->prepare('INSERT INTO rpay_room_beds (room_id, bed_no, status, updated_by) VALUES (?, ?, ?, ?)');
    for ($i = 1; $i <= $count; $i++) {
        $put->execute([$roomId, (string)($last + $i), $status === 'occupied' ? 'occupied' : 'vacant', $userId ?: null]);
    }
}

/** A room number in any text ("G03", "G3", "Room G03"), as "G3"; '' when there is none. */
function rpay_room_key(string $s): string
{
    if (preg_match('/\bpartition\s*#?\s*0*(\d{1,2})\b/i', $s, $m)) {
        return 'P' . (int)$m[1]; // "Partition 01", "Partition # 02"
    }
    return preg_match('/\bG\s*0*(\d{1,2})\b/i', $s, $m) ? 'G' . (int)$m[1] : '';
}

/** The payments moved to a room by hand (rpay_due_rooms): due id => room id. */
function rpay_due_room_moves(PDO $conn): array
{
    try {
        return array_map('intval', $conn->query('SELECT due_id, room_id FROM rpay_due_rooms')->fetchAll(PDO::FETCH_KEY_PAIR));
    } catch (PDOException $e) {
        return [];
    }
}

/** The rooms picked for entries on the entry form (rpay_entry_rooms): entry id => room id. */
function rpay_entry_room_picks(PDO $conn): array
{
    try {
        return array_map('intval', $conn->query('SELECT entry_id, room_id FROM rpay_entry_rooms')->fetchAll(PDO::FETCH_KEY_PAIR));
    } catch (PDOException $e) {
        return [];
    }
}

/** Keep the room picked for an entry; 0 = none picked (the room number in the unit or title decides). */
function rpay_set_entry_room(PDO $conn, int $entryId, int $roomId, int $userId): void
{
    if ($roomId <= 0) {
        $conn->prepare('DELETE FROM rpay_entry_rooms WHERE entry_id = ?')->execute([$entryId]);
        return;
    }
    $conn->prepare('INSERT INTO rpay_entry_rooms (entry_id, room_id, set_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE room_id = VALUES(room_id), set_by = VALUES(set_by)')->execute([$entryId, $roomId, $userId ?: null]);
}

/** Move a payment to a room by hand; room 0 = go back to the room its unit or title says. */
function rpay_move_due_room(PDO $conn, int $dueId, int $roomId, int $userId): void
{
    if ($roomId <= 0) {
        $conn->prepare('DELETE FROM rpay_due_rooms WHERE due_id = ?')->execute([$dueId]);
        return;
    }
    $conn->prepare('INSERT INTO rpay_due_rooms (due_id, room_id, moved_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE room_id = VALUES(room_id), moved_by = VALUES(moved_by)')->execute([$dueId, $roomId, $userId ?: null]);
}

/**
 * The room a payment belongs to (moved there by hand, or the room number in its unit or
 * title, in its building), or null: ['id', 'name', 'group_id'].
 */
function rpay_room_of_due(PDO $conn, array $due): ?array
{
    static $rooms = null, $byKey = null, $moves = null, $entryRooms = null;
    if ($rooms === null) {
        $rooms = rpay_rooms($conn);
        $moves = rpay_due_room_moves($conn);
        $entryRooms = rpay_entry_room_picks($conn);
        $byKey = [];
        foreach ($rooms as $r) {
            $k = rpay_room_key((string)$r['name']);
            $byKey[$r['building_id'] . ':' . ($k !== '' ? $k : strtolower((string)$r['name']))] = (int)$r['id'];
        }
    }
    $id = $moves[(int)($due['id'] ?? 0)] ?? ($entryRooms[(int)($due['entry_id'] ?? 0)] ?? 0);
    if (!$id) {
        $b = (int)($due['building']['id'] ?? 0);
        foreach ([(string)($due['head']['name'] ?? ''), (string)($due['unit'] ?? ''), (string)($due['title'] ?? '')] as $s) {
            $k = rpay_room_key($s);
            if ($k !== '' && isset($byKey[$b . ':' . $k])) {
                $id = $byKey[$b . ':' . $k];
                break;
            }
        }
    }
    return $id && isset($rooms[$id]) ? $rooms[$id] : null;
}

/**
 * A posted "move_due" (from the payment rows in includes/rpay_ui.php): the payment moved to the room picked
 * (0: back to its own), said in a flash. Returns true when the post was one. Reem is only read.
 */
function rpay_handle_move_post(PDO $conn, string $code): bool
{
    if (($_POST['action'] ?? '') !== 'move_due') {
        return false;
    }
    // The payments ticked (or one, from an older form) and the room picked.
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['due_ids'] ?? [($_POST['due_id'] ?? 0)])))));
    $to = (int)($_POST['to_room'] ?? -1);
    try {
        if (!$ids) {
            throw new BinvError('Tick the payments to move first.', 400);
        }
        $rooms = rpay_rooms($conn);
        if ($to !== 0 && !isset($rooms[$to])) {
            throw new BinvError('Pick the room to move them to.', 400);
        }
        $titles = [];
        foreach ($ids as $dueId) {
            $due = rpay_reem($code, 'GET', '/dues/' . $dueId)['due'] ?? null;
            if (!$due) {
                throw new BinvError('A payment could not be found. Nothing was moved after it.', 404);
            }
            rpay_move_due_room($conn, $dueId, $to, (int)current_user_id());
            $titles[] = (string)($due['title'] ?? 'Payment');
        }
        $what = count($titles) === 1 ? '"' . $titles[0] . '"' : count($titles) . ' payments';
        binv_flash($what . ' ' . ($to ? 'moved to ' . $rooms[$to]['name'] . '.' : (count($titles) === 1 ? 'is' : 'are') . ' back in ' . (count($titles) === 1 ? 'its' : 'their') . ' own room.'));
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        binv_flash('The payment could not be moved.', 'danger');
    }
    return true;
}

/**
 * Each room's payments, newest first, over every month from the first entry (24 months back
 * at most): room id => [payment, ...], and 0 => the payments in no room. A payment is a room's
 * when someone moved it there, or else when it is in the room's building, its income head is
 * in a group with rooms, and its unit or title has the room's number. Each payment carries
 * 'moved' => true when it was moved by hand. Read only from Reem: one read per month.
 */
function rpay_rooms_payments(PDO $conn, string $code, array $rooms): array
{
    $out = array_fill_keys(array_keys($rooms), []) + [0 => []];
    if (!$rooms) {
        return $out;
    }
    // Matched against every room, so a payment is never put in a room only because its own room was not asked for.
    $allRooms = rpay_rooms($conn);
    $byKey = [];
    foreach ($allRooms as $r) {
        $k = rpay_room_key((string)$r['name']);
        $byKey[$r['building_id'] . ':' . ($k !== '' ? $k : strtolower((string)$r['name']))] = (int)$r['id'];
    }
    $moves = rpay_due_room_moves($conn);
    $entryRooms = rpay_entry_room_picks($conn);
    $roomGroupHeads = [];
    $all = rpay_all_groups($conn);
    foreach (rpay_head_group_map($conn) as $hid => $g) {
        if (!empty($all[$g['id']]['has_rooms'])) {
            $roomGroupHeads[$hid] = true;
        }
    }
    $entries = rpay_reem($code, 'GET', '/entries')['entries'] ?? [];
    $firsts = array_filter(array_map(function ($e) { return (string)($e['first_month'] ?? ''); }, $entries));
    $next = (new DateTime('first day of next month'))->format('Y-m');
    $from = max($firsts ? min($firsts) : $next, (new DateTime('first day of this month'))->modify('-23 months')->format('Y-m'));
    $buildings = array_unique(array_map(function ($r) { return (int)$r['building_id']; }, $allRooms));
    for ($m = DateTime::createFromFormat('!Y-m', $from); $m && $m->format('Y-m') <= $next; $m->modify('+1 month')) {
        foreach ($buildings as $b) {
            foreach (rpay_reem($code, 'GET', '?' . http_build_query(['month' => $m->format('Y-m'), 'building' => $b]))['dues'] ?? [] as $d) {
                $id = (int)$d['id'];
                if (isset($moves[$id]) && isset($allRooms[$moves[$id]])) {
                    // Moved by hand: that room, whatever the unit or title says.
                    if (isset($out[$moves[$id]])) {
                        $out[$moves[$id]][] = $d + ['moved' => true];
                    }
                    continue;
                }
                $picked = $entryRooms[(int)($d['entry_id'] ?? 0)] ?? 0;
                if ($picked && isset($allRooms[$picked])) {
                    // Its entry's room, picked on the entry form.
                    if (isset($out[$picked])) {
                        $out[$picked][] = $d;
                    }
                    continue;
                }
                if (!isset($roomGroupHeads[(int)($d['head']['id'] ?? 0)])) {
                    continue;
                }
                $room = 0;
                foreach ([(string)($d['head']['name'] ?? ''), (string)($d['unit'] ?? ''), (string)($d['title'] ?? '')] as $s) { // the income head's name, then the unit, then the title
                    $k = rpay_room_key($s);
                    if ($k !== '' && isset($byKey[$b . ':' . $k])) {
                        $room = $byKey[$b . ':' . $k];
                        break;
                    }
                }
                if (isset($out[$room])) {
                    $out[$room][] = $d;
                }
            }
        }
    }
    foreach ($out as &$list) {
        usort($list, function ($a, $b) { return strcmp((string)$b['due_date'], (string)$a['due_date']) ?: ((int)$b['id'] <=> (int)$a['id']); });
    }
    unset($list);
    return $out;
}

const RPAY_DUE_SOON_DAYS = 7; // "due soon" from this many days before

/**
 * Whether something unpaid needs attention by its due date: null (no), or
 * ['level' => 'overdue'|'soon', 'days' => n, 'text' => 'Overdue · 5 days' / 'Due today' / 'Due in 3 days'].
 */
function rpay_due_alert(?string $dueDate, bool $unpaid): ?array
{
    $d = $dueDate ? DateTime::createFromFormat('!Y-m-d', $dueDate) : false;
    if (!$unpaid || !$d) {
        return null;
    }
    $today = new DateTime('today', new DateTimeZone('Asia/Dubai'));
    $today = DateTime::createFromFormat('!Y-m-d', $today->format('Y-m-d'));
    $days = (int)$today->diff($d)->format('%r%a'); // < 0: past due
    if ($days < 0) {
        return ['level' => 'overdue', 'days' => -$days, 'text' => 'Overdue · ' . (-$days) . ' day' . ($days === -1 ? '' : 's')];
    }
    if ($days <= RPAY_DUE_SOON_DAYS) {
        return ['level' => 'soon', 'days' => $days, 'text' => $days === 0 ? 'Due today' : 'Due in ' . $days . ' day' . ($days === 1 ? '' : 's')];
    }
    return null;
}

/** The bell for rpay_due_alert(): red when overdue, amber when due soon. */
function rpay_due_bell(?array $alert): string
{
    if (!$alert) {
        return '';
    }
    return '<span class="rp-alert rp-alert-' . $alert['level'] . '" title="' . h($alert['text']) . '"><i class="bi bi-bell-fill"></i> ' . h($alert['text']) . '</span>';
}

/** The Fixed / Dynamic tag of a group. */
function rpay_kind_tag(string $kind): string
{
    if ($kind === 'dynamic') {
        return '<span class="badge rounded-pill rpay-dynamic" title="The amount changes every month"><i class="bi bi-graph-up"></i> Dynamic</span>';
    }
    return $kind === 'fixed' ? '<span class="badge rounded-pill rpay-fixed" title="The same amount every month"><i class="bi bi-pin-angle"></i> Fixed</span>' : '';
}

/**
 * A posted "save_period" or "reset_period" for the payment in $_POST['due']: done, said
 * in a flash. Returns true when the post was one of those. The period is saifsys's own
 * (rpay_due_periods); Reem is only asked whether the payment exists.
 */
function rpay_handle_period_post(PDO $conn, string $code): bool
{
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'save_period' && $action !== 'reset_period') {
        return false;
    }
    $dueId = (int)($_POST['due'] ?? 0);
    try {
        $exists = rpay_reem($code, 'GET', '/dues/' . $dueId);
        if (empty($exists['due'])) {
            throw new BinvError('That payment could not be found.', 404);
        }
        if ($action === 'reset_period') {
            rpay_save_period($conn, $dueId, null, null, (int)current_user_id());
            binv_flash('Period removed.');
            return true;
        }
        $from = DateTime::createFromFormat('!Y-m-d', (string)($_POST['period_from'] ?? ''));
        $to = DateTime::createFromFormat('!Y-m-d', (string)($_POST['period_to'] ?? ''));
        if (!$from || !$to) {
            throw new BinvError('Pick both dates of the period.', 400);
        }
        if ($to < $from) {
            throw new BinvError('The period cannot end before it starts.', 400);
        }
        rpay_save_period($conn, $dueId, $from->format('Y-m-d'), $to->format('Y-m-d'), (int)current_user_id());
        binv_flash('Period saved: ' . rpay_date($from->format('Y-m-d')) . ' – ' . rpay_date($to->format('Y-m-d')) . '.');
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        error_log('recurrent income: period not saved: ' . $e->getMessage());
        binv_flash('The period could not be saved.', 'danger');
    }
    return true;
}

/** The accounts hidden in saifsys (migrations/rpay_hidden_accounts.sql): account id => ['by', 'at']. */
function rpay_hidden_accounts(PDO $conn): array
{
    try {
        $rows = $conn->query('SELECT h.account_id, h.hidden_at, u.fullname FROM rpay_hidden_accounts h LEFT JOIN `user` u ON u.id = h.hidden_by')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['account_id']] = ['by' => (string)($r['fullname'] ?? ''), 'at' => (string)$r['hidden_at']];
    }
    return $out;
}

/** The accounts to offer: Reem's list without the hidden ones. $keep: an account id to keep anyway (one already picked). */
function rpay_visible_accounts(PDO $conn, array $accounts, int $keep = 0): array
{
    $hidden = rpay_hidden_accounts($conn);
    return array_values(array_filter($accounts, function ($a) use ($hidden, $keep) {
        return !isset($hidden[(int)$a['id']]) || (int)$a['id'] === $keep;
    }));
}

/** Hide an account in saifsys, or show it again. */
function rpay_set_account_hidden(PDO $conn, int $accountId, bool $hide, int $userId): void
{
    if ($hide) {
        $conn->prepare('INSERT IGNORE INTO rpay_hidden_accounts (account_id, hidden_by) VALUES (?, ?)')->execute([$accountId, $userId ?: null]);
    } else {
        $conn->prepare('DELETE FROM rpay_hidden_accounts WHERE account_id = ?')->execute([$accountId]);
    }
}

/** "2026-11-17 10:05:00" as "17 Nov 2026, 10:05". */
function rpay_datetime(string $at): string
{
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $at);
    return $d ? $d->format('d M Y, H:i') : $at;
}

/** 1 as "1st", 22 as "22nd". */
function rpay_day_label(int $day): string
{
    $suffix = ($day % 100 >= 11 && $day % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$day % 10] ?? 'th');
    return $day . $suffix;
}

// ---------- mode of payment (saifsys's own: migrations/rpay_entry_modes.sql) ----------

/** The modes of payment an entry can have: key => label. */
const RPAY_PAYMENT_MODES = ['cash' => 'Cash', 'card' => 'Card', 'cheque' => 'Cheque', 'transfer' => 'Bank transfer'];

/** The mode of payment kept per entry: entry id => mode. No table yet: none (every entry is cash). */
function rpay_entry_mode_picks(PDO $conn): array
{
    try {
        return $conn->query('SELECT entry_id, payment_mode FROM rpay_entry_modes')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/** An entry's mode of payment: cash unless one was picked. */
function rpay_entry_mode(array $picks, int $entryId, string $title = ''): string
{
    if (isset($picks[$entryId]) && isset(RPAY_PAYMENT_MODES[(string)$picks[$entryId]])) {
        return (string)$picks[$entryId];
    }
    return rpay_guess_mode($title); // no mode picked yet: worked out from the title
}

/** The words a mode is guessed from: an entry's (or a payment's) title and notes. */
function rpay_mode_text(array $row): string
{
    return trim((string)($row['title'] ?? '') . ' ' . (string)($row['notes'] ?? ''));
}

/** The mode of payment a title says: "Bank", "Transfer" or "ENBD" = bank transfer; cheque; card; otherwise cash. */
function rpay_guess_mode(string $title): string
{
    $t = strtolower($title);
    if (strpos($t, 'cheque') !== false) {
        return 'cheque';
    }
    if (strpos($t, 'bank') !== false || strpos($t, 'transfer') !== false || strpos($t, 'enbd') !== false) {
        return 'transfer';
    }
    if (preg_match('/\bcard\b/', $t)) {
        return 'card';
    }
    return 'cash';
}

/** Keep an entry's mode of payment. Anything not cash, card or cheque is saved as cash. */
function rpay_set_entry_mode(PDO $conn, int $entryId, string $mode, int $userId): void
{
    $mode = isset(RPAY_PAYMENT_MODES[$mode]) ? $mode : 'cash';
    $conn->prepare('INSERT INTO rpay_entry_modes (entry_id, payment_mode, set_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE payment_mode = VALUES(payment_mode), set_by = VALUES(set_by)')
        ->execute([$entryId, $mode, $userId ?: null]);
}

// ---------- when an entry was first saved (saifsys's own: migrations/rpay_entry_saved.sql) ----------

/** When each entry was first saved here: entry id => 'Y-m-d H:i:s'. No table yet: none. */
function rpay_entry_saved_picks(PDO $conn): array
{
    try {
        return $conn->query('SELECT entry_id, saved_at FROM rpay_entry_saved')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/** Remember the first time an entry was saved; a later save does not change it. */
function rpay_record_entry_saved(PDO $conn, int $entryId, int $userId): void
{
    $conn->prepare('INSERT IGNORE INTO rpay_entry_saved (entry_id, saved_at, saved_by) VALUES (?, NOW(), ?)')
        ->execute([$entryId, $userId ?: null]);
}

/** Set the day an entry was created, picked by hand (used when Reem has no date for it). */
function rpay_set_entry_saved_date(PDO $conn, int $entryId, string $ymd, int $userId): void
{
    $conn->prepare('INSERT INTO rpay_entry_saved (entry_id, saved_at, saved_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE saved_at = VALUES(saved_at), saved_by = VALUES(saved_by)')
        ->execute([$entryId, $ymd . ' 00:00:00', $userId ?: null]);
}

/**
 * Reem's created_at (unix seconds, as Reem keeps every time) as a Dubai date 'Y-m-d', or '' when there is none.
 * A value in milliseconds is read too, so a Reem that ever sends those still gives the right day.
 */
function rpay_reem_date($stamp): string
{
    $n = (int)$stamp;
    if ($n <= 0) {
        return '';
    }
    if ($n > 100000000000) { // milliseconds
        $n = intdiv($n, 1000);
    }
    return (new DateTime('@' . $n))->setTimezone(new DateTimeZone('Asia/Dubai'))->format('Y-m-d');
}
