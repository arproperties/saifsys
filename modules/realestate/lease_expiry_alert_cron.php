<?php
/**
 * Lease expiry reminder cron (admin / management only)
 *
 * Sends one email per lease per milestone when days remaining = 100, 90, 60, or 30.
 * Uses re_lease_expiry_reminders + send_lease_expiry_reminder() from lease_helper.php.
 *
 * Run daily, e.g.:
 *   0 8 * * * /usr/bin/php /path/to/herosysgro/modules/realestate/lease_expiry_alert_cron.php
 *
 * Or via browser (same pattern as amc_alert_cron.php):
 *   https://your-domain/sys/modules/realestate/lease_expiry_alert_cron.php?run_cron=1
 *
 * Optional CLI env for correct "View lease" links when HTTP_HOST is unset:
 *   LEASE_REMINDER_BASE_URL=https://your-domain/sys
 */

if (php_sapi_name() !== 'cli' && !isset($_GET['run_cron'])) {
    die('This script should be run via CLI or with ?run_cron=1');
}

if (php_sapi_name() === 'cli') {
    chdir(__DIR__ . '/../..');
}

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/lease_helper.php';

/** @var PDO $conn */

$milestones = [
    100 => '100_days',
    90  => '90_days',
    60  => '60_days',
    30  => '30_days',
];

$summary = [];
$errors = [];

foreach ($milestones as $daysRemaining => $reminderType) {
    $stmt = $conn->prepare("
        SELECT l.id
        FROM re_leases l
        WHERE l.status = 'active'
          AND DATE(l.end_date) >= CURDATE()
          AND DATEDIFF(DATE(l.end_date), CURDATE()) = ?
          AND NOT EXISTS (
              SELECT 1
              FROM re_lease_expiry_reminders r
              WHERE r.lease_id = l.id
                AND r.reminder_type = ?
                AND r.sent_to_management = 1
          )
    ");
    $stmt->execute([$daysRemaining, $reminderType]);
    $leaseIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $sent = 0;
    foreach ($leaseIds as $leaseId) {
        $leaseId = (int)$leaseId;
        if ($leaseId <= 0) {
            continue;
        }
        try {
            $result = send_lease_expiry_reminder($conn, $leaseId, $reminderType, true, false);
            if (!empty($result['success']) && !empty($result['messages'])) {
                $sent++;
            }
        } catch (Throwable $e) {
            $errors[] = "Lease {$leaseId} ({$reminderType}): " . $e->getMessage();
            error_log('lease_expiry_alert_cron: ' . $e->getMessage());
        }
    }
    $summary[] = "{$reminderType}: candidates " . count($leaseIds) . ", sent_ok {$sent}";
}

// Mark leases past end_date as expired (same as lease_auto_expire.php helper)
try {
    $exp = auto_check_lease_expiry($conn);
    $summary[] = 'auto_expire_leases: ' . (int)($exp['updated_count'] ?? 0) . ' row(s)';
} catch (Throwable $e) {
    $errors[] = 'auto_check_lease_expiry: ' . $e->getMessage();
}

$out = "Lease expiry alert cron — " . date('c') . "\n" . implode("\n", $summary) . "\n";
if ($errors) {
    $out .= "\nWarnings/errors:\n" . implode("\n", $errors) . "\n";
}

if (php_sapi_name() === 'cli') {
    echo $out;
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    echo nl2br(htmlspecialchars($out, ENT_QUOTES, 'UTF-8'));
}
