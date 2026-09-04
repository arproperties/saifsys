<?php
/**
 * Tenant Portal — Documents (view/download linked to lease or unit + tenant signed contract scans)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';
require_once __DIR__ . '/includes/tenancy_contract_scan_helper.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

$lease_id = (int)$lease['lease_id'];
$unit_id = (int)$lease['unit_id'];
$company_id = (int)$lease['company_id'];

$docs = [];
try {
    $q = $conn->prepare("
        SELECT id, document_name, document_number, document_type, file_name, file_path, issue_date, expiry_date, status, created_at, 'library' AS _source
        FROM re_documents
        WHERE company_id = ? AND (
            (related_type = 'lease' AND related_id = ?) OR
            (related_type = 'unit' AND related_id = ?)
        )
    ");
    $q->execute([$company_id, $lease_id, $unit_id]);
    $docs = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $docs = [];
}

$tenantScans = [];
if (tenancy_contract_scan_table_exists($conn)) {
    try {
        $ts = $conn->prepare("
            SELECT id, original_filename AS file_name, stored_path AS file_path, uploaded_at AS created_at,
                   'tenant_signed_scan' AS document_type, 'active' AS status,
                   'Your signed tenancy contract (scan)' AS document_name,
                   NULL AS document_number, NULL AS issue_date, NULL AS expiry_date, 'tenant_scan' AS _source
            FROM re_tenancy_contract_tenant_uploads
            WHERE company_id = ? AND lease_id = ? AND superseded_at IS NULL
            ORDER BY uploaded_at DESC
        ");
        $ts->execute([$company_id, $lease_id]);
        $tenantScans = $ts->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $tenantScans = [];
    }
}

$allRows = array_merge($docs, $tenantScans);
usort($allRows, static function ($a, $b) {
    $ta = strtotime((string)($a['created_at'] ?? '1970-01-01')) ?: 0;
    $tb = strtotime((string)($b['created_at'] ?? '1970-01-01')) ?: 0;
    return $tb <=> $ta;
});

$pageTitle = 'Documents';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Documents</h4>
<p class="text-muted small mb-3">
    Files uploaded by management appear here. Your <strong>signed contract scan</strong> (from the <a href="lease.php">Lease</a> page) is listed as &ldquo;Tenant signed scan&rdquo; until the final fully executed contract is uploaded by management.
</p>

<?php if (empty($allRows)): ?>
    <p class="portal-empty">No documents available for your lease yet.</p>
<?php else: ?>
<div class="portal-table-wrap">
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Document</th>
                <th>Type</th>
                <th>Issue date</th>
                <th>Expiry</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allRows as $row): ?>
                <?php
                $isScan = (($row['_source'] ?? '') === 'tenant_scan');
                $typeLabel = $isScan
                    ? 'Tenant signed scan'
                    : htmlspecialchars(str_replace('_', ' ', (string)$row['document_type']));
                $dl = $isScan
                    ? 'download_tenant_contract_scan.php?id=' . (int)$row['id']
                    : 'download_document.php?id=' . (int)$row['id'];
                ?>
                <tr>
                    <td data-label="Document"><?= htmlspecialchars($row['document_name'] ?: $row['file_name']) ?></td>
                    <td data-label="Type"><?= $isScan ? htmlspecialchars($typeLabel) : $typeLabel ?></td>
                    <td data-label="Issue date"><?= !empty($row['issue_date']) ? date('M j, Y', strtotime($row['issue_date'])) : '—' ?></td>
                    <td data-label="Expiry"><?= !empty($row['expiry_date']) ? date('M j, Y', strtotime($row['expiry_date'])) : '—' ?></td>
                    <td data-label="Status"><span class="badge bg-<?= ($row['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars((string)($row['status'] ?? '')) ?></span></td>
                    <td data-label="Action">
                        <?php if (!empty($row['file_path'])): ?>
                            <a href="<?= htmlspecialchars($dl) ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener">Download</a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
