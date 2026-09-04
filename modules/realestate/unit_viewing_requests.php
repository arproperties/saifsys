<?php
/**
 * Real Estate — Find Your Home viewing requests queue.
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

$statuses = ['new','contacted','viewing_scheduled','completed','cancelled','closed'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? 'new');
    $notes = trim((string)($_POST['admin_notes'] ?? ''));
    if ($id > 0 && in_array($status, $statuses, true)) {
        $stmt = $conn->prepare("
            UPDATE re_unit_viewing_requests
            SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$status, $notes !== '' ? $notes : null, current_user_id(), $id, $currentCompanyId]);
        $_SESSION['unit_viewing_flash'] = ['success', 'Viewing request updated.'];
    }
    header('Location: unit_viewing_requests.php');
    exit;
}

$flash = $_SESSION['unit_viewing_flash'] ?? null;
unset($_SESSION['unit_viewing_flash']);

$statusFilter = (string)($_GET['status'] ?? 'all');
$where = ['r.company_id = ?'];
$params = [$currentCompanyId];
if ($statusFilter !== 'all' && in_array($statusFilter, $statuses, true)) {
    $where[] = 'r.status = ?';
    $params[] = $statusFilter;
}

$stmt = $conn->prepare("
    SELECT r.*, u.unit_number, u.listing_title, b.name AS building_name
    FROM re_unit_viewing_requests r
    JOIN re_units u ON u.id = r.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY FIELD(r.status, 'new','contacted','viewing_scheduled','completed','cancelled','closed'), r.created_at DESC
");
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Viewing Requests';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Find Your Home — Viewing Requests</div>
    <a href="units.php?mobile_listing=listed" class="btn btn-outline-primary"><i class="bi bi-phone"></i> Mobile Listings</a>
</div>

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
        <?php if (empty($requests)): ?>
            <p class="text-muted mb-0">No viewing requests yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Unit</th>
                            <th>Prospect</th>
                            <th>Preferred Slot</th>
                            <th>Status / Notes</th>
                            <th>Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td><?= date('M j, Y H:i', strtotime((string)$r['created_at'])) ?></td>
                                <td>
                                    <strong><?= h($r['building_name'] . ' – ' . $r['unit_number']) ?></strong><br>
                                    <small class="text-muted"><?= h($r['listing_title'] ?: 'Unit listing') ?></small><br>
                                    <a href="unit_view.php?id=<?= (int)$r['unit_id'] ?>" class="small">View unit</a>
                                </td>
                                <td>
                                    <?= h($r['full_name']) ?><br>
                                    <small><?= h($r['phone']) ?><?= !empty($r['email']) ? ' · ' . h($r['email']) : '' ?></small>
                                    <?php if (!empty($r['message'])): ?><br><small class="text-muted"><?= h($r['message']) ?></small><?php endif; ?>
                                </td>
                                <td><?= !empty($r['preferred_date']) ? h($r['preferred_date']) : 'Flexible' ?><?= !empty($r['preferred_time']) ? ' ' . h(substr((string)$r['preferred_time'], 0, 5)) : '' ?></td>
                                <td>
                                    <span class="badge bg-info"><?= h(ucwords(str_replace('_', ' ', $r['status']))) ?></span>
                                    <?php if (!empty($r['admin_notes'])): ?><br><small class="text-muted"><?= h($r['admin_notes']) ?></small><?php endif; ?>
                                </td>
                                <td style="min-width:260px;">
                                    <form method="POST" class="d-flex gap-2">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <select name="status" class="form-select form-select-sm">
                                            <?php foreach ($statuses as $s): ?><option value="<?= h($s) ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?>
                                        </select>
                                        <input name="admin_notes" class="form-control form-control-sm" placeholder="Notes" value="<?= h($r['admin_notes'] ?? '') ?>">
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
