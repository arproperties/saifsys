<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$leaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : 0;
$rowId = !empty($_GET['row_id']) ? (int)$_GET['row_id'] : 0;

if (!$leaseId) {
    header('Location: leases.php');
    exit;
}

$stmt = $conn->prepare("SELECT lease_number FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
$stmt->execute([$leaseId, $currentCompanyId]);
$leaseNumber = (string)($stmt->fetchColumn() ?: ('Lease #' . $leaseId));

$where = 'a.company_id = ? AND a.lease_id = ?';
$params = [$currentCompanyId, $leaseId];
$linkedChequeIds = [];
if ($rowId > 0) {
    try {
        $chequeStmt = $conn->prepare("
            SELECT id FROM re_post_dated_cheques WHERE company_id = ? AND lease_id = ? AND installment_id = ?
            UNION
            SELECT id FROM re_lease_cheques WHERE company_id = ? AND lease_id = ? AND installment_id = ?
        ");
        $chequeStmt->execute([$currentCompanyId, $leaseId, $rowId, $currentCompanyId, $leaseId, $rowId]);
        $linkedChequeIds = array_values(array_unique(array_map('intval', $chequeStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    } catch (Throwable $e) {
        $linkedChequeIds = [];
    }
    $rowClauses = ['a.schedule_row_id = ?'];
    $params[] = $rowId;
    if ($linkedChequeIds) {
        $rowClauses[] = 'a.cheque_id IN (' . implode(',', array_fill(0, count($linkedChequeIds), '?')) . ')';
        foreach ($linkedChequeIds as $chequeId) {
            $params[] = $chequeId;
        }
    }
    $where .= ' AND (' . implode(' OR ', $rowClauses) . ')';
}

$rows = [];
$loadError = '';
try {
    $stmt = $conn->prepare("
        SELECT a.*, u.username
        FROM re_lease_payment_schedule_audit a
        LEFT JOIN user u ON u.id = a.changed_by
        WHERE $where
        ORDER BY a.changed_at DESC, a.id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
    $loadError = $e->getMessage();
}

$showingLeaseFallback = false;
if ($rowId > 0 && !$rows && $loadError === '') {
    try {
        $stmt = $conn->prepare("
            SELECT a.*, u.username
            FROM re_lease_payment_schedule_audit a
            LEFT JOIN user u ON u.id = a.changed_by
            WHERE a.company_id = ? AND a.lease_id = ?
            ORDER BY a.changed_at DESC, a.id DESC
        ");
        $stmt->execute([$currentCompanyId, $leaseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $showingLeaseFallback = (bool)$rows;
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
    }
}

$mismatchRows = [];
try {
    $stmt = $conn->prepare("
        SELECT m.*, u.username
        FROM re_lease_payment_schedule_mismatch_approvals m
        LEFT JOIN user u ON u.id = m.approved_by
        WHERE m.company_id = ? AND m.lease_id = ?
        ORDER BY m.approved_at DESC, m.id DESC
    ");
    $stmt->execute([$currentCompanyId, $leaseId]);
    $mismatchRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $mismatchRows = [];
}

$pageTitle = 'Payment Schedule Audit';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-clock-history"></i> Payment Schedule Audit</div>
    <a href="lease_view.php?id=<?= (int)$leaseId ?>#installments" class="btn btn-outline-secondary">Back to Lease</a>
</div>

<div class="card card-round">
    <div class="card-header">
        <strong><?= h($leaseNumber) ?></strong>
        <?php if ($rowId): ?><span class="badge bg-secondary ms-2">Row #<?= (int)$rowId ?></span><?php endif; ?>
        <?php if ($linkedChequeIds): ?><span class="badge bg-info ms-2">Cheque <?= h(implode(', ', $linkedChequeIds)) ?></span><?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if ($loadError): ?>
            <div class="alert alert-danger m-3">Could not load audit entries: <?= h($loadError) ?></div>
        <?php elseif (!$rows): ?>
            <div class="text-center text-muted py-4">
                No audit entries found.
                <?php if ($rowId): ?>
                    <div class="small mt-2">No direct history was found for this row or its linked cheque. Try opening the full lease audit without a row filter.</div>
                    <a href="lease_payment_schedule_audit.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-sm btn-outline-primary mt-2">Show Full Lease Audit</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($showingLeaseFallback): ?>
                <div class="alert alert-info m-3">
                    No direct entries were found for row #<?= (int)$rowId ?>, so the full lease schedule audit is shown below.
                </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Row</th>
                            <th>Field</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                            <th>Changed By</th>
                            <th>Reason</th>
                            <th>Mode</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= h($row['changed_at']) ?></td>
                                <td><?= h($row['schedule_row_id'] ?: '-') ?></td>
                                <td><?= h($row['field_name']) ?></td>
                                <td><?= h($row['old_value']) ?></td>
                                <td><?= h($row['new_value']) ?></td>
                                <td><?= h($row['username'] ?: ('User #' . $row['changed_by'])) ?></td>
                                <td><?= h($row['reason'] ?: '-') ?></td>
                                <td><span class="badge bg-<?= $row['accounting_mode'] === 'invoice' ? 'primary' : 'secondary' ?>"><?= h($row['accounting_mode']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($mismatchRows): ?>
<div class="card card-round mt-4">
    <div class="card-header"><strong>Schedule Mismatch Approvals</strong></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Expected Total</th>
                        <th class="text-end">Scheduled Total</th>
                        <th class="text-end">Difference</th>
                        <th>Approved By</th>
                        <th>Reason</th>
                        <th>Mode</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mismatchRows as $row): ?>
                        <tr>
                            <td><?= h($row['approved_at']) ?></td>
                            <td class="text-end"><?= number_format((float)$row['expected_total'], 2) ?></td>
                            <td class="text-end"><?= number_format((float)$row['scheduled_total'], 2) ?></td>
                            <td class="text-end <?= abs((float)$row['difference_amount']) > 0.02 ? 'text-warning fw-bold' : '' ?>"><?= number_format((float)$row['difference_amount'], 2) ?></td>
                            <td><?= h($row['username'] ?: ('User #' . $row['approved_by'])) ?></td>
                            <td><?= h($row['reason'] ?: '-') ?></td>
                            <td><span class="badge bg-<?= $row['accounting_mode'] === 'invoice' ? 'primary' : 'secondary' ?>"><?= h($row['accounting_mode']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

