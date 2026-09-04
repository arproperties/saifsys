<?php
/**
 * Real Estate reminder helpers: validation, scheduling, CRUD, and cron delivery.
 */

require_once __DIR__ . '/re_email_helper.php';

function re_reminder_admin(PDO $conn): bool
{
    return function_exists('has_role')
        && (has_role('Owner', $conn) || has_role('Admin', $conn));
}

function re_reminder_tables_ready(PDO $conn): bool
{
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 're_reminders'");
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function re_reminder_allowed_module_types(): array
{
    return ['invoice', 'payment', 'contract', 'client', 'employee', 'custom'];
}

function re_reminder_allowed_channels(): array
{
    return ['in_app', 'email', 'both'];
}

function re_reminder_allowed_repeats(): array
{
    return ['none', 'daily', 'weekly', 'monthly', 'yearly'];
}

function re_reminder_calculate_next_run(string $dueDate, int $daysBefore, string $time): string
{
    $daysBefore = max(0, $daysBefore);
    $time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time) ? $time : '09:00:00';
    if (strlen($time) === 5) {
        $time .= ':00';
    }
    $dt = new DateTimeImmutable($dueDate . ' ' . $time);
    return $dt->modify('-' . $daysBefore . ' days')->format('Y-m-d H:i:s');
}

function re_reminder_parse_emails(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', $raw);
    $emails = [];
    foreach ($parts ?: [] as $email) {
        $email = trim($email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = strtolower($email);
        }
    }
    return array_values(array_unique($emails));
}

function re_reminder_normalize_recipients($userIds, string $emailRaw): string
{
    $ids = [];
    foreach ((array)$userIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $payload = [
        'user_ids' => array_values(array_unique($ids)),
        'emails' => re_reminder_parse_emails($emailRaw),
    ];
    return json_encode($payload);
}

function re_reminder_decode_recipients(?string $json): array
{
    $data = json_decode((string)$json, true);
    if (!is_array($data)) {
        return ['user_ids' => [], 'emails' => []];
    }
    return [
        'user_ids' => array_values(array_filter(array_map('intval', $data['user_ids'] ?? []))),
        'emails' => array_values(array_filter(array_map('trim', $data['emails'] ?? []))),
    ];
}

function re_reminder_recipient_label(?string $json): string
{
    $r = re_reminder_decode_recipients($json);
    $parts = [];
    if ($r['user_ids']) {
        $parts[] = count($r['user_ids']) . ' user' . (count($r['user_ids']) === 1 ? '' : 's');
    }
    if ($r['emails']) {
        $parts[] = implode(', ', $r['emails']);
    }
    return $parts ? implode(' + ', $parts) : 'No recipients';
}

function re_reminder_user_options(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id, u.username, u.fullname, u.email
        FROM user u
        LEFT JOIN user_companies uc ON uc.user_id = u.id
        WHERE (u.company_id = ? OR uc.company_id = ?)
          AND COALESCE(u.is_active, 1) = 1
        ORDER BY COALESCE(u.fullname, u.username), u.username
    ");
    $stmt->execute([$companyId, $companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function re_reminder_validate(array $data): array
{
    $errors = [];
    $name = trim((string)($data['reminder_name'] ?? ''));
    if ($name === '') {
        $errors[] = 'Reminder name is required.';
    }
    $moduleType = $data['module_type'] ?? 'custom';
    if (!in_array($moduleType, re_reminder_allowed_module_types(), true)) {
        $errors[] = 'Invalid related module/type.';
    }
    $dueDate = trim((string)($data['due_date'] ?? ''));
    if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        $errors[] = 'Valid due date is required.';
    }
    $days = (int)($data['reminder_days_before'] ?? 0);
    if ($days < 0 || $days > 3650) {
        $errors[] = 'Reminder days before must be between 0 and 3650.';
    }
    $time = trim((string)($data['reminder_time'] ?? '09:00'));
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        $errors[] = 'Valid reminder time is required.';
    }
    $channel = $data['channel'] ?? 'in_app';
    if (!in_array($channel, re_reminder_allowed_channels(), true)) {
        $errors[] = 'Invalid reminder channel.';
    }
    $repeat = $data['repeat_type'] ?? 'none';
    if (!in_array($repeat, re_reminder_allowed_repeats(), true)) {
        $errors[] = 'Invalid repeat option.';
    }
    $status = $data['status'] ?? 'active';
    if (!in_array($status, ['active', 'inactive', 'completed'], true)) {
        $errors[] = 'Invalid reminder status.';
    }
    $recipients = re_reminder_decode_recipients($data['recipients'] ?? null);
    if (!$recipients['user_ids'] && !$recipients['emails']) {
        $errors[] = 'Select at least one user or enter one valid email.';
    }
    return $errors;
}

function re_reminder_payload_from_request(array $source): array
{
    $time = trim((string)($source['reminder_time'] ?? '09:00'));
    if (strlen($time) === 5) {
        $time .= ':00';
    }
    $recipients = $source['recipients'] ?? re_reminder_normalize_recipients(
        $source['recipient_user_ids'] ?? [],
        (string)($source['recipient_emails'] ?? '')
    );
    $dueDate = trim((string)($source['due_date'] ?? ''));
    $days = max(0, (int)($source['reminder_days_before'] ?? 0));
    return [
        'reminder_name' => trim((string)($source['reminder_name'] ?? '')),
        'description' => trim((string)($source['description'] ?? '')),
        'module_type' => $source['module_type'] ?? 'custom',
        'related_record_id' => !empty($source['related_record_id']) ? (int)$source['related_record_id'] : null,
        'due_date' => $dueDate,
        'reminder_days_before' => $days,
        'reminder_time' => $time,
        'recipients' => $recipients,
        'channel' => $source['channel'] ?? 'in_app',
        'repeat_type' => $source['repeat_type'] ?? 'none',
        'status' => $source['status'] ?? 'active',
        'next_run_at' => $dueDate ? re_reminder_calculate_next_run($dueDate, $days, $time) : null,
    ];
}

function re_reminder_create(PDO $conn, int $companyId, int $userId, array $payload): int
{
    $errors = re_reminder_validate($payload);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }
    $stmt = $conn->prepare("
        INSERT INTO re_reminders
        (company_id, reminder_name, description, module_type, related_record_id, due_date,
         reminder_days_before, reminder_time, recipients, channel, repeat_type, status,
         next_run_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $payload['reminder_name'],
        $payload['description'] !== '' ? $payload['description'] : null,
        $payload['module_type'],
        $payload['related_record_id'],
        $payload['due_date'],
        $payload['reminder_days_before'],
        $payload['reminder_time'],
        $payload['recipients'],
        $payload['channel'],
        $payload['repeat_type'],
        $payload['status'],
        $payload['next_run_at'],
        $userId,
    ]);
    return (int)$conn->lastInsertId();
}

function re_reminder_exists_allowed(PDO $conn, int $companyId, int $id, int $userId, bool $isAdmin): bool
{
    $sql = "SELECT 1 FROM re_reminders WHERE id = ? AND company_id = ?";
    $args = [$id, $companyId];
    if (!$isAdmin) {
        $sql .= " AND created_by = ?";
        $args[] = $userId;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    return (bool)$stmt->fetchColumn();
}

function re_reminder_update(PDO $conn, int $companyId, int $id, int $userId, bool $isAdmin, array $payload): void
{
    $errors = re_reminder_validate($payload);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }
    $where = 'id = ? AND company_id = ?';
    $args = [
        $payload['reminder_name'],
        $payload['description'] !== '' ? $payload['description'] : null,
        $payload['module_type'],
        $payload['related_record_id'],
        $payload['due_date'],
        $payload['reminder_days_before'],
        $payload['reminder_time'],
        $payload['recipients'],
        $payload['channel'],
        $payload['repeat_type'],
        $payload['status'],
        $payload['next_run_at'],
        $id,
        $companyId,
    ];
    if (!$isAdmin) {
        $where .= ' AND created_by = ?';
        $args[] = $userId;
    }
    $stmt = $conn->prepare("
        UPDATE re_reminders
        SET reminder_name = ?, description = ?, module_type = ?, related_record_id = ?,
            due_date = ?, reminder_days_before = ?, reminder_time = ?, recipients = ?,
            channel = ?, repeat_type = ?, status = ?, next_run_at = ?,
            processing_started_at = NULL, processing_token = NULL
        WHERE {$where}
    ");
    $stmt->execute($args);
    if ($stmt->rowCount() === 0 && !re_reminder_exists_allowed($conn, $companyId, $id, $userId, $isAdmin)) {
        throw new RuntimeException('Reminder not found or not allowed.');
    }
}

function re_reminder_delete(PDO $conn, int $companyId, int $id, int $userId, bool $isAdmin): void
{
    $sql = "DELETE FROM re_reminders WHERE id = ? AND company_id = ?";
    $args = [$id, $companyId];
    if (!$isAdmin) {
        $sql .= " AND created_by = ?";
        $args[] = $userId;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    if ($stmt->rowCount() === 0 && !re_reminder_exists_allowed($conn, $companyId, $id, $userId, $isAdmin)) {
        throw new RuntimeException('Reminder not found or not allowed.');
    }
}

function re_reminder_set_status(PDO $conn, int $companyId, int $id, int $userId, bool $isAdmin, string $status): void
{
    if (!in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Invalid status.');
    }
    $nextSql = '';
    $nextArgs = [];
    if ($status === 'active') {
        $loadSql = "SELECT due_date, reminder_days_before, reminder_time FROM re_reminders WHERE id = ? AND company_id = ?";
        $loadArgs = [$id, $companyId];
        if (!$isAdmin) {
            $loadSql .= " AND created_by = ?";
            $loadArgs[] = $userId;
        }
        $load = $conn->prepare($loadSql);
        $load->execute($loadArgs);
        $row = $load->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Reminder not found or not allowed.');
        }
        $nextSql = ', next_run_at = ?';
        $nextArgs[] = re_reminder_calculate_next_run($row['due_date'], (int)$row['reminder_days_before'], (string)$row['reminder_time']);
    }
    $sql = "UPDATE re_reminders SET status = ?{$nextSql}, processing_started_at = NULL, processing_token = NULL WHERE id = ? AND company_id = ?";
    $args = array_merge([$status], $nextArgs, [$id, $companyId]);
    if (!$isAdmin) {
        $sql .= " AND created_by = ?";
        $args[] = $userId;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    if ($stmt->rowCount() === 0 && !re_reminder_exists_allowed($conn, $companyId, $id, $userId, $isAdmin)) {
        throw new RuntimeException('Reminder not found or not allowed.');
    }
}

function re_reminder_message(array $reminder): array
{
    $subject = 'Reminder: ' . $reminder['reminder_name'];
    $bodyText = "Task: {$reminder['reminder_name']}\n"
        . "Due Date: {$reminder['due_date']}\n"
        . "Notes: " . ($reminder['description'] ?: '-') . "\n"
        . "Related Module: {$reminder['module_type']}\n\n"
        . "This reminder was scheduled from the ERP.";
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#333">'
        . '<h2 style="margin:0 0 12px">Reminder: ' . htmlspecialchars($reminder['reminder_name'], ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p><strong>Task:</strong> ' . htmlspecialchars($reminder['reminder_name'], ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Due Date:</strong> ' . htmlspecialchars($reminder['due_date'], ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Notes:</strong><br>' . nl2br(htmlspecialchars($reminder['description'] ?: '-', ENT_QUOTES, 'UTF-8')) . '</p>'
        . '<p><strong>Related Module:</strong> ' . htmlspecialchars($reminder['module_type'], ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="color:#666">This reminder was scheduled from the ERP.</p>'
        . '</div>';
    return ['subject' => $subject, 'body_text' => $bodyText, 'body_html' => $html];
}

function re_reminder_log(PDO $conn, array $reminder, string $channel, ?string $recipient, string $status, ?string $message): void
{
    $stmt = $conn->prepare("
        INSERT INTO re_reminder_logs (company_id, reminder_id, run_at, channel, recipient, status, message)
        VALUES (?, ?, NOW(), ?, ?, ?, ?)
    ");
    $stmt->execute([
        $reminder['company_id'],
        $reminder['id'],
        $channel,
        $recipient,
        $status,
        $message,
    ]);
}

function re_reminder_send_one(PDO $conn, array $reminder): array
{
    $message = re_reminder_message($reminder);
    $recipients = re_reminder_decode_recipients($reminder['recipients'] ?? null);
    $summary = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

    $needsInApp = in_array($reminder['channel'], ['in_app', 'both'], true);
    $needsEmail = in_array($reminder['channel'], ['email', 'both'], true);

    if ($needsInApp) {
        if (!$recipients['user_ids']) {
            re_reminder_log($conn, $reminder, 'in_app', null, 'skipped', 'No user recipients selected for in-app notification.');
            $summary['skipped']++;
        }
        $stmt = $conn->prepare("
            INSERT INTO re_in_app_notifications
            (company_id, user_id, title, body, related_type, related_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($recipients['user_ids'] as $uid) {
            try {
                $stmt->execute([
                    $reminder['company_id'],
                    $uid,
                    $message['subject'],
                    $message['body_text'],
                    $reminder['module_type'],
                    $reminder['related_record_id'],
                ]);
                re_reminder_log($conn, $reminder, 'in_app', (string)$uid, 'sent', 'In-app notification created.');
                $summary['sent']++;
            } catch (Throwable $e) {
                re_reminder_log($conn, $reminder, 'in_app', (string)$uid, 'failed', $e->getMessage());
                $summary['failed']++;
            }
        }
    }

    if ($needsEmail) {
        $emails = $recipients['emails'];
        if ($recipients['user_ids']) {
            $placeholders = implode(',', array_fill(0, count($recipients['user_ids']), '?'));
            $stmt = $conn->prepare("SELECT email FROM user WHERE id IN ($placeholders) AND email IS NOT NULL AND TRIM(email) != ''");
            $stmt->execute($recipients['user_ids']);
            $emails = array_merge($emails, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $emails = array_values(array_unique(array_filter($emails, fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
        if (!$emails) {
            re_reminder_log($conn, $reminder, 'email', null, 'skipped', 'No valid email recipients.');
            $summary['skipped']++;
        }
        foreach ($emails as $email) {
            try {
                $ok = send_re_email($email, $message['subject'], $message['body_html']);
                re_reminder_log($conn, $reminder, 'email', $email, $ok ? 'sent' : 'failed', $ok ? 'Email sent.' : 'Email sending failed. Check SMTP settings.');
                $summary[$ok ? 'sent' : 'failed']++;
            } catch (Throwable $e) {
                re_reminder_log($conn, $reminder, 'email', $email, 'failed', $e->getMessage());
                $summary['failed']++;
            }
        }
    }

    return $summary;
}

function re_reminder_next_repeat_run(array $reminder): ?array
{
    $repeat = $reminder['repeat_type'] ?? 'none';
    if ($repeat === 'none') {
        return null;
    }
    $map = [
        'daily' => '+1 day',
        'weekly' => '+1 week',
        'monthly' => '+1 month',
        'yearly' => '+1 year',
    ];
    if (empty($map[$repeat])) {
        return null;
    }
    $due = new DateTimeImmutable($reminder['due_date']);
    $now = new DateTimeImmutable('now');
    do {
        $due = $due->modify($map[$repeat]);
        $nextRun = new DateTimeImmutable(re_reminder_calculate_next_run(
            $due->format('Y-m-d'),
            (int)$reminder['reminder_days_before'],
            (string)$reminder['reminder_time']
        ));
    } while ($nextRun <= $now);

    return ['due_date' => $due->format('Y-m-d'), 'next_run_at' => $nextRun->format('Y-m-d H:i:s')];
}

function re_reminder_process_due(PDO $conn, int $limit = 25): array
{
    $token = bin2hex(random_bytes(16));
    $stmt = $conn->prepare("
        SELECT id
        FROM re_reminders
        WHERE status = 'active'
          AND next_run_at IS NOT NULL
          AND next_run_at <= NOW()
          AND (processing_started_at IS NULL OR processing_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
        ORDER BY next_run_at ASC
        LIMIT {$limit}
    ");
    $stmt->execute();
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $claimed = [];
    foreach ($ids as $id) {
        $claim = $conn->prepare("
            UPDATE re_reminders
            SET processing_token = ?, processing_started_at = NOW()
            WHERE id = ?
              AND status = 'active'
              AND next_run_at <= NOW()
              AND (processing_started_at IS NULL OR processing_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
        ");
        $claim->execute([$token, $id]);
        if ($claim->rowCount() > 0) {
            $claimed[] = $id;
        }
    }

    $result = ['claimed' => count($claimed), 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    if (!$claimed) {
        return $result;
    }

    $placeholders = implode(',', array_fill(0, count($claimed), '?'));
    $load = $conn->prepare("SELECT * FROM re_reminders WHERE id IN ($placeholders) AND processing_token = ?");
    $load->execute(array_merge($claimed, [$token]));
    $reminders = $load->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reminders as $reminder) {
        $summary = re_reminder_send_one($conn, $reminder);
        foreach (['sent', 'failed', 'skipped'] as $key) {
            $result[$key] += $summary[$key] ?? 0;
        }

        $next = re_reminder_next_repeat_run($reminder);
        if ($next) {
            $upd = $conn->prepare("
                UPDATE re_reminders
                SET last_sent_at = NOW(), due_date = ?, next_run_at = ?,
                    processing_started_at = NULL, processing_token = NULL
                WHERE id = ? AND processing_token = ?
            ");
            $upd->execute([$next['due_date'], $next['next_run_at'], $reminder['id'], $token]);
        } else {
            $upd = $conn->prepare("
                UPDATE re_reminders
                SET last_sent_at = NOW(), status = 'completed', next_run_at = NULL,
                    processing_started_at = NULL, processing_token = NULL
                WHERE id = ? AND processing_token = ?
            ");
            $upd->execute([$reminder['id'], $token]);
        }
    }

    return $result;
}
