<?php
/**
 * Real Estate Module - Maintenance Requests Queue
 * For Maintenance Managers to review, edit, and assign pending requests
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/tenant_notifications.php';
require_once __DIR__ . '/includes/maintenance_location_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = re_maint_require_company($conn);
$userId = current_user_id();

// Check if user has Maintenance Manager role or is Owner/Admin
$userRoles = current_user_roles($conn);
$isMaintenanceManager = false;
foreach ($userRoles as $role) {
    if (stripos($role, 'maintenance') !== false && stripos($role, 'manager') !== false) {
        $isMaintenanceManager = true;
        break;
    }
}
$isOwnerOrAdmin = in_array('Owner', $userRoles, true) || in_array('Admin', $userRoles, true);

if (!$isMaintenanceManager && !$isOwnerOrAdmin) {
    header('Location: maintenance.php');
    exit;
}

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'assign') {
            $requestId = (int)$_POST['request_id'];
            $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $priority = $_POST['priority'] ?? 'medium';
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : 0;
            $notes = trim($_POST['notes'] ?? '');
            
            try {
                $conn->beginTransaction();
                
                // Get request details before update
                $stmt = $conn->prepare("
                    SELECT mr.*,
                           u.unit_number,
                           ca.area_name AS common_area_name,
                           COALESCE(b.name, bu.name) AS building_name,
                           t.first_name, t.last_name, t.phone
                    FROM re_maintenance_requests mr
                    LEFT JOIN re_units u ON u.id = mr.unit_id
                    LEFT JOIN re_buildings bu ON bu.id = u.building_id
                    LEFT JOIN re_buildings b ON b.id = mr.building_id
                    LEFT JOIN re_building_common_areas ca ON ca.id = mr.common_area_id
                    LEFT JOIN re_tenants t ON t.id = mr.tenant_id
                    WHERE mr.id = ? AND mr.company_id = ?
                ");
                $stmt->execute([$requestId, $currentCompanyId]);
                $requestDetails = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$requestDetails) {
                    throw new Exception("Maintenance request not found");
                }
                
                // Update maintenance request
                $respondedAt = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("
                    UPDATE re_maintenance_requests 
                    SET assigned_to = ?, priority = ?, category = ?, description = ?, 
                        cost = ?, notes = ?, status = 'in_progress', responded_at = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$assignedTo, $priority, $category, $description, $cost, $notes, $respondedAt, $requestId, $currentCompanyId]);
                
                // Update SLA tracking for response time
                require_once __DIR__ . '/includes/sla_helper.php';
                update_sla_response_time($conn, $requestId, $respondedAt);
                
                // Get assigned employee details
                if ($assignedTo) {
                    $stmt = $conn->prepare("SELECT id, full_name, email, phone FROM employees WHERE id = ?");
                    $stmt->execute([$assignedTo]);
                    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Send email notification to assigned employee
                    if ($employee && !empty($employee['email'])) {
                        require_once __DIR__ . '/includes/re_email_helper.php';
                        $emailResult = send_maintenance_assignment_notification(
                            $conn, 
                            $requestId, 
                            $currentCompanyId, 
                            $assignedTo,
                            $requestDetails,
                            $employee
                        );
                        
                        if (!$emailResult['success']) {
                            // Log error but don't fail the assignment
                            error_log("Failed to send assignment email: " . implode('; ', $emailResult['errors']));
                        }
                    }
                }
                
                $conn->commit();
                if (($requestDetails['status'] ?? '') !== 'in_progress') {
                    tenant_notification_create($conn, [
                        'company_id' => $currentCompanyId,
                        'lease_id' => (int)($requestDetails['lease_id'] ?? 0),
                        'tenant_id' => (int)($requestDetails['tenant_id'] ?? 0),
                        'type' => 'maintenance_status',
                        'entity_type' => 'maintenance',
                        'entity_id' => (int)$requestId,
                        'title' => 'Maintenance in progress',
                        'body' => 'Your maintenance request is now in progress.',
                        'dedup_window_minutes' => 10,
                    ]);
                }
                $success = "Maintenance request assigned successfully" . 
                           ($assignedTo && isset($employee) && !empty($employee['email']) ? " and notification sent to " . $employee['full_name'] : "");
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'update') {
            $requestId = (int)$_POST['request_id'];
            $priority = $_POST['priority'] ?? 'medium';
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : 0;
            $notes = trim($_POST['notes'] ?? '');
            
            try {
                $stmt = $conn->prepare("
                    UPDATE re_maintenance_requests 
                    SET priority = ?, category = ?, description = ?, cost = ?, notes = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$priority, $category, $description, $cost, $notes, $requestId, $currentCompanyId]);
                $success = "Maintenance request updated successfully";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'cancel') {
            $requestId = (int)$_POST['request_id'];
            $requestStmt = $conn->prepare("SELECT id, lease_id, tenant_id, status FROM re_maintenance_requests WHERE id = ? AND company_id = ? LIMIT 1");
            $requestStmt->execute([$requestId, $currentCompanyId]);
            $requestDetails = $requestStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $stmt = $conn->prepare("
                UPDATE re_maintenance_requests 
                SET status = 'cancelled', updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$requestId, $currentCompanyId]);
            if ($requestDetails && $stmt->rowCount() > 0 && ($requestDetails['status'] ?? '') !== 'cancelled') {
                tenant_notification_create($conn, [
                    'company_id' => $currentCompanyId,
                    'lease_id' => (int)($requestDetails['lease_id'] ?? 0),
                    'tenant_id' => (int)($requestDetails['tenant_id'] ?? 0),
                    'type' => 'maintenance_status',
                    'entity_type' => 'maintenance',
                    'entity_id' => (int)$requestId,
                    'title' => 'Maintenance cancelled',
                    'body' => 'Your maintenance request has been cancelled.',
                    'dedup_window_minutes' => 10,
                ]);
            }
            $success = "Maintenance request cancelled";
        }
    }
}

// Filters
$statusFilter = (string)($_GET['status'] ?? 'pending');
$buildingFilter = (int)($_GET['building_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);
$priorityFilter = (string)($_GET['priority'] ?? 'all');
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$allowedStatuses = ['pending', 'in_progress', 'all', 'completed', 'cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
}
$allowedPriorities = ['all', 'low', 'medium', 'high', 'urgent'];
if (!in_array($priorityFilter, $allowedPriorities, true)) {
    $priorityFilter = 'all';
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$buildings = re_maint_load_buildings($conn, $currentCompanyId);
$unitsForFilter = [];
if ($buildingFilter > 0) {
    $ust = $conn->prepare("SELECT id, unit_number FROM re_units WHERE company_id = ? AND building_id = ? ORDER BY unit_number");
    $ust->execute([$currentCompanyId, $buildingFilter]);
    $unitsForFilter = $ust->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$where = ["mr.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter === 'pending') {
    $where[] = "mr.status = 'pending'";
} elseif ($statusFilter !== 'all') {
    $where[] = "mr.status = ?";
    $params[] = $statusFilter;
}
if ($priorityFilter !== 'all') {
    $where[] = 'mr.priority = ?';
    $params[] = $priorityFilter;
}
if ($buildingFilter > 0) {
    $where[] = 'COALESCE(mr.building_id, u.building_id) = ?';
    $params[] = $buildingFilter;
}
if ($unitFilter > 0) {
    $where[] = 'mr.unit_id = ?';
    $params[] = $unitFilter;
}
if ($dateFrom !== '') {
    $where[] = 'mr.request_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'mr.request_date <= ?';
    $params[] = $dateTo;
}
if ($q !== '') {
    $where[] = '(mr.description LIKE ? OR mr.category LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR u.unit_number LIKE ? OR ca.area_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$stmt = $conn->prepare("
    SELECT mr.*, mr.source,
           u.unit_number,
           ca.area_name AS common_area_name,
           COALESCE(b.name, bu.name) AS building_name,
           t.first_name, t.last_name, t.phone,
           l.lease_number,
           e.full_name as assigned_employee_name,
           creator.username as created_by_name,
           (SELECT COUNT(*) FROM re_maintenance_photos mp
             WHERE mp.maintenance_request_id = mr.id AND mp.company_id = mr.company_id) AS photo_count
    FROM re_maintenance_requests mr
    LEFT JOIN re_units u ON u.id = mr.unit_id
    LEFT JOIN re_buildings bu ON bu.id = u.building_id
    LEFT JOIN re_buildings b ON b.id = mr.building_id
    LEFT JOIN re_building_common_areas ca ON ca.id = mr.common_area_id
    LEFT JOIN re_tenants t ON t.id = mr.tenant_id
    LEFT JOIN re_leases l ON l.id = mr.lease_id
    LEFT JOIN employees e ON e.id = mr.assigned_to
    LEFT JOIN user creator ON creator.id = mr.created_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        FIELD(mr.priority, 'urgent', 'high', 'medium', 'low'),
        mr.request_date DESC,
        mr.created_at DESC
");
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$queueCounts = ['pending' => 0, 'in_progress' => 0];
try {
    $cs = $conn->prepare("
        SELECT status, COUNT(*) AS c
        FROM re_maintenance_requests
        WHERE company_id = ? AND status IN ('pending', 'in_progress')
        GROUP BY status
    ");
    $cs->execute([$currentCompanyId]);
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $st = (string)$row['status'];
        if (isset($queueCounts[$st])) {
            $queueCounts[$st] = (int)$row['c'];
        }
    }
} catch (Throwable $e) {
}

// Get maintenance team employees
// Try with company_id first, fallback to all employees if none found or column doesn't exist
try {
    $stmt = $conn->prepare("
        SELECT id, full_name, email, phone
        FROM employees 
        WHERE company_id = ? 
        ORDER BY full_name
    ");
    $stmt->execute([$currentCompanyId]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no employees found for this company, try getting all employees
    if (empty($employees)) {
        $stmt = $conn->query("
            SELECT id, full_name, email, phone
            FROM employees 
            ORDER BY full_name
        ");
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // If company_id column doesn't exist, get all employees
    $stmt = $conn->query("
        SELECT id, full_name, email, phone
        FROM employees 
        ORDER BY full_name
    ");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Maintenance Queue';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <div class="page-header-label mb-0">Maintenance Queue</div>
                <div class="text-muted small">Review, prioritize, and assign open requests to the team.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="maintenance_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New</a>
                <a href="maintenance.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> All requests</a>
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

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card card-round border-0 shadow-sm h-100">
                    <div class="card-body py-3">
                        <div class="text-muted small text-uppercase">Pending (company)</div>
                        <div class="fs-3 fw-semibold text-warning"><?= (int)$queueCounts['pending'] ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-round border-0 shadow-sm h-100">
                    <div class="card-body py-3">
                        <div class="text-muted small text-uppercase">In progress (company)</div>
                        <div class="fs-3 fw-semibold text-primary"><?= (int)$queueCounts['in_progress'] ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-round border-0 shadow-sm h-100">
                    <div class="card-body py-3">
                        <div class="text-muted small text-uppercase">Showing now</div>
                        <div class="fs-3 fw-semibold"><?= count($requests) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-round mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In progress</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-select">
                            <option value="all" <?= $priorityFilter === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="urgent" <?= $priorityFilter === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                            <option value="high" <?= $priorityFilter === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="medium" <?= $priorityFilter === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="low" <?= $priorityFilter === 'low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Building</label>
                        <select name="building_id" id="filterBuilding" class="form-select">
                            <option value="0">All buildings</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $buildingFilter === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Unit</label>
                        <select name="unit_id" id="filterUnit" class="form-select" <?= $buildingFilter <= 0 ? 'disabled' : '' ?>>
                            <option value="0">All units</option>
                            <?php foreach ($unitsForFilter as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" <?= $unitFilter === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['unit_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">From</label>
                        <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To</label>
                        <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Description, tenant, unit…">
                    </div>
                    <div class="col-md-8 d-flex gap-2 justify-content-md-end">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button>
                        <a href="maintenance_queue.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card card-round">
            <div class="card-body p-0">
                <?php if (empty($requests)): ?>
                    <div class="text-center text-muted py-5">No maintenance requests match these filters.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Priority</th>
                                    <th>Location</th>
                                    <th>Tenant</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Assigned</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $req): ?>
                                    <?php
                                    $priorityClass = [
                                        'low' => 'secondary',
                                        'medium' => 'info',
                                        'high' => 'warning',
                                        'urgent' => 'danger',
                                    ];
                                    $statusClass = [
                                        'pending' => 'warning',
                                        'in_progress' => 'primary',
                                        'completed' => 'success',
                                        'cancelled' => 'secondary',
                                    ];
                                    $pClass = $priorityClass[$req['priority']] ?? 'secondary';
                                    $sClass = $statusClass[$req['status']] ?? 'secondary';
                                    ?>
                                    <tr>
                                        <td class="text-nowrap"><?= h(date('d M Y', strtotime($req['request_date']))) ?></td>
                                        <td><span class="badge bg-<?= $pClass ?>"><?= h(ucfirst((string)$req['priority'])) ?></span></td>
                                        <td>
                                            <div class="fw-semibold"><?= h(re_maint_location_label(
                                                $req['building_name'] ?? null,
                                                (string)($req['location_type'] ?? 'unit'),
                                                $req['unit_number'] ?? null,
                                                $req['common_area_name'] ?? null
                                            )) ?></div>
                                            <?php if (($req['source'] ?? '') === 'ars'): ?>
                                                <span class="badge bg-dark">ARS</span>
                                            <?php endif; ?>
                                            <?php if ((int)($req['photo_count'] ?? 0) > 0): ?>
                                                <span class="badge bg-light text-dark border"><i class="bi bi-image"></i> <?= (int)$req['photo_count'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($req['first_name']): ?>
                                                <?= h($req['first_name'] . ' ' . $req['last_name']) ?>
                                                <?php if ($req['phone']): ?>
                                                    <div class="small text-muted"><?= h($req['phone']) ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($req['category'] ?: '—') ?></td>
                                        <td style="max-width:220px;">
                                            <span class="d-inline-block text-truncate" style="max-width:220px;" title="<?= h($req['description']) ?>">
                                                <?= h($req['description']) ?>
                                            </span>
                                        </td>
                                        <td><span class="badge bg-<?= $sClass ?>"><?= h(ucfirst(str_replace('_', ' ', (string)$req['status']))) ?></span></td>
                                        <td><?= $req['assigned_employee_name'] ? h($req['assigned_employee_name']) : '<span class="text-muted">Not assigned</span>' ?></td>
                                        <td class="text-end text-nowrap">
                                            <?php if (!in_array($req['status'], ['completed', 'cancelled'], true)): ?>
                                            <button class="btn btn-sm btn-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#assignModal<?= (int)$req['id'] ?>">
                                                <i class="bi bi-person-plus"></i> Assign
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#cancelModal<?= (int)$req['id'] ?>">
                                                Cancel
                                            </button>
                                            <?php endif; ?>
                                            <a href="maintenance_view.php?id=<?= (int)$req['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>

                                    <!-- Assign Modal -->
                                    <div class="modal fade" id="assignModal<?= $req['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Assign Maintenance Request</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="assign">
                                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Location</label>
                                                            <input type="text" class="form-control"
                                                                   value="<?= h(re_maint_location_label(
                                                                       $req['building_name'] ?? null,
                                                                       (string)($req['location_type'] ?? 'unit'),
                                                                       $req['unit_number'] ?? null,
                                                                       $req['common_area_name'] ?? null
                                                                   )) ?>" readonly>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-6 mb-3">
                                                                <label class="form-label">Priority *</label>
                                                                <select name="priority" class="form-select" required>
                                                                    <option value="low" <?= $req['priority'] === 'low' ? 'selected' : '' ?>>Low</option>
                                                                    <option value="medium" <?= $req['priority'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                                                                    <option value="high" <?= $req['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                                                                    <option value="urgent" <?= $req['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                                                                </select>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label class="form-label">Category</label>
                                                                <input type="text" name="category" class="form-control" 
                                                                       value="<?= h($req['category']) ?>" 
                                                                       placeholder="e.g., Plumbing, Electrical, HVAC">
                                                            </div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Description *</label>
                                                            <textarea name="description" class="form-control" rows="3" required><?= h($req['description']) ?></textarea>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-6 mb-3">
                                                                <label class="form-label">Assign To *</label>
                                                                <select name="assigned_to" class="form-select" required>
                                                                    <option value="">-- Select Team Member --</option>
                                                                    <?php if (empty($employees)): ?>
                                                                        <option value="" disabled>No employees found. Please add employees in HR module.</option>
                                                                    <?php else: ?>
                                                                        <?php foreach ($employees as $emp): ?>
                                                                            <option value="<?= $emp['id'] ?>" 
                                                                                    <?= $req['assigned_to'] == $emp['id'] ? 'selected' : '' ?>>
                                                                                <?= h($emp['full_name'] ?: 'Employee #' . $emp['id']) ?>
                                                                                <?php if ($emp['email']): ?>
                                                                                    (<?= h($emp['email']) ?>)
                                                                                <?php endif; ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    <?php endif; ?>
                                                                </select>
                                                                <?php if (empty($employees)): ?>
                                                                    <small class="text-danger">
                                                                        <i class="bi bi-exclamation-triangle"></i> 
                                                                        No employees found. <a href="<?= get_base_path() ? get_base_path() . '/' : '/' ?>hr/employees.php" target="_blank">Add employees in HR module</a>
                                                                    </small>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label class="form-label">Estimated Cost (AED)</label>
                                                                <input type="number" step="0.01" name="cost" class="form-control" 
                                                                       value="<?= $req['cost'] ?>">
                                                            </div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Notes</label>
                                                            <textarea name="notes" class="form-control" rows="2"><?= h($req['notes']) ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                        <button type="submit" class="btn btn-primary">
                                                            <i class="bi bi-check-circle"></i> Assign & Start
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if (!in_array($req['status'], ['completed', 'cancelled'], true)): ?>
                                    <div class="modal fade" id="cancelModal<?= (int)$req['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="cancel">
                                                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Cancel request #<?= (int)$req['id'] ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        Cancel this maintenance request? The tenant will be notified if linked.
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep</button>
                                                        <button type="submit" class="btn btn-danger">Confirm cancel</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

<script>
(function() {
    const building = document.getElementById('filterBuilding');
    const unit = document.getElementById('filterUnit');
    if (!building || !unit) return;
    building.addEventListener('change', function() {
        const bid = building.value;
        unit.innerHTML = '<option value="0">All units</option>';
        if (!bid || bid === '0') {
            unit.disabled = true;
            unit.value = '0';
            return;
        }
        unit.disabled = true;
        fetch('ajax_get_units.php?building_id=' + encodeURIComponent(bid))
            .then(r => r.json())
            .then(d => {
                (d.units || []).forEach(u => {
                    const o = document.createElement('option');
                    o.value = u.id;
                    o.textContent = u.unit_number;
                    unit.appendChild(o);
                });
                unit.disabled = false;
            })
            .catch(() => { unit.disabled = false; });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

