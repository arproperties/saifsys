<?php
/**
 * Recurring Payments — shared helpers.
 *
 * saifsys only draws the screens. The buildings (the module's own list, nothing to do
 * with any other building list), the entries, the monthly payments and the day each one
 * is created all live in the Reem app. Every read and every change on these pages is
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
