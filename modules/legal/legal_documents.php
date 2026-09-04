<?php
/**
 * Legal Department — Document Management for cases, leases, and tenants.
 * Reuses Real Estate documents logic with Legal module layout.
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

define('LEGAL_DOCUMENTS_LAYOUT', true);
require __DIR__ . '/../realestate/documents.php';
