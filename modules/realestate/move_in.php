<?php
/**
 * Real Estate Module - Move-In Management
 * List and manage tenant move-in processes
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/move_in_stages_helper.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_OPERATIONS, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build query
$where = ["mi.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = "mi.status = ?";
    $params[] = $statusFilter;
}

if ($dateFrom) {
    $where[] = "mi.move_in_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = "mi.move_in_date <= ?";
    $params[] = $dateTo;
}

// Get move-ins
$moveIns = $conn->prepare("
    SELECT 
        mi.*,
        l.accounting_mode,
        l.lease_number,
        l.start_date as lease_start,
        l.monthly_rent,
        u.unit_number,
        u.unit_type,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        EXISTS(SELECT 1 FROM re_documents d LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
               WHERE d.related_type = 'lease' AND d.related_id = mi.lease_id AND d.company_id = mi.company_id AND d.status = 'active'
                 AND (dt.document_type_code = 'TENANCY_CONTRACT' OR d.document_type = 'lease_agreement')) AS has_contract_doc,
        (SELECT COUNT(*) FROM re_move_in_checklist_items WHERE move_in_id = mi.id AND is_completed = 1) as completed_items,
        (SELECT COUNT(*) FROM re_move_in_checklist_items WHERE move_in_id = mi.id) as total_items
    FROM re_move_ins mi
    JOIN re_leases l ON l.id = mi.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY mi.move_in_date DESC, mi.created_at DESC
");
$moveIns->execute($params);
$moveIns = $moveIns->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed
    FROM re_move_ins
    WHERE company_id = ?
");
$stats->execute([$currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Move-In Management';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles = ($pageStyles ?? '') . re_move_ui_css();
require_once __DIR__ . '/includes/re_layout_header.php';

$statTiles = [
    ['all', 'Total', (int)$stats['total'], 'primary'],
    ['pending', 'Pending', (int)$stats['pending'], 'warning'],
    ['in_progress', 'In Progress', (int)$stats['in_progress'], 'info'],
    ['completed', 'Completed', (int)$stats['completed'], 'success'],
];
$filterQs = static fn(string $status) => '?' . http_build_query(array_filter([
    'status' => $status, 'date_from' => $dateFrom, 'date_to' => $dateTo,
], fn($v) => $v !== '' && $v !== null));
?>
<div class="rm-page">
        <div class="rm-hero">
            <div>
                <h1><i class="bi bi-box-arrow-in-right"></i>Move-In Management</h1>
                <div class="rm-sub">Track tenant handovers: checklist, meter readings and keys.</div>
            </div>
            <div class="rm-actions">
                <a href="form_letterhead.php" class="btn btn-outline-secondary">
                    <i class="bi bi-layout-text-window me-1"></i> Form Header &amp; Footer
                </a>
                <a href="move_in_inspection_template.php" class="btn btn-outline-secondary">
                    <i class="bi bi-ui-checks me-1"></i> Inspection Template
                </a>
                <a href="move_in_mark_bulk.php" class="btn btn-outline-primary">
                    <i class="bi bi-check2-square me-1"></i> Mark as Moved In
                </a>
                <a href="move_in_add.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i> New Move-In
                </a>
            </div>
        </div>

        <div class="rm-stats">
            <?php foreach ($statTiles as [$key, $label, $count, $tone]): ?>
                <a href="<?= h($filterQs($key)) ?>" class="rm-stat rm-tone-<?= $tone ?> <?= $statusFilter === $key ? 'is-active' : '' ?>">
                    <div class="lbl"><?= h($label) ?></div>
                    <div class="val"><?= $count ?></div>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="GET" class="rm-filter">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-6 col-sm-6 col-lg-3">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
                </div>
                <div class="col-6 col-sm-6 col-lg-3">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
                </div>
                <div class="col-12 col-sm-6 col-lg-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1"></i> Filter</button>
                    <a href="move_in.php" class="btn btn-outline-secondary" title="Clear filters"><i class="bi bi-x-lg"></i></a>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-list-ul me-1"></i> Move-In Records <span class="rm-pill rm-pill-secondary ms-1"><?= count($moveIns) ?></span></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover rm-table-stack">
                        <thead>
                            <tr>
                                <th>Move-In Date</th>
                                <th>Lease / Unit</th>
                                <th>Tenant</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($moveIns)): ?>
                                <tr>
                                    <td colspan="6" class="rm-empty"><i class="bi bi-inbox"></i>No move-in records found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($moveIns as $mi):
                                    $total = (int)$mi['total_items'];
                                    $completed = (int)$mi['completed_items'];
                                    $percent = $total > 0 ? round(($completed / $total) * 100) : 0;
                                ?>
                                    <tr>
                                        <td data-label="Move-In Date"><span class="rm-cell-main"><?= date('M d, Y', strtotime($mi['move_in_date'])) ?></span></td>
                                        <td data-label="Lease / Unit">
                                            <div>
                                                <div class="rm-cell-main"><?= h($mi['lease_number']) ?></div>
                                                <div class="rm-cell-sub"><?= h($mi['building_name']) ?> · <?= h($mi['unit_number']) ?></div>
                                            </div>
                                        </td>
                                        <td data-label="Tenant">
                                            <div>
                                                <div class="rm-cell-main"><?= h($mi['first_name'] . ' ' . $mi['last_name']) ?></div>
                                                <div class="rm-cell-sub"><?= h($mi['phone']) ?></div>
                                            </div>
                                        </td>
                                        <td data-label="Status">
                                            <div class="d-flex flex-wrap gap-1 justify-content-end justify-content-md-start">
                                                <?= re_move_ui_pill(ucfirst(str_replace('_', ' ', $mi['status'])), re_move_ui_tone($mi['status'])) ?>
                                                <?php if (!empty($mi['is_historical'])): ?>
                                                    <span class="rm-pill rm-pill-secondary" title="Recorded with Mark as Moved In — no checklist">Historical</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Progress">
                                            <?php if ((int)($mi['workflow_version'] ?? 1) >= 2):
                                                // Same reading as the move-in page (contract document, payment from the lease, keys)
                                                $nextLabel = '';
                                                if (in_array($mi['status'], ['completed', 'cancelled'], true) || !rms_ready($conn)) {
                                                    $stageFlags = [!empty($mi['contract_verified']) || !empty($mi['has_contract_doc']), !empty($mi['payment_confirmed']) || !empty($mi['payment_override']), !empty($mi['inspection_completed']), !empty($mi['keys_handed_over'])];
                                                } else {
                                                    $ms = rms_stage_state($conn, (int)$mi['company_id'], $mi, rms_items($conn, (int)$mi['id']));
                                                    $stageFlags = [$ms['done'][1], $ms['done'][2], $ms['done'][3], $ms['keys_ready']];
                                                    $nextLabel = [1 => 'Contract Signed', 2 => 'Payment Confirmed', 3 => 'Inspection', 4 => 'Key Handover', 5 => 'Inspection Report'][$ms['current']]
                                                        ?? (!$ms['done'][4] ? 'Keys & access handover' : 'Complete Move-In');
                                                }
                                                $stagesDone = $mi['status'] === 'completed' ? 4 : count(array_filter($stageFlags));
                                                $stageNames = ['Contract Signed', 'Payment Confirmed', 'Inspection', 'Key Handover'];
                                                $nextStage = array_search(false, $stageFlags, true);
                                                if ($nextLabel === '' && $nextStage !== false) { $nextLabel = $stageNames[$nextStage]; }
                                                $sp = (int)round($stagesDone / 4 * 100); ?>
                                                <div style="min-width:110px">
                                                    <div class="progress">
                                                        <div class="progress-bar <?= $sp == 100 ? 'bg-success' : ($sp >= 50 ? 'bg-info' : 'bg-warning') ?>" role="progressbar" style="width: <?= $sp ?>%" aria-valuenow="<?= $sp ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                    </div>
                                                    <div class="rm-progress-label"><?= $stagesDone ?>/4 stages<?= $nextLabel !== '' && !in_array($mi['status'], ['completed', 'cancelled'], true) ? ' · next: ' . h($nextLabel) : '' ?></div>
                                                </div>
                                            <?php elseif (!empty($mi['is_historical']) && $total === 0): ?>
                                                <span class="rm-cell-sub">Not required</span>
                                            <?php else: ?>
                                                <div style="min-width:110px">
                                                    <div class="progress">
                                                        <div class="progress-bar <?= $percent == 100 ? 'bg-success' : ($percent >= 50 ? 'bg-info' : 'bg-warning') ?>"
                                                             role="progressbar" style="width: <?= $percent ?>%" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                    </div>
                                                    <div class="rm-progress-label"><?= $completed ?>/<?= $total ?> items · <?= $percent ?>%</div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end rm-td-actions">
                                            <a href="move_in_view.php?id=<?= $mi['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye me-1"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
