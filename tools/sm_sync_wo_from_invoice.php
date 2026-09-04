<?php
/**
 * CLI/browser: sync finalized work order totals from its issued invoice (one-time repair).
 *
 * Usage:
 *   php tools/sm_sync_wo_from_invoice.php 2912
 *   php tools/sm_sync_wo_from_invoice.php --order=2912
 */
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/work_order_financial_guard.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_role(['Owner', 'Admin', 'Account'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

$orderId = 0;
if ($isCli) {
    foreach ($argv as $arg) {
        if (preg_match('/^--order=(\d+)$/', $arg, $m)) {
            $orderId = (int)$m[1];
        } elseif (ctype_digit($arg)) {
            $orderId = (int)$arg;
        }
    }
} else {
    $orderId = (int)($_GET['order_id'] ?? $_GET['order'] ?? 0);
}

if ($orderId <= 0) {
    $msg = "Usage: php tools/sm_sync_wo_from_invoice.php <order_id>\n";
    echo $isCli ? $msg : '<pre>' . htmlspecialchars($msg) . '</pre>';
    exit(1);
}

$userId = function_exists('current_user_id') ? current_user_id() : null;
$result = wo_sync_finalized_order_from_invoice($conn, $orderId, $userId);

$line = ($result['success'] ? 'OK' : 'FAIL') . ': ' . ($result['message'] ?? '') . "\n";
echo $isCli ? $line : '<pre>' . htmlspecialchars($line) . '</pre>';
exit($result['success'] ? 0 : 1);
