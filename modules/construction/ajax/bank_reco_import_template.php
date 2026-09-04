<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../includes/construction_bank_reconciliation.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
co_bank_reco_require_permission($conn, 'construction.bank_reconciliation.import');

co_bank_rec_download_import_template_xlsx();
