<?php
/**
 * Move-out inspection reminders (automatic).
 *
 * Run once every morning (e.g. 08:00). For every booked move-out inspection with
 * automatic reminders on, it emails the tenant:
 *   - the day before  (appointment tomorrow)  -> "Reminder: ... Tomorrow"
 *   - on the morning  (appointment today)     -> "Reminder: ... Today"
 * Each reminder is sent once per appointment date (re-running the same day
 * sends nothing new). Missed, completed and cancelled ones are skipped.
 *
 * Cron (local XAMPP):
 *   0 8 * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/POSsys/cron/move_out_inspection_reminders.php >> /Applications/XAMPP/xamppfiles/htdocs/POSsys/logs/move_out_inspection_reminders.log 2>&1
 * Live: same, with the server's PHP and project paths.
 *
 * Options: --dry-run (list what would be sent, send nothing)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/realestate/includes/move_out_appointment_helper.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
echo '[' . date('Y-m-d H:i:s') . '] Move-out inspection reminders' . ($dryRun ? ' (dry run)' : '') . PHP_EOL;

if (!rmoa_ready($conn)) {
    echo 'Run migrations/re_move_out_appointment.sql first.' . PHP_EOL;
    exit(1);
}

$st = $conn->prepare("
    SELECT mo.id, mo.company_id, mo.inspection_scheduled_date, t.email
    FROM re_move_outs mo
    JOIN re_leases l ON l.id = mo.lease_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE mo.inspection_scheduled_date IN (?, ?)
      AND mo.inspection_auto_remind = 1
      AND COALESCE(mo.inspection_appt_status, '') <> 'missed'
      AND mo.inspection_completed = 0
      AND mo.status NOT IN ('completed', 'cancelled')
");
$st->execute([$today, $tomorrow]);
$sentBefore = $conn->prepare("SELECT COUNT(*) FROM re_move_out_appointment_log WHERE move_out_id = ? AND action = ? AND appt_date = ? AND email_ok = 1");

$sent = $skipped = $failed = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $type = $r['inspection_scheduled_date'] === $tomorrow ? 'auto_d1' : 'auto_d0';
    $sentBefore->execute([(int)$r['id'], 'email_' . $type, $r['inspection_scheduled_date']]);
    if ((int)$sentBefore->fetchColumn() > 0) {
        $skipped++;
        continue;
    }
    $to = trim((string)$r['email']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo "  move-out {$r['id']}: no valid tenant email, skipped" . PHP_EOL;
        $skipped++;
        continue;
    }
    if ($dryRun) {
        echo "  move-out {$r['id']}: would send {$type} to {$to}" . PHP_EOL;
        continue;
    }
    $res = rmoa_send($conn, (int)$r['company_id'], (int)$r['id'], $type, $to, null);
    if ($res['ok']) {
        $sent++;
        echo "  move-out {$r['id']}: {$type} sent to {$to}" . PHP_EOL;
    } else {
        $failed++;
        echo "  move-out {$r['id']}: {$type} to {$to} FAILED: {$res['error']}" . PHP_EOL;
    }
}
echo "Done: {$sent} sent, {$skipped} skipped, {$failed} failed." . PHP_EOL;
