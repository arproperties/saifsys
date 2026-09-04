<?php
/**
 * Real Estate Module - Post-Dated Cheques Management
 * Track and manage post-dated cheques
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/payment_allocation_helper.php';

$isLegalLayout = defined('LEGAL_PDC_LAYOUT') && LEGAL_PDC_LAYOUT;
if ($isLegalLayout) {
    require_once __DIR__ . '/../legal/includes/legal_helper.php';
} else {
    require_once __DIR__ . '/../legal/includes/legal_helper.php';
}

require_login();
if ($isLegalLayout) {
    if (!legal_can_manage($conn)) {
        legal_require_access($conn);
    }
} else {
    require_module_access($conn, MODULE_REALESTATE);
}

$reBase = $isLegalLayout ? '../realestate/' : '';
$legalBase = $isLegalLayout ? '../legal/' : '../legal/';
$pageSelf = $isLegalLayout ? 'legal_billing_cheques.php' : 'billing_cheques.php';
$docsPage = $isLegalLayout ? '../legal/legal_documents.php' : 'documents.php';

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$canSyncPdc = has_role('Owner', $conn);
$hasLegalAccess = legal_can_manage($conn);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if (!$canSyncPdc) {
        $_SESSION['error'] = 'You do not have permission to sync PDC statuses.';
    } elseif (in_array($action, ['dry_run_stale_pdc', 'apply_stale_pdc'], true)) {
        try {
            if ($action === 'apply_stale_pdc') {
                $conn->beginTransaction();
                $result = sync_stale_paid_pdc_statuses($conn, $currentCompanyId, true);
                $conn->commit();
                $_SESSION['success'] = "PDC sync completed. {$result['pdc']} PDC cheque(s) and {$result['lease_cheques']} lease cheque(s) marked cleared from {$result['candidates']} paid installment(s).";
            } else {
                $result = sync_stale_paid_pdc_statuses($conn, $currentCompanyId, false);
                $_SESSION['success'] = "Dry run: {$result['candidates']} paid installment(s) have pending/deposited PDC cheques ready to mark cleared.";
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['error'] = 'PDC sync failed: ' . $e->getMessage();
        }
    }
    header('Location: ' . $pageSelf . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$buildingIdFilter = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$floorIdFilter = !empty($_GET['floor_id']) ? (int)$_GET['floor_id'] : 0;
$unitNumberFilter = trim($_GET['unit_number'] ?? '');
$chequeNumberFilter = trim($_GET['cheque_number'] ?? '');
$exportCsv = ($_GET['export'] ?? '') === 'csv';

// Build query (cheques are shown for active leases only).
// $commonWhere holds every filter EXCEPT the status filter, so the top
// status-breakdown cards stay accurate while still honouring building/floor/
// unit/cheque/date filters.
$commonWhere = ["c.company_id = ?", "l.status = 'active'"];
$commonParams = [$currentCompanyId];

if ($dateFrom) {
    $commonWhere[] = "c.cheque_date >= ?";
    $commonParams[] = $dateFrom;
}

if ($dateTo) {
    $commonWhere[] = "c.cheque_date <= ?";
    $commonParams[] = $dateTo;
}

if ($buildingIdFilter > 0) {
    $commonWhere[] = "b.id = ?";
    $commonParams[] = $buildingIdFilter;
}

if ($floorIdFilter > 0) {
    $commonWhere[] = "u.floor_id = ?";
    $commonParams[] = $floorIdFilter;
}

// Cheque number: partial match (e.g. "CHQ-149" finds "CHQ-149-1")
if ($chequeNumberFilter !== '') {
    $commonWhere[] = "c.cheque_number LIKE ?";
    $commonParams[] = '%' . $chequeNumberFilter . '%';
}

// Unit number: exact match; supports comma-separated list (e.g. "319, 102" or "708")
if ($unitNumberFilter !== '') {
    $unitParts = array_map('trim', array_filter(explode(',', $unitNumberFilter)));
    if (!empty($unitParts)) {
        $placeholders = implode(',', array_fill(0, count($unitParts), '?'));
        $commonWhere[] = "u.unit_number IN ($placeholders)";
        $commonParams = array_merge($commonParams, $unitParts);
    }
}

// Detect Phase 6 settlement columns so this page still works on un-migrated databases.
$hasSettlementCols = false;
try {
    $colChk = $conn->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_post_dated_cheques' AND COLUMN_NAME = 'settlement_method'
    ");
    $colChk->execute();
    $hasSettlementCols = ((int)$colChk->fetchColumn()) > 0;
} catch (Throwable $e) {
    $hasSettlementCols = false;
}

// "Bounced but settled": cheque bounced, but the tenant later settled the obligation
// by another method (paid installment or recorded settlement method).
$settledSql = "c.status = 'bounced' AND (li.status = 'paid'"
    . ($hasSettlementCols ? " OR c.settlement_method IS NOT NULL" : "")
    . ")";

// Cheque list also applies the chosen status filter.
$where = $commonWhere;
$params = $commonParams;

if ($statusFilter === 'due_now') {
    $where[] = "c.status = 'pending' AND c.cheque_date <= CURDATE()";
} elseif ($statusFilter === 'paid_not_synced') {
    $where[] = "c.status IN ('pending', 'deposited') AND li.status = 'paid' AND COALESCE(l.accounting_mode, 'legacy') <> 'invoice'";
} elseif ($statusFilter === 'bounced_settled') {
    $where[] = $settledSql;
} elseif ($statusFilter !== 'all') {
    $where[] = "c.status = ?";
    $params[] = $statusFilter;
}

// Get cheques
$cheques = $conn->prepare("
    SELECT 
        c.*,
        l.lease_number,
        l.end_date AS lease_end_date,
        l.tenant_id AS lease_tenant_id,
        u.id AS unit_id,
        u.unit_number,
        u.floor_id,
        f.floor_number,
        f.name AS floor_name,
        b.id AS building_id,
        b.name as building_name,
        li.status AS installment_status,
        li.paid_at AS installment_paid_at,
        direct_p.receipt_number AS linked_receipt_number,
        settle_p.payment_method AS settle_payment_method,
        settle_p.receipt_number AS settle_receipt_number,
        settle_p.reference_number AS settle_reference_number,
        settle_p.payment_date AS settle_payment_date,
        COALESCE(c.cleared_date, direct_p.payment_date, legacy_p.payment_date, alloc_p.last_payment_date, DATE(li.paid_at)) AS effective_paid_date,
        t.first_name,
        t.last_name,
        (SELECT lc.id FROM re_legal_cases lc WHERE lc.primary_cheque_id = c.id AND lc.deleted_at IS NULL ORDER BY lc.id DESC LIMIT 1) AS legal_case_id
    FROM re_post_dated_cheques c
    JOIN re_leases l ON l.id = c.lease_id
    JOIN re_units u ON u.id = l.unit_id
    LEFT JOIN re_floors f ON f.id = u.floor_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_lease_installments li ON li.id = c.installment_id
    LEFT JOIN re_payments direct_p ON direct_p.id = c.payment_id
    LEFT JOIN re_payments legacy_p ON legacy_p.id = li.payment_id
    LEFT JOIN (
        SELECT pa.installment_id, MAX(p.payment_date) AS last_payment_date
        FROM re_payment_allocations pa
        JOIN re_payments p ON p.id = pa.payment_id
        GROUP BY pa.installment_id
    ) alloc_p ON alloc_p.installment_id = li.id
    LEFT JOIN re_payments settle_p ON settle_p.id = COALESCE(
        li.payment_id,
        (SELECT pa.payment_id FROM re_payment_allocations pa WHERE pa.installment_id = li.id ORDER BY pa.id DESC LIMIT 1)
    )
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.name ASC, COALESCE(f.floor_number, 9999) ASC, u.unit_number ASC, c.cheque_date ASC, c.created_at DESC
");
$cheques->execute($params);
$cheques = $cheques->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) AS total,
        COUNT(CASE WHEN c.status = 'pending' THEN 1 END) AS pending,
        COUNT(CASE WHEN c.status = 'deposited' THEN 1 END) AS deposited,
        COUNT(CASE WHEN c.status = 'cleared' THEN 1 END) AS cleared,
        COUNT(CASE WHEN c.status = 'bounced' THEN 1 END) AS bounced,
        COUNT(CASE WHEN c.status = 'returned' THEN 1 END) AS returned,
        COUNT(CASE WHEN c.status = 'pending' AND c.cheque_date <= CURDATE() THEN 1 END) AS due_now,
        COUNT(CASE WHEN c.status IN ('pending', 'deposited') AND li.status = 'paid' AND COALESCE(l.accounting_mode, 'legacy') <> 'invoice' THEN 1 END) AS paid_not_synced,
        COUNT(CASE WHEN {$settledSql} THEN 1 END) AS bounced_settled
    FROM re_post_dated_cheques c
    JOIN re_leases l ON l.id = c.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_lease_installments li ON li.id = c.installment_id
    WHERE " . implode(' AND ', $commonWhere) . "
");
$stats->execute($commonParams);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

$buildingsStmt = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? AND is_active = 1 ORDER BY name");
$buildingsStmt->execute([$currentCompanyId]);
$buildings = $buildingsStmt->fetchAll(PDO::FETCH_ASSOC);

$floors = [];
if ($buildingIdFilter > 0) {
    $floorsStmt = $conn->prepare("
        SELECT DISTINCT f.id, f.floor_number, COALESCE(f.name, CONCAT('Floor ', f.floor_number)) AS name
        FROM re_floors f
        JOIN re_units u ON u.floor_id = f.id
        WHERE u.company_id = ? AND u.building_id = ?
        ORDER BY f.floor_number ASC
    ");
    $floorsStmt->execute([$currentCompanyId, $buildingIdFilter]);
    $floors = $floorsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$summaryByBuilding = [];
$summaryByFloor = [];
foreach ($cheques as $cheque) {
    $buildingName = $cheque['building_name'] ?: 'Unknown';
    $floorLabel = $cheque['floor_name'] ?: (!empty($cheque['floor_number']) ? 'Floor ' . $cheque['floor_number'] : 'No Floor');
    if (!isset($summaryByBuilding[$buildingName])) {
        $summaryByBuilding[$buildingName] = ['count' => 0, 'amount' => 0.0, 'pending' => 0, 'cleared' => 0, 'bounced' => 0, 'returned' => 0];
    }
    if (!isset($summaryByFloor[$floorLabel])) {
        $summaryByFloor[$floorLabel] = ['count' => 0, 'amount' => 0.0, 'pending' => 0, 'cleared' => 0, 'bounced' => 0, 'returned' => 0];
    }
    $summaryByBuilding[$buildingName]['count']++;
    $summaryByBuilding[$buildingName]['amount'] += (float)$cheque['cheque_amount'];
    $summaryByFloor[$floorLabel]['count']++;
    $summaryByFloor[$floorLabel]['amount'] += (float)$cheque['cheque_amount'];
    if (isset($summaryByBuilding[$buildingName][$cheque['status']])) {
        $summaryByBuilding[$buildingName][$cheque['status']]++;
        $summaryByFloor[$floorLabel][$cheque['status']]++;
    }
}

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
$exportUrl = $pageSelf . '?' . http_build_query($exportQuery);

if ($exportCsv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pdc-report-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Cheque #', 'Cheque Date', 'Building', 'Floor', 'Unit', 'Lease', 'Lease Expiry Date', 'Tenant', 'Amount', 'Bank', 'PDC Status', 'Installment Status', 'Paid/Cleared Date']);
    foreach ($cheques as $cheque) {
        fputcsv($out, [
            $cheque['cheque_number'],
            $cheque['cheque_date'],
            $cheque['building_name'],
            $cheque['floor_name'] ?: $cheque['floor_number'],
            $cheque['unit_number'],
            $cheque['lease_number'],
            !empty($cheque['lease_end_date']) ? date('Y-m-d', strtotime($cheque['lease_end_date'])) : '',
            trim($cheque['first_name'] . ' ' . $cheque['last_name']),
            $cheque['cheque_amount'],
            $cheque['bank_name'],
            $cheque['status'],
            $cheque['installment_status'],
            $cheque['effective_paid_date'],
        ]);
    }
    exit;
}

// Set page title and include layout
$pageTitle = $isLegalLayout ? 'Post-Dated Cheques' : 'Post-Dated Cheques';
if ($isLegalLayout) {
    require_once __DIR__ . '/../legal/includes/legal_layout_header.php';
} else {
    require_once __DIR__ . '/includes/re_layout_header.php';
}
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-bank"></i> Post-Dated Cheques</h1>
            <div class="d-flex gap-2">
                <a href="<?= h($exportUrl) ?>" class="btn btn-outline-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>
                <?php if (!$isLegalLayout): ?>
                <a href="billing_cheque_add.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Add Cheque
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-md-2 col-sm-6">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total</h5>
                        <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Pending</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['pending'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Due Now</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['due_now'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h5 class="text-muted">Deposited</h5>
                        <h2 class="mb-0 text-info"><?= $stats['deposited'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h5 class="text-muted">Cleared</h5>
                        <h2 class="mb-0 text-success"><?= $stats['cleared'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Bounced</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['bounced'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <a class="text-decoration-none" href="<?= h($pageSelf) ?>?status=returned">
                    <div class="card text-center border-secondary">
                        <div class="card-body">
                            <h5 class="text-muted">Returned</h5>
                            <h2 class="mb-0 text-secondary"><?= $stats['returned'] ?></h2>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-2 col-sm-6">
                <a class="text-decoration-none" href="<?= h($pageSelf) ?>?status=paid_not_synced">
                    <div class="card text-center border-dark">
                        <div class="card-body">
                            <h5 class="text-muted">Paid Not Synced</h5>
                            <h2 class="mb-0 text-dark"><?= $stats['paid_not_synced'] ?></h2>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-2 col-sm-6">
                <a class="text-decoration-none" href="<?= h($pageSelf) ?>?status=bounced_settled">
                    <div class="card text-center border-success">
                        <div class="card-body">
                            <h5 class="text-muted">Bounced but Settled</h5>
                            <h2 class="mb-0 text-success"><?= (int)($stats['bounced_settled'] ?? 0) ?></h2>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <?php if ($canSyncPdc && !$isLegalLayout): ?>
            <div class="card mb-4 border-dark">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h5 class="mb-1">PDC Status Sync</h5>
                        <div class="text-muted small">
                            Finds paid installments whose PDC is still pending/deposited and marks only those cheques as cleared.
                            Bounced, returned, and cancelled cheques are protected.
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <form method="post">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="dry_run_stale_pdc">
                            <button type="submit" class="btn btn-outline-dark btn-sm">Dry Run</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Apply PDC sync and mark stale paid cheques as cleared?');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="apply_stale_pdc">
                            <button type="submit" class="btn btn-dark btn-sm">Apply Sync</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="due_now" <?= $statusFilter === 'due_now' ? 'selected' : '' ?>>Due Now</option>
                            <option value="paid_not_synced" <?= $statusFilter === 'paid_not_synced' ? 'selected' : '' ?>>Paid Not Synced</option>
                            <option value="deposited" <?= $statusFilter === 'deposited' ? 'selected' : '' ?>>Deposited</option>
                            <option value="cleared" <?= $statusFilter === 'cleared' ? 'selected' : '' ?>>Cleared</option>
                            <option value="bounced" <?= $statusFilter === 'bounced' ? 'selected' : '' ?>>Bounced</option>
                            <option value="bounced_settled" <?= $statusFilter === 'bounced_settled' ? 'selected' : '' ?>>Bounced but Settled</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            <option value="returned" <?= $statusFilter === 'returned' ? 'selected' : '' ?>>Returned</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Building</label>
                        <select name="building_id" id="filterBuilding" class="form-select form-select-sm">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $building): ?>
                                <option value="<?= (int)$building['id'] ?>" <?= $buildingIdFilter === (int)$building['id'] ? 'selected' : '' ?>>
                                    <?= h($building['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Floor</label>
                        <select name="floor_id" id="filterFloor" class="form-select form-select-sm" data-selected="<?= (int)$floorIdFilter ?>" <?= $buildingIdFilter ? '' : 'disabled' ?>>
                            <option value="">All Floors</option>
                            <?php foreach ($floors as $floor): ?>
                                <option value="<?= (int)$floor['id'] ?>" <?= $floorIdFilter === (int)$floor['id'] ? 'selected' : '' ?>>
                                    <?= h($floor['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="filterFloorHint" <?= $buildingIdFilter ? 'style="display:none"' : '' ?>>Select building first</small>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Unit Number</label>
                        <input type="text" name="unit_number" class="form-control form-control-sm" value="<?= h($unitNumberFilter) ?>" placeholder="e.g. 708, 319, 102">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Cheque #</label>
                        <input type="text" name="cheque_number" class="form-control form-control-sm" value="<?= h($chequeNumberFilter) ?>" placeholder="e.g. CHQ-149-1">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-funnel"></i> Filter
                            </button>
                            <a href="<?= h($pageSelf) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-1">Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h5 class="mb-0">Summary by Building</h5></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Building</th><th class="text-end">Cheques</th><th class="text-end">Amount</th><th class="text-end">Cleared</th><th class="text-end">Pending</th><th class="text-end">Bounced</th></tr></thead>
                                <tbody>
                                <?php if (empty($summaryByBuilding)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-3">No data for selected filters.</td></tr>
                                <?php else: foreach ($summaryByBuilding as $name => $row): ?>
                                    <tr>
                                        <td><?= h($name) ?></td>
                                        <td class="text-end"><?= (int)$row['count'] ?></td>
                                        <td class="text-end"><?= number_format($row['amount'], 2) ?> AED</td>
                                        <td class="text-end text-success"><?= (int)$row['cleared'] ?></td>
                                        <td class="text-end text-warning"><?= (int)$row['pending'] ?></td>
                                        <td class="text-end text-danger"><?= (int)$row['bounced'] ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h5 class="mb-0">Summary by Floor</h5></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Floor</th><th class="text-end">Cheques</th><th class="text-end">Amount</th><th class="text-end">Cleared</th><th class="text-end">Pending</th><th class="text-end">Bounced</th></tr></thead>
                                <tbody>
                                <?php if (empty($summaryByFloor)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-3">No data for selected filters.</td></tr>
                                <?php else: foreach ($summaryByFloor as $name => $row): ?>
                                    <tr>
                                        <td><?= h($name) ?></td>
                                        <td class="text-end"><?= (int)$row['count'] ?></td>
                                        <td class="text-end"><?= number_format($row['amount'], 2) ?> AED</td>
                                        <td class="text-end text-success"><?= (int)$row['cleared'] ?></td>
                                        <td class="text-end text-warning"><?= (int)$row['pending'] ?></td>
                                        <td class="text-end text-danger"><?= (int)$row['bounced'] ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cheques Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Post-Dated Cheques (<?= count($cheques) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Cheque #</th>
                                <th>Cheque Date</th>
                                <th>Property</th>
                                <th>Lease</th>
                                <th>Lease Expiry</th>
                                <th>Tenant</th>
                                <th>Amount</th>
                                <th>Bank</th>
                                <th>Status</th>
                                <th>Paid/Cleared</th>
                                <th>Links</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cheques)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No cheques found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cheques as $cheque): ?>
                                    <tr class="<?= 
                                        $cheque['status'] === 'pending' && strtotime($cheque['cheque_date']) <= time() ? 'table-warning' : 
                                        ($cheque['status'] === 'bounced' ? 'table-danger' : ($cheque['status'] === 'returned' ? 'table-secondary' : '')) 
                                    ?>">
                                        <td><strong><?= h($cheque['cheque_number']) ?></strong></td>
                                        <td>
                                            <?= date('M d, Y', strtotime($cheque['cheque_date'])) ?>
                                            <?php if ($cheque['status'] === 'pending' && strtotime($cheque['cheque_date']) <= time()): ?>
                                                <br><small class="text-danger">Due Now</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= h($cheque['building_name']) ?><br>
                                            <small class="text-muted">
                                                Floor: <?= h($cheque['floor_name'] ?: ($cheque['floor_number'] ? 'Floor ' . $cheque['floor_number'] : '-')) ?> |
                                                Unit: <?= h($cheque['unit_number']) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?= h($cheque['lease_number']) ?><br>
                                            <?php if (!empty($cheque['installment_status']) && $cheque['status'] !== 'cleared' && $cheque['installment_status'] === 'paid'): ?>
                                                <span class="badge bg-dark">Paid installment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($cheque['lease_end_date'])): ?>
                                                <?php
                                                    $leaseEnd = strtotime($cheque['lease_end_date']);
                                                    $isExpiringSoon = $leaseEnd >= time() && $leaseEnd <= strtotime('+30 days');
                                                ?>
                                                <span class="<?= $isExpiringSoon ? 'text-danger fw-semibold' : '' ?>">
                                                    <?= date('M d, Y', $leaseEnd) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($cheque['first_name'] . ' ' . $cheque['last_name']) ?></td>
                                        <td class="text-end"><strong><?= number_format($cheque['cheque_amount'], 2) ?> AED</strong></td>
                                        <td><?= h($cheque['bank_name'] ?: '-') ?></td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $cheque['status'] === 'cleared' ? 'success' : 
                                                ($cheque['status'] === 'bounced' ? 'danger' : 
                                                ($cheque['status'] === 'deposited' ? 'info' : 
                                                ($cheque['status'] === 'returned' ? 'dark' : 'warning'))) 
                                            ?>">
                                                <?= ucfirst($cheque['status']) ?>
                                            </span>
                                            <?php if ($cheque['status'] === 'bounced' && !empty($cheque['bounced_date'])): ?>
                                                <br><small class="text-muted">Bounced: <?= h(date('M d, Y', strtotime($cheque['bounced_date']))) ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($cheque['installment_status'])): ?>
                                                <br><small class="text-muted">Installment: <?= h(ucfirst($cheque['installment_status'])) ?></small>
                                            <?php endif; ?>
                                            <?php
                                                $isBouncedSettled = $cheque['status'] === 'bounced'
                                                    && (($cheque['installment_status'] ?? '') === 'paid' || !empty($cheque['settlement_method']));
                                                if ($isBouncedSettled):
                                                    $settledMethodRaw = $cheque['settlement_method'] ?? $cheque['settle_payment_method'] ?? '';
                                                    $settledMethodLabel = $settledMethodRaw !== ''
                                                        ? ucwords(str_replace('_', ' ', $settledMethodRaw))
                                                        : 'Other Method';
                                                    $settledRef = $cheque['settlement_reference']
                                                        ?? $cheque['settle_receipt_number']
                                                        ?? $cheque['settle_reference_number']
                                                        ?? '';
                                            ?>
                                                <br><span class="badge bg-success mt-1">Settled by <?= h($settledMethodLabel) ?></span>
                                                <?php if (!empty($settledRef)): ?>
                                                    <br><small class="text-muted">Ref: <?= h($settledRef) ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($cheque['settle_payment_date'])): ?>
                                                    <br><small class="text-muted">Settled: <?= h(date('M d, Y', strtotime($cheque['settle_payment_date']))) ?></small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= !empty($cheque['effective_paid_date']) ? h(date('M d, Y', strtotime($cheque['effective_paid_date']))) : '-' ?>
                                        </td>
                                        <td class="small">
                                            <?php if (!empty($cheque['linked_receipt_number'])): ?>
                                                <div>Receipt: <?= h($cheque['linked_receipt_number']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($cheque['replaced_by_cheque_id'])): ?>
                                                <div>Replaced by #<?= (int)$cheque['replaced_by_cheque_id'] ?></div>
                                            <?php elseif (!empty($cheque['replacement_for_cheque_id'])): ?>
                                                <div>Replacement for #<?= (int)$cheque['replacement_for_cheque_id'] ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($cheque['legal_case_id'])): ?>
                                                <div class="text-dark">Legal case linked</div>
                                            <?php endif; ?>
                                            <?php if (empty($cheque['linked_receipt_number']) && empty($cheque['replaced_by_cheque_id']) && empty($cheque['replacement_for_cheque_id']) && empty($cheque['legal_case_id'])): ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?= h($reBase) ?>billing_cheque_view.php?id=<?= $cheque['id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            <a href="<?= h($docsPage) ?>?lease_id=<?= (int)$cheque['lease_id'] ?>&tenant_id=<?= (int)$cheque['lease_tenant_id'] ?>" class="btn btn-sm btn-outline-info" title="View lease & tenant documents">
                                                <i class="bi bi-folder2-open"></i> Documents
                                            </a>
                                            <?php if ($hasLegalAccess || $isLegalLayout): ?>
                                                <?php if (!empty($cheque['legal_case_id'])): ?>
                                                    <a href="<?= h($isLegalLayout ? 'legal_case_view.php' : $legalBase . 'legal_case_view.php') ?>?id=<?= (int)$cheque['legal_case_id'] ?>" class="btn btn-sm btn-dark" title="Open linked legal case">
                                                        <i class="bi bi-briefcase"></i> Legal Case
                                                    </a>
                                                <?php else: ?>
                                                    <?php
                                                        $escTitle = 'Bounced cheque recovery - ' . $cheque['cheque_number'];
                                                        $escType = ($cheque['status'] === 'bounced') ? 'cheque_bounce' : 'rent_recovery';
                                                        $escQs = http_build_query([
                                                            'case_type' => $escType,
                                                            'case_source' => 'bounced_cheque',
                                                            'title' => $escTitle,
                                                            'building_id' => (int)$cheque['building_id'],
                                                            'unit_id' => (int)$cheque['unit_id'],
                                                            'tenant_id' => (int)$cheque['lease_tenant_id'],
                                                            'lease_id' => (int)$cheque['lease_id'],
                                                            'primary_cheque_id' => (int)$cheque['id'],
                                                            'claim_amount' => (float)$cheque['cheque_amount'],
                                                            'priority' => ($cheque['status'] === 'bounced') ? 'high' : 'medium',
                                                        ]);
                                                    ?>
                                                    <a href="<?= h($isLegalLayout ? 'legal_case_add.php' : $legalBase . 'legal_case_add.php') ?>?<?= h($escQs) ?>" class="btn btn-sm btn-outline-dark" title="Escalate to Legal Department">
                                                        <i class="bi bi-bank"></i> Escalate
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
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
(function () {
    var buildingSel = document.getElementById('filterBuilding');
    var floorSel = document.getElementById('filterFloor');
    var floorHint = document.getElementById('filterFloorHint');
    if (!buildingSel || !floorSel) return;

    function loadFloors(buildingId, selectedFloorId) {
        if (!buildingId) {
            floorSel.innerHTML = '<option value="">All Floors</option>';
            floorSel.disabled = true;
            if (floorHint) floorHint.style.display = '';
            return;
        }
        floorSel.disabled = true;
        floorSel.innerHTML = '<option value="">Loading…</option>';
        if (floorHint) floorHint.style.display = 'none';
        fetch('<?= h($reBase) ?>ajax_floors.php?action=list&building_id=' + encodeURIComponent(buildingId))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                floorSel.innerHTML = '<option value="">All Floors</option>';
                if (d && d.success && Array.isArray(d.floors)) {
                    d.floors.forEach(function (f) {
                        var opt = document.createElement('option');
                        opt.value = f.id;
                        var label = (f.name && f.name.trim() !== '') ? f.name : ('Floor ' + f.floor_number);
                        opt.textContent = label;
                        if (selectedFloorId && String(selectedFloorId) === String(f.id)) opt.selected = true;
                        floorSel.appendChild(opt);
                    });
                }
                floorSel.disabled = false;
            })
            .catch(function () {
                floorSel.innerHTML = '<option value="">All Floors</option>';
                floorSel.disabled = false;
            });
    }

    buildingSel.addEventListener('change', function () {
        loadFloors(this.value, '');
    });

    // On load: if a building is preselected, (re)load floors and keep current selection.
    if (buildingSel.value) {
        loadFloors(buildingSel.value, floorSel.getAttribute('data-selected') || '');
    }
})();
</script>

<?php
if ($isLegalLayout) {
    require_once __DIR__ . '/../legal/includes/legal_layout_footer.php';
} else {
    require_once __DIR__ . '/includes/re_layout_footer.php';
}
?>

