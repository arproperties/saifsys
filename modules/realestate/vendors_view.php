<?php
/**
 * Real Estate Module - Vendor View
 * Complete vendor details, agreements, performance, invoices
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/vendor_ap_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

$vendorId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$vendorId) {
    header('Location: vendors.php');
    exit;
}

// Get vendor
$stmt = $conn->prepare("SELECT * FROM re_vendors WHERE id = ? AND company_id = ?");
$stmt->execute([$vendorId, $currentCompanyId]);
$vendor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vendor) {
    header('Location: vendors.php');
    exit;
}

// Get statistics
$stats = [];
$stmt = $conn->prepare("SELECT COUNT(*) FROM re_service_agreements WHERE vendor_id = ? AND company_id = ?");
$stmt->execute([$vendorId, $currentCompanyId]);
$stats['agreements'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_service_agreements WHERE vendor_id = ? AND company_id = ? AND status = 'active'");
$stmt->execute([$vendorId, $currentCompanyId]);
$stats['active_agreements'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_maintenance_requests WHERE vendor_id = ? AND company_id = ?");
$stmt->execute([$vendorId, $currentCompanyId]);
$stats['maintenance_jobs'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM re_vendor_payments WHERE vendor_id = ? AND company_id = ? AND status <> 'void'");
$stmt->execute([$vendorId, $currentCompanyId]);
$stats['total_spent'] = (float)($stmt->fetchColumn() ?: 0);

$stmt = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','void','rejected') THEN total_amount ELSE 0 END),0), COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled','void','rejected') THEN GREATEST(COALESCE(balance_due, total_amount - COALESCE(paid_amount,0)), 0) ELSE 0 END),0), COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled','void','rejected') AND due_date < CURDATE() THEN GREATEST(COALESCE(balance_due, total_amount - COALESCE(paid_amount,0)), 0) ELSE 0 END),0) FROM re_vendor_invoices WHERE vendor_id = ? AND company_id = ?");
$stmt->execute([$vendorId, $currentCompanyId]);
$apStats = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0, 0];
$stats['bill_count'] = (int)$apStats[0];
$stats['total_billed'] = (float)$apStats[1];
$stats['outstanding_balance'] = (float)$apStats[2];
$stats['overdue_balance'] = (float)$apStats[3];

$stmt = $conn->prepare("SELECT payment_date, amount, reference_number FROM re_vendor_payments WHERE vendor_id = ? AND company_id = ? AND status <> 'void' ORDER BY payment_date DESC, id DESC LIMIT 1");
$stmt->execute([$vendorId, $currentCompanyId]);
$latestPayment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$stats['advance_remaining'] = function_exists('re_ap_vendor_advance_balance')
    ? re_ap_vendor_advance_balance($conn, $currentCompanyId, $vendorId)
    : 0.0;

$quickPaidExpenses = [];
try {
    $qpe = $conn->prepare("
        SELECT id, expense_number, expense_date, total, paid_via, reference_no, journal_id, status
        FROM erp_expense_headers
        WHERE company_id = ? AND source_module = 'realestate' AND vendor_id = ? AND status = 'posted'
        ORDER BY expense_date DESC, id DESC
        LIMIT 25
    ");
    $qpe->execute([$currentCompanyId, $vendorId]);
    $quickPaidExpenses = $qpe->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $quickPaidExpenses = [];
}

$stmt = $conn->prepare("SELECT AVG(overall_score) FROM re_vendor_performance WHERE vendor_id = ? AND company_id = ?");
$stmt->execute([$vendorId, $currentCompanyId]);
$stats['avg_performance'] = (float)($stmt->fetchColumn() ?: 0);

// Get agreements
$agreements = $conn->prepare("
    SELECT sa.*, COUNT(DISTINCT vs.id) as service_count
    FROM re_service_agreements sa
    LEFT JOIN re_vendor_services vs ON vs.agreement_id = sa.id
    WHERE sa.vendor_id = ? AND sa.company_id = ?
    GROUP BY sa.id
    ORDER BY sa.start_date DESC
    LIMIT 5
");
$agreements->execute([$vendorId, $currentCompanyId]);
$agreements = $agreements->fetchAll(PDO::FETCH_ASSOC);

// Get recent performance
$performance = $conn->prepare("
    SELECT p.*, u.username as reviewed_by_name
    FROM re_vendor_performance p
    LEFT JOIN user u ON u.id = p.reviewed_by
    WHERE p.vendor_id = ? AND p.company_id = ?
    ORDER BY p.performance_date DESC
    LIMIT 5
");
$performance->execute([$vendorId, $currentCompanyId]);
$performance = $performance->fetchAll(PDO::FETCH_ASSOC);

// Get recent invoices
$invoices = $conn->prepare("
    SELECT vi.*,
           GREATEST(COALESCE(vi.balance_due, vi.total_amount - COALESCE(vi.paid_amount,0)), 0) as computed_balance,
           (SELECT COUNT(*) FROM re_vendor_bill_attachments a WHERE a.vendor_invoice_id=vi.id AND a.company_id=vi.company_id) attachment_count,
           (SELECT jh.id FROM re_journal_headers jh WHERE jh.company_id=vi.company_id AND jh.reference_type='vendor_invoice' AND jh.reference_id=vi.id AND jh.is_posted=1 AND jh.is_reversed=0 ORDER BY jh.id DESC LIMIT 1) posted_journal_id
    FROM re_vendor_invoices vi
    WHERE vi.vendor_id = ? AND vi.company_id = ?
    ORDER BY vi.invoice_date DESC, vi.id DESC
    LIMIT 8
");
$invoices->execute([$vendorId, $currentCompanyId]);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);

$recentPayments = $conn->prepare("
    SELECT *
    FROM re_vendor_payments
    WHERE vendor_id = ? AND company_id = ? AND status <> 'void'
    ORDER BY payment_date DESC, id DESC
    LIMIT 5
");
$recentPayments->execute([$vendorId, $currentCompanyId]);
$recentPayments = $recentPayments->fetchAll(PDO::FETCH_ASSOC);

// Get documents
$documents = $conn->prepare("
    SELECT d.*, u.username as uploaded_by_name
    FROM re_vendor_documents d
    LEFT JOIN user u ON u.id = d.uploaded_by
    WHERE d.vendor_id = ? AND d.company_id = ?
    ORDER BY d.uploaded_at DESC
");
$documents->execute([$vendorId, $currentCompanyId]);
$documents = $documents->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function getVendorTypeLabel($type) {
    $types = ['contractor' => 'Contractor', 'supplier' => 'Supplier', 'service_provider' => 'Service Provider', 'maintenance' => 'Maintenance', 'cleaning' => 'Cleaning', 'security' => 'Security', 'other' => 'Other'];
    return $types[$type] ?? ucfirst($type);
}
function getStatusBadge($status) {
    $badges = ['active' => 'bg-success', 'inactive' => 'bg-secondary', 'suspended' => 'bg-warning', 'blacklisted' => 'bg-danger', 'draft' => 'bg-warning text-dark', 'open' => 'bg-primary', 'partially_paid' => 'bg-info', 'paid' => 'bg-success', 'pending' => 'bg-secondary', 'approved' => 'bg-primary', 'cancelled' => 'bg-secondary', 'void' => 'bg-dark', 'expired' => 'bg-danger', 'terminated' => 'bg-dark', 'renewed' => 'bg-info'];
    return $badges[$status] ?? 'bg-secondary';
}
function m($n) { return number_format((float)$n, 2); }
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

// Set page title and include layout
$pageTitle = 'Vendor Details';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="vendors.php">Vendors</a>
                <a class="nav-link" href="index.php">Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-truck"></i> <?= h($vendor['vendor_name']) ?></h1>
            <div>
                <a href="vendors_add.php?id=<?= $vendorId ?>" class="btn btn-primary">
                    <i class="bi bi-pencil"></i> Edit Vendor
                </a>
                <a href="vendor_agreements.php?vendor_id=<?= $vendorId ?>" class="btn btn-info">
                    <i class="bi bi-file-text"></i> Agreements
                </a>
                <a href="vendors.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Bills</h5>
                        <h2 class="mb-0"><?= $stats['bill_count'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Billed</h5>
                        <h2 class="mb-0"><?= m($stats['total_billed']) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Paid</h5>
                        <h2 class="mb-0 text-success"><?= m($stats['total_spent']) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Balance Due</h5>
                        <h2 class="mb-0 <?= $stats['outstanding_balance'] > 0.005 ? 'text-danger' : 'text-success' ?>"><?= m($stats['outstanding_balance']) ?> AED</h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Advance Remaining</h5>
                        <h2 class="mb-0 text-success"><?= m($stats['advance_remaining']) ?> AED</h2>
                        <?php if ($stats['advance_remaining'] > 0.005): ?>
                            <small class="text-muted">
                                <a href="accounting/vendor_advance_refund_add.php?vendor_id=<?= (int)$vendorId ?>">Refund advance</a>
                            </small>
                        <?php endif; ?>
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
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Overdue Balance</h5>
                        <h2 class="mb-0 <?= $stats['overdue_balance'] > 0.005 ? 'text-danger' : 'text-success' ?>"><?= m($stats['overdue_balance']) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Latest Payment</h5>
                        <?php if ($latestPayment): ?>
                            <h2 class="mb-0"><?= m($latestPayment['amount']) ?> AED</h2>
                            <small class="text-muted"><?= date('M d, Y', strtotime($latestPayment['payment_date'])) ?></small>
                        <?php else: ?>
                            <h2 class="mb-0">0.00 AED</h2>
                            <small class="text-muted">No payments yet</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Main Content -->
            <div class="col-md-8">
                <!-- Vendor Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Vendor Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Vendor Type:</strong> 
                                <span class="badge bg-info"><?= getVendorTypeLabel($vendor['vendor_type']) ?></span><br>
                                <strong>Status:</strong> 
                                <span class="badge <?= getStatusBadge($vendor['status']) ?>">
                                    <?= ucfirst($vendor['status']) ?>
                                </span><br>
                                <?php if ($vendor['rating']): ?>
                                    <strong>Rating:</strong> 
                                    <span class="text-warning">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="bi bi-star<?= $i <= round($vendor['rating']) ? '-fill' : '' ?>"></i>
                                        <?php endfor; ?>
                                    </span>
                                    <?= number_format($vendor['rating'], 1) ?>/5.0<br>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <?php if ($vendor['contact_person']): ?>
                                    <strong>Contact Person:</strong> <?= h($vendor['contact_person']) ?><br>
                                <?php endif; ?>
                                <?php if ($vendor['email']): ?>
                                    <strong>Email:</strong> <a href="mailto:<?= h($vendor['email']) ?>"><?= h($vendor['email']) ?></a><br>
                                <?php endif; ?>
                                <?php if ($vendor['phone']): ?>
                                    <strong>Phone:</strong> <?= h($vendor['phone']) ?><br>
                                <?php endif; ?>
                                <?php if ($vendor['mobile']): ?>
                                    <strong>Mobile:</strong> <?= h($vendor['mobile']) ?><br>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($vendor['address']): ?>
                            <div class="mb-3">
                                <strong>Address:</strong><br>
                                <?= nl2br(h($vendor['address'])) ?>
                                <?php if ($vendor['city']): ?>, <?= h($vendor['city']) ?><?php endif; ?>
                                <?php if ($vendor['state']): ?>, <?= h($vendor['state']) ?><?php endif; ?>
                                <?php if ($vendor['country']): ?>, <?= h($vendor['country']) ?><?php endif; ?>
                                <?php if ($vendor['postal_code']): ?> <?= h($vendor['postal_code']) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Legal & Compliance -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Legal & Compliance</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <?php if ($vendor['tax_id']): ?>
                                    <strong>Tax ID:</strong> <?= h($vendor['tax_id']) ?><br>
                                <?php endif; ?>
                                <?php if ($vendor['license_number']): ?>
                                    <strong>License Number:</strong> <?= h($vendor['license_number']) ?><br>
                                <?php endif; ?>
                                <?php if ($vendor['license_expiry']): ?>
                                    <strong>License Expiry:</strong> 
                                    <span class="<?= strtotime($vendor['license_expiry']) < time() ? 'text-danger' : '' ?>">
                                        <?= date('M d, Y', strtotime($vendor['license_expiry'])) ?>
                                    </span>
                                    <?php if (strtotime($vendor['license_expiry']) < time()): ?>
                                        <span class="badge bg-danger">Expired</span>
                                    <?php endif; ?>
                                    <br>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <?php if ($vendor['insurance_provider']): ?>
                                    <strong>Insurance Provider:</strong> <?= h($vendor['insurance_provider']) ?><br>
                                <?php endif; ?>
                                <?php if ($vendor['insurance_policy_number']): ?>
                                    <strong>Policy Number:</strong> <?= h($vendor['insurance_policy_number']) ?><br>
                                <?php endif; ?>
                                <?php if ($vendor['insurance_expiry']): ?>
                                    <strong>Insurance Expiry:</strong> 
                                    <span class="<?= strtotime($vendor['insurance_expiry']) < time() ? 'text-danger' : '' ?>">
                                        <?= date('M d, Y', strtotime($vendor['insurance_expiry'])) ?>
                                    </span>
                                    <?php if (strtotime($vendor['insurance_expiry']) < time()): ?>
                                        <span class="badge bg-danger">Expired</span>
                                    <?php endif; ?>
                                    <br>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Information -->
                <?php if ($vendor['payment_terms'] || $vendor['bank_name']): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Payment Information</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($vendor['payment_terms']): ?>
                            <strong>Payment Terms:</strong> <?= h($vendor['payment_terms']) ?><br>
                        <?php endif; ?>
                        <?php if ($vendor['bank_name']): ?>
                            <strong>Bank:</strong> <?= h($vendor['bank_name']) ?><br>
                        <?php endif; ?>
                        <?php if ($vendor['bank_account_number']): ?>
                            <strong>Account Number:</strong> <?= h($vendor['bank_account_number']) ?><br>
                        <?php endif; ?>
                        <?php if ($vendor['bank_iban']): ?>
                            <strong>IBAN:</strong> <?= h($vendor['bank_iban']) ?><br>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Accounting Overview -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Accounting Overview</h5>
                        <a href="accounting/vendor_bills.php?vendor_id=<?= $vendorId ?>" class="btn btn-sm btn-outline-primary">Open Vendor Bills</a>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <small class="text-muted">Total Billed</small>
                                <div class="fs-5 fw-bold"><?= m($stats['total_billed']) ?> AED</div>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Paid / Spent</small>
                                <div class="fs-5 fw-bold text-success"><?= m($stats['total_spent']) ?> AED</div>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Outstanding</small>
                                <div class="fs-5 fw-bold <?= $stats['outstanding_balance'] > 0.005 ? 'text-danger' : 'text-success' ?>"><?= m($stats['outstanding_balance']) ?> AED</div>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Overdue</small>
                                <div class="fs-5 fw-bold <?= $stats['overdue_balance'] > 0.005 ? 'text-danger' : 'text-success' ?>"><?= m($stats['overdue_balance']) ?> AED</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Agreements -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Recent Agreements</h5>
                        <a href="vendor_agreements.php?vendor_id=<?= $vendorId ?>" class="btn btn-sm btn-primary">
                            View All
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($agreements)): ?>
                            <p class="text-muted">No agreements found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Agreement</th>
                                            <th>Service Type</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agreements as $agr): ?>
                                            <tr>
                                                <td>
                                                    <a href="vendor_agreements.php?id=<?= $agr['id'] ?>">
                                                        <?= h($agr['agreement_name']) ?>
                                                    </a>
                                                    <br><small class="text-muted"><?= h($agr['agreement_number']) ?></small>
                                                </td>
                                                <td><?= ucfirst(str_replace('_', ' ', $agr['service_type'])) ?></td>
                                                <td><?= date('M d, Y', strtotime($agr['start_date'])) ?></td>
                                                <td><?= $agr['end_date'] ? date('M d, Y', strtotime($agr['end_date'])) : 'Ongoing' ?></td>
                                                <td>
                                                    <span class="badge <?= getStatusBadge($agr['status']) ?>">
                                                        <?= ucfirst($agr['status']) ?>
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

                <!-- Recent Performance -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Recent Performance Reviews</h5>
                        <a href="vendor_performance.php?vendor_id=<?= $vendorId ?>" class="btn btn-sm btn-primary">
                            View All
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($performance)): ?>
                            <p class="text-muted">No performance reviews yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Rating</th>
                                            <th>Overall Score</th>
                                            <th>Reviewed By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($performance as $perf): ?>
                                            <tr>
                                                <td><?= date('M d, Y', strtotime($perf['performance_date'])) ?></td>
                                                <td>
                                                    <span class="text-warning">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="bi bi-star<?= $i <= $perf['rating'] ? '-fill' : '' ?>"></i>
                                                        <?php endfor; ?>
                                                    </span>
                                                </td>
                                                <td><?= $perf['overall_score'] ? number_format($perf['overall_score'], 1) : '-' ?>/10</td>
                                                <td><?= h($perf['reviewed_by_name'] ?: 'System') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <a href="vendor_agreements.php?vendor_id=<?= $vendorId ?>&action=add" class="btn btn-primary w-100 mb-2">
                            <i class="bi bi-file-plus"></i> New Agreement
                        </a>
                        <a href="vendor_performance.php?vendor_id=<?= $vendorId ?>&action=add" class="btn btn-success w-100 mb-2">
                            <i class="bi bi-star"></i> Add Performance Review
                        </a>
                        <a href="accounting/bill_entry_add.php?vendor_id=<?= $vendorId ?>" class="btn btn-info w-100 mb-2">
                            <i class="bi bi-receipt"></i> New Bill
                        </a>
                        <a href="accounting/vendor_payment_add.php?vendor_id=<?= $vendorId ?>" class="btn btn-outline-success w-100 mb-2">
                            <i class="bi bi-cash-coin"></i> Payment Made
                        </a>
                        <a href="accounting/vendor_advance_refunds.php?vendor_id=<?= $vendorId ?>" class="btn btn-outline-warning w-100 mb-2">
                            <i class="bi bi-arrow-counterclockwise"></i> Advance Refunds
                            <?php if ($stats['advance_remaining'] > 0.005): ?>
                                <span class="badge bg-success"><?= m($stats['advance_remaining']) ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="accounting/vendor_statement.php?vendor_id=<?= $vendorId ?>" class="btn btn-outline-primary w-100 mb-2">
                            <i class="bi bi-file-earmark-ruled"></i> Vendor SOA
                        </a>
                        <a href="accounting/ap_aging.php?vendor_id=<?= $vendorId ?>" class="btn btn-outline-secondary w-100 mb-2">
                            <i class="bi bi-clock-history"></i> AP Aging
                        </a>
                        <a href="accounting/vendor_ledger.php?vendor_id=<?= $vendorId ?>" class="btn btn-outline-dark w-100 mb-2">
                            <i class="bi bi-journal-bookmark"></i> Vendor Ledger
                        </a>
                    </div>
                </div>

                <!-- Latest Vendor Bills -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Latest Vendor Bills</h5>
                        <a href="accounting/vendor_bills.php?vendor_id=<?= $vendorId ?>" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($invoices)): ?>
                            <p class="text-muted small">No vendor bills yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Bill</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-end">Balance</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($invoices as $inv): ?>
                                            <?php $balance = (float)($inv['computed_balance'] ?? 0); $isPosted = (($inv['posting_status'] ?? 'not_posted') === 'posted' || !empty($inv['journal_id']) || !empty($inv['posted_journal_id'])); ?>
                                            <tr>
                                                <td>
                                                    <a href="accounting/vendor_bill_view.php?id=<?= (int)$inv['id'] ?>"><?= h($inv['invoice_number']) ?></a><br>
                                                    <small class="text-muted">
                                                        <?= date('M d, Y', strtotime($inv['invoice_date'])) ?>
                                                        <?php if (!empty($inv['due_date'])): ?> · Due <?= date('M d, Y', strtotime($inv['due_date'])) ?><?php endif; ?>
                                                    </small>
                                                </td>
                                                <td class="text-end"><?= m($inv['total_amount']) ?></td>
                                                <td class="text-end <?= $balance > 0.005 ? 'text-danger' : 'text-success' ?>"><?= m($balance) ?></td>
                                                <td>
                                                    <span class="badge <?= getStatusBadge($inv['status']) ?>"><?= h(ucwords(str_replace('_', ' ', $inv['status']))) ?></span><br>
                                                    <small class="text-muted"><?= $isPosted ? 'Posted' : 'Not posted' ?> · <?= (int)($inv['attachment_count'] ?? 0) ?> files</small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Payments -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Recent Payments</h5>
                        <a href="accounting/vendor_payment_add.php?vendor_id=<?= $vendorId ?>" class="btn btn-sm btn-outline-success">Add Payment</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recentPayments)): ?>
                            <p class="text-muted small">No payments recorded yet.</p>
                        <?php else: ?>
                            <?php foreach ($recentPayments as $payment): ?>
                                <div class="mb-2 pb-2 border-bottom">
                                    <strong><?= m($payment['amount']) ?> AED</strong><br>
                                    <small class="text-muted">
                                        <?= date('M d, Y', strtotime($payment['payment_date'])) ?>
                                        · <?= h(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?>
                                        <?php if (!empty($payment['reference_number'])): ?> · Ref <?= h($payment['reference_number']) ?><?php endif; ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Paid Expenses (informational — not AP) -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Quick Paid Expenses</h5>
                        <a href="expenses.php?vendor_id=<?= (int)$vendorId ?>" class="btn btn-sm btn-outline-secondary">View list</a>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-2">Paid immediately from bank/cash. These do not create vendor bills or change AP outstanding.</p>
                        <?php if (empty($quickPaidExpenses)): ?>
                            <p class="text-muted small mb-0">No quick paid expenses linked to this vendor.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Expense #</th>
                                            <th class="text-end">Total</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($quickPaidExpenses as $qe): ?>
                                            <tr>
                                                <td><?= h(date('M d, Y', strtotime($qe['expense_date']))) ?></td>
                                                <td>
                                                    <?= h($qe['expense_number']) ?>
                                                    <?php if (!empty($qe['paid_via'])): ?>
                                                        <br><small class="text-muted"><?= h(ucfirst((string)$qe['paid_via'])) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end"><?= m($qe['total']) ?></td>
                                                <td class="text-end">
                                                    <a href="expense_edit.php?id=<?= (int)$qe['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                    <?php if (!empty($qe['journal_id'])): ?>
                                                        <a href="accounting/journal_entry_view.php?id=<?= (int)$qe['journal_id'] ?>" class="btn btn-sm btn-outline-secondary">Journal</a>
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

                <!-- Documents -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Documents</h5>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal" title="Upload document">
                            <i class="bi bi-upload"></i>
                        </button>
                    </div>
                    <div class="card-body" id="vendorDocumentsList">
                        <?php if (empty($documents)): ?>
                            <p class="text-muted small mb-0" id="vendorDocsEmpty">No documents uploaded.</p>
                        <?php else: ?>
                            <?php foreach ($documents as $doc): ?>
                                <?php
                                $filePath = (string)($doc['file_path'] ?? '');
                                $viewUrl = $filePath !== '' ? '../../' . ltrim($filePath, '/') : '';
                                $isImage = str_starts_with((string)($doc['mime_type'] ?? ''), 'image/');
                                $isPdf = (($doc['mime_type'] ?? '') === 'application/pdf') || str_ends_with(strtolower($filePath), '.pdf');
                                ?>
                                <div class="mb-2 pb-2 border-bottom vendor-doc-item">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <strong><?= h($doc['document_name']) ?></strong><br>
                                            <small class="text-muted">
                                                <?= h(ucfirst((string)$doc['document_type'])) ?> —
                                                <?= h(formatFileSize($doc['file_size'])) ?><br>
                                                <?= h(date('M d, Y', strtotime($doc['uploaded_at']))) ?>
                                                <?php if (!empty($doc['uploaded_by_name'])): ?>
                                                    · <?= h($doc['uploaded_by_name']) ?>
                                                <?php endif; ?>
                                                <?php if ($doc['expiry_date']): ?>
                                                    <br>Expires:
                                                    <span class="<?= strtotime($doc['expiry_date']) < time() ? 'text-danger' : '' ?>">
                                                        <?= h(date('M d, Y', strtotime($doc['expiry_date']))) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <?php if ($viewUrl !== ''): ?>
                                            <div class="btn-group btn-group-sm flex-shrink-0">
                                                <a class="btn btn-outline-primary" href="<?= h($viewUrl) ?>" target="_blank" rel="noopener"
                                                   title="<?= $isImage || $isPdf ? 'View' : 'Open' ?>">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                                <a class="btn btn-outline-secondary" href="<?= h($viewUrl) ?>" download
                                                   title="Download">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div class="modal fade" id="uploadDocModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="vendorDocUploadForm" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="vendor_id" value="<?= (int)$vendorId ?>">
                    <div class="modal-body">
                        <div id="vendorDocUploadAlert" class="alert d-none" role="alert"></div>
                        <div class="mb-3">
                            <label class="form-label">Document Type</label>
                            <select name="document_type" class="form-select" required>
                                <option value="license">License</option>
                                <option value="insurance">Insurance</option>
                                <option value="contract">Contract</option>
                                <option value="invoice">Invoice</option>
                                <option value="certificate">Certificate</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Document Name</label>
                            <input type="text" name="document_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">File</label>
                            <input type="file" name="file" class="form-control" required
                                   accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,image/*,application/pdf">
                            <small class="text-muted">Max 10MB · JPG, PNG, GIF, PDF, DOC, DOCX, TXT</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Expiry Date (Optional)</label>
                            <input type="date" name="expiry_date" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="vendorDocUploadBtn">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const form = document.getElementById('vendorDocUploadForm');
        const alertBox = document.getElementById('vendorDocUploadAlert');
        const submitBtn = document.getElementById('vendorDocUploadBtn');
        if (!form) return;

        function showAlert(type, msg) {
            if (!alertBox) return;
            alertBox.className = 'alert alert-' + type;
            alertBox.textContent = msg;
            alertBox.classList.remove('d-none');
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (alertBox) alertBox.classList.add('d-none');
            const fd = new FormData(form);
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';
            }
            fetch('ajax_upload_vendor_document.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        showAlert('danger', (data && data.message) ? data.message : 'Upload failed');
                        return;
                    }
                    showAlert('success', data.message || 'Uploaded');
                    setTimeout(function () { location.reload(); }, 600);
                })
                .catch(function () {
                    showAlert('danger', 'Upload failed. Please try again.');
                })
                .finally(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Upload';
                    }
                });
        });
    })();
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

