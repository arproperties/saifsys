<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.import');

require_once __DIR__ . '/../../includes/re_bank_reco_xlsx.php';
re_bank_serve_import_template_xlsx();
