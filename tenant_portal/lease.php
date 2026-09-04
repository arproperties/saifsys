<?php
/**
 * Tenant Portal — Lease details (read-only)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';
require_once __DIR__ . '/includes/renewal_portal_helper.php';
require_once __DIR__ . '/includes/tenancy_contract_scan_helper.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

$renewalMessage = '';
$renewalMessageType = '';
$contractScanMessage = '';
$contractScanMessageType = '';

$lease_ids = current_tenant_lease_ids($conn);
$allLeasesForSwitcher = [];
if (count($lease_ids) > 1) {
    $placeholders = implode(',', array_fill(0, count($lease_ids), '?'));
    $stmt = $conn->prepare("
        SELECT l.id, l.lease_number, u.unit_number, b.name AS building_name
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE l.id IN ($placeholders)
        ORDER BY l.lease_number
    ");
    $stmt->execute(array_values($lease_ids));
    $allLeasesForSwitcher = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if there is already a renewal workflow for this lease (original or converted new lease)
$hasRenewalWorkflow = false;
try {
    $rw = $conn->prepare("
        SELECT COUNT(*) FROM re_lease_renewal_workflows
        WHERE lease_id = ? OR new_lease_id = ?
    ");
    $rw->execute([(int)$lease['lease_id'], (int)$lease['lease_id']]);
    $hasRenewalWorkflow = ((int)$rw->fetchColumn() > 0);
} catch (Throwable $e) {
    $hasRenewalWorkflow = false;
}

$contractPath = trim((string)($lease['generated_contract_path'] ?? ''));
$hasContractPdf = $contractPath !== '';

$renewalRows = [];
try {
    $rs = $conn->prepare("
        SELECT id, status, initiated_date, proposed_start_date, proposed_end_date
        FROM re_lease_renewal_workflows
        WHERE (lease_id = ? OR new_lease_id = ?)
          AND status <> 'initiated'
        ORDER BY id DESC
        LIMIT 5
    ");
    $rs->execute([(int)$lease['lease_id'], (int)$lease['lease_id']]);
    $renewalRows = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $renewalRows = [];
}

// Signed tenancy contract scan (PDF) — after offline tenant signature
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tenancy_signed_scan_upload') {
    csrf_verify();
    if (!$hasContractPdf) {
        $contractScanMessage = 'Please wait until your official tenancy contract PDF is available, then download it before uploading your signed scan.';
        $contractScanMessageType = 'warning';
    } else {
        $actor = tenant_renewal_actor_ids();
        $res = tenancy_contract_save_tenant_scan_pdf(
            $conn,
            $lease,
            $_FILES['signed_scan'] ?? [],
            (int)$actor['tpu_id'],
            (int)$actor['legacy_user_id']
        );
        $contractScanMessage = $res['message'];
        $contractScanMessageType = $res['ok'] ? 'success' : 'danger';
    }
}

$activeTenantScan = tenancy_contract_fetch_active_tenant_scan(
    $conn,
    (int)$lease['lease_id'],
    (int)$lease['company_id']
);

// Handle tenant expressing interest in renewal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'renewal_interest') {
    csrf_verify();
    if ($hasRenewalWorkflow) {
        $renewalMessage = 'A renewal request is already in progress for this lease.';
        $renewalMessageType = 'info';
    } elseif (empty($lease['end_date'])) {
        $renewalMessage = 'This lease does not have an end date on file. Please contact management.';
        $renewalMessageType = 'danger';
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO re_lease_renewal_workflows
                    (lease_id, workflow_step, initiated_date, target_renewal_date, proposed_rent, proposed_start_date, proposed_end_date, assigned_to)
                VALUES
                    (?, 'tenant_interested', CURDATE(), ?, NULL, NULL, NULL, NULL)
            ");
            $stmt->execute([(int)$lease['lease_id'], $lease['end_date']]);
            $workflowId = (int)$conn->lastInsertId();

            // Notify management via Real Estate email helper (if available)
            $emailOk = false;
            try {
                $companyId = (int)($lease['company_id'] ?? 0);
                if ($companyId > 0 && file_exists(__DIR__ . '/../modules/realestate/includes/re_email_helper.php')) {
                    require_once __DIR__ . '/../modules/realestate/includes/re_email_helper.php';
                    $tenantName = trim(($lease['first_name'] ?? '') . ' ' . ($lease['last_name'] ?? ''));
                    $leaseNumber = $lease['lease_number'] ?? '';
                    $endDateText = (new DateTimeImmutable($lease['end_date']))->format('M j, Y');
                    $subject = '[Tenant Portal] Renewal interest for lease ' . $leaseNumber;
                    $htmlBody = "
                    <html><body style='font-family: Arial, sans-serif; line-height:1.6;'>
                        <p><strong>Tenant Portal — Lease renewal interest</strong></p>
                        <p>The tenant <strong>" . htmlspecialchars($tenantName ?: 'Unknown tenant', ENT_QUOTES, 'UTF-8') . "</strong>"
                        . " has indicated interest in renewing lease <strong>" . htmlspecialchars($leaseNumber, ENT_QUOTES, 'UTF-8') . "</strong>.</p>
                        <p><strong>Lease end date:</strong> " . htmlspecialchars($endDateText, ENT_QUOTES, 'UTF-8') . "</p>
                        <p>You can review and manage the renewal workflow in the Real Estate module.</p>
                    </body></html>";
                    $emailResult = send_re_email_notification(
                        $conn,
                        $companyId,
                        'lease_renewal_interest',
                        $subject,
                        $htmlBody,
                        [],
                        $workflowId,
                        'lease_renewal_workflow'
                    );
                    $emailOk = $emailResult['success'];
                }
            } catch (Throwable $e) {
                // Email failures should not block the tenant
                $emailOk = false;
            }

            $conn->commit();

            $hasRenewalWorkflow = true;
            $renewalMessage = 'Thank you. We have recorded your interest in renewing this lease. Management will follow up with you.';
            if (!$emailOk) {
                $renewalMessage .= ' (Email notification could not be sent, but your request was saved.)';
            }
            $renewalMessageType = 'success';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $renewalMessage = (defined('APP_ENV') && APP_ENV === 'dev')
                ? 'Could not record renewal request: ' . htmlspecialchars($e->getMessage())
                : 'We could not record your renewal request. Please try again later or contact management.';
            $renewalMessageType = 'danger';
        }
    }
}

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Lease expiry helper (for inline messaging)
$leaseEndDate = null;
$leaseDaysToEnd = null;
$leaseExpiryFlag = null; // 'expired', 'expiring_soon', 'active'
if (!empty($lease['end_date'])) {
    try {
        $end = new DateTimeImmutable($lease['end_date']);
        $today = new DateTimeImmutable('today');
        $diff = $today->diff($end);
        $days = (int)$diff->format('%r%a');
        $leaseEndDate = $end;
        $leaseDaysToEnd = $days;
        if ($days < 0) {
            $leaseExpiryFlag = 'expired';
        } elseif ($days <= 120) {
            $leaseExpiryFlag = 'expiring_soon';
        } else {
            $leaseExpiryFlag = 'active';
        }
    } catch (Throwable $e) {
        // ignore invalid dates
    }
}

$pageTitle = 'Lease details';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Lease details</h4>

<?php if ($renewalMessage): ?>
    <div class="alert alert-<?= htmlspecialchars($renewalMessageType) ?> mb-3">
        <?= htmlspecialchars($renewalMessage) ?>
    </div>
<?php endif; ?>
<?php if ($contractScanMessage): ?>
    <div class="alert alert-<?= htmlspecialchars($contractScanMessageType) ?> mb-3 alert-dismissible fade show">
        <?= htmlspecialchars($contractScanMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<a id="renewal"></a>

<?php if ($leaseExpiryFlag === 'expiring_soon'): ?>
    <div class="alert alert-info mb-3">
        <strong>Lease renewal:</strong>
        This lease ends on
        <?= htmlspecialchars($leaseEndDate->format('M j, Y')) ?>
        (in <?= (int)$leaseDaysToEnd ?> day<?= $leaseDaysToEnd === 1 ? '' : 's' ?>).
        <?php if ($hasRenewalWorkflow): ?>
            A renewal request is already in progress.
        <?php else: ?>
            If you would like to renew, click the button below and we will notify management.
        <?php endif; ?>
        <?php if (!$hasRenewalWorkflow): ?>
            <form method="post" class="mt-2">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="renewal_interest">
                <button type="submit" class="btn btn-sm btn-primary">I'm interested in renewing</button>
            </form>
        <?php endif; ?>
    </div>
<?php elseif ($leaseExpiryFlag === 'expired'): ?>
    <div class="alert alert-warning mb-3">
        <strong>Lease expired:</strong>
        This lease ended on
        <?= htmlspecialchars($leaseEndDate->format('M j, Y')) ?>.
        Please contact management for renewal or move-out arrangements.
    </div>
<?php endif; ?>

<?php if (count($allLeasesForSwitcher) > 1): ?>
<div class="portal-card card mb-4">
    <div class="card-body py-3">
        <form method="post" action="switch_lease.php" class="row align-items-center g-2">
            <div class="col-auto">
                <label class="form-label mb-0 small text-muted">Viewing lease</label>
            </div>
            <div class="col-auto">
                <select name="lease_id" class="form-select form-select-sm" style="max-width: 260px;" onchange="this.form.submit()">
                    <?php foreach ($allLeasesForSwitcher as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === (int)$lease['lease_id'] ? 'selected' : '' ?>>
                            <?= h($l['lease_number']) ?> — <?= h($l['building_name'] . ' ' . $l['unit_number']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-primary">Switch</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="portal-card card h-100 border-primary border-opacity-25">
            <div class="card-header bg-primary bg-opacity-10 d-flex align-items-center">
                <i class="bi bi-file-earmark-pdf text-primary me-2"></i>
                <span>Tenancy contract</span>
            </div>
            <div class="card-body">
                <?php if ($hasContractPdf): ?>
                    <div class="small mb-3 p-3 bg-light rounded border">
                        <strong class="d-block mb-2">How to complete signing (offline)</strong>
                        <ol class="mb-0 ps-3">
                            <li class="mb-1"><strong>Download</strong> your tenancy contract using the button below.</li>
                            <li class="mb-1"><strong>Print</strong> the PDF and sign it by hand where required.</li>
                            <li class="mb-1"><strong>Scan</strong> or photograph every page clearly and save as <strong>one PDF file</strong> (max 10&nbsp;MB).</li>
                            <li class="mb-1"><strong>Upload</strong> that PDF here so management can process it.</li>
                            <li class="mb-0">Management will arrange <strong>landlord signature</strong>, then upload the <strong>final fully signed contract</strong> to your documents. You will see it under <a href="documents.php">Documents</a> (and below when available).</li>
                        </ol>
                    </div>
                    <a class="btn btn-primary mb-3" href="lease_download_contract.php?lease_id=<?= (int)$lease['lease_id'] ?>" target="_blank" rel="noopener">
                        <i class="bi bi-download me-1"></i> View / download PDF
                    </a>
                    <?php if ($activeTenantScan): ?>
                        <div class="alert alert-success small py-2 mb-3">
                            <strong>Uploaded:</strong> <?= h($activeTenantScan['original_filename'] ?? 'Signed scan') ?>
                            on <?= h(date('M j, Y g:i A', strtotime((string)($activeTenantScan['uploaded_at'] ?? 'now')))) ?>.
                            <a class="btn btn-sm btn-outline-success ms-2" href="download_tenant_contract_scan.php?id=<?= (int)$activeTenantScan['id'] ?>" target="_blank" rel="noopener">Open your upload</a>
                        </div>
                    <?php endif; ?>
                    <?php if (tenancy_contract_scan_table_exists($conn)): ?>
                        <form method="post" enctype="multipart/form-data" class="border rounded p-3 bg-white">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="tenancy_signed_scan_upload">
                            <label class="form-label fw-semibold small">Upload signed contract (PDF only)</label>
                            <input type="file" name="signed_scan" class="form-control form-control-sm mb-2" accept=".pdf,application/pdf" required>
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-upload me-1"></i><?= $activeTenantScan ? 'Replace with new scan' : 'Upload signed scan' ?>
                            </button>
                            <?php if ($activeTenantScan): ?>
                                <p class="text-muted small mt-2 mb-0">Uploading again replaces your previous file for this lease.</p>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <p class="text-warning small mb-0">Signed scan upload is not enabled yet (database migration pending).</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted small mb-0">
                        No tenancy contract PDF is on file for this lease yet. After management generates the contract in the admin system, it will appear here automatically.
                    </p>
                    <p class="small text-muted mt-2 mb-0">
                        <strong>Electronic signature:</strong> If you were asked to sign a <em>renewal draft</em>, that happens under
                        <a href="renewals.php">Renewals</a> when the status is &ldquo;Contract ready to sign.&rdquo; The final PDF on this page is separate and is added when management generates the official document.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="portal-card card h-100">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span><i class="bi bi-arrow-repeat me-2"></i>Renewal activity</span>
                <a href="renewals.php" class="btn btn-sm btn-outline-primary">All renewals</a>
            </div>
            <div class="card-body">
                <?php if (empty($renewalRows)): ?>
                    <p class="text-muted small mb-0">No renewal notices or workflow history for this lease yet.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($renewalRows as $rr): ?>
                            <li class="list-group-item px-0 d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <div>
                                    <span class="badge bg-secondary"><?= h(tenant_renewal_status_label((string)($rr['status'] ?? ''))) ?></span>
                                    <?php if (!empty($rr['initiated_date'])): ?>
                                        <span class="text-muted small ms-1"><?= h(date('M j, Y', strtotime($rr['initiated_date']))) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($rr['proposed_start_date']) && !empty($rr['proposed_end_date'])): ?>
                                        <div class="small text-muted mt-1">
                                            Proposed: <?= h(date('M j, Y', strtotime($rr['proposed_start_date']))) ?> — <?= h(date('M j, Y', strtotime($rr['proposed_end_date']))) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <a class="btn btn-sm btn-outline-secondary" href="renewal_detail.php?id=<?= (int)$rr['id'] ?>">Open</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="portal-card card mb-4">
    <div class="card-header"><?= htmlspecialchars($lease['lease_number']) ?></div>
    <div class="card-body">
        <div class="row g-3 g-md-4">
            <div class="col-12 col-md-6">
                <p class="mb-2"><strong>Unit:</strong> <?= htmlspecialchars($lease['building_name'] . ' — ' . $lease['unit_number']) ?></p>
                <p class="mb-2"><strong>Unit type:</strong> <?= htmlspecialchars($lease['unit_type']) ?></p>
                <?php if (!empty($lease['area_sqm'])): ?>
                    <p class="mb-2"><strong>Area:</strong> <?= htmlspecialchars($lease['area_sqm']) ?> sqm</p>
                <?php endif; ?>
                <p class="mb-2"><strong>Period:</strong> <?= htmlspecialchars(date('M j, Y', strtotime($lease['start_date']))) ?> — <?= htmlspecialchars(date('M j, Y', strtotime($lease['end_date']))) ?></p>
                <p class="mb-0"><strong>Status:</strong> <span class="badge bg-<?= $lease['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($lease['status']) ?></span></p>
            </div>
            <div class="col-12 col-md-6">
                <p class="mb-2"><strong>Annual rent:</strong> 
                    <?php $annual = isset($lease['annual_rent']) && $lease['annual_rent'] !== null ? (float)$lease['annual_rent'] : ((float)$lease['monthly_rent'] * 12); ?>
                    <?= number_format($annual, 2) ?> AED
                </p>
                <?php if (!empty($lease['monthly_service_charge']) && (float)$lease['monthly_service_charge'] > 0): ?>
                    <p class="mb-2"><strong>Service charge:</strong> <?= number_format((float)$lease['monthly_service_charge'], 2) ?> AED</p>
                <?php endif; ?>
                <?php if (!empty($lease['security_deposit'])): ?>
                    <p class="mb-2"><strong>Security deposit:</strong> <?= number_format((float)$lease['security_deposit'], 2) ?> AED</p>
                <?php endif; ?>
                <p class="mb-2"><strong>Payment method:</strong> <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $lease['payment_method'] ?? ''))) ?></p>
                <?php if (!empty($lease['payment_day'])): ?>
                    <p class="mb-0"><strong>Rent due day:</strong> <?= (int)$lease['payment_day'] ?> of each month</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<div class="portal-card card">
    <div class="card-header">Tenant contact</div>
    <div class="card-body">
        <p class="mb-1 fw-semibold"><?= htmlspecialchars(trim($lease['first_name'] . ' ' . $lease['last_name'])) ?></p>
        <?php if (!empty($lease['email'])): ?>
            <p class="mb-1 text-muted small"><a href="mailto:<?= htmlspecialchars($lease['email']) ?>"><?= htmlspecialchars($lease['email']) ?></a></p>
        <?php endif; ?>
        <?php if (!empty($lease['phone'])): ?>
            <p class="mb-0 text-muted small"><a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $lease['phone'])) ?>"><?= htmlspecialchars($lease['phone']) ?></a></p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
