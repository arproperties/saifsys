<?php
/**
 * Real Estate Module - Ejari Record View
 * View and manage a specific Ejari record
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

$ejariId = !empty($_GET['id']) ? (int)$_GET['id'] : null;

if (!$ejariId) {
    header('Location: compliance_ejari.php');
    exit;
}

// Get Ejari record
$ejari = $conn->prepare("
    SELECT 
        e.*,
        l.lease_number,
        l.start_date,
        l.end_date,
        l.monthly_rent,
        u.unit_number,
        u.unit_type,
        b.name as building_name,
        b.address as building_address,
        t.first_name,
        t.last_name,
        t.email as tenant_email,
        t.phone as tenant_phone,
        d.file_name,
        d.file_path,
        DATEDIFF(e.expiry_date, CURDATE()) as days_until_expiry
    FROM re_ejari_tracking e
    JOIN re_leases l ON l.id = e.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_documents d ON d.id = e.document_id
    WHERE e.id = ? AND e.company_id = ?
");
$ejari->execute([$ejariId, $currentCompanyId]);
$ejari = $ejari->fetch(PDO::FETCH_ASSOC);

if (!$ejari) {
    header('Location: compliance_ejari.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        $ejariNumber = $_POST['ejari_number'] ?? '';
        $registrationDate = $_POST['registration_date'] ?? null;
        $expiryDate = $_POST['expiry_date'] ?? null;
        $registrationStatus = $_POST['registration_status'] ?? 'pending';
        $registrationFee = !empty($_POST['registration_fee']) ? (float)$_POST['registration_fee'] : null;
        $notes = $_POST['notes'] ?? '';
        
        if ($ejariNumber) {
            $stmt = $conn->prepare("
                UPDATE re_ejari_tracking
                SET ejari_number = ?,
                    registration_date = ?,
                    expiry_date = ?,
                    registration_status = ?,
                    registration_fee = ?,
                    notes = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $ejariNumber, $registrationDate, $expiryDate,
                $registrationStatus, $registrationFee, $notes, $ejariId, $currentCompanyId
            ]);
            $_SESSION['success'] = 'Ejari record updated successfully.';
            header('Location: compliance_ejari_view.php?id=' . $ejariId);
            exit;
        }
    } elseif ($action === 'link_document') {
        $documentId = !empty($_POST['document_id']) ? (int)$_POST['document_id'] : null;
        $conn->prepare("UPDATE re_ejari_tracking SET document_id = ? WHERE id = ? AND company_id = ?")
            ->execute([$documentId, $ejariId, $currentCompanyId]);
        $_SESSION['success'] = 'Document linked successfully.';
        header('Location: compliance_ejari_view.php?id=' . $ejariId);
        exit;
    }
}

// Get related documents
$documents = $conn->prepare("
    SELECT 
        d.*,
        dt.document_type_name
    FROM re_documents d
    LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
    WHERE d.company_id = ? 
    AND d.related_type = 'lease'
    AND d.related_id = ?
    ORDER BY d.created_at DESC
");
$documents->execute([$currentCompanyId, $ejari['lease_id']]);
$documents = $documents->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Ejari Record Details';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-file-earmark-text"></i> Ejari Record: <?= h($ejari['ejari_number']) ?></h1>
            <a href="compliance_ejari.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= h($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="row">
            <!-- Ejari Details -->
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Ejari Information</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Ejari Number *</label>
                                    <input type="text" name="ejari_number" class="form-control" value="<?= h($ejari['ejari_number']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Registration Status *</label>
                                    <select name="registration_status" class="form-select" required>
                                        <option value="pending" <?= $ejari['registration_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="registered" <?= $ejari['registration_status'] === 'registered' ? 'selected' : '' ?>>Registered</option>
                                        <option value="expired" <?= $ejari['registration_status'] === 'expired' ? 'selected' : '' ?>>Expired</option>
                                        <option value="renewed" <?= $ejari['registration_status'] === 'renewed' ? 'selected' : '' ?>>Renewed</option>
                                        <option value="cancelled" <?= $ejari['registration_status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Registration Date</label>
                                    <input type="date" name="registration_date" class="form-control" value="<?= $ejari['registration_date'] ? date('Y-m-d', strtotime($ejari['registration_date'])) : '' ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Expiry Date</label>
                                    <input type="date" name="expiry_date" class="form-control" value="<?= $ejari['expiry_date'] ? date('Y-m-d', strtotime($ejari['expiry_date'])) : '' ?>">
                                    <?php if ($ejari['expiry_date'] && $ejari['days_until_expiry'] !== null): ?>
                                        <small class="form-text text-<?= 
                                            $ejari['days_until_expiry'] < 0 ? 'danger' : 
                                            ($ejari['days_until_expiry'] <= 30 ? 'warning' : 'muted') 
                                        ?>">
                                            <?= $ejari['days_until_expiry'] < 0 ? abs($ejari['days_until_expiry']) . ' days expired' : $ejari['days_until_expiry'] . ' days until expiry' ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Registration Fee (AED)</label>
                                    <input type="number" step="0.01" name="registration_fee" class="form-control" value="<?= $ejari['registration_fee'] ?? '' ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3"><?= h($ejari['notes'] ?? '') ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Update Ejari Record
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Lease Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Lease Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <strong>Lease Number:</strong><br>
                                <a href="lease_view.php?id=<?= $ejari['lease_id'] ?>"><?= h($ejari['lease_number']) ?></a>
                            </div>
                            <div class="col-md-6 mb-3">
                                <strong>Unit:</strong><br>
                                <?= h($ejari['building_name']) ?> - <?= h($ejari['unit_number']) ?>
                            </div>
                            <div class="col-md-6 mb-3">
                                <strong>Tenant:</strong><br>
                                <?= h($ejari['first_name'] . ' ' . $ejari['last_name']) ?><br>
                                <small class="text-muted"><?= h($ejari['tenant_phone'] ?: '') ?></small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <strong>Lease Period:</strong><br>
                                <?= date('M d, Y', strtotime($ejari['start_date'])) ?> - <?= date('M d, Y', strtotime($ejari['end_date'])) ?>
                            </div>
                            <div class="col-md-6 mb-3">
                                <strong>Monthly Rent:</strong><br>
                                <?= number_format($ejari['monthly_rent'], 2) ?> AED
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-md-4">
                <!-- Status Card -->
                <div class="card mb-4">
                    <div class="card-header bg-<?= 
                        $ejari['registration_status'] === 'registered' ? 'success' : 
                        ($ejari['registration_status'] === 'expired' ? 'danger' : 
                        ($ejari['registration_status'] === 'pending' ? 'warning' : 'secondary')) 
                    ?> text-white">
                        <h5 class="mb-0">Status</h5>
                    </div>
                    <div class="card-body text-center">
                        <h3 class="mb-0">
                            <span class="badge bg-<?= 
                                $ejari['registration_status'] === 'registered' ? 'success' : 
                                ($ejari['registration_status'] === 'expired' ? 'danger' : 
                                ($ejari['registration_status'] === 'pending' ? 'warning' : 'secondary')) 
                            ?>">
                                <?= ucfirst($ejari['registration_status']) ?>
                            </span>
                        </h3>
                    </div>
                </div>

                <!-- Linked Document -->
                <?php if ($ejari['document_id']): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-paperclip"></i> Linked Document</h5>
                    </div>
                    <div class="card-body">
                        <p><strong><?= h($ejari['file_name']) ?></strong></p>
                        <a href="<?= h($ejari['file_path']) ?>" target="_blank" class="btn btn-sm btn-primary">
                            <i class="bi bi-download"></i> View Document
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Link Document -->
                <?php if (!empty($documents)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-link"></i> Link Document</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="link_document">
                            <div class="mb-3">
                                <label class="form-label">Select Document</label>
                                <select name="document_id" class="form-select">
                                    <option value="">-- No Document --</option>
                                    <?php foreach ($documents as $doc): ?>
                                        <option value="<?= $doc['id'] ?>" <?= $ejari['document_id'] == $doc['id'] ? 'selected' : '' ?>>
                                            <?= h($doc['document_name'] ?: $doc['file_name']) ?>
                                            <?php if ($doc['document_type_name']): ?>
                                                (<?= h($doc['document_type_name']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="bi bi-link"></i> Link Document
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <a href="lease_view.php?id=<?= $ejari['lease_id'] ?>" class="btn btn-sm btn-outline-primary w-100 mb-2">
                            <i class="bi bi-file-text"></i> View Lease
                        </a>
                        <a href="compliance.php" class="btn btn-sm btn-outline-info w-100 mb-2">
                            <i class="bi bi-shield-check"></i> Compliance Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

