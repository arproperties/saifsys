<?php
/**
 * Load Composer autoload without fatal on PHP platform version mismatch (e.g. local 8.2 vs vendor 8.3).
 * Swallows platform_check.php output so JSON APIs stay clean.
 */
if (!function_exists('herosysgro_composer_autoload_safe')) {
    function herosysgro_composer_autoload_safe(?string $autoloadPath = null): bool
    {
        if ($autoloadPath === null) {
            $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
        }
        if (!is_file($autoloadPath)) {
            return false;
        }
        if (class_exists('ComposerAutoloaderInit', false)) {
            return true;
        }
        try {
            ob_start();
            require_once $autoloadPath;
            ob_end_clean();
            return true;
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            error_log('composer_autoload_safe: skipped (' . $e->getMessage() . ')');
            return false;
        }
    }
}
