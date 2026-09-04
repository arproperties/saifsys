<?php
/**
 * Test file to verify Accounting menu is in the layout
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Check department access
$hasFinancial = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn);

echo "<h1>Accounting Menu Debug</h1>";
echo "<p>hasFinancial: " . ($hasFinancial ? 'TRUE' : 'FALSE') . "</p>";
echo "<p>File exists: " . (file_exists(__DIR__ . '/includes/re_layout_header.php') ? 'YES' : 'NO') . "</p>";

// Check if Accounting is in the file
$fileContent = file_get_contents(__DIR__ . '/includes/re_layout_header.php');
$hasAccounting = strpos($fileContent, 'Accounting') !== false;
echo "<p>Accounting found in file: " . ($hasAccounting ? 'YES' : 'NO') . "</p>";

if ($hasAccounting) {
    // Show the Accounting section
    preg_match('/<div class="nav-sect.*?">Accounting<\/div>.*?<\/div>/s', $fileContent, $matches);
    if (!empty($matches)) {
        echo "<h2>Accounting Section Found:</h2>";
        echo "<pre>" . htmlspecialchars(substr($matches[0], 0, 500)) . "</pre>";
    }
}

echo "<hr>";
echo "<h2>Full Navigation Test:</h2>";
$currentPage = 'test_accounting_menu.php';
require_once __DIR__ . '/includes/re_layout_header.php';
