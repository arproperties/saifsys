<?php
/**
 * Real Estate Module - Ejari Tracking Management
 * Track Ejari registration for Dubai leases
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'edit') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $leaseId = (int)$_POST['lease_id'];
        $ejariNumber = $_POST['ejari_number'] ?? '';
        $registrationDate = $_POST['registration_date'] ?? null;
        $expiryDate = $_POST['expiry_date'] ?? null;
        $registrationStatus = $_POST['registration_status'] ?? 'pending';
        $registrationFee = !empty($_POST['registration_fee']) ? (float)$_POST['registration_fee'] : null;
        $notes = $_POST['notes'] ?? '';
        
        if ($leaseId && $ejariNumber) {
            if ($id) {
                // Update
                $stmt = $conn->prepare("
                    UPDATE re_ejari_tracking
                    SET lease_id = ?,
                        ejari_number = ?,
                        registration_date = ?,
                        expiry_date = ?,
                        registration_status = ?,
                        registration_fee = ?,
                        notes = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $leaseId, $ejariNumber, $registrationDate, $expiryDate,
                    $registrationStatus, $registrationFee, $notes, $id, $currentCompanyId
                ]);
            } else {
                // Insert
                $stmt = $conn->prepare("
                    INSERT INTO re_ejari_tracking
                    (company_id, lease_id, ejari_number, registration_date, expiry_date, 
                     registration_status, registration_fee, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId, $leaseId, $ejariNumber, $registrationDate, $expiryDate,
                    $registrationStatus, $registrationFee, $notes, $currentUserId
                ]);
            }
            $_SESSION['success'] = 'Ejari record saved successfully.';
            header('Location: compliance_ejari.php');
            exit;
        }
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';

// Build query
$where = ["e.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = "e.registration_status = ?";
    $params[] = $statusFilter;
}

// Get Ejari records
$ejariRecords = $conn->prepare("
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
    WHERE " . implode(' AND ', $where) . "
    ORDER BY 
        CASE e.registration_status
            WHEN 'expired' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'registered' THEN 3
            ELSE 4
        END,
        e.expiry_date ASC
");
$ejariRecords->execute($params);
$ejariRecords = $ejariRecords->fetchAll(PDO::FETCH_ASSOC);

// Get active leases for dropdown
$leases = $conn->prepare("
    SELECT 
        l.id,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE l.company_id = ? AND l.status = 'active'
    ORDER BY b.name, u.unit_number
");
$leases->execute([$currentCompanyId]);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN registration_status = 'registered' THEN 1 END) as registered,
        COUNT(CASE WHEN registration_status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN registration_status = 'expired' THEN 1 END) as expired,
        COUNT(CASE WHEN registration_status = 'registered' AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as expiring_soon
    FROM re_ejari_tracking
    WHERE company_id = ?
");
$stats->execute([$currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Ejari Tracking';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-file-earmark-text"></i> Ejari Tracking</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ejariModal" onclick="resetForm()">
                <i class="bi bi-plus-circle"></i> Add Ejari Record
            </button>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= h($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total</h5>
                        <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h5 class="text-muted">Registered</h5>
                        <h2 class="mb-0 text-success"><?= $stats['registered'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Pending</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['pending'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Expired</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['expired'] ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="registered" <?= $statusFilter === 'registered' ? 'selected' : '' ?>>Registered</option>
                            <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                            <option value="renewed" <?= $statusFilter === 'renewed' ? 'selected' : '' ?>>Renewed</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Ejari Records Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Ejari Records (<?= count($ejariRecords) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
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
                            <?php if (empty($ejariRecords)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No Ejari records found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($ejariRecords as $ejari): ?>
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
                                                <br><small class="text-<?= 
                                                    $ejari['days_until_expiry'] < 0 ? 'danger' : 
                                                    ($ejari['days_until_expiry'] <= 30 ? 'warning' : 'muted') 
                                                ?>">
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
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Ejari Modal -->
    <div class="modal fade" id="ejariModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Ejari Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="ejariForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create" id="formAction">
                    <input type="hidden" name="id" id="formId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Select Lease *</label>
                            <select name="lease_id" id="lease_id" class="form-select" required>
                                <option value="">-- Select Lease --</option>
                                <?php foreach ($leases as $lease): ?>
                                    <option value="<?= $lease['id'] ?>">
                                        <?= h($lease['lease_number']) ?> - 
                                        <?= h($lease['building_name']) ?> - 
                                        <?= h($lease['unit_number']) ?> - 
                                        <?= h($lease['first_name'] . ' ' . $lease['last_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ejari Number *</label>
                            <input type="text" name="ejari_number" id="ejari_number" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Registration Date</label>
                                <input type="date" name="registration_date" id="registration_date" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Expiry Date</label>
                                <input type="date" name="expiry_date" id="expiry_date" class="form-control">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Registration Status *</label>
                                <select name="registration_status" id="registration_status" class="form-select" required>
                                    <option value="pending">Pending</option>
                                    <option value="registered">Registered</option>
                                    <option value="expired">Expired</option>
                                    <option value="renewed">Renewed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Registration Fee (AED)</label>
                                <input type="number" step="0.01" name="registration_fee" id="registration_fee" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function resetForm() {
            document.getElementById('ejariForm').reset();
            document.getElementById('formAction').value = 'create';
            document.getElementById('formId').value = '';
        }
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

