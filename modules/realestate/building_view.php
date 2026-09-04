<?php
/**
 * Real Estate Module - Building View
 * Shows building details, facilities, common areas, and floor plans
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$buildingId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$buildingId) {
    header('Location: buildings.php');
    exit;
}

// Get building details with managed_by company name
$stmt = $conn->prepare("
    SELECT b.*, c.name as managed_by_company_name
    FROM re_buildings b
    LEFT JOIN companies c ON c.id = b.managed_by
    WHERE b.id = ? AND b.company_id = ?
");
$stmt->execute([$buildingId, $currentCompanyId]);
$building = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$building) {
    header('Location: buildings.php');
    exit;
}

$renewalNoticeFlash = '';
$renewalNoticeFlashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_renewal_notice_building_settings') {
    csrf_verify();
    try {
        $tpl = trim((string)($_POST['template_code'] ?? 'renewal_notice_unified_v1'));
        if ($tpl === '') {
            $tpl = 'renewal_notice_unified_v1';
        }
        $showChillerRow = !empty($_POST['show_chiller_row']) ? 1 : 0;
        $showChillerTerm = !empty($_POST['show_chiller_term']) ? 1 : 0;
        $adminFeeLabel = trim((string)($_POST['admin_fee_label'] ?? 'Lease Renewal Charges'));
        if ($adminFeeLabel === '') {
            $adminFeeLabel = 'Lease Renewal Charges';
        }
        $defPark = round(max(0, (float)($_POST['default_parking_fee'] ?? 0)), 2);
        $defSmall = round(max(0, (float)($_POST['default_small_store_fee'] ?? 0)), 2);
        $defBig = round(max(0, (float)($_POST['default_big_store_fee'] ?? 0)), 2);
        $vatOn = !empty($_POST['vat_enabled']) ? 1 : 0;
        $vatRate = (float)($_POST['vat_rate'] ?? 5);
        if ($vatRate < 0) {
            $vatRate = 0;
        }
        if ($vatRate > 100) {
            $vatRate = 100;
        }
        $rera = round(max(0, (float)($_POST['rera_charges'] ?? 300)), 2);
        $community = trim((string)($_POST['community_name'] ?? 'Jumeirah Village Circle'));
        if ($community === '') {
            $community = 'Jumeirah Village Circle';
        }
        $location = trim((string)($_POST['location_name'] ?? 'Al Barsha South Fourth, Dubai'));
        if ($location === '') {
            $location = 'Al Barsha South Fourth, Dubai';
        }
        $showGrand = !empty($_POST['show_grand_total_row']) ? 1 : 0;

        $upsert = $conn->prepare("
            INSERT INTO re_renewal_notice_building_settings (
                company_id, building_id, template_code,
                show_chiller_row, show_chiller_term, admin_fee_label,
                default_parking_fee, default_small_store_fee, default_big_store_fee,
                vat_enabled, vat_rate, rera_charges,
                community_name, location_name,
                show_grand_total_row
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?
            )
            ON DUPLICATE KEY UPDATE
                template_code = VALUES(template_code),
                show_chiller_row = VALUES(show_chiller_row),
                show_chiller_term = VALUES(show_chiller_term),
                admin_fee_label = VALUES(admin_fee_label),
                default_parking_fee = VALUES(default_parking_fee),
                default_small_store_fee = VALUES(default_small_store_fee),
                default_big_store_fee = VALUES(default_big_store_fee),
                vat_enabled = VALUES(vat_enabled),
                vat_rate = VALUES(vat_rate),
                rera_charges = VALUES(rera_charges),
                community_name = VALUES(community_name),
                location_name = VALUES(location_name),
                show_grand_total_row = VALUES(show_grand_total_row)
        ");
        $upsert->execute([
            $currentCompanyId,
            $buildingId,
            $tpl,
            $showChillerRow,
            $showChillerTerm,
            $adminFeeLabel,
            $defPark,
            $defSmall,
            $defBig,
            $vatOn,
            $vatRate,
            $rera,
            $community,
            $location,
            $showGrand,
        ]);
        $renewalNoticeFlash = 'Renewal notice settings saved for this building.';
        $renewalNoticeFlashType = 'success';
    } catch (Throwable $e) {
        $renewalNoticeFlash = 'Could not save renewal settings: ' . $e->getMessage();
        $renewalNoticeFlashType = 'danger';
    }
}

// Floors for this building (used by Floors Management + Common Area floor dropdown)
$stmt = $conn->prepare("
    SELECT f.*, COUNT(u.id) as units_count
    FROM re_floors f
    LEFT JOIN re_units u ON u.floor_id = f.id
    WHERE f.building_id = ?
    GROUP BY f.id
    ORDER BY f.floor_number ASC
");
$stmt->execute([$buildingId]);
$floors = $stmt->fetchAll(PDO::FETCH_ASSOC);
$floorLabelByNumber = [];
foreach ($floors as $f) {
    $n = (int)$f['floor_number'];
    $name = trim((string)($f['name'] ?? ''));
    // Prefer floor name (e.g. Podium, 1st Floor); fall back to Floor N
    $floorLabelByNumber[$n] = $name !== '' ? $name : ('Floor ' . $n);
}

// Get common areas (include inactive so they can be reactivated)
$stmt = $conn->prepare("
    SELECT ca.*,
           f.name AS floor_name
    FROM re_building_common_areas ca
    LEFT JOIN re_floors f ON f.building_id = ca.building_id AND f.floor_number = ca.floor_number
    WHERE ca.building_id = ?
    ORDER BY ca.is_active DESC, ca.area_type, ca.floor_number, ca.area_name
");
$stmt->execute([$buildingId]);
$commonAreas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get floor plans
$stmt = $conn->prepare("
    SELECT fp.*, u.username as uploaded_by_name
    FROM re_building_floor_plans fp
    LEFT JOIN user u ON u.id = fp.uploaded_by
    WHERE fp.building_id = ?
    ORDER BY fp.floor_number ASC, fp.is_primary DESC, fp.created_at DESC
");
$stmt->execute([$buildingId]);
$floorPlans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get units count
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_units,
        COUNT(CASE WHEN status = 'occupied' THEN 1 END) as occupied_units,
        COUNT(CASE WHEN status = 'vacant' THEN 1 END) as vacant_units
    FROM re_units
    WHERE building_id = ?
");
$stmt->execute([$buildingId]);
$unitStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Per-building renewal notice / lease renewal workflow defaults (re_renewal_notice_building_settings)
$renewalStmt = $conn->prepare("
    SELECT * FROM re_renewal_notice_building_settings
    WHERE company_id = ? AND building_id = ?
    LIMIT 1
");
$renewalStmt->execute([$currentCompanyId, $buildingId]);
$renewalNoticeSettings = $renewalStmt->fetch(PDO::FETCH_ASSOC);
if (!$renewalNoticeSettings) {
    $renewalNoticeSettings = [
        'template_code' => 'renewal_notice_unified_v1',
        'show_chiller_row' => 0,
        'show_chiller_term' => 0,
        'admin_fee_label' => 'Lease Renewal Charges',
        'default_parking_fee' => 0,
        'default_small_store_fee' => 0,
        'default_big_store_fee' => 0,
        'vat_enabled' => 1,
        'vat_rate' => 5.0,
        'rera_charges' => 300.0,
        'community_name' => 'Jumeirah Village Circle',
        'location_name' => 'Al Barsha South Fourth, Dubai',
        'show_grand_total_row' => 0,
    ];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

// Set page title and include layout
$pageTitle = 'Building Details';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><?= h($building['name']) ?></div>
            <div>
                <a href="buildings.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <a href="buildings.php" class="btn btn-primary">
                    <i class="bi bi-pencil"></i> Edit Building
                </a>
            </div>
        </div>

        <?php if ($renewalNoticeFlash !== ''): ?>
            <div class="alert alert-<?= $renewalNoticeFlashType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <?= h($renewalNoticeFlash) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= h((string)$_SESSION['flash_error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div class="row">
            <!-- Building Information -->
            <div class="col-md-6 mb-4">
                <div class="card card-round">
                    <div class="card-header">
                        <h5><i class="bi bi-building"></i> Building Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Name:</th>
                                <td><strong><?= h($building['name']) ?></strong></td>
                            </tr>
                            <tr>
                                <th>Address:</th>
                                <td><?= h($building['address'] ?: '-') ?></td>
                            </tr>
                            <?php if ($building['dewa_premises']): ?>
                            <tr>
                                <th>DEWA Premises:</th>
                                <td><?= h($building['dewa_premises']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($building['plot_number']): ?>
                            <tr>
                                <th>Plot Number:</th>
                                <td><?= h($building['plot_number']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($building['landlord_name']): ?>
                            <tr>
                                <th>Landlord Name:</th>
                                <td><?= h($building['landlord_name']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($building['managed_by']): ?>
                            <tr>
                                <th>Managed By:</th>
                                <td><?= h($building['managed_by_company_name'] ?: 'N/A') ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($building['total_floors']): ?>
                            <tr>
                                <th>Total Floors:</th>
                                <td><?= $building['total_floors'] ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($building['total_units']): ?>
                            <tr>
                                <th>Total Units:</th>
                                <td><?= $building['total_units'] ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <?php if ($building['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Unit Statistics -->
            <div class="col-md-6 mb-4">
                <div class="card card-round">
                    <div class="card-header">
                        <h5><i class="bi bi-bar-chart"></i> Unit Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4">
                                <h3 class="mb-0"><?= $unitStats['total_units'] ?: 0 ?></h3>
                                <small class="text-muted">Total Units</small>
                            </div>
                            <div class="col-4">
                                <h3 class="mb-0 text-success"><?= $unitStats['occupied_units'] ?: 0 ?></h3>
                                <small class="text-muted">Occupied</small>
                            </div>
                            <div class="col-4">
                                <h3 class="mb-0 text-warning"><?= $unitStats['vacant_units'] ?: 0 ?></h3>
                                <small class="text-muted">Vacant</small>
                            </div>
                        </div>
                        <div class="mt-3">
                            <a href="units.php?building_id=<?= $buildingId ?>" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-door-open"></i> View All Units
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lease renewal notice defaults (used by Renewal Workflow, PDF, emails) -->
        <div class="card card-round mb-4 border-info">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Lease renewal notice (per building)</h5>
                <a href="lease_renewal_workflow.php" class="btn btn-sm btn-outline-secondary">Renewal Workflow</a>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    These settings apply when you <strong>initiate</strong> a renewal for any lease in this building.
                    If you have <strong>no row</strong> saved yet, buildings whose name contains <strong>Park Place</strong> still get chiller on by default in the app;
                    saving here creates an explicit profile for this building (recommended for Park Place: enable <em>Show chiller on notice</em>).
                </p>
                <form method="post" action="building_view.php?id=<?= (int)$buildingId ?>">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="save_renewal_notice_building_settings">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Notice template code</label>
                            <input type="text" name="template_code" class="form-control"
                                   value="<?= h((string)($renewalNoticeSettings['template_code'] ?? 'renewal_notice_unified_v1')) ?>"
                                   placeholder="renewal_notice_unified_v1">
                            <div class="form-text">Usually leave as default unless IT changes templates.</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Admin / renewal fee label</label>
                            <input type="text" name="admin_fee_label" class="form-control"
                                   value="<?= h((string)($renewalNoticeSettings['admin_fee_label'] ?? 'Lease Renewal Charges')) ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">RERA line amount (AED)</label>
                            <input type="number" step="0.01" min="0" name="rera_charges" class="form-control"
                                   value="<?= h((string)($renewalNoticeSettings['rera_charges'] ?? '300')) ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="show_chiller_row" value="1" id="rnShowChillerRow"
                                    <?= !empty($renewalNoticeSettings['show_chiller_row']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rnShowChillerRow"><strong>Show chiller on notice &amp; renewal form</strong></label>
                            </div>
                            <div class="form-check ms-4">
                                <input class="form-check-input" type="checkbox" name="show_chiller_term" value="1" id="rnShowChillerTerm"
                                    <?= !empty($renewalNoticeSettings['show_chiller_term']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rnShowChillerTerm">Include chiller bullet in terms block</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="vat_enabled" value="1" id="rnVatOn"
                                    <?= !empty($renewalNoticeSettings['vat_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rnVatOn">VAT on extra charges (admin, chiller, parking, store)</label>
                            </div>
                            <label class="form-label">VAT rate (%)</label>
                            <input type="number" step="0.01" min="0" max="100" name="vat_rate" class="form-control form-control-sm" style="max-width:8rem"
                                   value="<?= h((string)($renewalNoticeSettings['vat_rate'] ?? '5')) ?>">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="show_grand_total_row" value="1" id="rnGrandTotal"
                                    <?= !empty($renewalNoticeSettings['show_grand_total_row']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rnGrandTotal">Show grand total row on notice</label>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Default extra parking (AED)</label>
                            <input type="number" step="0.01" min="0" name="default_parking_fee" class="form-control"
                                   value="<?= h((string)($renewalNoticeSettings['default_parking_fee'] ?? '0')) ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Default small storeroom (AED)</label>
                            <input type="number" step="0.01" min="0" name="default_small_store_fee" class="form-control"
                                   value="<?= h((string)($renewalNoticeSettings['default_small_store_fee'] ?? '0')) ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Default big storeroom (AED)</label>
                            <input type="number" step="0.01" min="0" name="default_big_store_fee" class="form-control"
                                   value="<?= h((string)($renewalNoticeSettings['default_big_store_fee'] ?? '0')) ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Community (PDF header)</label>
                            <input type="text" name="community_name" class="form-control"
                                   value="<?= h((string)($renewalNoticeSettings['community_name'] ?? '')) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Location line (PDF header)</label>
                            <input type="text" name="location_name" class="form-control"
                                   value="<?= h((string)($renewalNoticeSettings['location_name'] ?? '')) ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save renewal notice settings
                    </button>
                </form>
            </div>
        </div>

        <!-- AMC Contracts -->
        <?php
        // Get AMC contracts for this building
        $stmt = $conn->prepare("
            SELECT ac.*, cat.name as category_name, cat.code as category_code, v.vendor_name,
                   DATEDIFF(ac.end_date, CURDATE()) as days_until_expiry,
                   (SELECT COUNT(*) FROM re_amc_visits av WHERE av.contract_id = ac.id AND av.status = 'completed') as completed_visits,
                   (SELECT COUNT(*) FROM re_amc_visits av WHERE av.contract_id = ac.id AND av.status = 'scheduled') as scheduled_visits,
                   (SELECT COUNT(*) FROM re_amc_certificates cert WHERE cert.contract_id = ac.id AND cert.status IN ('valid', 'expiring_soon')) as valid_certificates,
                   (SELECT COUNT(*) FROM re_amc_certificates cert WHERE cert.contract_id = ac.id AND cert.status = 'expired') as expired_certificates
            FROM re_amc_contracts ac
            LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
            LEFT JOIN re_vendors v ON v.id = ac.vendor_id
            WHERE ac.building_id = ? AND ac.company_id = ?
            ORDER BY ac.status ASC, ac.end_date ASC
        ");
        $stmt->execute([$buildingId, $currentCompanyId]);
        $amcContracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="card card-round mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-file-earmark-check"></i> AMC Contracts</h5>
                <div>
                    <a href="amc.php?building_id=<?= $buildingId ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-circle"></i> Add AMC Contract
                    </a>
                    <a href="amc.php?building_id=<?= $buildingId ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-list"></i> View All
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($amcContracts)): ?>
                    <p class="text-muted mb-0">No AMC contracts found for this building.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Contract #</th>
                                    <th>Category</th>
                                    <th>Vendor</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Status</th>
                                    <th>Value</th>
                                    <th>Visits</th>
                                    <th>Certificates</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($amcContracts as $ac): 
                                    $daysLeft = (int)($ac['days_until_expiry'] ?? 0);
                                    $statusBadge = [
                                        'draft' => 'secondary',
                                        'active' => 'success',
                                        'expired' => 'danger',
                                        'terminated' => 'dark',
                                        'renewed' => 'info'
                                    ][$ac['status']] ?? 'secondary';
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($ac['contract_number']) ?></strong>
                                            <?php if ($ac['contract_title']): ?>
                                                <br><small class="text-muted"><?= h($ac['contract_title']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= h($ac['category_name'] ?? 'N/A') ?></span>
                                        </td>
                                        <td><?= h($ac['vendor_name'] ?? 'N/A') ?></td>
                                        <td><?= h($ac['start_date']) ?></td>
                                        <td>
                                            <?= h($ac['end_date']) ?>
                                            <?php if ($ac['status'] === 'active' && $daysLeft <= 30): ?>
                                                <br><small class="text-<?= $daysLeft <= 7 ? 'danger' : 'warning' ?>">
                                                    <?= $daysLeft > 0 ? "{$daysLeft} days left" : "Expired" ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $statusBadge ?>"><?= ucfirst($ac['status']) ?></span>
                                        </td>
                                        <td>
                                            <strong><?= number_format($ac['total_amount'], 2) ?> AED</strong>
                                        </td>
                                        <td>
                                            <small>
                                                <i class="bi bi-check-circle text-success"></i> <?= $ac['completed_visits'] ?>
                                                <i class="bi bi-calendar ms-2 text-primary"></i> <?= $ac['scheduled_visits'] ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($ac['valid_certificates'] > 0): ?>
                                                <small class="text-success">
                                                    <i class="bi bi-shield-check"></i> <?= $ac['valid_certificates'] ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if ($ac['expired_certificates'] > 0): ?>
                                                <br><small class="text-danger">
                                                    <i class="bi bi-exclamation-triangle"></i> <?= $ac['expired_certificates'] ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if ($ac['valid_certificates'] == 0 && $ac['expired_certificates'] == 0): ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="amc_view.php?id=<?= $ac['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="amc_visits.php?contract_id=<?= $ac['id'] ?>" class="btn btn-sm btn-outline-info" title="Visits">
                                                <i class="bi bi-calendar-check"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Floors Management -->
        <div class="card card-round mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-layers"></i> Floors Management</h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#floorManagementModal" onclick="loadFloors()">
                    <i class="bi bi-gear"></i> Manage Floors
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($floors)): ?>
                    <p class="text-muted mb-0">No floors configured. Click "Manage Floors" to add floors.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Floor Number</th>
                                    <th>Floor Name</th>
                                    <th>Units</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($floors as $floor): ?>
                                    <tr>
                                        <td><strong><?= $floor['floor_number'] ?></strong></td>
                                        <td><?= h($floor['name'] ?: '—') ?></td>
                                        <td>
                                            <span class="badge bg-info"><?= $floor['units_count'] ?> unit<?= $floor['units_count'] != 1 ? 's' : '' ?></span>
                                        </td>
                                        <td>
                                            <?php if ($floor['units_count'] > 0): ?>
                                                <span class="badge bg-success">In Use</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Empty</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Facilities -->
        <div class="card card-round mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-star"></i> Facilities</h5>
                <a href="buildings.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-pencil"></i> Edit Facilities
                </a>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="d-flex align-items-center">
                            <?php if ($building['has_parking']): ?>
                                <i class="bi bi-p-square text-success me-2" style="font-size: 1.5rem;"></i>
                                <div>
                                    <strong>Parking Available</strong>
                                    <?php if ($building['parking_spaces']): ?>
                                        <br><small class="text-muted"><?= $building['parking_spaces'] ?> spaces</small>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <i class="bi bi-p-square text-muted me-2" style="font-size: 1.5rem;"></i>
                                <div><span class="text-muted">No Parking</span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="d-flex align-items-center">
                            <?php if ($building['has_gym']): ?>
                                <i class="bi bi-dumbbell text-success me-2" style="font-size: 1.5rem;"></i>
                                <div><strong>Gym Available</strong></div>
                            <?php else: ?>
                                <i class="bi bi-dumbbell text-muted me-2" style="font-size: 1.5rem;"></i>
                                <div><span class="text-muted">No Gym</span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="d-flex align-items-center">
                            <?php if ($building['has_pool']): ?>
                                <i class="bi bi-water text-success me-2" style="font-size: 1.5rem;"></i>
                                <div><strong>Pool Available</strong></div>
                            <?php else: ?>
                                <i class="bi bi-water text-muted me-2" style="font-size: 1.5rem;"></i>
                                <div><span class="text-muted">No Pool</span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($building['facilities_notes']): ?>
                <div class="mt-3 p-3 bg-light rounded">
                    <strong>Additional Notes:</strong>
                    <p class="mb-0"><?= nl2br(h($building['facilities_notes'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Common Areas -->
        <div class="card card-round mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-layout-text-window"></i> Common Areas</h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addCommonAreaModal">
                    <i class="bi bi-plus-circle"></i> Add Common Area
                </button>
            </div>
            <div class="card-body">
                <?php if (!empty($commonAreas)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Area Name</th>
                                    <th>Type</th>
                                    <th>Floor</th>
                                    <th>Area (sqm)</th>
                                    <th>Capacity</th>
                                    <th>Status</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($commonAreas as $area): ?>
                                    <tr class="<?= empty($area['is_active']) ? 'table-secondary' : '' ?>">
                                        <td><strong><?= h($area['area_name']) ?></strong></td>
                                        <td>
                                            <span class="badge bg-info"><?= ucfirst(str_replace('_', ' ', $area['area_type'])) ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            if (!empty($area['floor_number'])) {
                                                $fn = (int)$area['floor_number'];
                                                $fallback = trim((string)($area['floor_name'] ?? ''));
                                                echo h($floorLabelByNumber[$fn] ?? ($fallback !== '' ? $fallback : ('Floor ' . $fn)));
                                            } else {
                                                echo 'Building-wide / Ground';
                                            }
                                            ?>
                                        </td>
                                        <td><?= $area['area_sqm'] ? number_format($area['area_sqm'], 2) . ' sqm' : '-' ?></td>
                                        <td><?= $area['capacity'] ?: '-' ?></td>
                                        <td>
                                            <?php if (!empty($area['is_active'])): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($area['description'] ?: '-') ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-secondary" onclick="editCommonArea(<?= htmlspecialchars(json_encode($area)) ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No common areas recorded. Click "Add Common Area" to add one.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Floor Plans -->
        <div class="card card-round mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-image"></i> Floor Plans</h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadFloorPlanModal">
                    <i class="bi bi-upload"></i> Upload Floor Plan
                </button>
            </div>
            <div class="card-body">
                <?php if (!empty($floorPlans)): ?>
                    <div class="row g-3">
                        <?php foreach ($floorPlans as $plan): ?>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title"><?= h($plan['plan_name']) ?></h6>
                                        <p class="card-text small text-muted mb-2">
                                            <?= $plan['floor_number'] ? 'Floor ' . $plan['floor_number'] : 'Building-Wide' ?>
                                            <?php if ($plan['is_primary']): ?>
                                                <span class="badge bg-primary ms-2">Primary</span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($plan['description']): ?>
                                            <p class="card-text small"><?= h($plan['description']) ?></p>
                                        <?php endif; ?>
                                        <p class="card-text small text-muted">
                                            <?= formatFileSize($plan['file_size']) ?>
                                            <?php if ($plan['uploaded_by_name']): ?>
                                                <br>Uploaded by: <?= h($plan['uploaded_by_name']) ?>
                                            <?php endif; ?>
                                            <br>Date: <?= date('Y-m-d', strtotime($plan['created_at'])) ?>
                                        </p>
                                        <div class="btn-group w-100" role="group">
                                            <a href="../../<?= h($plan['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            <a href="../../<?= h($plan['file_path']) ?>" download class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-download"></i> Download
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No floor plans uploaded. Click "Upload Floor Plan" to add one.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add Common Area Modal -->
        <div class="modal fade" id="addCommonAreaModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="building_common_area_add.php" id="commonAreaForm">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="building_id" value="<?= $buildingId ?>">
                        <input type="hidden" name="action" value="add" id="commonAreaAction">
                        <input type="hidden" name="area_id" id="commonAreaId">
                        <div class="modal-header">
                            <h5 class="modal-title">Add Common Area</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Area Name *</label>
                                <input type="text" class="form-control" name="area_name" id="commonAreaName" required maxlength="200" placeholder="e.g., Main Lobby, Pump Room B1">
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Area Type *</label>
                                    <select name="area_type" id="commonAreaType" class="form-select" required>
                                        <option value="lobby">Lobby</option>
                                        <option value="reception">Reception</option>
                                        <option value="corridor">Corridor</option>
                                        <option value="elevator">Elevator</option>
                                        <option value="staircase">Staircase</option>
                                        <option value="rooftop">Rooftop</option>
                                        <option value="garden">Garden</option>
                                        <option value="playground">Playground</option>
                                        <option value="parking">Parking</option>
                                        <option value="storage">Storage</option>
                                        <option value="swimming_pool">Swimming Pool</option>
                                        <option value="pump_room">Pump Room</option>
                                        <option value="electrical_room">Electrical Room</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Floor</label>
                                    <select name="floor_number" id="commonAreaFloor" class="form-select">
                                        <option value="">Building-wide / Ground (no floor)</option>
                                        <?php if (empty($floors)): ?>
                                            <option value="" disabled>No floors configured — use Manage Floors first</option>
                                        <?php else: ?>
                                            <?php foreach ($floors as $floor): ?>
                                                <option value="<?= (int)$floor['floor_number'] ?>">
                                                    <?= h($floorLabelByNumber[(int)$floor['floor_number']]) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <div class="form-text">Uses floors from Floors Management for this building.</div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Area (sqm)</label>
                                    <input type="number" step="0.01" min="0" class="form-control" name="area_sqm" id="commonAreaSqm" placeholder="Optional">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Capacity</label>
                                    <input type="number" min="0" class="form-control" name="capacity" id="commonAreaCapacity" placeholder="Max people if applicable">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" id="commonAreaDescription" rows="2" placeholder="Optional notes (access, location cues, etc.)"></textarea>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="commonAreaActive" value="1" checked>
                                <label class="form-check-label" for="commonAreaActive">Active (available for maintenance)</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add Area</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Floor Management Modal -->
        <div class="modal fade" id="floorManagementModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Manage Floors - <?= h($building['name']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <!-- CSRF Token for AJAX requests -->
                        <input type="hidden" id="modalCsrfToken" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <p class="mb-0">Configure floor names and numbers for this building.</p>
                            <button type="button" class="btn btn-sm btn-primary" onclick="showAddFloorForm()">
                                <i class="bi bi-plus-circle"></i> Add Floor
                            </button>
                        </div>
                        
                        <div id="floorsList" class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Floor #</th>
                                        <th>Floor Name</th>
                                        <th>Units</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="floorsTableBody">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-3">
                                            <i class="bi bi-arrow-repeat"></i> Loading floors...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Add/Edit Floor Form (hidden by default) -->
                        <div id="floorFormContainer" style="display: none;" class="mt-4 p-3 bg-light rounded">
                            <h6 id="floorFormTitle">Add Floor</h6>
                            <form id="floorForm">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="building_id" value="<?= $buildingId ?>">
                                <input type="hidden" name="floor_id" id="floorId">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Floor Number *</label>
                                        <input type="number" class="form-control" name="floor_number" id="floorNumber" min="1" required>
                                        <small class="form-text text-muted">Sequential number (1, 2, 3...)</small>
                                    </div>
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label">Floor Name</label>
                                        <input type="text" class="form-control" name="floor_name" id="floorName" placeholder="e.g., Ground Floor, Podium, Mezzanine, 1st Floor">
                                        <small class="form-text text-muted">Custom name for this floor (optional)</small>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary" id="floorSubmitBtn">Add Floor</button>
                                    <button type="button" class="btn btn-secondary" onclick="hideFloorForm()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload Floor Plan Modal -->
        <div class="modal fade" id="uploadFloorPlanModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="building_floor_plan_upload.php" enctype="multipart/form-data">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="building_id" value="<?= $buildingId ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Upload Floor Plan</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Plan Name *</label>
                                <input type="text" class="form-control" name="plan_name" required placeholder="e.g., Ground Floor Plan">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Floor Number</label>
                                <input type="number" class="form-control" name="floor_number" placeholder="Leave empty for building-wide plan">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">File *</label>
                                <input type="file" class="form-control" name="floor_plan_file" accept="image/*,.pdf" required>
                                <small class="form-text text-muted">Accepted formats: Images (JPG, PNG, etc.) or PDF</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="2"></textarea>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="isPrimary">
                                    <label class="form-check-label" for="isPrimary">
                                        Set as primary plan for this floor
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Upload</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            const buildingId = <?= $buildingId ?>;
            // CSRF token available globally
            const csrfToken = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>';
            
            // Get CSRF token helper
            function getCsrfToken() {
                // First try the global variable
                if (csrfToken) {
                    return csrfToken;
                }
                // Then try the modal token
                const modalToken = document.getElementById('modalCsrfToken');
                if (modalToken && modalToken.value) {
                    return modalToken.value;
                }
                // Try multiple ways to find the CSRF token
                let tokenInput = document.querySelector('input[name="_csrf"]');
                if (!tokenInput) {
                    tokenInput = document.querySelector('input[name="csrf_token"]');
                }
                if (!tokenInput) {
                    // Try to find it in any form on the page
                    tokenInput = document.querySelector('form input[name="_csrf"]');
                }
                if (!tokenInput) {
                    // Try in the floor form specifically
                    tokenInput = document.getElementById('floorForm')?.querySelector('input[name="_csrf"]');
                }
                const token = tokenInput ? tokenInput.value : '';
                if (!token) {
                    console.error('CSRF token not found. Available inputs:', Array.from(document.querySelectorAll('input[type="hidden"]')).map(i => i.name));
                }
                return token;
            }
            
            // Load floors list
            function loadFloors() {
                fetch(`ajax_floors.php?action=list&building_id=${buildingId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const tbody = document.getElementById('floorsTableBody');
                            if (data.floors.length === 0) {
                                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No floors configured. Click "Add Floor" to add one.</td></tr>';
                            } else {
                                tbody.innerHTML = data.floors.map((floor, index) => `
                                    <tr>
                                        <td><strong>${floor.floor_number}</strong></td>
                                        <td>${floor.name || '—'}</td>
                                        <td><span class="badge bg-info">${floor.units_count} unit${floor.units_count != 1 ? 's' : ''}</span></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-outline-secondary" onclick="moveFloor(${floor.id}, 'up', ${index})" title="Move Up" ${index === 0 ? 'disabled' : ''}>
                                                    <i class="bi bi-arrow-up"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-secondary" onclick="moveFloor(${floor.id}, 'down', ${index})" title="Move Down" ${index === data.floors.length - 1 ? 'disabled' : ''}>
                                                    <i class="bi bi-arrow-down"></i>
                                                </button>
                                            </div>
                                            <button class="btn btn-sm btn-outline-info ms-1" onclick="showMoveToPosition(${floor.id}, ${floor.floor_number}, ${data.floors.length})" title="Move to Position">
                                                <i class="bi bi-arrows-move"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary ms-1" onclick="editFloor(${floor.id}, ${floor.floor_number}, ${JSON.stringify(floor.name || '').replace(/"/g, '&quot;')})" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            ${floor.units_count == 0 ? `
                                                <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteFloor(${floor.id})" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            ` : '<span class="text-muted small ms-2">Has units</span>'}
                                        </td>
                                    </tr>
                                `).join('');
                            }
                        } else {
                            alert('Error loading floors: ' + data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error loading floors');
                    });
            }
            
            // Show add floor form
            function showAddFloorForm() {
                document.getElementById('floorFormContainer').style.display = 'block';
                document.getElementById('floorFormTitle').textContent = 'Add Floor';
                document.getElementById('floorSubmitBtn').textContent = 'Add Floor';
                document.getElementById('floorForm').reset();
                document.getElementById('floorId').value = '';
                document.getElementById('floorNumber').focus();
            }
            
            // Edit floor
            function editFloor(floorId, floorNumber, floorName) {
                document.getElementById('floorFormContainer').style.display = 'block';
                document.getElementById('floorFormTitle').textContent = 'Edit Floor';
                document.getElementById('floorSubmitBtn').textContent = 'Update Floor';
                document.getElementById('floorId').value = floorId;
                document.getElementById('floorNumber').value = floorNumber;
                document.getElementById('floorName').value = floorName;
                document.getElementById('floorNumber').focus();
            }
            
            // Hide floor form
            function hideFloorForm() {
                document.getElementById('floorFormContainer').style.display = 'none';
                document.getElementById('floorForm').reset();
            }
            
            // Move floor up or down (no confirmation for single moves)
            function moveFloor(floorId, direction, currentIndex) {
                const formData = new FormData();
                formData.append('action', 'move');
                formData.append('floor_id', floorId);
                formData.append('direction', direction);
                const token = getCsrfToken();
                if (!token) {
                    alert('Error: CSRF token not found. Please refresh the page.');
                    console.error('CSRF token missing. Available:', {
                        global: csrfToken,
                        modal: document.getElementById('modalCsrfToken')?.value,
                        forms: Array.from(document.querySelectorAll('input[name="_csrf"]')).map(i => ({id: i.id, value: i.value}))
                    });
                    return;
                }
                formData.append('_csrf', token);
                console.log('Moving floor:', {floorId, direction, token: token.substring(0, 10) + '...'});
                
                fetch('ajax_floors.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async response => {
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        return response.json();
                    } else {
                        // If not JSON, read as text to see what the error is
                        const text = await response.text();
                        console.error('Non-JSON response:', text);
                        throw new Error(text || 'Server returned non-JSON response');
                    }
                })
                .then(data => {
                    if (data.success) {
                        loadFloors();
                        // Also reload the page to update the floors list in the main view
                        setTimeout(() => location.reload(), 500);
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error moving floor: ' + error.message);
                });
            }
            
            // Show move to position dialog
            function showMoveToPosition(floorId, currentFloorNumber, totalFloors) {
                const newPosition = prompt(`Move floor to position (1-${totalFloors}):\n\nCurrent position: ${currentFloorNumber}`, currentFloorNumber);
                
                if (newPosition === null) {
                    return; // User cancelled
                }
                
                const targetPosition = parseInt(newPosition);
                if (isNaN(targetPosition) || targetPosition < 1 || targetPosition > totalFloors) {
                    alert('Invalid position. Please enter a number between 1 and ' + totalFloors);
                    return;
                }
                
                if (targetPosition === currentFloorNumber) {
                    return; // No change needed
                }
                
                if (!confirm(`Move this floor from position ${currentFloorNumber} to position ${targetPosition}?`)) {
                    return;
                }
                
                const formData = new FormData();
                formData.append('action', 'move_to_position');
                formData.append('floor_id', floorId);
                formData.append('target_position', targetPosition);
                formData.append('_csrf', getCsrfToken());
                
                fetch('ajax_floors.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async response => {
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        return response.json();
                    } else {
                        const text = await response.text();
                        console.error('Non-JSON response:', text);
                        throw new Error(text || 'Server returned non-JSON response');
                    }
                })
                .then(data => {
                    if (data.success) {
                        loadFloors();
                        // Also reload the page to update the floors list in the main view
                        setTimeout(() => location.reload(), 500);
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error moving floor: ' + error.message);
                });
            }
            
            // Delete floor
            function deleteFloor(floorId) {
                if (!confirm('Are you sure you want to delete this floor? This action cannot be undone.')) {
                    return;
                }
                
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('floor_id', floorId);
                formData.append('_csrf', getCsrfToken());
                
                fetch('ajax_floors.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadFloors();
                        // Also reload the page to update the floors list in the main view
                        setTimeout(() => location.reload(), 500);
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error deleting floor');
                });
            }
            
            // Handle floor form submission
            document.getElementById('floorForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const floorId = document.getElementById('floorId').value;
                formData.append('action', floorId ? 'update' : 'add');
                if (floorId) {
                    formData.append('floor_id', floorId);
                }
                
                fetch('ajax_floors.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        hideFloorForm();
                        loadFloors();
                        // Also reload the page to update the floors list in the main view
                        setTimeout(() => location.reload(), 500);
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error saving floor');
                });
            });
            
            function editCommonArea(area) {
                // Populate edit modal
                const modal = document.getElementById('addCommonAreaModal');
                const form = document.getElementById('commonAreaForm');
                document.getElementById('commonAreaName').value = area.area_name || '';
                document.getElementById('commonAreaType').value = area.area_type || 'other';
                const floorSelect = document.getElementById('commonAreaFloor');
                const floorVal = (area.floor_number === null || area.floor_number === undefined || area.floor_number === '')
                    ? ''
                    : String(area.floor_number);
                // If a legacy floor number is not in the list, keep it selectable temporarily
                if (floorVal !== '' && !Array.from(floorSelect.options).some(o => o.value === floorVal)) {
                    const o = document.createElement('option');
                    o.value = floorVal;
                    o.textContent = 'Floor ' + floorVal + ' (not in Floors Management)';
                    floorSelect.appendChild(o);
                }
                floorSelect.value = floorVal;
                document.getElementById('commonAreaSqm').value = area.area_sqm || '';
                document.getElementById('commonAreaCapacity').value = area.capacity || '';
                document.getElementById('commonAreaDescription').value = area.description || '';
                document.getElementById('commonAreaActive').checked = String(area.is_active) === '1' || area.is_active === true || area.is_active === 1;
                
                // Set action and ID
                document.getElementById('commonAreaAction').value = 'edit';
                document.getElementById('commonAreaId').value = area.id;
                
                // Change modal title and submit button
                modal.querySelector('.modal-title').textContent = 'Edit Common Area';
                modal.querySelector('button[type="submit"]').textContent = 'Update Area';
                
                new bootstrap.Modal(modal).show();
            }
            
            // Reset modal when closed
            document.getElementById('addCommonAreaModal').addEventListener('hidden.bs.modal', function() {
                const form = document.getElementById('commonAreaForm');
                form.reset();
                document.getElementById('commonAreaAction').value = 'add';
                document.getElementById('commonAreaId').value = '';
                document.getElementById('commonAreaActive').checked = true;
                this.querySelector('.modal-title').textContent = 'Add Common Area';
                this.querySelector('button[type="submit"]').textContent = 'Add Area';
            });
            
        </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

