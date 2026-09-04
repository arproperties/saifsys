<?php
/**
 * CSRF Protection Helper
 * Wrapper file - functions are already defined in auth.php
 * This file exists for backward compatibility
 */

// Functions are already defined in auth.php if it was included first
// Only define them if they don't already exist (for cases where csrf.php is included without auth.php)
if (!function_exists('csrf_token')) {
    // Ensure session is started
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    /**
     * Generate CSRF token
     * @return string
     */
    function csrf_token(): string {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Output CSRF field for forms
     * @return void
     */
    function csrf_field(): void {
        echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_verify')) {
    /**
     * Verify CSRF token
     * @param bool $exitOnFail
     * @return bool
     */
    function csrf_verify(bool $exitOnFail = true): bool {
        $ok = isset($_POST['_csrf']) && hash_equals($_SESSION['_csrf'] ?? '', (string)$_POST['_csrf']);
        if (!$ok && $exitOnFail) {
            http_response_code(419);
            echo 'CSRF token invalid or missing.';
            exit;
        }
        return $ok;
    }
}

