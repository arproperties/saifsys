<?php
/**
 * Tenant Portal — Dashboard
 * Lease summary, outstanding balance, quick links. Multi-lease switcher. Cleaning & Pest control cards.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';
require_once __DIR__ . '/../modules/realestate/includes/payment_allocation_helper.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

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

// Outstanding: sum of pending/overdue installments (current lease)
$outstandingStmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM re_lease_installments
    WHERE lease_id = ? AND status IN ('pending', 'overdue')
");
$outstandingStmt->execute([$lease['lease_id']]);
$outstanding = (float) $outstandingStmt->fetchColumn();

// Outstanding penalties / late fees (from re_billing_items)
$penaltyOutstanding = 0.0;
try {
    $pOut = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0)
        FROM re_billing_items
        WHERE lease_id = ? AND item_type = 'penalty' AND status IN ('pending', 'overdue')
    ");
    $pOut->execute([$lease['lease_id']]);
    $penaltyOutstanding = (float)$pOut->fetchColumn();
} catch (Throwable $e) {
    $penaltyOutstanding = 0.0;
}

// Outstanding service charges (EV charging, paid parking services, etc.)
$serviceOutstanding = 0.0;
$activeServiceCount = 0;
$nextServiceCharge = null;
try {
    $svcStmt = $conn->prepare("
        SELECT bi.id, bi.due_date, bi.item_name, bi.total_amount, bi.status,
               sc.charge_name AS service_name
        FROM re_billing_items bi
        LEFT JOIN re_service_charges sc ON sc.id = bi.service_charge_id
        WHERE bi.lease_id = ?
          AND bi.item_type = 'service_charge'
          AND bi.status <> 'waived'
        ORDER BY bi.due_date ASC, bi.id ASC
    ");
    $svcStmt->execute([$lease['lease_id']]);
    while ($svc = $svcStmt->fetch(PDO::FETCH_ASSOC)) {
        $paid = get_billing_item_total_paid($conn, (int)$svc['id']);
        $open = max(0, (float)$svc['total_amount'] - $paid);
        if ($open > 0.005) {
            $serviceOutstanding += $open;
            if (!$nextServiceCharge) {
                $svc['outstanding_amount'] = $open;
                $nextServiceCharge = $svc;
            }
        }
        $activeServiceCount++;
    }
} catch (Throwable $e) {
    $serviceOutstanding = 0.0;
    $activeServiceCount = 0;
    $nextServiceCharge = null;
}

// Next upcoming installment (earliest pending/overdue by due date)
$upcomingStmt = $conn->prepare("
    SELECT installment_date, amount, status
    FROM re_lease_installments
    WHERE lease_id = ? AND status IN ('pending', 'overdue')
    ORDER BY installment_date ASC
    LIMIT 1
");
$upcomingStmt->execute([$lease['lease_id']]);
$upcoming_installment = $upcomingStmt->fetch(PDO::FETCH_ASSOC);

// Lease expiry info (for dashboard banners)
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

// Pending maintenance count (current lease)
$maintStmt = $conn->prepare("
    SELECT COUNT(*) FROM re_maintenance_requests
    WHERE lease_id = ? AND status IN ('pending', 'in_progress')
");
$maintStmt->execute([$lease['lease_id']]);
$pending_maintenance = (int) $maintStmt->fetchColumn();

// Pending cleaning requests (current lease) — table may not exist yet
$pending_cleaning = 0;
try {
    $c = $conn->prepare("SELECT COUNT(*) FROM tenant_cleaning_requests WHERE lease_id = ? AND status IN ('pending', 'approved')");
    $c->execute([$lease['lease_id']]);
    $pending_cleaning = (int) $c->fetchColumn();
} catch (Throwable $e) {}

// Pending pest control requests (current lease)
$pending_pest = 0;
try {
    $p = $conn->prepare("SELECT COUNT(*) FROM tenant_pest_control_requests WHERE lease_id = ? AND status IN ('pending', 'approved')");
    $p->execute([$lease['lease_id']]);
    $pending_pest = (int) $p->fetchColumn();
} catch (Throwable $e) {}

// Lease renewal inbox counts (portal Phase 1 — requires migration)
$renewal_pending = 0;
$renewal_await_signature = 0;
$renewal_completed = 0;
try {
    $rwStmt = $conn->prepare("
        SELECT rw.status,
               (SELECT COUNT(*) FROM re_renewal_electronic_signatures es WHERE es.workflow_id = rw.id) AS signature_count
        FROM re_lease_renewal_workflows rw
        INNER JOIN re_leases l ON l.id = rw.lease_id AND l.company_id = ?
        WHERE (rw.lease_id = ? OR rw.new_lease_id = ?)
          AND rw.status <> 'initiated'
          AND (
            rw.notice_sent_at IS NOT NULL
            OR rw.renewal_notice_pdf_path IS NOT NULL
            OR rw.status IN ('viewed_by_tenant','acknowledged','pending_response','negotiation','accepted','rejected','approved','contract_ready','signed','completed','converted')
          )
    ");
    $rwStmt->execute([(int)$lease['company_id'], (int)$lease['lease_id'], (int)$lease['lease_id']]);
    while ($row = $rwStmt->fetch(PDO::FETCH_ASSOC)) {
        $st = (string)($row['status'] ?? '');
        $sig = (int)($row['signature_count'] ?? 0);
        if ($st === 'contract_ready' && $sig === 0) {
            $renewal_await_signature++;
        }
        if (!in_array($st, ['converted', 'rejected', 'signed', 'completed'], true)) {
            $renewal_pending++;
        }
        if (in_array($st, ['converted', 'rejected', 'signed', 'completed'], true)) {
            $renewal_completed++;
        }
    }
} catch (Throwable $e) {
    $renewal_pending = 0;
    $renewal_await_signature = 0;
    $renewal_completed = 0;
}

// Documents expiring soon (next 90 days) for this lease/unit
$expiring_docs_count = 0;
try {
    $docStmt = $conn->prepare("
        SELECT COUNT(*) FROM re_documents
        WHERE company_id = ?
          AND expiry_date IS NOT NULL
          AND expiry_date >= CURDATE()
          AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
          AND status IN ('active','renewed')
          AND (
              (related_type = 'lease' AND related_id = ?) OR
              (related_type = 'unit' AND related_id = ?)
          )
    ");
    $docStmt->execute([$lease['company_id'], $lease['lease_id'], $lease['unit_id']]);
    $expiring_docs_count = (int)$docStmt->fetchColumn();
} catch (Throwable $e) {
    $expiring_docs_count = 0;
}

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Welcome to your Tenant Portal</h4>

<?php if ($leaseExpiryFlag === 'expiring_soon'): ?>
    <div class="alert alert-info mb-4">
        <strong>Lease renewal:</strong>
        Your lease ends on
        <?= htmlspecialchars($leaseEndDate->format('M j, Y')) ?>
        (in <?= (int)$leaseDaysToEnd ?> day<?= $leaseDaysToEnd === 1 ? '' : 's' ?>).
        <a href="lease.php#renewal" class="ms-1">I'm interested in renewing</a>
    </div>
<?php elseif ($leaseExpiryFlag === 'expired'): ?>
    <div class="alert alert-warning mb-4">
        <strong>Lease expired:</strong>
        Your lease ended on
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
                <select name="lease_id" class="form-select form-select-sm" style="max-width: 220px;" onchange="this.form.submit()">
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

<div class="row g-3 g-md-4">
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Lease</div>
            <div class="stat-value"><?= htmlspecialchars($lease['lease_number']) ?></div>
            <p class="small text-muted mb-0 mt-1"><?= htmlspecialchars($lease['building_name'] . ' — ' . $lease['unit_number']) ?></p>
            <a href="lease.php" class="btn btn-outline-primary btn-sm mt-3 w-100">View details</a>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Upcoming installment</div>
            <?php if ($upcoming_installment): ?>
                <div class="stat-value"><?= number_format((float)$upcoming_installment['amount'], 2) ?> <small class="text-muted fw-normal">AED</small></div>
                <p class="small text-muted mb-0 mt-1">Due <?= htmlspecialchars(date('M j, Y', strtotime($upcoming_installment['installment_date']))) ?></p>
            <?php else: ?>
                <div class="stat-value small" style="font-size: 1rem;">No upcoming installments</div>
                <p class="small text-muted mb-0 mt-1">All installments paid or none scheduled</p>
            <?php endif; ?>
            <a href="payments.php" class="btn btn-outline-primary btn-sm mt-3 w-100">Payments</a>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Outstanding balance</div>
            <div class="stat-value <?= ($outstanding + $penaltyOutstanding + $serviceOutstanding) > 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($outstanding + $penaltyOutstanding + $serviceOutstanding, 2) ?> AED</div>
            <?php if ($serviceOutstanding > 0): ?>
                <p class="small text-muted mb-0 mt-1">
                    Includes <?= number_format($serviceOutstanding, 2) ?> AED in service charges.
                </p>
            <?php endif; ?>
            <?php if ($penaltyOutstanding > 0): ?>
                <p class="small text-muted mb-0 mt-1">
                    Includes <?= number_format($penaltyOutstanding, 2) ?> AED in penalties / late fees.
                </p>
            <?php endif; ?>
            <?php if (($outstanding + $penaltyOutstanding + $serviceOutstanding) > 0): ?>
                <a href="payments.php" class="btn btn-primary btn-sm mt-3 w-100">View installments</a>
            <?php else: ?>
                <a href="payments.php" class="btn btn-outline-primary btn-sm mt-3 w-100">View payments</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Lease period</div>
            <div class="stat-value small" style="font-size: 1rem;"><?= htmlspecialchars(date('M j, Y', strtotime($lease['start_date']))) ?> — <?= htmlspecialchars(date('M j, Y', strtotime($lease['end_date']))) ?></div>
            <span class="badge bg-<?= $lease['status'] === 'active' ? 'success' : 'secondary' ?> mt-2"><?= htmlspecialchars($lease['status']) ?></span>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Maintenance</div>
            <div class="stat-value"><?= $pending_maintenance ?> <small class="text-muted fw-normal">open request(s)</small></div>
            <a href="maintenance.php" class="btn btn-outline-primary btn-sm mt-3 w-100">View / submit request</a>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Documents</div>
            <div class="stat-value small" style="font-size: 1rem;">Lease & notices</div>
            <?php if ($expiring_docs_count > 0): ?>
                <p class="small text-warning mb-0 mt-1">
                    <?= (int)$expiring_docs_count ?> document<?= $expiring_docs_count === 1 ? '' : 's' ?> expiring in the next 90 days.
                </p>
            <?php endif; ?>
            <a href="documents.php" class="btn btn-outline-primary btn-sm mt-3 w-100">View documents</a>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Cleaning</div>
            <div class="stat-value"><?= $pending_cleaning ?> <small class="text-muted fw-normal">request(s)</small></div>
            <a href="cleaning.php" class="btn btn-outline-primary btn-sm mt-3 w-100">Request cleaning</a>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Pest control</div>
            <div class="stat-value"><?= $pending_pest ?> <small class="text-muted fw-normal">request(s)</small></div>
            <a href="pest_control.php" class="btn btn-outline-primary btn-sm mt-3 w-100">Request pest control</a>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Extra services</div>
            <?php if ($nextServiceCharge): ?>
                <div class="stat-value"><?= number_format((float)$nextServiceCharge['outstanding_amount'], 2) ?> <small class="text-muted fw-normal">AED</small></div>
                <p class="small text-muted mb-0 mt-1">
                    <?= htmlspecialchars($nextServiceCharge['service_name'] ?: $nextServiceCharge['item_name']) ?>
                    due <?= htmlspecialchars(date('M j, Y', strtotime($nextServiceCharge['due_date']))) ?>
                </p>
            <?php elseif ($activeServiceCount > 0): ?>
                <div class="stat-value small" style="font-size: 1rem;"><?= (int)$activeServiceCount ?> service schedule item(s)</div>
                <p class="small text-muted mb-0 mt-1">All service charges are paid.</p>
            <?php else: ?>
                <div class="stat-value small" style="font-size: 1rem;">Parking, storage, services</div>
            <?php endif; ?>
            <a href="payments.php#service-charges" class="btn btn-outline-primary btn-sm mt-3 w-100">View service charges</a>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Lease renewals</div>
            <?php if ($renewal_await_signature > 0): ?>
                <div class="stat-value text-primary"><?= (int)$renewal_await_signature ?> <small class="text-muted fw-normal">awaiting signature</small></div>
                <p class="small text-muted mb-0 mt-1">Review and sign your renewal contract in the portal.</p>
            <?php elseif ($renewal_pending > 0): ?>
                <div class="stat-value"><?= (int)$renewal_pending ?> <small class="text-muted fw-normal">in progress</small></div>
                <p class="small text-muted mb-0 mt-1">Open notices, acknowledge, or respond to your renewal offer.</p>
            <?php elseif ($renewal_completed > 0): ?>
                <div class="stat-value small" style="font-size: 1rem;"><?= (int)$renewal_completed ?> completed</div>
                <p class="small text-muted mb-0 mt-1">No action needed. View notices and history under Renewals.</p>
            <?php else: ?>
                <div class="stat-value small" style="font-size: 1rem;">No active renewals</div>
                <p class="small text-muted mb-0 mt-1">Renewal notices from management will show here.</p>
            <?php endif; ?>
            <a href="renewals.php" class="btn btn-outline-primary btn-sm mt-3 w-100"><?= ($renewal_pending > 0 || $renewal_await_signature > 0) ? 'Open renewals' : 'View renewals' ?></a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
