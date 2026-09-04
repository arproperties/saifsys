<?php
/**
 * Real Estate Module - Move-In View & Workflow
 * Complete move-in process with checklist, meter readings, photos, and approval
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

$moveInId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$moveInId) {
    header('Location: move_in.php');
    exit;
}

// Get move-in details
$moveIn = $conn->prepare("
    SELECT 
        mi.*,
        l.lease_number,
        l.start_date as lease_start,
        l.end_date as lease_end,
        l.annual_rent,
        l.monthly_rent,
        l.number_of_installments,
        l.security_deposit,
        u.unit_number,
        u.unit_type,
        u.area_sqm,
        b.name as building_name,
        b.address as building_address,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        t.id_number,
        u2.username as created_by_name,
        u3.username as approved_by_name
    FROM re_move_ins mi
    JOIN re_leases l ON l.id = mi.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN user u2 ON u2.id = mi.created_by
    LEFT JOIN user u3 ON u3.id = mi.approved_by
    WHERE mi.id = ? AND mi.company_id = ?
");
$moveIn->execute([$moveInId, $currentCompanyId]);
$moveIn = $moveIn->fetch(PDO::FETCH_ASSOC);

// Get first installment amount
$firstInstallment = null;
if ($moveIn) {
    $stmt = $conn->prepare("
        SELECT amount 
        FROM re_lease_installments 
        WHERE lease_id = ? 
        ORDER BY installment_date ASC 
        LIMIT 1
    ");
    $stmt->execute([$moveIn['lease_id']]);
    $firstInstallmentRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $firstInstallment = $firstInstallmentRow['amount'] ?? null;
}

// Calculate annual rent if not available
if ($moveIn && empty($moveIn['annual_rent'])) {
    $moveIn['annual_rent'] = $moveIn['monthly_rent'] * ($moveIn['number_of_installments'] ?? 12);
}

if (!$moveIn) {
    header('Location: move_in.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    csrf_verify();
    
    $action = $_POST['action'];
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($action) {
            case 'update_checklist_item':
                $itemId = (int)$_POST['item_id'];
                $isCompleted = !empty($_POST['is_completed']) ? 1 : 0;
                $notes = $_POST['notes'] ?? '';
                
                $stmt = $conn->prepare("
                    UPDATE re_move_in_checklist_items
                    SET is_completed = ?,
                        completed_by = ?,
                        completed_at = ?,
                        notes = ?,
                        updated_at = NOW()
                    WHERE id = ? AND move_in_id = ? AND company_id = ?
                ");
                $completedAt = $isCompleted ? date('Y-m-d H:i:s') : null;
                $stmt->execute([$isCompleted, $currentUserId, $completedAt, $notes, $itemId, $moveInId, $currentCompanyId]);
                
                // Update move-in status to in_progress if any item is completed
                if ($isCompleted) {
                    $conn->prepare("
                        UPDATE re_move_ins 
                        SET status = 'in_progress' 
                        WHERE id = ? AND status = 'pending'
                    ")->execute([$moveInId]);
                }
                
                $response = ['success' => true, 'message' => 'Checklist item updated'];
                break;
                
            case 'update_verification':
                $field = $_POST['field'] ?? '';
                $value = !empty($_POST['value']) ? 1 : 0;
                
                if (in_array($field, ['contract_verified', 'payment_confirmed', 'keys_handed_over', 'keys_received_by_tenant', 'inspection_completed'])) {
                    $updateField = $field;
                    $updateByField = $field . '_by';
                    $updateAtField = $field . '_at';
                    
                    $stmt = $conn->prepare("
                        UPDATE re_move_ins
                        SET {$updateField} = ?,
                            {$updateByField} = ?,
                            {$updateAtField} = ?
                        WHERE id = ? AND company_id = ?
                    ");
                    $updateAt = $value ? date('Y-m-d H:i:s') : null;
                    $updateBy = $value ? $currentUserId : null;
                    $stmt->execute([$value, $updateBy, $updateAt, $moveInId, $currentCompanyId]);
                    
                    $response = ['success' => true, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' updated'];
                }
                break;
                
            case 'save_meter_reading':
                $meterType = $_POST['meter_type'] ?? '';
                $readingValue = $_POST['reading_value'] ?? '';
                $meterNumber = $_POST['meter_number'] ?? '';
                $notes = $_POST['notes'] ?? '';
                
                if ($meterType && $readingValue) {
                    $stmt = $conn->prepare("
                        INSERT INTO re_meter_readings
                        (company_id, lease_id, reading_type, meter_type, reading_value, reading_date, meter_number, notes, taken_by)
                        VALUES (?, ?, 'move_in', ?, ?, CURDATE(), ?, ?, ?)
                    ");
                    $stmt->execute([$currentCompanyId, $moveIn['lease_id'], $meterType, $readingValue, $meterNumber, $notes, $currentUserId]);
                    $response = ['success' => true, 'message' => 'Meter reading saved'];
                }
                break;
                
            case 'complete_move_in':
                // Check if all required items are completed
                $requiredCheck = $conn->prepare("
                    SELECT COUNT(*) as incomplete
                    FROM re_move_in_checklist_items
                    WHERE move_in_id = ? AND is_required = 1 AND is_completed = 0
                ");
                $requiredCheck->execute([$moveInId]);
                $incomplete = $requiredCheck->fetchColumn();
                
                if ($incomplete > 0) {
                    $response = ['success' => false, 'message' => 'Please complete all required checklist items before finalizing move-in.'];
                } else {
                    $conn->beginTransaction();
                    
                    // Update move-in status
                    $stmt = $conn->prepare("
                        UPDATE re_move_ins
                        SET status = 'completed',
                            approved_by = ?,
                            approved_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$currentUserId, $moveInId, $currentCompanyId]);
                    
                    // Update lease
                    $conn->prepare("
                        UPDATE re_leases
                        SET move_in_completed = 1
                        WHERE id = ?
                    ")->execute([$moveIn['lease_id']]);
                    
                    // Update unit status to occupied
                    $conn->prepare("
                        UPDATE re_units
                        SET status = 'occupied'
                        WHERE id = (SELECT unit_id FROM re_leases WHERE id = ?)
                    ")->execute([$moveIn['lease_id']]);
                    
                    $conn->commit();
                    $response = ['success' => true, 'message' => 'Move-in completed successfully!', 'redirect' => 'move_in.php'];
                }
                break;
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// Get checklist items
$checklistItems = $conn->prepare("
    SELECT * FROM re_move_in_checklist_items
    WHERE move_in_id = ?
    ORDER BY display_order, id
");
$checklistItems->execute([$moveInId]);
$checklistItems = $checklistItems->fetchAll(PDO::FETCH_ASSOC);

// Get meter readings
$meterReadings = $conn->prepare("
    SELECT * FROM re_meter_readings
    WHERE lease_id = ? AND reading_type = 'move_in'
    ORDER BY meter_type, reading_date DESC
");
$meterReadings->execute([$moveIn['lease_id']]);
$meterReadings = $meterReadings->fetchAll(PDO::FETCH_ASSOC);

// Get photos
$photos = $conn->prepare("
    SELECT * FROM re_move_in_photos
    WHERE move_in_id = ?
    ORDER BY uploaded_at DESC
");
$photos->execute([$moveInId]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);

// Calculate progress
$totalItems = count($checklistItems);
$completedItems = count(array_filter($checklistItems, fn($item) => $item['is_completed']));
$progressPercent = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Move-In Details';
$pageStyles = '
    .checklist-item { border-left: 4px solid #dee2e6; padding: 15px; margin-bottom: 10px; background: #f8f9fa; }
    .checklist-item.completed { border-left-color: #28a745; background: #d4edda; }
    .checklist-item.required { border-left-color: #ffc107; }
    .verification-badge { font-size: 0.9rem; }
';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1>Move-In #<?= $moveInId ?></h1>
                <p class="text-muted mb-0">
                    <span class="badge bg-<?= $moveIn['status'] === 'completed' ? 'success' : ($moveIn['status'] === 'in_progress' ? 'info' : 'warning') ?>">
                        <?= ucfirst(str_replace('_', ' ', $moveIn['status'])) ?>
                    </span>
                    • Move-In Date: <?= date('M d, Y', strtotime($moveIn['move_in_date'])) ?>
                </p>
            </div>
            <a href="move_in.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
        </div>

        <!-- Progress Bar -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Move-In Progress</h5>
                <div class="progress" style="height: 30px;">
                    <div class="progress-bar <?= $progressPercent == 100 ? 'bg-success' : ($progressPercent >= 50 ? 'bg-info' : 'bg-warning') ?>" 
                         role="progressbar" style="width: <?= $progressPercent ?>%">
                        <?= $progressPercent ?>% Complete (<?= $completedItems ?>/<?= $totalItems ?> items)
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Checklist & Details -->
            <div class="col-md-8">
                <!-- Lease Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-text"></i> Lease Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Lease Number:</strong> <?= h($moveIn['lease_number']) ?><br>
                                <strong>Building:</strong> <?= h($moveIn['building_name']) ?><br>
                                <strong>Unit:</strong> <?= h($moveIn['unit_number']) ?> (<?= h($moveIn['unit_type']) ?>)<br>
                                <strong>Annual Rent:</strong> <?= number_format($moveIn['annual_rent'], 2) ?> AED<br>
                                <strong>Number of Installments:</strong> <?= h($moveIn['number_of_installments'] ?? '-') ?><br>
                                <strong>First Installment Amount:</strong> <?= $firstInstallment ? number_format($firstInstallment, 2) . ' AED' : '-' ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Tenant:</strong> <?= h($moveIn['first_name'] . ' ' . $moveIn['last_name']) ?><br>
                                <strong>Phone:</strong> <?= h($moveIn['phone']) ?><br>
                                <strong>Email:</strong> <?= h($moveIn['email']) ?><br>
                                <strong>Security Deposit:</strong> <?= number_format($moveIn['security_deposit'], 2) ?> AED
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Verifications -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-check-circle"></i> Quick Verifications</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>Contract Verified</span>
                                    <div>
                                        <span class="verification-badge badge bg-<?= $moveIn['contract_verified'] ? 'success' : 'secondary' ?>">
                                            <?= $moveIn['contract_verified'] ? 'Yes' : 'No' ?>
                                        </span>
                                        <button class="btn btn-sm btn-outline-primary ms-2" onclick="toggleVerification('contract_verified')">
                                            <i class="bi bi-<?= $moveIn['contract_verified'] ? 'x' : 'check' ?>"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>Payment Confirmed</span>
                                    <div>
                                        <span class="verification-badge badge bg-<?= $moveIn['payment_confirmed'] ? 'success' : 'secondary' ?>">
                                            <?= $moveIn['payment_confirmed'] ? 'Yes' : 'No' ?>
                                        </span>
                                        <button class="btn btn-sm btn-outline-primary ms-2" onclick="toggleVerification('payment_confirmed')">
                                            <i class="bi bi-<?= $moveIn['payment_confirmed'] ? 'x' : 'check' ?>"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>Keys Handed Over</span>
                                    <div>
                                        <span class="verification-badge badge bg-<?= $moveIn['keys_handed_over'] ? 'success' : 'secondary' ?>">
                                            <?= $moveIn['keys_handed_over'] ? 'Yes' : 'No' ?>
                                        </span>
                                        <button class="btn btn-sm btn-outline-primary ms-2" onclick="toggleVerification('keys_handed_over')">
                                            <i class="bi bi-<?= $moveIn['keys_handed_over'] ? 'x' : 'check' ?>"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>Inspection Completed</span>
                                    <div>
                                        <span class="verification-badge badge bg-<?= $moveIn['inspection_completed'] ? 'success' : 'secondary' ?>">
                                            <?= $moveIn['inspection_completed'] ? 'Yes' : 'No' ?>
                                        </span>
                                        <button class="btn btn-sm btn-outline-primary ms-2" onclick="toggleVerification('inspection_completed')">
                                            <i class="bi bi-<?= $moveIn['inspection_completed'] ? 'x' : 'check' ?>"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Checklist -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list-check"></i> Move-In Checklist</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($checklistItems)): ?>
                            <p class="text-muted">No checklist items found.</p>
                        <?php else: ?>
                            <?php foreach ($checklistItems as $item): ?>
                                <div class="checklist-item <?= $item['is_completed'] ? 'completed' : '' ?> <?= $item['is_required'] ? 'required' : '' ?>">
                                    <div class="form-check">
                                        <input class="form-check-input checklist-checkbox" 
                                               type="checkbox" 
                                               data-item-id="<?= $item['id'] ?>"
                                               <?= $item['is_completed'] ? 'checked' : '' ?>
                                               onchange="updateChecklistItem(<?= $item['id'] ?>, this.checked)">
                                        <label class="form-check-label w-100">
                                            <strong><?= h(str_replace('First month rent', 'First Installment rent', $item['item_name'])) ?></strong>
                                            <?php if ($item['is_required']): ?>
                                                <span class="badge bg-warning ms-2">Required</span>
                                            <?php endif; ?>
                                            <?php if ($item['item_description']): ?>
                                                <br><small class="text-muted"><?= h(str_replace('first month', 'first installment', $item['item_description'])) ?></small>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                    <?php if ($item['is_completed'] && $item['completed_at']): ?>
                                        <small class="text-success">
                                            <i class="bi bi-check-circle"></i> Completed on <?= date('M d, Y H:i', strtotime($item['completed_at'])) ?>
                                        </small>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <textarea class="form-control form-control-sm notes-field" 
                                                  data-item-id="<?= $item['id'] ?>"
                                                  placeholder="Add notes..."
                                                  onblur="saveNotes(<?= $item['id'] ?>, this.value)"><?= h($item['notes'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Meter Readings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-speedometer"></i> Meter Readings</h5>
                    </div>
                    <div class="card-body">
                        <form id="meterReadingForm" class="mb-3">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <select name="meter_type" class="form-select form-select-sm" required>
                                        <option value="">Meter Type</option>
                                        <option value="electricity">Electricity</option>
                                        <option value="water">Water</option>
                                        <option value="gas">Gas</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" name="reading_value" class="form-control form-control-sm" placeholder="Reading" required>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" name="meter_number" class="form-control form-control-sm" placeholder="Meter #">
                                </div>
                                <div class="col-md-3">
                                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <div id="meterReadingsList">
                            <?php if (empty($meterReadings)): ?>
                                <p class="text-muted">No meter readings recorded yet.</p>
                            <?php else: ?>
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Reading</th>
                                            <th>Meter #</th>
                                            <th>Date</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($meterReadings as $reading): ?>
                                            <tr>
                                                <td><?= ucfirst($reading['meter_type']) ?></td>
                                                <td><?= number_format($reading['reading_value'], 2) ?></td>
                                                <td><?= h($reading['meter_number'] ?: '-') ?></td>
                                                <td><?= date('M d, Y', strtotime($reading['reading_date'])) ?></td>
                                                <td><?= h($reading['notes'] ?: '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Actions & Summary -->
            <div class="col-md-4">
                <!-- Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-gear"></i> Actions</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($moveIn['status'] !== 'completed'): ?>
                            <button class="btn btn-success w-100 mb-2" onclick="completeMoveIn()">
                                <i class="bi bi-check-circle"></i> Complete Move-In
                            </button>
                        <?php endif; ?>
                        <a href="lease_view.php?id=<?= $moveIn['lease_id'] ?>" class="btn btn-outline-primary w-100 mb-2">
                            <i class="bi bi-file-text"></i> View Lease
                        </a>
                        <a href="move_in.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>

                <!-- Summary -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Summary</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Created:</strong> <?= date('M d, Y H:i', strtotime($moveIn['created_at'])) ?></p>
                        <?php if ($moveIn['created_by_name']): ?>
                            <p><strong>Created By:</strong> <?= h($moveIn['created_by_name']) ?></p>
                        <?php endif; ?>
                        <?php if ($moveIn['approved_by_name']): ?>
                            <p><strong>Approved By:</strong> <?= h($moveIn['approved_by_name']) ?></p>
                            <p><strong>Approved At:</strong> <?= date('M d, Y H:i', strtotime($moveIn['approved_at'])) ?></p>
                        <?php endif; ?>
                        <?php if ($moveIn['notes']): ?>
                            <hr>
                            <p><strong>Notes:</strong></p>
                            <p><?= nl2br(h($moveIn['notes'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = '<?= csrf_token() ?>';
        
        function updateChecklistItem(itemId, isCompleted) {
            const checkbox = event.target;
            checkbox.disabled = true;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'update_checklist_item',
                    item_id: itemId,
                    is_completed: isCompleted ? 1 : 0,
                    _csrf: csrfToken
                })
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('Network response was not ok');
                }
                return r.json();
            })
            .then(data => {
                if (data.success) {
                    // Update progress bar immediately
                    updateProgressBar();
                    // Reload after a short delay to show the change
                    setTimeout(() => location.reload(), 300);
                } else {
                    checkbox.checked = !isCompleted; // Revert checkbox
                    checkbox.disabled = false;
                    alert(data.message || 'Error updating checklist item');
                }
            })
            .catch(error => {
                checkbox.checked = !isCompleted; // Revert checkbox
                checkbox.disabled = false;
                console.error('Error:', error);
                alert('Error updating checklist item: ' + error.message);
            });
        }
        
        function saveNotes(itemId, notes) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'update_checklist_item',
                    item_id: itemId,
                    notes: notes,
                    _csrf: csrfToken
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Show a subtle success indicator
                    const textarea = event.target;
                    const originalBg = textarea.style.backgroundColor;
                    textarea.style.backgroundColor = '#d4edda';
                    setTimeout(() => {
                        textarea.style.backgroundColor = originalBg;
                    }, 1000);
                }
            })
            .catch(error => {
                console.error('Error saving notes:', error);
            });
        }
        
        function toggleVerification(field) {
            const button = event.target.closest('button');
            button.disabled = true;
            
            const currentValue = <?= json_encode([
                'contract_verified' => $moveIn['contract_verified'],
                'payment_confirmed' => $moveIn['payment_confirmed'],
                'keys_handed_over' => $moveIn['keys_handed_over'],
                'inspection_completed' => $moveIn['inspection_completed']
            ]) ?>[field];
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'update_verification',
                    field: field,
                    value: currentValue ? 0 : 1,
                    _csrf: csrfToken
                })
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('Network response was not ok');
                }
                return r.json();
            })
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    button.disabled = false;
                    alert(data.message || 'Error updating verification');
                }
            })
            .catch(error => {
                button.disabled = false;
                console.error('Error:', error);
                alert('Error updating verification: ' + error.message);
            });
        }
        
        function updateProgressBar() {
            // Count completed items
            const checkboxes = document.querySelectorAll('.checklist-checkbox');
            const total = checkboxes.length;
            const completed = Array.from(checkboxes).filter(cb => cb.checked).length;
            const percent = total > 0 ? Math.round((completed / total) * 100) : 0;
            
            // Update progress bar
            const progressBar = document.querySelector('.progress-bar');
            if (progressBar) {
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '% Complete (' + completed + '/' + total + ' items)';
                progressBar.className = 'progress-bar ' + (percent == 100 ? 'bg-success' : (percent >= 50 ? 'bg-info' : 'bg-warning'));
            }
        }
        
        const meterReadingForm = document.getElementById('meterReadingForm');
        if (meterReadingForm) {
            meterReadingForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
                
                const formData = new FormData(this);
                formData.append('action', 'save_meter_reading');
                formData.append('_csrf', csrfToken);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(r => {
                    if (!r.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return r.json();
                })
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                        alert(data.message || 'Error saving meter reading');
                    }
                })
                .catch(error => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    console.error('Error:', error);
                    alert('Error saving meter reading: ' + error.message);
                });
            });
        }
        
        function completeMoveIn() {
            if (!confirm('Are you sure you want to complete this move-in? This will mark the unit as occupied.')) {
                return;
            }
            
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'complete_move_in',
                    _csrf: csrfToken
                })
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('Network response was not ok');
                }
                return r.json();
            })
            .then(data => {
                if (data.success) {
                    alert(data.message || 'Move-in completed successfully!');
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        location.reload();
                    }
                } else {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    alert(data.message || 'Error completing move-in');
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                console.error('Error:', error);
                alert('Error completing move-in: ' + error.message);
            });
        }
        
        // Initialize progress bar on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateProgressBar();
        });
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>



