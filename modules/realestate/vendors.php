<?php
/**
 * Real Estate Module - Vendor Management
 * Contractors and service providers management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/vendor_delete_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}
$userId = current_user_id();

$success = '';
$error = '';

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    csrf_verify();
    $vendorId = (int)($_POST['id'] ?? 0);
    $res = re_vendor_delete($conn, $currentCompanyId, $vendorId, $userId);
    if (!empty($res['success'])) {
        $success = (string)($res['message'] ?? 'Vendor deleted successfully.');
    } else {
        $error = (string)($res['error'] ?? 'Could not delete vendor.');
    }
}

// Get filter parameters
$filterType = $_GET['vendor_type'] ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

// Build query
$where = ["v.company_id = ?"];
$params = [$currentCompanyId];

if ($filterType !== 'all') {
    $where[] = "v.vendor_type = ?";
    $params[] = $filterType;
}

if ($filterStatus !== 'all') {
    $where[] = "v.status = ?";
    $params[] = $filterStatus;
}

if ($search) {
    $where[] = "(v.vendor_name LIKE ? OR v.contact_person LIKE ? OR v.email LIKE ? OR v.phone LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Get vendors
$vendors = $conn->prepare("
    SELECT v.*, 
           COUNT(DISTINCT sa.id) as agreement_count,
           COUNT(DISTINCT mr.id) as maintenance_count,
           COALESCE(ap.bill_count, 0) as bill_count,
           COALESCE(ap.total_billed, 0) as total_billed,
           COALESCE(pay.total_paid, 0) as total_paid,
           COALESCE(pay.payment_count, 0) as payment_count,
           COALESCE(ap.outstanding_balance, 0) as outstanding_balance,
           COALESCE(adv.balance_aed, 0) as advance_remaining,
           COALESCE(qpe.qpe_count, 0) as qpe_count,
           ap.last_bill_date,
           ap.last_bill_number
    FROM re_vendors v
    LEFT JOIN re_service_agreements sa ON sa.vendor_id = v.id AND sa.company_id = v.company_id
    LEFT JOIN re_maintenance_requests mr ON mr.vendor_id = v.id AND mr.company_id = v.company_id
    LEFT JOIN (
        SELECT company_id, vendor_id,
               COUNT(*) as bill_count,
               SUM(CASE WHEN status NOT IN ('cancelled','void','rejected') THEN total_amount ELSE 0 END) as total_billed,
               SUM(CASE WHEN status NOT IN ('paid','cancelled','void','rejected') THEN GREATEST(CASE WHEN COALESCE(balance_due,0) > 0.005 THEN balance_due ELSE total_amount - COALESCE(paid_amount,0) END, 0) ELSE 0 END) as outstanding_balance,
               MAX(invoice_date) as last_bill_date,
               SUBSTRING_INDEX(GROUP_CONCAT(invoice_number ORDER BY invoice_date DESC, id DESC), ',', 1) as last_bill_number
        FROM re_vendor_invoices
        WHERE company_id = ?
        GROUP BY company_id, vendor_id
    ) ap ON ap.vendor_id = v.id AND ap.company_id = v.company_id
    LEFT JOIN (
        SELECT company_id, vendor_id,
               SUM(CASE WHEN status <> 'void' THEN amount ELSE 0 END) as total_paid,
               COUNT(*) as payment_count
        FROM re_vendor_payments
        WHERE company_id = ?
        GROUP BY company_id, vendor_id
    ) pay ON pay.vendor_id = v.id AND pay.company_id = v.company_id
    LEFT JOIN (
        SELECT company_id, vendor_id, COUNT(*) as qpe_count
        FROM erp_expense_headers
        WHERE company_id = ? AND source_module = 'realestate'
        GROUP BY company_id, vendor_id
    ) qpe ON qpe.vendor_id = v.id AND qpe.company_id = v.company_id
    LEFT JOIN re_vendor_advance_balances adv ON adv.vendor_id = v.id AND adv.company_id = v.company_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY v.id
    ORDER BY v.vendor_name
");
$vendors->execute(array_merge([$currentCompanyId, $currentCompanyId, $currentCompanyId], $params));
$vendors = $vendors->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];
$stmt = $conn->prepare("SELECT COUNT(*) FROM re_vendors WHERE company_id = ? AND status = 'active'");
$stmt->execute([$currentCompanyId]);
$stats['active'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_vendors WHERE company_id = ?");
$stmt->execute([$currentCompanyId]);
$stats['total'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_service_agreements WHERE company_id = ? AND status = 'active'");
$stmt->execute([$currentCompanyId]);
$stats['active_agreements'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM re_vendor_payments WHERE company_id = ? AND status <> 'void'");
$stmt->execute([$currentCompanyId]);
$stats['total_spent'] = (float)($stmt->fetchColumn() ?: 0);

$stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) FROM re_vendor_invoices WHERE company_id = ? AND status NOT IN ('cancelled','void','rejected')");
$stmt->execute([$currentCompanyId]);
$stats['total_billed'] = (float)($stmt->fetchColumn() ?: 0);

$stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled','void','rejected') THEN GREATEST(CASE WHEN COALESCE(balance_due,0) > 0.005 THEN balance_due ELSE total_amount - COALESCE(paid_amount,0) END, 0) ELSE 0 END),0) FROM re_vendor_invoices WHERE company_id = ?");
$stmt->execute([$currentCompanyId]);
$stats['outstanding_balance'] = (float)($stmt->fetchColumn() ?: 0);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function getVendorTypeLabel($type) {
    $types = [
        'contractor' => 'Contractor',
        'supplier' => 'Supplier',
        'service_provider' => 'Service Provider',
        'maintenance' => 'Maintenance',
        'cleaning' => 'Cleaning',
        'security' => 'Security',
        'other' => 'Other'
    ];
    return $types[$type] ?? ucfirst($type);
}
function getStatusBadge($status) {
    $badges = [
        'active' => 'bg-success',
        'inactive' => 'bg-secondary',
        'suspended' => 'bg-warning',
        'blacklisted' => 'bg-danger'
    ];
    return $badges[$status] ?? 'bg-secondary';
}

// Set page title and include layout
$pageTitle = 'Vendor Management';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-truck"></i> Vendor Management</h1>
            <a href="vendors_add.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> New Vendor
            </a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Vendors</h5>
                        <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Active Vendors</h5>
                        <h2 class="mb-0 text-success"><?= $stats['active'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Billed</h5>
                        <h2 class="mb-0"><?= number_format($stats['total_billed'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Spent</h5>
                        <h2 class="mb-0"><?= number_format($stats['total_spent'], 2) ?> AED</h2>
                        <small class="text-muted">Paid to vendors</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Outstanding AP</h5>
                        <h2 class="mb-0 text-danger"><?= number_format($stats['outstanding_balance'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Active Agreements</h5>
                        <h2 class="mb-0"><?= $stats['active_agreements'] ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Vendor Type</label>
                        <select name="vendor_type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="contractor" <?= $filterType === 'contractor' ? 'selected' : '' ?>>Contractor</option>
                            <option value="supplier" <?= $filterType === 'supplier' ? 'selected' : '' ?>>Supplier</option>
                            <option value="service_provider" <?= $filterType === 'service_provider' ? 'selected' : '' ?>>Service Provider</option>
                            <option value="maintenance" <?= $filterType === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            <option value="cleaning" <?= $filterType === 'cleaning' ? 'selected' : '' ?>>Cleaning</option>
                            <option value="security" <?= $filterType === 'security' ? 'selected' : '' ?>>Security</option>
                            <option value="other" <?= $filterType === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="suspended" <?= $filterStatus === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            <option value="blacklisted" <?= $filterStatus === 'blacklisted' ? 'selected' : '' ?>>Blacklisted</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm" 
                               value="<?= h($search) ?>" placeholder="Name, contact, email, phone...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Vendors Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Vendors (<?= count($vendors) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($vendors)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No vendors found. 
                        <a href="vendors_add.php">Create your first vendor</a>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Vendor Name</th>
                                    <th>Type</th>
                                    <th>Contact</th>
                                    <th>Rating</th>
                                    <th>Agreements</th>
                                    <th>Jobs</th>
                                    <th>Accounting</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vendors as $vendor): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($vendor['vendor_name']) ?></strong>
                                            <?php if ($vendor['contact_person']): ?>
                                                <br><small class="text-muted">Contact: <?= h($vendor['contact_person']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= getVendorTypeLabel($vendor['vendor_type']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($vendor['email']): ?>
                                                <i class="bi bi-envelope"></i> <?= h($vendor['email']) ?><br>
                                            <?php endif; ?>
                                            <?php if ($vendor['phone']): ?>
                                                <i class="bi bi-telephone"></i> <?= h($vendor['phone']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($vendor['rating']): ?>
                                                <div class="d-flex align-items-center">
                                                    <span class="text-warning">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="bi bi-star<?= $i <= round($vendor['rating']) ? '-fill' : '' ?>"></i>
                                                        <?php endfor; ?>
                                                    </span>
                                                    <span class="ms-2"><?= number_format($vendor['rating'], 1) ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">No rating</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= $vendor['agreement_count'] ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?= $vendor['maintenance_count'] ?></span>
                                        </td>
                                        <td>
                                            <div><strong>Billed:</strong> <?= number_format((float)$vendor['total_billed'], 2) ?> AED</div>
                                            <div><strong>Paid:</strong> <?= number_format((float)$vendor['total_paid'], 2) ?> AED</div>
                                            <div class="<?= ((float)$vendor['outstanding_balance'] > 0.005) ? 'text-danger' : 'text-success' ?>">
                                                <strong>Balance:</strong> <?= number_format((float)$vendor['outstanding_balance'], 2) ?> AED
                                            </div>
                                            <div class="text-success">
                                                <strong>Advance Remaining:</strong> <?= number_format((float)($vendor['advance_remaining'] ?? 0), 2) ?> AED
                                            </div>
                                            <small class="text-muted">
                                                Bills: <?= (int)$vendor['bill_count'] ?>
                                                <?php if (!empty($vendor['last_bill_date'])): ?>
                                                    · Last: <?= h($vendor['last_bill_number']) ?> (<?= date('M d, Y', strtotime($vendor['last_bill_date'])) ?>)
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge <?= getStatusBadge($vendor['status']) ?>">
                                                <?= ucfirst($vendor['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $deletePre = re_vendor_delete_precheck($conn, $currentCompanyId, (int)$vendor['id']);
                                            $canDeleteVendor = !empty($deletePre['can_delete']);
                                            $qpeCount = (int)($deletePre['qpe_count'] ?? $vendor['qpe_count'] ?? 0);
                                            $deleteBlockReason = !empty($deletePre['blockers'])
                                                ? ('Cannot delete: ' . implode(', ', $deletePre['blockers']) . '. Set inactive instead.')
                                                : 'Cannot delete this vendor.';
                                            ?>
                                            <div class="btn-group" role="group">
                                                <a href="vendors_view.php?id=<?= $vendor['id'] ?>" class="btn btn-sm btn-primary" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="vendors_add.php?id=<?= $vendor['id'] ?>" class="btn btn-sm btn-secondary" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="accounting/vendor_statement.php?vendor_id=<?= $vendor['id'] ?>" class="btn btn-sm btn-outline-primary" title="Vendor SOA">
                                                    <i class="bi bi-file-earmark-ruled"></i>
                                                </a>
                                                <a href="accounting/vendor_payment_add.php?vendor_id=<?= $vendor['id'] ?>" class="btn btn-sm btn-outline-success" title="Payment Made">
                                                    <i class="bi bi-cash-coin"></i>
                                                </a>
                                                <a href="accounting/ap_aging.php?vendor_id=<?= $vendor['id'] ?>" class="btn btn-sm btn-outline-secondary" title="AP Aging">
                                                    <i class="bi bi-clock-history"></i>
                                                </a>
                                                <?php if ($canDeleteVendor): ?>
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-danger"
                                                            title="Delete vendor"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#deleteVendorModal<?= (int)$vendor['id'] ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-secondary"
                                                            title="<?= h($deleteBlockReason) ?>"
                                                            disabled>
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($canDeleteVendor): ?>
                                            <div class="modal fade" id="deleteVendorModal<?= (int)$vendor['id'] ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="POST">
                                                            <?php csrf_field(); ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?= (int)$vendor['id'] ?>">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title text-danger"><i class="bi bi-trash"></i> Delete vendor</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <p class="mb-2">
                                                                    Delete <strong><?= h($vendor['vendor_name']) ?></strong>?
                                                                </p>
                                                                <ul class="small text-muted mb-0">
                                                                    <li>Allowed because this vendor has <strong>no bills</strong> and <strong>no payments</strong>.</li>
                                                                    <?php if ($qpeCount > 0): ?>
                                                                        <li>
                                                                            <strong><?= $qpeCount ?></strong> Quick Paid Expense record(s) will be reversed (if posted) and deleted with the vendor.
                                                                        </li>
                                                                    <?php else: ?>
                                                                        <li>Related agreements / documents for this vendor will also be removed.</li>
                                                                    <?php endif; ?>
                                                                    <li>This cannot be undone.</li>
                                                                </ul>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep vendor</button>
                                                                <button type="submit" class="btn btn-danger">Delete vendor</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
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
    </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

