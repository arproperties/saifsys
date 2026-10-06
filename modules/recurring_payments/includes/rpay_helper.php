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
        throw new BinvError('Recurring Payments is not connected to Reem yet: REEM_URL and REEM_DOOR_KEY are missing from config.php.', 503);
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
    echo '<div style="font-family:system-ui;padding:32px;max-width:640px">
            <h3>Recurring Payments</h3>
            <p>' . h($e->getMessage()) . '</p>
            <p><a href="' . h($back) . '">Back to modules</a></p>
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

/** 1 as "1st", 22 as "22nd". */
function rpay_day_label(int $day): string
{
    $suffix = ($day % 100 >= 11 && $day % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$day % 10] ?? 'th');
    return $day . $suffix;
}
