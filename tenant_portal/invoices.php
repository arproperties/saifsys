<?php
/**
 * Tenant Portal — Invoices (read-only)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

$lease_id = (int)$lease['lease_id'];
$invoices = $conn->prepare("
    SELECT id, invoice_number, invoice_date, due_date, total_amount, paid_amount, outstanding_amount, status
    FROM re_invoices
    WHERE lease_id = ?
    ORDER BY invoice_date DESC
");
$invoices->execute([$lease_id]);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Invoices';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Invoices</h4>
<div class="portal-table-wrap">
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Date</th>
                <th>Due date</th>
                <th>Total (AED)</th>
                <th>Paid (AED)</th>
                <th>Outstanding (AED)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoices as $row): ?>
                <tr>
                    <td data-label="Invoice #"><?= htmlspecialchars($row['invoice_number']) ?></td>
                    <td data-label="Date"><?= htmlspecialchars(date('M j, Y', strtotime($row['invoice_date']))) ?></td>
                    <td data-label="Due date"><?= htmlspecialchars(date('M j, Y', strtotime($row['due_date']))) ?></td>
                    <td data-label="Total (AED)"><?= number_format((float)$row['total_amount'], 2) ?></td>
                    <td data-label="Paid (AED)"><?= number_format((float)($row['paid_amount'] ?? 0), 2) ?></td>
                    <td data-label="Outstanding (AED)"><?= number_format((float)$row['outstanding_amount'], 2) ?></td>
                    <td data-label="Status">
                        <span class="badge bg-<?= $row['status'] === 'paid' ? 'success' : ($row['status'] === 'overdue' ? 'danger' : 'secondary') ?>">
                            <?= htmlspecialchars($row['status']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if (empty($invoices)): ?>
    <p class="portal-empty">No invoices on record.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
