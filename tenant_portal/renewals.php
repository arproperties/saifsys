<?php
/**
 * Tenant Portal — Renewal notices & contract signing (inbox).
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';
require_once __DIR__ . '/includes/renewal_portal_helper.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

$leaseId = (int)$lease['lease_id'];
$companyId = (int)$lease['company_id'];

$renewals = [];
$inboxError = '';
try {
    $q = $conn->prepare("
        SELECT rw.id, rw.status, rw.initiated_date, rw.notice_sent_at, rw.proposed_rent, rw.proposed_start_date, rw.proposed_end_date,
               rw.renewal_notice_pdf_path, rw.new_lease_id,
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
        ORDER BY rw.id DESC
    ");
    $q->execute([$companyId, $leaseId, $leaseId]);
    $renewals = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $inboxError = 'Renewal inbox is not available yet. Ask your administrator to run the latest database migration (tenant_portal_renewal_phase1.sql).';
}

$noticeRows = [];
$pendingRows = [];
$signRows = [];
$completedRows = [];
foreach ($renewals as $r) {
    $st = (string)($r['status'] ?? '');
    $sig = (int)($r['signature_count'] ?? 0);
    if ($st === 'contract_ready' && $sig === 0) {
        $signRows[] = $r;
    }
    if (!in_array($st, ['converted', 'rejected', 'signed', 'completed'], true)) {
        $pendingRows[] = $r;
    }
    if (in_array($st, ['converted', 'rejected', 'signed', 'completed'], true)) {
        $completedRows[] = $r;
    }
    $noticeRows[] = $r;
}

$pageTitle = 'Lease renewals';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title"><i class="bi bi-arrow-repeat me-2"></i>Lease renewals</h4>

<?php if ($inboxError): ?>
    <div class="alert alert-warning"><?= h($inboxError) ?></div>
<?php elseif (empty($renewals)): ?>
    <p class="portal-empty">You have no renewal notices yet. When management sends a renewal notice, it will appear here.</p>
<?php else: ?>

    <?php if (!empty($signRows)): ?>
        <div class="alert alert-primary mb-4">
            <strong>Action needed:</strong> You have <?= count($signRows) ?> renewal contract(s) ready for your electronic signature.
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-all" type="button">All notices</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pending" type="button">Pending</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sign" type="button">Awaiting signature</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-completed" type="button">Completed</button>
        </li>
    </ul>
    <div class="tab-content">
        <?php
        $renderTable = function (array $rows) {
            if (empty($rows)) {
                echo '<p class="text-muted">Nothing in this list.</p>';
                return;
            }
            ?>
            <div class="portal-table-wrap">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Started</th>
                            <th>Status</th>
                            <th>Proposed rent (AED)</th>
                            <th>New period</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td data-label="Started"><?= !empty($r['initiated_date']) ? h(date('M j, Y', strtotime($r['initiated_date']))) : '—' ?></td>
                                <td data-label="Status"><span class="badge bg-secondary"><?= h(tenant_renewal_status_label((string)$r['status'])) ?></span></td>
                                <td data-label="Rent"><?= isset($r['proposed_rent']) ? number_format((float)$r['proposed_rent'], 2) : '—' ?></td>
                                <td data-label="Period">
                                    <?php if (!empty($r['proposed_start_date']) && !empty($r['proposed_end_date'])): ?>
                                        <?= h(date('M j, Y', strtotime($r['proposed_start_date']))) ?> — <?= h(date('M j, Y', strtotime($r['proposed_end_date']))) ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td data-label="">
                                    <a class="btn btn-sm btn-primary" href="renewal_detail.php?id=<?= (int)$r['id'] ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        };
        ?>
        <div class="tab-pane fade show active" id="tab-all" role="tabpanel"><?php $renderTable($noticeRows); ?></div>
        <div class="tab-pane fade" id="tab-pending" role="tabpanel"><?php $renderTable($pendingRows); ?></div>
        <div class="tab-pane fade" id="tab-sign" role="tabpanel"><?php $renderTable($signRows); ?></div>
        <div class="tab-pane fade" id="tab-completed" role="tabpanel"><?php $renderTable($completedRows); ?></div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
