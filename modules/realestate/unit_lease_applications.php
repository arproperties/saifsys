<?php
/**
 * Real Estate — Find Your Home lease applications queue.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/unit_public_listing_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
re_unit_public_ensure_schema($conn);

$statuses = ['new','contacted','documents_requested','applied','converted','rejected','closed'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? 'new');
    $notes = trim((string)($_POST['admin_notes'] ?? ''));
    if ($id > 0 && in_array($status, $statuses, true)) {
        $stmt = $conn->prepare("
            UPDATE re_unit_lease_applications
            SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$status, $notes !== '' ? $notes : null, current_user_id(), $id, $currentCompanyId]);
        $_SESSION['unit_application_flash'] = ['success', 'Lease application updated.'];
    }
    header('Location: unit_lease_applications.php');
    exit;
}

$flash = $_SESSION['unit_application_flash'] ?? null;
unset($_SESSION['unit_application_flash']);

$statusFilter = (string)($_GET['status'] ?? 'all');
$where = ['a.company_id = ?'];
$params = [$currentCompanyId];
if ($statusFilter !== 'all' && in_array($statusFilter, $statuses, true)) {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}

$stmt = $conn->prepare("
    SELECT a.*, u.unit_number, u.listing_title, b.name AS building_name,
           t.first_name AS converted_first_name, t.last_name AS converted_last_name,
           l.lease_number AS converted_lease_number
    FROM re_unit_lease_applications a
    JOIN re_units u ON u.id = a.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_tenants t ON t.id = a.converted_tenant_id
    LEFT JOIN re_leases l ON l.id = a.converted_lease_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY FIELD(a.status, 'new','contacted','documents_requested','applied','converted','rejected','closed'), a.created_at DESC
");
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Lease Applications';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Find Your Home — Lease Applications</div>
    <a href="unit_viewing_requests.php" class="btn btn-outline-secondary"><i class="bi bi-calendar-check"></i> Viewing Requests</a>
</div>
<p class="text-muted">Applications are prospect leads only. They do not automatically create tenants or leases.</p>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash[1]) ?></div><?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-body">
        <?php if (empty($applications)): ?>
            <p class="text-muted mb-0">No lease applications yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Unit</th>
                            <th>Applicant</th>
                            <th>Move-in / Occupants</th>
                            <th>Status / Conversion</th>
                            <th>Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $a): ?>
                            <tr>
                                <td><?= date('M j, Y H:i', strtotime((string)$a['created_at'])) ?></td>
                                <td>
                                    <strong><?= h($a['building_name'] . ' – ' . $a['unit_number']) ?></strong><br>
                                    <small class="text-muted"><?= h($a['listing_title'] ?: 'Unit listing') ?></small><br>
                                    <a href="unit_view.php?id=<?= (int)$a['unit_id'] ?>" class="small">View unit</a>
                                </td>
                                <td>
                                    <?= h($a['full_name']) ?><br>
                                    <small><?= h($a['phone']) ?><?= !empty($a['email']) ? ' · ' . h($a['email']) : '' ?></small>
                                    <?php if (!empty($a['nationality']) || !empty($a['employer'])): ?>
                                        <br><small class="text-muted"><?= h(trim(($a['nationality'] ?? '') . ' ' . ($a['employer'] ? '· ' . $a['employer'] : ''))) ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($a['notes'])): ?><br><small class="text-muted"><?= h($a['notes']) ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <?= !empty($a['move_in_date']) ? h($a['move_in_date']) : 'Flexible' ?><br>
                                    <small class="text-muted"><?= !empty($a['occupants_count']) ? (int)$a['occupants_count'] . ' occupants' : 'Occupants not set' ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?= h(ucwords(str_replace('_', ' ', $a['status']))) ?></span>
                                    <?php if (!empty($a['converted_tenant_id']) || !empty($a['converted_lease_id'])): ?>
                                        <br><small class="text-success">
                                            Converted
                                            <?= !empty($a['converted_first_name']) ? 'to ' . h(trim($a['converted_first_name'] . ' ' . $a['converted_last_name'])) : '' ?>
                                            <?= !empty($a['converted_lease_number']) ? ' / ' . h($a['converted_lease_number']) : '' ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php if (!empty($a['admin_notes'])): ?><br><small class="text-muted"><?= h($a['admin_notes']) ?></small><?php endif; ?>
                                </td>
                                <td style="min-width:280px;">
                                    <form method="POST" class="d-flex gap-2">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                        <select name="status" class="form-select form-select-sm">
                                            <?php foreach ($statuses as $s): ?><option value="<?= h($s) ?>" <?= $a['status'] === $s ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?>
                                        </select>
                                        <input name="admin_notes" class="form-control form-control-sm" placeholder="Notes" value="<?= h($a['admin_notes'] ?? '') ?>">
                                        <button class="btn btn-sm btn-primary">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
