<?php
/**
 * Static regression matrix for Cleaning WO ops vs financial lock split.
 * Run: php tools/sm_wo_lock_regression_check.php
 * Does not touch the database.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/work_order_financial_guard.php';

$required = [
    'wo_ops_is_locked',
    'wo_financial_is_locked',
    'wo_invoice_activity',
    'wo_has_financial_activity',
    'wo_ops_can_direct_cancel',
    'wo_ops_lock_reason',
    'wo_financial_lock_reason',
    'wo_assert_ops_editable',
    'wo_assert_financial_editable',
];

$fail = 0;
foreach ($required as $fn) {
    if (!function_exists($fn)) {
        echo "FAIL missing function {$fn}\n";
        $fail++;
    } else {
        echo "OK   {$fn}\n";
    }
}

$settingsSql = $root . '/migrations/sm_settings_defaults_only.sql';
if (!is_file($settingsSql)) {
    echo "FAIL missing {$settingsSql}\n";
    $fail++;
} else {
    $sql = file_get_contents($settingsSql);
    if (stripos($sql, 'UPDATE make_order') !== false) {
        echo "FAIL settings-only SQL must not UPDATE make_order\n";
        $fail++;
    } else {
        echo "OK   settings-only SQL has no make_order backfill\n";
    }
    if (substr_count($sql, 'INSERT INTO settings') < 8) {
        echo "FAIL expected multiple settings inserts\n";
        $fail++;
    } else {
        echo "OK   settings-only SQL inserts SM defaults\n";
    }
}

$files = [
    'operation/workorder_list.php' => ['wo_ops_can_direct_cancel', 'showStatusDropdown'],
    'operation/ajax_update_order_status.php' => ['wo_ops_is_locked', 'wo_ops_can_direct_cancel'],
    'operation/order_cancel.php' => ['wo_ops_can_direct_cancel'],
    'operation/order_edit.php' => ['wo_ops_is_locked', 'moneyFieldsChanged', 'financialLockReason'],
    'operation/api_availability.php' => ['wo_ops_is_locked', 'can_direct_cancel', 'financial_lock_reason'],
    'operation/worker_availability.php' => ['can_direct_cancel', 'financial_lock_reason', 'MONEY_LOCK_SELECTORS'],
];

foreach ($files as $rel => $needles) {
    $path = $root . '/' . $rel;
    $src = file_get_contents($path);
    foreach ($needles as $n) {
        if (strpos($src, $n) === false) {
            echo "FAIL {$rel} missing '{$n}'\n";
            $fail++;
        } else {
            echo "OK   {$rel} contains '{$n}'\n";
        }
    }
}

echo "\n=== Expected behaviour matrix (manual UI verify on live after deploy) ===\n";
$rows = [
    ['OPEN+Confirmed, no invoice', 'ops editable', 'money editable', 'direct cancel OK'],
    ['OPEN+Confirmed, issued+GL (legacy)', 'ops editable', 'money locked', 'cancel via Accounts'],
    ['Completed, not finalized', 'ops editable', 'money per activity', 'cancel per activity'],
    ['is_finalized=1', 'fully locked', 'fully locked', 'Accounts adjustment only'],
];
foreach ($rows as $r) {
    echo sprintf("- %-40s | %-14s | %-14s | %s\n", $r[0], $r[1], $r[2], $r[3]);
}

echo $fail === 0 ? "\nPASS static checks ({$fail} failures)\n" : "\nFAIL {$fail} check(s)\n";
exit($fail === 0 ? 0 : 1);
