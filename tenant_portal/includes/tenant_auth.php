<?php
/**
 * Tenant Portal — Authentication & authorization
 * Supports: (1) tenant_portal_users (new) and (2) user + tenant_portal_accounts (legacy).
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof PDO)) {
    require_once __DIR__ . '/../../includes/db_connect.php';
}
if (!function_exists('csrf_token')) {
    require_once __DIR__ . '/../../includes/auth.php';
}
if (!function_exists('get_base_path')) {
    require_once __DIR__ . '/../../includes/url_helper.php';
}

/** Session key for new tenant identity (tenant_portal_users). */
const TENANT_PORTAL_USER_SESSION_KEY = 'tenant_portal_user_id';

/**
 * Check if current user is a tenant (approved portal account).
 * True if logged in via tenant_portal_users (new) or user+tenant_portal_accounts (legacy).
 */
function is_tenant_user(PDO $conn): bool {
    if (!empty($_SESSION[TENANT_PORTAL_USER_SESSION_KEY])) {
        return true;
    }
    $uid = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
    if (!$uid) return false;
    $stmt = $conn->prepare("
        SELECT 1 FROM tenant_portal_accounts tpa
        WHERE tpa.user_id = ? AND tpa.status = 'approved'
        LIMIT 1
    ");
    $stmt->execute([$uid]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Get current tenant's portal account row(s). Keyed by lease_id.
 * For new flow uses session; for legacy queries tenant_portal_accounts.
 */
function current_tenant_accounts(PDO $conn): array {
    $tpuId = (int)($_SESSION[TENANT_PORTAL_USER_SESSION_KEY] ?? 0);
    if ($tpuId) {
        $lease_id = (int)($_SESSION['tenant_portal_lease_id'] ?? 0);
        $tenant_id = (int)($_SESSION['tenant_portal_tenant_id'] ?? 0);
        $company_id = (int)($_SESSION['tenant_portal_company_id'] ?? 0);
        if ($lease_id) {
            return [$lease_id => [
                'id' => $tpuId,
                'lease_id' => $lease_id,
                'tenant_id' => $tenant_id,
                'company_id' => $company_id,
                'user_id' => null,
            ]];
        }
        return [];
    }
    $uid = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
    if (!$uid) return [];
    $stmt = $conn->prepare("
        SELECT tpa.* FROM tenant_portal_accounts tpa
        WHERE tpa.user_id = ? AND tpa.status = 'approved'
        ORDER BY tpa.lease_id
    ");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['lease_id']] = $r;
    }
    return $out;
}

/**
 * Get all lease IDs the current tenant has access to (for multi-lease switcher).
 * New flow: all leases from re_leases for this tenant + company (one login sees all their leases).
 * Legacy: all leases from tenant_portal_accounts for this user.
 */
function current_tenant_lease_ids(PDO $conn): array {
    $tpuId = (int)($_SESSION[TENANT_PORTAL_USER_SESSION_KEY] ?? 0);
    if ($tpuId) {
        $tenant_id = (int)($_SESSION['tenant_portal_tenant_id'] ?? 0);
        $company_id = (int)($_SESSION['tenant_portal_company_id'] ?? 0);
        if (!$tenant_id) return [];
        // Show all leases for this tenant in this company (so one login sees all their leases)
        $stmt = $conn->prepare("
            SELECT id FROM re_leases
            WHERE tenant_id = ? AND company_id = ?
            ORDER BY lease_number
        ");
        $stmt->execute([$tenant_id, $company_id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $accounts = current_tenant_accounts($conn);
    return $accounts ? array_map('intval', array_keys($accounts)) : [];
}

/**
 * Get currently selected (or first) lease_id for current tenant.
 * Uses session tenant_portal_lease_id if set and in allowed list.
 */
function current_tenant_lease_id(PDO $conn): ?int {
    $all = current_tenant_lease_ids($conn);
    if (empty($all)) return null;
    $selected = (int)($_SESSION['tenant_portal_lease_id'] ?? 0);
    if ($selected && in_array($selected, $all, true)) return $selected;
    return $all[0];
}

/**
 * Get current tenant's company_id.
 */
function current_tenant_company_id(PDO $conn): ?int {
    $accounts = current_tenant_accounts($conn);
    if (empty($accounts)) return null;
    $first = reset($accounts);
    return (int) $first['company_id'];
}

/**
 * Require tenant login; redirect to portal login if not logged in or not approved.
 */
function require_tenant_login(PDO $conn): void {
    $hasNew = !empty($_SESSION[TENANT_PORTAL_USER_SESSION_KEY]);
    $hasLegacy = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0) && is_tenant_user($conn);
    if (!$hasNew && !$hasLegacy) {
        $_SESSION['tenant_portal_next'] = $_SERVER['REQUEST_URI'] ?? 'tenant_portal/dashboard.php';
        $in_portal = (strpos($_SERVER['REQUEST_URI'] ?? '', 'tenant_portal') !== false);
        $login_target = $in_portal ? 'login.php' : ((get_base_path() ? get_base_path() . '/' : '') . 'tenant_portal/login.php');
        header('Location: ' . $login_target);
        exit;
    }
    if (!is_tenant_user($conn)) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Access denied</title></head><body>';
        echo '<p>You do not have access to the Tenant Portal. If you believe this is an error, contact management.</p>';
        echo '<p><a href="login.php">Back to login</a></p></body></html>';
        exit;
    }
}

/**
 * Base path for tenant portal (e.g. /tenant_portal or /herosysgro/tenant_portal).
 */
function tenant_portal_base(): string {
    $root = get_application_web_root();

    return ($root === '' ? '' : $root) . '/tenant_portal';
}

/**
 * Full URL for a tenant portal path (for emails).
 */
function tenant_portal_url(string $path): string {
    if (!function_exists('tenant_portal_absolute_url')) {
        require_once __DIR__ . '/../../includes/url_helper.php';
    }

    return tenant_portal_absolute_url($path);
}
