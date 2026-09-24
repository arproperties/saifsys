<?php
/**
 * Operations field app — the PIN.
 *
 * One file, used from two very different places: the HR profile page where the
 * office sets a PIN, and api/mobile/ops where a phone spends it. Everything
 * about how a PIN is stored, checked and rate-limited is here so the two sides
 * cannot drift apart.
 *
 * It deliberately does NOT include auth.php. The mobile API is stateless and
 * must not open a session just to check four digits.
 *
 * ---------------------------------------------------------------------------
 * WHAT FOUR DIGITS CAN AND CANNOT DO
 * ---------------------------------------------------------------------------
 * A PIN typed on its own — no name, no employee code — is a 1-in-10,000 guess,
 * and it is checked against every employee at once. With 50 people holding
 * PINs, a few hundred blind guesses would land on somebody. That is acceptable
 * only because guessing is not allowed to be cheap, so the lockout below is
 * part of the credential, not a nicety bolted on beside it. If the throttle is
 * ever removed, the sign-in becomes trivially breakable.
 *
 * Obvious PINs are deliberately ALLOWED. 1111 and 1234 are the first values
 * anyone guesses, and refusing them was tried and taken back out: the office
 * hands these to staff verbally, often across a language gap, and a PIN that
 * cannot be said in one breath comes straight back as a support call. That is
 * a decision to lean harder on the lockout, not an oversight — do not add the
 * check back without also being asked to.
 *
 * What contains the damage:
 *   - a signed-in phone can only see and touch that person's own jobs, which
 *     is what the rest of the ops API already enforces;
 *   - PINs are unique, so a guess reaches one person, never a chosen one;
 *   - changing or removing a PIN kills every token already issued from it.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/config.php';

// ---------------------------------------------------------------------------
// The server-side secret
// ---------------------------------------------------------------------------

/**
 * Key for the blind index. Not a password hash secret — see the migration.
 *
 * Empty means this deployment has not been configured, and every path below
 * fails closed rather than silently hashing under ''.
 */
function ops_pin_secret(): string
{
    $env = getenv('OPS_MOBILE_PIN_SECRET');
    if ($env !== false && $env !== '') {
        return (string)$env;
    }
    return defined('OPS_MOBILE_PIN_SECRET') ? (string)OPS_MOBILE_PIN_SECRET : '';
}

function ops_pin_configured(): bool
{
    return ops_pin_secret() !== '';
}

// ---------------------------------------------------------------------------
// What counts as a PIN
// ---------------------------------------------------------------------------

function ops_pin_length(): int
{
    return 4;
}

function ops_pin_format_ok(string $pin): bool
{
    return (bool)preg_match('/^\d{' . ops_pin_length() . '}$/', $pin);
}

/**
 * The blind index: HMAC of the PIN under the server secret.
 *
 * Finds the candidate row and enforces uniqueness. It never decides whether a
 * PIN is right — password_verify() against pin_hash does that.
 */
function ops_pin_lookup_hash(string $pin): string
{
    return hash_hmac('sha256', $pin, ops_pin_secret());
}

// ---------------------------------------------------------------------------
// Setting and clearing — the office side
// ---------------------------------------------------------------------------

/**
 * Give this person a PIN, replacing any they already had.
 *
 * @return array{ok:bool,error:string} error is written for the office to read
 */
function ops_pin_set(PDO $conn, int $userId, string $pin, ?int $actorId): array
{
    if (!ops_pin_configured()) {
        return ['ok' => false, 'error' => 'Field app PINs are not switched on for this server yet. Ask IT to set OPS_MOBILE_PIN_SECRET.'];
    }
    if (!ops_pin_format_ok($pin)) {
        return ['ok' => false, 'error' => 'A PIN must be exactly ' . ops_pin_length() . ' digits.'];
    }
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'This employee has no login yet. Create one first.'];
    }

    // The login must actually exist and be active. Some employee rows point at
    // a user that has since been deleted, and without this the INSERT below
    // fails on the foreign key — which looks identical to a duplicate PIN and
    // sends the office hunting for a clash that is not there.
    $account = $conn->prepare("SELECT status FROM `user` WHERE id = ? LIMIT 1");
    $account->execute([$userId]);
    $status = $account->fetchColumn();
    if ($status === false) {
        return ['ok' => false, 'error' => 'This employee is linked to a login that no longer exists. Create a new login before setting a PIN.'];
    }
    if ((int)$status !== 1) {
        return ['ok' => false, 'error' => 'That login is deactivated. Reactivate it before giving out a PIN.'];
    }

    $lookup = ops_pin_lookup_hash($pin);

    // Taken by someone else? Say so plainly. It does leak that the value is in
    // use, but only to the office staff who could read or reset any PIN here
    // anyway — and the alternative is two people whose PIN opens one account.
    $taken = $conn->prepare("SELECT user_id FROM ops_staff_pins WHERE pin_lookup = ? AND user_id <> ? LIMIT 1");
    $taken->execute([$lookup, $userId]);
    if ($taken->fetchColumn()) {
        return ['ok' => false, 'error' => 'Another employee already uses that PIN. Choose a different one.'];
    }

    $hash = password_hash($pin, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO ops_staff_pins (user_id, pin_hash, pin_lookup, set_at, set_by)
        VALUES (?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
            pin_hash = VALUES(pin_hash),
            pin_lookup = VALUES(pin_lookup),
            set_at = VALUES(set_at),
            set_by = VALUES(set_by)
    ");

    try {
        $stmt->execute([$userId, $hash, $lookup, $actorId]);
    } catch (PDOException $e) {
        // 1062 is the duplicate-key error specifically — two people racing to
        // the same PIN past the check above. Everything else in the 23000 class
        // (a broken foreign key, say) means something different and must not be
        // reported as a PIN clash the office cannot find.
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => 'Another employee already uses that PIN. Choose a different one.'];
        }
        throw $e;
    }

    return ['ok' => true, 'error' => ''];
}

/** Take the PIN away. Any phone already signed in with it stops working. */
function ops_pin_clear(PDO $conn, int $userId): bool
{
    $stmt = $conn->prepare("DELETE FROM ops_staff_pins WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->rowCount() > 0;
}

/**
 * Does this person have a PIN, and who set it when?
 *
 * The PIN itself is never readable — not here, not anywhere. A lost PIN is
 * replaced, not recovered.
 *
 * @return array{set_at:string,set_by_name:string}|null
 */
function ops_pin_status(PDO $conn, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT p.set_at, COALESCE(NULLIF(u.fullname, ''), u.username, '') AS set_by_name
        FROM ops_staff_pins p
        LEFT JOIN `user` u ON u.id = p.set_by
        WHERE p.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * True when the ops_staff_pins table has not been created yet.
 *
 * The HR profile page renders for every employee on a server that may not have
 * run the migration; it shows a hint instead of a 500.
 */
function ops_pin_table_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->query("SELECT 1 FROM ops_staff_pins LIMIT 1");
        $ready = true;
    } catch (PDOException $e) {
        $ready = false;
    }
    return $ready;
}

// ---------------------------------------------------------------------------
// Spending a PIN — the phone side
// ---------------------------------------------------------------------------

/**
 * Which user does this PIN belong to?
 *
 * Two steps on purpose: the HMAC narrows 10,000 possibilities to at most one
 * row, then password_verify() decides. Neither alone is enough — the first
 * cannot resist a leaked table, the second cannot be searched.
 *
 * @return array{id:int,name:string,epoch:int}|null
 */
function ops_pin_resolve_user(PDO $conn, string $pin): ?array
{
    if (!ops_pin_configured() || !ops_pin_format_ok($pin)) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT p.user_id, p.pin_hash, UNIX_TIMESTAMP(p.set_at) AS epoch,
               COALESCE(NULLIF(u.fullname, ''), u.username) AS name
        FROM ops_staff_pins p
        JOIN `user` u ON u.id = p.user_id
        WHERE p.pin_lookup = ? AND u.status = 1
        LIMIT 1
    ");
    $stmt->execute([ops_pin_lookup_hash($pin)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($pin, (string)$row['pin_hash'])) {
        return null;
    }

    // Someone who has left the company keeps a valid PIN until it is removed,
    // so check the account the same way the admin sign-in does. A closed account
    // looks exactly like a wrong PIN.
    require_once __DIR__ . '/../../../includes/account_status.php';
    if (account_login_block_reason($conn, (int)$row['user_id']) !== null) {
        return null;
    }

    return [
        'id' => (int)$row['user_id'],
        'name' => (string)$row['name'],
        'epoch' => (int)$row['epoch'],
    ];
}

/**
 * The epoch stamped into a token when it was issued.
 *
 * A token is only good while it still matches the PIN row it came from, so
 * changing or removing someone's PIN logs their phone out — which is the whole
 * of "revoke this person's access", and it takes effect on the next request.
 */
function ops_pin_current_epoch(PDO $conn, int $userId): ?int
{
    $stmt = $conn->prepare("SELECT UNIX_TIMESTAMP(set_at) FROM ops_staff_pins WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $epoch = $stmt->fetchColumn();
    return $epoch === false || $epoch === null ? null : (int)$epoch;
}

// ---------------------------------------------------------------------------
// The lockout
// ---------------------------------------------------------------------------

/** How long failures are remembered, in seconds. */
function ops_pin_window_seconds(): int
{
    return 15 * 60;
}

/** Wrong PINs from one handset before it is shut out for the window. */
function ops_pin_device_limit(): int
{
    return 5;
}

/**
 * The same for one address. Higher, because a whole site of cleaners can share
 * one office wifi and a genuine bad morning must not lock all of them out —
 * but low enough that scripted guessing from one place dies quickly.
 */
function ops_pin_ip_limit(): int
{
    return 25;
}

function ops_pin_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * Is this caller allowed another guess right now?
 *
 * Counts recent failures for the handset and for the address, and reports how
 * long until the oldest of the offending failures ages out of the window — so
 * the app can say "try again in 12 minutes" rather than "no".
 *
 * @return array{blocked:bool,retry_after:int}
 */
function ops_pin_throttle_state(PDO $conn, string $deviceId, string $ip): array
{
    $window = ops_pin_window_seconds();
    $since = date('Y-m-d H:i:s', time() - $window);

    $check = static function (string $column, string $value, int $limit) use ($conn, $since, $window): int {
        if ($value === '') {
            return 0;
        }
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS failures, UNIX_TIMESTAMP(MIN(attempted_at)) AS oldest
            FROM ops_pin_attempts
            WHERE {$column} = ? AND succeeded = 0 AND attempted_at >= ?
        ");
        $stmt->execute([$value, $since]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($row['failures'] ?? 0) < $limit) {
            return 0;
        }
        // Free again once the oldest counted failure leaves the window.
        return max(1, (int)$row['oldest'] + $window - time());
    };

    try {
        $retry = max(
            $check('device_id', $deviceId, ops_pin_device_limit()),
            $check('ip', $ip, ops_pin_ip_limit())
        );
    } catch (PDOException $e) {
        // No attempts table means no throttle, and no throttle means a
        // four-digit secret in the open. Refuse rather than run unprotected.
        error_log('ops_pin_throttle_state failed: ' . $e->getMessage());
        return ['blocked' => true, 'retry_after' => ops_pin_window_seconds()];
    }

    return ['blocked' => $retry > 0, 'retry_after' => $retry];
}

function ops_pin_record_attempt(PDO $conn, string $deviceId, string $ip, ?int $userId, bool $succeeded): void
{
    try {
        $stmt = $conn->prepare("
            INSERT INTO ops_pin_attempts (device_id, ip, user_id, succeeded, attempted_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([substr($deviceId, 0, 64), $ip, $userId, $succeeded ? 1 : 0]);
    } catch (PDOException $e) {
        error_log('ops_pin_record_attempt failed: ' . $e->getMessage());
    }
}

/** Attempts stop mattering once the window has passed; keep a day, no more. */
function ops_pin_prune_attempts(PDO $conn): void
{
    try {
        $conn->exec("DELETE FROM ops_pin_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
    } catch (PDOException $e) {
        // Housekeeping. Never worth failing a sign-in over.
    }
}
