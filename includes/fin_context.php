<?php
/**
 * Finance (new chart) pages: login, role and company context.
 * Sets $brand, $appBase, $userId, $companyId, $company, $userCompanies.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/fin_coa_helper.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/url_helper.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$brand = getBrandSettings($conn);
// get_application_web_root() does not know /accounts sits under the app root.
$appBase = preg_replace('#/accounts$#', '', get_application_web_root());

$userId = (int)current_user_id();
$companyId = (int)(current_company_id($conn) ?: 0);
$company = $companyId > 0 ? get_company($conn, $companyId) : null;
$userCompanies = get_user_companies($conn, $userId);
