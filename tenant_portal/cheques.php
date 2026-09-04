<?php
/**
 * Tenant Portal — Cheques (read-only)
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
$cheques = $conn->prepare("
    SELECT id, cheque_number, cheque_date, cheque_amount, cheque_holder_name, status, cleared_date, bounced_date, bounced_reason
    FROM re_lease_cheques
    WHERE lease_id = ?
    ORDER BY cheque_date DESC
");
$cheques->execute([$lease_id]);
$cheques = $cheques->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Cheques';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Cheques</h4>
<div class="portal-table-wrap">
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Cheque #</th>
                <th>Date</th>
                <th>Amount (AED)</th>
                <th>Holder</th>
                <th>Status</th>
                <th>Cleared / Bounced</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cheques as $row): ?>
                <tr>
                    <td data-label="Cheque #"><?= htmlspecialchars($row['cheque_number'] ?: '—') ?></td>
                    <td data-label="Date"><?= htmlspecialchars(date('M j, Y', strtotime($row['cheque_date']))) ?></td>
                    <td data-label="Amount (AED)"><?= number_format((float)$row['cheque_amount'], 2) ?></td>
                    <td data-label="Holder"><?= htmlspecialchars($row['cheque_holder_name'] ?: '—') ?></td>
                    <td data-label="Status">
                        <span class="badge bg-<?= $row['status'] === 'cleared' ? 'success' : ($row['status'] === 'bounced' ? 'danger' : ($row['status'] === 'returned' ? 'secondary' : 'warning')) ?>">
                            <?= htmlspecialchars($row['status']) ?>
                        </span>
                    </td>
                    <td data-label="Cleared / Bounced">
                        <?php if ($row['cleared_date']): ?>
                            Cleared <?= date('M j, Y', strtotime($row['cleared_date'])) ?>
                        <?php elseif ($row['bounced_date']): ?>
                            Bounced <?= date('M j, Y', strtotime($row['bounced_date'])) ?>
                            <?php if (!empty($row['bounced_reason'])): ?>
                                <br><small class="text-danger"><?= htmlspecialchars($row['bounced_reason']) ?></small>
                            <?php endif; ?>
                        <?php elseif ($row['status'] === 'returned'): ?>
                            Returned due to lease termination
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if (empty($cheques)): ?>
    <p class="portal-empty">No cheques on record.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
