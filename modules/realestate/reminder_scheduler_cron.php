<?php
/**
 * Real Estate Reminder Scheduler
 *
 * Recommended cron:
 * * * * * /Applications/XAMPP/bin/php /Applications/XAMPP/xamppfiles/htdocs/herosysgro/modules/realestate/reminder_scheduler_cron.php >> /Applications/XAMPP/xamppfiles/htdocs/herosysgro/logs/re_reminders_cron.log 2>&1
 *
 * URL fallback:
 * https://your-domain.com/herosysgro/modules/realestate/reminder_scheduler_cron.php?run_cron=1
 */

if (php_sapi_name() === 'cli') {
    chdir(dirname(__DIR__, 2));
}

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/re_cron_helpers.php';
require_once __DIR__ . '/includes/re_reminder_helper.php';

re_cron_guard();

if (!re_reminder_tables_ready($conn)) {
    echo "Reminder tables not installed. Run migrations/re_reminders.sql." . PHP_EOL;
    exit(1);
}

$started = date('Y-m-d H:i:s');
try {
    $summary = re_reminder_process_due($conn, 50);
    echo sprintf(
        "[%s] Reminder scheduler processed. Claimed=%d Sent=%d Failed=%d Skipped=%d",
        $started,
        (int)$summary['claimed'],
        (int)$summary['sent'],
        (int)$summary['failed'],
        (int)$summary['skipped']
    ) . PHP_EOL;
} catch (Throwable $e) {
    error_log('Real Estate reminder scheduler failed: ' . $e->getMessage());
    echo '[' . $started . '] Reminder scheduler failed: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
