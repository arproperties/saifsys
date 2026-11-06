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
require_once __DIR__ . '/includes/re_lease_picker.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';
require_once __DIR__ . '/includes/move_in_stages_helper.php';

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
        WHERE l.id = ? AND l.company_id = ? AND " . re_lease_not_deleted_sql($conn, 'l') . "
    ");
    $leaseCheck->execute([$leaseId, $currentCompanyId]);
    $lease = $leaseCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$lease) {
        $_SESSION['error'] = 'Invalid lease selected.';
        header('Location: move_in_add.php');
        exit;
    }
    
    // Renewal of the same unit by the same tenant: covered by the first lease's move-in
    require_once __DIR__ . '/includes/move_in_chain_helper.php';
    if ($carried = re_move_in_carried_over($conn, (int)$currentCompanyId, (int)$leaseId)) {
        $_SESSION['error'] = 'No move-in is needed: this lease renews the same unit for the same tenant, who moved in under lease '
            . ($carried['lease_number'] ?: ('#' . $carried['lease_id'])) . ' (Move-In #' . $carried['move_in_id'] . ').';
        header('Location: move_in_add.php');
        exit;
    }
    // Renewal without a move-in on its first lease, or an existing tenancy: Mark as Moved In instead
    if ($reason = re_move_in_add_block_reason($conn, (int)$currentCompanyId, (int)$leaseId)) {
        $_SESSION['error'] = $reason;
        header('Location: lease_view.php?id=' . (int)$leaseId);
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
        
        if (rms_ready($conn)) {
            // Stages: Contract Signed -> Payment Confirmed -> Inspection -> Key Handover
            rms_create_for_move_in($conn, (int)$currentCompanyId, (int)$moveInId, $currentUserId ? (int)$currentUserId : null);
        } else {
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
                    ['Unit cleanliness & pest-free confirmation', 'Confirm the unit is clean and pest-free before handover', 1],
                    ['Maintenance completion confirmation', 'Confirm all pending maintenance work in the unit is completed', 1],
                    ['Move-in photos & inspection report uploaded', 'Upload the move-in photos and the inspection report', 0]
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

// All leases (any status). Leases that already have a move-in (anything but
// cancelled) are listed but disabled, so they cannot be picked again.
$leases = $conn->prepare("
    SELECT 
        l.id,
        l.lease_number,
        l.start_date,
        l.end_date,
        l.annual_rent,
        l.monthly_rent,
        l.status,
        u.unit_number,
        u.unit_type,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        mi.id AS existing_id,
        mi.status AS existing_status
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_move_ins mi ON mi.id = (
        SELECT mi2.id FROM re_move_ins mi2
        WHERE mi2.lease_id = l.id AND mi2.status != 'cancelled'
        ORDER BY mi2.id DESC LIMIT 1
    )
    WHERE l.company_id = ? AND " . re_lease_not_deleted_sql($conn, 'l') . "
      -- A draft is not listed while its unit has an active lease
      AND NOT (l.status = 'draft' AND EXISTS (
          SELECT 1 FROM re_leases la
          WHERE la.unit_id = l.unit_id AND la.company_id = l.company_id AND la.id <> l.id
            AND la.status IN ('active', 'has_legal_case') AND " . re_lease_not_deleted_sql($conn, 'la') . "
      ))
    ORDER BY (mi.id IS NOT NULL), l.start_date DESC, b.name, u.unit_number
");
$leases->execute([$currentCompanyId]);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__ . '/includes/move_in_chain_helper.php';
$carriedMap = re_move_in_carried_over_map($conn, (int)$currentCompanyId);
$firstMap = re_move_in_first_lease_map($conn, (int)$currentCompanyId);
$moveInFrom = re_move_in_process_from($conn);
foreach ($leases as &$l) {
    $lid = (int)$l['id'];
    if ($l['existing_id']) {
        $l['blocked_reason'] = 'Move-in ' . re_lease_picker_status_label((string)$l['existing_status']);
    } elseif (isset($carriedMap[$lid])) {
        $l['blocked_reason'] = re_move_in_carried_over_label($carriedMap[$lid]);
    } elseif (isset($firstMap[$lid])) {
        $l['blocked_reason'] = 'Renewal: Mark as Moved In (first lease ' . ($firstMap[$lid]['lease_number'] ?: ('#' . $firstMap[$lid]['id'])) . ')';
    } elseif ($l['status'] !== 'draft' && (string)$l['start_date'] < $moveInFrom) {
        $l['blocked_reason'] = 'Existing tenancy: use Mark as Moved In';
    } else {
        $l['blocked_reason'] = '';
    }
}
unset($l);
// Available leases first
usort($leases, static fn($a, $b) => ($a['blocked_reason'] !== '') <=> ($b['blocked_reason'] !== ''));

// Opened from a lease (?lease_id=): fix the picker to that lease, no search.
// Opened from the menu: searchable dropdown with quick filters.
$lockedLease = null;
$preselectMissing = false;
$preselectBlocked = null;
if ($preSelectedLeaseId) {
    foreach ($leases as $l) {
        if ((int)$l['id'] === $preSelectedLeaseId) {
            if ($l['blocked_reason']) {
                $preselectBlocked = $l;
            } else {
                $lockedLease = $l;
            }
            break;
        }
    }
    $preselectMissing = !$lockedLease && !$preselectBlocked;
}
// Expired and terminated leases are not listed (still opens when started from the lease itself)
$leases = array_values(array_filter($leases, fn($l) => !in_array($l['status'], ['expired', 'terminated'], true)));
$availableCount = count(array_filter($leases, fn($l) => !$l['blocked_reason']));
$pickerLeases = $lockedLease ? [$lockedLease] : $leases;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'New Move-In';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles = ($pageStyles ?? '') . re_move_ui_css();
if (!$lockedLease) {
    $pageHead = re_lease_picker_head();
}
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="rm-page">
        <div class="rm-narrow">
                <a href="move_in.php" class="rm-back"><i class="bi bi-arrow-left"></i> Move-In Management</a>
                <div class="rm-hero">
                    <div>
                        <h1><i class="bi bi-box-arrow-in-right"></i>New Move-In</h1>
                        <div class="rm-sub">Choose the lease and the move-in date. The handover checklist is created next.</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-file-earmark-text me-1"></i> Move-In details</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger"><?= h($_SESSION['error']) ?></div>
                            <?php unset($_SESSION['error']); ?>
                        <?php endif; ?>
                        
                        <?php if ($preselectBlocked): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                Lease <strong><?= h($preselectBlocked['lease_number']) ?></strong> already has a move-in
                                (<?= h(re_lease_picker_status_label((string)$preselectBlocked['existing_status'])) ?>).
                                <a href="move_in_view.php?id=<?= (int)$preselectBlocked['existing_id'] ?>" class="alert-link">View move-in</a>
                            </div>
                        <?php elseif ($preselectMissing): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                The lease you opened was not found. Pick a lease below.
                            </div>
                        <?php endif; ?>

                        <?php if (!$lockedLease && $availableCount === 0): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> 
                                No leases available for move-in. All leases already have a move-in record.
                            </div>
                            <a href="leases.php" class="btn btn-primary">View Leases</a>
                        <?php else: ?>
                            <form method="POST" id="moveInForm">
                                <?= csrf_field() ?>
                                
                                <div class="mb-3">
                                    <label for="lease_id" class="form-label">Select Lease <span class="text-danger">*</span></label>
                                    <?php if ($lockedLease): ?>
                                        <?= re_lease_picker_locked($lockedLease, 'move_in_add.php') ?>
                                    <?php else: ?>
                                        <?= re_lease_picker_filters($leases) ?>
                                    <?php endif; ?>
                                    <select name="lease_id" id="lease_id" class="<?= $lockedLease ? 'd-none' : 'form-select' ?>" required>
                                        <option value="">-- Select Lease --</option>
                                        <?php foreach ($pickerLeases as $lease): 
                                            $annualRent = $lease['annual_rent'] ?? ($lease['monthly_rent'] * 12);
                                            $startDate = $lease['start_date'] ? date('d/m/Y', strtotime($lease['start_date'])) : '-';
                                        ?>
                                            <option value="<?= $lease['id'] ?>" 
                                                    <?= $lockedLease ? 'selected' : '' ?>
                                                    <?= $lease['blocked_reason'] ? 'disabled' : '' ?>
                                                    data-tenant="<?= h($lease['first_name'] . ' ' . $lease['last_name']) ?>"
                                                    data-unit="<?= h($lease['building_name'] . ' - ' . $lease['unit_number']) ?>"
                                                    data-annual-rent="<?= number_format($annualRent, 2) ?>"
                                                    data-start-date="<?= h($startDate) ?>"
                                                    data-status="<?= h($lease['status']) ?>"
                                                    data-building="<?= h($lease['building_name']) ?>"
                                                    data-search="<?= h(re_lease_picker_search_text($lease)) ?>">
                                                <?= h($lease['lease_number']) ?> - 
                                                <?= h($lease['building_name']) ?> - 
                                                <?= h($lease['unit_number']) ?> - 
                                                <?= h($lease['first_name'] . ' ' . $lease['last_name']) ?>
                                                [<?= h(re_lease_picker_status_label($lease['status'])) ?>]
                                                <?= $lease['blocked_reason'] ? '— ' . h($lease['blocked_reason']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!$lockedLease): ?>
                                        <div class="form-text"><span id="leaseFilterCount"></span> · Type in the box to search. Greyed-out leases already have a move-in and cannot be selected.</div>
                                    <?php endif; ?>
                                </div>
                                
                                <div id="leaseDetails" class="card mb-3" style="display: none;">
                                    <div class="card-body">
                                        <p class="rm-section-title">Lease details</p>
                                        <dl class="rm-dl">
                                            <dt>Tenant</dt><dd id="tenantName">-</dd>
                                            <dt>Unit</dt><dd id="unitInfo">-</dd>
                                            <dt>Annual Rent</dt><dd><span id="annualRent">-</span> AED</dd>
                                            <dt>Lease Start</dt><dd id="leaseStart">-</dd>
                                            <dt>Status</dt><dd id="leaseStatus">-</dd>
                                        </dl>
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
                                
                                <div class="rm-form-actions">
                                    <a href="move_in.php" class="btn btn-outline-secondary">
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
                document.getElementById('leaseStatus').textContent = option.dataset.status ? option.dataset.status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : '-';
                detailsDiv.style.display = 'block';
            } else {
                detailsDiv.style.display = 'none';
            }
        });
        
        // Show details for a pre-selected lease
        if (leaseSelect.value) {
            leaseSelect.dispatchEvent(new Event('change'));
        }
        
        // Set default move-in date to today
        document.getElementById('move_in_date').valueAsDate = new Date();
    </script>
    <?php if (!$lockedLease) echo re_lease_picker_script(); ?>

<?= re_datepicker_assets() ?>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

