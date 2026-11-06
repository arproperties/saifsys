<?php
/**
 * Finance > Vouchers: change a saved voucher (?id=). The form itself is shared
 * with the "new voucher" pages; a supplier invoice opens its own form. A deleted or unknown voucher has nothing to
 * edit, so it goes to the voucher page, which says so.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_voucher_helper.php';

$finEditId = (int)($_GET['id'] ?? 0);
$finEditVoucher = (fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn) && $company && $finEditId > 0)
    ? fin_voucher_get($conn, $companyId, $finEditId)
    : null;
if (!$finEditVoucher || $finEditVoucher['status'] !== 'posted') {
    header('Location: fin_voucher_view.php?id=' . $finEditId);
    exit;
}
// Supplier payments are deleted and entered again; advance journals change with their allocation;
// monthly scheduled entries come from their schedule.
$finEditLinks = fin_voucher_links($conn, $companyId, $finEditId);
if ($finEditLinks['supplier_payment'] || $finEditLinks['transfer'] || in_array($finEditVoucher['type'], FIN_SCHEDULED_TYPES, true)) {
    header('Location: fin_voucher_view.php?id=' . $finEditId);
    exit;
}
$finVoucherType = $finEditVoucher['type'];
require __DIR__ . ($finVoucherType === 'si' ? '/fin_supplier_invoice_form.php' : '/fin_voucher_form.php');
