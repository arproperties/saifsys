<?php
/**
 * Recurring Payments — shared helpers.
 *
 * saifsys only draws the screens. The entries, the monthly payments, the day each one
 * is created, and who may see a building's payments all live in the Reem app. Every
 * read and every change on these pages is one call to Reem, through its door for
 * saifsys: /api/from-saifsys/recurring-payments.
 *
 * Nothing is stored in the saifsys database and no rule is decided here. What Reem
 * refuses, it refuses with a sentence, and that sentence is what the page shows.
 *
 * The line to Reem, the employee-code lookup, the error class and the flash message
 * are Building Inventory's; this module only names its own route.
 */

require_once dirname(__DIR__, 2) . '/building_inventory/includes/binv_helper.php';

const RPAY_REEM_PATH = '/api/from-saifsys/recurring-payments';
const RPAY_TITLE_MAX = 120; // input lengths only, so the box stops where Reem would cut
const RPAY_UNIT_MAX = 80;
const RPAY_NOTES_MAX = 500;

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
    return binv_reem($code, $method, $path, $json, null, false, $timeout, RPAY_REEM_PATH);
}

/**
 * Whether the launcher should offer this module to a login: true when Reem says they
 * keep at least one building. Remembered in the session for ten minutes, asked with a
 * short wait, and any trouble at all means no - the launcher must never hang or break
 * because Reem is slow.
 */
function rpay_launcher_access(PDO $conn, int $userId): bool
{
    if ($userId <= 0 || !binv_reem_configured() || session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $kept = $_SESSION['rpay_launcher'] ?? null;
    if (is_array($kept) && (int)($kept['user'] ?? 0) === $userId && time() - (int)($kept['at'] ?? 0) < 600) {
        return !empty($kept['ok']);
    }
    $ok = false;
    try {
        $code = binv_employee_code($conn, $userId);
        $ok = $code !== '' && count(rpay_reem($code, 'GET', '/entries', null, 4)['buildings'] ?? []) > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    $_SESSION['rpay_launcher'] = ['user' => $userId, 'at' => time(), 'ok' => $ok];
    return $ok;
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
