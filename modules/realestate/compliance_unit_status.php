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
require_once __DIR__ . '/includes/compliance_helper.php';

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
        t.tenant_type,
        t.company_name,
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

// Status and score are always worked out from the documents (shared watch rule),
// so a renewed document never leaves the old copy counting as expired.
$evaluation = update_unit_compliance_status($conn, $currentCompanyId, $unitId)['evaluation'];
$documents = $evaluation['documents'];
$missingDocs = $evaluation['missing'];

$legalStatus = $conn->prepare("SELECT * FROM re_unit_legal_status WHERE unit_id = ? AND company_id = ?");
$legalStatus->execute([$unitId, $currentCompanyId]);
$legalStatus = $legalStatus->fetch(PDO::FETCH_ASSOC);

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
        // Only the review details are saved by hand; status and score are automatic.
        $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));
        $nextReviewDate = trim((string)($_POST['next_review_date'] ?? ''));
        if ($nextReviewDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextReviewDate)) {
            $nextReviewDate = '';
        }

        $stmt = $conn->prepare("
            UPDATE re_unit_legal_status
            SET last_review_date = CURDATE(),
                next_review_date = ?,
                review_notes = ?,
                reviewed_by = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([
            $nextReviewDate !== '' ? $nextReviewDate : null,
            $reviewNotes, $currentUserId, $legalStatus['id'], $currentCompanyId
        ]);

        $_SESSION['success'] = 'Review saved.';
        header('Location: compliance_unit_status.php?unit_id=' . $unitId);
        exit;
    }
}

// Metrics (replaced copies are shown but never counted)
$expiredDocuments = $evaluation['expired'];
$expiringSoon = $evaluation['expiring'];
$missingRequired = $evaluation['missing_required'];
$tenantName = ($unit['tenant_type'] ?? '') === 'company' && trim((string)($unit['company_name'] ?? '')) !== ''
    ? trim((string)$unit['company_name'])
    : trim(($unit['first_name'] ?? '') . ' ' . ($unit['last_name'] ?? ''));

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

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
                        <h5 class="mb-0"><i class="bi bi-pencil"></i> Review</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_status">
                            
                            <div class="alert alert-light border small mb-3">
                                <i class="bi bi-info-circle me-1"></i>
                                Legal status and score update automatically from the documents:
                                each expired document &minus;20, each missing required document &minus;30.
                                Below 50 is <strong>Non-compliant</strong>, otherwise <strong>At risk</strong> while anything is expired or missing.
                                A renewed copy replaces the old one, so uploading the renewal clears it.
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
                                <i class="bi bi-save"></i> Save review
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
                                            <tr class="<?= $doc['is_replaced'] ? 'text-muted' : ($doc['is_expired'] ? 'table-danger' : ($doc['is_expiring'] ? 'table-warning' : '')) ?>">
                                                <td>
                                                    <strong><?= h($doc['document_name'] ?: $doc['file_name']) ?></strong>
                                                    <?php if ($doc['is_required']): ?>
                                                        <span class="badge bg-danger ms-2">Required</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= h($doc['document_type_name'] ?: '-') ?></td>
                                                <td>
                                                    <?= $doc['expiry_date'] ? date('M d, Y', strtotime($doc['expiry_date'])) : 'No expiry' ?>
                                                    <?php if ($doc['days_until_expiry'] !== null && !$doc['is_replaced']): $dl = (int)$doc['days_until_expiry']; ?>
                                                        <br><small class="text-<?= $dl < 0 ? 'danger' : ($dl <= 30 ? 'warning' : 'muted') ?>">
                                                            <?= $dl < 0 ? abs($dl) . ' days expired' : $dl . ' days left' ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($doc['is_replaced']): ?>
                                                        <span class="badge bg-secondary" title="A newer copy of this document is on file">Replaced by newer copy</span>
                                                    <?php elseif ($doc['is_expired']): ?>
                                                        <span class="badge bg-danger">Expired</span>
                                                    <?php elseif ($doc['is_expiring']): ?>
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
                            <p><strong>Tenant:</strong><br><?= h($tenantName ?: '—') ?></p>
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

