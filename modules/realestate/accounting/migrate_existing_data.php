<?php
/**
 * Real Estate Accounting - Migrate Existing Data
 * Import existing payments, invoices, and PDC cheques into accounting system
 * 
 * IMPORTANT: This script creates accounting entries for existing data.
 * It does NOT modify or delete existing data.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/accounting_engine.php';
require_once __DIR__ . '/accounting_integration.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

$success = '';
$error = '';
$migrationResults = [];

// Get statistics
$stats = [
    'payments' => 0,
    'invoices' => 0,
    'pdc_cheques' => 0,
    'already_migrated_payments' => 0,
    'already_migrated_invoices' => 0
];

// Count existing data
$stmt = $conn->prepare("SELECT COUNT(*) FROM re_payments WHERE company_id = ?");
$stmt->execute([$currentCompanyId]);
$stats['payments'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_invoices WHERE company_id = ?");
$stmt->execute([$currentCompanyId]);
$stats['invoices'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM re_post_dated_cheques WHERE company_id = ?");
$stmt->execute([$currentCompanyId]);
$stats['pdc_cheques'] = (int)$stmt->fetchColumn();

// Count already migrated (check if journal exists for source)
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT jh.reference_id) 
    FROM re_journal_headers jh
    WHERE jh.company_id = ? AND jh.reference_type = 'payment'
");
$stmt->execute([$currentCompanyId]);
$stats['already_migrated_payments'] = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT jh.reference_id) 
    FROM re_journal_headers jh
    WHERE jh.company_id = ? AND jh.reference_type = 'invoice'
");
$stmt->execute([$currentCompanyId]);
$stats['already_migrated_invoices'] = (int)$stmt->fetchColumn();

// Handle migration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $migratePayments = isset($_POST['migrate_payments']);
    $migrateInvoices = isset($_POST['migrate_invoices']);
    $migrateDeposits = isset($_POST['migrate_deposits']);
    $asOfDate = !empty($_POST['as_of_date']) ? $_POST['as_of_date'] : date('Y-m-d');
    $skipExisting = isset($_POST['skip_existing']);
    
    // Each posting function (create_journal_entry) manages its own transaction internally.
    // We must NOT wrap everything in an outer transaction — doing so causes MySQL to
    // implicitly commit the outer transaction on every inner BEGIN, leaving the outer
    // $conn->commit() with nothing to commit ("no active transaction" error).
    $migrationResults = [
        'payments' => ['success' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []],
        'invoices' => ['success' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []],
        'deposits' => ['success' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []]
    ];

    try {
        // Migrate Payments
        if ($migratePayments) {
            // Include deferred_revenue_mode so we can route each payment correctly
            $stmt = $conn->prepare("
                SELECT p.*, l.tenant_id, l.deferred_revenue_mode
                FROM re_payments p
                JOIN re_leases l ON l.id = p.lease_id
                WHERE p.company_id = ? AND p.payment_date <= ?
                ORDER BY p.payment_date ASC, p.id ASC
            ");
            $stmt->execute([$currentCompanyId, $asOfDate]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($payments as $payment) {
                // Check if already migrated
                if ($skipExisting) {
                    $checkStmt = $conn->prepare("
                        SELECT COUNT(*) FROM re_journal_headers
                        WHERE company_id = ? AND reference_type IN ('payment','deferred_payment')
                          AND reference_id = ?
                          AND journal_type != 'reversal'
                    ");
                    $checkStmt->execute([$currentCompanyId, $payment['id']]);
                    if ($checkStmt->fetchColumn() > 0) {
                        $migrationResults['payments']['skipped']++;
                        continue;
                    }
                }

                // Route to the correct posting function based on whether the lease
                // uses accrual/deferred revenue mode or cash-basis mode.
                if (!empty($payment['deferred_revenue_mode'])) {
                    $result = post_deferred_payment_to_accounting($payment['id'], $currentCompanyId, $userId);
                } else {
                    $result = post_payment_to_accounting($payment['id'], $currentCompanyId, $userId);
                }

                if ($result['success']) {
                    $migrationResults['payments']['success']++;
                } else {
                    $migrationResults['payments']['failed']++;
                    $msg = "Payment #{$payment['id']}: " . $result['error'];
                    $migrationResults['payments']['errors'][] = $msg;
                    error_log("Payment migration failed — $msg");
                }
            }
        }

        // Migrate Invoices
        if ($migrateInvoices) {
            $stmt = $conn->prepare("
                SELECT * FROM re_invoices
                WHERE company_id = ? AND invoice_date <= ?
                ORDER BY invoice_date ASC, id ASC
            ");
            $stmt->execute([$currentCompanyId, $asOfDate]);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($invoices as $invoice) {
                if ($skipExisting) {
                    $checkStmt = $conn->prepare("
                        SELECT COUNT(*) FROM re_journal_headers
                        WHERE company_id = ? AND reference_type = 'invoice' AND reference_id = ?
                    ");
                    $checkStmt->execute([$currentCompanyId, $invoice['id']]);
                    if ($checkStmt->fetchColumn() > 0) {
                        $migrationResults['invoices']['skipped']++;
                        continue;
                    }
                }

                $result = post_invoice_to_accounting($invoice['id'], $currentCompanyId, $userId);
                if ($result['success']) {
                    $migrationResults['invoices']['success']++;
                } else {
                    $migrationResults['invoices']['failed']++;
                    $msg = "Invoice #{$invoice['id']}: " . $result['error'];
                    $migrationResults['invoices']['errors'][] = $msg;
                    error_log("Invoice migration failed — $msg");
                }
            }
        }

        // Migrate Security Deposits (from leases)
        // Function signature: post_security_deposit_to_accounting($leaseId, $companyId, $createdBy)
        if ($migrateDeposits) {
            $stmt = $conn->prepare("
                SELECT l.id, l.lease_number, l.security_deposit, l.start_date, l.tenant_id
                FROM re_leases l
                WHERE l.company_id = ? AND l.security_deposit > 0
                ORDER BY l.start_date ASC
            ");
            $stmt->execute([$currentCompanyId]);
            $leases = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($leases as $lease) {
                if ($skipExisting) {
                    $checkStmt = $conn->prepare("
                        SELECT COUNT(*) FROM re_journal_headers
                        WHERE company_id = ? AND reference_type = 'deposit' AND reference_id = ?
                    ");
                    $checkStmt->execute([$currentCompanyId, $lease['id']]);
                    if ($checkStmt->fetchColumn() > 0) {
                        $migrationResults['deposits']['skipped']++;
                        continue;
                    }
                }

                $result = post_security_deposit_to_accounting(
                    $lease['id'],
                    $currentCompanyId,
                    $userId
                );

                if ($result['success']) {
                    $migrationResults['deposits']['success']++;
                } else {
                    $migrationResults['deposits']['failed']++;
                    $msg = "Lease #{$lease['id']} ({$lease['lease_number']}): " . $result['error'];
                    $migrationResults['deposits']['errors'][] = $msg;
                    error_log("Deposit migration failed — $msg");
                }
            }
        }

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = "Migration error: " . $e->getMessage();
        error_log("Migration error: " . $e->getMessage());
    }

    $totalSuccess = $migrationResults['payments']['success']
                  + $migrationResults['invoices']['success']
                  + $migrationResults['deposits']['success'];
    $totalFailed  = $migrationResults['payments']['failed']
                  + $migrationResults['invoices']['failed']
                  + $migrationResults['deposits']['failed'];

    if (!$error) {
        if ($totalSuccess > 0) {
            $success = "Migration completed: {$totalSuccess} entries created successfully";
            if ($totalFailed > 0) {
                $success .= ", {$totalFailed} failed (see details below)";
            }
        } elseif ($totalFailed === 0) {
            $success = "All records were already migrated — nothing new to process.";
        } else {
            $error = "Migration ran but all {$totalFailed} entries failed. See details below.";
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Migrate Existing Data';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

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

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-database"></i> Migrate Existing Data to Accounting
    </div>
</div>

<!-- Important Notice -->
<div class="alert alert-warning">
    <h6><i class="bi bi-exclamation-triangle"></i> Important Information</h6>
    <ul class="mb-0">
        <li><strong>This migration is SAFE:</strong> It does NOT modify or delete existing data</li>
        <li><strong>Creates accounting entries:</strong> For existing payments, invoices, and deposits</li>
        <li><strong>Historical data:</strong> All existing records will appear in General Ledger and reports</li>
        <li><strong>No duplication:</strong> Use "Skip Already Migrated" to avoid creating duplicate entries</li>
        <li><strong>Backup recommended:</strong> Always backup your database before running migration</li>
    </ul>
</div>

<!-- Statistics -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card card-round border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Payments</h6>
                <h3 class="text-primary mb-0"><?= number_format($stats['payments']) ?></h3>
                <small class="text-muted"><?= $stats['already_migrated_payments'] ?> already migrated</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round border-info">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Invoices</h6>
                <h3 class="text-info mb-0"><?= number_format($stats['invoices']) ?></h3>
                <small class="text-muted"><?= $stats['already_migrated_invoices'] ?> already migrated</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round border-success">
            <div class="card-body text-center">
                <h6 class="text-muted">PDC Cheques</h6>
                <h3 class="text-success mb-0"><?= number_format($stats['pdc_cheques']) ?></h3>
                <small class="text-muted">Tracked separately</small>
            </div>
        </div>
    </div>
</div>

<!-- Migration Form -->
<div class="card card-round">
    <div class="card-header bg-light">
        <h6 class="mb-0"><i class="bi bi-gear"></i> Migration Options</h6>
    </div>
    <div class="card-body">
        <form method="POST" onsubmit="return confirm('Are you sure you want to migrate existing data? This will create accounting entries for all selected records.');">
            <?= csrf_field() ?>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Migrate Data Up To Date *</label>
                    <input type="date" name="as_of_date" class="form-control" 
                           value="<?= date('Y-m-d') ?>" required>
                    <small class="text-muted">Only migrate records up to this date</small>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="skip_existing" id="skipExisting" value="1" checked>
                        <label class="form-check-label" for="skipExisting">
                            Skip Already Migrated Records
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <h6>Select Data Types to Migrate:</h6>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="migrate_payments" id="migratePayments" value="1" checked>
                    <label class="form-check-label" for="migratePayments">
                        <strong>Payments</strong> (<?= number_format($stats['payments']) ?> records)
                        <br><small class="text-muted">
                            Accrual leases → Dr. Cash/Bank, Cr. Deferred Rent Revenue (2410)<br>
                            Cash-basis leases → Dr. Cash/Bank, Cr. Rent Receivable (1310)
                        </small>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="migrate_invoices" id="migrateInvoices" value="1" checked>
                    <label class="form-check-label" for="migrateInvoices">
                        <strong>Invoices</strong> (<?= number_format($stats['invoices']) ?> records)
                        <br><small class="text-muted">Creates: Dr. Accounts Receivable, Cr. Income + VAT</small>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="migrate_deposits" id="migrateDeposits" value="1">
                    <label class="form-check-label" for="migrateDeposits">
                        <strong>Security Deposits</strong> (from leases)
                        <br><small class="text-muted">Creates: Dr. Cash/Bank, Cr. Security Deposits Payable</small>
                    </label>
                </div>
            </div>
            
            <?php if (!empty($migrationResults)): ?>
                <?php
                    $hasAnyFailed = ($migrationResults['payments']['failed'] + $migrationResults['invoices']['failed'] + $migrationResults['deposits']['failed']) > 0;
                    $alertClass   = $hasAnyFailed ? 'alert-warning' : 'alert-success';
                ?>
                <div class="alert <?= $alertClass ?>">
                    <h6><i class="bi bi-clipboard-check"></i> Migration Results:</h6>
                    <ul class="mb-0">
                        <?php foreach (['payments','invoices','deposits'] as $type): ?>
                            <?php $r = $migrationResults[$type]; ?>
                            <?php if ($r['success'] > 0 || $r['failed'] > 0 || $r['skipped'] > 0): ?>
                                <li>
                                    <strong><?= ucfirst($type) ?>:</strong>
                                    <span class="text-success"><?= $r['success'] ?> success</span>,
                                    <?php if ($r['failed'] > 0): ?>
                                        <span class="text-danger"><?= $r['failed'] ?> failed</span>,
                                    <?php else: ?>
                                        0 failed,
                                    <?php endif; ?>
                                    <?= $r['skipped'] ?> skipped
                                    <?php if (!empty($r['errors'])): ?>
                                        <ul class="text-danger small mt-1">
                                            <?php foreach (array_slice($r['errors'], 0, 20) as $errMsg): ?>
                                                <li><?= h($errMsg) ?></li>
                                            <?php endforeach; ?>
                                            <?php if (count($r['errors']) > 20): ?>
                                                <li>... and <?= count($r['errors']) - 20 ?> more (check server error log)</li>
                                            <?php endif; ?>
                                        </ul>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> <strong>Note:</strong> Migration may take several minutes depending on the amount of data. Do not close this page during migration.
            </div>
            
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-play-circle"></i> Start Migration
            </button>
        </form>
    </div>
</div>

<!-- What Happens After Migration -->
<div class="card card-round mt-4">
    <div class="card-header bg-light">
        <h6 class="mb-0"><i class="bi bi-question-circle"></i> What Happens After Migration?</h6>
    </div>
    <div class="card-body">
        <ul>
            <li><strong>General Ledger:</strong> All migrated transactions will appear in the General Ledger</li>
            <li><strong>Financial Reports:</strong> Trial Balance, P&L, Balance Sheet will include historical data</li>
            <li><strong>Tenant Statements:</strong> All historical invoices and payments will appear</li>
            <li><strong>Existing Pages:</strong> Billing, Payments, Collections pages continue to work normally</li>
            <li><strong>New Transactions:</strong> Future invoices/payments will automatically post to accounting</li>
        </ul>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
