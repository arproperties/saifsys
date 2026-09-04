<?php
/**
 * Legacy inventory URLs for POS screens redirect to the Grocery module.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/url_helper.php';

$script = basename($_SERVER['SCRIPT_NAME'] ?? 'pos_dashboard.php');
$target = rtrim(get_application_web_root(), '/') . '/modules/grocery/' . $script;
if (!empty($_SERVER['QUERY_STRING'])) {
    $target .= '?' . $_SERVER['QUERY_STRING'];
}
header('Location: ' . $target, true, 302);
exit;
