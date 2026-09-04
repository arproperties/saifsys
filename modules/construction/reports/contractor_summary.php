<?php
/**
 * Construction Module — Contractor Summary Report
 * Commercial progress from linked Supplier/AP (BR-CO-BP-005)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/construction_helpers.php';
require_once __DIR__ . '/../includes/construction_contractor_supplier_helpers.php';

require_login();
require_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_REPORTS, $conn);

require_once __DIR__ . '/../../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn);
if (!$cid) {
    http_response_code(403);
    die('Company context required.');
}

$stmt = $conn->prepare("
    SELECT pc.id, p.id as project_id, p.project_code, p.project_name, c.id AS contractor_id,
           c.contractor_name, pc.contract_value, pc.retention_pct
    FROM co_project_contractors pc
    JOIN co_projects p ON p.id = pc.project_id AND p.company_id = pc.company_id
    JOIN co_contractors c ON c.id = pc.contractor_id AND c.company_id = pc.company_id
    WHERE pc.company_id = ? AND pc.status = 'active'
    ORDER BY p.project_name, c.contractor_name
");
$stmt->execute([$cid]);
$baseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rows = [];
foreach ($baseRows as $r) {
    $prog = co_project_contractor_commercial_progress($conn, $cid, (int)$r['id']);
    $rows[] = [
        'project_id' => (int)$r['project_id'],
        'project_code' => $r['project_code'],
        'project_name' => $r['project_name'],
        'contractor_name' => $r['contractor_name'],
        'linked' => !empty($prog['linked']),
        'contract_value' => (float)$prog['contract_value'],
        'total_invoiced' => (float)$prog['total_invoiced'],
        'total_paid' => (float)$prog['total_paid'],
        'outstanding_ap' => (float)$prog['outstanding_ap'],
        'remaining' => (float)$prog['remaining_contract_value'],
        'billing_pct' => (float)$prog['billing_progress_pct'],
        'payment_pct' => (float)$prog['payment_progress_pct'],
        'retention_balance' => (float)$prog['retention_balance'],
    ];
}

require_once __DIR__ . '/../../realestate/accounting/export_excel_helper.php';
$exportRows = [];
foreach ($rows as $r) {
    $exportRows[] = [
        'project' => trim(($r['project_code'] ? $r['project_code'] . ' - ' : '') . $r['project_name']),
        'contractor' => $r['contractor_name'],
        'linked' => $r['linked'] ? 'Yes' : 'No',
        'contract_value' => $r['contract_value'],
        'invoiced' => $r['total_invoiced'],
        'paid' => $r['total_paid'],
        'outstanding_ap' => $r['outstanding_ap'],
        'remaining' => $r['remaining'],
        'billing_pct' => $r['billing_pct'],
        'payment_pct' => $r['payment_pct'],
    ];
}
if (($_GET['export'] ?? '') === 'excel') {
    accounting_export_excel_or_csv($exportRows, [
        'project' => 'Project', 'contractor' => 'Contractor', 'linked' => 'Supplier Linked',
        'contract_value' => 'Contract Value', 'invoiced' => 'Invoiced', 'paid' => 'Paid',
        'outstanding_ap' => 'Outstanding AP', 'remaining' => 'Remaining Contract',
        'billing_pct' => 'Billing %', 'payment_pct' => 'Payment %',
    ], 'construction_contractor_summary', 'Construction Contractor Summary');
} elseif (($_GET['export'] ?? '') === 'csv') {
    accounting_export_csv($exportRows, [
        'project' => 'Project', 'contractor' => 'Contractor', 'linked' => 'Supplier Linked',
        'contract_value' => 'Contract Value', 'invoiced' => 'Invoiced', 'paid' => 'Paid',
        'outstanding_ap' => 'Outstanding AP', 'remaining' => 'Remaining Contract',
        'billing_pct' => 'Billing %', 'payment_pct' => 'Payment %',
    ], 'construction_contractor_summary');
}

$pageTitle = 'Contractor Summary';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Contractor Summary</h1>
        <p class="text-muted mb-0">Commercial progress from linked Supplier/AP</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-sm" href="?export=csv"><i class="bi bi-download"></i> CSV</a>
        <a class="btn btn-outline-success btn-sm" href="?export=excel"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
</div>

<div class="card card-round">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Project</th>
                        <th>Contractor</th>
                        <th>Linked</th>
                        <th>Contract Value</th>
                        <th>Invoiced</th>
                        <th>Paid</th>
                        <th>Outstanding AP</th>
                        <th>Remaining</th>
                        <th>Billing %</th>
                        <th>Payment %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="../project_view.php?id=<?= (int)$r['project_id'] ?>"><?= h($r['project_code']) ?> — <?= h($r['project_name']) ?></a></td>
                        <td><?= h($r['contractor_name']) ?></td>
                        <td><?= $r['linked'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-warning text-dark">No</span>' ?></td>
                        <td><?= co_format_money($r['contract_value']) ?></td>
                        <td><?= $r['linked'] ? co_format_money($r['total_invoiced']) : '—' ?></td>
                        <td><?= $r['linked'] ? co_format_money($r['total_paid']) : '—' ?></td>
                        <td><?= $r['linked'] ? co_format_money($r['outstanding_ap']) : '—' ?></td>
                        <td class="<?= $r['remaining'] > 0 ? 'text-warning' : '' ?>"><?= co_format_money($r['remaining']) ?></td>
                        <td><?= $r['linked'] ? number_format($r['billing_pct'], 1) . '%' : '—' ?></td>
                        <td><?= $r['linked'] ? number_format($r['payment_pct'], 1) . '%' : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($rows)): ?>
        <div class="p-4 text-center text-muted">No project–contractor links found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
