<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = co_shop_require_company_id($conn);
$rows = [];
if (co_db_table_exists($conn, 'co_shop_rental_contracts')) {
    $stmt = $conn->prepare("
        SELECT c.contract_number, c.status, cl.client_name,
               c.security_deposit, c.deposit_received_amount,
               GREATEST(c.security_deposit - c.deposit_received_amount, 0) AS deposit_outstanding,
               (SELECT COALESCE(SUM(refund_amount),0) FROM co_shop_deposit_settlements ds
                WHERE ds.company_id = c.company_id AND ds.contract_id = c.id AND ds.status = 'finalized') AS refunded,
               (SELECT COALESCE(SUM(damage_amount+utility_amount+cleaning_amount+forfeit_amount),0)
                FROM co_shop_deposit_settlements ds
                WHERE ds.company_id = c.company_id AND ds.contract_id = c.id AND ds.status = 'finalized') AS recovered
        FROM co_shop_rental_contracts c
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE c.company_id = ?
          AND (c.security_deposit > 0 OR c.deposit_received_amount > 0)
        ORDER BY c.deposit_received_amount DESC, c.id
    ");
    try {
        $stmt->execute([$cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // settlements table missing
        $stmt = $conn->prepare("
            SELECT c.contract_number, c.status, cl.client_name,
                   c.security_deposit, c.deposit_received_amount,
                   GREATEST(c.security_deposit - c.deposit_received_amount, 0) AS deposit_outstanding,
                   0 AS refunded, 0 AS recovered
            FROM co_shop_rental_contracts c
            JOIN co_clients cl ON cl.id = c.client_id
            WHERE c.company_id = ? AND (c.security_deposit > 0 OR c.deposit_received_amount > 0)
            ORDER BY c.deposit_received_amount DESC
        ");
        $stmt->execute([$cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
co_report_export($rows, [
    'contract_number' => 'Contract', 'client_name' => 'Tenant', 'status' => 'Status',
    'security_deposit' => 'Required', 'deposit_received_amount' => 'Held (2200)',
    'deposit_outstanding' => 'Still Due', 'refunded' => 'Refunded', 'recovered' => 'Recovered (4160)',
], 'shop_deposit_liability', 'Deposit Liability');
$pageTitle = 'Deposit Liability Report';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Deposit Liability Report</h1><p class="text-muted mb-0">Security deposits held (liability 2200) by contract</p></div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<div class="card card-round"><div class="card-body p-0 table-responsive">
<table class="table table-hover mb-0"><thead class="table-light"><tr>
<th>Contract</th><th>Tenant</th><th>Status</th><th class="text-end">Required</th><th class="text-end">Held</th><th class="text-end">Still Due</th><th class="text-end">Refunded</th><th class="text-end">Recovered</th>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= h($row['contract_number']) ?></td><td><?= h($row['client_name']) ?></td><td><?= h($row['status']) ?></td>
<td class="text-end"><?= co_format_money($row['security_deposit']) ?></td>
<td class="text-end"><?= co_format_money($row['deposit_received_amount']) ?></td>
<td class="text-end"><?= co_format_money($row['deposit_outstanding']) ?></td>
<td class="text-end"><?= co_format_money($row['refunded']) ?></td>
<td class="text-end"><?= co_format_money($row['recovered']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No deposit activity.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
