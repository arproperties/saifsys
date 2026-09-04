<?php
/**
 * Real Estate — Tenant cleaning requests (management view). Also linked from Tenant extra service requests.
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

try {
    $conn->query("SELECT 1 FROM tenant_cleaning_requests LIMIT 1");
} catch (Throwable $e) {
    $pageTitle = 'Cleaning requests';
    require_once __DIR__ . '/includes/re_layout_header.php';
    echo '<div class="alert alert-info">No cleaning requests yet. Run migration <code>tenant_cleaning_pest_control.sql</code> if needed.</div>';
    require_once __DIR__ . '/includes/re_layout_footer.php';
    exit;
}

$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['admin_notes'] ?? '');
    $service_date_raw = trim($_POST['service_date'] ?? '');
    $service_time = trim($_POST['service_time'] ?? '');
    $service_date_sql = null;
    if ($service_date_raw !== '') {
        try {
            $d = new DateTime($service_date_raw);
            $service_date_sql = $d->format('Y-m-d');
        } catch (Throwable $e) {
            $service_date_sql = null;
        }
    }
    if ($id && in_array($action, ['approve', 'reject'], true)) {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $conn->prepare("
            UPDATE tenant_cleaning_requests
            SET status = ?, admin_notes = ?, service_date = COALESCE(?, service_date), service_time = COALESCE(?, service_time),
                approved_by = ?, approved_at = NOW()
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$status, $notes ?: null, $service_date_sql, $service_time ?: null, current_user_id(), $id, $currentCompanyId]);
        if ($stmt->rowCount()) {
            // Tenant in-app notification: cleaning request status changed
            require_once __DIR__ . '/../../includes/tenant_notifications.php';
            try {
                $clLease = $conn->prepare("SELECT lease_id FROM tenant_cleaning_requests WHERE id = ? AND company_id = ?");
                $clLease->execute([$id, $currentCompanyId]);
                $clLeaseId = (int)($clLease->fetchColumn() ?: 0);
                tenant_notification_create($conn, [
                    'company_id' => $currentCompanyId,
                    'lease_id' => $clLeaseId,
                    'type' => 'cleaning_status',
                    'entity_type' => 'cleaning',
                    'entity_id' => $id,
                    'title' => 'Cleaning request ' . $status,
                    'body' => 'Your cleaning request has been ' . $status . '.',
                ]);
            } catch (Throwable $e) {
                error_log('cleaning notification failed: ' . $e->getMessage());
            }
            // Send confirmation email to tenant on approve
            if ($status === 'approved') {
                $q = $conn->prepare("
                    SELECT r.*, t.email, t.first_name, t.last_name, u.unit_number, b.name AS building_name
                    FROM tenant_cleaning_requests r
                    JOIN re_leases l ON l.id = r.lease_id
                    JOIN re_tenants t ON t.id = r.tenant_id
                    JOIN re_units u ON u.id = l.unit_id
                    JOIN re_buildings b ON b.id = u.building_id
                    WHERE r.id = ? AND r.company_id = ?
                ");
                $q->execute([$id, $currentCompanyId]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['email'])) {
                    $to = trim($row['email']);
                    $tenantName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $servDate = $row['service_date'] ? date('M j, Y', strtotime($row['service_date'])) : null;
                    $servTime = $row['service_time'] ?: null;
                    $subject = 'Cleaning service confirmation';
                    $html = "
                    <html><body style='font-family: Arial, sans-serif; line-height:1.6;'>
                        <p>Dear " . htmlspecialchars($tenantName ?: 'Tenant', ENT_QUOTES, 'UTF-8') . ",</p>
                        <p>Your cleaning request has been <strong>approved</strong>.</p>
                        <p><strong>Unit:</strong> " . htmlspecialchars($row['building_name'] . ' – ' . $row['unit_number'], ENT_QUOTES, 'UTF-8') . "</p>";
                    if ($servDate) {
                        $html .= "<p><strong>Scheduled date:</strong> " . htmlspecialchars($servDate, ENT_QUOTES, 'UTF-8') .
                                 ($servTime ? " at " . htmlspecialchars($servTime, ENT_QUOTES, 'UTF-8') : '') . "</p>";
                    }
                    if ($row['total_amount_aed'] !== null) {
                        $html .= "<p><strong>Amount:</strong> " . number_format((float)$row['total_amount_aed'], 2) . " AED</p>";
                    }
                    if ($notes) {
                        $html .= "<p><strong>Note from management:</strong><br>" . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . "</p>";
                    }
                    $html .= "<p>Thank you.</p></body></html>";

                    $s = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
                    $s->execute();
                    $emailSettings = $s->fetch(PDO::FETCH_ASSOC);
                    if ($emailSettings) {
                        require_once __DIR__ . '/../../includes/mailer.php';
                        send_smtp_mail($emailSettings, $to, $subject, $html);
                    }
                }
            }
            $_SESSION['cleaning_flash'] = ['success', $action === 'approve' ? 'Request approved.' : 'Request rejected.'];
        }
        header('Location: cleaning_requests.php');
        exit;
    }
}

$flash = $_SESSION['cleaning_flash'] ?? null;
unset($_SESSION['cleaning_flash']);
$statusFilter = $_GET['status'] ?? 'all';
$where = ["r.company_id = ?"];
$params = [$currentCompanyId];
if ($statusFilter !== 'all') {
    $where[] = "r.status = ?";
    $params[] = $statusFilter;
}

$list = $conn->prepare("
    SELECT r.*, l.lease_number, t.first_name, t.last_name, t.phone, u.unit_number, b.name AS building_name
    FROM tenant_cleaning_requests r
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
$pageTitle = 'Cleaning requests';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Tenant cleaning requests</div>
    <div>
        <a href="cleaning_rates.php" class="btn btn-outline-primary me-2">Cleaning rates</a>
        <a href="extra_service_requests.php" class="btn btn-secondary me-2">Extra services</a>
        <a href="pest_control_requests.php" class="btn btn-outline-secondary">Pest control</a>
    </div>
</div>
<?php if ($flash): ?><div class="alert alert-success"><?= h($flash[1]) ?></div><?php endif; ?>
<div class="card card-round mb-3">
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
            <p class="text-muted mb-0">No cleaning requests found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Service date</th>
                            <th>Unit / Lease</th>
                            <th>Tenant</th>
                            <th>Cleaners</th>
                            <th>Hours</th>
                            <th>Materials</th>
                            <th>Amount (AED)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td>#<?= (int)$r['id'] ?></td>
                                <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                                <td>
                                    <?php if (!empty($r['service_date'])): ?>
                                        <?= date('M j, Y', strtotime($r['service_date'])) ?><?= $r['service_time'] ? ' &middot; ' . h($r['service_time']) : '' ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><?= h($r['building_name'] . ' – ' . $r['unit_number']) ?><br><small class="text-muted"><?= h($r['lease_number']) ?></small></td>
                                <td><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
                                <td><?= (int)$r['num_cleaners'] ?></td>
                                <td><?= h($r['num_hours']) ?></td>
                                <td><?= $r['has_materials'] ? 'Yes' : 'No' ?></td>
                                <td><?= $r['total_amount_aed'] !== null ? number_format((float)$r['total_amount_aed'], 2) : '—' ?></td>
                                <td><span class="badge bg-<?= $r['status'] === 'approved' ? 'success' : ($r['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= h($r['status']) ?></span></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal<?= (int)$r['id'] ?>">View / Respond</button>
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
                        <h5 class="modal-title">Cleaning request #<?= (int)$r['id'] ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-start">
                        <p><strong>Unit:</strong> <?= h($r['building_name'] . ' – ' . $r['unit_number']) ?> | <strong>Lease:</strong> <?= h($r['lease_number']) ?></p>
                        <p><strong>Tenant:</strong> <?= h($r['first_name'] . ' ' . $r['last_name']) ?></p>
                        <p><strong>Service date:</strong>
                            <?php if (!empty($r['service_date'])): ?>
                                <?= date('M j, Y', strtotime($r['service_date'])) ?><?= $r['service_time'] ? ' &middot; ' . h($r['service_time']) : '' ?>
                            <?php else: ?>Not set<?php endif; ?>
                        </p>
                        <p><strong>Cleaners:</strong> <?= (int)$r['num_cleaners'] ?> | <strong>Hours:</strong> <?= h($r['num_hours']) ?> | <strong>Materials:</strong> <?= $r['has_materials'] ? 'Yes' : 'No' ?></p>
                        <p><strong>Amount (AED):</strong> <?= $r['total_amount_aed'] !== null ? number_format((float)$r['total_amount_aed'], 2) : '—' ?></p>
                        <?php if ($r['admin_notes']): ?><p><strong>Your response:</strong><br><?= nl2br(h($r['admin_notes'])) ?></p><?php endif; ?>
                        <?php if ($r['status'] === 'pending'): ?>
                            <form method="POST" class="mt-3">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <div class="mb-3">
                                    <label class="form-label">Service date</label>
                                    <input type="date" name="service_date" class="form-control" value="<?= $r['service_date'] ? h($r['service_date']) : '' ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Time (optional)</label>
                                    <input type="time" name="service_time" class="form-control" value="<?= h($r['service_time'] ?? '') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Response to tenant (optional)</label>
                                    <textarea name="admin_notes" class="form-control" rows="2"><?= h($r['admin_notes'] ?? '') ?></textarea>
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
