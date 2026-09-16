<?php
// cron/airbnb_sync.php
// Reads new Airbnb emails from the ARS Gmail and creates/cancels ARS bookings.
// Hostinger cron (every 10 minutes):
//   */10 * * * * /usr/bin/php /home/<user>/public_html/cron/airbnb_sync.php >/dev/null 2>&1
// Emails the ARS "booking notification emails" when something needs staff, at most once per
// problem per 6 hours.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/ars/includes/ars_airbnb_sync.php';

$companyId = getArsCompanyId($conn);
if ($companyId <= 0) {
    fwrite(STDERR, "ARS company not found\n");
    exit(1);
}

$result = ars_airbnb_run_sync($conn, $companyId);
echo date('Y-m-d H:i:s') . ' ' . json_encode($result) . "\n";

ars_airbnb_send_alerts($conn, $companyId);

exit($result['ok'] ? 0 : 1);

function ars_airbnb_send_alerts(PDO $conn, int $companyId): void
{
    if (!ars_airbnb_tables_ready($conn)) return;

    $problems = ars_airbnb_health_warnings($conn, $companyId);
    $st = $conn->prepare("SELECT COUNT(*) FROM ars_channel_emails WHERE company_id = ? AND status = 'needs_review'");
    $st->execute([$companyId]);
    $reviewCount = (int)$st->fetchColumn();
    if ($reviewCount > 0) {
        $problems[] = $reviewCount . ' Airbnb email' . ($reviewCount === 1 ? '' : 's') . ' need staff attention.';
    }
    if (!$problems) return;

    // Throttle: same set of problems → one email per 6 hours.
    $fingerprint = md5(implode('|', $problems));
    $marker = sys_get_temp_dir() . '/ars_airbnb_alert_' . $companyId . '.json';
    $last = is_file($marker) ? (json_decode((string)file_get_contents($marker), true) ?: []) : [];
    if (($last['fingerprint'] ?? '') === $fingerprint && (int)($last['at'] ?? 0) > time() - 6 * 3600) {
        return;
    }

    $settings = getArsSettings($conn, $companyId);
    $to = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($settings['booking_notification_emails'] ?? ''))), static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
    $smtp = $conn->query('SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$to || !$smtp) {
        error_log('[ARS Airbnb sync] alert not sent (no ARS booking notification emails or SMTP disabled): ' . implode(' ', $problems));
        return;
    }

    require_once __DIR__ . '/../includes/mailer.php';
    $base = defined('APP_BASE_URL') ? rtrim((string)APP_BASE_URL, '/') : '';
    $link = $base !== '' ? $base . '/modules/ars/airbnb_sync.php' : 'ARS → Airbnb Sync';
    $html = '<p>The Airbnb booking sync needs attention:</p><ul>';
    foreach ($problems as $p) {
        $html .= '<li>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul><p>Open: ' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</p>';

    $sent = false;
    foreach ($to as $addr) {
        $r = send_smtp_mail($smtp, $addr, 'ARS: Airbnb sync needs attention', $html);
        $sent = $sent || !empty($r['ok']);
    }
    if ($sent) {
        @file_put_contents($marker, json_encode(['fingerprint' => $fingerprint, 'at' => time()]));
    }
}
