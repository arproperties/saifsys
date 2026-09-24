<?php
// includes/auth.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php'; // <-- ensure $conn (PDO) is available here
require_once __DIR__ . '/company_helper.php';
require_once __DIR__ . '/module_access.php';

// Error display policy (no stack traces in prod)
if (defined('APP_ENV') && APP_ENV === 'dev') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED & ~E_WARNING);
}

// Secure session cookie BEFORE session_start
if (session_status() !== PHP_SESSION_ACTIVE) {
    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $params['path'] ?? '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => defined('SESSION_SECURE') ? SESSION_SECURE : (!empty($_SERVER['HTTPS'])),
        'httponly' => true,
        'samesite' => defined('SESSION_SAMESITE') ? SESSION_SAMESITE : 'Lax',
    ]);
    session_start();
}

// Security headers (baseline)
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // blob:, with the colon. Written without it these were not permissive
    // tokens but invalid ones, silently dropped — which blocked every preview
    // thumbnail the operations composer draws from URL.createObjectURL().
    // media-src is spelled out for the same reason: a staged video preview is
    // a blob: URL in a <video>.
    header("Content-Security-Policy: default-src 'self' https: data: blob:; img-src 'self' https: data: blob:; media-src 'self' https: data: blob:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:; frame-ancestors 'self';");
}

/** Session helpers **/
function current_user_id(): ?int {
    if (!empty($_SESSION['user']['id'])) {
        return (int)$_SESSION['user']['id'];
    }
    if (!empty($_SESSION['user_id'])) {
        $_SESSION['user']['id'] = (int)$_SESSION['user_id']; // normalize
        return (int)$_SESSION['user_id'];
    }
    return null;
}

function current_user(): ?array {
    $uid = current_user_id();
    if (!$uid) return null;
    if (empty($_SESSION['user']) || empty($_SESSION['user']['id'])) {
        $_SESSION['user'] = ['id' => $uid];
    }
    return $_SESSION['user'];
}

/** Roles **/
function get_user_roles_from_db(PDO $conn, int $user_id): array {
    $stmt = $conn->prepare("
        SELECT r.name
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
        ORDER BY r.name
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function current_user_roles(PDO $conn = null): array {
    $uid = current_user_id();
    if (!$uid) return [];
    if ($conn instanceof PDO) {
        $roles = get_user_roles_from_db($conn, $uid);
        $_SESSION['roles'] = $roles; // cache for UI
        return $roles;
    }
    return isset($_SESSION['roles']) ? (array)$_SESSION['roles'] : [];
}

function has_role(string $role, PDO $conn = null): bool {
    $roles = current_user_roles($conn);
    return in_array($role, $roles, true);
}

function require_login(string $loginPath = '../login'): void {
    if (!current_user_id()) {
        // Store current URL in session for clean redirect after login
        if (session_status() === PHP_SESSION_ACTIVE) {
            $currentUri = $_SERVER['REQUEST_URI'] ?? '/';
            $_SESSION['login_next'] = preg_replace('/\.php$/', '', $currentUri);
        }
        if (!headers_sent()) header('Location: '.$loginPath);
        else echo '<script>location.href='.json_encode($loginPath).'</script>';
        exit;
    }
}

function require_role(array $allowed, PDO $conn = null): void {
    $roleMap = ['Account' => 'Accountant', 'Operation' => 'Dispatcher'];
    $allowed = array_map(fn($r) => $roleMap[$r] ?? $r, $allowed);

    require_login();

    $roles = current_user_roles($conn);
    if (!array_intersect($roles, $allowed)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

/** CSRF **/
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}
function csrf_field(): void {
    echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
function csrf_verify(bool $exitOnFail = true): bool {
    $ok = isset($_POST['_csrf']) && hash_equals($_SESSION['_csrf'] ?? '', (string)$_POST['_csrf']);
    if (!$ok && $exitOnFail) {
        http_response_code(419);
        echo 'CSRF token invalid or missing.';
        exit;
    }
    return $ok;
}

/** Session set/clear **/
function set_logged_in_user(int $user_id, ?string $username = null, ?string $fullname = null): void {
    $_SESSION['user'] = [
        'id'       => $user_id,
        'username' => $username,
        'fullname' => $fullname,
    ];
    $_SESSION['user_id'] = $user_id; // legacy compat
    
    // Load user companies and set default company
    if (isset($conn) && $conn instanceof PDO) {
        $companies = get_user_companies($conn, $user_id);
        if (!empty($companies)) {
            $_SESSION['user_companies'] = $companies;
            // Only auto-set company if user has exactly ONE company
            // If multiple companies, let them choose via selector
            if (count($companies) === 1) {
                set_current_company($companies[0]['id']);
            } else {
                // Multiple companies: clear any cached company_id to force selection
                unset($_SESSION['current_company_id']);
            }
        }
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool {
        return isset($_SESSION['user']['id']) || isset($_SESSION['user_id']);
    }
}

function logout_and_redirect(string $loginPath = '../login'): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    if (!headers_sent()) header('Location: '.$loginPath);
    else echo '<script>location.href='.json_encode($loginPath).'</script>';
    exit;
}

/** Remember-me auto-login (only if we have a PDO connection) **/
if (empty($_SESSION['user_id']) && !empty($_COOKIE['rememberme']) && isset($conn) && $conn instanceof PDO) {
    $parts = explode(':', $_COOKIE['rememberme'], 2);
    if (count($parts) === 2) {
        [$selector, $validator] = $parts;

        try {
            $stmt = $conn->prepare("SELECT id, user_id, hashed_validator, expires FROM user_tokens WHERE selector=? LIMIT 1");
            $stmt->execute([$selector]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && hash_equals($row['hashed_validator'], hash('sha256', $validator)) && strtotime($row['expires']) > time()) {
                // Load user (no 'role' column here)
                $u = $conn->prepare("SELECT id, username, fullname FROM user WHERE id=? LIMIT 1");
                $u->execute([(int)$row['user_id']]);
                $user = $u->fetch(PDO::FETCH_ASSOC);

                // The account may have been closed since this cookie was issued.
                if ($user) {
                    require_once __DIR__ . '/account_status.php';
                    if (account_login_block_reason($conn, (int)$user['id']) !== null) {
                        $conn->prepare("DELETE FROM user_tokens WHERE user_id=?")->execute([(int)$user['id']]);
                        setcookie('rememberme', '', time() - 3600, '/');
                        $user = null;
                    }
                }

                if ($user) {
                    session_regenerate_id(true);
                    set_logged_in_user((int)$user['id'], $user['username'] ?? null, $user['fullname'] ?? null);

                    // Rotate validator (mitigate token theft)
                    $newValidator = bin2hex(random_bytes(32));
                    $conn->prepare("UPDATE user_tokens SET hashed_validator=? WHERE id=?")
                         ->execute([hash('sha256', $newValidator), (int)$row['id']]);

                    setcookie('rememberme', $selector . ':' . $newValidator, [
                        'expires'  => time() + 60*60*24*30,
                        'path'     => '/',
                        'secure'   => !empty($_SERVER['HTTPS']),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }
            } elseif ($row && strtotime($row['expires']) <= time()) {
                // Expired: clean up
                $conn->prepare("DELETE FROM user_tokens WHERE id=?")->execute([(int)$row['id']]);
            }
        } catch (Throwable $e) {
            // swallow remember-me errors
        }
    }
}

// Role-based guard enforcement
if (isset($conn) && $conn instanceof PDO) {
    require_once __DIR__ . '/../lib/Guard.php';
    Guard::enforce($conn);
}
