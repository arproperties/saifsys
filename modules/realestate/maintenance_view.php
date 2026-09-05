<?php
/**
 * Real Estate Module - Maintenance Request View
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_request_links.php';
require_once __DIR__ . '/../../includes/inventory/inv_material_requests_for_modules.php';
require_once __DIR__ . '/includes/maintenance_location_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = re_maint_require_company($conn);
$userId = current_user_id();

$requestId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$success = '';
$error = '';

if (!$requestId) {
    header('Location: maintenance.php');
    exit;
}

// Get maintenance request details first (needed for status update)
$stmt = $conn->prepare("
    SELECT mr.*,
           u.unit_number, u.unit_type,
           ca.area_name AS common_area_name,
           COALESCE(b.name, bu.name) AS building_name,
           COALESCE(mr.building_id, u.building_id) AS building_id,
           t.first_name, t.last_name, t.phone, t.email,
           e.full_name as assigned_employee_name, e.phone as assigned_employee_phone,
           u2.username as created_by_name
    FROM re_maintenance_requests mr
    LEFT JOIN re_units u ON u.id = mr.unit_id
    LEFT JOIN re_buildings bu ON bu.id = u.building_id
    LEFT JOIN re_buildings b ON b.id = mr.building_id
    LEFT JOIN re_building_common_areas ca ON ca.id = mr.common_area_id
    LEFT JOIN re_tenants t ON t.id = mr.tenant_id
    LEFT JOIN employees e ON e.id = mr.assigned_to
    LEFT JOIN user u2 ON u2.id = mr.created_by
    WHERE mr.id = ? AND mr.company_id = ?
");
$stmt->execute([$requestId, $currentCompanyId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    header('Location: maintenance.php');
    exit;
}

$reloadMaintenanceRequest = static function (PDO $conn, int $requestId, int $companyId): ?array {
    $stmt = $conn->prepare("
        SELECT mr.*,
               u.unit_number, u.unit_type,
               ca.area_name AS common_area_name,
               COALESCE(b.name, bu.name) AS building_name,
               COALESCE(mr.building_id, u.building_id) AS building_id,
               t.first_name, t.last_name, t.phone, t.email,
               e.full_name as assigned_employee_name, e.phone as assigned_employee_phone,
               u2.username as created_by_name
        FROM re_maintenance_requests mr
        LEFT JOIN re_units u ON u.id = mr.unit_id
        LEFT JOIN re_buildings bu ON bu.id = u.building_id
        LEFT JOIN re_buildings b ON b.id = mr.building_id
        LEFT JOIN re_building_common_areas ca ON ca.id = mr.common_area_id
        LEFT JOIN re_tenants t ON t.id = mr.tenant_id
        LEFT JOIN employees e ON e.id = mr.assigned_to
        LEFT JOIN user u2 ON u2.id = mr.created_by
        WHERE mr.id = ? AND mr.company_id = ?
    ");
    $stmt->execute([$requestId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

// Load employees for assignment dropdown
$employees = [];
try {
    $stmt = $conn->prepare("SELECT id, full_name, email, phone FROM employees WHERE company_id = ? ORDER BY full_name");
    $stmt->execute([$currentCompanyId]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($employees)) {
        $employees = $conn->query("SELECT id, full_name, email, phone FROM employees ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $employees = $conn->query("SELECT id, full_name, email, phone FROM employees ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
}

$buildings = re_maint_load_buildings($conn, $currentCompanyId);
$tenants = $conn->prepare('SELECT id, first_name, last_name FROM re_tenants WHERE company_id = ? AND is_active = 1 ORDER BY last_name, first_name');
$tenants->execute([$currentCompanyId]);
$tenants = $tenants->fetchAll(PDO::FETCH_ASSOC);

$canEditRequest = !in_array(($request['status'] ?? ''), ['completed', 'cancelled'], true);

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();

    if ($_POST['action'] === 'edit_request') {
        if (!$canEditRequest) {
            $error = 'Completed or cancelled requests cannot be edited.';
        } else {
            $locationType = $_POST['location_type'] ?? 'unit';
            $buildingId = (int)($_POST['building_id'] ?? 0);
            $unitId = (int)($_POST['unit_id'] ?? 0);
            $commonAreaId = (int)($_POST['common_area_id'] ?? 0);
            $tenantId = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
            $leaseId = !empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : null;
            $requestDate = $_POST['request_date'] ?? date('Y-m-d');
            $priority = $_POST['priority'] ?? 'medium';
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $cost = ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : 0;
            $notes = trim($_POST['notes'] ?? '');

            if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
                $priority = 'medium';
            }
            $d = DateTime::createFromFormat('Y-m-d', $requestDate);
            if (!$d || $d->format('Y-m-d') !== $requestDate) {
                $error = 'Invalid request date.';
            } elseif ($description === '') {
                $error = 'Description is required.';
            } else {
                $loc = re_maint_validate_location($conn, $currentCompanyId, $locationType, $buildingId, $unitId, $commonAreaId, false);
                if (!$loc['ok']) {
                    $error = $loc['error'] ?? 'Invalid location.';
                } else {
                    if ($loc['location_type'] !== 'unit') {
                        $tenantId = null;
                        $leaseId = null;
                    }
                    try {
                        $stmt = $conn->prepare("
                            UPDATE re_maintenance_requests
                            SET unit_id = ?, location_type = ?, building_id = ?, common_area_id = ?,
                                tenant_id = ?, lease_id = ?, request_date = ?, priority = ?, category = ?,
                                description = ?, cost = ?, notes = ?, updated_at = NOW()
                            WHERE id = ? AND company_id = ?
                              AND status NOT IN ('completed', 'cancelled')
                        ");
                        $stmt->execute([
                            $loc['unit_id'],
                            $loc['location_type'],
                            $loc['building_id'],
                            $loc['common_area_id'],
                            $tenantId,
                            $leaseId,
                            $requestDate,
                            $priority,
                            $category !== '' ? $category : null,
                            $description,
                            $cost,
                            $notes !== '' ? $notes : null,
                            $requestId,
                            $currentCompanyId,
                        ]);
                        if ($stmt->rowCount() < 1) {
                            // No-op update (same values) still counts as success if row exists and editable
                            $chk = $conn->prepare("SELECT status FROM re_maintenance_requests WHERE id = ? AND company_id = ? LIMIT 1");
                            $chk->execute([$requestId, $currentCompanyId]);
                            $st = (string)($chk->fetchColumn() ?: '');
                            if (in_array($st, ['completed', 'cancelled'], true)) {
                                $error = 'Completed or cancelled requests cannot be edited.';
                            } else {
                                $success = 'Maintenance request updated successfully.';
                            }
                        } else {
                            $success = 'Maintenance request updated successfully.';
                        }
                        if ($success) {
                            try {
                                require_once __DIR__ . '/../../includes/AuditService.php';
                                AuditService::logEvent([
                                    'action' => 'update',
                                    'module' => 'realestate',
                                    'company_id' => (int)$currentCompanyId,
                                    'object_type' => 're_maintenance_requests',
                                    'object_id' => (string)$requestId,
                                    'object_ref' => 'Maintenance #' . $requestId,
                                    'summary' => 'Updated maintenance request #' . $requestId,
                                    'source' => 'user',
                                    'success' => true,
                                ]);
                            } catch (Throwable $ignored) {
                            }
                            $request = $reloadMaintenanceRequest($conn, $requestId, $currentCompanyId) ?: $request;
                            $canEditRequest = !in_array(($request['status'] ?? ''), ['completed', 'cancelled'], true);
                        }
                    } catch (Exception $e) {
                        $error = 'Error: ' . $e->getMessage();
                    }
                }
            }
        }
    } elseif ($_POST['action'] === 'assign_request') {
        $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : null;
        $notes = trim($_POST['notes'] ?? '');
        try {
            $conn->beginTransaction();
            $respondedAt = date('Y-m-d H:i:s');
            $updateNotes = $request['notes'];
            if ($notes) {
                $updateNotes = ($updateNotes ? $updateNotes . "\n\n" : '') . "Assignment Notes (" . date('Y-m-d H:i') . "): " . $notes;
            }
            $stmt = $conn->prepare("
                UPDATE re_maintenance_requests
                SET assigned_to = ?, status = 'in_progress', responded_at = ?,
                    cost = COALESCE(?, cost), notes = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$assignedTo, $respondedAt, $cost, $updateNotes, $requestId, $currentCompanyId]);


            if ($assignedTo) {
                $stmt = $conn->prepare("SELECT id, full_name, email, phone FROM employees WHERE id = ?");
                $stmt->execute([$assignedTo]);
                $employee = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($employee && !empty($employee['email'])) {
                    require_once __DIR__ . '/includes/re_email_helper.php';
                    send_maintenance_assignment_notification($conn, $requestId, $currentCompanyId, $assignedTo, $request, $employee);
                }
            }
            $conn->commit();
            if (($request['status'] ?? '') !== 'in_progress') {
                try {
                    require_once __DIR__ . '/../../includes/tenant_notifications.php';
                    tenant_notification_create($conn, [
                        'company_id' => $currentCompanyId,
                        'lease_id' => (int)($request['lease_id'] ?? 0),
                        'tenant_id' => (int)($request['tenant_id'] ?? 0),
                        'type' => 'maintenance_status',
                        'entity_type' => 'maintenance',
                        'entity_id' => (int)$requestId,
                        'title' => 'Maintenance in progress',
                        'body' => 'Your maintenance request is now in progress.',
                        'dedup_window_minutes' => 10,
                    ]);
                } catch (Throwable $e) {
                    error_log('maintenance assignment tenant notification failed: ' . $e->getMessage());
                }
            }
            $success = "Request assigned successfully.";
            $request = $reloadMaintenanceRequest($conn, $requestId, $currentCompanyId) ?: $request;
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'update_status') {
        $newStatus = $_POST['status'] ?? '';
        $completionNotes = trim($_POST['completion_notes'] ?? '');
        $actualCost = !empty($_POST['actual_cost']) ? (float)$_POST['actual_cost'] : null;
        
        if (in_array($newStatus, ['in_progress', 'completed', 'cancelled'])) {
            try {
                $conn->beginTransaction();
                
                $updateData = [
                    'status' => $newStatus,
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                if ($newStatus === 'completed') {
                    $completedAt = date('Y-m-d H:i:s');
                    $updateData['completed_at'] = $completedAt;
                    if ($completionNotes) {
                        $updateData['notes'] = ($request['notes'] ? $request['notes'] . "\n\n" : '') . 
                                                "Completion Notes (" . date('Y-m-d H:i') . "): " . $completionNotes;
                    }
                    if ($actualCost !== null) {
                        $updateData['cost'] = $actualCost;
                    }
                    
                }
                
                $setClause = [];
                $params = [];
                foreach ($updateData as $key => $value) {
                    if ($key !== 'status') {
                        $setClause[] = "$key = ?";
                        $params[] = $value;
                    }
                }
                $setClause[] = "status = ?";
                $params[] = $newStatus;
                $params[] = $requestId;
                $params[] = $currentCompanyId;
                
                $stmt = $conn->prepare("
                    UPDATE re_maintenance_requests 
                    SET " . implode(', ', $setClause) . "
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute($params);
                
                $conn->commit();

                // Tenant in-app notification: maintenance request status changed
                require_once __DIR__ . '/../../includes/tenant_notifications.php';
                tenant_notification_create($conn, [
                    'company_id' => $currentCompanyId,
                    'lease_id' => (int)($request['lease_id'] ?? 0),
                    'tenant_id' => (int)($request['tenant_id'] ?? 0),
                    'type' => 'maintenance_status',
                    'entity_type' => 'maintenance',
                    'entity_id' => (int)$requestId,
                    'title' => 'Maintenance ' . str_replace('_', ' ', $newStatus),
                    'body' => 'Your maintenance request is now ' . str_replace('_', ' ', $newStatus) . '.',
                ]);

                $success = "Maintenance request status updated to " . ucfirst(str_replace('_', ' ', $newStatus));
                $request = $reloadMaintenanceRequest($conn, $requestId, $currentCompanyId) ?: $request;
                $canEditRequest = !in_array(($request['status'] ?? ''), ['completed', 'cancelled'], true);
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

$editLocType = (string)($request['location_type'] ?? 'unit');
if (!in_array($editLocType, ['unit', 'common_area'], true)) {
    $editLocType = 'unit'; // force reassignment path for any legacy/odd values
}
$editBuildingId = (int)($request['building_id'] ?? 0);
$editUnitId = (int)($request['unit_id'] ?? 0);
$editCommonAreaId = (int)($request['common_area_id'] ?? 0);
$editRequestDate = !empty($request['request_date']) ? date('Y-m-d', strtotime($request['request_date'])) : date('Y-m-d');


function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$matReqForWo = inv_material_requests_fetch_for_maintenance_wo($conn, $currentCompanyId, $requestId);

// Set page title and include layout
$pageTitle = 'Maintenance Request';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Maintenance Request #<?= $requestId ?>
                <?php if (($request['source'] ?? '') === 'ars'): ?>
                <span class="badge bg-dark ms-2 fs-6" title="Created from ARS Home Rentals">ARS</span>
                <?php endif; ?>
            </h1>
            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <?php if (has_permission('inventory_requests.create', MODULE_INVENTORY, $conn) && inv_user_can_access_material_request_create($conn, 'realestate')): ?>
                    <?php
                    $invMatUrl = inv_request_material_create_url($conn, [
                        'source_module' => 'realestate',
                        'source_table' => 're_maintenance_requests',
                        'source_id' => $requestId,
                        'context_building_id' => (int)($request['building_id'] ?? 0),
                        'context_unit_id' => (int)($request['unit_id'] ?? 0),
                        'context_work_order_id' => $requestId,
                        'notes_hint' => 'Maintenance MR #' . $requestId,
                    ]);
                    ?>
                    <a class="btn btn-outline-secondary" href="<?= h($invMatUrl) ?>" title="Opens Inventory material request form">Request inventory materials</a>
                <?php endif; ?>
                <?php if ($canEditRequest): ?>
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editRequestModal">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                <?php endif; ?>
                <?php if ($request['status'] === 'pending' && !$request['assigned_to']): ?>
                    <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#assignModal">
                        <i class="bi bi-person-plus"></i> Assign
                    </button>
                <?php endif; ?>
                <?php if ($request['status'] !== 'completed' && $request['status'] !== 'cancelled'): ?>
                    <a class="btn btn-success" href="maintenance_schedule.php?request_id=<?= $requestId ?>" title="Schedule a work order from this request">
                        <i class="bi bi-calendar-plus"></i> Schedule Work
                    </a>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                        <i class="bi bi-pencil-square"></i> Update Status
                    </button>
                <?php endif; ?>
            </div>
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

        <?php if (isset($_SESSION['email_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= h($_SESSION['email_success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['email_success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['email_warning'])): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= h($_SESSION['email_warning']) ?>
                <br><small><a href="email_logs.php">View Email Logs</a> for details.</small>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['email_warning']); ?>
        <?php endif; ?>

        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                <strong><i class="bi bi-box-seam me-1"></i> Material requests for this work order</strong>
                <a class="btn btn-sm btn-outline-primary" href="my_material_requests.php">My material requests</a>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2">All stock requests linked to this maintenance record. Check before submitting another request to avoid duplicates.</p>
                <?php
                $companyId = $currentCompanyId;
                $rows = $matReqForWo;
                $detailPage = 'material_request_view.php';
                $showRequestedBy = true;
                require __DIR__ . '/../../includes/inventory/partials/material_requests_list_table.php';
                ?>
            </div>
        </div>

        <div class="row">
            <!-- Request Information -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Request Details</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Request Date:</th>
                                <td><?= date('Y-m-d', strtotime($request['request_date'])) ?></td>
                            </tr>
                            <tr>
                                <th>Location:</th>
                                <td><?= h(re_maint_location_label(
                                    $request['building_name'] ?? null,
                                    (string)($request['location_type'] ?? 'unit'),
                                    $request['unit_number'] ?? null,
                                    $request['common_area_name'] ?? null
                                )) ?></td>
                            </tr>
                            <tr>
                                <th>Category:</th>
                                <td><?= h($request['category'] ?: '-') ?></td>
                            </tr>
                            <tr>
                                <th>Priority:</th>
                                <td>
                                    <?php
                                    $priorityClass = [
                                        'low' => 'secondary',
                                        'medium' => 'info',
                                        'high' => 'warning',
                                        'urgent' => 'danger'
                                    ];
                                    $class = $priorityClass[$request['priority']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $class ?>"><?= ucfirst($request['priority']) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <?php
                                    $statusClass = [
                                        'pending' => 'warning',
                                        'in_progress' => 'primary',
                                        'completed' => 'success',
                                        'cancelled' => 'secondary'
                                    ];
                                    $class = $statusClass[$request['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $class ?>"><?= ucfirst(str_replace('_', ' ', $request['status'])) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th>Cost:</th>
                                <td><?= $request['cost'] > 0 ? number_format($request['cost'], 2) . ' AED' : '-' ?></td>
                            </tr>
                            <?php if ($request['completed_at']): ?>
                            <tr>
                                <th>Completed At:</th>
                                <td><?= date('Y-m-d H:i', strtotime($request['completed_at'])) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Assignment Information -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Assignment</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Assigned To:</th>
                                <td><?= h($request['assigned_employee_name'] ?: '-') ?></td>
                            </tr>
                            <?php if ($request['assigned_employee_phone']): ?>
                            <tr>
                                <th>Employee Phone:</th>
                                <td><?= h($request['assigned_employee_phone']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Created By:</th>
                                <td><?= h($request['created_by_name'] ?: 'System') ?></td>
                            </tr>
                            <tr>
                                <th>Created At:</th>
                                <td><?= date('Y-m-d H:i', strtotime($request['created_at'])) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tenant Information -->
        <?php if ($request['tenant_id']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Tenant Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="20%">Name:</th>
                        <td><?= h($request['first_name'] . ' ' . $request['last_name']) ?></td>
                        <th width="20%">Phone:</th>
                        <td><?= h($request['phone'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?= h($request['email'] ?: '-') ?></td>
                        <th></th>
                        <td></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Description -->
        <div class="card mb-4">
            <div class="card-header">
                <h5>Description</h5>
            </div>
            <div class="card-body">
                <p><?= nl2br(h($request['description'])) ?></p>
            </div>
        </div>

        <!-- Notes -->
        <?php if ($request['notes']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Notes</h5>
            </div>
            <div class="card-body">
                <p><?= nl2br(h($request['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Request / Before Photos (created with the request) -->
        <div class="card mb-4" id="requestPhotosSection">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0"><i class="bi bi-images"></i> Request Photos</h5>
                    <small class="text-muted">Photos attached when the request was created (before work)</small>
                </div>
                <?php if ($request['status'] !== 'cancelled'): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            data-bs-toggle="modal" data-bs-target="#uploadRequestPhotoModal">
                        <i class="bi bi-upload"></i> Add request photo
                    </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div id="requestPhotosList">
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-arrow-repeat spin"></i> Loading photos...
                    </div>
                </div>
            </div>
        </div>

        <!-- Completion Photos (maintenance team after/during work) -->
        <div class="card mb-4" id="completionPhotosSection">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0"><i class="bi bi-camera"></i> Completion Photos</h5>
                    <small class="text-muted">For the maintenance team to attach during / after work</small>
                </div>
                <?php if ($request['status'] !== 'cancelled'): ?>
                    <button type="button" class="btn btn-sm btn-primary"
                            data-bs-toggle="modal" data-bs-target="#uploadCompletionPhotoModal">
                        <i class="bi bi-upload"></i> Upload completion photo
                    </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div id="completionPhotosList">
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-arrow-repeat spin"></i> Loading photos...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Request (before) Photo Modal -->
    <div class="modal fade" id="uploadRequestPhotoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Request Photo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="uploadRequestPhotoForm" class="js-photo-upload-form" data-photo-type="before" data-modal="uploadRequestPhotoModal">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="photo_type" value="before">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Photo *</label>
                            <input type="file" name="photo" class="form-control" accept="image/*" required>
                            <small class="text-muted">JPG, PNG, GIF, WEBP — Max 10MB. Saved as a request / before-work photo.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Optional description"></textarea>
                        </div>
                        <div class="progress js-photo-progress" style="display:none;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Upload Completion Photo Modal -->
    <div class="modal fade" id="uploadCompletionPhotoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Completion Photo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="uploadCompletionPhotoForm" class="js-photo-upload-form" data-modal="uploadCompletionPhotoModal">
                    <?php csrf_field(); ?>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Photo Type</label>
                            <select name="photo_type" class="form-select" required>
                                <option value="completion" selected>Completion (After Work)</option>
                                <option value="during">During Work</option>
                                <option value="after">After Work</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Photo *</label>
                            <input type="file" name="photo" class="form-control" accept="image/*" required>
                            <small class="text-muted">JPG, PNG, GIF, WEBP — Max 10MB</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Optional description of the photo"></textarea>
                        </div>
                        <div class="progress js-photo-progress" style="display:none;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Upload Photo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Request Modal -->
    <?php if ($canEditRequest): ?>
    <div class="modal fade" id="editRequestModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" id="editRequestForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="edit_request">
                    <input type="hidden" name="lease_id" id="editLeaseId" value="<?= (int)($request['lease_id'] ?? 0) ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Maintenance Request #<?= (int)$requestId ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Location Type *</label>
                                <select name="location_type" id="editLocationType" class="form-select" required>
                                    <option value="unit" <?= $editLocType === 'unit' ? 'selected' : '' ?>>Unit</option>
                                    <option value="common_area" <?= $editLocType === 'common_area' ? 'selected' : '' ?>>Common Area</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Building *</label>
                                <select name="building_id" id="editBuildingSelect" class="form-select" required>
                                    <option value="">-- Select Building --</option>
                                    <?php foreach ($buildings as $b): ?>
                                        <option value="<?= (int)$b['id'] ?>" <?= $editBuildingId === (int)$b['id'] ? 'selected' : '' ?>>
                                            <?= h($b['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Request Date *</label>
                                <input type="date" class="form-control" name="request_date" value="<?= h($editRequestDate) ?>" required>
                            </div>
                            <div class="col-md-6" id="editUnitWrap">
                                <label class="form-label">Unit *</label>
                                <select name="unit_id" id="editUnitSelect" class="form-select">
                                    <option value="">-- Select Unit --</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="editCommonAreaWrap" style="display:none">
                                <label class="form-label">Common Area *</label>
                                <select name="common_area_id" id="editCommonAreaSelect" class="form-select">
                                    <option value="">-- Select Common Area --</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="editTenantWrap">
                                <label class="form-label">Tenant (Optional)</label>
                                <select name="tenant_id" id="editTenantSelect" class="form-select">
                                    <option value="">-- Select Tenant --</option>
                                    <?php foreach ($tenants as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>" <?= (int)($request['tenant_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>>
                                            <?= h($t['first_name'] . ' ' . $t['last_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Priority *</label>
                                <select name="priority" class="form-select" required>
                                    <?php foreach (['low', 'medium', 'high', 'urgent'] as $p): ?>
                                        <option value="<?= $p ?>" <?= ($request['priority'] ?? '') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Category</label>
                                <input type="text" class="form-control" name="category" value="<?= h($request['category'] ?? '') ?>" placeholder="e.g., Plumbing, Electrical">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Estimated Cost (AED)</label>
                                <input type="number" step="0.01" class="form-control" name="cost" value="<?= h((string)($request['cost'] ?? 0)) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description *</label>
                                <textarea class="form-control" name="description" rows="4" required><?= h($request['description'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="2"><?= h($request['notes'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Assign Modal -->
    <?php if ($request['status'] === 'pending' && !$request['assigned_to']): ?>
    <div class="modal fade" id="assignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Maintenance Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="assign_request">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" value="<?= h(re_maint_location_label(
                                $request['building_name'] ?? null,
                                (string)($request['location_type'] ?? 'unit'),
                                $request['unit_number'] ?? null,
                                $request['common_area_name'] ?? null
                            )) ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign To *</label>
                            <select name="assigned_to" class="form-select" required>
                                <option value="">-- Select Team Member --</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= h($emp['full_name'] ?: 'Employee #' . $emp['id']) ?><?= $emp['email'] ? ' (' . h($emp['email']) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Estimated Cost (AED)</label>
                            <input type="number" step="0.01" name="cost" class="form-control" value="<?= $request['cost'] > 0 ? $request['cost'] : '' ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Assignment notes…"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning"><i class="bi bi-check-circle"></i> Assign & Start</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Update Status Modal -->
    <?php if ($request['status'] !== 'completed' && $request['status'] !== 'cancelled'): ?>
    <div class="modal fade" id="updateStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Maintenance Request Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_status">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required id="statusSelect">
                                <option value="in_progress" <?= $request['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="completed" <?= $request['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= $request['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div id="completionFields" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Actual Cost (AED)</label>
                                <input type="number" step="0.01" name="actual_cost" class="form-control" 
                                       value="<?= $request['cost'] > 0 ? $request['cost'] : '' ?>" 
                                       placeholder="Enter actual cost if different from estimated">
                                <small class="text-muted">Leave empty to keep estimated cost</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Completion Notes</label>
                                <textarea name="completion_notes" class="form-control" rows="3" 
                                          placeholder="Add notes about the completion, work done, materials used, etc."></textarea>
                            </div>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> 
                                <strong>Tip:</strong> You can upload completion photos using the "Upload Photo" button in the Photos section below.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const maintenanceRequestId = <?= $requestId ?>;

        <?php if ($canEditRequest): ?>
        (function() {
            const locationType = document.getElementById('editLocationType');
            const buildingSelect = document.getElementById('editBuildingSelect');
            const unitSelect = document.getElementById('editUnitSelect');
            const commonAreaSelect = document.getElementById('editCommonAreaSelect');
            const unitWrap = document.getElementById('editUnitWrap');
            const caWrap = document.getElementById('editCommonAreaWrap');
            const tenantWrap = document.getElementById('editTenantWrap');
            const prefUnit = <?= (int)$editUnitId ?>;
            const prefCa = <?= (int)$editCommonAreaId ?>;

            function syncEditLocationUi() {
                const isUnit = locationType.value === 'unit';
                unitWrap.style.display = isUnit ? '' : 'none';
                caWrap.style.display = isUnit ? 'none' : '';
                tenantWrap.style.display = isUnit ? '' : 'none';
                unitSelect.required = isUnit;
                commonAreaSelect.required = !isUnit;
                if (!isUnit) {
                    unitSelect.value = '';
                    document.getElementById('editLeaseId').value = '';
                    document.getElementById('editTenantSelect').value = '';
                } else {
                    commonAreaSelect.value = '';
                }
            }

            function loadEditUnits(selected) {
                const bid = buildingSelect.value;
                unitSelect.innerHTML = '<option value="">-- Select Unit --</option>';
                if (!bid) return;
                fetch('ajax_get_units.php?building_id=' + encodeURIComponent(bid))
                    .then(r => r.json())
                    .then(d => {
                        (d.units || []).forEach(u => {
                            const o = document.createElement('option');
                            o.value = u.id;
                            o.textContent = u.unit_number;
                            if (String(u.id) === String(selected)) o.selected = true;
                            unitSelect.appendChild(o);
                        });
                    }).catch(() => {});
            }

            function loadEditCommonAreas(selected) {
                const bid = buildingSelect.value;
                commonAreaSelect.innerHTML = '<option value="">-- Select Common Area --</option>';
                if (!bid) return;
                fetch('ajax_get_common_areas.php?building_id=' + encodeURIComponent(bid))
                    .then(r => r.json())
                    .then(d => {
                        (d.common_areas || []).forEach(a => {
                            const o = document.createElement('option');
                            o.value = a.id;
                            o.textContent = a.area_name + (a.area_type ? ' (' + a.area_type.replace(/_/g, ' ') + ')' : '');
                            if (String(a.id) === String(selected)) o.selected = true;
                            commonAreaSelect.appendChild(o);
                        });
                    }).catch(() => {});
            }

            locationType.addEventListener('change', function() {
                syncEditLocationUi();
                if (locationType.value === 'unit') loadEditUnits('');
                else loadEditCommonAreas('');
            });
            buildingSelect.addEventListener('change', function() {
                if (locationType.value === 'unit') loadEditUnits('');
                else loadEditCommonAreas('');
            });
            unitSelect.addEventListener('change', function() {
                const unitId = this.value;
                document.getElementById('editLeaseId').value = '';
                if (!unitId) return;
                fetch('ajax_get_lease.php?unit_id=' + encodeURIComponent(unitId))
                    .then(r => r.json())
                    .then(data => {
                        if (data.lease_id) {
                            document.getElementById('editLeaseId').value = data.lease_id;
                            if (data.tenant_id) document.getElementById('editTenantSelect').value = data.tenant_id;
                        }
                    }).catch(() => {});
            });

            syncEditLocationUi();
            if (buildingSelect.value) {
                if (locationType.value === 'unit') loadEditUnits(prefUnit);
                else loadEditCommonAreas(prefCa);
            }
        })();
        <?php endif; ?>
        
        // Show/hide completion fields based on status selection
        document.getElementById('statusSelect')?.addEventListener('change', function() {
            const completionFields = document.getElementById('completionFields');
            if (this.value === 'completed') {
                completionFields.style.display = 'block';
            } else {
                completionFields.style.display = 'none';
            }
        });
        
        // Trigger on page load if status is already 'completed'
        const statusSelect = document.getElementById('statusSelect');
        if (statusSelect && statusSelect.value === 'completed') {
            document.getElementById('completionFields').style.display = 'block';
        }

        function loadPhotos() {
            fetch(`ajax_get_maintenance_photos.php?maintenance_request_id=${maintenanceRequestId}`)
                .then(response => response.json())
                .then(data => {
                    const all = (data.success && Array.isArray(data.photos)) ? data.photos : [];
                    const requestPhotos = all.filter(p => p.photo_type === 'before');
                    const completionPhotos = all.filter(p => p.photo_type !== 'before');
                    displayPhotos(requestPhotos, 'requestPhotosList', 'No request photos attached yet.');
                    displayPhotos(completionPhotos, 'completionPhotosList', 'No completion photos yet. The maintenance team can upload them here after work.');
                })
                .catch(error => {
                    console.error('Error loading photos:', error);
                    ['requestPhotosList', 'completionPhotosList'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.innerHTML = '<div class="alert alert-danger mb-0">Error loading photos.</div>';
                    });
                });
        }

        function displayPhotos(photos, containerId, emptyMessage) {
            const photosList = document.getElementById(containerId);
            if (!photosList) return;
            if (!photos || photos.length === 0) {
                photosList.innerHTML = `<div class="text-center text-muted py-3">${escapeHtml(emptyMessage)}</div>`;
                return;
            }

            const photoTypeLabels = {
                before: 'Request / Before',
                during: 'During Work',
                after: 'After Work',
                completion: 'Completion'
            };
            const badgeClass = {
                before: 'bg-secondary',
                during: 'bg-primary',
                after: 'bg-info',
                completion: 'bg-success'
            };

            let html = '<div class="row g-3">';
            photos.forEach(photo => {
                const type = photo.photo_type || '';
                html += `
                    <div class="col-md-4 col-sm-6">
                        <div class="card h-100">
                            <a href="../../${escapeHtml(photo.file_path)}" target="_blank" class="text-decoration-none">
                                <img src="../../${escapeHtml(photo.file_path)}" class="card-img-top"
                                     style="height: 200px; object-fit: cover; cursor: pointer;"
                                     alt="${escapeHtml(photo.description || photo.file_name || 'Photo')}">
                            </a>
                            <div class="card-body">
                                <h6 class="card-title">
                                    <span class="badge ${badgeClass[type] || 'bg-secondary'}">${escapeHtml(photoTypeLabels[type] || type)}</span>
                                </h6>
                                <p class="card-text small text-muted mb-1">
                                    <strong>${escapeHtml(photo.file_name || '')}</strong>
                                </p>
                                ${photo.description ? `<p class="card-text small">${escapeHtml(photo.description)}</p>` : ''}
                                <p class="card-text small text-muted">
                                    Uploaded: ${photo.created_at ? new Date(photo.created_at).toLocaleString() : '—'}
                                </p>
                                <button type="button" class="btn btn-sm btn-danger" onclick="deletePhoto(${Number(photo.id)})">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            photosList.innerHTML = html;
        }

        function deletePhoto(photoId) {
            if (!confirm('Are you sure you want to delete this photo?')) {
                return;
            }

            const formData = new FormData();
            formData.append('photo_id', photoId);

            fetch('ajax_delete_maintenance_photo.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadPhotos();
                } else {
                    alert('Error: ' + (data.error || 'Failed to delete photo'));
                }
            })
            .catch(error => {
                console.error('Error deleting photo:', error);
                alert('Error deleting photo');
            });
        }

        document.querySelectorAll('.js-photo-upload-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('maintenance_request_id', maintenanceRequestId);
                if (this.dataset.photoType) {
                    formData.set('photo_type', this.dataset.photoType);
                }

                const progressBar = this.querySelector('.js-photo-progress');
                const progressBarInner = progressBar ? progressBar.querySelector('.progress-bar') : null;
                if (progressBar) {
                    progressBar.style.display = 'block';
                    if (progressBarInner) progressBarInner.style.width = '40%';
                }

                fetch('ajax_maintenance_photo_upload.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (progressBar) progressBar.style.display = 'none';
                    if (data.success) {
                        const modalEl = document.getElementById(this.dataset.modal || '');
                        if (modalEl) {
                            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                            modal.hide();
                        }
                        this.reset();
                        if (this.dataset.photoType) {
                            const hidden = this.querySelector('input[name="photo_type"]');
                            if (hidden) hidden.value = this.dataset.photoType;
                        }
                        loadPhotos();
                    } else {
                        alert('Error: ' + (data.error || 'Failed to upload photo'));
                    }
                })
                .catch(error => {
                    if (progressBar) progressBar.style.display = 'none';
                    console.error('Error uploading photo:', error);
                    alert('Error uploading photo');
                });
            });
        });

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        loadPhotos();
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

