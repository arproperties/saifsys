<?php
/**
 * Main ERP forgot-password helpers (user table).
 */

require_once __DIR__ . '/url_helper.php';

function user_password_resets_table_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $st = $conn->query("SHOW TABLES LIKE 'user_password_resets'");
        $ready = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Find active ERP user by email for password reset.
 * @return array{id:int,username:string,fullname:string,email:string}|null
 */
function find_user_for_password_reset(PDO $conn, string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT id, username, COALESCE(NULLIF(TRIM(fullname), ''), username) AS fullname, email
        FROM `user`
        WHERE LOWER(TRIM(email)) = ?
          AND COALESCE(is_active, 1) = 1
          AND (status IS NULL OR LOWER(TRIM(status)) NOT IN ('inactive', 'disabled', 'deleted'))
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !filter_var((string)($row['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'fullname' => (string)$row['fullname'],
        'email' => (string)$row['email'],
    ];
}

function password_reset_absolute_url(string $token): string
{
    $root = get_application_web_root();
    $path = ($root === '' ? '' : $root) . '/reset_password.php?token=' . rawurlencode($token);
    return build_absolute_url($path);
}

/**
 * Invalidate outstanding unused reset tokens for a user.
 */
function invalidate_user_password_resets(PDO $conn, int $userId): void
{
    if (!user_password_resets_table_ready($conn) || $userId <= 0) {
        return;
    }
    $conn->prepare("
        UPDATE user_password_resets
           SET used_at = COALESCE(used_at, NOW())
         WHERE user_id = ? AND used_at IS NULL
    ")->execute([$userId]);
}
