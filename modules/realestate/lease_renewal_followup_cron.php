<?php
/**
 * Real Estate — Lease renewal notice follow-up cron (daily)
 *
 * Sends one follow-up email per workflow per day, starting 7 days after
 * notice_sent_at. Stops when the workflow is no longer waiting for tenant action.
 *
 * CLI: php modules/realestate/lease_renewal_followup_cron.php
 * Web: /modules/realestate/lease_renewal_followup_cron.php?run_cron=1
 */

if (php_sapi_name() === 'cli') {
    chdir(__DIR__ . '/../..');
}

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/re_cron_helpers.php';
re_cron_guard();

require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/phpmailer_bootstrap.php';
require_once __DIR__ . '/../../includes/renewal_portal_urls.php';
require_once __DIR__ . '/includes/renewal_notice_pdf_generator.php';

/** @var PDO $conn */

function re_renewal_followup_send_mail(array $settings, string $to, string $toName, string $subject, string $html, ?string $attachmentPath = null): array {
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return ['ok' => false, 'error' => 'PHPMailer is not available.'];
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $settings['smtp_host'] ?? '';
        $mail->Port = (int)($settings['smtp_port'] ?? 587);
        $secure = strtolower($settings['smtp_secure'] ?? 'tls');
        if ($secure === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
        }

        $mail->SMTPAuth = !empty($settings['smtp_username']);
        if ($mail->SMTPAuth) {
            $mail->Username = $settings['smtp_username'];
            $mail->Password = $settings['smtp_password'] ?? '';
        }

        $fromEmail = $settings['from_email'] ?? $settings['smtp_username'] ?? 'no-reply@example.com';
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $settings['from_name'] ?? $fromEmail);
        $mail->addAddress($to, $toName);
        $mail->Subject = $subject;
        $mail->msgHTML($html);

        if ($attachmentPath && is_file($attachmentPath)) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath));
        }

        $mail->send();
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
    }
}

function re_renewal_followup_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$settings = $conn->query("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings || empty($settings['smtp_host']) || empty($settings['smtp_username'])) {
    $out = "lease_renewal_followup_cron: SMTP not configured/enabled.\n";
    echo php_sapi_name() === 'cli' ? $out : htmlspecialchars($out, ENT_QUOTES, 'UTF-8');
    exit(0);
}

$basePath = dirname(__DIR__, 2);
$eligibleStatuses = ['notice_sent', 'pending_response', 'negotiation', 'contract_ready'];
$statusPlaceholders = implode(',', array_fill(0, count($eligibleStatuses), '?'));

$sql = "
    SELECT rw.id AS workflow_id,
           rw.status AS workflow_status,
           rw.notice_sent_at,
           rw.renewal_notice_pdf_path,
           rw.current_rent,
           rw.proposed_rent,
           rw.proposed_start_date,
           rw.proposed_end_date,
           l.id AS lease_id,
           l.lease_number,
           l.company_id,
           u.unit_number,
           b.name AS building_name,
           t.first_name,
           t.last_name,
           t.email,
           c.name AS company_name
    FROM re_lease_renewal_workflows rw
    JOIN re_leases l ON l.id = rw.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = rw.tenant_id
    LEFT JOIN companies c ON c.id = l.company_id
    WHERE rw.notice_sent_at IS NOT NULL
      AND rw.notice_sent_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND rw.status IN ($statusPlaceholders)
      AND NOT EXISTS (
          SELECT 1
          FROM re_email_logs el
          WHERE el.related_type = 'lease_renewal_workflow'
            AND el.related_id = rw.id
            AND el.notification_type = 'lease_renewal_followup'
            AND DATE(el.created_at) = CURDATE()
      )
    ORDER BY rw.notice_sent_at ASC, rw.id ASC
";
$stmt = $conn->prepare($sql);
$stmt->execute($eligibleStatuses);
$workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totals = ['eligible' => count($workflows), 'sent' => 0, 'failed' => 0, 'skipped' => 0];

foreach ($workflows as $wf) {
    $workflowId = (int)$wf['workflow_id'];
    $companyId = (int)$wf['company_id'];
    $tenantEmail = trim((string)($wf['email'] ?? ''));
    $tenantName = trim((string)($wf['first_name'] ?? '') . ' ' . (string)($wf['last_name'] ?? ''));
    if ($tenantName === '') {
        $tenantName = 'Tenant';
    }

    $subject = 'Reminder: Lease renewal notice for ' . ((string)($wf['unit_number'] ?? 'your unit'));
    $logStmt = $conn->prepare("
        INSERT INTO re_email_logs
            (company_id, notification_type, recipient_email, subject, status, related_id, related_type)
        VALUES
            (?, 'lease_renewal_followup', ?, ?, 'pending', ?, 'lease_renewal_workflow')
    ");
    $logStmt->execute([$companyId, $tenantEmail ?: 'invalid-email', $subject, $workflowId]);
    $emailLogId = (int)$conn->lastInsertId();

    if (!$tenantEmail || !filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
        $conn->prepare("UPDATE re_email_logs SET status = 'skipped', error_message = ? WHERE id = ?")
            ->execute(['Tenant email missing or invalid.', $emailLogId]);
        $totals['skipped']++;
        continue;
    }

    $pdfRelative = trim((string)($wf['renewal_notice_pdf_path'] ?? ''));
    if ($pdfRelative === '' || !is_file($basePath . '/' . ltrim($pdfRelative, '/'))) {
        try {
            $pdfRelative = (new RenewalNoticePDFGenerator($conn, $companyId))->generateFromWorkflow($workflowId);
        } catch (Throwable $e) {
            // The follow-up can still be sent with the portal link if PDF generation fails.
            $pdfRelative = '';
            error_log('Renewal follow-up cron PDF generation failed for workflow ' . $workflowId . ': ' . $e->getMessage());
        }
    }
    $pdfAbs = $pdfRelative !== '' ? $basePath . '/' . ltrim($pdfRelative, '/') : null;

    $portalLink = renewal_portal_renewal_detail_link_html($workflowId);
    $portalUrl = renewal_portal_renewal_detail_abs_url($workflowId);
    $noticeDate = !empty($wf['notice_sent_at']) ? date('Y-m-d', strtotime((string)$wf['notice_sent_at'])) : '';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,sans-serif;line-height:1.6;color:#111;max-width:680px;margin:0 auto;">'
        . '<p>Dear ' . re_renewal_followup_h($tenantName) . ',</p>'
        . '<p>This is a friendly follow-up regarding your <strong>lease renewal notice</strong>'
        . ($noticeDate !== '' ? ' sent on <strong>' . re_renewal_followup_h($noticeDate) . '</strong>' : '')
        . '.</p>'
        . '<table style="border-collapse:collapse;width:100%;margin:12px 0;">'
        . '<tr><td style="border:1px solid #ddd;padding:8px;font-weight:600;">Lease</td><td style="border:1px solid #ddd;padding:8px;">' . re_renewal_followup_h((string)($wf['lease_number'] ?? '')) . '</td></tr>'
        . '<tr><td style="border:1px solid #ddd;padding:8px;font-weight:600;">Unit</td><td style="border:1px solid #ddd;padding:8px;">' . re_renewal_followup_h((string)($wf['building_name'] ?? '') . ' - ' . (string)($wf['unit_number'] ?? '')) . '</td></tr>'
        . '<tr><td style="border:1px solid #ddd;padding:8px;font-weight:600;">Current Rent</td><td style="border:1px solid #ddd;padding:8px;">' . number_format((float)($wf['current_rent'] ?? 0), 2) . ' AED / year</td></tr>'
        . '<tr><td style="border:1px solid #ddd;padding:8px;font-weight:600;">Proposed Rent</td><td style="border:1px solid #ddd;padding:8px;">' . number_format((float)($wf['proposed_rent'] ?? 0), 2) . ' AED / year</td></tr>'
        . '<tr><td style="border:1px solid #ddd;padding:8px;font-weight:600;">Renewal Period</td><td style="border:1px solid #ddd;padding:8px;">'
        . re_renewal_followup_h(!empty($wf['proposed_start_date']) ? date('Y-m-d', strtotime((string)$wf['proposed_start_date'])) : '—')
        . ' to '
        . re_renewal_followup_h(!empty($wf['proposed_end_date']) ? date('Y-m-d', strtotime((string)$wf['proposed_end_date'])) : '—')
        . '</td></tr>'
        . '</table>'
        . '<p>Please review the renewal details and respond through the Tenant Portal:</p>'
        . '<p>' . $portalLink . '</p>'
        . '<p style="font-size:12px;color:#555;">Direct link: ' . re_renewal_followup_h($portalUrl) . '</p>'
        . '<p>Regards,<br>' . re_renewal_followup_h((string)($wf['company_name'] ?? 'Real Estate')) . '</p>'
        . '</body></html>';

    $send = re_renewal_followup_send_mail(
        $settings,
        $tenantEmail,
        $tenantName,
        $subject,
        $html,
        ($pdfAbs && is_file($pdfAbs)) ? $pdfAbs : null
    );

    if (!empty($send['ok'])) {
        $conn->prepare("UPDATE re_email_logs SET status = 'sent', sent_at = NOW(), error_message = NULL WHERE id = ?")
            ->execute([$emailLogId]);
        $totals['sent']++;
    } else {
        $conn->prepare("UPDATE re_email_logs SET status = 'failed', error_message = ? WHERE id = ?")
            ->execute([(string)($send['error'] ?? 'Unknown mail error'), $emailLogId]);
        $totals['failed']++;
    }
}

$out = "Lease renewal follow-up cron — " . date('c') . "\n"
    . "Eligible: {$totals['eligible']}\n"
    . "Sent: {$totals['sent']}\n"
    . "Skipped: {$totals['skipped']}\n"
    . "Failed: {$totals['failed']}\n";

if (php_sapi_name() === 'cli') {
    echo $out;
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    echo htmlspecialchars($out, ENT_QUOTES, 'UTF-8');
}

