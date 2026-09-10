<?php
/**
 * Real Estate Module - Leases Management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
re_lease_activate_due_renewals($conn, $currentCompanyId, current_user_id());

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$buildingFilter = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$searchQuery = !empty($_GET['search']) ? trim($_GET['search']) : '';

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Build WHERE clause
$where = ["l.company_id = ?", re_lease_not_deleted_sql($conn, 'l')];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = "l.status = ?";
    $params[] = $statusFilter;
}

if ($buildingFilter) {
    $where[] = "b.id = ?";
    $params[] = $buildingFilter;
}

if ($searchQuery) {
    $where[] = "(l.lease_number LIKE ? OR u.unit_number LIKE ? OR b.name LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR CONCAT(t.first_name, ' ', t.last_name) LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Get statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total_leases,
        COUNT(CASE WHEN l.status = 'active' THEN 1 END) as active_count,
        COUNT(CASE WHEN l.status = 'draft' THEN 1 END) as draft_count,
        COUNT(CASE WHEN l.status = 'expired' THEN 1 END) as expired_count,
        COUNT(CASE WHEN l.status = 'terminated' THEN 1 END) as terminated_count,
        COALESCE(SUM(l.annual_rent), 0) as total_annual_rent
    FROM re_leases l
    WHERE l.company_id = ?
      AND " . re_lease_not_deleted_sql($conn, 'l') . "
");
$stats->execute([$currentCompanyId]);
$statistics = $stats->fetch(PDO::FETCH_ASSOC);

$deletedLeaseCount = 0;
if (re_lease_column_exists($conn, 'deleted_at')) {
    $deletedStmt = $conn->prepare("SELECT COUNT(*) FROM re_leases l WHERE l.company_id = ? AND " . re_lease_deleted_sql($conn, 'l'));
    $deletedStmt->execute([$currentCompanyId]);
    $deletedLeaseCount = (int)$deletedStmt->fetchColumn();
}

// Multi-cheque receipt shares (optional table — created on first use).
$multiChequeLinksReady = false;
try {
    require_once __DIR__ . '/includes/receipt_multi_cheque_helper.php';
    $multiChequeLinksReady = function_exists('re_receipt_cheque_links_ensure_table')
        && re_receipt_cheque_links_ensure_table($conn);
} catch (Throwable $e) {
    $multiChequeLinksReady = false;
}

$chequeCollectedUnionSql = "
    SELECT p.cheque_id, p.company_id, p.amount AS amt
    FROM re_payments p
    WHERE p.company_id = ?
      AND p.cheque_id IS NOT NULL
";
if ($multiChequeLinksReady) {
    $chequeCollectedUnionSql .= "
    UNION ALL
    SELECT l.cheque_id, l.company_id, l.amount_applied AS amt
    FROM re_receipt_cheque_links l
    WHERE l.company_id = ?
    ";
}

// Get leases
$leases = $conn->prepare("
    SELECT l.*, 
           u.unit_number, u.unit_type,
           b.name as building_name,
           t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone, t.email,
           MAX(rw_new.id) as renewal_workflow_id,
           MAX(rw_new.status) as renewal_workflow_status,
           (SELECT rw_src.status FROM re_lease_renewal_workflows rw_src
             WHERE rw_src.new_lease_id = l.id
             ORDER BY rw_src.id DESC LIMIT 1) as source_renewal_status,
           COUNT(DISTINCT li.id) as installment_count,
           COALESCE(MAX(chq.cheque_count), 0) as cheque_count,
           COALESCE(MAX(chq.cleared_count), 0) as cleared_count,
           COALESCE(MAX(chq.partial_count), 0) as cheque_partial_count,
           COALESCE(MAX(chq.open_count), 0) as cheque_open_count,
           COALESCE(MAX(chq.problem_count), 0) as cheque_problem_count,
           COALESCE(MAX(chq.cheque_face_total), 0) as cheque_face_total,
           CASE WHEN COALESCE(l.accounting_mode,'legacy') = 'invoice'
                THEN COALESCE(MAX(chq.cleared_count), 0)
                ELSE COUNT(DISTINCT CASE WHEN COALESCE(pay_alloc.allocated_amount,0) >= li.amount AND li.amount > 0 THEN li.id END)
           END as paid_count,
           CASE WHEN COALESCE(l.accounting_mode,'legacy') = 'invoice'
                THEN COALESCE(MAX(chq.partial_count), 0)
                ELSE COUNT(DISTINCT CASE WHEN COALESCE(pay_alloc.allocated_amount,0) > 0 AND COALESCE(pay_alloc.allocated_amount,0) < li.amount THEN li.id END)
           END as partial_count,
           CASE WHEN COALESCE(l.accounting_mode,'legacy') = 'invoice'
                THEN COALESCE(MAX(chq.open_count), 0)
                ELSE COUNT(DISTINCT CASE WHEN li.id IS NOT NULL AND COALESCE(pay_alloc.allocated_amount,0) <= 0.005 THEN li.id END)
           END as unpaid_schedule_count,
           CASE WHEN COALESCE(l.accounting_mode,'legacy') = 'invoice'
                THEN COALESCE(MAX(chq.collected_amount), 0)
                ELSE COALESCE(SUM(pay_alloc.allocated_amount),0)
           END as collected_amount
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_lease_installments li ON li.lease_id = l.id
    LEFT JOIN (
        SELECT installment_id, SUM(amount_allocated) AS allocated_amount
        FROM re_payment_allocations
        GROUP BY installment_id
    ) pay_alloc ON pay_alloc.installment_id = li.id
    LEFT JOIN (
        SELECT c.lease_id,
               COUNT(*) AS cheque_count,
               SUM(c.cheque_amount) AS cheque_face_total,
               SUM(
                   CASE
                       WHEN c.status IN ('cleared', 'paid', 'deposited')
                            OR COALESCE(coll.collected, 0) >= (c.cheque_amount - 0.005)
                       THEN 1 ELSE 0
                   END
               ) AS cleared_count,
               SUM(
                   CASE
                       WHEN c.status NOT IN ('cleared', 'paid', 'deposited', 'bounced', 'returned', 'cancelled')
                            AND COALESCE(coll.collected, 0) > 0.005
                            AND COALESCE(coll.collected, 0) < (c.cheque_amount - 0.005)
                       THEN 1 ELSE 0
                   END
               ) AS partial_count,
               SUM(
                   CASE
                       WHEN c.status IN ('bounced', 'returned') THEN 1 ELSE 0
                   END
               ) AS problem_count,
               SUM(
                   CASE
                       WHEN c.status NOT IN ('cleared', 'paid', 'deposited', 'bounced', 'returned', 'cancelled')
                            AND COALESCE(coll.collected, 0) <= 0.005
                       THEN 1 ELSE 0
                   END
               ) AS open_count,
               SUM(
                   CASE
                       WHEN c.status IN ('cleared', 'paid', 'deposited')
                            OR COALESCE(coll.collected, 0) >= (c.cheque_amount - 0.005)
                       THEN c.cheque_amount
                       ELSE LEAST(COALESCE(coll.collected, 0), c.cheque_amount)
                   END
               ) AS collected_amount
        FROM re_post_dated_cheques c
        LEFT JOIN (
            SELECT cheque_id, company_id, SUM(amt) AS collected
            FROM (
                {$chequeCollectedUnionSql}
            ) receipt_bits
            GROUP BY cheque_id, company_id
        ) coll ON coll.cheque_id = c.id AND coll.company_id = c.company_id
        WHERE c.company_id = ?
        GROUP BY c.lease_id
    ) chq ON chq.lease_id = l.id
    LEFT JOIN re_lease_renewal_workflows rw_new ON rw_new.new_lease_id = l.id AND rw_new.status = 'converted'
    WHERE " . implode(' AND ', $where) . "
    GROUP BY l.id
    ORDER BY l.start_date DESC
");
$chequeAggParams = [$currentCompanyId];
if ($multiChequeLinksReady) {
    $chequeAggParams[] = $currentCompanyId;
}
$chequeAggParams[] = $currentCompanyId;
$leases->execute(array_merge($chequeAggParams, $params));
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function lease_tenant_display_name(array $lease): string {
    $companyName = trim((string)($lease['company_name'] ?? ''));
    $fullName = trim((string)($lease['first_name'] ?? '') . ' ' . (string)($lease['last_name'] ?? ''));

    if ($companyName !== '') {
        return $companyName;
    }
    if ($fullName !== '') {
        return $fullName;
    }

    return !empty($lease['tenant_id']) ? 'Tenant #' . (int)$lease['tenant_id'] : '-';
}

/**
 * Plain-text payment summary for Excel/CSV export (matches Payments column meaning).
 */
function lease_payments_export_summary(array $lease): string {
    $isInvoiceMode = (string)($lease['accounting_mode'] ?? 'legacy') === 'invoice';
    $chequeCount = (int)($lease['cheque_count'] ?? 0);
    $clearedCount = (int)($lease['cleared_count'] ?? $lease['paid_count'] ?? 0);
    $partialCount = (int)($lease['cheque_partial_count'] ?? $lease['partial_count'] ?? 0);
    $openCount = (int)($lease['cheque_open_count'] ?? $lease['unpaid_schedule_count'] ?? 0);
    $problemCount = (int)($lease['cheque_problem_count'] ?? 0);
    $collectedAmt = (float)($lease['collected_amount'] ?? 0);
    $faceTotal = (float)($lease['cheque_face_total'] ?? 0);
    $installmentCount = (int)($lease['installment_count'] ?? 0);

    if ($chequeCount <= 0 && $installmentCount <= 0 && $collectedAmt <= 0.005) {
        return '-';
    }

    $parts = [];
    if ($isInvoiceMode && $chequeCount > 0) {
        $parts[] = $clearedCount . ' / ' . $chequeCount . ' cleared';
        if ($partialCount > 0) {
            $parts[] = $partialCount . ' partial';
        }
        if ($openCount > 0) {
            $parts[] = $openCount . ' open';
        }
        if ($problemCount > 0) {
            $parts[] = $problemCount . ' bounced/returned';
        }
        if ($collectedAmt > 0.005 || $faceTotal > 0.005) {
            $money = 'AED ' . number_format($collectedAmt, 2);
            if ($faceTotal > 0.005) {
                $money .= ' / ' . number_format($faceTotal, 2);
            }
            $parts[] = $money;
        }
    } else {
        $parts[] = (int)($lease['paid_count'] ?? 0) . ' / ' . $installmentCount . ' collected';
        if ((int)($lease['partial_count'] ?? 0) > 0) {
            $parts[] = (int)$lease['partial_count'] . ' partial';
        }
        if ((int)($lease['unpaid_schedule_count'] ?? 0) > 0) {
            $parts[] = (int)$lease['unpaid_schedule_count'] . ' scheduled';
        }
        if ($collectedAmt > 0.005) {
            $parts[] = 'AED ' . number_format($collectedAmt, 2);
        }
    }

    return $parts !== [] ? implode('; ', $parts) : '-';
}

$leasesExportRows = [];
foreach ($leases as $exportLease) {
    $isPendingRenewalStart = ($exportLease['status'] ?? '') === 'draft' && !empty($exportLease['renewal_workflow_id']);
    $isRejectedRenewalDraft = ($exportLease['status'] ?? '') === 'draft' && ($exportLease['source_renewal_status'] ?? '') === 'rejected';
    $statusLabel = $isPendingRenewalStart ? 'Pending Start' : ($isRejectedRenewalDraft ? 'Renewal Rejected' : ucfirst((string)($exportLease['status'] ?? '')));
    $startTs = strtotime((string)($exportLease['start_date'] ?? ''));
    $endTs = strtotime((string)($exportLease['end_date'] ?? ''));
    $leasesExportRows[] = [
        'lease_number' => (string)($exportLease['lease_number'] ?: ('L-' . (int)$exportLease['id'])),
        'building_name' => (string)($exportLease['building_name'] ?? ''),
        'unit_number' => (string)($exportLease['unit_number'] ?? ''),
        'unit_type' => (string)($exportLease['unit_type'] ?? ''),
        'tenant' => lease_tenant_display_name($exportLease),
        'start_date' => $startTs ? date('Y-m-d', $startTs) : '',
        'end_date' => $endTs ? date('Y-m-d', $endTs) : '',
        'annual_rent' => number_format((float)($exportLease['annual_rent'] ?? (($exportLease['monthly_rent'] ?? 0) * 12)), 2, '.', ''),
        'status' => $statusLabel,
        'payments' => lease_payments_export_summary($exportLease),
    ];
}

// Ensure CSRF token exists
csrf_token();

// Set page title and include layout
$pageTitle = 'Leases';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label">Leases</div>
            <div class="d-flex gap-2">
                <a href="deleted_leases.php" class="btn btn-outline-secondary">
                    <i class="bi bi-archive"></i> Deleted Leases<?= $deletedLeaseCount ? ' (' . number_format($deletedLeaseCount) . ')' : '' ?>
                </a>
                <a href="lease_add.php" class="btn btn-primary" style="background-color: var(--primary); border-color: var(--primary);">
                    <i class="bi bi-plus-circle"></i> New Lease
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-file-earmark-text text-primary fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Total Leases</div>
                                <div class="h4 mb-0"><?= number_format($statistics['total_leases']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-check-circle text-success fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Active</div>
                                <div class="h4 mb-0"><?= number_format($statistics['active_count']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-exclamation-triangle text-warning fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Expired</div>
                                <div class="h4 mb-0"><?= number_format($statistics['expired_count']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-currency-exchange text-info fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Total Annual Rent</div>
                                <div class="h5 mb-0"><?= number_format($statistics['total_annual_rent'], 0) ?> AED</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Advanced Filters -->
        <div class="card card-round mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Search</h6>
            </div>
            <div class="card-body">
                <form method="GET" id="filterForm" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><i class="bi bi-tag"></i> Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="has_legal_case" <?= $statusFilter === 'has_legal_case' ? 'selected' : '' ?>>Has Legal Case</option>
                            <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                            <option value="terminated" <?= $statusFilter === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                            <option value="renewed" <?= $statusFilter === 'renewed' ? 'selected' : '' ?>>Renewed</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><i class="bi bi-building"></i> Building</label>
                        <select name="building_id" class="form-select">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $buildingFilter == $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="bi bi-search"></i> Search</label>
                        <input type="text" name="search" id="searchInput" class="form-control" placeholder="Lease #, Unit, Building, Tenant..." value="<?= h($searchQuery) ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel-fill"></i> Apply
                        </button>
                    </div>
                    <?php if ($statusFilter !== 'all' || $buildingFilter || $searchQuery): ?>
                    <div class="col-12">
                        <a href="leases.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Leases Table -->
        <div class="card card-round">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-list-ul"></i> Leases (<span id="leasesCount"><?= count($leases) ?></span>)</h6>
                <?php if (count($leases) > 0): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportTable()">
                    <i class="bi bi-download"></i> Export
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="leasesTable">
                        <thead class="table-light">
                            <tr>
                                <th><i class="bi bi-hash"></i> Lease #</th>
                                <th><i class="bi bi-building"></i> Unit</th>
                                <th><i class="bi bi-person"></i> Tenant</th>
                                <th><i class="bi bi-calendar-event"></i> Start Date</th>
                                <th><i class="bi bi-calendar-x"></i> End Date</th>
                                <th><i class="bi bi-currency-exchange"></i> Annual Rent</th>
                                <th><i class="bi bi-tag"></i> Status</th>
                                <th><i class="bi bi-cash-coin"></i> Payments</th>
                                <th><i class="bi bi-gear"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody id="leasesTableBody">
                            <?php if (empty($leases)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                        No leases found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                $currentBuilding = '';
                                foreach ($leases as $lease): 
                                    // Group by building if no building filter is applied
                                    if (!$buildingFilter && $currentBuilding !== $lease['building_name']):
                                        $currentBuilding = $lease['building_name'];
                                ?>
                                    <tr class="table-secondary">
                                        <td colspan="9" class="fw-bold">
                                            <i class="bi bi-building"></i> <?= h($currentBuilding) ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php $tenantName = lease_tenant_display_name($lease); ?>
                                <tr>
                                    <td><strong class="text-primary"><?= h($lease['lease_number'] ?: 'L-' . $lease['id']) ?></strong></td>
                                    <td>
                                        <i class="bi bi-building"></i> <?= h($lease['building_name']) ?><br>
                                        <small class="text-muted"><?= h($lease['unit_number']) ?> (<?= h($lease['unit_type']) ?>)</small>
                                    </td>
                                    <td>
                                        <i class="bi bi-person-circle"></i> <?= h($tenantName) ?>
                                    </td>
                                    <td><?= date('Y-m-d', strtotime($lease['start_date'])) ?></td>
                                    <td><?= date('Y-m-d', strtotime($lease['end_date'])) ?></td>
                                    <td><strong><?= number_format($lease['annual_rent'] ?? ($lease['monthly_rent'] * 12), 2) ?> AED</strong></td>
                                    <td>
                                        <?php
                                        $statusClass = [
                                            'draft' => 'secondary',
                                            'active' => 'success',
                                            'expired' => 'warning',
                                            'terminated' => 'danger',
                                            'renewed' => 'info',
                                            'has_legal_case' => 'dark',
                                        ];
                                        $isPendingRenewalStart = ($lease['status'] ?? '') === 'draft' && !empty($lease['renewal_workflow_id']);
                                        $isRejectedRenewalDraft = ($lease['status'] ?? '') === 'draft' && ($lease['source_renewal_status'] ?? '') === 'rejected';
                                        $class = $isPendingRenewalStart ? 'info' : ($isRejectedRenewalDraft ? 'danger' : ($statusClass[$lease['status']] ?? 'secondary'));
                                        $statusLabel = $isPendingRenewalStart ? 'Pending Start' : ($isRejectedRenewalDraft ? 'Renewal Rejected' : re_lease_status_label((string)$lease['status']));
                                        ?>
                                        <span class="badge bg-<?= $class ?>"><?= h($statusLabel) ?></span>
                                        <?php if ($isPendingRenewalStart): ?>
                                            <br><small class="text-muted">Renewal activates on <?= h($lease['start_date']) ?></small>
                                        <?php elseif ($isRejectedRenewalDraft): ?>
                                            <br><small class="text-muted">Draft not used</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $isInvoiceMode = (string)($lease['accounting_mode'] ?? 'legacy') === 'invoice';
                                        $chequeCount = (int)($lease['cheque_count'] ?? 0);
                                        $clearedCount = (int)($lease['cleared_count'] ?? $lease['paid_count'] ?? 0);
                                        $partialCount = (int)($lease['cheque_partial_count'] ?? $lease['partial_count'] ?? 0);
                                        $openCount = (int)($lease['cheque_open_count'] ?? $lease['unpaid_schedule_count'] ?? 0);
                                        $problemCount = (int)($lease['cheque_problem_count'] ?? 0);
                                        $collectedAmt = (float)($lease['collected_amount'] ?? 0);
                                        $faceTotal = (float)($lease['cheque_face_total'] ?? 0);
                                        $installmentCount = (int)($lease['installment_count'] ?? 0);
                                        $hasPaymentSummary = $chequeCount > 0 || $installmentCount > 0 || $collectedAmt > 0.005;
                                        ?>
                                        <?php if ($hasPaymentSummary): ?>
                                            <div class="small">
                                                <?php if ($isInvoiceMode && $chequeCount > 0): ?>
                                                    <span class="badge bg-success"><?= $clearedCount ?></span>
                                                    /
                                                    <span class="badge bg-secondary"><?= $chequeCount ?></span>
                                                    cleared
                                                    <?php if ($partialCount > 0): ?>
                                                        <br><span class="badge bg-info text-dark"><?= $partialCount ?> partial</span>
                                                    <?php endif; ?>
                                                    <?php if ($openCount > 0): ?>
                                                        <br><span class="badge bg-warning text-dark"><?= $openCount ?> open</span>
                                                    <?php endif; ?>
                                                    <?php if ($problemCount > 0): ?>
                                                        <br><span class="badge bg-danger"><?= $problemCount ?> bounced/returned</span>
                                                    <?php endif; ?>
                                                    <?php if ($collectedAmt > 0.005 || $faceTotal > 0.005): ?>
                                                        <br><small class="text-muted">
                                                            AED <?= number_format($collectedAmt, 2) ?>
                                                            <?php if ($faceTotal > 0.005): ?>
                                                                / <?= number_format($faceTotal, 2) ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><?= (int)$lease['paid_count'] ?></span>
                                                    /
                                                    <span class="badge bg-secondary"><?= $installmentCount ?></span>
                                                    collected
                                                    <?php if ((int)$lease['partial_count'] > 0): ?>
                                                        <br><span class="badge bg-info text-dark"><?= (int)$lease['partial_count'] ?> partial</span>
                                                    <?php endif; ?>
                                                    <?php if ((int)$lease['unpaid_schedule_count'] > 0): ?>
                                                        <br><span class="badge bg-warning text-dark"><?= (int)$lease['unpaid_schedule_count'] ?> scheduled</span>
                                                    <?php endif; ?>
                                                    <?php if ($collectedAmt > 0.005): ?>
                                                        <br><small class="text-muted">AED <?= number_format($collectedAmt, 2) ?></small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="lease_view.php?id=<?= $lease['id'] ?>" class="btn btn-outline-primary" title="View Lease">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($lease['status'] === 'draft' && empty($lease['renewal_workflow_id']) && !$isRejectedRenewalDraft): ?>
                                                <button type="button" 
                                                        class="btn btn-outline-danger"
                                                        onclick="deleteLease(<?= (int)$lease['id'] ?>, '<?= h($lease['lease_number'] ?: 'L-' . $lease['id']) ?>', this)"
                                                        title="Delete Draft Lease">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<script>
// Live search with debouncing (AJAX - no page reload). Run after DOM ready so #leasesTableBody exists.
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const filterForm = document.getElementById('filterForm');
    const leasesTableBody = document.getElementById('leasesTableBody');
    const leasesCount = document.getElementById('leasesCount');
    let searchTimeout;
    
    function updateTable() {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams();
        for (const [key, value] of formData.entries()) {
            if (value) params.append(key, value);
        }
        
        if (leasesTableBody) {
            leasesTableBody.innerHTML = '<tr><td colspan="9" class="text-center py-3"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...</td></tr>';
        }
        
        fetch('ajax_search_leases.php?' + params.toString())
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (leasesTableBody) leasesTableBody.innerHTML = data.html;
                    if (leasesCount) leasesCount.textContent = data.count;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (leasesTableBody) {
                    leasesTableBody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-3">Error loading data. Please refresh the page.</td></tr>';
                }
            });
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(updateTable, 500);
        });
    }
});

function exportTable() {
    const rows = <?= json_encode($leasesExportRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    if (!Array.isArray(rows) || rows.length === 0) {
        alert('No leases to export.');
        return;
    }

    const headers = [
        'Lease #',
        'Building Name',
        'Unit Number',
        'Unit Type',
        'Tenant',
        'Start Date',
        'End Date',
        'Annual Rent (AED)',
        'Status',
        'Payments'
    ];

    const escapeCsv = (value) => {
        const text = String(value ?? '').replace(/"/g, '""');
        return '"' + text + '"';
    };

    const lines = [headers.map(escapeCsv).join(',')];
    rows.forEach((row) => {
        lines.push([
            row.lease_number,
            row.building_name,
            row.unit_number,
            row.unit_type,
            row.tenant,
            row.start_date,
            row.end_date,
            row.annual_rent,
            row.status,
            row.payments
        ].map(escapeCsv).join(','));
    });

    // BOM helps Excel open UTF-8 CSV with Arabic / special characters correctly.
    const csvFile = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const downloadLink = document.createElement('a');
    downloadLink.download = 'leases_<?= date('Y-m-d') ?>.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php
$csrfTokenForJs = csrf_token();
$pageScripts = <<<SCRIPTS
<script>
function deleteLease(leaseId, leaseNumber, button) {
    const confirmation = prompt('Archive draft lease "' + leaseNumber + '"?\\n\\nThis will hide it from normal lists but admins can restore it later.\\nType DELETE to continue.');
    if (confirmation !== 'DELETE') {
        return;
    }
    const reason = prompt('Optional: enter a reason for archiving this draft lease.', '') || '';

    const btn = button || event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';

    const formData = new FormData();
    formData.append('lease_id', leaseId);
    formData.append('confirm_text', confirmation);
    formData.append('delete_reason', reason);
    formData.append('_csrf', '$csrfTokenForJs');

    fetch('ajax_delete_lease.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const row = btn.closest('tr');
            row.style.transition = 'opacity 0.3s';
            row.style.opacity = '0';
            setTimeout(() => {
                row.remove();
                const alert = document.createElement('div');
                alert.className = 'alert alert-success alert-dismissible fade show';
                alert.innerHTML = '<strong>Success!</strong> ' + data.message +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                document.querySelector('.page-header-label').parentElement.insertAdjacentElement('afterend', alert);
                setTimeout(() => alert.remove(), 5000);
            }, 300);
        } else {
            alert('Error: ' + (data.error || 'Failed to delete lease'));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
}
</script>
SCRIPTS;

require_once __DIR__ . '/includes/re_layout_footer.php';
?>

