<?php
/**
 * URL Helper Functions
 * Provides utilities for building absolute URLs and base path
 */

/**
 * Get application base path (empty when at server root, or e.g. /herosysgro when in subfolder).
 * Use for links: get_base_path() . '/profile' or (get_base_path() ?: '') . '/profile'
 */
if (!function_exists('get_base_path')) {
    function get_base_path(): string {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $basePath = rtrim(str_replace('\\', '/', $scriptDir), '/');
        return ($basePath === '' || $basePath === '/') ? '' : $basePath;
    }
}

/**
 * Web path to the application root (the folder that contains /modules and /tenant_portal).
 * When the current script is under /modules/..., returns the path above /modules (e.g. /herosysgro).
 * When under /api/... (e.g. api/pos/index.php), returns the path above /api so static URLs (uploads/) resolve correctly.
 * When under /tenant_portal, returns the path above that folder.
 * Use this for tenant-facing URLs so links never point under /modules/....
 */
if (!function_exists('get_application_web_root')) {
    function get_application_web_root(): string {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($dir === '' || $dir === '/') {
            return '';
        }
        $modulesPos = strpos($dir, '/modules');
        if ($modulesPos !== false) {
            return substr($dir, 0, $modulesPos);
        }
        $apiPos = strpos($dir, '/api');
        if ($apiPos !== false) {
            $root = substr($dir, 0, $apiPos);
            return $root === '' ? '' : $root;
        }
        if (preg_match('#^(.*)/tenant_portal$#', $dir, $m)) {
            return $m[1] === '' ? '' : $m[1];
        }
        // HR module lives at /hr/ under app root (not under /modules)
        if (preg_match('#^(.*)/hr$#', $dir, $m)) {
            return $m[1] === '' ? '' : $m[1];
        }
        return $dir;
    }
}

/**
 * Full absolute URL to a script under /tenant_portal/ (for emails and redirects).
 *
 * @param string $path Path inside tenant_portal, e.g. invite.php?token=abc
 */
if (!function_exists('tenant_portal_absolute_url')) {
    function tenant_portal_absolute_url(string $path): string {
        $path = ltrim($path, '/');
        $root = get_application_web_root();
        $rel  = ($root === '' ? '' : $root) . '/tenant_portal/' . $path;
        if ($rel === '' || $rel[0] !== '/') {
            $rel = '/' . ltrim($rel, '/');
        }
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

        return $protocol . '://' . $host . $rel;
    }
}

/**
 * Build absolute URL from relative path
 * 
 * @param string $path Relative path (e.g., '/operation', '/account')
 * @return string Absolute URL (e.g., 'https://sys.saifholdinggroup.com/operation')
 */
function build_absolute_url(string $path): string {
    // Ensure path starts with /
    $path = '/' . ltrim($path, '/');
    
    // Remove trailing slash (except for root)
    if ($path !== '/' && substr($path, -1) === '/') {
        $path = rtrim($path, '/');
    }
    
    // Get protocol
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    
    // Get host
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    
    // Build absolute URL
    return $protocol . '://' . $host . $path;
}

/**
 * Build redirect URL (wrapper for build_absolute_url)
 * 
 * @param string $path Relative path
 * @return string Absolute URL for redirects
 */
function redirect_url(string $path): string {
    return build_absolute_url($path);
}

/**
 * Merge GET parameters with session-stored filter state
 * 
 * GET parameters take precedence over session values
 * This allows clean URLs while maintaining filter state
 * 
 * @param string $pageKey Page identifier (e.g., 'operation_workorder')
 * @return array Merged filter array
 */
if (!function_exists('merge_get_with_session')) {
    function merge_get_with_session(string $pageKey): array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Get filters from session
        $sessionFilters = $_SESSION['filter_state'][$pageKey] ?? [];
        
        // Get filters from GET parameters
        $getFilters = $_GET;
        
        // Merge: GET parameters override session values
        $merged = array_merge($sessionFilters, $getFilters);
        
        // Update session with current GET values (if any)
        if (!empty($getFilters)) {
            if (!isset($_SESSION['filter_state'])) {
                $_SESSION['filter_state'] = [];
            }
            $_SESSION['filter_state'][$pageKey] = $merged;
        }
        
        return $merged;
    }
}
