<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_income_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$filterClientId = (int)($_GET['client_id'] ?? 0);
$accountJoin = co_client_payment_columns_ready($conn) ? "LEFT JOIN re_chart_of_accounts coa ON coa.id = p.pay_account_id AND coa.company_id = p.company_id" : "";
$accountSelect = co_client_payment_columns_ready($conn) ? "coa.account_code, coa.account_name," : "NULL AS account_code, NULL AS account_name,";
$where = "WHERE p.company_id = ?";
$params = [$cid];
if ($filterClientId > 0) {
    $where .= " AND COALESCE(p.client_id, i.client_id) = ?";
    $params[] = $filterClientId;
}
$stmt = $conn->prepare("
    SELECT p.*, {$accountSelect} i.invoice_number, c.client_name, jh.journal_number
    FROM co_client_payments p
    LEFT JOIN co_client_invoices i ON i.id = p.invoice_id
    LEFT JOIN co_clients c ON c.id = COALESCE(p.client_id, i.client_id)
    {$accountJoin}
    LEFT JOIN re_journal_headers jh ON jh.id = p.journal_id
    {$where}
    ORDER BY p.payment_date DESC, p.id DESC
");
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Client Receipts';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div><h1 class="h4 mb-0">Client Receipts</h1><p class="text-muted mb-0">Income receipts posted to bank/cash and Accounts Receivable.</p></div>
    <a href="client_payment_add.php" class="btn btn-outline-primary">Classic Receipt</a>
    <a href="shop_rental_payment_workspace.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Receive Payment</a>
</div>
<div class="card card-round"><div class="card-body p-0 table-responsive"><table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Date</th><th>Customer/Tenant</th><th>Invoice</th><th class="text-end">Amount</th><th>Received To</th><th>Reference</th><th>GL</th><th></th></tr></thead><tbody>
    <?php foreach ($payments as $payment): ?><tr><td><?= h($payment['payment_date']) ?></td><td><?= h($payment['client_name'] ?: '-') ?></td><td><?= h($payment['invoice_number'] ?: '-') ?></td><td class="text-end"><?= co_format_money($payment['amount']) ?></td><td><?= !empty($payment['account_code']) ? h($payment['account_code'] . ' - ' . $payment['account_name']) : '-' ?></td><td><?= h($payment['reference'] ?: '-') ?></td><td><?= $payment['journal_id'] ? '<span class="badge bg-success">' . h($payment['journal_number'] ?: 'Posted') . '</span>' : '<span class="badge bg-secondary">Unposted</span>' ?></td><td class="text-end text-nowrap"><a href="client_payment_receipt.php?id=<?= (int)$payment['id'] ?>" class="btn btn-sm btn-outline-primary">View Receipt</a> <a href="client_receipt_pdf.php?id=<?= (int)$payment['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">PDF</a></td></tr><?php endforeach; ?>
    <?php if (!$payments): ?><tr><td colspan="8" class="text-center text-muted py-4">No client receipts found.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
