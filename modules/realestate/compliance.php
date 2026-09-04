<?php
/**
 * Real Estate Module - Compliance Tracking Dashboard
 * Track document expiry, missing documents, legal status, Ejari
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_COMPLIANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get expiring documents (next 30 days)
$expiringDocuments = $conn->prepare("
    SELECT 
        d.*,
        dt.document_type_name,
        CASE d.related_type
            WHEN 'lease' THEN (SELECT CONCAT(b.name, ' - ', u.unit_number) FROM re_leases l JOIN re_units u ON u.id = l.unit_id JOIN re_buildings b ON b.id = u.building_id WHERE l.id = d.related_id)
            WHEN 'tenant' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM re_tenants WHERE id = d.related_id)
            WHEN 'unit' THEN (SELECT CONCAT(b.name, ' - ', u.unit_number) FROM re_units u JOIN re_buildings b ON b.id = u.building_id WHERE u.id = d.related_id)
            ELSE 'Other'
        END as related_name,
        DATEDIFF(d.expiry_date, CURDATE()) as days_until_expiry
    FROM re_documents d
    LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
    WHERE d.company_id = ?
    AND d.expiry_date IS NOT NULL
    AND d.expiry_date >= CURDATE()
    AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND d.status = 'active'
    ORDER BY d.expiry_date ASC
");
$expiringDocuments->execute([$currentCompanyId]);
$expiringDocuments = $expiringDocuments->fetchAll(PDO::FETCH_ASSOC);

// Get expired documents
$expiredDocuments = $conn->prepare("
    SELECT 
        d.*,
        dt.document_type_name,
        CASE d.related_type
            WHEN 'lease' THEN (SELECT CONCAT(b.name, ' - ', u.unit_number) FROM re_leases l JOIN re_units u ON u.id = l.unit_id JOIN re_buildings b ON b.id = u.building_id WHERE l.id = d.related_id)
            WHEN 'tenant' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM re_tenants WHERE id = d.related_id)
            WHEN 'unit' THEN (SELECT CONCAT(b.name, ' - ', u.unit_number) FROM re_units u JOIN re_buildings b ON b.id = u.building_id WHERE u.id = d.related_id)
            ELSE 'Other'
        END as related_name,
        DATEDIFF(CURDATE(), d.expiry_date) as days_expired
    FROM re_documents d
    LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
    WHERE d.company_id = ?
    AND d.expiry_date IS NOT NULL
    AND d.expiry_date < CURDATE()
    AND d.status = 'active'
    ORDER BY d.expiry_date DESC
    LIMIT 20
");
$expiredDocuments->execute([$currentCompanyId]);
$expiredDocuments = $expiredDocuments->fetchAll(PDO::FETCH_ASSOC);

// Get missing documents
$missingDocuments = $conn->prepare("
    SELECT 
        md.*,
        dt.document_type_name,
        dt.is_required,
        CASE md.related_type
            WHEN 'lease' THEN (SELECT CONCAT(b.name, ' - ', u.unit_number) FROM re_leases l JOIN re_units u ON u.id = l.unit_id JOIN re_buildings b ON b.id = u.building_id WHERE l.id = md.related_id)
            WHEN 'tenant' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM re_tenants WHERE id = md.related_id)
            WHEN 'unit' THEN (SELECT CONCAT(b.name, ' - ', u.unit_number) FROM re_units u JOIN re_buildings b ON b.id = u.building_id WHERE u.id = md.related_id)
            ELSE 'Other'
        END as related_name
    FROM re_missing_documents md
    JOIN re_document_types dt ON dt.id = md.document_type_id
    WHERE md.company_id = ?
    AND md.status IN ('missing', 'pending_upload')
    ORDER BY dt.is_required DESC, md.created_at DESC
");
$missingDocuments->execute([$currentCompanyId]);
$missingDocuments = $missingDocuments->fetchAll(PDO::FETCH_ASSOC);

// Get unit legal status summary
$unitLegalStatus = $conn->prepare("
    SELECT 
        uls.*,
        u.unit_number,
        b.name as building_name,
        l.lease_number,
        t.first_name,
        t.last_name
    FROM re_unit_legal_status uls
    JOIN re_units u ON u.id = uls.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_leases l ON l.unit_id = u.id AND l.status = 'active'
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE uls.company_id = ?
    ORDER BY 
        CASE uls.legal_status
            WHEN 'non_compliant' THEN 1
            WHEN 'at_risk' THEN 2
            WHEN 'pending_review' THEN 3
            WHEN 'compliant' THEN 4
        END,
        b.name, u.unit_number
");
$unitLegalStatus->execute([$currentCompanyId]);
$unitLegalStatus = $unitLegalStatus->fetchAll(PDO::FETCH_ASSOC);

// Get Ejari tracking
$ejariTracking = $conn->prepare("
    SELECT 
        e.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name,
        DATEDIFF(e.expiry_date, CURDATE()) as days_until_expiry
    FROM re_ejari_tracking e
    JOIN re_leases l ON l.id = e.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE e.company_id = ?
    ORDER BY 
        CASE e.registration_status
            WHEN 'expired' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'registered' THEN 3
            ELSE 4
        END,
        e.expiry_date ASC
");
$ejariTracking->execute([$currentCompanyId]);
$ejariTracking = $ejariTracking->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM re_documents 
         WHERE company_id = ? AND expiry_date IS NOT NULL 
         AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
         AND status = 'active') as expiring_soon,
        (SELECT COUNT(*) FROM re_documents 
         WHERE company_id = ? AND expiry_date IS NOT NULL 
         AND expiry_date < CURDATE() AND status = 'active') as expired,
        (SELECT COUNT(*) FROM re_missing_documents 
         WHERE company_id = ? AND status IN ('missing', 'pending_upload') AND is_required = 1) as missing_required,
        (SELECT COUNT(*) FROM re_unit_legal_status 
         WHERE company_id = ? AND legal_status = 'non_compliant') as non_compliant_units,
        (SELECT COUNT(*) FROM re_ejari_tracking 
         WHERE company_id = ? AND registration_status = 'expired') as expired_ejari
");
$stats->execute([$currentCompanyId, $currentCompanyId, $currentCompanyId, $currentCompanyId, $currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Compliance Tracking';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-shield-check"></i> Compliance Tracking</div>
            <div>
                <a href="compliance_document_types.php" class="btn btn-primary">
                    <i class="bi bi-tags"></i> Document Types
                </a>
                <a href="compliance_ejari.php" class="btn btn-info">
                    <i class="bi bi-file-earmark-text"></i> Ejari Tracking
                </a>
                <a href="compliance_send_alerts.php" class="btn btn-success">
                    <i class="bi bi-envelope"></i> Send Alerts
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Expiring Soon</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['expiring_soon'] ?></h2>
                        <small>Next 30 days</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Expired</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['expired'] ?></h2>
                        <small>Documents</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Missing Required</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['missing_required'] ?></h2>
                        <small>Documents</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Non-Compliant Units</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['non_compliant_units'] ?></h2>
                        <small>Units</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Expiring Documents -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="bi bi-clock"></i> Expiring Soon (Next 30 Days)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($expiringDocuments)): ?>
                            <p class="text-muted">No documents expiring in the next 30 days.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Document</th>
                                            <th>Related To</th>
                                            <th>Expiry Date</th>
                                            <th>Days Left</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($expiringDocuments as $doc): ?>
                                            <tr class="<?= $doc['days_until_expiry'] <= 7 ? 'table-danger' : ($doc['days_until_expiry'] <= 14 ? 'table-warning' : '') ?>">
                                                <td>
                                                    <strong><?= h($doc['document_name'] ?: $doc['document_type_name'] ?: 'Document') ?></strong>
                                                    <?php if ($doc['document_number']): ?>
                                                        <br><small class="text-muted">#<?= h($doc['document_number']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= h($doc['related_name'] ?: '-') ?></td>
                                                <td><?= date('M d, Y', strtotime($doc['expiry_date'])) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $doc['days_until_expiry'] <= 7 ? 'danger' : 
                                                        ($doc['days_until_expiry'] <= 14 ? 'warning' : 'info') 
                                                    ?>">
                                                        <?= $doc['days_until_expiry'] ?> days
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Expired Documents -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-x-circle"></i> Expired Documents</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($expiredDocuments)): ?>
                            <p class="text-muted">No expired documents.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Document</th>
                                            <th>Related To</th>
                                            <th>Expiry Date</th>
                                            <th>Days Expired</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($expiredDocuments as $doc): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= h($doc['document_name'] ?: $doc['document_type_name'] ?: 'Document') ?></strong>
                                                    <?php if ($doc['document_number']): ?>
                                                        <br><small class="text-muted">#<?= h($doc['document_number']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= h($doc['related_name'] ?: '-') ?></td>
                                                <td><?= date('M d, Y', strtotime($doc['expiry_date'])) ?></td>
                                                <td>
                                                    <span class="badge bg-danger"><?= $doc['days_expired'] ?> days</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Missing Documents -->
        <?php if (!empty($missingDocuments)): ?>
        <div class="card mb-4">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Missing Required Documents</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Document Type</th>
                                <th>Related To</th>
                                <th>Status</th>
                                <th>Required</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($missingDocuments as $missing): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($missing['document_type_name']) ?></strong>
                                        <?php if ($missing['is_required']): ?>
                                            <span class="badge bg-danger ms-2">Required</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($missing['related_name'] ?: '-') ?></td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $missing['status'] === 'missing' ? 'danger' : 'warning' 
                                        ?>">
                                            <?= ucfirst(str_replace('_', ' ', $missing['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $missing['is_required'] ? '<span class="text-danger">Yes</span>' : '<span class="text-muted">No</span>' ?>
                                    </td>
                                    <td>
                                        <a href="compliance_upload_document.php?related_type=<?= h($missing['related_type']) ?>&related_id=<?= $missing['related_id'] ?>&document_type_id=<?= $missing['document_type_id'] ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="bi bi-upload"></i> Upload
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Unit Legal Status -->
        <?php if (!empty($unitLegalStatus)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-shield-check"></i> Unit Legal Status</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Unit</th>
                                <th>Lease</th>
                                <th>Tenant</th>
                                <th>Legal Status</th>
                                <th>Compliance Score</th>
                                <th>Last Review</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unitLegalStatus as $status): ?>
                                <tr class="<?= 
                                    $status['legal_status'] === 'non_compliant' ? 'table-danger' : 
                                    ($status['legal_status'] === 'at_risk' ? 'table-warning' : '') 
                                ?>">
                                    <td>
                                        <?= h($status['building_name']) ?> - <?= h($status['unit_number']) ?>
                                    </td>
                                    <td><?= h($status['lease_number'] ?: '-') ?></td>
                                    <td><?= $status['first_name'] ? h($status['first_name'] . ' ' . $status['last_name']) : '-' ?></td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $status['legal_status'] === 'compliant' ? 'success' : 
                                            ($status['legal_status'] === 'non_compliant' ? 'danger' : 
                                            ($status['legal_status'] === 'at_risk' ? 'warning' : 'info')) 
                                        ?>">
                                            <?= ucfirst(str_replace('_', ' ', $status['legal_status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($status['compliance_score'] !== null): ?>
                                            <span class="badge bg-<?= 
                                                $status['compliance_score'] >= 80 ? 'success' : 
                                                ($status['compliance_score'] >= 60 ? 'warning' : 'danger') 
                                            ?>">
                                                <?= $status['compliance_score'] ?>%
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $status['last_review_date'] ? date('M d, Y', strtotime($status['last_review_date'])) : '-' ?>
                                    </td>
                                    <td>
                                        <a href="compliance_unit_status.php?unit_id=<?= $status['unit_id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i> Review
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ejari Tracking -->
        <?php if (!empty($ejariTracking)): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Ejari Tracking</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ejari Number</th>
                                <th>Lease</th>
                                <th>Unit</th>
                                <th>Tenant</th>
                                <th>Registration Date</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ejariTracking as $ejari): ?>
                                <tr class="<?= 
                                    $ejari['registration_status'] === 'expired' ? 'table-danger' : 
                                    ($ejari['registration_status'] === 'pending' ? 'table-warning' : '') 
                                ?>">
                                    <td><strong><?= h($ejari['ejari_number']) ?></strong></td>
                                    <td><?= h($ejari['lease_number']) ?></td>
                                    <td>
                                        <?= h($ejari['building_name']) ?> - <?= h($ejari['unit_number']) ?>
                                    </td>
                                    <td><?= h($ejari['first_name'] . ' ' . $ejari['last_name']) ?></td>
                                    <td><?= $ejari['registration_date'] ? date('M d, Y', strtotime($ejari['registration_date'])) : '-' ?></td>
                                    <td>
                                        <?= $ejari['expiry_date'] ? date('M d, Y', strtotime($ejari['expiry_date'])) : '-' ?>
                                        <?php if ($ejari['expiry_date'] && $ejari['days_until_expiry'] !== null): ?>
                                            <br><small class="text-<?= $ejari['days_until_expiry'] < 0 ? 'danger' : ($ejari['days_until_expiry'] <= 30 ? 'warning' : 'muted') ?>">
                                                <?= $ejari['days_until_expiry'] < 0 ? abs($ejari['days_until_expiry']) . ' days expired' : $ejari['days_until_expiry'] . ' days left' ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $ejari['registration_status'] === 'registered' ? 'success' : 
                                            ($ejari['registration_status'] === 'expired' ? 'danger' : 
                                            ($ejari['registration_status'] === 'pending' ? 'warning' : 'secondary')) 
                                        ?>">
                                            <?= ucfirst($ejari['registration_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="compliance_ejari_view.php?id=<?= $ejari['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
