<?php
/**
 * Ensure PHPMailer classes are loaded (Composer autoload + manual src fallback).
 * Use from any script that instantiates PHPMailer directly, including when vendor
 * was copied without a fresh composer dump-autoload.
 */
if (!function_exists('herosysgro_ensure_phpmailer')) {
    function herosysgro_ensure_phpmailer(): void
    {
        // Do not pass $autoload=false here: Composer only registers PHPMailer after autoload.php;
        // class_exists must be allowed to invoke the autoloader.
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return;
        }
        $vendor = dirname(__DIR__) . '/vendor';
        $autoload = $vendor . '/autoload.php';
        if (is_file($autoload)) {
            // Composer's generated platform_check.php throws a RuntimeException when the
            // running PHP is older than a dependency's requirement (e.g. requires >= 8.3
            // while the server runs 8.2.x). That must NOT block transactional email — we
            // catch it and fall back to loading PHPMailer's classes directly below.
            // platform_check.php may echo its warning before throwing, so buffer and
            // discard that output to keep JSON API responses clean.
            $buffering = false;
            try {
                ob_start();
                $buffering = true;
                require_once $autoload;
            } catch (\Throwable $e) {
                error_log('phpmailer_bootstrap: Composer autoload skipped (' . $e->getMessage() . '); using manual PHPMailer load.');
            } finally {
                if ($buffering && ob_get_level() > 0) {
                    ob_end_clean();
                }
            }
        }
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return;
        }
        $base = $vendor . '/phpmailer/phpmailer/src/';
        foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $f) {
            $p = $base . $f;
            if (is_file($p)) {
                require_once $p;
            }
        }
    }
}
