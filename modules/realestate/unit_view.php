<?php
/**
 * Real Estate Module - Unit View
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/unit_public_listing_helper.php';
require_once __DIR__ . '/../../includes/property_share_helper.php';
require_once __DIR__ . '/../../includes/app_mobile_config_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
re_unit_public_ensure_schema($conn);
re_property_share_ensure_schema($conn);

$unitId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$unitId) {
    header('Location: units.php');
    exit;
}

// Get unit details
$stmt = $conn->prepare("
    SELECT u.*, 
           b.name as building_name, b.address as building_address,
           f.floor_number, f.name as floor_name
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_floors f ON f.id = u.floor_id
    WHERE u.id = ? AND u.company_id = ?
");
$stmt->execute([$unitId, $currentCompanyId]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    header('Location: units.php');
    exit;
}

// Get active lease
$stmt = $conn->prepare("
    SELECT l.*, t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone, t.email
    FROM re_leases l
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE l.company_id = ? AND l.status = 'active'
      AND (
          l.unit_id = ?
          OR EXISTS (
              SELECT 1
              FROM re_lease_units lu
              WHERE lu.lease_id = l.id
                AND lu.company_id = l.company_id
                AND lu.unit_id = ?
          )
      )
    ORDER BY CASE WHEN CURDATE() BETWEEN l.start_date AND l.end_date THEN 0 ELSE 1 END, l.start_date DESC, l.id DESC
    LIMIT 1
");
$stmt->execute([$currentCompanyId, $unitId, $unitId]);
$activeLease = $stmt->fetch(PDO::FETCH_ASSOC);

$shareMeta = null;
$mobileShareCfg = app_mobile_config_get($conn);
if (!empty($unit['publish_to_mobile']) && !empty($mobileShareCfg['property_share_enabled'])) {
    $code = re_property_share_ensure_for_unit($conn, (int)$currentCompanyId, (int)$unitId);
    if ($code) {
        $shareMeta = [
            'share_code' => $code,
            'share_url' => re_property_share_build_url($conn, $code),
        ];
    }
}

// Get unit status history
$stmt = $conn->prepare("
    SELECT h.*, u.username as changed_by_name
    FROM re_unit_status_history h
    LEFT JOIN user u ON u.id = h.changed_by
    WHERE h.unit_id = ?
    ORDER BY h.changed_at DESC
    LIMIT 10
");
$stmt->execute([$unitId]);
$statusHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
$publicMedia = re_unit_public_media_rows($conn, $currentCompanyId, $unitId);
$publicPhotos = array_values(array_filter($publicMedia, fn($m) => ($m['media_type'] ?? '') === 'photo'));
$publicPlans = array_values(array_filter($publicMedia, fn($m) => ($m['media_type'] ?? '') === 'floor_plan'));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Unit Details';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Unit <?= h($unit['unit_number']) ?></h1>
            <a href="units_add.php?id=<?= $unitId ?>" class="btn btn-primary">
                <i class="bi bi-pencil"></i> Edit Unit
            </a>
        </div>

        <div class="row">
            <!-- Unit Information -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Unit Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Building:</th>
                                <td><?= h($unit['building_name']) ?></td>
                            </tr>
                            <tr>
                                <th>Floor:</th>
                                <td>
                                    <?php 
                                    // Show only the floor name (custom name if available, otherwise "Floor X")
                                    if ($unit['floor_name'] && trim($unit['floor_name']) !== '') {
                                        echo h($unit['floor_name']);
                                    } else {
                                        echo 'Floor ' . $unit['floor_number'];
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Unit Number:</th>
                                <td><strong><?= h($unit['unit_number']) ?></strong></td>
                            </tr>
                            <tr>
                                <th>Premises Number:</th>
                                <td><strong><?= h($unit['premises_number'] ?? '-') ?></strong></td>
                            </tr>
                            <tr>
                                <th>Unit Type:</th>
                                <td><?= strtoupper($unit['unit_type']) ?></td>
                            </tr>
                            <?php if (!empty($unit['furniture_status'])): ?>
                            <tr>
                                <th>Furniture Status:</th>
                                <td>
                                    <span class="badge bg-<?= $unit['furniture_status'] == 'furnished' ? 'info' : 'secondary' ?>">
                                        <?= ucfirst($unit['furniture_status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($unit['parking_slot'])): ?>
                            <tr>
                                <th>Parking Slot:</th>
                                <td><strong><?= h($unit['parking_slot']) ?></strong></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Area:</th>
                                <td><?= $unit['area_sqm'] ? number_format($unit['area_sqm'], 2) . ' sqm' : '-' ?></td>
                            </tr>
                            <tr>
                                <th>Annual Rent:</th>
                                <td><?= $unit['annual_rent'] ? number_format($unit['annual_rent'], 2) . ' AED' : '-' ?></td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <?php
                                    $statusClass = [
                                        'vacant' => 'success',
                                        'occupied' => 'primary',
                                        'maintenance' => 'warning',
                                        'reserved' => 'info'
                                    ];
                                    $class = $statusClass[$unit['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $class ?>"><?= ucfirst($unit['status']) ?></span>
                                </td>
                            </tr>
                            <?php
                            $rm = $unit['rental_mode'] ?? 'long_term';
                            $rmLabels = ['long_term' => 'Long Term Only', 'short_term' => 'Short Term (ARS)', 'both' => 'Both (Long + Short)'];
                            $rmBadge  = ['long_term' => 'secondary', 'short_term' => 'info', 'both' => 'primary'];
                            ?>
                            <tr>
                                <th>Rental Mode:</th>
                                <td>
                                    <span class="badge bg-<?= $rmBadge[$rm] ?? 'secondary' ?>"><?= $rmLabels[$rm] ?? ucfirst($rm) ?></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Active Lease -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Active Lease</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($activeLease): ?>
                            <?php
                                $activeTenantName = (($activeLease['tenant_type'] ?? '') === 'company' && !empty($activeLease['company_name']))
                                    ? (string)$activeLease['company_name']
                                    : trim((string)($activeLease['first_name'] ?? '') . ' ' . (string)($activeLease['last_name'] ?? ''));
                            ?>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Tenant:</th>
                                    <td><?= h($activeTenantName ?: '-') ?></td>
                                </tr>
                                <tr>
                                    <th>Phone:</th>
                                    <td><?= h($activeLease['phone'] ?: '-') ?></td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td><?= h($activeLease['email'] ?: '-') ?></td>
                                </tr>
                                <tr>
                                    <th>Lease Number:</th>
                                    <td><?= h($activeLease['lease_number'] ?: 'L-' . $activeLease['id']) ?></td>
                                </tr>
                                <tr>
                                    <th>Start Date:</th>
                                    <td><?= date('Y-m-d', strtotime($activeLease['start_date'])) ?></td>
                                </tr>
                                <tr>
                                    <th>End Date:</th>
                                    <td><?= date('Y-m-d', strtotime($activeLease['end_date'])) ?></td>
                                </tr>
                                <tr>
                                    <th>Monthly Rent:</th>
                                    <td><strong><?= number_format($activeLease['monthly_rent'], 2) ?> AED</strong></td>
                                </tr>
                            </table>
                            <a href="lease_view.php?id=<?= $activeLease['id'] ?>" class="btn btn-sm btn-outline-primary">
                                View Lease Details
                            </a>
                        <?php else: ?>
                            <p class="text-muted">No active lease</p>
                            <a href="lease_add.php?unit_id=<?= $unitId ?>" class="btn btn-sm btn-primary">
                                Create New Lease
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Public Listing Preview -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-phone"></i> Mobile Public Listing Preview</h5>
                <div>
                    <?php if (!empty($unit['publish_to_mobile'])): ?>
                        <span class="badge bg-success">Published to Mobile</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Not Published</span>
                    <?php endif; ?>
                    <span class="badge bg-<?= h(re_unit_public_status_badge((string)($unit['marketing_status'] ?? 'ready_to_move'))) ?>">
                        <?= h(re_unit_public_status_label((string)($unit['marketing_status'] ?? 'ready_to_move'))) ?>
                    </span>
                    <?php if (!empty($unit['featured'])): ?><span class="badge bg-warning text-dark">Featured</span><?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-5 mb-3">
                        <?php if (!empty($publicPhotos)): ?>
                            <img src="<?= h(re_unit_public_media_url($publicPhotos[0]['file_path'] ?? '')) ?>" class="img-fluid rounded shadow-sm" style="height:260px;width:100%;object-fit:cover;" alt="Primary public listing photo">
                        <?php else: ?>
                            <div class="bg-light rounded d-flex align-items-center justify-content-center text-muted" style="height:260px;">
                                <div class="text-center">
                                    <i class="bi bi-image fs-1 d-block mb-2"></i>
                                    No public photos uploaded
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-7">
                        <h4><?= h($unit['listing_title'] ?: ('Unit ' . $unit['unit_number'])) ?></h4>
                        <p class="text-muted mb-2"><?= h($unit['building_name']) ?><?= !empty($unit['building_address']) ? ' · ' . h($unit['building_address']) : '' ?></p>
                        <div class="h5 text-primary mb-3"><?= !empty($unit['annual_rent']) ? number_format((float)$unit['annual_rent'], 0) . ' AED / year' : 'Rent not set' ?></div>
                        <div class="row g-2 mb-3">
                            <div class="col-6 col-md-3"><small class="text-muted d-block">Beds</small><strong><?= h($unit['bedrooms'] ?? '-') ?></strong></div>
                            <div class="col-6 col-md-3"><small class="text-muted d-block">Baths</small><strong><?= h($unit['bathrooms'] ?? '-') ?></strong></div>
                            <div class="col-6 col-md-3"><small class="text-muted d-block">Size</small><strong><?= !empty($unit['unit_size_sqft']) ? number_format((float)$unit['unit_size_sqft']) . ' sqft' : '-' ?></strong></div>
                            <div class="col-6 col-md-3"><small class="text-muted d-block">Cheques</small><strong><?= h($unit['cheques_count'] ?? '-') ?></strong></div>
                        </div>
                        <?php if (!empty($unit['listing_description'])): ?>
                            <p><?= nl2br(h($unit['listing_description'])) ?></p>
                        <?php endif; ?>
                        <?php $amenities = re_unit_public_parse_amenities($unit['amenities_json'] ?? ''); ?>
                        <?php if (!empty($amenities)): ?>
                            <div class="mb-3">
                                <?php foreach ($amenities as $a): ?>
                                    <span class="badge bg-light text-dark border me-1 mb-1"><?= h($a) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (!empty($unit['virtual_tour_url'])): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= h($unit['virtual_tour_url']) ?>"><i class="bi bi-badge-3d"></i> Virtual Tour</a><?php endif; ?>
                            <?php if (!empty($unit['map_url'])): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= h($unit['map_url']) ?>"><i class="bi bi-geo-alt"></i> Map</a><?php endif; ?>
                            <?php if (!empty($publicPlans)): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= h(re_unit_public_media_url($publicPlans[0]['file_path'] ?? '')) ?>"><i class="bi bi-file-earmark-richtext"></i> Floor Plan</a><?php endif; ?>
                            <a class="btn btn-sm btn-primary" href="units_add.php?id=<?= (int)$unitId ?>#publishToMobile"><i class="bi bi-pencil"></i> Edit Listing</a>
                        </div>
                        <?php if (!empty($shareMeta['share_url'])): ?>
                            <div class="mt-3 p-3 bg-light rounded border">
                                <label class="form-label small text-muted mb-1"><i class="bi bi-share"></i> Public Share Link</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control font-monospace" id="unitShareUrl" readonly value="<?= h($shareMeta['share_url']) ?>">
                                    <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('unitShareUrl').value)">Copy</button>
                                </div>
                                <small class="text-muted">Opens the Customer App Unit Details when installed. Unpublished / occupied units resolve as unavailable (no listing data).</small>
                            </div>
                        <?php elseif (!empty($unit['publish_to_mobile']) && empty($mobileShareCfg['property_share_enabled'])): ?>
                            <p class="small text-muted mt-3 mb-0">Property sharing is disabled in Settings → Mobile App Management.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (count($publicPhotos) > 1): ?>
                    <div class="row g-2 mt-2">
                        <?php foreach (array_slice($publicPhotos, 1, 6) as $p): ?>
                            <div class="col-6 col-md-2"><img src="<?= h(re_unit_public_media_url($p['file_path'] ?? '')) ?>" class="img-fluid rounded" style="height:90px;width:100%;object-fit:cover;" alt="Gallery photo"></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status History -->
        <?php if (!empty($statusHistory)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Status History</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Old Status</th>
                                <th>New Status</th>
                                <th>Changed By</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($statusHistory as $history): ?>
                                <tr>
                                    <td><?= date('Y-m-d H:i', strtotime($history['changed_at'])) ?></td>
                                    <td><?= h($history['old_status'] ?: '-') ?></td>
                                    <td><?= h($history['new_status'] ?: '-') ?></td>
                                    <td><?= h($history['changed_by_name'] ?: 'System') ?></td>
                                    <td><?= h($history['reason'] ?: '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

