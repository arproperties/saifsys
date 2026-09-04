<?php
/**
 * Real Estate Module - Preventive Maintenance Assets Management
 * Manage equipment and assets that require preventive maintenance
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
$userId = current_user_id();

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
            $buildingId = !empty($_POST['building_id']) ? (int)$_POST['building_id'] : null;
            $unitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
            $assetType = $_POST['asset_type'] ?? '';
            $assetName = trim($_POST['asset_name'] ?? '');
            $assetCode = trim($_POST['asset_code'] ?? '');
            $manufacturer = trim($_POST['manufacturer'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $installationDate = !empty($_POST['installation_date']) ? $_POST['installation_date'] : null;
            $warrantyExpiry = !empty($_POST['warranty_expiry']) ? $_POST['warranty_expiry'] : null;
            $location = trim($_POST['location'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($assetName)) {
                $error = "Asset name is required";
            } elseif (empty($assetType)) {
                $error = "Asset type is required";
            } else {
                try {
                    if ($_POST['action'] === 'add') {
                        $stmt = $conn->prepare("
                            INSERT INTO re_maintenance_assets 
                            (company_id, building_id, unit_id, asset_type, asset_name, asset_code,
                             manufacturer, model, installation_date, warranty_expiry, location, notes, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $currentCompanyId, $buildingId, $unitId, $assetType, $assetName, $assetCode,
                            $manufacturer ?: null, $model ?: null, $installationDate, $warrantyExpiry,
                            $location ?: null, $notes ?: null, $isActive
                        ]);
                        $success = "Asset added successfully";
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE re_maintenance_assets 
                            SET building_id = ?, unit_id = ?, asset_type = ?, asset_name = ?, asset_code = ?,
                                manufacturer = ?, model = ?, installation_date = ?, warranty_expiry = ?,
                                location = ?, notes = ?, is_active = ?
                            WHERE id = ? AND company_id = ?
                        ");
                        $stmt->execute([
                            $buildingId, $unitId, $assetType, $assetName, $assetCode,
                            $manufacturer ?: null, $model ?: null, $installationDate, $warrantyExpiry,
                            $location ?: null, $notes ?: null, $isActive,
                            $id, $currentCompanyId
                        ]);
                        $success = "Asset updated successfully";
                    }
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            try {
                // Check if asset is used in schedules
                $checkStmt = $conn->prepare("SELECT COUNT(*) FROM re_preventive_maintenance_schedules WHERE asset_id = ?");
                $checkStmt->execute([$id]);
                if ($checkStmt->fetchColumn() > 0) {
                    $error = "Cannot delete asset: It is used in maintenance schedules";
                } else {
                    $stmt = $conn->prepare("DELETE FROM re_maintenance_assets WHERE id = ? AND company_id = ?");
                    $stmt->execute([$id, $currentCompanyId]);
                    $success = "Asset deleted successfully";
                }
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$filterType = $_GET['type'] ?? 'all';
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$filterActive = $_GET['active'] ?? 'all';

// Build query
$where = ["a.company_id = ?"];
$params = [$currentCompanyId];

if ($filterType !== 'all') {
    $where[] = "a.asset_type = ?";
    $params[] = $filterType;
}

if ($filterBuilding) {
    $where[] = "a.building_id = ?";
    $params[] = $filterBuilding;
}

if ($filterActive === 'active') {
    $where[] = "a.is_active = 1";
} elseif ($filterActive === 'inactive') {
    $where[] = "a.is_active = 0";
}

// Get assets
$assets = $conn->prepare("
    SELECT a.*, b.name as building_name, u.unit_number,
           (SELECT COUNT(*) FROM re_preventive_maintenance_schedules WHERE asset_id = a.id) as schedule_count
    FROM re_maintenance_assets a
    LEFT JOIN re_buildings b ON b.id = a.building_id
    LEFT JOIN re_units u ON u.id = a.unit_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY a.asset_type, a.asset_name
");
$assets->execute($params);
$assets = $assets->fetchAll(PDO::FETCH_ASSOC);

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatAssetType($type) {
    $types = [
        'ac_unit' => 'AC Unit',
        'elevator' => 'Elevator',
        'fire_system' => 'Fire System',
        'plumbing' => 'Plumbing',
        'electrical' => 'Electrical',
        'hvac' => 'HVAC',
        'generator' => 'Generator',
        'pump' => 'Pump',
        'security_system' => 'Security System',
        'other' => 'Other'
    ];
    return $types[$type] ?? ucfirst($type);
}

// Set page title and include layout
$pageTitle = 'Maintenance Assets';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
                <i class="bi bi-tools"></i> Real Estate - Maintenance Assets
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="preventive_maintenance.php">Preventive Maintenance</a>
                <a class="nav-link" href="index.php">Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-tools"></i> Maintenance Assets</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAssetModal">
                <i class="bi bi-plus-circle"></i> Add Asset
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Asset Type</label>
                        <select name="type" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="ac_unit" <?= $filterType === 'ac_unit' ? 'selected' : '' ?>>AC Unit</option>
                            <option value="elevator" <?= $filterType === 'elevator' ? 'selected' : '' ?>>Elevator</option>
                            <option value="fire_system" <?= $filterType === 'fire_system' ? 'selected' : '' ?>>Fire System</option>
                            <option value="plumbing" <?= $filterType === 'plumbing' ? 'selected' : '' ?>>Plumbing</option>
                            <option value="electrical" <?= $filterType === 'electrical' ? 'selected' : '' ?>>Electrical</option>
                            <option value="hvac" <?= $filterType === 'hvac' ? 'selected' : '' ?>>HVAC</option>
                            <option value="generator" <?= $filterType === 'generator' ? 'selected' : '' ?>>Generator</option>
                            <option value="pump" <?= $filterType === 'pump' ? 'selected' : '' ?>>Pump</option>
                            <option value="security_system" <?= $filterType === 'security_system' ? 'selected' : '' ?>>Security System</option>
                            <option value="other" <?= $filterType === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Building</label>
                        <select name="building_id" class="form-select" onchange="this.form.submit()">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $filterBuilding == $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="active" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $filterActive === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="active" <?= $filterActive === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $filterActive === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assets Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Assets (<?= count($assets) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($assets)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No assets found. 
                        <a href="#" data-bs-toggle="modal" data-bs-target="#addAssetModal">Add your first asset</a>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Asset Name</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Manufacturer/Model</th>
                                    <th>Asset Code</th>
                                    <th>Schedules</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assets as $asset): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($asset['asset_name']) ?></strong>
                                            <?php if ($asset['warranty_expiry'] && strtotime($asset['warranty_expiry']) < time()): ?>
                                                <span class="badge bg-warning" title="Warranty Expired">⚠️</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= formatAssetType($asset['asset_type']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($asset['building_name']): ?>
                                                <?= h($asset['building_name']) ?>
                                                <?php if ($asset['unit_number']): ?>
                                                    - Unit <?= h($asset['unit_number']) ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Building-wide</span>
                                            <?php endif; ?>
                                            <?php if ($asset['location']): ?>
                                                <br><small class="text-muted"><?= h($asset['location']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($asset['manufacturer']): ?>
                                                <?= h($asset['manufacturer']) ?>
                                                <?php if ($asset['model']): ?>
                                                    / <?= h($asset['model']) ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($asset['asset_code'] ?: '-') ?></td>
                                        <td>
                                            <?php if ($asset['schedule_count'] > 0): ?>
                                                <span class="badge bg-primary"><?= $asset['schedule_count'] ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($asset['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="editAsset(<?= htmlspecialchars(json_encode($asset)) ?>)">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this asset?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $asset['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add/Edit Asset Modal -->
    <div class="modal fade" id="addAssetModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Asset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="assetForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="assetId">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Asset Name *</label>
                                <input type="text" name="asset_name" class="form-control" id="assetName" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Asset Type *</label>
                                <select name="asset_type" class="form-select" id="assetType" required>
                                    <option value="">Select Type</option>
                                    <option value="ac_unit">AC Unit</option>
                                    <option value="elevator">Elevator</option>
                                    <option value="fire_system">Fire System</option>
                                    <option value="plumbing">Plumbing</option>
                                    <option value="electrical">Electrical</option>
                                    <option value="hvac">HVAC</option>
                                    <option value="generator">Generator</option>
                                    <option value="pump">Pump</option>
                                    <option value="security_system">Security System</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Building</label>
                                <select name="building_id" class="form-select" id="assetBuilding" onchange="loadUnits(this.value)">
                                    <option value="">Select Building (Optional)</option>
                                    <?php foreach ($buildings as $b): ?>
                                        <option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit</label>
                                <select name="unit_id" class="form-select" id="assetUnit">
                                    <option value="">Select Unit (Optional)</option>
                                </select>
                                <small class="text-muted">Select building first</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Asset Code / Serial Number</label>
                                <input type="text" name="asset_code" class="form-control" id="assetCode">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" class="form-control" id="assetLocation" 
                                       placeholder="e.g., Ground Floor, Room 101">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Manufacturer</label>
                                <input type="text" name="manufacturer" class="form-control" id="assetManufacturer">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Model</label>
                                <input type="text" name="model" class="form-control" id="assetModel">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Installation Date</label>
                                <input type="date" name="installation_date" class="form-control" id="assetInstallationDate">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Warranty Expiry</label>
                                <input type="date" name="warranty_expiry" class="form-control" id="assetWarrantyExpiry">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" id="assetNotes"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" 
                                       id="assetIsActive" value="1" checked>
                                <label class="form-check-label" for="assetIsActive">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Asset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editAsset(asset) {
            document.getElementById('modalTitle').textContent = 'Edit Asset';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('assetId').value = asset.id;
            document.getElementById('assetName').value = asset.asset_name || '';
            document.getElementById('assetType').value = asset.asset_type || '';
            document.getElementById('assetBuilding').value = asset.building_id || '';
            document.getElementById('assetCode').value = asset.asset_code || '';
            document.getElementById('assetLocation').value = asset.location || '';
            document.getElementById('assetManufacturer').value = asset.manufacturer || '';
            document.getElementById('assetModel').value = asset.model || '';
            document.getElementById('assetInstallationDate').value = asset.installation_date || '';
            document.getElementById('assetWarrantyExpiry').value = asset.warranty_expiry || '';
            document.getElementById('assetNotes').value = asset.notes || '';
            document.getElementById('assetIsActive').checked = asset.is_active == 1;
            
            if (asset.building_id) {
                loadUnits(asset.building_id, asset.unit_id);
            }
            
            const modal = new bootstrap.Modal(document.getElementById('addAssetModal'));
            modal.show();
        }

        function loadUnits(buildingId, selectedUnitId = null) {
            const unitSelect = document.getElementById('assetUnit');
            unitSelect.innerHTML = '<option value="">Loading...</option>';
            
            if (!buildingId) {
                unitSelect.innerHTML = '<option value="">Select Unit (Optional)</option>';
                return;
            }
            
            fetch(`ajax_get_floors.php?building_id=${buildingId}`)
                .then(response => response.json())
                .then(data => {
                    unitSelect.innerHTML = '<option value="">Select Unit (Optional)</option>';
                    if (data.units) {
                        data.units.forEach(unit => {
                            const option = document.createElement('option');
                            option.value = unit.id;
                            option.textContent = unit.unit_number;
                            if (selectedUnitId && unit.id == selectedUnitId) {
                                option.selected = true;
                            }
                            unitSelect.appendChild(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading units:', error);
                    unitSelect.innerHTML = '<option value="">Error loading units</option>';
                });
        }

        // Reset form when modal is closed
        document.getElementById('addAssetModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('assetForm').reset();
            document.getElementById('modalTitle').textContent = 'Add Asset';
            document.getElementById('formAction').value = 'add';
            document.getElementById('assetId').value = '';
            document.getElementById('assetIsActive').checked = true;
            document.getElementById('assetUnit').innerHTML = '<option value="">Select Unit (Optional)</option>';
        });
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

