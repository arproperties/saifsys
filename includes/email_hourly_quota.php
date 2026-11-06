<?php
/**
 * Hourly send quota for bulk email.
 *
 * The mail relay (smtp.titan.email) allows 50 messages per clock hour per
 * mailbox. Past that it refuses every further recipient until the top of the
 * next hour, so a large notice has to be split across hours: send 50 now, the
 * next 50 in the following hour, and so on.
 *
 * The counter is keyed on the sending mailbox and a UTC hour, because that is
 * how the relay itself measures — its refusals quote a UTC reset time.
 */

/** Messages the relay allows per clock hour. Raise only if the provider does. */
if (!defined('EMAIL_HOURLY_LIMIT')) {
    define('EMAIL_HOURLY_LIMIT', 50);
}

/** The mailbox the quota belongs to. */
function email_quota_account_key(array $settings): string
{
    $key = trim((string)($settings['smtp_username'] ?? ''));
    if ($key === '') {
        $key = trim((string)($settings['from_email'] ?? ''));
    }
    return substr(strtolower($key !== '' ? $key : 'default'), 0, 190);
}

/** The UTC hour we are currently in, e.g. "2026-09-02 07:00:00". */
function email_quota_window(?int $ts = null): string
{
    return gmdate('Y-m-d H:00:00', $ts ?? time());
}

/** When the relay's counter next resets. */
function email_quota_next_window(?int $ts = null): string
{
    return gmdate('Y-m-d H:00:00', ($ts ?? time()) + 3600);
}

/** Seconds until a UTC datetime, never negative. */
function email_quota_seconds_until(string $utc): int
{
    $t = strtotime($utc . ' UTC');
    return $t === false ? 0 : max(0, $t - time());
}

/** How many we have sent in the current hour. */
function email_quota_used(PDO $conn, string $accountKey): int
{
    try {
        $st = $conn->prepare('SELECT sent_count FROM app_email_hourly_quota
                              WHERE account_key = ? AND window_start = ?');
        $st->execute([$accountKey, email_quota_window()]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0; // table missing: do not block sending on our own bookkeeping
    }
}

/** How many we may still send this hour. */
function email_quota_remaining(PDO $conn, string $accountKey): int
{
    return max(0, EMAIL_HOURLY_LIMIT - email_quota_used($conn, $accountKey));
}

/** Count one delivered message against this hour. */
function email_quota_record_send(PDO $conn, string $accountKey): void
{
    try {
        $conn->prepare('
            INSERT INTO app_email_hourly_quota (account_key, window_start, sent_count)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE sent_count = sent_count + 1
        ')->execute([$accountKey, email_quota_window()]);
    } catch (Throwable $e) {
        // bookkeeping only — never break a send
    }
}

/**
 * Mark this hour as spent.
 *
 * Used when the relay refuses us even though our own count still had room —
 * other parts of the app (password resets, invoices) share the same mailbox
 * quota and are not counted here, so the relay is the final authority.
 */
function email_quota_mark_exhausted(PDO $conn, string $accountKey): void
{
    try {
        $conn->prepare('
            INSERT INTO app_email_hourly_quota (account_key, window_start, sent_count)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE sent_count = GREATEST(sent_count, VALUES(sent_count))
        ')->execute([$accountKey, email_quota_window(), EMAIL_HOURLY_LIMIT]);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Is this SMTP error the relay throttling us, rather than a bad address?
 * A throttled recipient is a real tenant who simply has not been reached yet.
 */
function email_quota_is_limit_error(string $error): bool
{
    $e = strtolower($error);
    foreach (['quota exceeded', 'sending limit', 'hourly limit', 'rate limit',
              'too many messages', 'too many emails', 'try again later'] as $needle) {
        if (strpos($e, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** Drop counters we no longer need. */
function email_quota_prune(PDO $conn): void
{
    try {
        $conn->prepare('DELETE FROM app_email_hourly_quota WHERE window_start < ?')
            ->execute([gmdate('Y-m-d H:00:00', time() - 86400)]);
    } catch (Throwable $e) {
        // ignore
    }
}
