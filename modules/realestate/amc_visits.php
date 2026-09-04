<?php
/**
 * Real Estate Module - AMC Visits Management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$contractId = !empty($_GET['contract_id']) ? (int)$_GET['contract_id'] : 0;
$visitId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? 'list';

$success = '';
$error = '';

// Get contract info
$contract = null;
if ($contractId) {
    $stmt = $conn->prepare("
        SELECT ac.*, b.name as building_name, cat.name as category_name, v.vendor_name
        FROM re_amc_contracts ac
        LEFT JOIN re_buildings b ON b.id = ac.building_id
        LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
        LEFT JOIN re_vendors v ON v.id = ac.vendor_id
        WHERE ac.id = ? AND ac.company_id = ?
    ");
    $stmt->execute([$contractId, $currentCompanyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action']) && $_POST['action'] === 'add_visit') {
        // Get next visit number
        $stmt = $conn->prepare("SELECT COALESCE(MAX(visit_number), 0) + 1 as next_num FROM re_amc_visits WHERE contract_id = ?");
        $stmt->execute([$contractId]);
        $nextNum = $stmt->fetchColumn();
        
        $scheduledDate = $_POST['scheduled_date'] ?? '';
        $scheduledTime = $_POST['scheduled_time'] ?? null;
        $visitType = $_POST['visit_type'] ?? 'scheduled';
        $technicianName = trim($_POST['technician_name'] ?? '');
        $technicianPhone = trim($_POST['technician_phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        $stmt = $conn->prepare("
            INSERT INTO re_amc_visits (
                contract_id, visit_number, scheduled_date, scheduled_time, visit_type, status,
                technician_name, technician_phone, notes
            ) VALUES (?, ?, ?, ?, ?, 'scheduled', ?, ?, ?)
        ");
        $stmt->execute([$contractId, $nextNum, $scheduledDate, $scheduledTime, $visitType, $technicianName, $technicianPhone, $notes]);
        $success = "Visit scheduled successfully!";
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'update_visit' && $visitId) {
        $actualDate = $_POST['actual_date'] ?? null;
        $actualTime = $_POST['actual_time'] ?? null;
        $status = $_POST['status'] ?? 'scheduled';
        $workPerformed = trim($_POST['work_performed'] ?? '');
        $issuesFound = trim($_POST['issues_found'] ?? '');
        $partsReplaced = trim($_POST['parts_replaced'] ?? '');
        $nextVisitDate = $_POST['next_visit_date'] ?? null;
        $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : null;
        $rating = !empty($_POST['rating']) ? (int)$_POST['rating'] : null;
        $notes = trim($_POST['notes'] ?? '');
        
        $stmt = $conn->prepare("
            UPDATE re_amc_visits SET
                actual_date = ?, actual_time = ?, status = ?, work_performed = ?,
                issues_found = ?, parts_replaced = ?, next_visit_date = ?,
                cost = ?, rating = ?, notes = ?
            WHERE id = ? AND contract_id = ?
        ");
        $stmt->execute([$actualDate, $actualTime, $status, $workPerformed, $issuesFound, $partsReplaced, 
                       $nextVisitDate, $cost, $rating, $notes, $visitId, $contractId]);
        $success = "Visit updated successfully!";
    }
}

// Get visits
$where = [];
$params = [];
if ($contractId) {
    $where[] = "contract_id = ?";
    $params[] = $contractId;
}
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

$visits = [];
if ($whereSql) {
    $stmt = $conn->prepare("
        SELECT av.*, ac.contract_number, b.name as building_name, cat.name as category_name
        FROM re_amc_visits av
        LEFT JOIN re_amc_contracts ac ON ac.id = av.contract_id
        LEFT JOIN re_buildings b ON b.id = ac.building_id
        LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
        {$whereSql}
        ORDER BY av.scheduled_date DESC, av.visit_number DESC
    ");
    $stmt->execute($params);
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get visit details if viewing/editing
$visit = null;
if ($visitId) {
    $stmt = $conn->prepare("SELECT * FROM re_amc_visits WHERE id = ?");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($visit && !$contractId) {
        $contractId = $visit['contract_id'];
        // Reload contract
        $stmt = $conn->prepare("
            SELECT ac.*, b.name as building_name, cat.name as category_name, v.vendor_name
            FROM re_amc_contracts ac
            LEFT JOIN re_buildings b ON b.id = ac.building_id
            LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
            LEFT JOIN re_vendors v ON v.id = ac.vendor_id
            WHERE ac.id = ? AND ac.company_id = ?
        ");
        $stmt->execute([$contractId, $currentCompanyId]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = $contract ? 'AMC Visits: ' . h($contract['contract_number']) : 'AMC Visits';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label">AMC Visits</h1>
            <?php if ($contract): ?>
                <p class="text-muted mb-0">
                    <strong><?= h($contract['contract_number']) ?></strong> - 
                    <?= h($contract['building_name']) ?> - <?= h($contract['category_name']) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="btn-group">
            <?php if ($contractId): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVisitModal">
                    <i class="bi bi-plus-circle"></i> Schedule Visit
                </button>
            <?php endif; ?>
            <?php if ($contractId): ?>
                <a href="amc_view.php?id=<?= $contractId ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Contract
                </a>
            <?php else: ?>
                <a href="amc.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to AMC
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= h($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= h($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Visits Table -->
    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (empty($visits)): ?>
                <p class="text-center text-muted py-4">No visits found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Visit #</th>
                                <th>Contract</th>
                                <th>Scheduled Date</th>
                                <th>Actual Date</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Technician</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visits as $v): ?>
                                <tr>
                                    <td><strong>#<?= $v['visit_number'] ?></strong></td>
                                    <td>
                                        <?= h($v['contract_number']) ?><br>
                                        <small class="text-muted"><?= h($v['building_name']) ?></small>
                                    </td>
                                    <td><?= h($v['scheduled_date']) ?><?= $v['scheduled_time'] ? ' ' . h($v['scheduled_time']) : '' ?></td>
                                    <td><?= $v['actual_date'] ? h($v['actual_date']) : '<span class="text-muted">-</span>' ?></td>
                                    <td><span class="badge bg-info"><?= ucfirst($v['visit_type']) ?></span></td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $v['status'] == 'completed' ? 'success' : 
                                            ($v['status'] == 'scheduled' ? 'primary' : 
                                            ($v['status'] == 'in_progress' ? 'warning' : 'secondary')) 
                                        ?>">
                                            <?= ucfirst($v['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= h($v['technician_name'] ?? 'N/A') ?><br>
                                        <?php if ($v['technician_phone']): ?>
                                            <small class="text-muted"><?= h($v['technician_phone']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" 
                                            onclick="viewVisit(<?= $v['id'] ?>)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-success" 
                                            onclick="editVisit(<?= $v['id'] ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
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

<!-- Add Visit Modal -->
<div class="modal fade" id="addVisitModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add_visit">
                <input type="hidden" name="contract_id" value="<?= $contractId ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Schedule New Visit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Scheduled Date <span class="text-danger">*</span></label>
                            <input type="date" name="scheduled_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Scheduled Time</label>
                            <input type="time" name="scheduled_time" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Visit Type <span class="text-danger">*</span></label>
                            <select name="visit_type" class="form-select" required>
                                <option value="scheduled">Scheduled</option>
                                <option value="emergency">Emergency</option>
                                <option value="inspection">Inspection</option>
                                <option value="repair">Repair</option>
                                <option value="compliance">Compliance</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technician Name</label>
                            <input type="text" name="technician_name" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technician Phone</label>
                            <input type="text" name="technician_phone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Schedule Visit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Visit Modal -->
<div class="modal fade" id="editVisitModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="update_visit">
                <input type="hidden" name="contract_id" value="<?= $contractId ?>">
                <input type="hidden" id="edit_visit_id" name="visit_id">
                <div class="modal-header">
                    <h5 class="modal-title">Update Visit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editVisitBody">
                    <!-- Loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Visit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editVisit(id) {
    document.getElementById('edit_visit_id').value = id;
    // Simple form - in production, load via AJAX
    document.getElementById('editVisitBody').innerHTML = `
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Actual Date</label>
                <input type="date" name="actual_date" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Actual Time</label>
                <input type="time" name="actual_time" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="scheduled">Scheduled</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Next Visit Date</label>
                <input type="date" name="next_visit_date" class="form-control">
            </div>
            <div class="col-12">
                <label class="form-label">Work Performed</label>
                <textarea name="work_performed" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Issues Found</label>
                <textarea name="issues_found" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Parts Replaced</label>
                <textarea name="parts_replaced" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Cost (AED)</label>
                <input type="number" step="0.01" name="cost" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Rating (1-5)</label>
                <input type="number" min="1" max="5" name="rating" class="form-control">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
        </div>
    `;
    new bootstrap.Modal(document.getElementById('editVisitModal')).show();
}

function viewVisit(id) {
    window.location.href = 'amc_visits.php?id=' + id + '&action=view';
}
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
