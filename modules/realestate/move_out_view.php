<?php
/**
 * Real Estate Module - Move-Out View & Workflow
 * Complete move-out process with inspection, damage assessment, and deposit calculation
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/security_deposit_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

$moveOutId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$moveOutId) {
    header('Location: move_out.php');
    exit;
}

// Get move-out details
$moveOut = $conn->prepare("
    SELECT 
        mo.*,
        mon.notice_date,
        mon.intended_move_out_date,
        mon.notice_type,
        mon.notice_reason,
        l.lease_number,
        l.start_date as lease_start,
        l.end_date as lease_end,
        l.monthly_rent,
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
    FROM re_move_outs mo
    JOIN re_leases l ON l.id = mo.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_move_out_notices mon ON mon.id = mo.move_out_notice_id
    LEFT JOIN user u2 ON u2.id = mo.created_by
    LEFT JOIN user u3 ON u3.id = mo.approved_by
    WHERE mo.id = ? AND mo.company_id = ?
");
$moveOut->execute([$moveOutId, $currentCompanyId]);
$moveOut = $moveOut->fetch(PDO::FETCH_ASSOC);

if (!$moveOut) {
    header('Location: move_out.php');
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
            case 'schedule_inspection':
                $inspectionDate = $_POST['inspection_date'] ?? '';
                if ($inspectionDate) {
                    $stmt = $conn->prepare("
                        UPDATE re_move_outs
                        SET inspection_scheduled_date = ?,
                            status = 'inspection_scheduled'
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$inspectionDate, $moveOutId, $currentCompanyId]);
                    $response = ['success' => true, 'message' => 'Inspection scheduled'];
                }
                break;
                
            case 'complete_inspection':
                $inspectionNotes = $_POST['inspection_notes'] ?? '';
                $keysReturned = !empty($_POST['keys_returned']) ? 1 : 0;
                
                $stmt = $conn->prepare("
                    UPDATE re_move_outs
                    SET inspection_completed = 1,
                        inspection_completed_by = ?,
                        inspection_completed_at = NOW(),
                        keys_returned = ?,
                        keys_returned_by = ?,
                        keys_returned_at = ?,
                        final_inspection_notes = ?,
                        status = 'inspection_completed'
                    WHERE id = ? AND company_id = ?
                ");
                $keysReturnedAt = $keysReturned ? date('Y-m-d H:i:s') : null;
                $keysReturnedBy = $keysReturned ? $currentUserId : null;
                $stmt->execute([
                    $currentUserId,
                    $keysReturned,
                    $keysReturnedBy,
                    $keysReturnedAt,
                    $inspectionNotes,
                    $moveOutId,
                    $currentCompanyId
                ]);
                $response = ['success' => true, 'message' => 'Inspection completed'];
                break;
                
            case 'add_damage':
                $damageDescription = $_POST['damage_description'] ?? '';
                $roomArea = $_POST['room_area'] ?? '';
                $damageType = $_POST['damage_type'] ?? 'minor';
                $repairCost = $_POST['repair_cost'] ?? 0;
                $notes = $_POST['notes'] ?? '';
                
                if ($damageDescription && $repairCost > 0) {
                    $stmt = $conn->prepare("
                        INSERT INTO re_move_out_damages
                        (company_id, move_out_id, damage_description, room_area, damage_type, repair_cost, notes, assessed_by, assessed_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $currentCompanyId,
                        $moveOutId,
                        $damageDescription,
                        $roomArea,
                        $damageType,
                        $repairCost,
                        $notes,
                        $currentUserId
                    ]);
                    
                    // Update total damage assessment
                    $damageTotal = $conn->prepare("
                        SELECT SUM(repair_cost) FROM re_move_out_damages WHERE move_out_id = ?
                    ");
                    $damageTotal->execute([$moveOutId]);
                    $total = (float)$damageTotal->fetchColumn();
                    
                    $conn->prepare("
                        UPDATE re_move_outs SET damage_assessment_total = ? WHERE id = ?
                    ")->execute([$total, $moveOutId]);
                    
                    $response = ['success' => true, 'message' => 'Damage added', 'damage_id' => $conn->lastInsertId()];
                }
                break;
                
            case 'calculate_deposit':
                $result = re_sd_prepare_move_out_settlement($conn, $currentCompanyId, $moveOutId, $currentUserId ? (int)$currentUserId : null);
                if (!empty($result['success'])) {
                    $response = [
                        'success' => true,
                        'message' => 'Deposit settlement preview prepared. Review and approve deductions before processing any refund.',
                        'received' => $result['received'] ?? 0,
                        'damage_total' => $result['damage_total'] ?? 0,
                    ];
                } else {
                    $response = ['success' => false, 'message' => $result['error'] ?? 'Could not prepare deposit settlement.'];
                }
                break;
                
            case 'approve_deposit_settlement':
                $approvalReason = trim((string)($_POST['approval_reason'] ?? ''));
                $result = re_sd_approve_move_out_deductions($conn, $currentCompanyId, $moveOutId, $currentUserId ? (int)$currentUserId : null, $approvalReason);
                if (!empty($result['success'])) {
                    $response = [
                        'success' => true,
                        'message' => 'Deposit settlement approved. Refund amount: AED ' . number_format((float)($result['refund'] ?? 0), 2) . '.',
                    ];
                } else {
                    $response = ['success' => false, 'message' => $result['error'] ?? 'Could not approve deposit settlement.'];
                }
                break;

            case 'process_deposit_refund':
                $refundMethod = $_POST['refund_method'] ?? 'bank_transfer';
                $refundReference = $_POST['refund_reference'] ?? '';
                $result = re_sd_process_move_out_refund($conn, $currentCompanyId, $moveOutId, $refundMethod, $refundReference, $currentUserId ? (int)$currentUserId : null);
                if (!empty($result['success'])) {
                    $response = [
                        'success' => true,
                        'message' => 'Approved security deposit settlement processed. Journal #' . (int)($result['journal_id'] ?? 0) . '.',
                    ];
                } else {
                    $response = ['success' => false, 'message' => $result['error'] ?? 'Could not process security deposit settlement.'];
                }
                break;
                
            case 'complete_move_out':
                // Check if inspection is completed
                if (!$moveOut['inspection_completed']) {
                    $response = ['success' => false, 'message' => 'Please complete the final inspection first.'];
                } else {
                    $conn->beginTransaction();
                    
                    // Update move-out status
                    $stmt = $conn->prepare("
                        UPDATE re_move_outs
                        SET status = 'completed',
                            approved_by = ?,
                            approved_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$currentUserId, $moveOutId, $currentCompanyId]);
                    
                    // Update lease
                    $conn->prepare("
                        UPDATE re_leases
                        SET move_out_completed = 1,
                            status = 'expired'
                        WHERE id = ?
                    ")->execute([$moveOut['lease_id']]);
                    
                    // Update unit status to vacant
                    $conn->prepare("
                        UPDATE re_units
                        SET status = 'vacant'
                        WHERE id = (SELECT unit_id FROM re_leases WHERE id = ?)
                    ")->execute([$moveOut['lease_id']]);
                    
                    $conn->commit();
                    $response = ['success' => true, 'message' => 'Move-out completed successfully!', 'redirect' => 'move_out.php'];
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
                        VALUES (?, ?, 'move_out', ?, ?, CURDATE(), ?, ?, ?)
                    ");
                    $stmt->execute([$currentCompanyId, $moveOut['lease_id'], $meterType, $readingValue, $meterNumber, $notes, $currentUserId]);
                    $response = ['success' => true, 'message' => 'Meter reading saved'];
                }
                break;
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// Get damages
$damages = $conn->prepare("
    SELECT * FROM re_move_out_damages
    WHERE move_out_id = ?
    ORDER BY assessed_at DESC
");
$damages->execute([$moveOutId]);
$damages = $damages->fetchAll(PDO::FETCH_ASSOC);

// Get meter readings
$meterReadings = $conn->prepare("
    SELECT * FROM re_meter_readings
    WHERE lease_id = ? AND reading_type = 'move_out'
    ORDER BY meter_type, reading_date DESC
");
$meterReadings->execute([$moveOut['lease_id']]);
$meterReadings = $meterReadings->fetchAll(PDO::FETCH_ASSOC);

// Get photos
$photos = $conn->prepare("
    SELECT * FROM re_move_out_photos
    WHERE move_out_id = ?
    ORDER BY uploaded_at DESC
");
$photos->execute([$moveOutId]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);

// Calculate deposit summary
$securityDeposit = (float)$moveOut['security_deposit'];
$damageTotal = (float)$moveOut['damage_assessment_total'];
$depositDeduction = (float)$moveOut['deposit_deduction_amount'];
$depositRefund = (float)($moveOut['deposit_refund_amount'] ?? 0);
$depositSummary = re_sd_summary($conn, (int)$currentCompanyId, (int)$moveOut['lease_id']);
$depositSettlement = re_sd_settlement_for_move_out($conn, (int)$currentCompanyId, (int)$moveOutId);
$depositSettlementComplete = ((float)($depositSummary['expected'] ?? 0) <= 0)
    || ($depositSettlement && in_array((string)$depositSettlement['status'], ['refunded','partially_refunded','deducted'], true));
$depositDeductions = [];
try {
    $dedStmt = $conn->prepare("SELECT * FROM re_security_deposit_deductions WHERE company_id = ? AND move_out_id = ? ORDER BY created_at DESC");
    $dedStmt->execute([$currentCompanyId, $moveOutId]);
    $depositDeductions = $dedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $depositDeductions = [];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Move-Out Details';
$pageStyles = '
    .damage-item { border-left: 4px solid #dc3545; padding: 15px; margin-bottom: 10px; background: #f8f9fa; }
    .status-badge { font-size: 0.9rem; }
';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1>Move-Out #<?= $moveOutId ?></h1>
                <p class="text-muted mb-0">
                    <span class="badge bg-<?= 
                        $moveOut['status'] === 'completed' ? 'success' : 
                        ($moveOut['status'] === 'deposit_processing' ? 'secondary' : 
                        ($moveOut['status'] === 'inspection_completed' ? 'primary' : 
                        ($moveOut['status'] === 'inspection_scheduled' ? 'info' : 'warning'))) 
                    ?>">
                        <?= ucfirst(str_replace('_', ' ', $moveOut['status'])) ?>
                    </span>
                    • Move-Out Date: <?= date('M d, Y', strtotime($moveOut['actual_move_out_date'])) ?>
                </p>
            </div>
            <a href="move_out.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
        </div>

        <div class="row">
            <!-- Left Column: Main Workflow -->
            <div class="col-md-8">
                <!-- Lease Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-text"></i> Lease Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Lease Number:</strong> <?= h($moveOut['lease_number']) ?><br>
                                <strong>Building:</strong> <?= h($moveOut['building_name']) ?><br>
                                <strong>Unit:</strong> <?= h($moveOut['unit_number']) ?> (<?= h($moveOut['unit_type']) ?>)<br>
                                <strong>Monthly Rent:</strong> <?= number_format($moveOut['monthly_rent'], 2) ?> AED
                            </div>
                            <div class="col-md-6">
                                <strong>Tenant:</strong> <?= h($moveOut['first_name'] . ' ' . $moveOut['last_name']) ?><br>
                                <strong>Phone:</strong> <?= h($moveOut['phone']) ?><br>
                                <strong>Email:</strong> <?= h($moveOut['email']) ?><br>
                                <strong>Security Deposit:</strong> <?= number_format($securityDeposit, 2) ?> AED
                            </div>
                        </div>
                        <?php if ($moveOut['notice_date']): ?>
                            <hr>
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Notice Date:</strong> <?= date('M d, Y', strtotime($moveOut['notice_date'])) ?><br>
                                    <strong>Notice Type:</strong> <?= ucfirst($moveOut['notice_type']) ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Intended Move-Out:</strong> <?= date('M d, Y', strtotime($moveOut['intended_move_out_date'])) ?><br>
                                    <?php if ($moveOut['notice_reason']): ?>
                                        <strong>Reason:</strong> <?= h($moveOut['notice_reason']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Final Inspection -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-clipboard-check"></i> Final Inspection</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!$moveOut['inspection_scheduled_date']): ?>
                            <form id="scheduleInspectionForm" class="mb-3">
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <input type="date" name="inspection_date" class="form-control" required>
                                    </div>
                                    <div class="col-md-4">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-calendar-check"></i> Schedule Inspection
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php else: ?>
                            <p><strong>Scheduled Date:</strong> <?= date('M d, Y', strtotime($moveOut['inspection_scheduled_date'])) ?></p>
                        <?php endif; ?>
                        
                        <?php if ($moveOut['inspection_scheduled_date'] && !$moveOut['inspection_completed']): ?>
                            <form id="completeInspectionForm">
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="keys_returned" id="keys_returned">
                                        <label class="form-check-label" for="keys_returned">
                                            Keys Returned
                                        </label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Inspection Notes</label>
                                    <textarea name="inspection_notes" class="form-control" rows="4" 
                                              placeholder="Record any observations, damages, or issues found during inspection"><?= h($moveOut['final_inspection_notes'] ?? '') ?></textarea>
                                </div>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-circle"></i> Complete Inspection
                                </button>
                            </form>
                        <?php elseif ($moveOut['inspection_completed']): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle"></i> Inspection completed on <?= date('M d, Y H:i', strtotime($moveOut['inspection_completed_at'])) ?>
                            </div>
                            <?php if ($moveOut['keys_returned']): ?>
                                <p><i class="bi bi-key"></i> Keys returned on <?= date('M d, Y H:i', strtotime($moveOut['keys_returned_at'])) ?></p>
                            <?php endif; ?>
                            <?php if ($moveOut['final_inspection_notes']): ?>
                                <div class="mt-3">
                                    <strong>Inspection Notes:</strong>
                                    <p><?= nl2br(h($moveOut['final_inspection_notes'])) ?></p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Damage Assessment -->
                <?php if ($moveOut['inspection_completed']): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Damage Assessment</h5>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addDamageModal">
                            <i class="bi bi-plus"></i> Add Damage
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($damages)): ?>
                            <p class="text-muted">No damages recorded.</p>
                        <?php else: ?>
                            <?php foreach ($damages as $damage): ?>
                                <div class="damage-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong><?= h($damage['damage_description']) ?></strong>
                                            <?php if ($damage['room_area']): ?>
                                                <span class="badge bg-secondary ms-2"><?= h($damage['room_area']) ?></span>
                                            <?php endif; ?>
                                            <span class="badge bg-<?= 
                                                $damage['damage_type'] === 'severe' ? 'danger' : 
                                                ($damage['damage_type'] === 'major' ? 'warning' : 
                                                ($damage['damage_type'] === 'moderate' ? 'info' : 'secondary')) 
                                            ?> ms-2">
                                                <?= ucfirst($damage['damage_type']) ?>
                                            </span>
                                        </div>
                                        <strong class="text-danger"><?= number_format($damage['repair_cost'], 2) ?> AED</strong>
                                    </div>
                                    <?php if ($damage['notes']): ?>
                                        <p class="mb-0 mt-2"><small><?= h($damage['notes']) ?></small></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <strong>Total Damage Assessment:</strong>
                                <strong class="text-danger"><?= number_format($damageTotal, 2) ?> AED</strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Security Deposit Settlement -->
                <?php if ($moveOut['inspection_completed']): ?>
                <div class="card mb-4 border-info">
                    <div class="card-header bg-info text-dark">
                        <h5 class="mb-0"><i class="bi bi-shield-check"></i> Security Deposit Settlement</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-light border small">
                            Security deposit is a refundable liability. Deductions and refunds require approval before accounting is posted.
                        </div>
                        <div class="row g-3 text-center mb-3">
                            <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Expected</div><strong><?= number_format((float)$depositSummary['expected'], 2) ?></strong> AED</div></div>
                            <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Received</div><strong><?= number_format((float)$depositSummary['allocated'], 2) ?></strong> AED</div></div>
                            <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Damage Assessment</div><strong class="text-danger"><?= number_format($damageTotal, 2) ?></strong> AED</div></div>
                            <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Current Refundable</div><strong><?= number_format((float)$depositSummary['refundable'], 2) ?></strong> AED</div></div>
                        </div>

                        <?php if (!$depositSettlement): ?>
                            <button class="btn btn-primary" onclick="calculateDeposit()">
                                <i class="bi bi-calculator"></i> Prepare Deposit Settlement Preview
                            </button>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <div class="row">
                                    <div class="col-md-3"><strong>Settlement Status:</strong><br><?= h(ucfirst(str_replace('_', ' ', $depositSettlement['status']))) ?></div>
                                    <div class="col-md-3"><strong>Approved Deduction:</strong><br><?= number_format((float)$depositSettlement['deduction_amount'], 2) ?> AED</div>
                                    <div class="col-md-3"><strong>Approved Refund:</strong><br><?= number_format((float)$depositSettlement['refund_amount'], 2) ?> AED</div>
                                    <div class="col-md-3"><strong>Retained:</strong><br><?= number_format((float)$depositSettlement['retained_amount'], 2) ?> AED</div>
                                </div>
                            </div>

                            <?php if (!empty($depositDeductions)): ?>
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light"><tr><th>Deduction</th><th class="text-end">Amount</th><th>Status</th><th>Reason</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($depositDeductions as $deduction): ?>
                                            <tr>
                                                <td><?= h(ucfirst(str_replace('_', ' ', $deduction['deduction_type']))) ?></td>
                                                <td class="text-end"><?= number_format((float)$deduction['amount'], 2) ?> AED</td>
                                                <td><span class="badge bg-<?= $deduction['status'] === 'approved' || $deduction['status'] === 'applied' ? 'success' : ($deduction['status'] === 'rejected' ? 'danger' : 'warning text-dark') ?>"><?= h($deduction['status']) ?></span></td>
                                                <td><?= h($deduction['reason']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <?php if (in_array($depositSettlement['status'], ['draft'], true)): ?>
                                <form id="approveDepositSettlementForm" class="mb-3">
                                    <div class="row g-2">
                                        <div class="col-md-8">
                                            <input type="text" name="approval_reason" class="form-control" placeholder="Approval reason for deductions / settlement" required>
                                        </div>
                                        <div class="col-md-4">
                                            <button type="submit" class="btn btn-warning w-100"><i class="bi bi-check2-circle"></i> Approve Settlement</button>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?>

                            <?php if (in_array($depositSettlement['status'], ['approved','deducted'], true)): ?>
                                <form id="processRefundForm" class="mt-3">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <select name="refund_method" class="form-select" required>
                                                <option value="bank_transfer">Bank Transfer</option>
                                                <option value="cash">Cash</option>
                                                <option value="cheque">Cheque</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="text" name="refund_reference" class="form-control" placeholder="Reference Number">
                                        </div>
                                        <div class="col-md-4">
                                            <button type="submit" class="btn btn-success w-100">
                                                <i class="bi bi-check-circle"></i> Process Approved Settlement
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            <?php elseif (in_array($depositSettlement['status'], ['refunded','partially_refunded','deducted'], true)): ?>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle"></i> Security deposit settlement processed.
                                    <?php if (!empty($depositSettlement['journal_id'])): ?>
                                        <br><a href="accounting/journal_entry_view.php?id=<?= (int)$depositSettlement['journal_id'] ?>">View Journal</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Meter Readings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-speedometer"></i> Final Meter Readings</h5>
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

            <!-- Right Column: Actions & Summary -->
            <div class="col-md-4">
                <!-- Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-gear"></i> Actions</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($moveOut['status'] !== 'completed' && $moveOut['inspection_completed'] && $depositSettlementComplete): ?>
                            <button class="btn btn-success w-100 mb-2" onclick="completeMoveOut()">
                                <i class="bi bi-check-circle"></i> Complete Move-Out
                            </button>
                        <?php endif; ?>
                        <a href="lease_view.php?id=<?= $moveOut['lease_id'] ?>" class="btn btn-outline-primary w-100 mb-2">
                            <i class="bi bi-file-text"></i> View Lease
                        </a>
                        <a href="move_out.php" class="btn btn-outline-secondary w-100">
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
                        <p><strong>Created:</strong> <?= date('M d, Y H:i', strtotime($moveOut['created_at'])) ?></p>
                        <?php if ($moveOut['created_by_name']): ?>
                            <p><strong>Created By:</strong> <?= h($moveOut['created_by_name']) ?></p>
                        <?php endif; ?>
                        <?php if ($moveOut['approved_by_name']): ?>
                            <p><strong>Approved By:</strong> <?= h($moveOut['approved_by_name']) ?></p>
                            <p><strong>Approved At:</strong> <?= date('M d, Y H:i', strtotime($moveOut['approved_at'])) ?></p>
                        <?php endif; ?>
                        <?php if ($moveOut['notes']): ?>
                            <hr>
                            <p><strong>Notes:</strong></p>
                            <p><?= nl2br(h($moveOut['notes'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Damage Modal -->
    <div class="modal fade" id="addDamageModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Damage</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addDamageForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Damage Description *</label>
                            <textarea name="damage_description" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Room/Area</label>
                            <input type="text" name="room_area" class="form-control" placeholder="e.g., Living Room, Kitchen">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Damage Type *</label>
                            <select name="damage_type" class="form-select" required>
                                <option value="minor">Minor</option>
                                <option value="moderate">Moderate</option>
                                <option value="major">Major</option>
                                <option value="severe">Severe</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Repair Cost (AED) *</label>
                            <input type="number" step="0.01" name="repair_cost" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Damage</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';
        const moveOutId = <?= $moveOutId ?>;
        
        document.getElementById('scheduleInspectionForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'schedule_inspection');
            formData.append('csrf_token', csrfToken);
            
            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Error scheduling inspection');
                    }
                });
        });
        
        document.getElementById('completeInspectionForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'complete_inspection');
            formData.append('csrf_token', csrfToken);
            
            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Error completing inspection');
                    }
                });
        });
        
        document.getElementById('addDamageForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'add_damage');
            formData.append('csrf_token', csrfToken);
            
            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Error adding damage');
                    }
                });
        });
        
        function calculateDeposit() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'calculate_deposit',
                    csrf_token: csrfToken
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Error calculating deposit');
                }
            });
        }
        
        document.getElementById('approveDepositSettlementForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'approve_deposit_settlement');
            formData.append('csrf_token', csrfToken);

            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Error approving deposit settlement');
                    }
                });
        });

        document.getElementById('processRefundForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'process_deposit_refund');
            formData.append('csrf_token', csrfToken);
            
            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Error processing refund');
                    }
                });
        });
        
        document.getElementById('meterReadingForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'save_meter_reading');
            formData.append('csrf_token', csrfToken);
            
            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Error saving meter reading');
                    }
                });
        });
        
        function completeMoveOut() {
            if (!confirm('Are you sure you want to complete this move-out? This will mark the unit as vacant and expire the lease.')) {
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'complete_move_out',
                    csrf_token: csrfToken
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        location.reload();
                    }
                } else {
                    alert(data.message || 'Error completing move-out');
                }
            });
        }
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

