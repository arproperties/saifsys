<?php
/**
 * ARS Bulk Email — pick guests, send one private email each, keep a log.
 * Built the same way as the Real Estate bulk tenant email.
 */

require_once dirname(__DIR__, 3) . '/includes/mailer.php';
require_once __DIR__ . '/ars_booking_balance.php';

if (!defined('ARS_BULK_EMAIL_BATCH_SIZE')) {
    define('ARS_BULK_EMAIL_BATCH_SIZE', 10);
}
if (!defined('ARS_BULK_EMAIL_MAX_FILES')) {
    define('ARS_BULK_EMAIL_MAX_FILES', 3);
}
if (!defined('ARS_BULK_EMAIL_MAX_ATTACH_BYTES')) {
    define('ARS_BULK_EMAIL_MAX_ATTACH_BYTES', 5 * 1024 * 1024);
}
if (!defined('ARS_BULK_EMAIL_STALE_SENDING_SECONDS')) {
    define('ARS_BULK_EMAIL_STALE_SENDING_SECONDS', 300);
}
// A run stops after this many failures in a row: the mail server has most
// likely hit its hourly limit, and the rest stay pending for "Continue".
if (!defined('ARS_BULK_EMAIL_MAX_FAILS_IN_A_ROW')) {
    define('ARS_BULK_EMAIL_MAX_FAILS_IN_A_ROW', 3);
}

function ars_bulk_email_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_bulk_email_campaigns (
            id INT(11) NOT NULL AUTO_INCREMENT,
            company_id INT(11) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_text MEDIUMTEXT NOT NULL,
            attachments_json TEXT NULL DEFAULT NULL,
            filters_json TEXT NULL DEFAULT NULL,
            selected_count INT(11) NOT NULL DEFAULT 0,
            unique_email_count INT(11) NOT NULL DEFAULT 0,
            excluded_no_email_count INT(11) NOT NULL DEFAULT 0,
            sent_count INT(11) NOT NULL DEFAULT 0,
            failed_count INT(11) NOT NULL DEFAULT 0,
            pending_count INT(11) NOT NULL DEFAULT 0,
            status ENUM('queued','sending','completed','completed_with_errors') NOT NULL DEFAULT 'queued',
            created_by INT(11) NULL DEFAULT NULL,
            started_at DATETIME NULL DEFAULT NULL,
            finished_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ars_bulk_email_campaigns_company (company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_bulk_email_recipients (
            id INT(11) NOT NULL AUTO_INCREMENT,
            campaign_id INT(11) NOT NULL,
            company_id INT(11) NOT NULL,
            guest_id INT(11) NOT NULL,
            booking_id INT(11) NULL DEFAULT NULL,
            guest_name VARCHAR(255) NOT NULL DEFAULT '',
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            booking_number VARCHAR(30) NOT NULL DEFAULT '',
            building_name VARCHAR(255) NOT NULL DEFAULT '',
            unit_number VARCHAR(100) NOT NULL DEFAULT '',
            check_in DATE NULL DEFAULT NULL,
            check_out DATE NULL DEFAULT NULL,
            email VARCHAR(190) NOT NULL,
            status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
            error_message VARCHAR(500) NULL DEFAULT NULL,
            sent_at DATETIME NULL DEFAULT NULL,
            attempts INT(11) NOT NULL DEFAULT 0,
            last_attempt_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ars_bulk_email_campaign_email (campaign_id, email),
            KEY idx_ars_bulk_email_recipients_status (campaign_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/** @return array<string,string> audience id => label */
function ars_bulk_email_audiences(): array
{
    return [
        'in_house' => 'In-house now',
        'upcoming' => 'Arriving (confirmed)',
        'balance_due' => 'Balance due',
        'past' => 'Past guests',
        'all' => 'All guests',
    ];
}

function ars_bulk_email_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function ars_bulk_email_is_valid_email(string $email): bool
{
    $n = ars_bulk_email_normalize_email($email);
    return $n !== '' && (bool)filter_var($n, FILTER_VALIDATE_EMAIL);
}

function ars_bulk_email_today(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
}

/**
 * Buildings that have at least one ARS booking, for the filter dropdown.
 *
 * @return list<array{id:int,name:string}>
 */
function ars_bulk_email_buildings(PDO $conn, int $companyId): array
{
    $st = $conn->prepare("
        SELECT DISTINCT bl.id, bl.name
        FROM ars_bookings b
        INNER JOIN re_units u ON u.id = b.unit_id
        INNER JOIN re_buildings bl ON bl.id = u.building_id
        WHERE b.company_id = ?
        ORDER BY bl.name
    ");
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Guests for one audience, one row per guest, each with the booking the
 * placeholders are filled from. Always read from the database: the compose
 * page, the preview and the final send all call this with the same filters.
 *
 * @param array{audience?:string,building_id?:int,q?:string} $filters
 * @return array{eligible:list<array>,excluded:list<array>}
 */
function ars_bulk_email_list_candidates(PDO $conn, int $companyId, array $filters): array
{
    $audience = (string)($filters['audience'] ?? 'in_house');
    if (!isset(ars_bulk_email_audiences()[$audience])) {
        $audience = 'in_house';
    }
    $buildingId = (int)($filters['building_id'] ?? 0);
    $search = trim((string)($filters['q'] ?? ''));

    $statusSql = [
        'in_house' => "b.status = 'checked_in'",
        'upcoming' => "b.status = 'confirmed' AND b.check_in >= ?",
        'balance_due' => "b.status IN ('confirmed','checked_in')",
        'past' => "b.status IN ('checked_out','completed')",
        'all' => "b.status NOT IN ('cancelled','expired')",
    ][$audience];

    $where = ['b.company_id = ?', 'g.company_id = ?', $statusSql];
    $params = [$companyId, $companyId];
    if ($audience === 'upcoming') {
        $params[] = ars_bulk_email_today();
    }
    if ($buildingId > 0) {
        $where[] = 'u.building_id = ?';
        $params[] = $buildingId;
    }

    // The first booking kept per guest is the one the email talks about:
    // the nearest arrival for "upcoming", otherwise the latest stay.
    $order = $audience === 'upcoming' ? 'b.check_in ASC, b.id ASC' : 'b.check_in DESC, b.id DESC';
    $st = $conn->prepare("
        SELECT b.id, b.guest_id, b.booking_number, b.status, b.check_in, b.check_out,
               b.total_amount, b.paid_amount, b.balance_due, b.payment_status,
               g.first_name, g.last_name, g.email, g.phone,
               u.unit_number, bl.name AS building_name
        FROM ars_bookings b
        INNER JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bl ON bl.id = u.building_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY {$order}
    ");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($audience === 'balance_due') {
        // Same definition as the Command Center "Payment follow-up" tile.
        $rows = ars_booking_rows_apply_balances($conn, $companyId, $rows, true);
        $rows = array_values(array_filter($rows, static fn($r) => (float)$r['balance_due'] > 0.009));
    }

    $byGuest = [];
    foreach ($rows as $r) {
        $gid = (int)$r['guest_id'];
        if (isset($byGuest[$gid])) {
            continue;
        }
        $byGuest[$gid] = [
            'guest_id' => $gid,
            'booking_id' => (int)$r['id'],
            'first_name' => (string)$r['first_name'],
            'last_name' => (string)$r['last_name'],
            'email' => (string)($r['email'] ?? ''),
            'phone' => (string)($r['phone'] ?? ''),
            'booking_number' => (string)$r['booking_number'],
            'booking_status' => (string)$r['status'],
            'check_in' => (string)$r['check_in'],
            'check_out' => (string)$r['check_out'],
            'unit_number' => (string)($r['unit_number'] ?? ''),
            'building_name' => (string)($r['building_name'] ?? ''),
        ];
    }

    // "All guests" also takes guests who never booked, unless a building is picked.
    if ($audience === 'all' && $buildingId <= 0) {
        $gs = $conn->prepare('SELECT id, first_name, last_name, email, phone FROM ars_guests WHERE company_id = ?');
        $gs->execute([$companyId]);
        foreach ($gs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $g) {
            $gid = (int)$g['id'];
            if (isset($byGuest[$gid])) {
                continue;
            }
            $byGuest[$gid] = [
                'guest_id' => $gid,
                'booking_id' => 0,
                'first_name' => (string)$g['first_name'],
                'last_name' => (string)$g['last_name'],
                'email' => (string)($g['email'] ?? ''),
                'phone' => (string)($g['phone'] ?? ''),
                'booking_number' => '',
                'booking_status' => '',
                'check_in' => '',
                'check_out' => '',
                'unit_number' => '',
                'building_name' => '',
            ];
        }
    }

    $eligible = [];
    $excluded = [];
    foreach ($byGuest as $r) {
        $r['guest_name'] = trim($r['first_name'] . ' ' . $r['last_name']) ?: ('Guest #' . $r['guest_id']);
        if ($search !== '') {
            $hay = $r['guest_name'] . ' ' . $r['email'] . ' ' . $r['phone'] . ' ' . $r['unit_number'] . ' ' . $r['booking_number'];
            if (stripos($hay, $search) === false) {
                continue;
            }
        }
        $email = trim($r['email']);
        if (ars_bulk_email_is_valid_email($email)) {
            $r['email'] = ars_bulk_email_normalize_email($email);
            $eligible[] = $r;
        } else {
            $r['exclude_reason'] = $email === '' ? 'No email' : 'Invalid email';
            $excluded[] = $r;
        }
    }
    $byName = static fn($a, $b) => strcasecmp($a['guest_name'], $b['guest_name']);
    usort($eligible, $byName);
    usort($excluded, $byName);

    return ['eligible' => $eligible, 'excluded' => $excluded];
}

/**
 * Turn ticked guest ids into the final recipient list. Read again from the
 * database with the same filters, so a browser cannot add anyone, and
 * guests sharing one address get a single email.
 *
 * @param list<int> $guestIds
 * @return array{ok:bool,error?:string,recipients?:list<array>,selected_count?:int,excluded_count?:int}
 */
function ars_bulk_email_validate_selection(PDO $conn, int $companyId, array $guestIds, array $filters): array
{
    $guestIds = array_values(array_unique(array_filter(array_map('intval', $guestIds), static fn($id) => $id > 0)));
    if (!$guestIds) {
        return ['ok' => false, 'error' => 'Select at least one guest.'];
    }
    $wanted = array_flip($guestIds);
    $list = ars_bulk_email_list_candidates($conn, $companyId, $filters);

    $byEmail = [];
    foreach ($list['eligible'] as $r) {
        if (isset($wanted[$r['guest_id']]) && !isset($byEmail[$r['email']])) {
            $byEmail[$r['email']] = $r;
        }
    }
    $recipients = array_values($byEmail);
    if (!$recipients) {
        return ['ok' => false, 'error' => 'None of the selected guests has a valid email for this filter.'];
    }
    return [
        'ok' => true,
        'recipients' => $recipients,
        'selected_count' => count($guestIds),
        'excluded_count' => count($guestIds) - count($recipients),
    ];
}

function ars_bulk_email_format_date(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : '';
}

/** @return array<string,string> placeholder => value for one recipient row */
function ars_bulk_email_placeholder_values(array $r): array
{
    return [
        '{{guest_name}}' => (string)($r['guest_name'] ?? ''),
        '{{first_name}}' => (string)($r['first_name'] ?? ''),
        '{{unit_number}}' => (string)($r['unit_number'] ?? ''),
        '{{building_name}}' => (string)($r['building_name'] ?? ''),
        '{{check_in}}' => ars_bulk_email_format_date($r['check_in'] ?? ''),
        '{{check_out}}' => ars_bulk_email_format_date($r['check_out'] ?? ''),
        '{{booking_number}}' => (string)($r['booking_number'] ?? ''),
    ];
}

function ars_bulk_email_render_subject(string $subject, array $r): string
{
    $map = ars_bulk_email_placeholder_values($r);
    return trim(str_replace(array_keys($map), array_values($map), $subject));
}

/** The message is typed as plain text; this is the HTML that is emailed. */
function ars_bulk_email_render_body(string $bodyText, array $r): string
{
    $map = ars_bulk_email_placeholder_values($r);
    $text = str_replace(array_keys($map), array_values($map), $bodyText);
    $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#222">' . $html . '</div>';
}

function ars_bulk_email_storage_dir(int $companyId): string
{
    return dirname(__DIR__, 3) . '/uploads/ars_bulk_email/' . $companyId;
}

/**
 * Save the uploaded files of one compose form ($_FILES['attachments']).
 *
 * @return array{ok:bool,files?:list<array{path:string,name:string,size:int}>,error?:string}
 */
function ars_bulk_email_store_attachments(int $companyId, array $files): array
{
    $names = $files['name'] ?? [];
    if (!is_array($names)) {
        return ['ok' => true, 'files' => []];
    }
    $picked = [];
    foreach ($names as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $picked[] = $i;
    }
    if (!$picked) {
        return ['ok' => true, 'files' => []];
    }
    if (count($picked) > ARS_BULK_EMAIL_MAX_FILES) {
        return ['ok' => false, 'error' => 'Attach at most ' . ARS_BULK_EMAIL_MAX_FILES . ' files.'];
    }

    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($picked as $i) {
        $name = (string)$files['name'][$i];
        if (($files['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Could not upload ' . $name . '.'];
        }
        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0 || $size > ARS_BULK_EMAIL_MAX_ATTACH_BYTES) {
            return ['ok' => false, 'error' => $name . ' is larger than 5MB.'];
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        if (!isset($allowed[$ext]) || $tmp === '' || !is_uploaded_file($tmp)
            || !in_array((string)$finfo->file($tmp), $allowed[$ext], true)) {
            return ['ok' => false, 'error' => $name . ' is not allowed. Use PDF, JPG, PNG, DOC, DOCX or XLSX.'];
        }
    }

    $dir = ars_bulk_email_storage_dir($companyId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create the folder uploads/ars_bulk_email. Make uploads writable.'];
    }
    // Only PHP reads these files (to attach them), so the folder is closed to the web.
    $guard = dirname($dir) . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }

    $saved = [];
    foreach ($picked as $i) {
        $name = (string)$files['name'][$i];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stored = date('YmdHis') . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file((string)$files['tmp_name'][$i], $dir . '/' . $stored)) {
            foreach ($saved as $s) {
                @unlink(dirname(__DIR__, 3) . '/' . $s['path']);
            }
            return ['ok' => false, 'error' => 'Could not save ' . $name . '.'];
        }
        $saved[] = [
            'path' => 'uploads/ars_bulk_email/' . $companyId . '/' . $stored,
            'name' => basename($name),
            'size' => (int)$files['size'][$i],
        ];
    }
    return ['ok' => true, 'files' => $saved];
}

/** Remove files of a draft that was cancelled before sending. */
function ars_bulk_email_delete_files(int $companyId, array $files): void
{
    $prefix = 'uploads/ars_bulk_email/' . $companyId . '/';
    foreach ($files as $f) {
        $path = (string)($f['path'] ?? '');
        if (strpos($path, $prefix) === 0 && strpos($path, '..') === false) {
            @unlink(dirname(__DIR__, 3) . '/' . $path);
        }
    }
}

/**
 * @param list<array> $recipients from ars_bulk_email_validate_selection
 * @return array{ok:bool,campaign_id?:int,error?:string}
 */
function ars_bulk_email_create_campaign(
    PDO $conn,
    int $companyId,
    int $userId,
    string $subject,
    string $bodyText,
    array $recipients,
    int $selectedCount,
    int $excludedCount,
    array $filters,
    array $attachments
): array {
    $subject = trim($subject);
    $bodyText = trim($bodyText);
    if ($subject === '') {
        return ['ok' => false, 'error' => 'Subject is required.'];
    }
    if ($bodyText === '') {
        return ['ok' => false, 'error' => 'Message is required.'];
    }
    if (!$recipients) {
        return ['ok' => false, 'error' => 'No recipients.'];
    }

    try {
        $conn->beginTransaction();
        $conn->prepare("
            INSERT INTO ars_bulk_email_campaigns
            (company_id, subject, body_text, attachments_json, filters_json,
             selected_count, unique_email_count, excluded_no_email_count, pending_count, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,'queued',?)
        ")->execute([
            $companyId,
            $subject,
            $bodyText,
            $attachments ? json_encode(array_values($attachments), JSON_UNESCAPED_UNICODE) : null,
            json_encode($filters, JSON_UNESCAPED_UNICODE),
            $selectedCount,
            count($recipients),
            $excludedCount,
            count($recipients),
            $userId > 0 ? $userId : null,
        ]);
        $campaignId = (int)$conn->lastInsertId();

        $ins = $conn->prepare("
            INSERT INTO ars_bulk_email_recipients
            (campaign_id, company_id, guest_id, booking_id, guest_name, first_name, booking_number,
             building_name, unit_number, check_in, check_out, email, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'pending')
        ");
        foreach ($recipients as $r) {
            $ins->execute([
                $campaignId,
                $companyId,
                (int)$r['guest_id'],
                (int)$r['booking_id'] > 0 ? (int)$r['booking_id'] : null,
                (string)$r['guest_name'],
                (string)$r['first_name'],
                (string)$r['booking_number'],
                (string)$r['building_name'],
                (string)$r['unit_number'],
                $r['check_in'] !== '' ? $r['check_in'] : null,
                $r['check_out'] !== '' ? $r['check_out'] : null,
                (string)$r['email'],
            ]);
        }
        $conn->commit();
        return ['ok' => true, 'campaign_id' => $campaignId];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not save the email: ' . $e->getMessage()];
    }
}

function ars_bulk_email_get_campaign(PDO $conn, int $companyId, int $campaignId): ?array
{
    $st = $conn->prepare('SELECT * FROM ars_bulk_email_campaigns WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$campaignId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @return list<array{path:string,name:string,size:int}> */
function ars_bulk_email_campaign_files(array $campaign): array
{
    $files = json_decode((string)($campaign['attachments_json'] ?? ''), true);
    return is_array($files) ? $files : [];
}

function ars_bulk_email_refresh_counts(PDO $conn, int $companyId, int $campaignId): void
{
    $st = $conn->prepare("
        SELECT SUM(status = 'sent') AS sent_count,
               SUM(status = 'failed') AS failed_count,
               SUM(status IN ('pending','sending')) AS pending_count
        FROM ars_bulk_email_recipients
        WHERE campaign_id = ? AND company_id = ?
    ");
    $st->execute([$campaignId, $companyId]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $sent = (int)($c['sent_count'] ?? 0);
    $failed = (int)($c['failed_count'] ?? 0);
    $pending = (int)($c['pending_count'] ?? 0);

    $status = 'sending';
    $finished = null;
    if ($pending === 0) {
        $status = $failed > 0 ? 'completed_with_errors' : 'completed';
        $finished = date('Y-m-d H:i:s');
    }
    $conn->prepare("
        UPDATE ars_bulk_email_campaigns
        SET sent_count = ?, failed_count = ?, pending_count = ?, status = ?, finished_at = ?
        WHERE id = ? AND company_id = ?
    ")->execute([$sent, $failed, $pending, $status, $finished, $campaignId, $companyId]);
}

/**
 * Send to the next pending recipients. Safe to call again after a dropped
 * connection: a recipient is claimed before sending and never sent twice.
 *
 * @return array{ok:bool,processed:int,sent:int,failed:int,pending_remaining:int,done?:bool,stopped?:bool,error?:string}
 */
function ars_bulk_email_process_batch(PDO $conn, int $companyId, int $campaignId, int $limit = ARS_BULK_EMAIL_BATCH_SIZE): array
{
    $none = ['ok' => false, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'pending_remaining' => 0];
    $campaign = ars_bulk_email_get_campaign($conn, $companyId, $campaignId);
    if (!$campaign) {
        return $none + ['error' => 'Email not found.'];
    }

    $settingsSt = $conn->prepare('SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1');
    $settingsSt->execute();
    $settings = $settingsSt->fetch(PDO::FETCH_ASSOC);
    if (!$settings || empty($settings['smtp_host'])) {
        return $none + ['error' => 'Email is not set up or is switched off in Email Settings.'];
    }

    $conn->prepare("UPDATE ars_bulk_email_campaigns SET status = 'sending', started_at = COALESCE(started_at, NOW()) WHERE id = ? AND company_id = ?")
        ->execute([$campaignId, $companyId]);

    // Release claims left behind by a run that died mid-send.
    $conn->prepare("
        UPDATE ars_bulk_email_recipients
        SET status = 'pending'
        WHERE campaign_id = ? AND company_id = ? AND status = 'sending'
          AND (last_attempt_at IS NULL OR last_attempt_at < (NOW() - INTERVAL " . (int)ARS_BULK_EMAIL_STALE_SENDING_SECONDS . " SECOND))
    ")->execute([$campaignId, $companyId]);

    $limit = max(1, min(50, $limit));
    $pick = $conn->prepare("
        SELECT * FROM ars_bulk_email_recipients
        WHERE campaign_id = ? AND company_id = ? AND status = 'pending'
        ORDER BY id ASC
        LIMIT {$limit}
    ");
    $pick->execute([$campaignId, $companyId]);
    $rows = $pick->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $attachments = [];
    foreach (ars_bulk_email_campaign_files($campaign) as $f) {
        $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$f['path'], '/');
        if (!is_file($abs)) {
            return $none + ['error' => 'Attachment "' . $f['name'] . '" is missing on the server. Nothing was sent.'];
        }
        $attachments[] = ['path' => $abs, 'name' => (string)$f['name']];
    }

    $claim = $conn->prepare("
        UPDATE ars_bulk_email_recipients
        SET status = 'sending', attempts = attempts + 1, last_attempt_at = NOW()
        WHERE id = ? AND status = 'pending'
    ");
    $markSent = $conn->prepare("UPDATE ars_bulk_email_recipients SET status = 'sent', sent_at = NOW(), error_message = NULL WHERE id = ? AND status = 'sending'");
    $markFailed = $conn->prepare("UPDATE ars_bulk_email_recipients SET status = 'failed', error_message = ? WHERE id = ? AND status = 'sending'");

    $sent = 0;
    $failed = 0;
    $failsInARow = 0;
    $stopped = false;
    foreach ($rows as $row) {
        $rid = (int)$row['id'];
        $claim->execute([$rid]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $subject = ars_bulk_email_render_subject((string)$campaign['subject'], $row);
        $body = ars_bulk_email_render_body((string)$campaign['body_text'], $row);

        $result = $attachments
            ? send_smtp_mail_with_attachments($settings, (string)$row['email'], $subject, $body, $attachments)
            : send_smtp_mail($settings, (string)$row['email'], $subject, $body);

        if (!empty($result['ok'])) {
            $markSent->execute([$rid]);
            $sent++;
            $failsInARow = 0;
            continue;
        }
        $markFailed->execute([substr((string)($result['error'] ?? 'Send failed'), 0, 500), $rid]);
        $failed++;
        if (++$failsInARow >= ARS_BULK_EMAIL_MAX_FAILS_IN_A_ROW) {
            $stopped = true;
            break;
        }
    }

    ars_bulk_email_refresh_counts($conn, $companyId, $campaignId);
    $fresh = ars_bulk_email_get_campaign($conn, $companyId, $campaignId);
    $pendingLeft = (int)($fresh['pending_count'] ?? 0);

    return [
        'ok' => true,
        'processed' => $sent + $failed,
        'sent' => $sent,
        'failed' => $failed,
        'pending_remaining' => $pendingLeft,
        'done' => $pendingLeft === 0,
        'stopped' => $stopped,
        'last_error' => $stopped ? (string)($result['error'] ?? '') : '',
        'campaign_status' => $fresh['status'] ?? null,
    ];
}

/** Put failed recipients back in the queue. Sent ones are never touched. */
function ars_bulk_email_retry_failed(PDO $conn, int $companyId, int $campaignId): int
{
    $st = $conn->prepare("
        UPDATE ars_bulk_email_recipients
        SET status = 'pending', error_message = NULL
        WHERE campaign_id = ? AND company_id = ? AND status = 'failed'
    ");
    $st->execute([$campaignId, $companyId]);
    $n = $st->rowCount();
    if ($n > 0) {
        ars_bulk_email_refresh_counts($conn, $companyId, $campaignId);
    }
    return $n;
}

function ars_bulk_email_status_label(string $status): string
{
    return [
        'queued' => 'Waiting',
        'sending' => 'Sending',
        'completed' => 'Sent',
        'completed_with_errors' => 'Sent, some failed',
    ][$status] ?? $status;
}
