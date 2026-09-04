<?php
/**
 * Real Estate Module - Send Document Expiry Alerts
 * Manually trigger document expiry alerts
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

$results = null;

// Handle manual alert sending
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_expiry_alerts') {
        $results = check_and_send_document_expiry_alerts($conn, $currentCompanyId);
    } elseif ($action === 'update_compliance_status') {
        $unitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
        if ($unitId) {
            $result = update_unit_compliance_status($conn, $currentCompanyId, $unitId);
            $_SESSION['success'] = 'Compliance status updated for unit.';
        }
    } elseif ($action === 'update_all_units') {
        // Get all units
        $units = $conn->prepare("SELECT id FROM re_units WHERE company_id = ?");
        $units->execute([$currentCompanyId]);
        $unitIds = $units->fetchAll(PDO::FETCH_COLUMN);
        
        $updated = 0;
        foreach ($unitIds as $unitId) {
            update_unit_compliance_status($conn, $currentCompanyId, $unitId);
            $updated++;
        }
        $_SESSION['success'] = "Updated compliance status for {$updated} units.";
    }
}

// Get statistics
$stats = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM re_documents 
         WHERE company_id = ? AND expiry_date IS NOT NULL 
         AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
         AND expiry_date >= CURDATE() AND status = 'active' 
         AND (expiry_alert_sent = 0 OR expiry_alert_sent IS NULL)) as expiring_soon,
        (SELECT COUNT(*) FROM re_documents 
         WHERE company_id = ? AND expiry_date IS NOT NULL 
         AND expiry_date < CURDATE() AND status = 'active') as expired,
        (SELECT COUNT(*) FROM re_unit_legal_status 
         WHERE company_id = ? AND legal_status = 'non_compliant') as non_compliant_units
");
$stats->execute([$currentCompanyId, $currentCompanyId, $currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Send Compliance Alerts';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-envelope"></i> Send Compliance Alerts</h1>
            <a href="compliance.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Compliance
            </a>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= h($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if ($results): ?>
            <div class="alert alert-<?= $results['sent'] > 0 ? 'success' : 'info' ?>">
                <strong>Results:</strong><br>
                Total documents checked: <?= $results['total'] ?><br>
                Alerts sent: <?= $results['sent'] ?><br>
                Skipped: <?= $results['skipped'] ?>
                <?php if (!empty($results['errors'])): ?>
                    <br><small>Errors: <?= implode(', ', array_slice($results['errors'], 0, 5)) ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> 
            <strong>Note:</strong> This will check all documents and send expiry alerts based on your document type configurations.
        </div>

        <div class="row">
            <!-- Send Document Expiry Alerts -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="bi bi-file-earmark"></i> Document Expiry Alerts</h5>
                    </div>
                    <div class="card-body">
                        <p class="h3 text-warning"><?= $stats['expiring_soon'] + $stats['expired'] ?></p>
                        <p class="text-muted">Documents expiring soon or expired</p>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send_expiry_alerts">
                            <button type="submit" class="btn btn-warning w-100" 
                                    onclick="return confirm('Send expiry alerts for all expiring/expired documents?')">
                                <i class="bi bi-envelope"></i> Send Alerts
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Update Compliance Status -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-shield-check"></i> Update Compliance Status</h5>
                    </div>
                    <div class="card-body">
                        <p class="h3 text-info"><?= $stats['non_compliant_units'] ?></p>
                        <p class="text-muted">Non-compliant units</p>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_all_units">
                            <button type="submit" class="btn btn-info w-100" 
                                    onclick="return confirm('Update compliance status for all units? This will recalculate compliance scores based on documents.')">
                                <i class="bi bi-arrow-clockwise"></i> Update All Units
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle"></i> How It Works</h5>
            </div>
            <div class="card-body">
                <ul>
                    <li><strong>Document Expiry Alerts:</strong> Checks all documents with expiry dates and sends alerts based on document type configuration (alert days before expiry)</li>
                    <li><strong>Update Compliance Status:</strong> Recalculates compliance scores and legal status for all units based on document status (expired, missing, etc.)</li>
                    <li>Alerts respect the configuration in Document Types</li>
                    <li>Alerts are only sent once per day per document</li>
                    <li>All alerts are logged for tracking</li>
                </ul>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

