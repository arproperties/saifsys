<?php
// Finance > PDC Receivables: tenants' post-dated cheques moved to PDC Receivables on their dates.
// Opening the page posts the cheques recorded since the last run.
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_pdc_helper.php';

$finListNotes = [];
if ($company && fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn) && fin_pdc_ready($conn)) {
    try {
        $finListNotes = fin_pdc_post_due($conn, $companyId, 0, $userId)['waiting'];
    } catch (Throwable $e) {
        error_log('fin_pdc_receivables: ' . $e->getMessage());
        $finListNotes = ['The cheques due could not be posted. Try again, or tell the system administrator.'];
    }
}
$finVoucherType = 'pdc';
require __DIR__ . '/fin_vouchers.php';
