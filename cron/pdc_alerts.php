<?php
/**
 * cron/pdc_alerts.php — Post Dated Cheques: email the alerts for pending cheques due within
 * 7 days (once) and overdue (once). Bounced alerts go out when the status is changed.
 * Opening the Post Dated Cheques page also runs this once a day, so the cron is optional;
 * it only makes the time fixed. Example (8:00 every day):
 *   0 8 * * * /usr/bin/php /home/USER/public_html/cron/pdc_alerts.php >> /home/USER/logs/pdc_alerts.log 2>&1
 * --dry-run: show what would be sent, send and log nothing.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/loan_tracker/includes/lt_pdc.php';

$dry = in_array('--dry-run', $argv, true);
if (!$dry) {
    $conn->exec("INSERT IGNORE INTO lt_pdc_alert_runs (run_date) VALUES (CURDATE())");
}
$res = lt_pdc_run_due_alerts($conn, $dry);
echo date('Y-m-d H:i:s'), ' · ', $dry ? 'DRY RUN · ' : '', count($res), " alert(s)\n";
foreach ($res as $r) {
    echo '  ', $r['kind'], ' · cheque ', $r['cheque_no'], ' · ', $r['result'], ' · ', implode(', ', $r['to']) ?: '(no address)', "\n";
}
