<?php
/**
 * Real Estate Module - Move-Out Management
 * List and manage tenant move-out processes
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

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
$where = ["mo.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = "mo.status = ?";
    $params[] = $statusFilter;
}

if ($dateFrom) {
    $where[] = "mo.actual_move_out_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = "mo.actual_move_out_date <= ?";
    $params[] = $dateTo;
}

// Get move-outs
$moveOuts = $conn->prepare("
    SELECT 
        mo.*,
        mon.notice_date,
        mon.intended_move_out_date,
        l.lease_number,
        l.end_date as lease_end,
        u.unit_number,
        u.unit_type,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email
    FROM re_move_outs mo
    JOIN re_leases l ON l.id = mo.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_move_out_notices mon ON mon.id = mo.move_out_notice_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY mo.actual_move_out_date DESC, mo.created_at DESC
");
$moveOuts->execute($params);
$moveOuts = $moveOuts->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'inspection_scheduled' THEN 1 END) as inspection_scheduled,
        COUNT(CASE WHEN status = 'inspection_completed' THEN 1 END) as inspection_completed,
        COUNT(CASE WHEN status = 'deposit_processing' THEN 1 END) as deposit_processing,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed
    FROM re_move_outs
    WHERE company_id = ?
");
$stats->execute([$currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Move-Out Management';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles = ($pageStyles ?? '') . re_move_ui_css();
require_once __DIR__ . '/includes/re_layout_header.php';

$statTiles = [
    ['all', 'Total', (int)$stats['total'], 'primary'],
    ['pending', 'Pending', (int)$stats['pending'], 'warning'],
    ['inspection_scheduled', 'Inspection Scheduled', (int)$stats['inspection_scheduled'], 'info'],
    ['inspection_completed', 'Inspection Done', (int)$stats['inspection_completed'], 'primary'],
    ['deposit_processing', 'Deposit Processing', (int)$stats['deposit_processing'], 'secondary'],
    ['completed', 'Completed', (int)$stats['completed'], 'success'],
];
$filterQs = static fn(string $status) => '?' . http_build_query(array_filter([
    'status' => $status, 'date_from' => $dateFrom, 'date_to' => $dateTo,
], fn($v) => $v !== '' && $v !== null));
?>
<div class="rm-page">
        <div class="rm-hero">
            <div>
                <h1><i class="bi bi-box-arrow-right"></i>Move-Out Management</h1>
                <div class="rm-sub">Notices, final inspection, damages and security deposit settlement.</div>
            </div>
            <div class="rm-actions">
                <a href="move_out_inspection_template.php" class="btn btn-outline-secondary">
                    <i class="bi bi-ui-checks me-1"></i> Inspection Template
                </a>
                <a href="move_out_charge_types.php" class="btn btn-outline-secondary">
                    <i class="bi bi-brush me-1"></i> Standard Charges
                </a>
                <a href="move_out_add.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i> New Move-Out Notice
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
                        <option value="inspection_scheduled" <?= $statusFilter === 'inspection_scheduled' ? 'selected' : '' ?>>Inspection Scheduled</option>
                        <option value="inspection_completed" <?= $statusFilter === 'inspection_completed' ? 'selected' : '' ?>>Inspection Completed</option>
                        <option value="deposit_processing" <?= $statusFilter === 'deposit_processing' ? 'selected' : '' ?>>Deposit Processing</option>
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
                    <a href="move_out.php" class="btn btn-outline-secondary" title="Clear filters"><i class="bi bi-x-lg"></i></a>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-list-ul me-1"></i> Move-Out Records <span class="rm-pill rm-pill-secondary ms-1"><?= count($moveOuts) ?></span></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover rm-table-stack">
                        <thead>
                            <tr>
                                <th>Move-Out Date</th>
                                <th>Lease / Unit</th>
                                <th>Tenant</th>
                                <th>Status</th>
                                <th>Deposit</th>
                                <th class="text-end">Refund</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($moveOuts)): ?>
                                <tr>
                                    <td colspan="7" class="rm-empty"><i class="bi bi-inbox"></i>No move-out records found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($moveOuts as $mo): ?>
                                    <tr>
                                        <td data-label="Move-Out Date"><span class="rm-cell-main"><?= date('M d, Y', strtotime($mo['actual_move_out_date'])) ?></span></td>
                                        <td data-label="Lease / Unit">
                                            <div>
                                                <div class="rm-cell-main"><?= h($mo['lease_number']) ?></div>
                                                <div class="rm-cell-sub"><?= h($mo['building_name']) ?> · <?= h($mo['unit_number']) ?></div>
                                            </div>
                                        </td>
                                        <td data-label="Tenant">
                                            <div>
                                                <div class="rm-cell-main"><?= h($mo['first_name'] . ' ' . $mo['last_name']) ?></div>
                                                <div class="rm-cell-sub"><?= h($mo['phone']) ?></div>
                                            </div>
                                        </td>
                                        <td data-label="Status"><?= re_move_ui_pill(ucfirst(str_replace('_', ' ', $mo['status'])), re_move_ui_tone($mo['status'])) ?></td>
                                        <td data-label="Deposit">
                                            <?php if (!empty($mo['settlement_completed_at'])): ?>
                                                <?= re_move_ui_pill('Settled', 'success') ?>
                                            <?php elseif ($mo['deposit_status']): ?>
                                                <?= re_move_ui_pill(ucfirst($mo['deposit_status']), re_move_ui_tone($mo['deposit_status'])) ?>
                                            <?php else: ?>
                                                <span class="rm-cell-sub">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Refund" class="text-end">
                                            <?php if ($mo['deposit_refund_amount']): ?>
                                                <span class="rm-cell-main"><?= number_format($mo['deposit_refund_amount'], 2) ?> AED</span>
                                            <?php else: ?>
                                                <span class="rm-cell-sub">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end rm-td-actions">
                                            <a href="move_out_view.php?id=<?= $mo['id'] ?>" class="btn btn-sm btn-outline-primary">
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
