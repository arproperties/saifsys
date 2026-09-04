<?php
/**
 * Real Estate Module - Add/Edit Unit
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/unit_public_listing_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
re_unit_public_ensure_schema($conn);

$unitId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$unit = null;
$success = '';
$error = '';
$publicMedia = [];

// Get buildings and floors
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

function re_unit_type_options(PDO $conn): array {
    $fallback = [
        'studio', '1br', '2br', '3br', '4br', 'penthouse', 'commercial',
        'office', 'big_electrical_room', 'pump_room', 'store_room', 'dining_hall', 'big_kitchen',
        'labour_room', 'labour_bathroom',
    ];

    try {
        $stmt = $conn->query("SHOW COLUMNS FROM re_units LIKE 'unit_type'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $type = (string)($column['Type'] ?? '');
        if (preg_match("/^enum\\((.*)\\)$/i", $type, $m)) {
            $values = str_getcsv($m[1], ',', "'");
            $values = array_values(array_filter(array_map('trim', $values), static fn($v) => $v !== ''));
            if (!empty($values)) {
                return $values;
            }
        }
    } catch (Throwable $e) {
        // Keep the form usable if schema metadata cannot be read.
    }

    return $fallback;
}

function re_unit_type_label(string $type): string {
    $labels = [
        'studio' => 'Studio',
        '1br' => '1BR',
        '2br' => '2BR',
        '3br' => '3BR',
        '4br' => '4BR',
        'penthouse' => 'Penthouse',
        'commercial' => 'Commercial',
        'office' => 'Office',
        'big_electrical_room' => 'Big Electrical Room',
        'pump_room' => 'Pump Room',
        'store_room' => 'Store Room',
        'dining_hall' => 'Dining Hall',
        'big_kitchen' => 'Big Kitchen',
        'labour_room' => 'Labour Room',
        'labour_bathroom' => 'Labour Bathroom',
        'labour room' => 'Labour Room',
        'labour bathroom' => 'Labour Bathroom',
    ];

    return $labels[$type] ?? ucwords(str_replace(['_', '-'], ' ', $type));
}

$unitTypeOptions = re_unit_type_options($conn);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $buildingId = (int)$_POST['building_id'];
    $floorId = !empty($_POST['floor_id']) ? (int)$_POST['floor_id'] : null;
    $unitNumber = trim($_POST['unit_number'] ?? '');
    $premisesNumber = trim($_POST['premises_number'] ?? '');
    $unitType = $_POST['unit_type'] ?? 'studio';
    $areaSqm = !empty($_POST['area_sqm']) ? (float)$_POST['area_sqm'] : null;
    $status = $_POST['status'] ?? 'vacant';
    $annualRent = !empty($_POST['annual_rent']) ? (float)$_POST['annual_rent'] : null;
    $parkingSlot = trim($_POST['parking_slot'] ?? '');
    $furnitureStatus = $_POST['furniture_status'] ?? null;
    $rentalMode = $_POST['rental_mode'] ?? 'long_term';
    $publishToMobile = !empty($_POST['publish_to_mobile']) ? 1 : 0;
    $listingTitle = trim($_POST['listing_title'] ?? '');
    $listingDescription = trim($_POST['listing_description'] ?? '');
    $marketingStatus = $_POST['marketing_status'] ?? 'ready_to_move';
    $chequesCount = (string)($_POST['cheques_count'] ?? '') !== '' ? (int)$_POST['cheques_count'] : null;
    $securityDeposit = (string)($_POST['security_deposit'] ?? '') !== '' ? (float)$_POST['security_deposit'] : null;
    $commission = (string)($_POST['commission'] ?? '') !== '' ? (float)$_POST['commission'] : null;
    $unitSizeSqft = (string)($_POST['unit_size_sqft'] ?? '') !== '' ? (float)$_POST['unit_size_sqft'] : null;
    $bedrooms = (string)($_POST['bedrooms'] ?? '') !== '' ? (int)$_POST['bedrooms'] : null;
    $bathrooms = (string)($_POST['bathrooms'] ?? '') !== '' ? (float)$_POST['bathrooms'] : null;
    $parkingCount = (string)($_POST['parking_count'] ?? '') !== '' ? (int)$_POST['parking_count'] : null;
    $balcony = !empty($_POST['balcony']) ? 1 : 0;
    $amenitiesJson = re_unit_public_normalize_amenities((string)($_POST['amenities'] ?? ''));
    $virtualTourUrl = trim($_POST['virtual_tour_url'] ?? '');
    $mapUrl = trim($_POST['map_url'] ?? '');
    $latitude = (string)($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
    $longitude = (string)($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;
    $whatsappContact = trim($_POST['whatsapp_contact'] ?? '');
    $callContact = trim($_POST['call_contact'] ?? '');
    $featured = !empty($_POST['featured']) ? 1 : 0;
    $sortOrder = (string)($_POST['sort_order'] ?? '') !== '' ? (int)$_POST['sort_order'] : 0;
    if (!in_array($marketingStatus, ['ready_to_move', 'under_maintenance', 'coming_soon', 'reserved'], true)) {
        $marketingStatus = 'ready_to_move';
    }
    
    // Validation
    if (!$buildingId || !$unitNumber) {
        $error = "Building and Unit Number are required";
    } elseif (!$floorId) {
        $error = "Floor is required";
    } elseif ($premisesNumber) {
        // Check if premises_number is unique (excluding current unit if editing)
        $checkStmt = $conn->prepare("
            SELECT id FROM re_units 
            WHERE premises_number = ? AND company_id = ? AND id != ?
        ");
        $checkStmt->execute([$premisesNumber, $currentCompanyId, $unitId ?: 0]);
        if ($checkStmt->fetch()) {
            $error = "Premises Number already exists. Each unit must have a unique premises number.";
        }
    }
    
    if (!$error) {
        if ($unitId) {
            // Update
            $stmt = $conn->prepare("
                UPDATE re_units 
                SET building_id = ?, floor_id = ?, unit_number = ?, premises_number = ?, unit_type = ?, 
                    area_sqm = ?, status = ?, annual_rent = ?, parking_slot = ?, furniture_status = ?,
                    rental_mode = ?, publish_to_mobile = ?, listing_title = ?, listing_description = ?,
                    marketing_status = ?, cheques_count = ?, security_deposit = ?, commission = ?,
                    unit_size_sqft = ?, bedrooms = ?, bathrooms = ?, parking_count = ?, balcony = ?,
                    amenities_json = ?, virtual_tour_url = ?, map_url = ?, latitude = ?, longitude = ?,
                    whatsapp_contact = ?, call_contact = ?, featured = ?, sort_order = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $buildingId, $floorId, $unitNumber, $premisesNumber ?: null, $unitType, 
                $areaSqm, $status, $annualRent, $parkingSlot ?: null, $furnitureStatus,
                $rentalMode, $publishToMobile, $listingTitle ?: null, $listingDescription ?: null,
                $marketingStatus, $chequesCount, $securityDeposit, $commission,
                $unitSizeSqft, $bedrooms, $bathrooms, $parkingCount, $balcony,
                $amenitiesJson, $virtualTourUrl ?: null, $mapUrl ?: null, $latitude, $longitude,
                $whatsappContact ?: null, $callContact ?: null, $featured, $sortOrder,
                $unitId, $currentCompanyId
            ]);
            $success = "Unit updated successfully";
            try {
                require_once __DIR__ . '/../../includes/audit_bridge.php';
                audit_bridge_re_ops(
                    $conn,
                    (int)$currentCompanyId,
                    'unit_updated',
                    're_units',
                    (int)$unitId,
                    (string)$unitNumber,
                    'Updated unit ' . $unitNumber,
                    null,
                    ['unit_number' => $unitNumber, 'status' => $status, 'unit_type' => $unitType],
                    (int)($_SESSION['user']['id'] ?? 0) ?: null
                );
            } catch (Throwable $e) {
                error_log('units_add audit: ' . $e->getMessage());
            }
            if ($publishToMobile) {
                try {
                    require_once __DIR__ . '/../../includes/property_share_helper.php';
                    re_property_share_ensure_for_unit($conn, (int)$currentCompanyId, (int)$unitId);
                } catch (Throwable $e) {
                    error_log('units_add share ensure: ' . $e->getMessage());
                }
            }
        } else {
            // Insert
            $stmt = $conn->prepare("
                INSERT INTO re_units 
                (company_id, building_id, floor_id, unit_number, premises_number, unit_type, area_sqm, status, annual_rent, parking_slot, furniture_status, rental_mode,
                 publish_to_mobile, listing_title, listing_description, marketing_status, cheques_count, security_deposit, commission, unit_size_sqft, bedrooms, bathrooms,
                 parking_count, balcony, amenities_json, virtual_tour_url, map_url, latitude, longitude, whatsapp_contact, call_contact, featured, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $currentCompanyId, $buildingId, $floorId, $unitNumber, $premisesNumber ?: null, 
                $unitType, $areaSqm, $status, $annualRent, $parkingSlot ?: null, $furnitureStatus,
                $rentalMode, $publishToMobile, $listingTitle ?: null, $listingDescription ?: null,
                $marketingStatus, $chequesCount, $securityDeposit, $commission, $unitSizeSqft,
                $bedrooms, $bathrooms, $parkingCount, $balcony, $amenitiesJson,
                $virtualTourUrl ?: null, $mapUrl ?: null, $latitude, $longitude,
                $whatsappContact ?: null, $callContact ?: null, $featured, $sortOrder
            ]);
            $newUnitId = (int)$conn->lastInsertId();
            $success = "Unit added successfully";
            try {
                require_once __DIR__ . '/../../includes/audit_bridge.php';
                audit_bridge_re_ops(
                    $conn,
                    (int)$currentCompanyId,
                    'unit_created',
                    're_units',
                    $newUnitId,
                    (string)$unitNumber,
                    'Added unit ' . $unitNumber,
                    null,
                    ['unit_number' => $unitNumber, 'status' => $status, 'unit_type' => $unitType],
                    (int)($_SESSION['user']['id'] ?? 0) ?: null
                );
            } catch (Throwable $e) {
                error_log('units_add audit: ' . $e->getMessage());
            }
            if ($publishToMobile && $newUnitId > 0) {
                try {
                    require_once __DIR__ . '/../../includes/property_share_helper.php';
                    re_property_share_ensure_for_unit($conn, (int)$currentCompanyId, $newUnitId);
                } catch (Throwable $e) {
                    error_log('units_add share ensure: ' . $e->getMessage());
                }
            }
            header('Location: units.php');
            exit;
        }
    }
}

// Load unit if editing
if ($unitId) {
    $stmt = $conn->prepare("SELECT * FROM re_units WHERE id = ? AND company_id = ?");
    $stmt->execute([$unitId, $currentCompanyId]);
    $unit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$unit) {
        header('Location: units.php');
        exit;
    }
    $publicMedia = re_unit_public_media_rows($conn, $currentCompanyId, $unitId);
}

// Get floors for selected building
$floors = [];
if ($unit && $unit['building_id']) {
    $stmt = $conn->prepare("SELECT id, floor_number, name FROM re_floors WHERE building_id = ? ORDER BY floor_number");
    $stmt->execute([$unit['building_id']]);
    $floors = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = ($unitId ? 'Edit' : 'Add') . ' Unit';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <h1><?= $unitId ? 'Edit' : 'Add' ?> Unit</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" id="unitForm">
                    <?php csrf_field(); ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Building *</label>
                            <select name="building_id" class="form-select" id="buildingSelect" required>
                                <option value="">-- Select Building --</option>
                                <?php foreach ($buildings as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= ($unit && $unit['building_id'] == $b['id']) ? 'selected' : '' ?>>
                                        <?= h($b['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Floor *</label>
                            <select name="floor_id" class="form-select" id="floorSelect" required>
                                <option value="">-- Select Floor --</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Unit Number *</label>
                            <input type="text" class="form-control" name="unit_number" value="<?= $unit ? h($unit['unit_number']) : '' ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Premises Number *</label>
                            <input type="text" class="form-control" name="premises_number" value="<?= $unit ? h($unit['premises_number'] ?? '') : '' ?>" required>
                            <small class="form-text text-muted">Unique premises number for this unit</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Unit Type *</label>
                            <select name="unit_type" class="form-select" required>
                                <?php foreach ($unitTypeOptions as $type): ?>
                                    <option value="<?= h($type) ?>" <?= ($unit && ($unit['unit_type'] ?? '') === $type) ? 'selected' : '' ?>>
                                        <?= h(re_unit_type_label($type)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Furniture Status</label>
                            <select name="furniture_status" class="form-select">
                                <option value="">-- Select Status --</option>
                                <option value="furnished" <?= ($unit && $unit['furniture_status'] == 'furnished') ? 'selected' : '' ?>>Furnished</option>
                                <option value="unfurnished" <?= ($unit && $unit['furniture_status'] == 'unfurnished') ? 'selected' : '' ?>>Unfurnished</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Area (sqm)</label>
                            <input type="number" step="0.01" class="form-control" name="area_sqm" value="<?= $unit ? h($unit['area_sqm']) : '' ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Annual Rent (AED)</label>
                            <input type="number" step="0.01" class="form-control" name="annual_rent" value="<?= $unit ? h($unit['annual_rent']) : '' ?>">
                            <small class="form-text text-muted">Enter the total rent per year for this unit</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="vacant" <?= ($unit && $unit['status'] == 'vacant') ? 'selected' : '' ?>>Vacant</option>
                                <option value="occupied" <?= ($unit && $unit['status'] == 'occupied') ? 'selected' : '' ?>>Occupied</option>
                                <option value="maintenance" <?= ($unit && $unit['status'] == 'maintenance') ? 'selected' : '' ?>>Maintenance</option>
                                <option value="reserved" <?= ($unit && $unit['status'] == 'reserved') ? 'selected' : '' ?>>Reserved</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Parking Slot</label>
                            <input type="text" class="form-control" name="parking_slot" value="<?= $unit ? h($unit['parking_slot'] ?? '') : '' ?>" placeholder="e.g., P-101, Slot A-12">
                            <small class="form-text text-muted">Parking slot number assigned to this unit</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Rental Mode</label>
                            <select name="rental_mode" class="form-select">
                                <option value="long_term" <?= ($unit && ($unit['rental_mode'] ?? '') == 'long_term') ? 'selected' : '' ?>>Long Term Only</option>
                                <option value="short_term" <?= ($unit && ($unit['rental_mode'] ?? '') == 'short_term') ? 'selected' : '' ?>>Short Term Only (ARS)</option>
                                <option value="both" <?= ($unit && ($unit['rental_mode'] ?? '') == 'both') ? 'selected' : '' ?>>Both (Long + Short Term)</option>
                            </select>
                            <small class="form-text text-muted">Set to "Short Term" or "Both" to make this unit available in ARS Home Rentals</small>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="mb-1"><i class="bi bi-phone"></i> Mobile Public Listing</h5>
                            <p class="text-muted mb-0">Controls the future Find Your Home long-term rental listing. This is separate from ARS short-term stays and tenant portal.</p>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="publish_to_mobile" id="publishToMobile" value="1" <?= $unit && !empty($unit['publish_to_mobile']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="publishToMobile">Publish to mobile</label>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Listing Title</label>
                            <input type="text" class="form-control" name="listing_title" value="<?= $unit ? h($unit['listing_title'] ?? '') : '' ?>" placeholder="e.g., Creek Palace 2 Bed Apartment">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Marketing Status</label>
                            <?php $ms = $unit['marketing_status'] ?? 'ready_to_move'; ?>
                            <select name="marketing_status" class="form-select">
                                <option value="ready_to_move" <?= $ms === 'ready_to_move' ? 'selected' : '' ?>>Ready to Move</option>
                                <option value="under_maintenance" <?= $ms === 'under_maintenance' ? 'selected' : '' ?>>Under Maintenance</option>
                                <option value="coming_soon" <?= $ms === 'coming_soon' ? 'selected' : '' ?>>Coming Soon</option>
                                <option value="reserved" <?= $ms === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Listing Description</label>
                        <textarea class="form-control" name="listing_description" rows="4" placeholder="Premium public description for mobile listing"><?= $unit ? h($unit['listing_description'] ?? '') : '' ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Cheques Count</label>
                            <input type="number" min="1" max="12" class="form-control" name="cheques_count" value="<?= $unit ? h($unit['cheques_count'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Security Deposit (AED)</label>
                            <input type="number" step="0.01" class="form-control" name="security_deposit" value="<?= $unit ? h($unit['security_deposit'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Commission (AED)</label>
                            <input type="number" step="0.01" class="form-control" name="commission" value="<?= $unit ? h($unit['commission'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Sort Order</label>
                            <input type="number" class="form-control" name="sort_order" value="<?= $unit ? h($unit['sort_order'] ?? 0) : '0' ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Size (sqft)</label>
                            <input type="number" step="0.01" class="form-control" name="unit_size_sqft" value="<?= $unit ? h($unit['unit_size_sqft'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Bedrooms</label>
                            <input type="number" min="0" max="20" class="form-control" name="bedrooms" value="<?= $unit ? h($unit['bedrooms'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Bathrooms</label>
                            <input type="number" step="0.5" min="0" max="20" class="form-control" name="bathrooms" value="<?= $unit ? h($unit['bathrooms'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Parking Count</label>
                            <input type="number" min="0" max="20" class="form-control" name="parking_count" value="<?= $unit ? h($unit['parking_count'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-2 mb-3 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="balcony" value="1" id="balcony" <?= $unit && !empty($unit['balcony']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="balcony">Balcony</label>
                            </div>
                        </div>
                        <div class="col-md-2 mb-3 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="featured" value="1" id="featured" <?= $unit && !empty($unit['featured']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="featured">Featured</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Amenities</label>
                        <?php $amenityText = $unit ? implode(', ', re_unit_public_parse_amenities($unit['amenities_json'] ?? '')) : ''; ?>
                        <input type="text" class="form-control" name="amenities" value="<?= h($amenityText) ?>" placeholder="Pool, Gym, Concierge, Sea View">
                        <small class="form-text text-muted">Comma separated. Stored as structured JSON for future mobile API use.</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Virtual Tour URL</label>
                            <input type="url" class="form-control" name="virtual_tour_url" value="<?= $unit ? h($unit['virtual_tour_url'] ?? '') : '' ?>" placeholder="https://...">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Map URL</label>
                            <input type="url" class="form-control" name="map_url" value="<?= $unit ? h($unit['map_url'] ?? '') : '' ?>" placeholder="Google Maps or Apple Maps link">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Latitude</label>
                            <input type="number" step="0.00000001" class="form-control" name="latitude" value="<?= $unit ? h($unit['latitude'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Longitude</label>
                            <input type="number" step="0.00000001" class="form-control" name="longitude" value="<?= $unit ? h($unit['longitude'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">WhatsApp Contact</label>
                            <input type="text" class="form-control" name="whatsapp_contact" value="<?= $unit ? h($unit['whatsapp_contact'] ?? '') : '' ?>" placeholder="+971...">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Call Contact</label>
                            <input type="text" class="form-control" name="call_contact" value="<?= $unit ? h($unit['call_contact'] ?? '') : '' ?>" placeholder="+971...">
                        </div>
                    </div>

                    <?php if ($unitId): ?>
                    <div class="card border-0 bg-light mb-3">
                        <div class="card-body">
                            <h6 class="mb-3"><i class="bi bi-images"></i> Public Listing Media</h6>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Upload Photos</label>
                                    <div class="input-group">
                                        <input type="file" class="form-control" id="publicPhotosInput" multiple accept="image/jpeg,image/png,image/webp">
                                        <button class="btn btn-outline-primary" type="button" onclick="uploadPublicMedia('photo')">Upload</button>
                                    </div>
                                    <small class="text-muted">JPG, PNG, WebP. First photo becomes primary automatically.</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Upload Floor Plan</label>
                                    <div class="input-group">
                                        <input type="file" class="form-control" id="floorPlanInput" accept="image/jpeg,image/png,image/webp,application/pdf">
                                        <button class="btn btn-outline-primary" type="button" onclick="uploadPublicMedia('floor_plan')">Upload</button>
                                    </div>
                                    <small class="text-muted">Image or PDF. Latest floor plan is saved to the unit profile.</small>
                                </div>
                            </div>
                            <?php if (empty($publicMedia)): ?>
                                <p class="text-muted mb-0">No public media uploaded yet.</p>
                            <?php else: ?>
                                <div class="row g-3">
                                    <?php foreach ($publicMedia as $m): ?>
                                        <div class="col-md-3">
                                            <div class="border rounded p-2 h-100 bg-white">
                                                <?php if (($m['media_type'] ?? '') === 'photo'): ?>
                                                    <img src="<?= h(re_unit_public_media_url($m['file_path'] ?? '')) ?>" alt="Unit photo" class="img-fluid rounded mb-2" style="height:120px;width:100%;object-fit:cover;">
                                                <?php else: ?>
                                                    <div class="d-flex align-items-center justify-content-center bg-light rounded mb-2" style="height:120px;">
                                                        <i class="bi bi-file-earmark-richtext fs-1 text-muted"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="small fw-semibold"><?= h(ucwords(str_replace('_', ' ', $m['media_type'] ?? 'media'))) ?> <?= !empty($m['is_primary']) ? '<span class="badge bg-success ms-1">Primary</span>' : '' ?></div>
                                                <div class="btn-group btn-group-sm mt-2">
                                                    <?php if (($m['media_type'] ?? '') === 'photo' && empty($m['is_primary'])): ?>
                                                        <button type="button" class="btn btn-outline-success" onclick="mediaAction('primary', <?= (int)$m['id'] ?>)">Primary</button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-outline-danger" onclick="mediaAction('delete', <?= (int)$m['id'] ?>)">Delete</button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                        <div class="alert alert-info">Save the unit first, then edit it to upload public listing photos and floor plans.</div>
                    <?php endif; ?>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary"><?= $unitId ? 'Update' : 'Add' ?> Unit</button>
                        <a href="units.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Load floors when building changes
        document.getElementById('buildingSelect').addEventListener('change', function() {
            const buildingId = this.value;
            const floorSelect = document.getElementById('floorSelect');
            floorSelect.innerHTML = '<option value="">-- No Floor --</option>';
            
            if (buildingId) {
                fetch(`ajax_get_floors.php?building_id=${buildingId}`)
                    .then(r => r.json())
                    .then(floors => {
                        floors.forEach(floor => {
                            const option = document.createElement('option');
                            option.value = floor.id;
                            // Display only the floor name (custom name if available, otherwise "Floor X")
                            if (floor.name && floor.name.trim() !== '') {
                                option.textContent = floor.name;
                            } else {
                                option.textContent = `Floor ${floor.floor_number}`;
                            }
                            floorSelect.appendChild(option);
                        });
                        // Make sure floor is required
                        floorSelect.required = true;
                    })
                    .catch(e => console.error('Error loading floors:', e));
            }
        });
        
        <?php if ($unit && $unit['building_id']): ?>
        // Load floors on page load
        document.getElementById('buildingSelect').dispatchEvent(new Event('change'));
        setTimeout(() => {
            document.getElementById('floorSelect').value = <?= $unit['floor_id'] ?: 'null' ?>;
        }, 500);
        <?php endif; ?>

        <?php if ($unitId): ?>
        function csrfValue() {
            const el = document.querySelector('input[name="_csrf"]');
            return el ? el.value : '';
        }

        function uploadPublicMedia(type) {
            const input = type === 'photo' ? document.getElementById('publicPhotosInput') : document.getElementById('floorPlanInput');
            if (!input || !input.files || input.files.length === 0) {
                alert('Please choose a file first.');
                return;
            }
            const fd = new FormData();
            fd.append('_csrf', csrfValue());
            fd.append('unit_id', '<?= (int)$unitId ?>');
            fd.append('media_type', type);
            Array.from(input.files).forEach(file => fd.append('media[]', file));
            fetch('unit_public_media_upload.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.error || 'Upload failed');
                    window.location.reload();
                })
                .catch(err => alert(err.message || 'Upload failed'));
        }

        function mediaAction(action, mediaId) {
            if (action === 'delete' && !confirm('Delete this media file?')) return;
            const fd = new FormData();
            fd.append('_csrf', csrfValue());
            fd.append('unit_id', '<?= (int)$unitId ?>');
            fd.append('media_id', mediaId);
            fd.append('action', action);
            fetch('unit_public_media_delete.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.error || 'Action failed');
                    window.location.reload();
                })
                .catch(err => alert(err.message || 'Action failed'));
        }
        <?php endif; ?>
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

