<?php
// includes/rbac.php
// Tiny RBAC helper. Requires: includes/auth.php + includes/db_connect.php loaded first.

if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * Get current app user id from session (handles various existing keys).
 */
function rbac_current_user_id(): ?int {
    foreach (['user_id','id','userid','uid'] as $k) {
        if (!empty($_SESSION[$k]) && is_numeric($_SESSION[$k])) {
            return (int)$_SESSION[$k];
        }
    }
    return null;
}

/**
 * Load role names for current user into session (cached per request).
 */
function rbac_bootstrap(PDO $conn): void {
    if (!isset($_SESSION['_rbac_loaded'])) {
        $_SESSION['_rbac_loaded'] = true;
        $_SESSION['roles'] = [];

        $uid = rbac_current_user_id();
        if ($uid) {
            $stmt = $conn->prepare("
                SELECT r.name
                FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = ?
            ");
            $stmt->execute([$uid]);
            $_SESSION['roles'] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
    }
}

/** Check if current user has at least one of these roles. */
function user_has_role($roles): bool {
    if (!is_array($roles)) $roles = [$roles];
    $have = $_SESSION['roles'] ?? [];
    foreach ($roles as $r) {
        if (in_array($r, $have, true)) return true;
    }
    return false;
}

/** Guard the page: require any of the roles (else 403). */
function require_role($roles): void {
    if (!user_has_role($roles)) {
        http_response_code(403);
        echo "<div style='font-family:system-ui;padding:32px'>
                <h3>403 – Forbidden</h3>
                <p>You don’t have permission to access this page.</p>
                <p><small>Required role(s): ".htmlspecialchars(is_array($roles)?implode(', ',$roles):$roles)."</small></p>
              </div>";
        exit;
    }
}
