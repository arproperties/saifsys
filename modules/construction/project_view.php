<?php
/**
 * Construction Module — Project View
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_contractor_supplier_helpers.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_request_links.php';
require_once __DIR__ . '/../../includes/inventory/inv_material_requests_for_modules.php';

require_login();
$hasAccess = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_CORE, $conn)
    || has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_PROJECTS, $conn);
if (!$hasAccess) require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn);
if (!$cid) {
    http_response_code(403);
    die('Company context required.');
}
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: projects.php'); exit; }

$stmt = $conn->prepare("
    SELECT p.*, e.full_name as manager_name, c.client_name
    FROM co_projects p
    LEFT JOIN employees e ON e.id = p.project_manager_id
    LEFT JOIN co_clients c ON c.id = p.client_id
    WHERE p.id = ? AND p.company_id = ?
");
$stmt->execute([$id, $cid]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { header('Location: projects.php'); exit; }

$matReqForProject = inv_material_requests_fetch_for_project($conn, $cid, $id);

$totalCost = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM co_project_costs WHERE project_id = ?");
$totalCost->execute([$id]);
$totalCost = (float)$totalCost->fetchColumn();

$projectContractors = [];
$projectContractorsMains = [];
$projectContractorsSubs = [];
try {
    $projectContractors = $conn->prepare("
        SELECT pc.id, pc.contractor_id, pc.contract_value, pc.retention_pct, pc.start_date, pc.end_date, pc.status,
               pc.parent_project_contractor_id, pc.coordination_fee,
               c.contractor_name,
               main_c.contractor_name AS main_contractor_name
        FROM co_project_contractors pc
        JOIN co_contractors c ON c.id = pc.contractor_id
        LEFT JOIN co_project_contractors parent_pc ON parent_pc.id = pc.parent_project_contractor_id
        LEFT JOIN co_contractors main_c ON main_c.id = parent_pc.contractor_id
        WHERE pc.project_id = ? AND pc.company_id = ?
        ORDER BY COALESCE(pc.parent_project_contractor_id, pc.id), c.contractor_name
    ");
    $projectContractors->execute([$id, $cid]);
    $projectContractors = $projectContractors->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $st = $conn->prepare("
        SELECT pc.id, pc.contractor_id, pc.contract_value, pc.retention_pct, pc.start_date, pc.end_date, pc.status,
               c.contractor_name
        FROM co_project_contractors pc
        JOIN co_contractors c ON c.id = pc.contractor_id
        WHERE pc.project_id = ? AND pc.company_id = ?
        ORDER BY c.contractor_name
    ");
    $st->execute([$id, $cid]);
    $projectContractors = array_map(function ($r) {
        $r['parent_project_contractor_id'] = null;
        $r['main_contractor_name'] = null;
        $r['coordination_fee'] = null;
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}
$contractorContractSum = array_sum(array_column($projectContractors, 'contract_value'));
$contractorProgressByPc = [];
foreach ($projectContractors as $pcRow) {
    $contractorProgressByPc[(int)$pcRow['id']] = co_project_contractor_commercial_progress($conn, $cid, (int)$pcRow['id']);
}
$projectContractorsMains = array_values(array_filter($projectContractors, function ($r) { return empty($r['parent_project_contractor_id']); }));
foreach ($projectContractors as $r) {
    if (!empty($r['parent_project_contractor_id'])) {
        $pid = (int)$r['parent_project_contractor_id'];
        if (!isset($projectContractorsSubs[$pid])) $projectContractorsSubs[$pid] = [];
        $projectContractorsSubs[$pid][] = $r;
    }
}

$projectPhases = $conn->prepare("SELECT id, phase_name, sequence, percent_complete, status FROM co_project_phases WHERE project_id = ? AND company_id = ? ORDER BY sequence ASC, id ASC");
$projectPhases->execute([$id, $cid]);
$projectPhases = $projectPhases->fetchAll(PDO::FETCH_ASSOC);
$overallProgress = 0;
if (!empty($projectPhases)) {
    $overallProgress = round(array_sum(array_column($projectPhases, 'percent_complete')) / count($projectPhases), 1);
}

$projectSupplierInvoices = [];
$supplierInvoiceTotal = 0.0;
try {
    $siStmt = $conn->prepare("
        SELECT si.id, si.invoice_number, si.invoice_date, si.total, si.journal_id, s.supplier_name
        FROM co_supplier_invoices si
        JOIN co_suppliers s ON s.id = si.supplier_id
        WHERE si.project_id = ? AND si.company_id = ?
        ORDER BY si.invoice_date DESC, si.id DESC
        LIMIT 10
    ");
    $siStmt->execute([$id, $cid]);
    $projectSupplierInvoices = $siStmt->fetchAll(PDO::FETCH_ASSOC);
    $siTotalStmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) FROM co_supplier_invoices WHERE project_id = ? AND company_id = ?");
    $siTotalStmt->execute([$id, $cid]);
    $supplierInvoiceTotal = (float)$siTotalStmt->fetchColumn();
} catch (Throwable $e) {
    // co_supplier_invoices may not exist yet
}

$voSummary = $conn->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS approved_total FROM co_variation_orders WHERE project_id = ? AND company_id = ?");
$voSummary->execute([$id, $cid]);
$voSummary = $voSummary->fetch(PDO::FETCH_ASSOC);
$voCount = (int)$voSummary['cnt'];
$voApprovedTotal = (float)$voSummary['approved_total'];

$submittalCount = 0;
$rfiCount = 0;
try {
    $stmtSub = $conn->prepare("SELECT COUNT(*) FROM co_submittals WHERE project_id = ? AND company_id = ?");
    $stmtSub->execute([$id, $cid]);
    $submittalCount = (int)$stmtSub->fetchColumn();
    $stmtRfi = $conn->prepare("SELECT COUNT(*) FROM co_rfis WHERE project_id = ? AND company_id = ?");
    $stmtRfi->execute([$id, $cid]);
    $rfiCount = (int)$stmtRfi->fetchColumn();
} catch (Throwable $e) {
    // Phase 3 tables may not exist yet
}

$pageTitle = $project['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="projects.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0"><?= h($project['project_name']) ?></h1>
            <p class="text-muted mb-0"><?= h($project['project_code']) ?> · <span class="badge bg-<?= co_project_status_badge($project['status']) ?>"><?= h(co_project_status_label($project['status'])) ?></span></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if (has_permission('inventory_requests.create', MODULE_INVENTORY, $conn) && inv_user_can_access_material_request_create($conn, 'construction')): ?>
                <a class="btn btn-outline-primary" href="<?= h(inv_request_material_create_url($conn, [
                    'source_module' => 'construction',
                    'source_table' => 'co_projects',
                    'source_id' => $id,
                    'context_project_id' => $id,
                    'notes_hint' => 'Construction project ' . ($project['project_code'] ?? '') . ' ' . ($project['project_name'] ?? ''),
                ])) ?>">Request inventory materials</a>
            <?php endif; ?>
            <a href="project_edit.php?id=<?= $id ?>" class="btn btn-primary">Edit</a>
        </div>
    </div>
</div>

<div class="card card-round mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0"><i class="bi bi-box-seam me-1"></i> Material requests for this project</h6>
        <a class="btn btn-sm btn-outline-primary" href="my_material_requests.php">My material requests</a>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-2">Stock requests linked to this project (any user). Use this list to avoid duplicate material requests.</p>
        <?php
        $companyId = $cid;
        $rows = $matReqForProject;
        $detailPage = 'material_request_view.php';
        $showRequestedBy = true;
        require __DIR__ . '/../../includes/inventory/partials/material_requests_list_table.php';
        ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card card-round">
            <div class="card-header bg-white"><h6 class="mb-0">Details</h6></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted" style="width:40%">Type</td><td><?= h($project['project_type']) ?></td></tr>
                    <tr><td class="text-muted">Location</td><td><?= h($project['location'] ?? '-') ?></td></tr>
                    <tr><td class="text-muted">Manager</td><td><?= h($project['manager_name'] ?? '-') ?></td></tr>
                    <tr><td class="text-muted">Start</td><td><?= $project['start_date'] ? date('M j, Y', strtotime($project['start_date'])) : '-' ?></td></tr>
                    <tr><td class="text-muted">Expected Completion</td><td><?= $project['expected_completion_date'] ? date('M j, Y', strtotime($project['expected_completion_date'])) : '-' ?></td></tr>
                    <?php if ($project['project_type'] === 'CLIENT' && $project['client_name']): ?>
                    <tr><td class="text-muted">Client</td><td><?= h($project['client_name']) ?></td></tr>
                    <tr><td class="text-muted">Contract Value</td><td><?= co_format_money($project['contract_value'] ?? 0) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card card-round">
            <div class="card-header bg-white"><h6 class="mb-0">Financial</h6></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted" style="width:40%">Approved Budget</td><td><?= co_format_money($project['approved_budget'] ?? 0) ?></td></tr>
                    <tr><td class="text-muted">Contractor Contract</td><td><?= co_format_money($contractorContractSum) ?></td></tr>
                    <tr><td class="text-muted"><strong>Total Cost</strong></td><td><strong><?= co_format_money($totalCost) ?></strong></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($projectPhases)): ?>
<div class="card card-round mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Progress</h6>
        <a href="project_phases.php?project_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">Manage Phases</a>
    </div>
    <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-2">
            <div class="progress flex-grow-1" style="height: 26px;">
                <div class="progress-bar bg-success" role="progressbar" style="width: <?= min(100, (float)$overallProgress) ?>%;" aria-valuenow="<?= (float)$overallProgress ?>" aria-valuemin="0" aria-valuemax="100"><?= (float)$overallProgress ?>%</div>
            </div>
            <span class="fw-bold"><?= (float)$overallProgress ?>% complete</span>
        </div>
        <p class="text-muted small mb-0"><?= count($projectPhases) ?> phase(s). <a href="project_phases.php?project_id=<?= $id ?>">View all</a></p>
    </div>
</div>
<?php else: ?>
<div class="card card-round mt-3">
    <div class="card-body py-2">
        <a href="project_phases.php?project_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-flag"></i> Add project phases / milestones</a>
    </div>
</div>
<?php endif; ?>

<div class="mt-4">
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="project_phases.php?project_id=<?= $id ?>" class="btn btn-outline-secondary">Phases</a>
        <a href="variation_orders.php?project_id=<?= $id ?>" class="btn btn-outline-warning">Variation Orders<?= $voCount ? ' (' . $voCount . ')' : '' ?></a>
        <a href="work_orders.php?project_id=<?= $id ?>" class="btn btn-outline-dark">Work Orders</a>
        <a href="project_submittals.php?project_id=<?= $id ?>" class="btn btn-outline-info">Submittals<?= $submittalCount ? ' (' . $submittalCount . ')' : '' ?></a>
        <a href="project_rfis.php?project_id=<?= $id ?>" class="btn btn-outline-info">RFIs<?= $rfiCount ? ' (' . $rfiCount . ')' : '' ?></a>
        <a href="project_documents.php?project_id=<?= $id ?>" class="btn btn-outline-secondary">Documents</a>
        <a href="supplier_invoices.php?project_id=<?= $id ?>" class="btn btn-outline-danger">Supplier Invoices<?= $projectSupplierInvoices ? ' (' . count($projectSupplierInvoices) . ')' : '' ?></a>
        <a href="project_contractors.php?project_id=<?= $id ?>" class="btn btn-outline-primary">+ Link Contractor</a>
        <a href="project_costs.php?project_id=<?= $id ?>" class="btn btn-outline-success">Project Costs</a>
        <a href="project_labor.php?project_id=<?= $id ?>" class="btn btn-outline-info">Labor Assignments</a>
    </div>
    <?php if ($voCount > 0): ?>
    <div class="card card-round mb-3">
        <div class="card-body py-2 d-flex justify-content-between align-items-center">
            <span><strong>Variation Orders:</strong> <?= $voCount ?> VO(s) · Approved total <?= co_format_money($voApprovedTotal) ?></span>
            <a href="variation_orders.php?project_id=<?= $id ?>" class="btn btn-sm btn-outline-warning">Manage</a>
        </div>
    </div>
    <?php endif; ?>
    <div class="card card-round mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Supplier Invoices</h6>
            <a href="supplier_invoices.php?project_id=<?= $id ?>" class="btn btn-sm btn-outline-danger">View all</a>
        </div>
        <div class="card-body">
            <?php if (empty($projectSupplierInvoices)): ?>
                <p class="text-muted mb-0">No supplier invoices linked to this project yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th>Invoice #</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($projectSupplierInvoices as $si): ?>
                        <tr>
                            <td><?= $si['invoice_date'] ? date('M j, Y', strtotime($si['invoice_date'])) : '-' ?></td>
                            <td><?= h($si['supplier_name']) ?></td>
                            <td><a href="supplier_invoice_view.php?id=<?= (int)$si['id'] ?>"><?= h($si['invoice_number']) ?></a></td>
                            <td class="text-end"><?= co_format_money($si['total']) ?></td>
                            <td><span class="badge bg-<?= $si['journal_id'] ? 'success' : 'secondary' ?>"><?= $si['journal_id'] ? 'Posted' : 'Draft' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Total (all)</strong></td>
                            <td class="text-end"><strong><?= co_format_money($supplierInvoiceTotal) ?></strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
                <?php if (count($projectSupplierInvoices) >= 10): ?>
                <p class="text-muted small mt-2 mb-0">Showing latest 10. <a href="supplier_invoices.php?project_id=<?= $id ?>">View all</a>.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="card card-round">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Project Contractors</h6>
            <a href="project_contractors.php?project_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">+ Add</a>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3"><i class="bi bi-info-circle me-1"></i> <strong>Fee (you → main):</strong> Amount <strong>you as owner</strong> pay to the main contractor for his coordination and mobilization of each subcontractor.</p>
            <?php if (empty($projectContractors)): ?>
                <p class="text-muted mb-0">No contractors linked yet. <a href="project_contractors.php?project_id=<?= $id ?>">Link a contractor</a>.</p>
            <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Contractor</th>
                            <th>Contract Value</th>
                            <th>Invoiced</th>
                            <th>Paid</th>
                            <th>Outstanding AP</th>
                            <th>Remaining</th>
                            <th>Billing %</th>
                            <th>Payment %</th>
                            <th>Fee (you → main)</th>
                            <th>Retention %</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($projectContractorsMains as $pc):
                        $prog = $contractorProgressByPc[(int)$pc['id']] ?? null;
                        $payRedirect = co_project_contractor_payment_redirect($conn, $cid, (int)$pc['id']);
                    ?>
                        <tr class="table-light">
                            <td><a href="contractor_view.php?id=<?= (int)$pc['contractor_id'] ?>"><?= h($pc['contractor_name']) ?></a> <span class="badge bg-primary">Main</span>
                                <?php if ($prog && $prog['linked']): ?>
                                    <div class="small"><a href="supplier_view.php?id=<?= (int)$prog['supplier_id'] ?>">Supplier: <?= h($prog['supplier_name']) ?></a></div>
                                <?php else: ?>
                                    <div class="small text-warning">No supplier link</div>
                                <?php endif; ?>
                            </td>
                            <td><?= co_format_money($pc['contract_value']) ?></td>
                            <td><?= ($prog && $prog['linked']) ? co_format_money($prog['total_invoiced']) : '—' ?></td>
                            <td><?= ($prog && $prog['linked']) ? co_format_money($prog['total_paid']) : '—' ?></td>
                            <td><?= ($prog && $prog['linked']) ? co_format_money($prog['outstanding_ap']) : '—' ?></td>
                            <td><?= $prog ? co_format_money($prog['remaining_contract_value']) : '—' ?></td>
                            <td><?= ($prog && $prog['linked']) ? number_format($prog['billing_progress_pct'], 1) . '%' : '—' ?></td>
                            <td>
                                <?php if ($prog && $prog['linked']): ?>
                                    <?= number_format($prog['payment_progress_pct'], 1) ?>%
                                    <div class="progress mt-1" style="height:4px"><div class="progress-bar bg-success" style="width:<?= min(100, (float)$prog['payment_progress_pct']) ?>%"></div></div>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>—</td>
                            <td><?= h($pc['retention_pct']) ?>%</td>
                            <td class="d-flex flex-wrap gap-1">
                                <a href="project_contractor_edit.php?project_contractor_id=<?= (int)$pc['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit">Edit</a>
                                <?php if (!empty($payRedirect['url'])): ?>
                                    <a href="<?= h($payRedirect['url']) ?>" class="btn btn-sm btn-outline-success">Payment</a>
                                <?php else: ?>
                                    <a href="contractor_view.php?id=<?= (int)$pc['contractor_id'] ?>" class="btn btn-sm btn-outline-warning" title="<?= h($payRedirect['error'] ?? 'Link supplier') ?>">Link Supplier</a>
                                <?php endif; ?>
                                <a href="retention_release_add.php?project_contractor_id=<?= (int)$pc['id'] ?>" class="btn btn-sm btn-outline-success">Release Retention</a>
                                <a href="project_contractors.php?project_id=<?= $id ?>&parent_project_contractor_id=<?= (int)$pc['id'] ?>" class="btn btn-sm btn-outline-info" title="Add subcontractor under this main">+ Sub</a>
                            </td>
                        </tr>
                        <?php
                        $subs = $projectContractorsSubs[(int)$pc['id']] ?? [];
                        foreach ($subs as $sub):
                            $sprog = $contractorProgressByPc[(int)$sub['id']] ?? null;
                            $spay = co_project_contractor_payment_redirect($conn, $cid, (int)$sub['id']);
                        ?>
                        <tr>
                            <td class="ps-4"><a href="contractor_view.php?id=<?= (int)$sub['contractor_id'] ?>"><?= h($sub['contractor_name']) ?></a> <span class="badge bg-secondary">Sub</span> <small class="text-muted">under <?= h($pc['contractor_name']) ?></small></td>
                            <td><?= co_format_money($sub['contract_value']) ?></td>
                            <td><?= ($sprog && $sprog['linked']) ? co_format_money($sprog['total_invoiced']) : '—' ?></td>
                            <td><?= ($sprog && $sprog['linked']) ? co_format_money($sprog['total_paid']) : '—' ?></td>
                            <td><?= ($sprog && $sprog['linked']) ? co_format_money($sprog['outstanding_ap']) : '—' ?></td>
                            <td><?= $sprog ? co_format_money($sprog['remaining_contract_value']) : '—' ?></td>
                            <td><?= ($sprog && $sprog['linked']) ? number_format($sprog['billing_progress_pct'], 1) . '%' : '—' ?></td>
                            <td><?= ($sprog && $sprog['linked']) ? number_format($sprog['payment_progress_pct'], 1) . '%' : '—' ?></td>
                            <td title="Amount you (owner) pay to the main contractor for coordination/mobilization of this sub"><?= isset($sub['coordination_fee']) && $sub['coordination_fee'] !== null && $sub['coordination_fee'] !== '' ? co_format_money($sub['coordination_fee']) : '—' ?></td>
                            <td><?= h($sub['retention_pct']) ?>%</td>
                            <td class="d-flex flex-wrap gap-1">
                                <a href="project_contractor_edit.php?project_contractor_id=<?= (int)$sub['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <?php if (!empty($spay['url'])): ?>
                                    <a href="<?= h($spay['url']) ?>" class="btn btn-sm btn-outline-success">Payment</a>
                                <?php else: ?>
                                    <a href="contractor_view.php?id=<?= (int)$sub['contractor_id'] ?>" class="btn btn-sm btn-outline-warning">Link Supplier</a>
                                <?php endif; ?>
                                <a href="retention_release_add.php?project_contractor_id=<?= (int)$sub['id'] ?>" class="btn btn-sm btn-outline-success">Release Retention</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php
                        $totalCoordToMain = 0;
                        foreach ($subs as $s) { $totalCoordToMain += (float)($s['coordination_fee'] ?? 0); }
                        if ($totalCoordToMain > 0):
                        ?>
                        <tr class="table-warning">
                            <td class="ps-4" colspan="8"><small><strong>Total coordination/mobilization (you pay to <?= h($pc['contractor_name']) ?>):</strong></small></td>
                            <td><strong><?= co_format_money($totalCoordToMain) ?></strong></td>
                            <td colspan="2"></td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
