<?php
/**
 * Move-Out Inspection Appointment (migrations/re_move_out_appointment.sql).
 *
 * The final inspection is booked on the move-out day (usual) or on another
 * date, with a time window; the date is re_move_outs.inspection_scheduled_date.
 * The tenant is emailed the appointment (English, with a calendar invite and
 * what to prepare) and reminded: on demand from the move-out page, and
 * automatically the day before and on the morning of the inspection
 * (cron/move_out_inspection_reminders.php). Every change and email is logged
 * in re_move_out_appointment_log; emails also in re_email_logs.
 */

const RMOA_EMAIL_TYPES = [
    'confirmation' => 'Appointment confirmation',
    'reminder' => 'Reminder',
    'auto_d1' => 'Automatic reminder (day before)',
    'auto_d0' => 'Automatic reminder (on the day)',
];

function rmoa_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM re_move_outs LIKE 'inspection_time_from'")->fetch()
                && (bool)$conn->query("SHOW TABLES LIKE 're_move_out_appointment_log'")->fetchColumn();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The move-out with what the appointment emails need. */
function rmoa_load(PDO $conn, int $companyId, int $moveOutId): ?array
{
    $st = $conn->prepare("
        SELECT mo.*, l.lease_number, u.unit_number, b.name AS building_name, b.address AS building_address,
               t.first_name, t.last_name, t.email AS tenant_email, c.name AS company_name
        FROM re_move_outs mo
        JOIN re_leases l ON l.id = mo.lease_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN companies c ON c.id = mo.company_id
        WHERE mo.id = ? AND mo.company_id = ?
    ");
    $st->execute([$moveOutId, $companyId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** "10:00 – 12:00", "from 10:00" or "" */
function rmoa_window_text(?string $from, ?string $to): string
{
    $f = $from ? date('H:i', strtotime($from)) : '';
    $t = $to ? date('H:i', strtotime($to)) : '';
    if ($f !== '' && $t !== '') {
        return $f . ' – ' . $t;
    }
    return $f !== '' ? 'from ' . $f : '';
}

/** One line for pages and emails, e.g. "Sat 24 Oct 2026, 10:00 – 12:00". */
function rmoa_when_text(array $mo): string
{
    if (empty($mo['inspection_scheduled_date'])) {
        return '';
    }
    $w = rmoa_window_text($mo['inspection_time_from'] ?? null, $mo['inspection_time_to'] ?? null);
    return date('D d M Y', strtotime($mo['inspection_scheduled_date'])) . ($w !== '' ? ', ' . $w : '');
}

function rmoa_log(PDO $conn, int $companyId, int $moveOutId, string $action, array $mo, ?string $note, ?int $userId, ?string $emailTo = null, ?bool $emailOk = null): void
{
    $conn->prepare("
        INSERT INTO re_move_out_appointment_log
            (company_id, move_out_id, action, appt_date, time_from, time_to, note, email_to, email_ok, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $companyId, $moveOutId, $action, $mo['inspection_scheduled_date'] ?? null, $mo['inspection_time_from'] ?? null, $mo['inspection_time_to'] ?? null,
        $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null, $emailTo, $emailOk === null ? null : ($emailOk ? 1 : 0), $userId,
    ]);
}

function rmoa_history(PDO $conn, int $moveOutId): array
{
    if (!rmoa_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("
        SELECT a.*, u.username AS by_name FROM re_move_out_appointment_log a
        LEFT JOIN user u ON u.id = a.created_by
        WHERE a.move_out_id = ? ORDER BY a.created_at DESC, a.id DESC
    ");
    $st->execute([$moveOutId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Calendar invite (.ics) for the appointment, Dubai time. */
function rmoa_ics(array $mo): string
{
    $date = (string)$mo['inspection_scheduled_date'];
    $from = $mo['inspection_time_from'] ?: '09:00:00';
    $to = $mo['inspection_time_to'] ?: date('H:i:s', strtotime($from) + 7200);
    $dt = static fn(string $t) => date('Ymd', strtotime($date)) . 'T' . date('His', strtotime($t));
    $esc = static fn(string $v) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\\;', '\\,', '\\n', '\\n'], $v);
    $where = trim($mo['building_name'] . ', Unit ' . $mo['unit_number'] . (!empty($mo['building_address']) ? ', ' . $mo['building_address'] : ''));
    return implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SaifSys//Move-Out Inspection//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VTIMEZONE', 'TZID:Asia/Dubai', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0400', 'TZOFFSETTO:+0400', 'TZNAME:GST', 'END:STANDARD', 'END:VTIMEZONE',
        'BEGIN:VEVENT',
        'UID:move-out-inspection-' . (int)$mo['id'] . '-' . date('Ymd', strtotime($date)) . '@saifsys',
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART;TZID=Asia/Dubai:' . $dt($from),
        'DTEND;TZID=Asia/Dubai:' . $dt($to),
        'SUMMARY:' . $esc('Move-out inspection - ' . $mo['building_name'] . ' ' . $mo['unit_number']),
        'LOCATION:' . $esc($where),
        'DESCRIPTION:' . $esc('Final move-out inspection of Unit ' . $mo['unit_number'] . ' (lease ' . $mo['lease_number'] . '). Please bring all keys and access cards.'),
        'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY', 'DESCRIPTION:Move-out inspection', 'END:VALARM',
        'END:VEVENT', 'END:VCALENDAR', '',
    ]);
}

/**
 * The details a saved template fills in, as {placeholder} => value. $html
 * gives the values HTML-escaped (for the body), else plain (for the subject).
 */
function rmoa_placeholders(array $mo, bool $html): array
{
    $e = static fn($v) => $html ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : (string)$v;
    return [
        '{tenant}' => $e(trim($mo['first_name'] . ' ' . $mo['last_name'])),
        '{when}' => $e(rmoa_when_text($mo)),
        '{date}' => $e(!empty($mo['inspection_scheduled_date']) ? date('d M Y', strtotime($mo['inspection_scheduled_date'])) : ''),
        '{unit}' => $e('Unit ' . $mo['unit_number'] . ', ' . $mo['building_name']),
        '{address}' => $e($mo['building_address'] ?? ''),
        '{lease}' => $e($mo['lease_number']),
        '{note}' => $e($mo['inspection_access_note'] ?? ''),
        '{company}' => $e($mo['company_name'] ?: 'Property Management'),
    ];
}

/** Saved templates exist for these email types (the automatic reminders keep their own wording). */
const RMOA_TEMPLATE_TYPES = ['reminder', 'confirmation'];

function rmoa_template_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW TABLES LIKE 're_move_out_email_templates'")->fetchColumn();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The company's saved template for this email type: ['subject', 'body_html', ...] or null. */
function rmoa_template_get(PDO $conn, int $companyId, string $type): ?array
{
    if (!in_array($type, RMOA_TEMPLATE_TYPES, true) || !rmoa_template_ready($conn)) {
        return null;
    }
    $st = $conn->prepare("SELECT * FROM re_move_out_email_templates WHERE company_id = ? AND email_type = ?");
    $st->execute([$companyId, $type]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Save the email as edited for this move-out as the company's template: this
 * tenant's details (name, date and time, unit, address, lease, note, company)
 * are turned back into {placeholders}, so every later email fills in its own.
 */
function rmoa_template_save(PDO $conn, int $companyId, string $type, array $mo, string $subject, string $html, ?int $userId): void
{
    if (!in_array($type, RMOA_TEMPLATE_TYPES, true)) {
        throw new RuntimeException('This email type cannot be saved as a template.');
    }
    $toPlaceholders = static function (string $text, array ...$maps): string {
        $pairs = [];
        foreach ($maps as $map) {
            foreach ($map as $ph => $v) {
                if (trim((string)$v) !== '') {
                    $pairs[(string)$v] = $ph;
                }
            }
        }
        // strtr() tries the longest match first, so "Unit 5, Tower" wins over "Tower"
        return strtr($text, $pairs);
    };
    // The browser writes quotes in the edited message as plain characters
    $plainQuotes = array_map(static fn($v) => htmlspecialchars_decode((string)$v, ENT_QUOTES) === (string)$v ? $v : htmlspecialchars(htmlspecialchars_decode((string)$v, ENT_QUOTES), ENT_NOQUOTES, 'UTF-8'), rmoa_placeholders($mo, true));
    $subject = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $subject)), 0, 250);
    $body = rmoa_clean_html($html);
    if ($subject === '' || trim(strip_tags($body)) === '') {
        throw new RuntimeException('The subject and the message cannot be empty.');
    }
    $conn->prepare("
        INSERT INTO re_move_out_email_templates (company_id, email_type, subject, body_html, updated_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html), updated_by = VALUES(updated_by)
    ")->execute([$companyId, $type, $toPlaceholders($subject, rmoa_placeholders($mo, false)), $toPlaceholders($body, rmoa_placeholders($mo, true), $plainQuotes), $userId]);
}

function rmoa_template_delete(PDO $conn, int $companyId, string $type): void
{
    if (rmoa_template_ready($conn)) {
        $conn->prepare("DELETE FROM re_move_out_email_templates WHERE company_id = ? AND email_type = ?")->execute([$companyId, $type]);
    }
}

/**
 * [subject, html] of an appointment email (English): the company's saved
 * template for this type when there is one ($tpl), else the default wording.
 */
function rmoa_email(array $mo, string $type, ?array $tpl = null): array
{
    if ($tpl) {
        return [
            strtr((string)$tpl['subject'], rmoa_placeholders($mo, false)),
            '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#1f2937;max-width:620px">'
                // a detail row left empty for this tenant (e.g. no note) is dropped
                . preg_replace('#<tr\b[^>]*>(?:(?!</tr>).)*?<td\b[^>]*>\s*</td>\s*</tr>#is', '', strtr((string)$tpl['body_html'], rmoa_placeholders($mo, true))) . '</div>',
        ];
    }
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $tenant = trim($mo['first_name'] . ' ' . $mo['last_name']);
    $company = $mo['company_name'] ?: 'Property Management';
    $unit = 'Unit ' . $mo['unit_number'] . ', ' . $mo['building_name'];
    $when = rmoa_when_text($mo);
    switch ($type) {
        case 'confirmation':
            $subject = 'Move-Out Inspection Scheduled - ' . $unit;
            $intro = 'Your move-out inspection has been scheduled. Our team will inspect the unit with you and check the keys and access cards handed over at move-in.';
            break;
        case 'auto_d1':
            $subject = 'Reminder: Move-Out Inspection Tomorrow - ' . $unit;
            $intro = 'This is a gentle reminder that your move-out inspection is tomorrow.';
            break;
        case 'auto_d0':
            $subject = 'Reminder: Move-Out Inspection Today - ' . $unit;
            $intro = 'This is a gentle reminder that your move-out inspection is today.';
            break;
        default:
            $subject = 'Reminder: Move-Out Inspection on ' . date('d M Y', strtotime((string)$mo['inspection_scheduled_date'])) . ' - ' . $unit;
            $intro = 'This is a gentle reminder of your upcoming move-out inspection.';
    }
    $row = static fn(string $k, string $v) => '<tr><td style="padding:6px 12px 6px 0;color:#6b7280;white-space:nowrap;vertical-align:top">' . $k . '</td><td style="padding:6px 0;font-weight:600;color:#111827">' . $v . '</td></tr>';
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#1f2937;max-width:620px">'
        . '<p>Dear ' . $e($tenant) . ',</p>'
        . '<p>' . $e($intro) . '</p>'
        . '<table style="border-collapse:collapse;margin:14px 0;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:6px 14px;display:block">'
        . $row('Date &amp; time', $e($when))
        . $row('Property', $e($unit) . (!empty($mo['building_address']) ? '<br><span style="font-weight:400;color:#4b5563">' . $e($mo['building_address']) . '</span>' : ''))
        . $row('Lease', $e($mo['lease_number']))
        . (!empty($mo['inspection_access_note']) ? $row('Note', $e($mo['inspection_access_note'])) : '')
        . '</table>'
        . '<p style="margin-bottom:6px"><strong>Before the inspection, please:</strong></p>'
        . '<ul style="margin-top:0;padding-left:20px">'
        . '<li>Remove all personal belongings and furniture that is not part of the unit.</li>'
        . '<li>Leave the unit clean, including the kitchen, bathrooms and balcony.</li>'
        . '<li>Bring all keys, access cards, parking cards and remote controls.</li>'
        . '<li>Settle your final DEWA / chiller and other utility bills, and bring the final readings if you have them.</li>'
        . '<li>Attend the inspection in person.</li>'
        . '</ul>'
        . ($type === 'confirmation' ? '<p>A calendar invitation is attached for your convenience.</p>' : '')
        . '<p>If you have any concerns, please reply to this email as soon as possible.</p>'
        . '<p>Kind regards,<br>' . $e($company) . '</p>'
        . '</div>';
    return [$subject, $html];
}

/**
 * Email body edited by staff in the preview: keep simple formatting only (no
 * scripts, event handlers or javascript: links).
 */
function rmoa_clean_html(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html);
    $html = strip_tags($html, '<p><br><div><span><strong><b><em><i><u><ul><ol><li><table><tbody><thead><tr><td><th><a><h3><h4>');
    $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
    $html = preg_replace('#(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2#i', '$1="#"', $html);
    return trim($html);
}

/**
 * Email the tenant (confirmation or reminder). Logs it in re_email_logs and in
 * the appointment log. $override: 'subject' / 'html' edited in the preview.
 * Returns ['ok' => bool, 'error' => string].
 */
function rmoa_send(PDO $conn, int $companyId, int $moveOutId, string $type, string $to, ?int $userId, array $override = []): array
{
    $mo = rmoa_load($conn, $companyId, $moveOutId);
    if (!$mo || empty($mo['inspection_scheduled_date'])) {
        return ['ok' => false, 'error' => 'Set the inspection appointment first.'];
    }
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    $settings = $conn->query("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$settings || empty($settings['smtp_host'])) {
        return ['ok' => false, 'error' => 'Email is not set up. Configure SMTP in Email Settings first.'];
    }
    $root = dirname(__DIR__, 3);
    $quotaKey = null;
    if (is_file($root . '/includes/email_hourly_quota.php')) {
        require_once $root . '/includes/email_hourly_quota.php';
        $quotaKey = email_quota_account_key($settings);
        if (email_quota_remaining($conn, $quotaKey) <= 0) {
            return ['ok' => false, 'error' => 'The hourly email limit is reached. Try again after the top of the hour.'];
        }
    }
    require_once $root . '/includes/mailer.php';
    [$subject, $html] = rmoa_email($mo, $type, rmoa_template_get($conn, $companyId, $type));
    if (trim((string)($override['subject'] ?? '')) !== '') {
        $subject = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', (string)$override['subject'])), 0, 250);
    }
    if (trim(strip_tags((string)($override['html'] ?? ''))) !== '') {
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#1f2937;max-width:620px">'
            . rmoa_clean_html((string)$override['html']) . '</div>';
    }
    $attachments = [];
    $icsPath = null;
    if ($type === 'confirmation') {
        $icsPath = sys_get_temp_dir() . '/move_out_inspection_' . $moveOutId . '_' . bin2hex(random_bytes(4)) . '.ics';
        file_put_contents($icsPath, rmoa_ics($mo));
        $attachments[] = ['path' => $icsPath, 'name' => 'move-out-inspection.ics'];
    }
    $sent = send_smtp_mail_with_attachments($settings, $to, $subject, $html, $attachments);
    if ($icsPath) {
        @unlink($icsPath);
    }
    if ($quotaKey && $sent['ok']) {
        email_quota_record_send($conn, $quotaKey);
    } elseif ($quotaKey && email_quota_is_limit_error((string)$sent['error'])) {
        email_quota_mark_exhausted($conn, $quotaKey);
    }
    try {
        $conn->prepare("INSERT INTO re_email_logs (company_id, notification_type, recipient_email, subject, status, error_message, related_id, related_type, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, 're_move_outs', ?)")
            ->execute([$companyId, 'move_out_inspection_' . $type, $to, $subject, $sent['ok'] ? 'sent' : 'failed', $sent['ok'] ? null : $sent['error'], $moveOutId, $sent['ok'] ? date('Y-m-d H:i:s') : null]);
    } catch (Throwable $e) {
        error_log('Move-out appointment email log failed: ' . $e->getMessage());
    }
    rmoa_log($conn, $companyId, $moveOutId, 'email_' . $type, $mo, $sent['ok'] ? null : (string)$sent['error'], $userId, $to, (bool)$sent['ok']);
    return ['ok' => (bool)$sent['ok'], 'error' => (string)($sent['error'] ?? '')];
}
