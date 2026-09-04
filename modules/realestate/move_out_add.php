<?php
/**
 * Real Estate Module - Create Move-Out Notice
 * Record move-out notice from tenant or landlord
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $leaseId = !empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : 0;
    $noticeDate = $_POST['notice_date'] ?? '';
    $intendedMoveOutDate = $_POST['intended_move_out_date'] ?? '';
    $noticeType = $_POST['notice_type'] ?? 'tenant';
    $noticeReason = $_POST['notice_reason'] ?? '';
    $noticeDeliveredBy = $_POST['notice_delivered_by'] ?? '';
    
    if (!$leaseId || !$noticeDate || !$intendedMoveOutDate) {
        $_SESSION['error'] = 'Please fill in all required fields.';
        header('Location: move_out_add.php');
        exit;
    }
    
    // Verify lease exists and belongs to company
    $leaseCheck = $conn->prepare("
        SELECT l.*, u.unit_number, b.name as building_name, t.first_name, t.last_name
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.id = ? AND l.company_id = ? AND l.status = 'active'
    ");
    $leaseCheck->execute([$leaseId, $currentCompanyId]);
    $lease = $leaseCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$lease) {
        $_SESSION['error'] = 'Invalid or inactive lease selected.';
        header('Location: move_out_add.php');
        exit;
    }
    
    // Check if move-out notice already exists for this lease
    $existingCheck = $conn->prepare("
        SELECT id FROM re_move_out_notices 
        WHERE lease_id = ? AND status != 'cancelled'
    ");
    $existingCheck->execute([$leaseId]);
    if ($existingCheck->fetch()) {
        $_SESSION['error'] = 'A move-out notice already exists for this lease.';
        header('Location: move_out_add.php');
        exit;
    }
    
    try {
        // Create move-out notice
        $stmt = $conn->prepare("
            INSERT INTO re_move_out_notices 
            (company_id, lease_id, notice_date, intended_move_out_date, notice_type, 
             notice_reason, notice_delivered_by, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $currentCompanyId, 
            $leaseId, 
            $noticeDate, 
            $intendedMoveOutDate, 
            $noticeType,
            $noticeReason,
            $noticeDeliveredBy,
            $currentUserId
        ]);
        $noticeId = $conn->lastInsertId();
        
        // Create move-out record
        $stmt = $conn->prepare("
            INSERT INTO re_move_outs 
            (company_id, lease_id, move_out_notice_id, actual_move_out_date, status, created_by)
            VALUES (?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $currentCompanyId, 
            $leaseId, 
            $noticeId, 
            $intendedMoveOutDate, // Default to intended date, can be updated later
            $currentUserId
        ]);
        $moveOutId = $conn->lastInsertId();
        
        $_SESSION['success'] = 'Move-out notice created successfully.';
        header('Location: move_out_view.php?id=' . $moveOutId);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error creating move-out notice: ' . $e->getMessage();
        header('Location: move_out_add.php');
        exit;
    }
}

// Get active leases without move-out notices
$leases = $conn->prepare("
    SELECT 
        l.id,
        l.lease_number,
        l.start_date,
        l.end_date,
        l.monthly_rent,
        l.security_deposit,
        u.unit_number,
        u.unit_type,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_move_out_notices mon ON mon.lease_id = l.id AND mon.status != 'cancelled'
    WHERE l.company_id = ? 
    AND l.status = 'active'
    AND mon.id IS NULL
    ORDER BY l.end_date ASC, b.name, u.unit_number
");
$leases->execute([$currentCompanyId]);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'New Move-Out Notice';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="bi bi-box-arrow-right"></i> Create Move-Out Notice</h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger"><?= h($_SESSION['error']) ?></div>
                            <?php unset($_SESSION['error']); ?>
                        <?php endif; ?>
                        
                        <?php if (empty($leases)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> 
                                No active leases available for move-out. All leases either have move-out notices or are inactive.
                            </div>
                            <a href="leases.php" class="btn btn-primary">View Leases</a>
                        <?php else: ?>
                            <form method="POST" id="moveOutForm">
                                <?= csrf_field() ?>
                                
                                <div class="mb-3">
                                    <label for="lease_id" class="form-label">Select Lease <span class="text-danger">*</span></label>
                                    <select name="lease_id" id="lease_id" class="form-select" required>
                                        <option value="">-- Select Lease --</option>
                                        <?php foreach ($leases as $lease): ?>
                                            <option value="<?= $lease['id'] ?>" 
                                                    data-tenant="<?= h($lease['first_name'] . ' ' . $lease['last_name']) ?>"
                                                    data-unit="<?= h($lease['building_name'] . ' - ' . $lease['unit_number']) ?>"
                                                    data-deposit="<?= number_format($lease['security_deposit'], 2) ?>"
                                                    data-end-date="<?= $lease['end_date'] ?>">
                                                <?= h($lease['lease_number']) ?> - 
                                                <?= h($lease['building_name']) ?> - 
                                                <?= h($lease['unit_number']) ?> - 
                                                <?= h($lease['first_name'] . ' ' . $lease['last_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Only active leases without existing move-out notices are shown.</div>
                                </div>
                                
                                <div id="leaseDetails" class="card mb-3" style="display: none;">
                                    <div class="card-body">
                                        <h6 class="card-title">Lease Details</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>Tenant:</strong> <span id="tenantName">-</span><br>
                                                <strong>Unit:</strong> <span id="unitInfo">-</span><br>
                                                <strong>Security Deposit:</strong> <span id="securityDeposit">-</span> AED
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Lease End:</strong> <span id="leaseEnd">-</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notice_type" class="form-label">Notice Type <span class="text-danger">*</span></label>
                                    <select name="notice_type" id="notice_type" class="form-select" required>
                                        <option value="tenant">Tenant Notice</option>
                                        <option value="landlord">Landlord Notice</option>
                                        <option value="mutual">Mutual Agreement</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notice_date" class="form-label">Notice Date <span class="text-danger">*</span></label>
                                    <input type="date" name="notice_date" id="notice_date" class="form-control" required>
                                    <div class="form-text">Date when the move-out notice was received/given.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="intended_move_out_date" class="form-label">Intended Move-Out Date <span class="text-danger">*</span></label>
                                    <input type="date" name="intended_move_out_date" id="intended_move_out_date" class="form-control" required>
                                    <div class="form-text">Expected date when tenant will vacate the unit.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notice_delivered_by" class="form-label">Notice Delivered By</label>
                                    <input type="text" name="notice_delivered_by" class="form-control" 
                                           placeholder="e.g., Email, In-person, Registered Mail">
                                    <div class="form-text">How the notice was delivered.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notice_reason" class="form-label">Reason for Move-Out</label>
                                    <textarea name="notice_reason" class="form-control" rows="3" 
                                              placeholder="Optional: Reason for move-out"></textarea>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> 
                                    <strong>Note:</strong> After creating the move-out notice, you'll be able to schedule the final inspection, 
                                    assess damages, calculate deposit deductions, and process the move-out.
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <a href="move_out.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> Create Move-Out Notice
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('lease_id').addEventListener('change', function() {
            const select = this;
            const option = select.options[select.selectedIndex];
            const detailsDiv = document.getElementById('leaseDetails');
            
            if (select.value) {
                document.getElementById('tenantName').textContent = option.dataset.tenant || '-';
                document.getElementById('unitInfo').textContent = option.dataset.unit || '-';
                document.getElementById('securityDeposit').textContent = option.dataset.deposit || '-';
                const endDate = option.dataset.endDate ? new Date(option.dataset.endDate).toLocaleDateString() : '-';
                document.getElementById('leaseEnd').textContent = endDate;
                detailsDiv.style.display = 'block';
                
                // Set default intended move-out date to lease end date if available
                if (option.dataset.endDate) {
                    document.getElementById('intended_move_out_date').value = option.dataset.endDate;
                }
            } else {
                detailsDiv.style.display = 'none';
            }
        });
        
        // Set default notice date to today
        document.getElementById('notice_date').valueAsDate = new Date();
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

