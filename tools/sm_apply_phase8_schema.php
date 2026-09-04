<?php
/**
 * Schema helper — Service Management Phase 8 (reporting settings).
 */
require_once __DIR__ . '/../includes/db_connect.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

echo "=== SM Phase 8 schema (reporting) ===\n";

$settings = [
    'sm_phase8_enabled' => '1',
    'sm_gm_dashboard_default_days' => '30',
];
foreach ($settings as $key => $val) {
    $st = $conn->prepare('SELECT COUNT(*) FROM settings WHERE `key` = ?');
    $st->execute([$key]);
    if ((int)$st->fetchColumn() === 0) {
        $conn->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)')->execute([$key, $val]);
        echo "OK: setting {$key}\n";
    } else {
        echo "SKIP: setting {$key}\n";
    }
}

echo "=== Phase 8 complete ===\n";
