<?php
/**
 * Real Estate Module - Migrate Existing Lease Cheques
 * Creates post-dated cheques for existing leases with cheque payment method
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = current_user_id();

$action = $_GET['action'] ?? '';
$success = '';
$error = '';
$stats = null;

// Handle migration
if ($action === 'migrate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    try {
        $conn->beginTransaction();
        
        // Find leases with cheque payment method that don't have cheques in re_post_dated_cheques
        $stmt = $conn->prepare("
            SELECT DISTINCT l.id as lease_id, l.company_id, l.payment_method, l.lease_number,
                   t.first_name, t.last_name, l.created_by
            FROM re_leases l
            JOIN re_tenants t ON t.id = l.tenant_id
            LEFT JOIN re_post_dated_cheques c ON c.lease_id = l.id
            WHERE l.company_id = ? AND l.payment_method = 'cheque'
            AND c.id IS NULL
            ORDER BY l.id
        ");
        $stmt->execute([$currentCompanyId]);
        $leases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalChequesCreated = 0;
        $leasesProcessed = 0;
        
        foreach ($leases as $lease) {
            // Get all installments for this lease
            $installmentsStmt = $conn->prepare("
                SELECT id, installment_date, amount 
                FROM re_lease_installments 
                WHERE lease_id = ? 
                ORDER BY installment_date ASC
            ");
            $installmentsStmt->execute([$lease['lease_id']]);
            $installments = $installmentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($installments)) {
                continue;
            }
            
            $tenantName = trim($lease['first_name'] . ' ' . $lease['last_name']);
            
            foreach ($installments as $index => $installment) {
                // Check if cheque already exists in re_post_dated_cheques
                $checkStmt = $conn->prepare("
                    SELECT id FROM re_post_dated_cheques 
                    WHERE lease_id = ? AND installment_id = ?
                ");
                $checkStmt->execute([$lease['lease_id'], $installment['id']]);
                if ($checkStmt->fetch()) {
                    continue; // Skip if already exists
                }
                
                // Generate cheque number
                $chequeNumber = 'CHQ-' . $lease['lease_id'] . '-' . ($index + 1);
                
                // Insert into re_post_dated_cheques
                $insertStmt = $conn->prepare("
                    INSERT INTO re_post_dated_cheques 
                    (company_id, lease_id, installment_id, cheque_number, cheque_date, cheque_amount, 
                     account_holder_name, received_date, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 'pending', ?)
                ");
                $insertStmt->execute([
                    $lease['company_id'],
                    $lease['lease_id'],
                    $installment['id'],
                    $chequeNumber,
                    $installment['installment_date'],
                    $installment['amount'],
                    $tenantName,
                    $lease['created_by'] ?: $currentUserId
                ]);
                
                $totalChequesCreated++;
            }
            
            $leasesProcessed++;
        }
        
        $conn->commit();
        $success = "Migration completed successfully! Created {$totalChequesCreated} cheques for {$leasesProcessed} leases.";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Migration failed: " . $e->getMessage();
    }
}

// Get statistics
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT l.id) as leases_with_cheque_payment,
        COUNT(DISTINCT CASE WHEN c.id IS NULL THEN l.id END) as leases_without_cheques,
        COUNT(DISTINCT li.id) as total_installments,
        COUNT(DISTINCT CASE WHEN c.id IS NULL THEN li.id END) as installments_without_cheques
    FROM re_leases l
    JOIN re_lease_installments li ON li.lease_id = l.id
    LEFT JOIN re_post_dated_cheques c ON c.lease_id = l.id AND c.installment_id = li.id
    WHERE l.company_id = ? AND l.payment_method = 'cheque'
");
$statsStmt->execute([$currentCompanyId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Migrate Lease Cheques';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-arrow-repeat"></i> Migrate Lease Cheques</h1>
            <a href="billing_cheques.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Cheques
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle"></i> Migration Information</h5>
            </div>
            <div class="card-body">
                <p>This tool will automatically create post-dated cheques for existing leases that have:</p>
                <ul>
                    <li>Payment method set to "Cheque"</li>
                    <li>Installments created</li>
                    <li>No cheques in the Post-Dated Cheques table</li>
                </ul>
                <p class="text-muted"><strong>Note:</strong> This migration is safe to run multiple times. It will skip cheques that already exist.</p>
            </div>
        </div>

        <?php if ($stats): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Statistics</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <div class="text-muted">Leases with Cheque Payment</div>
                            <div class="h4"><?= $stats['leases_with_cheque_payment'] ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <div class="text-muted">Leases Without Cheques</div>
                            <div class="h4 text-warning"><?= $stats['leases_without_cheques'] ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <div class="text-muted">Total Installments</div>
                            <div class="h4"><?= $stats['total_installments'] ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <div class="text-muted">Installments Without Cheques</div>
                            <div class="h4 text-danger"><?= $stats['installments_without_cheques'] ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-play-circle"></i> Run Migration</h5>
            </div>
            <div class="card-body">
                <?php if ($stats && $stats['installments_without_cheques'] > 0): ?>
                    <p>Found <strong><?= $stats['installments_without_cheques'] ?></strong> installments that need cheques created.</p>
                    <form method="POST" action="?action=migrate" onsubmit="return confirm('Are you sure you want to create cheques for <?= $stats['installments_without_cheques'] ?> installments?');">
                        <?php csrf_field(); ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-play-fill"></i> Run Migration
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i> All leases with cheque payment method already have cheques created. No migration needed!
                    </div>
                    <a href="billing_cheques.php" class="btn btn-primary">
                        <i class="bi bi-bank"></i> View Post-Dated Cheques
                    </a>
                <?php endif; ?>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

