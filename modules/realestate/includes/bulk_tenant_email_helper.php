<?php
/**
 * Bulk Tenant Email — helpers (authz, recipient validation, campaigns, send).
 */

require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/lease_lifecycle_guard.php';

if (!defined('RE_BULK_EMAIL_BATCH_SIZE')) {
    define('RE_BULK_EMAIL_BATCH_SIZE', 20);
}
if (!defined('RE_BULK_EMAIL_MAX_ATTACH_BYTES')) {
    define('RE_BULK_EMAIL_MAX_ATTACH_BYTES', 5 * 1024 * 1024);
}
if (!defined('RE_BULK_EMAIL_STALE_SENDING_SECONDS')) {
    define('RE_BULK_EMAIL_STALE_SENDING_SECONDS', 300);
}

/**
 * Strict gate: Real Estate Operations department (Owner/Admin included via has_department_access).
 * Does NOT fall back to general module access.
 *
 * @return int company_id
 */
function re_bulk_email_require_access(PDO $conn): int
{
    require_login();
    require_department_access(MODULE_REALESTATE, DEPT_REALESTATE_OPERATIONS, $conn);
    $companyId = (int)(current_company_id($conn) ?: 0);
    if ($companyId <= 0) {
        http_response_code(400);
        die('Company context is required.');
    }
    return $companyId;
}

function re_bulk_email_tables_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->query('SELECT 1 FROM re_bulk_email_campaigns LIMIT 1');
        $conn->query('SELECT 1 FROM re_bulk_email_recipients LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function re_bulk_email_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function re_bulk_email_is_valid_email(string $email): bool
{
    $n = re_bulk_email_normalize_email($email);
    return $n !== '' && (bool)filter_var($n, FILTER_VALIDATE_EMAIL);
}

function re_bulk_email_tenant_display_name(array $row): string
{
    $type = strtolower(trim((string)($row['tenant_type'] ?? 'individual')));
    if ($type === 'company') {
        $cn = trim((string)($row['company_name'] ?? ''));
        if ($cn !== '') {
            return $cn;
        }
    }
    $name = trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
    if ($name !== '') {
        return $name;
    }
    $cn = trim((string)($row['company_name'] ?? ''));
    return $cn !== '' ? $cn : ('Tenant #' . (int)($row['tenant_id'] ?? $row['id'] ?? 0));
}

function re_bulk_email_sanitize_html(string $html): string
{
    $allowed = '<p><br><br/><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><span><div>';
    $clean = strip_tags($html, $allowed);
    // Drop javascript: / data: urls in href
    $clean = preg_replace_callback('/<a\s+[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>/i', static function ($m) {
        $href = trim($m[2]);
        if (!preg_match('#^(https?:|mailto:|/|#)#i', $href)) {
            return '<a href="#">';
        }
        return $m[0];
    }, $clean);
    return $clean;
}

function re_bulk_email_apply_placeholders(string $text, array $vars): string
{
    $map = [
        '{{tenant_name}}' => (string)($vars['tenant_name'] ?? ''),
        '{{building_name}}' => (string)($vars['building_name'] ?? ''),
        '{{unit_number}}' => (string)($vars['unit_number'] ?? ''),
    ];
    return str_replace(array_keys($map), array_values($map), $text);
}

/**
 * @return list<string>
 */
function re_bulk_email_lease_statuses(): array
{
    return ['draft', 'active', 'expired', 'terminated', 'renewed', 'has_legal_case'];
}

/**
 * List lease/unit rows for compose UI (company-scoped).
 *
 * @return array{eligible:list<array>,excluded:list<array>}
 */
function re_bulk_email_list_candidates(
    PDO $conn,
    int $companyId,
    ?int $buildingId,
    ?int $unitId,
    string $leaseStatus,
    string $search
): array {
    $where = [
        'l.company_id = ?',
        't.company_id = ?',
        re_lease_not_deleted_sql($conn, 'l'),
    ];
    $params = [$companyId, $companyId];

    if ($buildingId !== null && $buildingId > 0) {
        $where[] = 'b.id = ?';
        $params[] = $buildingId;
    }
    if ($unitId !== null && $unitId > 0) {
        $where[] = 'u.id = ?';
        $params[] = $unitId;
    }
    if ($leaseStatus !== '' && $leaseStatus !== 'all') {
        $where[] = 'l.status = ?';
        $params[] = $leaseStatus;
    }
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(
            t.first_name LIKE ? OR t.last_name LIKE ? OR t.company_name LIKE ?
            OR t.email LIKE ? OR t.phone LIKE ? OR t.phone_alt LIKE ?
            OR u.unit_number LIKE ? OR CONCAT(t.first_name, \' \', t.last_name) LIKE ?
        )';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    $sql = "
        SELECT
            l.id AS lease_id,
            l.lease_number,
            l.status AS lease_status,
            t.id AS tenant_id,
            t.tenant_type,
            t.first_name,
            t.last_name,
            t.company_name,
            t.email,
            t.phone,
            u.id AS unit_id,
            u.unit_number,
            b.id AS building_id,
            b.name AS building_name
        FROM re_leases l
        INNER JOIN re_tenants t ON t.id = l.tenant_id AND t.company_id = l.company_id
        INNER JOIN re_units u ON u.id = l.unit_id AND u.company_id = l.company_id
        INNER JOIN re_buildings b ON b.id = u.building_id AND b.company_id = l.company_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY b.name, u.unit_number, l.id
        LIMIT 2000
    ";
    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $eligible = [];
    $excluded = [];
    foreach ($rows as $r) {
        $r['tenant_name'] = re_bulk_email_tenant_display_name($r);
        $email = trim((string)($r['email'] ?? ''));
        if (re_bulk_email_is_valid_email($email)) {
            $r['email_normalized'] = re_bulk_email_normalize_email($email);
            $eligible[] = $r;
        } else {
            $r['exclude_reason'] = $email === '' ? 'Missing email' : 'Invalid email';
            $excluded[] = $r;
        }
    }
    return ['eligible' => $eligible, 'excluded' => $excluded];
}

/**
 * Revalidate selected lease IDs from DB only. Never trust browser snapshot fields.
 *
 * @param list<int> $leaseIds
 * @param array{building_id?:int,unit_id?:int,lease_status?:string} $filters
 * @return array{ok:bool,error?:string,recipients?:list<array>,excluded_count?:int,selected_count?:int}
 */
function re_bulk_email_validate_selected_leases(
    PDO $conn,
    int $companyId,
    array $leaseIds,
    array $filters = []
): array {
    $leaseIds = array_values(array_unique(array_filter(array_map('intval', $leaseIds), static fn($id) => $id > 0)));
    if (!$leaseIds) {
        return ['ok' => false, 'error' => 'Select at least one tenant/lease.'];
    }
    if (count($leaseIds) > 2000) {
        return ['ok' => false, 'error' => 'Too many recipients selected (max 2000).'];
    }

    $placeholders = implode(',', array_fill(0, count($leaseIds), '?'));
    $where = [
        'l.company_id = ?',
        't.company_id = ?',
        "l.id IN ($placeholders)",
        re_lease_not_deleted_sql($conn, 'l'),
    ];
    $params = array_merge([$companyId, $companyId], $leaseIds);

    $buildingId = (int)($filters['building_id'] ?? 0);
    $unitId = (int)($filters['unit_id'] ?? 0);
    $leaseStatus = (string)($filters['lease_status'] ?? '');
    if ($buildingId > 0) {
        $where[] = 'b.id = ?';
        $params[] = $buildingId;
    }
    if ($unitId > 0) {
        $where[] = 'u.id = ?';
        $params[] = $unitId;
    }
    if ($leaseStatus !== '' && $leaseStatus !== 'all' && in_array($leaseStatus, re_bulk_email_lease_statuses(), true)) {
        $where[] = 'l.status = ?';
        $params[] = $leaseStatus;
    }

    $sql = "
        SELECT
            l.id AS lease_id,
            l.lease_number,
            l.status AS lease_status,
            t.id AS tenant_id,
            t.tenant_type,
            t.first_name,
            t.last_name,
            t.company_name,
            t.email,
            u.id AS unit_id,
            u.unit_number,
            b.id AS building_id,
            b.name AS building_name
        FROM re_leases l
        INNER JOIN re_tenants t ON t.id = l.tenant_id AND t.company_id = l.company_id
        INNER JOIN re_units u ON u.id = l.unit_id AND u.company_id = l.company_id
        INNER JOIN re_buildings b ON b.id = u.building_id AND b.company_id = l.company_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.id ASC
    ";
    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (count($rows) !== count($leaseIds)) {
        return ['ok' => false, 'error' => 'One or more selected leases are invalid for the current company/filters.'];
    }

    $byNorm = [];
    $excluded = 0;
    foreach ($rows as $r) {
        $email = trim((string)($r['email'] ?? ''));
        if (!re_bulk_email_is_valid_email($email)) {
            $excluded++;
            continue;
        }
        $norm = re_bulk_email_normalize_email($email);
        if (isset($byNorm[$norm])) {
            continue; // keep first (lowest lease id due to ORDER BY)
        }
        $byNorm[$norm] = [
            'tenant_id' => (int)$r['tenant_id'],
            'lease_id' => (int)$r['lease_id'],
            'building_id' => (int)$r['building_id'],
            'unit_id' => (int)$r['unit_id'],
            'tenant_name' => re_bulk_email_tenant_display_name($r),
            'building_name' => (string)$r['building_name'],
            'unit_number' => (string)$r['unit_number'],
            'email' => $norm,
            'email_normalized' => $norm,
        ];
    }

    $recipients = array_values($byNorm);
    if (!$recipients) {
        return ['ok' => false, 'error' => 'None of the selected leases have a valid email address.', 'excluded_count' => $excluded];
    }

    return [
        'ok' => true,
        'recipients' => $recipients,
        'excluded_count' => $excluded,
        'selected_count' => count($leaseIds),
    ];
}

/**
 * @return array{ok:bool,path?:string,name?:string,mime?:string,size?:int,error?:string}
 */
function re_bulk_email_store_attachment(PDO $conn, int $companyId, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Attachment upload failed.'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > RE_BULK_EMAIL_MAX_ATTACH_BYTES) {
        return ['ok' => false, 'error' => 'Attachment must be between 1 byte and 5MB.'];
    }
    $orig = (string)($file['name'] ?? 'attachment');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'error' => 'Attachment type not allowed. Use PDF, JPG, PNG, DOC, or DOCX.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $allowedMime = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    if (!in_array($mime, $allowedMime, true)) {
        return ['ok' => false, 'error' => 'Attachment MIME type not allowed.'];
    }

    $baseDir = __DIR__ . '/../../../uploads/re_bulk_email';
    $dir = $baseDir . '/' . $companyId;
    if (!is_dir($baseDir)) {
        if (!@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            return ['ok' => false, 'error' => 'Could not create upload directory. Ensure uploads/re_bulk_email is writable by the web server.'];
        }
        @chmod($baseDir, 0775);
    }
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create upload directory. Ensure uploads/re_bulk_email is writable by the web server.'];
        }
        @chmod($dir, 0775);
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'error' => 'Upload directory is not writable. On the server, make uploads/re_bulk_email writable (e.g. chmod 775/777).'];
    }
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($orig)) ?: ('file.' . $ext);
    $stored = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
    $abs = $dir . '/' . $stored;
    if (!move_uploaded_file($tmp, $abs)) {
        return ['ok' => false, 'error' => 'Could not save attachment.'];
    }
    return [
        'ok' => true,
        'path' => 'uploads/re_bulk_email/' . $companyId . '/' . $stored,
        'name' => $orig,
        'mime' => $mime,
        'size' => $size,
    ];
}

/**
 * @param list<array> $recipients from re_bulk_email_validate_selected_leases
 * @return array{ok:bool,campaign_id?:int,error?:string}
 */
function re_bulk_email_create_campaign(
    PDO $conn,
    int $companyId,
    int $userId,
    string $subject,
    string $bodyHtml,
    array $recipients,
    int $selectedCount,
    int $excludedCount,
    array $filters,
    ?array $attachment
): array {
    $subject = trim($subject);
    if ($subject === '') {
        return ['ok' => false, 'error' => 'Subject is required.'];
    }
    $bodyHtml = re_bulk_email_sanitize_html($bodyHtml);
    if (trim(strip_tags($bodyHtml)) === '') {
        return ['ok' => false, 'error' => 'Message body is required.'];
    }
    if (!$recipients) {
        return ['ok' => false, 'error' => 'No recipients.'];
    }

    try {
        $conn->beginTransaction();
        $ins = $conn->prepare("
            INSERT INTO re_bulk_email_campaigns
            (company_id, subject, body_html, attachment_path, attachment_name, attachment_mime, attachment_size,
             filters_json, selected_count, unique_email_count, excluded_no_email_count,
             sent_count, failed_count, pending_count, status, created_by, confirmed_at, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,?,'queued',?,NOW(),NOW())
        ");
        $ins->execute([
            $companyId,
            $subject,
            $bodyHtml,
            $attachment['path'] ?? null,
            $attachment['name'] ?? null,
            $attachment['mime'] ?? null,
            $attachment['size'] ?? null,
            json_encode($filters, JSON_UNESCAPED_UNICODE),
            $selectedCount,
            count($recipients),
            $excludedCount,
            count($recipients),
            $userId > 0 ? $userId : null,
        ]);
        $campaignId = (int)$conn->lastInsertId();

        $rIns = $conn->prepare("
            INSERT INTO re_bulk_email_recipients
            (campaign_id, company_id, tenant_id, lease_id, building_id, unit_id,
             tenant_name, building_name, unit_number, email, email_normalized, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending')
        ");
        foreach ($recipients as $r) {
            $rIns->execute([
                $campaignId,
                $companyId,
                (int)$r['tenant_id'],
                (int)$r['lease_id'],
                (int)$r['building_id'],
                (int)$r['unit_id'],
                (string)$r['tenant_name'],
                (string)$r['building_name'],
                (string)$r['unit_number'],
                (string)$r['email'],
                (string)$r['email_normalized'],
            ]);
        }
        $conn->commit();
        return ['ok' => true, 'campaign_id' => $campaignId];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        if (stripos($e->getMessage(), 'uq_re_bulk_email_campaign_email') !== false) {
            return ['ok' => false, 'error' => 'Duplicate email detected for this campaign.'];
        }
        return ['ok' => false, 'error' => 'Could not create campaign: ' . $e->getMessage()];
    }
}

function re_bulk_email_get_campaign(PDO $conn, int $companyId, int $campaignId): ?array
{
    $st = $conn->prepare('SELECT * FROM re_bulk_email_campaigns WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$campaignId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function re_bulk_email_refresh_counts(PDO $conn, int $companyId, int $campaignId): void
{
    $st = $conn->prepare("
        SELECT
            SUM(status = 'sent') AS sent_count,
            SUM(status = 'failed') AS failed_count,
            SUM(status IN ('pending','sending')) AS pending_count
        FROM re_bulk_email_recipients
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
        UPDATE re_bulk_email_campaigns
        SET sent_count = ?, failed_count = ?, pending_count = ?, status = ?,
            finished_at = CASE WHEN ? IS NOT NULL THEN ? ELSE finished_at END,
            started_at = COALESCE(started_at, NOW())
        WHERE id = ? AND company_id = ?
    ")->execute([$sent, $failed, $pending, $status, $finished, $finished, $campaignId, $companyId]);
}

/**
 * Claim and send up to $limit pending (or stale sending) recipients. Restart-safe.
 *
 * @return array{ok:bool,processed:int,sent:int,failed:int,pending_remaining:int,error?:string,done?:bool}
 */
function re_bulk_email_process_batch(PDO $conn, int $companyId, int $campaignId, int $limit = RE_BULK_EMAIL_BATCH_SIZE): array
{
    $campaign = re_bulk_email_get_campaign($conn, $companyId, $campaignId);
    if (!$campaign) {
        return ['ok' => false, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'pending_remaining' => 0, 'error' => 'Campaign not found.'];
    }
    if (in_array($campaign['status'], ['completed', 'completed_with_errors', 'cancelled'], true)
        && (int)$campaign['pending_count'] === 0) {
        return [
            'ok' => true,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'pending_remaining' => 0,
            'done' => true,
        ];
    }

    $settingsSt = $conn->prepare('SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1');
    $settingsSt->execute();
    $settings = $settingsSt->fetch(PDO::FETCH_ASSOC);
    if (!$settings || empty($settings['smtp_host'])) {
        return ['ok' => false, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'pending_remaining' => 0, 'error' => 'Email is not configured or disabled in Settings.'];
    }

    $conn->prepare("UPDATE re_bulk_email_campaigns SET status = 'sending', started_at = COALESCE(started_at, NOW()) WHERE id = ? AND company_id = ?")
        ->execute([$campaignId, $companyId]);

    // Release stale sending claims so Continue can resume after disconnect.
    $conn->prepare("
        UPDATE re_bulk_email_recipients
        SET status = 'pending'
        WHERE campaign_id = ? AND company_id = ? AND status = 'sending'
          AND (last_attempt_at IS NULL OR last_attempt_at < (NOW() - INTERVAL " . (int)RE_BULK_EMAIL_STALE_SENDING_SECONDS . " SECOND))
    ")->execute([$campaignId, $companyId]);

    $limit = max(1, min(50, $limit));
    $pick = $conn->prepare("
        SELECT id FROM re_bulk_email_recipients
        WHERE campaign_id = ? AND company_id = ? AND status = 'pending'
        ORDER BY id ASC
        LIMIT {$limit}
    ");
    $pick->execute([$campaignId, $companyId]);
    $ids = array_map('intval', $pick->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $attachments = [];
    if (!empty($campaign['attachment_path'])) {
        $abs = __DIR__ . '/../../../' . ltrim((string)$campaign['attachment_path'], '/');
        if (is_file($abs)) {
            $attachments[] = [
                'path' => $abs,
                'name' => (string)($campaign['attachment_name'] ?: basename($abs)),
            ];
        }
    }

    $sent = 0;
    $failed = 0;
    foreach ($ids as $rid) {
        // Claim only if still pending — never touch sent/failed.
        $claim = $conn->prepare("
            UPDATE re_bulk_email_recipients
            SET status = 'sending', attempts = attempts + 1, last_attempt_at = NOW()
            WHERE id = ? AND campaign_id = ? AND company_id = ? AND status = 'pending'
        ");
        $claim->execute([$rid, $campaignId, $companyId]);
        if ($claim->rowCount() !== 1) {
            continue;
        }

        $rowSt = $conn->prepare('SELECT * FROM re_bulk_email_recipients WHERE id = ? AND campaign_id = ? AND company_id = ? LIMIT 1');
        $rowSt->execute([$rid, $campaignId, $companyId]);
        $row = $rowSt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }

        $vars = [
            'tenant_name' => $row['tenant_name'],
            'building_name' => $row['building_name'],
            'unit_number' => $row['unit_number'],
        ];
        $subject = re_bulk_email_apply_placeholders((string)$campaign['subject'], $vars);
        $body = re_bulk_email_apply_placeholders((string)$campaign['body_html'], $vars);

        $result = $attachments
            ? send_smtp_mail_with_attachments($settings, (string)$row['email'], $subject, $body, $attachments)
            : send_smtp_mail($settings, (string)$row['email'], $subject, $body);

        if (!empty($result['ok'])) {
            $conn->prepare("
                UPDATE re_bulk_email_recipients
                SET status = 'sent', sent_at = NOW(), error_message = NULL
                WHERE id = ? AND campaign_id = ? AND company_id = ? AND status = 'sending'
            ")->execute([$rid, $campaignId, $companyId]);
            $sent++;
        } else {
            $err = substr((string)($result['error'] ?? 'Send failed'), 0, 500);
            $conn->prepare("
                UPDATE re_bulk_email_recipients
                SET status = 'failed', error_message = ?
                WHERE id = ? AND campaign_id = ? AND company_id = ? AND status = 'sending'
            ")->execute([$err, $rid, $campaignId, $companyId]);
            $failed++;
        }
    }

    re_bulk_email_refresh_counts($conn, $companyId, $campaignId);
    $fresh = re_bulk_email_get_campaign($conn, $companyId, $campaignId);
    $pendingLeft = (int)($fresh['pending_count'] ?? 0);

    return [
        'ok' => true,
        'processed' => $sent + $failed,
        'sent' => $sent,
        'failed' => $failed,
        'pending_remaining' => $pendingLeft,
        'done' => $pendingLeft === 0,
        'campaign_status' => $fresh['status'] ?? null,
    ];
}

/**
 * Re-queue failed recipients only. Never touches sent.
 *
 * @return array{ok:bool,requeued:int,error?:string}
 */
function re_bulk_email_retry_failed(PDO $conn, int $companyId, int $campaignId): array
{
    $campaign = re_bulk_email_get_campaign($conn, $companyId, $campaignId);
    if (!$campaign) {
        return ['ok' => false, 'requeued' => 0, 'error' => 'Campaign not found.'];
    }
    $st = $conn->prepare("
        UPDATE re_bulk_email_recipients
        SET status = 'pending', error_message = NULL
        WHERE campaign_id = ? AND company_id = ? AND status = 'failed'
    ");
    $st->execute([$campaignId, $companyId]);
    $n = $st->rowCount();
    if ($n > 0) {
        $conn->prepare("
            UPDATE re_bulk_email_campaigns
            SET status = 'queued', finished_at = NULL
            WHERE id = ? AND company_id = ?
        ")->execute([$campaignId, $companyId]);
        re_bulk_email_refresh_counts($conn, $companyId, $campaignId);
    }
    return ['ok' => true, 'requeued' => $n];
}

/**
 * @return list<array>
 */
function re_bulk_email_list_templates(PDO $conn, int $companyId): array
{
    re_bulk_email_ensure_default_templates($conn, $companyId, 0);
    $st = $conn->prepare("
        SELECT id, name, subject, body_html
        FROM re_bulk_email_templates
        WHERE company_id = ? AND is_active = 1
        ORDER BY name ASC
    ");
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function re_bulk_email_ensure_default_templates(PDO $conn, int $companyId, int $userId): void
{
    try {
        $c = $conn->prepare('SELECT COUNT(*) FROM re_bulk_email_templates WHERE company_id = ?');
        $c->execute([$companyId]);
        if ((int)$c->fetchColumn() > 0) {
            return;
        }
        $defaults = [
            [
                'Pest control notice',
                'Pest control visit — {{building_name}}',
                '<p>Dear {{tenant_name}},</p><p>We will carry out pest control at <strong>{{building_name}}</strong>, unit <strong>{{unit_number}}</strong>. Please prepare as advised.</p><p>Thank you,<br>Building Management</p>',
            ],
            [
                'Water shutdown notice',
                'Water shutdown — {{building_name}}',
                '<p>Dear {{tenant_name}},</p><p>Water supply at <strong>{{building_name}}</strong> (unit <strong>{{unit_number}}</strong>) will be temporarily interrupted for maintenance.</p><p>Thank you for your understanding.</p>',
            ],
            [
                'General building notice',
                'Building notice — {{building_name}}',
                '<p>Dear {{tenant_name}},</p><p>Please note the following update regarding <strong>{{building_name}}</strong>, unit <strong>{{unit_number}}</strong>:</p><p>[Your message here]</p><p>Regards,<br>Building Management</p>',
            ],
        ];
        $ins = $conn->prepare("
            INSERT INTO re_bulk_email_templates (company_id, name, subject, body_html, is_active, created_by)
            VALUES (?,?,?,?,1,?)
        ");
        foreach ($defaults as $d) {
            $ins->execute([$companyId, $d[0], $d[1], $d[2], $userId > 0 ? $userId : null]);
        }
    } catch (Throwable $e) {
        // tables may be missing
    }
}

function re_bulk_email_save_template(PDO $conn, int $companyId, int $userId, string $name, string $subject, string $bodyHtml): array
{
    $name = trim($name);
    $subject = trim($subject);
    $bodyHtml = re_bulk_email_sanitize_html($bodyHtml);
    if ($name === '' || $subject === '' || trim(strip_tags($bodyHtml)) === '') {
        return ['ok' => false, 'error' => 'Template name, subject, and body are required.'];
    }
    $conn->prepare("
        INSERT INTO re_bulk_email_templates (company_id, name, subject, body_html, is_active, created_by)
        VALUES (?,?,?,?,1,?)
    ")->execute([$companyId, $name, $subject, $bodyHtml, $userId > 0 ? $userId : null]);
    return ['ok' => true, 'id' => (int)$conn->lastInsertId()];
}
