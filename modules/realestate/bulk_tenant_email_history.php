<?php
/**
 * Bulk Tenant Email — campaign history.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/bulk_tenant_email_helper.php';

$companyId = re_bulk_email_require_access($conn);
$brand = getBrandSettings($conn);
$reLayoutFluid = true;

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$rows = [];
if (re_bulk_email_tables_ready($conn)) {
    $st = $conn->prepare("
        SELECT c.*, COALESCE(NULLIF(u.fullname, ''), u.username) AS sender_name
        FROM re_bulk_email_campaigns c
        LEFT JOIN user u ON u.id = c.created_by
        WHERE c.company_id = ?
        ORDER BY c.created_at DESC, c.id DESC
        LIMIT 200
    ");
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Bulk Email History';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>Bulk Email History</h4>
    <a href="bulk_tenant_email.php" class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> New campaign</a>
</div>

<div class="card card-round">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Subject</th>
                    <th>Sender</th>
                    <th class="text-end">Unique</th>
                    <th class="text-end">Sent</th>
                    <th class="text-end">Failed</th>
                    <th class="text-end">Pending</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No campaigns yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= h($r['created_at']) ?></td>
                    <td><?= h($r['subject']) ?></td>
                    <td><?= h($r['sender_name'] ?: '—') ?></td>
                    <td class="text-end"><?= (int)$r['unique_email_count'] ?></td>
                    <td class="text-end"><?= (int)$r['sent_count'] ?></td>
                    <td class="text-end"><?= (int)$r['failed_count'] ?></td>
                    <td class="text-end"><?= (int)$r['pending_count'] ?></td>
                    <td><span class="badge bg-<?= in_array($r['status'], ['completed'], true) ? 'success' : (in_array($r['status'], ['completed_with_errors','failed'], true) ? 'warning text-dark' : 'secondary') ?>"><?= h($r['status']) ?></span></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="bulk_tenant_email_view.php?id=<?= (int)$r['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
