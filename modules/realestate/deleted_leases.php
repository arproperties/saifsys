<?php
/**
 * Real Estate Module - Deleted/Archived Leases
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id() ?: 0;
$canRestore = has_role('Owner', $conn) || has_role('Admin', $conn);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_lease_id'])) {
    csrf_verify();
    $leaseId = (int)$_POST['restore_lease_id'];
    $confirmText = trim((string)($_POST['confirm_text'] ?? ''));

    if (!$canRestore) {
        $_SESSION['error'] = 'Only Admin or Owner users can restore deleted leases.';
    } elseif ($confirmText !== 'RESTORE') {
        $_SESSION['error'] = 'Type RESTORE to confirm lease restoration.';
    } else {
        try {
            re_lease_restore($conn, $currentCompanyId, $leaseId, $userId);
            $_SESSION['success'] = 'Lease restored successfully.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not restore lease: ' . $e->getMessage();
        }
    }

    header('Location: deleted_leases.php');
    exit;
}

$deletedLeases = [];
$migrationReady = re_lease_column_exists($conn, 'deleted_at');
if ($migrationReady) {
    $stmt = $conn->prepare("
        SELECT l.*,
               u.unit_number, u.unit_type,
               b.name AS building_name,
               t.first_name, t.last_name, t.company_name, t.tenant_type,
               du.username AS deleted_by_name
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        LEFT JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN user du ON du.id = l.deleted_by
        WHERE l.company_id = ?
          AND " . re_lease_deleted_sql($conn, 'l') . "
        ORDER BY l.deleted_at DESC, l.id DESC
    ");
    $stmt->execute([$currentCompanyId]);
    $deletedLeases = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

csrf_token();
$pageTitle = 'Deleted Leases';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="page-header-label">Deleted Leases</div>
        <div class="text-muted small">Archived draft leases are hidden from normal workflows but can be restored here.</div>
    </div>
    <a href="leases.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Leases
    </a>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= h($_SESSION['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= h($_SESSION['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?php if (!$migrationReady): ?>
    <div class="alert alert-warning">
        Run <code>migrations/realestate_lease_lifecycle_safeguards.sql</code> to enable deleted lease recovery.
    </div>
<?php endif; ?>

<div class="card card-round">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-archive"></i> Archived Draft Leases</h6>
        <span class="badge bg-secondary"><?= number_format(count($deletedLeases)) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Lease #</th>
                        <th>Unit</th>
                        <th>Tenant</th>
                        <th>Archived At</th>
                        <th>Archived By</th>
                        <th>Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($deletedLeases)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            No deleted leases found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deletedLeases as $lease): ?>
                        <?php
                        $tenantName = ($lease['tenant_type'] ?? '') === 'company'
                            ? ($lease['company_name'] ?: '-')
                            : trim(($lease['first_name'] ?? '') . ' ' . ($lease['last_name'] ?? ''));
                        $leaseLabel = $lease['lease_number'] ?: 'L-' . $lease['id'];
                        ?>
                        <tr>
                            <td><strong class="text-primary"><?= h($leaseLabel) ?></strong></td>
                            <td>
                                <?= h($lease['building_name']) ?><br>
                                <small class="text-muted"><?= h($lease['unit_number']) ?> (<?= h($lease['unit_type']) ?>)</small>
                            </td>
                            <td><?= h($tenantName ?: '-') ?></td>
                            <td><?= h($lease['deleted_at']) ?></td>
                            <td><?= h($lease['deleted_by_name'] ?: '-') ?></td>
                            <td><?= h($lease['delete_reason'] ?: '-') ?></td>
                            <td>
                                <?php if ($canRestore): ?>
                                    <form method="POST" class="d-inline restore-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="restore_lease_id" value="<?= (int)$lease['id'] ?>">
                                        <input type="hidden" name="confirm_text" value="">
                                        <button type="submit" class="btn btn-sm btn-outline-success" data-lease="<?= h($leaseLabel) ?>">
                                            <i class="bi bi-arrow-counterclockwise"></i> Restore
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small">Admin/Owner only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.restore-form').forEach(function(form) {
    form.addEventListener('submit', function(event) {
        const leaseLabel = form.querySelector('button[data-lease]').dataset.lease || 'this lease';
        const confirmation = prompt('Restore archived lease "' + leaseLabel + '"?\\n\\nType RESTORE to continue.');
        if (confirmation !== 'RESTORE') {
            event.preventDefault();
            return;
        }
        form.querySelector('input[name="confirm_text"]').value = confirmation;
    });
});
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
