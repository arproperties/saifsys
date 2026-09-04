<?php
/**
 * Real Estate Module - Unit Legal Status Management
 * Review and manage legal compliance status for units
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

$unitId = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : null;

if (!$unitId) {
    header('Location: compliance.php');
    exit;
}

// Get unit details
$unit = $conn->prepare("
    SELECT 
        u.*,
        b.name as building_name,
        b.address as building_address,
        l.id as lease_id,
        l.lease_number,
        l.status as lease_status,
        t.first_name,
        t.last_name,
        t.email as tenant_email,
        t.phone as tenant_phone
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_leases l ON l.unit_id = u.id AND l.status = 'active'
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE u.id = ? AND u.company_id = ?
");
$unit->execute([$unitId, $currentCompanyId]);
$unit = $unit->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    header('Location: compliance.php');
    exit;
}

// Get or create legal status
$legalStatus = $conn->prepare("
    SELECT * FROM re_unit_legal_status
    WHERE unit_id = ? AND company_id = ?
");
$legalStatus->execute([$unitId, $currentCompanyId]);
$legalStatus = $legalStatus->fetch(PDO::FETCH_ASSOC);

if (!$legalStatus) {
    // Create default status
    $conn->prepare("
        INSERT INTO re_unit_legal_status
        (company_id, unit_id, legal_status, compliance_score)
        VALUES (?, ?, 'pending_review', NULL)
    ")->execute([$currentCompanyId, $unitId]);
    
    $legalStatus = $conn->prepare("
        SELECT * FROM re_unit_legal_status
        WHERE unit_id = ? AND company_id = ?
    ");
    $legalStatus->execute([$unitId, $currentCompanyId]);
    $legalStatus = $legalStatus->fetch(PDO::FETCH_ASSOC);
}

// Get related documents
$documents = $conn->prepare("
    SELECT 
        d.*,
        dt.document_type_name,
        dt.is_required,
        DATEDIFF(d.expiry_date, CURDATE()) as days_until_expiry
    FROM re_documents d
    LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
    WHERE d.company_id = ?
    AND (
        (d.related_type = 'lease' AND d.related_id = ?)
        OR (d.related_type = 'tenant' AND d.related_id = ?)
        OR (d.related_type = 'unit' AND d.related_id = ?)
    )
    AND d.status = 'active'
    ORDER BY dt.is_required DESC, d.expiry_date ASC
");
$documents->execute([$currentCompanyId, $unit['lease_id'], $unit['lease_id'] ? $unit['lease_id'] : 0, $unitId]);
$documents = $documents->fetchAll(PDO::FETCH_ASSOC);

// Get missing documents
$missingDocs = $conn->prepare("
    SELECT 
        md.*,
        dt.document_type_name,
        dt.is_required
    FROM re_missing_documents md
    JOIN re_document_types dt ON dt.id = md.document_type_id
    WHERE md.company_id = ?
    AND md.related_type = 'unit'
    AND md.related_id = ?
    AND md.status IN ('missing', 'pending_upload')
    ORDER BY dt.is_required DESC
");
$missingDocs->execute([$currentCompanyId, $unitId]);
$missingDocs = $missingDocs->fetchAll(PDO::FETCH_ASSOC);

// Get Ejari record if lease exists
$ejari = null;
if ($unit['lease_id']) {
    $ejari = $conn->prepare("
        SELECT * FROM re_ejari_tracking
        WHERE lease_id = ? AND company_id = ?
    ");
    $ejari->execute([$unit['lease_id'], $currentCompanyId]);
    $ejari = $ejari->fetch(PDO::FETCH_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_status') {
        $legalStatusValue = $_POST['legal_status'] ?? 'pending_review';
        $complianceScore = !empty($_POST['compliance_score']) ? (int)$_POST['compliance_score'] : null;
        $reviewNotes = $_POST['review_notes'] ?? '';
        $nextReviewDate = $_POST['next_review_date'] ?? null;
        
        // Calculate compliance score if not provided
        if ($complianceScore === null) {
            $totalDocs = count($documents);
            $requiredDocs = count(array_filter($documents, function($d) { return $d['is_required'] ?? false; }));
            $expiredDocs = count(array_filter($documents, function($d) { 
                return $d['expiry_date'] && strtotime($d['expiry_date']) < time(); 
            }));
            $missingRequired = count(array_filter($missingDocs, function($d) { return $d['is_required'] ?? false; }));
            
            if ($totalDocs > 0) {
                $complianceScore = max(0, min(100, 
                    100 - ($expiredDocs * 20) - ($missingRequired * 30)
                ));
            } else {
                $complianceScore = 50; // Default if no documents
            }
        }
        
        $stmt = $conn->prepare("
            UPDATE re_unit_legal_status
            SET legal_status = ?,
                compliance_score = ?,
                last_review_date = CURDATE(),
                next_review_date = ?,
                review_notes = ?,
                reviewed_by = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([
            $legalStatusValue, $complianceScore, $nextReviewDate,
            $reviewNotes, $currentUserId, $legalStatus['id'], $currentCompanyId
        ]);
        
        $_SESSION['success'] = 'Legal status updated successfully.';
        header('Location: compliance_unit_status.php?unit_id=' . $unitId);
        exit;
    }
}

// Calculate current compliance metrics
$totalDocuments = count($documents);
$requiredDocuments = count(array_filter($documents, function($d) { return $d['is_required'] ?? false; }));
$expiredDocuments = count(array_filter($documents, function($d) { 
    return $d['expiry_date'] && strtotime($d['expiry_date']) < time(); 
}));
$expiringSoon = count(array_filter($documents, function($d) { 
    return $d['expiry_date'] && 
           strtotime($d['expiry_date']) > time() && 
           strtotime($d['expiry_date']) <= strtotime('+30 days'); 
}));
$missingRequired = count(array_filter($missingDocs, function($d) { return $d['is_required'] ?? false; }));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Unit Legal Status';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-shield-check"></i> Legal Status: <?= h($unit['building_name']) ?> - <?= h($unit['unit_number']) ?></h1>
            <a href="compliance.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= h($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="row">
            <!-- Main Content -->
            <div class="col-md-8">
                <!-- Compliance Metrics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="text-muted">Compliance Score</h5>
                                <h2 class="mb-0 text-<?= 
                                    ($legalStatus['compliance_score'] ?? 0) >= 80 ? 'success' : 
                                    (($legalStatus['compliance_score'] ?? 0) >= 60 ? 'warning' : 'danger') 
                                ?>">
                                    <?= $legalStatus['compliance_score'] ?? '-' ?>%
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center border-<?= $expiredDocuments > 0 ? 'danger' : 'success' ?>">
                            <div class="card-body">
                                <h5 class="text-muted">Expired Docs</h5>
                                <h2 class="mb-0"><?= $expiredDocuments ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center border-<?= $expiringSoon > 0 ? 'warning' : 'success' ?>">
                            <div class="card-body">
                                <h5 class="text-muted">Expiring Soon</h5>
                                <h2 class="mb-0"><?= $expiringSoon ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center border-<?= $missingRequired > 0 ? 'danger' : 'success' ?>">
                            <div class="card-body">
                                <h5 class="text-muted">Missing Required</h5>
                                <h2 class="mb-0"><?= $missingRequired ?></h2>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Legal Status -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-pencil"></i> Update Legal Status</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_status">
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Legal Status *</label>
                                    <select name="legal_status" class="form-select" required>
                                        <option value="compliant" <?= $legalStatus['legal_status'] === 'compliant' ? 'selected' : '' ?>>Compliant</option>
                                        <option value="non_compliant" <?= $legalStatus['legal_status'] === 'non_compliant' ? 'selected' : '' ?>>Non-Compliant</option>
                                        <option value="pending_review" <?= $legalStatus['legal_status'] === 'pending_review' ? 'selected' : '' ?>>Pending Review</option>
                                        <option value="at_risk" <?= $legalStatus['legal_status'] === 'at_risk' ? 'selected' : '' ?>>At Risk</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Compliance Score (0-100)</label>
                                    <input type="number" name="compliance_score" class="form-control" 
                                           value="<?= $legalStatus['compliance_score'] ?? '' ?>" min="0" max="100">
                                    <small class="form-text text-muted">Leave empty to auto-calculate</small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Next Review Date</label>
                                <input type="date" name="next_review_date" class="form-control" 
                                       value="<?= $legalStatus['next_review_date'] ? date('Y-m-d', strtotime($legalStatus['next_review_date'])) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Review Notes</label>
                                <textarea name="review_notes" class="form-control" rows="4"><?= h($legalStatus['review_notes'] ?? '') ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Update Status
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Documents -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-earmark"></i> Related Documents (<?= count($documents) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($documents)): ?>
                            <p class="text-muted">No documents found for this unit.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Document</th>
                                            <th>Type</th>
                                            <th>Expiry Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($documents as $doc): ?>
                                            <tr class="<?= 
                                                $doc['expiry_date'] && strtotime($doc['expiry_date']) < time() ? 'table-danger' : 
                                                ($doc['days_until_expiry'] !== null && $doc['days_until_expiry'] <= 30 ? 'table-warning' : '') 
                                            ?>">
                                                <td>
                                                    <strong><?= h($doc['document_name'] ?: $doc['file_name']) ?></strong>
                                                    <?php if ($doc['is_required']): ?>
                                                        <span class="badge bg-danger ms-2">Required</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= h($doc['document_type_name'] ?: '-') ?></td>
                                                <td>
                                                    <?= $doc['expiry_date'] ? date('M d, Y', strtotime($doc['expiry_date'])) : 'No expiry' ?>
                                                    <?php if ($doc['days_until_expiry'] !== null): ?>
                                                        <br><small class="text-<?= 
                                                            $doc['days_until_expiry'] < 0 ? 'danger' : 
                                                            ($doc['days_until_expiry'] <= 30 ? 'warning' : 'muted') 
                                                        ?>">
                                                            <?= $doc['days_until_expiry'] < 0 ? abs($doc['days_until_expiry']) . ' days expired' : $doc['days_until_expiry'] . ' days left' ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($doc['expiry_date'] && strtotime($doc['expiry_date']) < time()): ?>
                                                        <span class="badge bg-danger">Expired</span>
                                                    <?php elseif ($doc['days_until_expiry'] !== null && $doc['days_until_expiry'] <= 30): ?>
                                                        <span class="badge bg-warning">Expiring Soon</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Valid</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Missing Documents -->
                <?php if (!empty($missingDocs)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Missing Documents (<?= count($missingDocs) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Document Type</th>
                                        <th>Required</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($missingDocs as $missing): ?>
                                        <tr>
                                            <td><strong><?= h($missing['document_type_name']) ?></strong></td>
                                            <td>
                                                <?= $missing['is_required'] ? '<span class="badge bg-danger">Required</span>' : '<span class="badge bg-secondary">Optional</span>' ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $missing['status'] === 'missing' ? 'danger' : 'warning' ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $missing['status'])) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-md-4">
                <!-- Unit Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Unit Information</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Building:</strong><br><?= h($unit['building_name']) ?></p>
                        <p><strong>Unit:</strong><br><?= h($unit['unit_number']) ?> (<?= ucfirst($unit['unit_type']) ?>)</p>
                        <p><strong>Status:</strong><br>
                            <span class="badge bg-<?= 
                                $unit['status'] === 'occupied' ? 'success' : 
                                ($unit['status'] === 'vacant' ? 'secondary' : 'warning') 
                            ?>">
                                <?= ucfirst($unit['status']) ?>
                            </span>
                        </p>
                        <?php if ($unit['lease_id']): ?>
                            <p><strong>Lease:</strong><br>
                                <a href="lease_view.php?id=<?= $unit['lease_id'] ?>"><?= h($unit['lease_number']) ?></a>
                            </p>
                            <p><strong>Tenant:</strong><br><?= h($unit['first_name'] . ' ' . $unit['last_name']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Ejari Status -->
                <?php if ($ejari): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Ejari Status</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Ejari Number:</strong><br><?= h($ejari['ejari_number']) ?></p>
                        <p><strong>Status:</strong><br>
                            <span class="badge bg-<?= 
                                $ejari['registration_status'] === 'registered' ? 'success' : 
                                ($ejari['registration_status'] === 'expired' ? 'danger' : 'warning') 
                            ?>">
                                <?= ucfirst($ejari['registration_status']) ?>
                            </span>
                        </p>
                        <?php if ($ejari['expiry_date']): ?>
                            <p><strong>Expiry:</strong><br><?= date('M d, Y', strtotime($ejari['expiry_date'])) ?></p>
                        <?php endif; ?>
                        <a href="compliance_ejari_view.php?id=<?= $ejari['id'] ?>" class="btn btn-sm btn-primary w-100">
                            <i class="bi bi-eye"></i> View Ejari
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Current Status -->
                <div class="card">
                    <div class="card-header bg-<?= 
                        $legalStatus['legal_status'] === 'compliant' ? 'success' : 
                        ($legalStatus['legal_status'] === 'non_compliant' ? 'danger' : 
                        ($legalStatus['legal_status'] === 'at_risk' ? 'warning' : 'info')) 
                    ?> text-white">
                        <h5 class="mb-0">Current Status</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Legal Status:</strong><br>
                            <span class="badge bg-<?= 
                                $legalStatus['legal_status'] === 'compliant' ? 'success' : 
                                ($legalStatus['legal_status'] === 'non_compliant' ? 'danger' : 
                                ($legalStatus['legal_status'] === 'at_risk' ? 'warning' : 'info')) 
                            ?>">
                                <?= ucfirst(str_replace('_', ' ', $legalStatus['legal_status'])) ?>
                            </span>
                        </p>
                        <?php if ($legalStatus['last_review_date']): ?>
                            <p><strong>Last Review:</strong><br><?= date('M d, Y', strtotime($legalStatus['last_review_date'])) ?></p>
                        <?php endif; ?>
                        <?php if ($legalStatus['next_review_date']): ?>
                            <p><strong>Next Review:</strong><br><?= date('M d, Y', strtotime($legalStatus['next_review_date'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

