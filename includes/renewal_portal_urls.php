<?php
/**
 * Absolute URLs for tenant portal renewal deep links (emails, admin UI).
 * Prefer setting APP_BASE_URL in includes/config.php (or env) to a full origin, e.g. https://example.com/herosysgro
 */

if (!function_exists('app_install_web_prefix')) {
    /**
     * Web path prefix for the app root (e.g. '' or '/herosysgro').
     * If APP_BASE_URL includes a path, returns that path only (no origin).
     */
    function app_install_web_prefix(): string {
        if (defined('APP_BASE_URL') && is_string(APP_BASE_URL) && APP_BASE_URL !== '') {
            $p = parse_url(APP_BASE_URL, PHP_URL_PATH);
            if ($p !== null && $p !== false && $p !== '' && $p !== '/') {
                return rtrim((string)$p, '/');
            }
        }
        $sn = $_SERVER['SCRIPT_NAME'] ?? '';
        if (preg_match('#^(.+?)/modules/#', $sn, $m)) {
            return rtrim($m[1], '/') === '/' ? '' : rtrim($m[1], '/');
        }
        if (preg_match('#^(.+)/tenant_portal/#', $sn, $m)) {
            $p = rtrim($m[1], '/');
            return $p === '' ? '' : $p;
        }
        return '';
    }
}

if (!function_exists('renewal_portal_renewal_detail_abs_url')) {
    /**
     * Direct link to open this renewal in the tenant portal (after login if needed).
     */
    function renewal_portal_renewal_detail_abs_url(int $workflowId): string {
        $tail = '/tenant_portal/renewal_detail.php?id=' . max(0, (int)$workflowId);

        if (defined('APP_BASE_URL') && is_string(APP_BASE_URL) && APP_BASE_URL !== ''
            && preg_match('#^https?://#i', APP_BASE_URL)) {
            $u = parse_url(APP_BASE_URL);
            $origin = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '');
            if (!empty($u['port'])) {
                $origin .= ':' . (int)$u['port'];
            }
            $path = isset($u['path']) ? rtrim((string)$u['path'], '/') : '';
            if ($path !== '' && $path !== '/') {
                return $origin . $path . $tail;
            }
            $pre = app_install_web_prefix();
            return $origin . ($pre !== '' ? $pre : '') . $tail;
        }

        $pre = app_install_web_prefix();
        $full = ($pre !== '' ? $pre : '') . $tail;
        if (!function_exists('build_absolute_url')) {
            require_once __DIR__ . '/url_helper.php';
        }
        return build_absolute_url($full);
    }
}

if (!function_exists('renewal_portal_renewal_detail_link_html')) {
    /** HTML anchor for email templates (uses RENEWAL_PORTAL_URL placeholder target). */
    function renewal_portal_renewal_detail_link_html(int $workflowId, string $label = 'Open renewal in Tenant Portal'): string {
        $url = htmlspecialchars(renewal_portal_renewal_detail_abs_url($workflowId), ENT_QUOTES, 'UTF-8');
        $lab = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return '<a href="' . $url . '" style="color:#0d6efd;">' . $lab . '</a>';
    }
}
