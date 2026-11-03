<?php
/**
 * Uploads saved under uploads/ and opened by a direct link: keep only document
 * and image types, and make sure the folder never runs a script that gets in.
 */

const HR_UPLOAD_SAFE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

/** Lower-case extension of an uploaded file's name, or null when it is not an allowed type. */
function hr_upload_safe_ext(string $name, array $allowed = HR_UPLOAD_SAFE_EXTENSIONS): ?string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, $allowed, true) ? $ext : null;
}

/**
 * Stop the web server running scripts from $dir and from uploads/ itself, while
 * still serving the files. uploads/ is not in git, so the rule is written here.
 */
function hr_upload_protect_dir(string $dir): void
{
    $rule = "# Written by hr_upload_protect_dir(): files here are served, never run.\n"
        . "<FilesMatch \"(?i)\\.(php[0-9]?|phtml|phar|pht|phps|shtml|cgi|pl|py|sh)(\\.|$)\">\n"
        . "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n"
        . "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n"
        . "</FilesMatch>\n";
    foreach ([dirname(__DIR__, 2) . '/uploads', $dir] as $d) {
        if (is_dir($d) && !file_exists($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', $rule);
        }
    }
}
