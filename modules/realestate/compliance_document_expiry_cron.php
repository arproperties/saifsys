<?php
/**
 * Real Estate — Compliance: document expiry alerts (daily)
 *
 * Calls check_and_send_document_expiry_alerts() per company (same as compliance_send_alerts.php).
 *
 * CLI:  php modules/realestate/compliance_document_expiry_cron.php
 * Web:  .../compliance_document_expiry_cron.php?run_cron=1
 */

if (php_sapi_name() === 'cli') {
    chdir(__DIR__ . '/../..');
}

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/re_cron_helpers.php';
re_cron_guard();

require_once __DIR__ . '/includes/compliance_helper.php';

/** @var PDO $conn */

$companies = re_cron_active_company_ids($conn);
$agg = ['companies' => 0, 'sent' => 0, 'skipped' => 0];

foreach ($companies as $companyId) {
    $agg['companies']++;
    $r = check_and_send_document_expiry_alerts($conn, $companyId);
    $agg['sent'] += (int)($r['sent'] ?? 0);
    $agg['skipped'] += (int)($r['skipped'] ?? 0);
}

$out = "Compliance document expiry cron — " . date('c') . "\n"
    . "Companies: {$agg['companies']}, documents alerted: {$agg['sent']}, skipped: {$agg['skipped']}\n";

if (php_sapi_name() === 'cli') {
    echo $out;
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    echo htmlspecialchars($out, ENT_QUOTES, 'UTF-8');
}
