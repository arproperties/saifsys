<?php
/**
 * Real Estate Module - Create New Move-In
 * Initialize move-in process for a lease
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
    $moveInDate = $_POST['move_in_date'] ?? '';
    
    if (!$leaseId || !$moveInDate) {
        $_SESSION['error'] = 'Please fill in all required fields.';
        header('Location: move_in_add.php');
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
        header('Location: move_in_add.php');
        exit;
    }
    
    // Check if move-in already exists for this lease
    $existingCheck = $conn->prepare("
        SELECT id FROM re_move_ins 
        WHERE lease_id = ? AND status != 'cancelled'
    ");
    $existingCheck->execute([$leaseId]);
    if ($existingCheck->fetch()) {
        $_SESSION['error'] = 'A move-in record already exists for this lease.';
        header('Location: move_in_add.php');
        exit;
    }
    
    try {
        $conn->beginTransaction();
        
        // Create move-in record
        $stmt = $conn->prepare("
            INSERT INTO re_move_ins 
            (company_id, lease_id, move_in_date, status, created_by)
            VALUES (?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([$currentCompanyId, $leaseId, $moveInDate, $currentUserId]);
        $moveInId = $conn->lastInsertId();
        
        // Get default checklist templates
        $templates = $conn->prepare("
            SELECT * FROM re_move_in_checklist_templates
            WHERE company_id = ? AND is_active = 1
            ORDER BY display_order, id
        ");
        $templates->execute([$currentCompanyId]);
        $templateItems = $templates->fetchAll(PDO::FETCH_ASSOC);
        
        // If no templates exist, create default items
        if (empty($templateItems)) {
            $defaultItems = [
                ['Contract signed and verified', 'Verify lease contract is signed by both parties', 1],
                ['Deposit received', 'Confirm deposit payment has been received', 1],
                ['First Installment rent received', 'Confirm first installment rent payment', 1],
                ['Keys handed over', 'Physical keys handed over to tenant', 1],
                ['Initial inspection completed', 'Complete initial unit inspection with photos', 1],
                ['Meter readings taken', 'Record initial electricity, water, and gas meter readings', 1],
                ['Utilities connected', 'Confirm utilities are connected and active', 0],
                ['Welcome package provided', 'Provide tenant welcome package and information', 0]
            ];
            
            $templateStmt = $conn->prepare("
                INSERT INTO re_move_in_checklist_templates
                (company_id, item_name, item_description, is_required, display_order, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            
            foreach ($defaultItems as $index => $item) {
                $templateStmt->execute([$currentCompanyId, $item[0], $item[1], $item[2], $index + 1]);
            }
            
            // Re-fetch templates
            $templates->execute([$currentCompanyId]);
            $templateItems = $templates->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Create checklist items from templates
        $checklistStmt = $conn->prepare("
            INSERT INTO re_move_in_checklist_items
            (company_id, move_in_id, checklist_template_id, item_name, item_description, is_required, display_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($templateItems as $index => $template) {
            $checklistStmt->execute([
                $currentCompanyId,
                $moveInId,
                $template['id'],
                $template['item_name'],
                $template['item_description'],
                $template['is_required'],
                $template['display_order'] ?: ($index + 1)
            ]);
        }
        
        $conn->commit();
        
        $_SESSION['success'] = 'Move-in record created successfully.';
        header('Location: move_in_view.php?id=' . $moveInId);
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = 'Error creating move-in record: ' . $e->getMessage();
        header('Location: move_in_add.php');
        exit;
    }
}

// Get lease_id from GET parameter if provided
$preSelectedLeaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : null;

// Get active leases without completed move-ins (exclude only completed, allow pending)
if ($preSelectedLeaseId) {
    // If a specific lease_id is provided, include it even if it has a pending move-in (but not if completed)
    $leases = $conn->prepare("
        SELECT 
            l.id,
            l.lease_number,
            l.start_date,
            l.end_date,
            l.annual_rent,
            l.monthly_rent,
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
        WHERE l.company_id = ? 
        AND l.status = 'active'
        AND (
            l.id = ? 
            OR (SELECT COUNT(*) FROM re_move_ins mi2 WHERE mi2.lease_id = l.id AND mi2.status = 'completed') = 0
        )
        ORDER BY l.start_date DESC, b.name, u.unit_number
    ");
    $leases->execute([$currentCompanyId, $preSelectedLeaseId]);
} else {
    $leases = $conn->prepare("
        SELECT 
            l.id,
            l.lease_number,
            l.start_date,
            l.end_date,
            l.annual_rent,
            l.monthly_rent,
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
        WHERE l.company_id = ? 
        AND l.status = 'active'
        AND (SELECT COUNT(*) FROM re_move_ins mi2 WHERE mi2.lease_id = l.id AND mi2.status = 'completed') = 0
        ORDER BY l.start_date DESC, b.name, u.unit_number
    ");
    $leases->execute([$currentCompanyId]);
}
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'New Move-In';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="bi bi-box-arrow-in-right"></i> Create New Move-In</h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger"><?= h($_SESSION['error']) ?></div>
                            <?php unset($_SESSION['error']); ?>
                        <?php endif; ?>
                        
                        <?php if (empty($leases)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> 
                                No active leases available for move-in. All leases either have completed move-ins or are inactive.
                            </div>
                            <a href="leases.php" class="btn btn-primary">View Leases</a>
                        <?php else: ?>
                            <form method="POST" id="moveInForm">
                                <?= csrf_field() ?>
                                
                                <div class="mb-3">
                                    <label for="lease_id" class="form-label">Select Lease <span class="text-danger">*</span></label>
                                    <select name="lease_id" id="lease_id" class="form-select" required>
                                        <option value="">-- Select Lease --</option>
                                        <?php foreach ($leases as $lease): 
                                            $annualRent = $lease['annual_rent'] ?? ($lease['monthly_rent'] * 12);
                                            $startDate = $lease['start_date'] ? date('d/m/Y', strtotime($lease['start_date'])) : '-';
                                        ?>
                                            <option value="<?= $lease['id'] ?>" 
                                                    <?= ($preSelectedLeaseId && $lease['id'] == $preSelectedLeaseId) ? 'selected' : '' ?>
                                                    data-tenant="<?= h($lease['first_name'] . ' ' . $lease['last_name']) ?>"
                                                    data-unit="<?= h($lease['building_name'] . ' - ' . $lease['unit_number']) ?>"
                                                    data-annual-rent="<?= number_format($annualRent, 2) ?>"
                                                    data-start-date="<?= h($startDate) ?>">
                                                <?= h($lease['lease_number']) ?> - 
                                                <?= h($lease['building_name']) ?> - 
                                                <?= h($lease['unit_number']) ?> - 
                                                <?= h($lease['first_name'] . ' ' . $lease['last_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Only active leases without completed move-ins are shown.</div>
                                </div>
                                
                                <div id="leaseDetails" class="card mb-3" style="display: none;">
                                    <div class="card-body">
                                        <h6 class="card-title">Lease Details</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>Tenant:</strong> <span id="tenantName">-</span><br>
                                                <strong>Unit:</strong> <span id="unitInfo">-</span><br>
                                                <strong>Annual Rent:</strong> <span id="annualRent">-</span> AED
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Lease Start:</strong> <span id="leaseStart">-</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="move_in_date" class="form-label">Move-In Date <span class="text-danger">*</span></label>
                                    <input type="date" name="move_in_date" id="move_in_date" class="form-control" required>
                                    <div class="form-text">The actual date when the tenant will move in.</div>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> 
                                    <strong>Note:</strong> After creating the move-in record, you'll be able to complete the checklist, 
                                    record meter readings, upload inspection photos, and finalize the move-in process.
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <a href="move_in.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> Create Move-In
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
        const leaseSelect = document.getElementById('lease_id');
        
        leaseSelect.addEventListener('change', function() {
            const select = this;
            const option = select.options[select.selectedIndex];
            const detailsDiv = document.getElementById('leaseDetails');
            
            if (select.value) {
                document.getElementById('tenantName').textContent = option.dataset.tenant || '-';
                document.getElementById('unitInfo').textContent = option.dataset.unit || '-';
                document.getElementById('annualRent').textContent = option.dataset.annualRent || '-';
                document.getElementById('leaseStart').textContent = option.dataset.startDate || '-';
                detailsDiv.style.display = 'block';
            } else {
                detailsDiv.style.display = 'none';
            }
        });
        
        // Trigger change event if a lease is pre-selected
        <?php if ($preSelectedLeaseId): ?>
            if (leaseSelect.value) {
                leaseSelect.dispatchEvent(new Event('change'));
            }
        <?php endif; ?>
        
        // Set default move-in date to today
        document.getElementById('move_in_date').valueAsDate = new Date();
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

