<?php
/**
 * SMTP UI consolidated into Settings Center (canonical).
 * See docs/SETTINGS_CENTER_TAXONOMY.md
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/url_helper.php';

require_login();
if (!has_role('admin') && !has_role('Owner') && !has_role('Admin')) {
    header('Location: ../login');
    exit;
}

$base = function_exists('get_base_path') ? get_base_path() : '';
header('Location: ' . $base . '/settings.php?tab=email');
exit;
