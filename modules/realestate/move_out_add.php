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
require_once __DIR__ . '/includes/re_lease_picker.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';

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
        WHERE l.id = ? AND l.company_id = ? AND " . re_lease_not_deleted_sql($conn, 'l') . "
    ");
    $leaseCheck->execute([$leaseId, $currentCompanyId]);
    $lease = $leaseCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$lease) {
        $_SESSION['error'] = 'Invalid lease selected.';
        header('Location: move_out_add.php');
        exit;
    }
    
    // No move-out for a draft lease
    if (($lease['status'] ?? '') === 'draft') {
        $_SESSION['error'] = 'Lease ' . ($lease['lease_number'] ?: ('#' . $leaseId)) . ' is in Draft status. Activate the lease before creating a move-out.';
        header('Location: move_out_add.php');
        exit;
    }

    // Move-out is only allowed after a completed move-in
    $moveInCheck = $conn->prepare("
        SELECT id FROM re_move_ins WHERE lease_id = ? AND status = 'completed' LIMIT 1
    ");
    $moveInCheck->execute([$leaseId]);
    require_once __DIR__ . '/includes/move_in_chain_helper.php';
    if (!$moveInCheck->fetch() && !re_move_in_carried_over($conn, (int)$currentCompanyId, (int)$leaseId)) {
        $_SESSION['error'] = 'This lease has no completed move-in. Complete the move-in before creating a move-out.';
        header('Location: move_out_add.php');
        exit;
    }
    
    // A renewed lease: the move-out belongs on the latest lease of the tenancy
    if ($newer = re_move_out_superseded_by($conn, (int)$currentCompanyId, (int)$leaseId)) {
        $_SESSION['error'] = 'Lease ' . ($lease['lease_number'] ?: ('#' . $leaseId)) . ' was renewed by lease ' . ($newer['lease_number'] ?: ('#' . $newer['id']))
            . '. Create the move-out on that lease, the current one of this tenancy.';
        header('Location: move_out_add.php');
        exit;
    }

    // Check if a move-out notice or move-out record already exists for this lease
    $existingCheck = $conn->prepare("
        SELECT id FROM re_move_out_notices WHERE lease_id = ? AND status != 'cancelled'
        UNION ALL
        SELECT id FROM re_move_outs WHERE lease_id = ? AND status != 'cancelled'
        LIMIT 1
    ");
    $existingCheck->execute([$leaseId, $leaseId]);
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

// Get lease_id from GET parameter if provided (e.g. Move-Out button on lease view)
$preSelectedLeaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : null;

// All leases (any status). Leases that already have a move-out notice or
// move-out record (anything but cancelled) are listed but disabled.
$leases = $conn->prepare("
    SELECT 
        l.id,
        l.lease_number,
        l.start_date,
        l.end_date,
        l.monthly_rent,
        l.security_deposit,
        l.status,
        u.unit_number,
        u.unit_type,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        mo.id AS existing_id,
        mo.status AS existing_status,
        (SELECT COUNT(*) FROM re_move_out_notices mon
         WHERE mon.lease_id = l.id AND mon.status != 'cancelled') AS notice_count,
        EXISTS(SELECT 1 FROM re_move_ins mi
               WHERE mi.lease_id = l.id AND mi.status = 'completed') AS has_completed_move_in
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_move_outs mo ON mo.id = (
        SELECT mo2.id FROM re_move_outs mo2
        WHERE mo2.lease_id = l.id AND mo2.status != 'cancelled'
        ORDER BY mo2.id DESC LIMIT 1
    )
    WHERE l.company_id = ? AND " . re_lease_not_deleted_sql($conn, 'l') . "
      -- A draft is not listed while its unit has an active lease
      AND NOT (l.status = 'draft' AND EXISTS (
          SELECT 1 FROM re_leases la
          WHERE la.unit_id = l.unit_id AND la.company_id = l.company_id AND la.id <> l.id
            AND la.status IN ('active', 'has_legal_case') AND " . re_lease_not_deleted_sql($conn, 'la') . "
      ))
    ORDER BY (mo.id IS NOT NULL OR notice_count > 0 OR NOT has_completed_move_in OR l.status = 'draft'), l.end_date ASC, b.name, u.unit_number
");
$leases->execute([$currentCompanyId]);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);
// Renewals count the move-in of the first lease (same tenant and unit)
require_once __DIR__ . '/includes/move_in_chain_helper.php';
$carriedMap = re_move_in_carried_over_map($conn, (int)$currentCompanyId);
foreach ($leases as &$l) {
    if (!(int)$l['has_completed_move_in'] && isset($carriedMap[(int)$l['id']])) {
        $l['has_completed_move_in'] = 1;
    }
    if ($l['existing_id']) {
        $l['blocked_reason'] = 'Move-out ' . re_lease_picker_status_label((string)$l['existing_status']);
    } elseif ((int)$l['notice_count'] > 0) {
        $l['blocked_reason'] = 'Move-out notice exists';
    } elseif ($l['status'] === 'draft') {
        $l['blocked_reason'] = 'Lease is Draft';
    } elseif (!(int)$l['has_completed_move_in']) {
        $l['blocked_reason'] = 'No completed move-in';
    } else {
        $l['blocked_reason'] = '';
    }
}
unset($l);
// Available leases first (renewals covered by an earlier move-in are available)
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
$preselectDraft = $preselectBlocked && $preselectBlocked['status'] === 'draft'
    && !$preselectBlocked['existing_id'] && !(int)$preselectBlocked['notice_count'];
// Expired and terminated leases are not listed (still opens when started from the lease itself)
$leases = array_values(array_filter($leases, fn($l) => !in_array($l['status'], ['expired', 'terminated'], true)));
$availableCount = count(array_filter($leases, fn($l) => !$l['blocked_reason']));
$pickerLeases = $lockedLease ? [$lockedLease] : $leases;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'New Move-Out Notice';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles = ($pageStyles ?? '') . re_move_ui_css();
if (!$lockedLease) {
    $pageHead = re_lease_picker_head();
}
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="rm-page">
        <div class="rm-narrow">
                <a href="move_out.php" class="rm-back"><i class="bi bi-arrow-left"></i> Move-Out Management</a>
                <div class="rm-hero">
                    <div>
                        <h1><i class="bi bi-box-arrow-right"></i>New Move-Out Notice</h1>
                        <div class="rm-sub">Record the tenant's or landlord's notice. Inspection and deposit settlement follow.</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-file-earmark-text me-1"></i> Notice details</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger"><?= h($_SESSION['error']) ?></div>
                            <?php unset($_SESSION['error']); ?>
                        <?php endif; ?>
                        
                        <?php if ($preselectDraft): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                Lease <strong><?= h($preselectBlocked['lease_number']) ?></strong> is in <strong>Draft</strong> status.
                                Activate the lease before creating a move-out.
                                <a href="lease_add.php?id=<?= (int)$preselectBlocked['id'] ?>" class="alert-link">Edit Lease</a>
                            </div>
                        <?php elseif ($preselectBlocked && !$preselectBlocked['existing_id'] && !(int)$preselectBlocked['notice_count']): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                Lease <strong><?= h($preselectBlocked['lease_number']) ?></strong> has no completed move-in.
                                Complete the move-in before creating a move-out.
                                <a href="move_in_add.php?lease_id=<?= (int)$preselectBlocked['id'] ?>" class="alert-link">Go to Move-In</a>
                            </div>
                        <?php elseif ($preselectBlocked): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                Lease <strong><?= h($preselectBlocked['lease_number']) ?></strong> already has a move-out
                                (<?= h($preselectBlocked['blocked_reason']) ?>).
                                <?php if ($preselectBlocked['existing_id']): ?>
                                    <a href="move_out_view.php?id=<?= (int)$preselectBlocked['existing_id'] ?>" class="alert-link">View move-out</a>
                                <?php else: ?>
                                    <a href="move_out.php" class="alert-link">View move-outs</a>
                                <?php endif; ?>
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
                                No leases available for move-out. A lease needs a completed move-in and no existing move-out.
                            </div>
                            <a href="leases.php" class="btn btn-primary">View Leases</a>
                        <?php else: ?>
                            <form method="POST" id="moveOutForm">
                                <?= csrf_field() ?>
                                
                                <div class="mb-3">
                                    <label for="lease_id" class="form-label">Select Lease <span class="text-danger">*</span></label>
                                    <?php if ($lockedLease): ?>
                                        <?= re_lease_picker_locked($lockedLease, 'move_out_add.php') ?>
                                    <?php else: ?>
                                        <?= re_lease_picker_filters($leases) ?>
                                    <?php endif; ?>
                                    <select name="lease_id" id="lease_id" class="<?= $lockedLease ? 'd-none' : 'form-select' ?>" required>
                                        <option value="">-- Select Lease --</option>
                                        <?php foreach ($pickerLeases as $lease): ?>
                                            <option value="<?= $lease['id'] ?>" 
                                                    <?= $lockedLease ? 'selected' : '' ?>
                                                    <?= $lease['blocked_reason'] === 'Lease is Draft' ? 'data-blocked="1"' : ($lease['blocked_reason'] ? 'disabled' : '') ?>
                                                    data-lease-number="<?= h($lease['lease_number']) ?>"
                                                    data-tenant="<?= h($lease['first_name'] . ' ' . $lease['last_name']) ?>"
                                                    data-unit="<?= h($lease['building_name'] . ' - ' . $lease['unit_number']) ?>"
                                                    data-deposit="<?= number_format($lease['security_deposit'], 2) ?>"
                                                    data-end-date="<?= $lease['end_date'] ?>"
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
                                        <div class="form-text"><span id="leaseFilterCount"></span> · Type in the box to search. Greyed-out leases have no completed move-in or already have a move-out. Draft leases cannot have a move-out.</div>
                                    <?php endif; ?>
                                </div>
                                
                                <div id="leaseDetails" class="card mb-3" style="display: none;">
                                    <div class="card-body">
                                        <p class="rm-section-title">Lease details</p>
                                        <dl class="rm-dl">
                                            <dt>Tenant</dt><dd id="tenantName">-</dd>
                                            <dt>Unit</dt><dd id="unitInfo">-</dd>
                                            <dt>Security Deposit</dt><dd><span id="securityDeposit">-</span> AED</dd>
                                            <dt>Lease End</dt><dd id="leaseEnd">-</dd>
                                            <dt>Status</dt><dd id="leaseStatus">-</dd>
                                        </dl>
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
                                
                                <div class="row g-3 mb-3">
                                <div class="col-12 col-sm-6">
                                    <label for="notice_date" class="form-label">Notice Date <span class="text-danger">*</span></label>
                                    <input type="date" name="notice_date" id="notice_date" class="form-control" required>
                                    <div class="form-text">Date when the move-out notice was received/given.</div>
                                </div>
                                
                                <div class="col-12 col-sm-6">
                                    <label for="intended_move_out_date" class="form-label">Intended Move-Out Date <span class="text-danger">*</span></label>
                                    <input type="date" name="intended_move_out_date" id="intended_move_out_date" class="form-control" required>
                                    <div class="form-text">Expected date when tenant will vacate the unit.</div>
                                </div>
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
                                
                                <div class="rm-form-actions">
                                    <a href="move_out.php" class="btn btn-outline-secondary">
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

            // Draft lease: no move-out. Show the popup and clear the selection.
            if (option && option.value && option.dataset.status === 'draft') {
                showDraftLeasePopup(option.dataset.leaseNumber, option.value);
                select.value = '';
                select.dispatchEvent(new Event('change'));
                return;
            }
            
            if (select.value) {
                document.getElementById('tenantName').textContent = option.dataset.tenant || '-';
                document.getElementById('unitInfo').textContent = option.dataset.unit || '-';
                document.getElementById('securityDeposit').textContent = option.dataset.deposit || '-';
                const endDate = option.dataset.endDate ? new Date(option.dataset.endDate).toLocaleDateString() : '-';
                document.getElementById('leaseEnd').textContent = endDate;
                document.getElementById('leaseStatus').textContent = option.dataset.status ? option.dataset.status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : '-';
                detailsDiv.style.display = 'block';
                
                // Set default intended move-out date to lease end date if available
                if (option.dataset.endDate) {
                    // The date picker loads after this script: set the field directly until then
                    const moDate = document.getElementById('intended_move_out_date');
                    if (window.rmSetDate) { rmSetDate(moDate, option.dataset.endDate); } else { moDate.value = option.dataset.endDate; }
                }
            } else {
                detailsDiv.style.display = 'none';
            }
        });
        
        // Set default notice date to today
        document.getElementById('notice_date').valueAsDate = new Date();

        // Show details for a lease pre-selected via ?lease_id=
        if (document.getElementById('lease_id').value) {
            document.getElementById('lease_id').dispatchEvent(new Event('change'));
        }
    </script>
    <?= re_lease_draft_popup() ?>
    <?php if ($preselectDraft): ?>
        <script>showDraftLeasePopup(<?= json_encode((string)$preselectBlocked['lease_number']) ?>, <?= (int)$preselectBlocked['id'] ?>);</script>
    <?php endif; ?>
    <?php if (!$lockedLease) echo re_lease_picker_script(); ?>

<?= re_datepicker_assets() ?>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

