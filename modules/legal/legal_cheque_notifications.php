<?php
/**
 * Legal Module — Cheque Notifications inbox.
 *
 * Shows bounced cheques and "escalate to legal" requests raised by accounting
 * from the Lease View page, with acknowledge/resolve workflow and deep links.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/../realestate/includes/cheque_legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$re = legal_re_path();
$hasTable = cheque_legal_escalations_table_exists($conn);

// ---- Workflow actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['escalation_action']) && $hasTable) {
    csrf_verify();
    $eid = (int)($_POST['escalation_id'] ?? 0);
    $act = (string)$_POST['escalation_action'];
    if ($eid > 0) {
        try {
            if ($act === 'acknowledge') {
                $conn->prepare("UPDATE re_legal_cheque_escalations SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ? AND company_id = ?")
                    ->execute([current_user_id(), $eid, $currentCompanyId]);
                $_SESSION['success'] = 'Marked as acknowledged.';
            } elseif ($act === 'resolve') {
                $conn->prepare("UPDATE re_legal_cheque_escalations SET status = 'resolved', resolved_by = ?, resolved_at = NOW() WHERE id = ? AND company_id = ?")
                    ->execute([current_user_id(), $eid, $currentCompanyId]);
                $_SESSION['success'] = 'Marked as resolved.';
            } elseif ($act === 'reopen') {
                $conn->prepare("UPDATE re_legal_cheque_escalations SET status = 'new', resolved_by = NULL, resolved_at = NULL WHERE id = ? AND company_id = ?")
                    ->execute([$eid, $currentCompanyId]);
                $_SESSION['success'] = 'Re-opened.';
            }
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not update: ' . $e->getMessage();
        }
    }
    header('Location: legal_cheque_notifications.php');
    exit;
}

$filter = $_GET['status'] ?? 'open'; // open | all

$tenantName = function (array $r): string {
    if (($r['tenant_type'] ?? '') === 'company' && !empty($r['company_name'])) {
        return $r['company_name'];
    }
    $n = trim(($r['tenant_name'] ?? ''));
    return $n !== '' ? $n : '—';
};
$unitLabel = function (array $r): string {
    return trim((string)($r['building_name'] ?? '') . ' ' . (string)($r['unit_number'] ?? '')) ?: '—';
};

// ---- Escalation / hold / bounce requests from accounting ----
$escalations = [];
$counts = ['new' => 0, 'acknowledged' => 0, 'resolved' => 0];
if ($hasTable) {
    $where = "e.company_id = ?";
    if ($filter === 'open') {
        $where .= " AND e.status <> 'resolved'";
    }
    $stmt = $conn->prepare("
        SELECT e.*, l.lease_number,
               TRIM(CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,''))) AS tenant_name,
               t.company_name, t.tenant_type,
               u.unit_number, b.name AS building_name
        FROM re_legal_cheque_escalations e
        LEFT JOIN re_leases l ON l.id = e.lease_id
        LEFT JOIN re_tenants t ON t.id = e.tenant_id
        LEFT JOIN re_units u ON u.id = l.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE {$where}
        ORDER BY (e.status = 'new') DESC, e.created_at DESC
    ");
    $stmt->execute([$currentCompanyId]);
    $escalations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        $cs = $conn->prepare("SELECT status, COUNT(*) c FROM re_legal_cheque_escalations WHERE company_id = ? GROUP BY status");
        $cs->execute([$currentCompanyId]);
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']] = (int)$row['c'];
        }
    } catch (Throwable $e) { /* ignore */ }
}

// ---- All bounced cheques (live, regardless of source) ----
$bounced = [];
try {
    $stmt = $conn->prepare("
        SELECT c.id, c.cheque_number, c.cheque_amount, c.cheque_date, c.bounced_date, c.bounced_reason,
               c.lease_id, l.lease_number,
               TRIM(CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,''))) AS tenant_name,
               t.id AS tenant_id, t.company_name, t.tenant_type,
               u.unit_number, b.name AS building_name
        FROM re_post_dated_cheques c
        JOIN re_leases l ON l.id = c.lease_id
        LEFT JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN re_units u ON u.id = l.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE c.company_id = ? AND c.status = 'bounced'
        ORDER BY c.bounced_date DESC, c.id DESC
    ");
    $stmt->execute([$currentCompanyId]);
    $bounced = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }

$typeBadge = ['bounced' => 'danger', 'escalated' => 'primary', 'hold' => 'dark'];
$statusBadge = ['new' => 'warning text-dark', 'acknowledged' => 'info', 'resolved' => 'success'];

$pageTitle = 'Cheque Notifications';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1 class="h3 mb-0"><i class="bi bi-bell"></i> Cheque Notifications</h1>
            <div class="btn-group">
                <a href="?status=open" class="btn btn-outline-secondary <?= $filter === 'open' ? 'active' : '' ?>">Open</a>
                <a href="?status=all" class="btn btn-outline-secondary <?= $filter === 'all' ? 'active' : '' ?>">All</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= h($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= h($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <?php if (!$hasTable): ?>
            <div class="alert alert-warning">
                The legal notification inbox table is not installed yet. Run
                <code>migrations/add_cheque_hold_and_legal_escalations.sql</code> to enable escalation tracking.
                Bounced cheques are still listed below.
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6"><div class="card text-center border-warning h-100"><div class="card-body">
                <i class="bi bi-inbox fs-3 text-warning"></i>
                <h2 class="mb-0"><?= (int)$counts['new'] ?></h2><small class="text-muted">New Requests</small>
            </div></div></div>
            <div class="col-md-3 col-6"><div class="card text-center border-info h-100"><div class="card-body">
                <i class="bi bi-eye fs-3 text-info"></i>
                <h2 class="mb-0"><?= (int)$counts['acknowledged'] ?></h2><small class="text-muted">Acknowledged</small>
            </div></div></div>
            <div class="col-md-3 col-6"><div class="card text-center border-success h-100"><div class="card-body">
                <i class="bi bi-check2-circle fs-3 text-success"></i>
                <h2 class="mb-0"><?= (int)$counts['resolved'] ?></h2><small class="text-muted">Resolved</small>
            </div></div></div>
            <div class="col-md-3 col-6"><div class="card text-center border-danger h-100"><div class="card-body">
                <i class="bi bi-x-octagon fs-3 text-danger"></i>
                <h2 class="mb-0"><?= count($bounced) ?></h2><small class="text-muted">Bounced Cheques</small>
            </div></div></div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-flag"></i> Escalation &amp; Bounce Requests</h5></div>
            <div class="card-body">
                <?php if (empty($escalations)): ?>
                    <p class="text-muted mb-0">No <?= $filter === 'open' ? 'open ' : '' ?>requests from accounting.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr>
                            <th>Raised</th><th>Type</th><th>Lease</th><th>Tenant</th><th>Unit</th>
                            <th>Cheque</th><th class="text-end">Amount</th><th>Reason</th><th>Status</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($escalations as $e): ?>
                            <tr>
                                <td><small><?= h(date('M d, Y H:i', strtotime($e['created_at']))) ?></small></td>
                                <td><span class="badge bg-<?= $typeBadge[$e['type']] ?? 'secondary' ?>"><?= h(ucfirst($e['type'])) ?></span></td>
                                <td><a href="<?= h($re) ?>lease_view.php?id=<?= (int)$e['lease_id'] ?>#installments"><?= h($e['lease_number'] ?: ('#' . $e['lease_id'])) ?></a></td>
                                <td><?= h($tenantName($e)) ?></td>
                                <td><?= h($unitLabel($e)) ?></td>
                                <td>
                                    <?php if (!empty($e['cheque_id'])): ?>
                                        <a href="<?= h($re) ?>billing_cheque_view.php?id=<?= (int)$e['cheque_id'] ?>"><?= h($e['cheque_number'] ?: ('#' . $e['cheque_id'])) ?></a>
                                    <?php else: ?><?= h($e['cheque_number'] ?: '—') ?><?php endif; ?>
                                    <?php if (!empty($e['cheque_date'])): ?><br><small class="text-muted"><?= h($e['cheque_date']) ?></small><?php endif; ?>
                                </td>
                                <td class="text-end"><?= $e['cheque_amount'] !== null ? number_format((float)$e['cheque_amount'], 2) : '—' ?></td>
                                <td><small><?= h($e['reason'] ?: '—') ?></small></td>
                                <td><span class="badge bg-<?= $statusBadge[$e['status']] ?? 'secondary' ?>"><?= h(ucfirst($e['status'])) ?></span></td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <a class="btn btn-sm btn-outline-primary" href="legal_case_add.php?case_source=bounced_cheque&primary_cheque_id=<?= (int)$e['cheque_id'] ?>&lease_id=<?= (int)$e['lease_id'] ?>&tenant_id=<?= (int)$e['tenant_id'] ?>" title="Open a legal case from this">
                                            <i class="bi bi-briefcase"></i> Case
                                        </a>
                                        <?php if ($e['status'] !== 'resolved'): ?>
                                            <?php if ($e['status'] === 'new'): ?>
                                            <form method="post" class="m-0"><?php csrf_field(); ?>
                                                <input type="hidden" name="escalation_id" value="<?= (int)$e['id'] ?>">
                                                <input type="hidden" name="escalation_action" value="acknowledge">
                                                <button class="btn btn-sm btn-outline-info" title="Acknowledge"><i class="bi bi-eye"></i></button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="post" class="m-0"><?php csrf_field(); ?>
                                                <input type="hidden" name="escalation_id" value="<?= (int)$e['id'] ?>">
                                                <input type="hidden" name="escalation_action" value="resolve">
                                                <button class="btn btn-sm btn-outline-success" title="Resolve"><i class="bi bi-check2"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" class="m-0"><?php csrf_field(); ?>
                                                <input type="hidden" name="escalation_id" value="<?= (int)$e['id'] ?>">
                                                <input type="hidden" name="escalation_action" value="reopen">
                                                <button class="btn btn-sm btn-outline-secondary" title="Re-open"><i class="bi bi-arrow-counterclockwise"></i></button>
                                            </form>
                                        <?php endif; ?>
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

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-x-octagon"></i> All Bounced Cheques</h5></div>
            <div class="card-body">
                <?php if (empty($bounced)): ?>
                    <p class="text-muted mb-0">No bounced cheques.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr>
                            <th>Bounced</th><th>Lease</th><th>Tenant</th><th>Unit</th>
                            <th>Cheque</th><th class="text-end">Amount</th><th>Reason</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($bounced as $c): ?>
                            <tr>
                                <td><small><?= h($c['bounced_date'] ?: '—') ?></small></td>
                                <td><a href="<?= h($re) ?>lease_view.php?id=<?= (int)$c['lease_id'] ?>#installments"><?= h($c['lease_number'] ?: ('#' . $c['lease_id'])) ?></a></td>
                                <td><?= h($tenantName($c)) ?></td>
                                <td><?= h($unitLabel($c)) ?></td>
                                <td><a href="<?= h($re) ?>billing_cheque_view.php?id=<?= (int)$c['id'] ?>"><?= h($c['cheque_number'] ?: ('#' . $c['id'])) ?></a>
                                    <?php if (!empty($c['cheque_date'])): ?><br><small class="text-muted"><?= h($c['cheque_date']) ?></small><?php endif; ?></td>
                                <td class="text-end"><?= number_format((float)$c['cheque_amount'], 2) ?></td>
                                <td><small><?= h($c['bounced_reason'] ?: '—') ?></small></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary" href="legal_case_add.php?case_source=bounced_cheque&primary_cheque_id=<?= (int)$c['id'] ?>&lease_id=<?= (int)$c['lease_id'] ?>&tenant_id=<?= (int)$c['tenant_id'] ?>">
                                        <i class="bi bi-briefcase"></i> Case
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/legal_layout_footer.php'; ?>
