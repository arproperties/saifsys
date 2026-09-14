<?php
/**
 * Dev-only router for PHP's built-in server. Stands in for the Apache
 * mod_rewrite rules in .htaccess so `php -S` behaves like the real host.
 *
 * Never used in production — Apache reads the .htaccess files instead.
 *
 *   php -S 0.0.0.0:8001 -t . scripts/dev-router.php
 */

$root = dirname(__DIR__);
$rawPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$path = rtrim($rawPath, '/');

// Root: let the server serve index.php itself.
if ($path === '') {
    return false;
}

// A real file — an asset, or a .php script.
if (is_file($root . $path)) {
    return false;
}

/**
 * Mirrors api/mobile/{ops,fleet}/.htaccess: everything under those prefixes that
 * is not a real file goes to that API's front controller, which does its own
 * routing.
 */
if (preg_match('#^/api/mobile/(ops|fleet)(/|$)#', $path, $apiMatch)) {
    $script = '/api/mobile/' . $apiMatch[1] . '/index.php';
    $_SERVER['SCRIPT_NAME'] = $script;
    $_SERVER['SCRIPT_FILENAME'] = $root . $script;
    $_SERVER['PHP_SELF'] = $script;
    require $root . $script;
    return true;
}

// Apache's DirectorySlash: a directory requested without a trailing slash must
// redirect, or the browser resolves the page's relative asset hrefs against the
// parent directory and every stylesheet 404s.
if (substr($rawPath, -1) !== '/' && is_file($root . $path . '/index.php')) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . $rawPath . '/' . ($qs !== '' ? '?' . $qs : ''), true, 301);
    return true;
}

// Clean URLs: /settings -> settings.php, /hr -> hr/index.php
foreach ([$path . '.php', $path . '/index.php'] as $try) {
    if (is_file($root . $try)) {
        $_SERVER['SCRIPT_NAME'] = $try;
        $_SERVER['SCRIPT_FILENAME'] = $root . $try;
        $_SERVER['PHP_SELF'] = $try;
        require $root . $try;
        return true;
    }
}

return false;
