<?php
/**
 * Legal Department — Post-Dated Cheques (read-only operational view for legal team).
 * Reuses Real Estate PDC logic with Legal module layout and deep links.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

define('LEGAL_PDC_LAYOUT', true);
require __DIR__ . '/../realestate/billing_cheques.php';
