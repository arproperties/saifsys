<?php
/**
 * POST: switch session company and redirect back (used by module sidebars).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/company_helper.php';
require_once __DIR__ . '/includes/url_helper.php';

require_login();

$userId = current_user_id();
if (!$userId || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . redirect_url((get_application_web_root() ?: '') . '/select-module.php'));
    exit;
}

csrf_verify();

$companyId = (int)($_POST['company_id'] ?? 0);
$returnRaw = trim((string)($_POST['return'] ?? ''));

if ($companyId <= 0 || !user_has_company_access($conn, $userId, $companyId)) {
    http_response_code(403);
    echo 'Invalid company';
    exit;
}

set_current_company($companyId);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$root = get_application_web_root() ?: '';
$fallback = ($root !== '' ? $root : '') . '/select-module.php';
$target = $fallback;

if ($returnRaw !== '' && strpos($returnRaw, '..') === false && isset($returnRaw[0]) && $returnRaw[0] === '/') {
    if ($root === '' || strpos($returnRaw, $root) === 0) {
        $target = $returnRaw;
    }
}

header('Location: ' . redirect_url($target));
exit;
