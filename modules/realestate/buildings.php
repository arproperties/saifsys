<?php
/**
 * Real Estate Module - Buildings Management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/re_building_photo_helper.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
re_building_photo_ensure_schema($conn);
re_building_photo_ensure_htaccess();

/**
 * Ensure that re_floors has rows for each floor number from 1..$totalFloors
 * for the given building. This keeps the floors dropdown in Units in sync
 * with the building's configured total_floors.
 */
function syncBuildingFloors(PDO $conn, int $buildingId, ?int $totalFloors): void
{
    if ($totalFloors === null || $totalFloors <= 0) {
        return;
    }

    // Get existing floor numbers for this building
    $stmt = $conn->prepare("SELECT floor_number FROM re_floors WHERE building_id = ? ORDER BY floor_number");
    $stmt->execute([$buildingId]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $existing = array_map('intval', $existing);

    // Insert any missing floors from 1..$totalFloors
    $insert = $conn->prepare("
        INSERT INTO re_floors (building_id, floor_number, name, total_units)
        VALUES (?, ?, ?, 0)
    ");

    for ($i = 1; $i <= $totalFloors; $i++) {
        if (!in_array($i, $existing, true)) {
            // Don't auto-generate names - let user customize them
            $insert->execute([$buildingId, $i, null]);
        }
    }

    // NOTE: We deliberately do NOT delete floors above total_floors here
    // to avoid impacting existing units that might already be assigned.
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $name = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $dewaPremises = trim($_POST['dewa_premises'] ?? '');
            $plotNumber = trim($_POST['plot_number'] ?? '');
            $landlordName = trim($_POST['landlord_name'] ?? '');
            $managedBy = !empty($_POST['managed_by']) ? (int)$_POST['managed_by'] : null;
            $totalFloors = !empty($_POST['total_floors']) ? (int)$_POST['total_floors'] : null;
            $totalUnits = !empty($_POST['total_units']) ? (int)$_POST['total_units'] : null;
            $hasParking = isset($_POST['has_parking']) ? 1 : 0;
            $parkingSpaces = !empty($_POST['parking_spaces']) ? (int)$_POST['parking_spaces'] : null;
            $hasGym = isset($_POST['has_gym']) ? 1 : 0;
            $hasPool = isset($_POST['has_pool']) ? 1 : 0;
            $facilitiesNotes = trim($_POST['facilities_notes'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            if ($name) {
                if ($_POST['action'] === 'add') {
                    $stmt = $conn->prepare("
                        INSERT INTO re_buildings 
                        (company_id, name, address, dewa_premises, plot_number, landlord_name, managed_by, total_floors, total_units, has_parking, parking_spaces, has_gym, has_pool, facilities_notes, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$currentCompanyId, $name, $address, $dewaPremises ?: null, $plotNumber ?: null, $landlordName ?: null, $managedBy, $totalFloors, $totalUnits, $hasParking, $parkingSpaces, $hasGym, $hasPool, $facilitiesNotes ?: null, $isActive]);
                    $newBuildingId = (int)$conn->lastInsertId();
                    if ($newBuildingId) {
                        syncBuildingFloors($conn, $newBuildingId, $totalFloors);
                    }
                    $success = "Building added successfully";
                } else {
                    $id = (int)$_POST['id'];
                    $stmt = $conn->prepare("
                        UPDATE re_buildings 
                        SET name = ?, address = ?, dewa_premises = ?, plot_number = ?, landlord_name = ?, managed_by = ?, total_floors = ?, total_units = ?, 
                            has_parking = ?, parking_spaces = ?, has_gym = ?, has_pool = ?, facilities_notes = ?, is_active = ?
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$name, $address, $dewaPremises ?: null, $plotNumber ?: null, $landlordName ?: null, $managedBy, $totalFloors, $totalUnits, $hasParking, $parkingSpaces, $hasGym, $hasPool, $facilitiesNotes ?: null, $isActive, $id, $currentCompanyId]);
                    syncBuildingFloors($conn, $id, $totalFloors);
                    $success = "Building updated successfully";
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("DELETE FROM re_buildings WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $currentCompanyId]);
            $success = "Building deleted successfully";
        }
    }
}

// Get all buildings with managed_by company name
$buildings = $conn->prepare("
    SELECT b.*, 
           COUNT(DISTINCT u.id) as unit_count,
           COUNT(DISTINCT CASE WHEN u.status = 'occupied' THEN u.id END) as occupied_count,
           c.name as managed_by_company_name
    FROM re_buildings b
    LEFT JOIN re_units u ON u.building_id = b.id
    LEFT JOIN companies c ON c.id = b.managed_by
    WHERE b.company_id = ?
    GROUP BY b.id
    ORDER BY b.name ASC
");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Get real estate companies for managed_by dropdown
$realEstateCompanies = $conn->prepare("
    SELECT id, name 
    FROM companies 
    WHERE business_type = 'realestate' AND is_active = 1 
    ORDER BY name
");
$realEstateCompanies->execute();
$realEstateCompanies = $realEstateCompanies->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Buildings';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label">Buildings</div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBuildingModal" style="background-color: var(--primary); border-color: var(--primary);">
                <i class="bi bi-plus-circle"></i> Add Building
            </button>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>

        <div class="row g-4">

            <?php foreach ($buildings as $building): ?>
                <?php
                    $photoUrl = re_building_photo_url($building['primary_photo_path'] ?? null);
                ?>
                <div class="col-md-4">
                    <div class="card card-round overflow-hidden">
                        <?php if ($photoUrl): ?>
                            <div class="ratio ratio-16x9 bg-light">
                                <img src="<?= h($photoUrl) ?>" alt="<?= h($building['name']) ?>" class="object-fit-cover w-100 h-100" style="object-fit:cover;">
                            </div>
                        <?php else: ?>
                            <div class="ratio ratio-16x9 bg-light d-flex align-items-center justify-content-center text-muted">
                                <div class="text-center p-3">
                                    <i class="bi bi-building" style="font-size:2rem;"></i>
                                    <div class="small mt-1">No photo</div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <h5 class="card-title"><?= h($building['name']) ?></h5>
                            <p class="card-text text-muted"><?= h($building['address']) ?></p>
                            <div class="mb-2">
                                <small class="text-muted">Units: <?= $building['unit_count'] ?> 
                                (<?= $building['occupied_count'] ?> occupied)</small>
                            </div>
                            <?php if ($building['total_floors']): ?>
                                <div class="mb-2"><small class="text-muted">Floors: <?= $building['total_floors'] ?></small></div>
                            <?php endif; ?>
                            <?php if ($building['landlord_name']): ?>
                                <div class="mb-2">
                                    <small class="text-muted">
                                        <i class="bi bi-person"></i> Landlord: <?= h($building['landlord_name']) ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                            <?php if ($building['managed_by'] && $building['managed_by_company_name']): ?>
                                <div class="mb-2">
                                    <small class="text-muted">
                                        <i class="bi bi-building"></i> Managed by: <?= h($building['managed_by_company_name']) ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                            <?php if ($building['has_parking'] || $building['has_gym'] || $building['has_pool']): ?>
                                <div class="mb-2">
                                    <small class="text-muted">
                                        <?php if ($building['has_parking']): ?>
                                            <i class="bi bi-p-square"></i> Parking
                                        <?php endif; ?>
                                        <?php if ($building['has_gym']): ?>
                                            <i class="bi bi-dumbbell"></i> Gym
                                        <?php endif; ?>
                                        <?php if ($building['has_pool']): ?>
                                            <i class="bi bi-water"></i> Pool
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                            <div class="mt-3">
                                <a href="building_view.php?id=<?= $building['id'] ?>" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-eye"></i> View Details
                                </a>
                                <a href="units.php?building_id=<?= $building['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    View Units
                                </a>
                                <button class="btn btn-sm btn-outline-secondary" onclick="editBuilding(<?= htmlspecialchars(json_encode($building)) ?>)">
                                    Edit
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <!-- Add/Edit Building Modal -->
    <div class="modal fade" id="addBuildingModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="buildingForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="buildingId">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">Add Building</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Building Name *</label>
                            <input type="text" class="form-control" name="name" id="buildingName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" id="buildingAddress" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">DEWA Premises</label>
                                <input type="text" class="form-control" name="dewa_premises" id="dewaPremises" placeholder="DEWA Premises Number">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Plot Number</label>
                                <input type="text" class="form-control" name="plot_number" id="plotNumber" placeholder="Plot Number">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Landlord Name</label>
                                <input type="text" class="form-control" name="landlord_name" id="landlordName" placeholder="Landlord Name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Managed By</label>
                                <select class="form-select" name="managed_by" id="managedBy">
                                    <option value="">-- Select Company --</option>
                                    <?php foreach ($realEstateCompanies as $company): ?>
                                        <option value="<?= $company['id'] ?>"><?= h($company['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Total Floors</label>
                                <input type="number" class="form-control" name="total_floors" id="buildingFloors" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Total Units</label>
                                <input type="number" class="form-control" name="total_units" id="buildingUnits" min="0">
                            </div>
                        </div>

                        <hr class="my-3">
                        <h6 class="mb-3">Facilities</h6>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="has_parking" id="hasParking" value="1">
                                    <label class="form-check-label" for="hasParking">
                                        <i class="bi bi-p-square"></i> Has Parking
                                    </label>
                                </div>
                                <input type="number" class="form-control mt-2" name="parking_spaces" id="parkingSpaces" placeholder="Number of parking spaces" min="0" style="display: none;">
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="has_gym" id="hasGym" value="1">
                                    <label class="form-check-label" for="hasGym">
                                        <i class="bi bi-dumbbell"></i> Has Gym
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="has_pool" id="hasPool" value="1">
                                    <label class="form-check-label" for="hasPool">
                                        <i class="bi bi-water"></i> Has Pool
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Facilities Notes</label>
                            <textarea class="form-control" name="facilities_notes" id="facilitiesNotes" rows="2" placeholder="Additional facilities information..."></textarea>
                        </div>

                        <hr class="my-3">
                        <h6 class="mb-2">Building Photo</h6>
                        <p class="small text-muted mb-2">One primary image used by the Customer App, portals, website, and AI. JPG/PNG/GIF/WEBP · max 10 MB.</p>
                        <div id="buildingPhotoPanel" class="border rounded p-3 bg-light">
                            <div id="buildingPhotoPreviewWrap" class="mb-2 text-center" style="display:none;">
                                <img id="buildingPhotoPreview" src="" alt="Building photo preview" class="img-fluid rounded" style="max-height:180px;object-fit:cover;">
                            </div>
                            <div id="buildingPhotoPlaceholder" class="text-center text-muted py-3 mb-2">
                                <i class="bi bi-image" style="font-size:1.75rem;"></i>
                                <div class="small mt-1">No photo yet — save building first, then upload.</div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <input type="file" id="buildingPhotoFile" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control form-control-sm" style="max-width:280px;" disabled>
                                <button type="button" class="btn btn-sm btn-primary" id="buildingPhotoUploadBtn" disabled>
                                    <i class="bi bi-upload"></i> Upload / Replace
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" id="buildingPhotoRemoveBtn" disabled>
                                    <i class="bi bi-trash"></i> Remove
                                </button>
                            </div>
                            <div id="buildingPhotoStatus" class="small mt-2 text-muted"></div>
                        </div>

                        <hr class="my-3">
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="buildingActive" value="1" checked>
                                <label class="form-check-label" for="buildingActive">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

        <script>
            const buildingPhotoCsrf = <?= json_encode(csrf_token()) ?>;

            function setBuildingPhotoUi(building) {
                const hasId = !!(building && building.id);
                const path = building && building.primary_photo_path ? building.primary_photo_path : '';
                const url = path ? ('../../' + String(path).replace(/^\/+/, '')) : '';
                const previewWrap = document.getElementById('buildingPhotoPreviewWrap');
                const preview = document.getElementById('buildingPhotoPreview');
                const placeholder = document.getElementById('buildingPhotoPlaceholder');
                const fileInput = document.getElementById('buildingPhotoFile');
                const uploadBtn = document.getElementById('buildingPhotoUploadBtn');
                const removeBtn = document.getElementById('buildingPhotoRemoveBtn');
                const status = document.getElementById('buildingPhotoStatus');

                fileInput.value = '';
                fileInput.disabled = !hasId;
                uploadBtn.disabled = !hasId;
                removeBtn.disabled = !hasId || !path;
                status.textContent = hasId
                    ? (path ? 'Primary photo on file. Upload replaces the current image.' : 'Upload a primary photo for this building.')
                    : 'Save the building first, then open Edit to upload a photo.';

                if (url) {
                    preview.src = url;
                    previewWrap.style.display = 'block';
                    placeholder.style.display = 'none';
                } else {
                    preview.removeAttribute('src');
                    previewWrap.style.display = 'none';
                    placeholder.style.display = 'block';
                }
            }

            async function buildingPhotoRequest(action) {
                const buildingId = document.getElementById('buildingId').value;
                if (!buildingId) {
                    alert('Save the building first, then upload a photo.');
                    return;
                }
                const status = document.getElementById('buildingPhotoStatus');
                const fd = new FormData();
                fd.append('_csrf', buildingPhotoCsrf);
                fd.append('action', action);
                fd.append('building_id', buildingId);
                if (action === 'upload' || action === 'replace') {
                    const fileInput = document.getElementById('buildingPhotoFile');
                    if (!fileInput.files || !fileInput.files[0]) {
                        alert('Choose a photo file first.');
                        return;
                    }
                    fd.append('photo', fileInput.files[0]);
                }
                status.textContent = 'Working…';
                try {
                    const res = await fetch('ajax_building_photo.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await res.json();
                    if (!data.success) {
                        throw new Error(data.error || 'Request failed');
                    }
                    if (action === 'remove') {
                        setBuildingPhotoUi({ id: buildingId, primary_photo_path: '' });
                        status.textContent = 'Photo removed.';
                    } else {
                        setBuildingPhotoUi({ id: buildingId, primary_photo_path: data.photo.path });
                        status.textContent = 'Photo saved. Reload the page to refresh building cards.';
                    }
                } catch (err) {
                    status.textContent = err.message || 'Photo request failed';
                    alert(err.message || 'Photo request failed');
                }
            }

            function editBuilding(building) {
                document.getElementById('formAction').value = 'edit';
                document.getElementById('buildingId').value = building.id;
                document.getElementById('buildingName').value = building.name;
                document.getElementById('buildingAddress').value = building.address || '';
                document.getElementById('dewaPremises').value = building.dewa_premises || '';
                document.getElementById('plotNumber').value = building.plot_number || '';
                document.getElementById('landlordName').value = building.landlord_name || '';
                document.getElementById('managedBy').value = building.managed_by || '';
                document.getElementById('buildingFloors').value = building.total_floors || '';
                document.getElementById('buildingUnits').value = building.total_units || '';
                document.getElementById('hasParking').checked = building.has_parking == 1;
                document.getElementById('parkingSpaces').value = building.parking_spaces || '';
                document.getElementById('hasGym').checked = building.has_gym == 1;
                document.getElementById('hasPool').checked = building.has_pool == 1;
                document.getElementById('facilitiesNotes').value = building.facilities_notes || '';
                document.getElementById('buildingActive').checked = building.is_active == 1;
                document.getElementById('modalTitle').textContent = 'Edit Building';
                
                // Show/hide parking spaces input
                document.getElementById('parkingSpaces').style.display = building.has_parking == 1 ? 'block' : 'none';
                setBuildingPhotoUi(building);
                
                new bootstrap.Modal(document.getElementById('addBuildingModal')).show();
            }
            
            // Toggle parking spaces input
            document.getElementById('hasParking').addEventListener('change', function() {
                document.getElementById('parkingSpaces').style.display = this.checked ? 'block' : 'none';
            });

            document.getElementById('buildingPhotoUploadBtn').addEventListener('click', function() {
                buildingPhotoRequest('replace');
            });
            document.getElementById('buildingPhotoRemoveBtn').addEventListener('click', function() {
                if (confirm('Remove this building photo?')) {
                    buildingPhotoRequest('remove');
                }
            });
            
            document.getElementById('addBuildingModal').addEventListener('hidden.bs.modal', function() {
                document.getElementById('buildingForm').reset();
                document.getElementById('formAction').value = 'add';
                document.getElementById('modalTitle').textContent = 'Add Building';
                setBuildingPhotoUi(null);
            });

            document.querySelector('[data-bs-target="#addBuildingModal"]')?.addEventListener('click', function() {
                setBuildingPhotoUi(null);
            });
        </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

