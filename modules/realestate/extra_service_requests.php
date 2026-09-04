<?php
/**
 * Real Estate — Tenant extra service requests (management view)
 * List, approve, reject, and add notes to tenant portal extra service requests.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_MAINTENANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Check for pricing columns (optional)
$hasPricingCols = false;
try {
    $conn->query("SELECT period_from, total_amount_aed, payment_status FROM tenant_extra_service_requests LIMIT 1");
    $hasPricingCols = true;
} catch (Throwable $e) {}

// Approve / Reject / Notes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['admin_notes'] ?? '');
    if ($id && in_array($action, ['approve', 'reject'], true)) {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $conn->prepare("UPDATE tenant_extra_service_requests SET status = ?, admin_notes = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND company_id = ?");
        $stmt->execute([$newStatus, $notes ?: null, current_user_id(), $id, $currentCompanyId]);
        if ($stmt->rowCount()) {
            // Tenant in-app notification: extra service request status changed
            require_once __DIR__ . '/../../includes/tenant_notifications.php';
            try {
                $esLease = $conn->prepare("SELECT lease_id FROM tenant_extra_service_requests WHERE id = ? AND company_id = ?");
                $esLease->execute([$id, $currentCompanyId]);
                $esLeaseId = (int)($esLease->fetchColumn() ?: 0);
                tenant_notification_create($conn, [
                    'company_id' => $currentCompanyId,
                    'lease_id' => $esLeaseId,
                    'type' => 'extra_service_status',
                    'entity_type' => 'extra_service',
                    'entity_id' => $id,
                    'title' => 'Service request ' . $newStatus,
                    'body' => 'Your extra service request has been ' . $newStatus . '.',
                ]);
            } catch (Throwable $e) {
                error_log('extra service notification failed: ' . $e->getMessage());
            }
            $_SESSION['extra_service_flash'] = ['success', $action === 'approve' ? 'Request approved.' : 'Request rejected.'];
        }
        header('Location: extra_service_requests.php');
        exit;
    }
}

$flash = $_SESSION['extra_service_flash'] ?? null;
unset($_SESSION['extra_service_flash']);

$statusFilter = $_GET['status'] ?? 'all';
$where = ["r.company_id = ?"];
$params = [$currentCompanyId];
if ($statusFilter !== 'all') {
    $where[] = "r.status = ?";
    $params[] = $statusFilter;
}

$cols = "r.id, r.service_type, r.description, r.status, r.admin_notes, r.created_at, r.approved_at, l.lease_number, t.first_name, t.last_name, t.phone, t.email, u.unit_number, b.name AS building_name";
if ($hasPricingCols) {
    $cols .= ", r.period_from, r.period_to, r.monthly_rate_aed, r.total_amount_aed, r.payment_status";
}
try {
    $conn->query("SELECT cash_payment_request_id FROM tenant_extra_service_requests LIMIT 1");
    $cols .= ", r.cash_payment_request_id";
} catch (Throwable $e) {}

$list = $conn->prepare("
    SELECT {$cols}
    FROM tenant_extra_service_requests r
    JOIN re_leases l ON l.id = r.lease_id
    JOIN re_tenants t ON t.id = r.tenant_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.created_at DESC
");
$list->execute($params);
$requests = $list->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Extra service requests';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Tenant extra service requests</div>
    <div>
        <a href="extra_service_rates.php" class="btn btn-outline-primary me-2"><i class="bi bi-currency-exchange"></i> Extra service rates</a>
        <a href="cleaning_requests.php" class="btn btn-outline-secondary me-2"><i class="bi bi-droplet"></i> Cleaning</a>
        <a href="pest_control_requests.php" class="btn btn-outline-secondary me-2"><i class="bi bi-bug"></i> Pest control</a>
        <a href="maintenance.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Maintenance</a>
    </div>
</div>
<p class="text-muted">Requests submitted by tenants (parking, storage, other). Also view <a href="cleaning_requests.php">Cleaning requests</a> and <a href="pest_control_requests.php">Pest control requests</a>.</p>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash[0] === 'success' ? 'success' : 'info' ?>"><?= h($flash[1]) ?></div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-body">
        <?php if (empty($requests)): ?>
            <p class="text-muted mb-0">No extra service requests found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Unit / Lease</th>
                            <th>Tenant</th>
                            <?php if ($hasPricingCols): ?><th>Period</th><th>Amount</th><th>Payment</th><?php endif; ?>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td>#<?= (int)$r['id'] ?></td>
                                <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                                <td><?= h(ucwords(str_replace('_', ' ', $r['service_type']))) ?></td>
                                <td><?= h($r['building_name'] . ' – ' . $r['unit_number']) ?><br><small class="text-muted"><?= h($r['lease_number']) ?></small></td>
                                <td><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?><br><?php if (!empty($r['phone'])): ?><small><?= h($r['phone']) ?></small><?php endif; ?></td>
                                <?php if ($hasPricingCols): ?>
                                <td>
                                    <?php if (!empty($r['period_from']) && !empty($r['period_to'])): ?>
                                        <?= date('M j, Y', strtotime($r['period_from'])) ?> – <?= date('M j, Y', strtotime($r['period_to'])) ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><?= !empty($r['total_amount_aed']) ? number_format((float)$r['total_amount_aed'], 2) . ' AED' : '—' ?></td>
                                <td><?php
                                    if (($r['payment_status'] ?? 'n_a') === 'paid') {
                                        echo '<span class="badge bg-success">Paid</span>';
                                    } elseif (!empty($r['cash_payment_request_id']) && file_exists(__DIR__ . '/includes/cash_payment_helper.php')) {
                                        require_once __DIR__ . '/includes/cash_payment_helper.php';
                                        echo cash_payment_tables_exist($conn) && cash_payment_can_activate_service($conn, 'extra_service', (int)$r['id'])
                                            ? '<span class="badge bg-success">Cash verified</span>' : '<span class="badge bg-warning">Cash pending</span>';
                                    } elseif (($r['payment_status'] ?? '') === 'pending_payment') {
                                        echo '<span class="badge bg-warning">Pending</span>';
                                    } else {
                                        echo '—';
                                    }
                                ?></td>
                                <?php endif; ?>
                                <td><span class="badge bg-<?= $r['status'] === 'approved' ? 'success' : ($r['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= h($r['status']) ?></span></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal<?= (int)$r['id'] ?>">View / Respond</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($requests)): ?>
    <?php foreach ($requests as $r): ?>
        <div class="modal fade" id="modal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Request #<?= (int)$r['id'] ?> — <?= h(ucwords(str_replace('_', ' ', $r['service_type']))) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-start">
                        <p class="mb-2"><strong>Unit / Lease:</strong> <?= h($r['building_name'] . ' – ' . $r['unit_number']) ?> (<?= h($r['lease_number']) ?>)</p>
                        <p class="mb-2"><strong>Tenant:</strong> <?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?><?php if (!empty($r['phone'])): ?> — <small class="text-muted"><?= h($r['phone']) ?></small><?php endif; ?></p>
                        <?php if ($hasPricingCols): ?>
                            <p class="mb-2"><strong>Period:</strong>
                                <?php if (!empty($r['period_from']) && !empty($r['period_to'])): ?>
                                    <?= date('M j, Y', strtotime($r['period_from'])) ?> – <?= date('M j, Y', strtotime($r['period_to'])) ?>
                                <?php else: ?>—<?php endif; ?>
                            </p>
                            <p class="mb-2"><strong>Amount:</strong> <?= !empty($r['total_amount_aed']) ? number_format((float)$r['total_amount_aed'], 2) . ' AED' : '—' ?></p>
                            <p class="mb-3"><strong>Payment status:</strong>
                                <?php if (($r['payment_status'] ?? 'n_a') === 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif (($r['payment_status'] ?? '') === 'pending_payment'): ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <p class="mb-1"><strong>Description:</strong></p>
                        <p><?= nl2br(h($r['description'] ?: '—')) ?></p>
                        <?php if ($r['admin_notes']): ?>
                            <p><strong>Your response:</strong></p>
                            <p><?= nl2br(h($r['admin_notes'])) ?></p>
                        <?php endif; ?>
                        <?php if ($r['status'] === 'pending'): ?>
                            <form method="POST" class="mt-3">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <div class="mb-3">
                                    <label class="form-label">Response to tenant (optional)</label>
                                    <textarea name="admin_notes" class="form-control" rows="2" placeholder="Notes visible to tenant..."><?= h($r['admin_notes'] ?? '') ?></textarea>
                                </div>
                                <button type="submit" name="action" value="approve" class="btn btn-success">Approve</button>
                                <button type="submit" name="action" value="reject" class="btn btn-danger">Reject</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
